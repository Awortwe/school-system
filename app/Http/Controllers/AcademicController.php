<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\Audit;
use App\Support\StudentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AcademicController extends Controller
{
    public function index(): Response
    {
        $years = AcademicYear::with(['terms' => fn ($query) => $query->orderBy('sequence')])
            ->orderByDesc('start_date')
            ->get();

        $levels = Level::with(['schoolClasses.sections.classTeacher'])
            ->orderBy('sort_order')
            ->get();

        $sections = StudentAccess::sections(request()->user())->with(['schoolClass.level', 'classTeacher'])
            ->get()
            ->sortBy(fn (ClassSection $section) => [
                $section->schoolClass?->level?->sort_order ?? 0,
                $section->schoolClass?->sort_order ?? 0,
                $section->name,
            ])
            ->values();

        $teachers = User::role('teacher')->when(! StudentAccess::schoolWide(request()->user()), fn ($query) => $query->whereKey(request()->user()->id))->orderBy('name')->get(['id', 'name']);
        if (! StudentAccess::schoolWide(request()->user())) {
            foreach ($levels as $level) {
                foreach ($level->schoolClasses as $class) $class->setRelation('sections', $class->sections->whereIn('id', $sections->pluck('id'))->values());
                $level->setRelation('schoolClasses', $level->schoolClasses->filter(fn ($class) => $class->sections->isNotEmpty())->values());
            }
            $levels = $levels->filter(fn ($level) => $level->schoolClasses->isNotEmpty())->values();
        }

        $classSubjects = ClassSubject::with(['classSection.schoolClass', 'subject', 'teacher', 'term'])
            ->when(! StudentAccess::schoolWide(request()->user()), fn ($query) => $query->where('teacher_id', request()->user()->id))
            ->latest('id')
            ->get()
            ->map(fn (ClassSubject $assignment) => [
                'id' => $assignment->id,
                'section' => $assignment->classSection?->displayName(),
                'subject' => $assignment->subject?->name,
                'teacher' => $assignment->teacher?->name,
                'term' => $assignment->term?->name,
                'academic_year_id' => $assignment->academic_year_id,
                'term_id' => $assignment->term_id,
            ]);

        return Inertia::render('Academics/Index', [
            'years' => $years,
            'levels' => $levels,
            'sections' => $sections->map(fn (ClassSection $section) => [
                'id' => $section->id,
                'name' => $section->displayName(),
                'section_name' => $section->name,
                'level' => $section->schoolClass?->level?->name,
                'class_id' => $section->school_class_id,
                'room' => $section->room,
                'capacity' => $section->capacity,
                'class_teacher_id' => $section->class_teacher_id,
                'class_teacher' => $section->classTeacher?->name,
            ]),
            'subjects' => Subject::when(! StudentAccess::schoolWide(request()->user()), fn ($query) => $query->whereIn('id', ClassSubject::where('teacher_id', request()->user()->id)->pluck('subject_id')))->orderBy('sort_order')->orderBy('name')->get(),
            'teachers' => $teachers,
            'classSubjects' => $classSubjects,
            'curricula' => ['GES', 'Cambridge', 'Montessori'],
            'campuses' => ['montessori', 'international'],
        ]);
    }

    public function storeYear(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:20', 'unique:academic_years,name'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_current' => ['boolean'],
        ]);

        $year = DB::transaction(function () use ($data) {
            if (! empty($data['is_current'])) {
                AcademicYear::query()->update(['is_current' => false]);
            }

            return AcademicYear::create($data);
        });

        Audit::record('academic_year_created', $year, [], $year->only(['name', 'start_date', 'end_date']), null, 'Academic year created');

        return back()->with('success', 'Academic year created.');
    }

    public function updateYear(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:20', Rule::unique('academic_years', 'name')->ignore($academicYear->id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $academicYear->update($data);

        Audit::record('academic_year_updated', $academicYear, [], $data, null, 'Academic year updated');

        return back()->with('success', 'Academic year updated.');
    }

    public function currentYear(AcademicYear $academicYear): RedirectResponse
    {
        DB::transaction(function () use ($academicYear) {
            AcademicYear::query()->update(['is_current' => false]);
            $academicYear->update(['is_current' => true]);
        });

        Audit::record('academic_year_current', $academicYear, [], ['is_current' => true], null, 'Academic year set as current');

        return back()->with('success', $academicYear->name.' is now the current academic year.');
    }

    public function destroyYear(AcademicYear $academicYear): RedirectResponse
    {
        return $this->safeDelete(fn () => $academicYear->delete(), 'Academic year deleted.');
    }

    public function storeTerm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:50'],
            'sequence' => ['required', 'integer', 'min:1', 'max:10'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'reopening_date' => ['nullable', 'date'],
            'is_current' => ['boolean'],
            'status' => ['required', Rule::in(['open', 'closed'])],
        ]);

        $term = DB::transaction(function () use ($data) {
            if (! empty($data['is_current'])) {
                Term::query()->update(['is_current' => false]);
                AcademicYear::query()->update(['is_current' => false]);
                AcademicYear::whereKey($data['academic_year_id'])->update(['is_current' => true]);
            }

            return Term::create($data);
        });

        Audit::record('term_created', $term, [], $term->only(['name', 'academic_year_id']), null, 'Term created');

        return back()->with('success', 'Term created.');
    }

    public function updateTerm(Request $request, Term $term): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'sequence' => ['required', 'integer', 'min:1', 'max:10'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'reopening_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['open', 'closed'])],
        ]);

        $term->update($data);

        Audit::record('term_updated', $term, [], $data, null, 'Term updated');

        return back()->with('success', 'Term updated.');
    }

    public function currentTerm(Term $term): RedirectResponse
    {
        DB::transaction(function () use ($term) {
            Term::query()->update(['is_current' => false]);
            AcademicYear::query()->update(['is_current' => false]);
            $term->update(['is_current' => true]);
            $term->academicYear()->update(['is_current' => true]);
        });

        Audit::record('term_current', $term, [], ['is_current' => true], null, 'Term set as current');

        return back()->with('success', $term->name.' is now the current term.');
    }

    public function destroyTerm(Term $term): RedirectResponse
    {
        return $this->safeDelete(fn () => $term->delete(), 'Term deleted.');
    }

    public function storeLevel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', 'unique:levels,code'],
            'curriculum' => ['required', Rule::in(['GES', 'Cambridge', 'Montessori'])],
            'campus' => ['required', Rule::in(['montessori', 'international'])],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $level = Level::create($data);

        Audit::record('level_created', $level, [], $data, null, 'Level created');

        return back()->with('success', 'Level created.');
    }

    public function updateLevel(Request $request, Level $level): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', Rule::unique('levels', 'code')->ignore($level->id)],
            'curriculum' => ['required', Rule::in(['GES', 'Cambridge', 'Montessori'])],
            'campus' => ['required', Rule::in(['montessori', 'international'])],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $level->update($data);

        Audit::record('level_updated', $level, [], $data, null, 'Level updated');

        return back()->with('success', 'Level updated.');
    }

    public function destroyLevel(Level $level): RedirectResponse
    {
        return $this->safeDelete(fn () => $level->delete(), 'Level deleted.');
    }

    public function storeClass(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'level_id' => ['required', 'exists:levels,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $class = SchoolClass::create($data);

        Audit::record('class_created', $class, [], $data, null, 'Class created');

        return back()->with('success', 'Class created.');
    }

    public function updateClass(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $data = $request->validate([
            'level_id' => ['required', 'exists:levels,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $schoolClass->update($data);

        Audit::record('class_updated', $schoolClass, [], $data, null, 'Class updated');

        return back()->with('success', 'Class updated.');
    }

    public function destroyClass(SchoolClass $schoolClass): RedirectResponse
    {
        return $this->safeDelete(fn () => $schoolClass->delete(), 'Class deleted.');
    }

    public function storeSection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'name' => ['required', 'string', 'max:30'],
            'room' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'class_teacher_id' => ['nullable', 'exists:users,id'],
        ]);

        $section = ClassSection::create($data);

        Audit::record('section_created', $section, [], $data, null, 'Class section created');

        return back()->with('success', 'Class section created.');
    }

    public function updateSection(Request $request, ClassSection $section): RedirectResponse
    {
        $data = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'name' => ['required', 'string', 'max:30'],
            'room' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'class_teacher_id' => ['nullable', 'exists:users,id'],
        ]);

        $section->update($data);

        Audit::record('section_updated', $section, [], $data, null, 'Class section updated');

        return back()->with('success', 'Class section updated.');
    }

    public function destroySection(ClassSection $section): RedirectResponse
    {
        return $this->safeDelete(fn () => $section->delete(), 'Class section deleted.');
    }

    public function storeSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', 'unique:subjects,code'],
            'level_id' => ['nullable', 'exists:levels,id'],
            'is_core' => ['boolean'],
            'is_examinable' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $subject = Subject::create($data);

        Audit::record('subject_created', $subject, [], $data, null, 'Subject created');

        return back()->with('success', 'Subject created.');
    }

    public function updateSubject(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', Rule::unique('subjects', 'code')->ignore($subject->id)],
            'level_id' => ['nullable', 'exists:levels,id'],
            'is_core' => ['boolean'],
            'is_examinable' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $subject->update($data);

        Audit::record('subject_updated', $subject, [], $data, null, 'Subject updated');

        return back()->with('success', 'Subject updated.');
    }

    public function destroySubject(Subject $subject): RedirectResponse
    {
        return $this->safeDelete(fn () => $subject->delete(), 'Subject deleted.');
    }

    public function storeClassSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'term_id' => ['nullable', 'exists:terms,id'],
        ]);

        $assignment = ClassSubject::create($data);

        Audit::record('class_subject_created', $assignment, [], $data, null, 'Subject assigned to class');

        return back()->with('success', 'Subject assigned to class.');
    }

    public function destroyClassSubject(ClassSubject $classSubject): RedirectResponse
    {
        return $this->safeDelete(fn () => $classSubject->delete(), 'Class subject assignment removed.');
    }

    private function safeDelete(callable $callback, string $message): RedirectResponse
    {
        return back()->with('error', 'Academic records are retained to preserve history. Edit the configuration instead.');
    }
}
