<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Application;
use App\Models\ClassSection;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Services\DocumentNumberService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdmissionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'level_id']);

        $applications = Application::with(['level', 'schoolClass', 'academicYear'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('application_no', 'like', "%{$search}%")
                        ->orWhere('guardian_name', 'like', "%{$search}%")
                        ->orWhere('guardian_phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['level_id'] ?? null, fn ($query, $level) => $query->where('level_id', $level))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Application $application) => [
                'id' => $application->id,
                'application_no' => $application->application_no,
                'name' => $application->fullName(),
                'guardian_name' => $application->guardian_name,
                'guardian_phone' => $application->guardian_phone,
                'level' => $application->level?->name,
                'class' => $application->schoolClass?->name,
                'year' => $application->academicYear?->name,
                'status' => $application->status,
                'submitted_at' => $application->submitted_at?->toDateString() ?? $application->created_at?->toDateString(),
            ]);

        return Inertia::render('Admissions/Index', [
            'applications' => $applications,
            'filters' => $filters,
            'levels' => Level::orderBy('sort_order')->get(['id', 'name']),
            'statuses' => ['submitted', 'under_review', 'approved', 'waitlisted', 'rejected', 'admitted'],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admissions/Create', [
            'levels' => Level::with('schoolClasses')->orderBy('sort_order')->get(),
            'years' => AcademicYear::orderByDesc('start_date')->get(['id', 'name', 'is_current']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['application_no'] = DocumentNumberService::nextApplication((int) now()->format('Y'));
        $data['submitted_at'] = now();

        $application = Application::create($data);

        Audit::record('application_created', $application, [], $application->only(['application_no', 'first_name', 'last_name']), null, 'Admission application recorded');

        return redirect()->route('admissions.show', $application)->with('success', 'Application recorded.');
    }

    public function show(Application $admission): Response
    {
        $admission->load(['level', 'schoolClass', 'academicYear', 'reviewer', 'student']);

        return Inertia::render('Admissions/Show', [
            'application' => $admission,
            'sections' => ClassSection::with('schoolClass.level')
                ->whereHas('schoolClass', fn ($query) => $query->where('level_id', $admission->level_id))
                ->get()
                ->map(fn (ClassSection $section) => [
                    'id' => $section->id,
                    'name' => $section->displayName(),
                ]),
            'statuses' => ['submitted', 'under_review', 'approved', 'waitlisted', 'rejected', 'admitted'],
        ]);
    }

    public function update(Request $request, Application $admission): RedirectResponse
    {
        $data = $this->validated($request);
        $admission->update($data);

        Audit::record('application_updated', $admission, [], $data, null, 'Admission application updated');

        return back()->with('success', 'Application updated.');
    }

    public function decide(Request $request, Application $admission): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['submitted', 'under_review', 'approved', 'waitlisted', 'rejected'])],
            'decision_notes' => ['nullable', 'string'],
        ]);

        $old = $admission->status;
        abort_if($admission->converted_student_id, 422, 'An admitted application cannot be changed.');

        $admission->update([
            'status' => $data['status'],
            'decision_notes' => $data['decision_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        Audit::record('application_decision', $admission, ['status' => $old], ['status' => $data['status']], $data['decision_notes'] ?? null, 'Admission decision recorded');

        return back()->with('success', 'Admission decision saved.');
    }

    public function convert(Request $request, Application $admission): RedirectResponse
    {
        if ($admission->converted_student_id) {
            return redirect()->route('students.show', $admission->converted_student_id)
                ->with('warning', 'This application has already been converted to a student record.');
        }

        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'category' => ['required', Rule::in(['day', 'boarding'])],
            'admission_date' => ['nullable', 'date'],
        ]);

        $student = DB::transaction(function () use ($request, $admission, $data) {
            $admission = Application::whereKey($admission->id)->lockForUpdate()->firstOrFail();
            abort_unless($admission->status === 'approved' && ! $admission->converted_student_id, 422, 'Approve the application before admission.');
            $section = ClassSection::with('schoolClass')->findOrFail($data['class_section_id']);
            abort_if($admission->level_id && $section->schoolClass->level_id != $admission->level_id, 422, 'Class must match the application level.');
            $year = AcademicYear::where('is_current', true)->first();

            $student = Student::create([
                'admission_no' => DocumentNumberService::nextAdmission((int) now()->format('Y')),
                'first_name' => $admission->first_name,
                'middle_name' => $admission->middle_name,
                'last_name' => $admission->last_name,
                'date_of_birth' => $admission->date_of_birth,
                'gender' => $admission->gender,
                'category' => $data['category'],
                'status' => 'active',
                'admission_date' => $data['admission_date'] ?? now()->toDateString(),
                'current_section_id' => $data['class_section_id'],
                'address' => $admission->address,
                'previous_school' => $admission->previous_school,
            ]);

            $guardian = Guardian::create([
                'first_name' => $admission->guardian_name,
                'last_name' => $admission->last_name,
                'phone' => $admission->guardian_phone,
                'email' => $admission->guardian_email,
                'address' => $admission->address,
            ]);

            $student->guardians()->attach($guardian->id, [
                'relationship' => $admission->guardian_relationship,
                'is_primary' => true,
                'is_emergency' => true,
            ]);

            if ($year) {
                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'class_section_id' => $data['class_section_id'],
                    'status' => 'active',
                    'enrolled_on' => $student->admission_date->toDateString(),
                ]);
            }

            StudentStatusHistory::create([
                'student_id' => $student->id,
                'status' => 'active',
                'reason' => 'Converted from application '.$admission->application_no,
                'changed_by' => $request->user()->id,
            ]);

            $admission->update([
                'status' => 'admitted',
                'converted_student_id' => $student->id,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            Audit::record('application_converted', $admission, [], ['converted_student_id' => $student->id], null, 'Application converted to student record');

            return $student;
        });

        return redirect()->route('students.show', $student)->with('success', 'Application converted. Student record created with admission number '.$student->admission_no.'.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'academic_year_id' => ['nullable', 'exists:academic_years,id'],
            'level_id' => ['nullable', 'exists:levels,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'guardian_name' => ['required', 'string', 'max:150'],
            'guardian_phone' => ['required', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'guardian_relationship' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'assessment_notes' => ['nullable', 'string'],
        ]);
    }
}
