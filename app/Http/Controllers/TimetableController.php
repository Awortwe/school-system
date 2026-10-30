<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableEntry;
use App\Models\TimetablePeriod;
use App\Models\User;
use App\Support\Audit;
use App\Support\StudentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TimetableController extends Controller
{
    public function index(Request $request): Response
    {
        $sections = StudentAccess::sections($request->user())->with('schoolClass.level')->get()
            ->sortBy(fn (ClassSection $section) => [
                $section->schoolClass?->level?->sort_order ?? 0,
                $section->schoolClass?->sort_order ?? 0,
                $section->name,
            ])->values();

        $section = $sections->firstWhere('id', (int) $request->input('section_id')) ?? $sections->first();
        $term = Term::find($request->input('term_id')) ?? Term::where('is_current', true)->first();

        $entries = collect();

        if ($section && $term) {
            $entries = TimetableEntry::with(['subject', 'teacher', 'period'])
                ->where('class_section_id', $section->id)
                ->where('academic_year_id', $term->academic_year_id)
                ->where(function ($query) use ($term) {
                    $query->whereNull('term_id')->orWhere('term_id', $term->id);
                })
                ->get()
                ->map(fn (TimetableEntry $entry) => [
                    'id' => $entry->id,
                    'day_of_week' => $entry->day_of_week,
                    'timetable_period_id' => $entry->timetable_period_id,
                    'subject_id' => $entry->subject_id,
                    'subject' => $entry->subject?->name,
                    'teacher_id' => $entry->teacher_id,
                    'teacher' => $entry->teacher?->name,
                    'room' => $entry->room,
                ]);
        }

        $classSubjects = $section && $term
            ? ClassSubject::with(['subject', 'teacher'])
                ->where('class_section_id', $section->id)
                ->where('academic_year_id', $term->academic_year_id)
                ->where(function ($query) use ($term) {
                    $query->whereNull('term_id')->orWhere('term_id', $term->id);
                })
                ->get()
                ->map(fn (ClassSubject $assignment) => [
                    'subject_id' => $assignment->subject_id,
                    'subject' => $assignment->subject?->name,
                    'teacher_id' => $assignment->teacher_id,
                    'teacher' => $assignment->teacher?->name,
                ])
            : collect();

        return Inertia::render('Academics/Timetable', [
            'sections' => $sections->map(fn (ClassSection $section) => [
                'id' => $section->id,
                'name' => $section->displayName(),
            ]),
            'terms' => Term::with('academicYear')->orderByDesc('academic_year_id')->orderBy('sequence')->get(),
            'periods' => TimetablePeriod::orderBy('sort_order')->get(),
            'teachers' => User::role('teacher')->orderBy('name')->get(['id', 'name']),
            'subjects' => Subject::orderBy('name')->get(['id', 'name']),
            'classSubjects' => $classSubjects,
            'entries' => $entries,
            'selectedSectionId' => $section?->id,
            'selectedTermId' => $term?->id,
            'days' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'term_id' => ['required', 'exists:terms,id'],
            'entries' => ['array'],
            'entries.*.day_of_week' => ['required', 'integer', 'between:1,5'],
            'entries.*.timetable_period_id' => ['required', 'exists:timetable_periods,id'],
            'entries.*.subject_id' => ['required', 'exists:subjects,id'],
            'entries.*.teacher_id' => ['nullable', 'exists:users,id'],
            'entries.*.room' => ['nullable', 'string', 'max:50'],
        ]);

        $term = Term::findOrFail($data['term_id']);

        DB::transaction(function () use ($data, $term) {
            \App\Models\AcademicYear::whereKey($term->academic_year_id)->lockForUpdate()->firstOrFail();
            $seen = [];
            foreach ($data['entries'] ?? [] as $entry) {
                $period = TimetablePeriod::findOrFail($entry['timetable_period_id']);
                foreach ($seen as $prior) {
                    if ($prior['day'] == $entry['day_of_week'] && $period->start_time < $prior['end'] && $period->end_time > $prior['start']) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['entries' => 'Class lesson times overlap.']);
                    }
                }
                $seen[] = ['day' => $entry['day_of_week'], 'start' => $period->start_time, 'end' => $period->end_time];
                $clash = TimetableEntry::where('academic_year_id', $term->academic_year_id)
                    ->where(fn ($query) => $query->whereNull('term_id')->orWhere('term_id', $term->id))
                    ->where('class_section_id', '!=', $data['class_section_id'])
                    ->where('teacher_id', $entry['teacher_id'] ?? 0)->where('day_of_week', $entry['day_of_week'])
                    ->whereHas('period', fn ($query) => $query->where('start_time', '<', $period->end_time)->where('end_time', '>', $period->start_time))->exists();
                if ($clash) throw \Illuminate\Validation\ValidationException::withMessages(['entries' => 'Teacher already has an overlapping lesson in another class.']);
            }
            TimetableEntry::where('class_section_id', $data['class_section_id'])
                ->where('academic_year_id', $term->academic_year_id)
                ->where(fn ($query) => $query->where('term_id', $term->id)->orWhereNull('term_id'))
                ->delete();

            foreach ($data['entries'] ?? [] as $entry) {
                TimetableEntry::create([
                    'class_section_id' => $data['class_section_id'],
                    'subject_id' => $entry['subject_id'],
                    'teacher_id' => $entry['teacher_id'] ?? null,
                    'timetable_period_id' => $entry['timetable_period_id'],
                    'academic_year_id' => $term->academic_year_id,
                    'term_id' => $term->id,
                    'day_of_week' => $entry['day_of_week'],
                    'room' => $entry['room'] ?? null,
                ]);
            }
        });

        Audit::record('timetable_saved', null, [], ['class_section_id' => $data['class_section_id'], 'term_id' => $term->id], null, 'Timetable saved');

        return back()->with('success', 'Timetable saved.');
    }

    public function storePeriod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        TimetablePeriod::create($data);

        return back()->with('success', 'Timetable period added.');
    }

    public function destroyPeriod(TimetablePeriod $period): RedirectResponse
    {
        if ($period->entries()->exists()) {
            return back()->with('error', 'This period is used in a timetable and cannot be deleted.');
        }

        $period->delete();

        return back()->with('success', 'Timetable period deleted.');
    }
}
