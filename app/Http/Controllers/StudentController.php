<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\Term;
use App\Models\TermSubjectResult;
use App\Services\AttendanceSummaryService;
use App\Services\DocumentNumberService;
use App\Services\FinanceService;
use App\Support\Audit;
use App\Support\StudentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly AttendanceSummaryService $attendance,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'section_id', 'status', 'category']);

        $students = StudentAccess::students($request->user())->with(['currentSection.schoolClass.level'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('admission_no', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('guardians', fn ($guardian) => $guardian->where('phone', 'like', "%{$search}%"));
                });
            })
            ->when($filters['section_id'] ?? null, fn ($query, $section) => $query->where('current_section_id', $section))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Student $student) => [
                'id' => $student->id,
                'admission_no' => $student->admission_no,
                'name' => $student->fullName(),
                'gender' => $student->gender,
                'category' => $student->category,
                'status' => $student->status,
                'class' => $student->currentSection?->displayName(),
                'level' => $student->currentSection?->schoolClass?->level?->name,
                'phone' => $student->phone,
                'photo_url' => $student->photo_path ? '/student-photos/'.$student->id : null,
                'balance_minor' => $request->user()->can('reports.financial') ? $this->finance->currentBalance($student) : null,
            ]);

        return Inertia::render('Students/Index', [
            'students' => $students,
            'filters' => $filters,
            'sections' => $this->sectionOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Students/Create', [
            'sections' => $this->sectionOptions(),
            'currentYear' => AcademicYear::where('is_current', true)->first(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['admission_no'] = ($data['admission_no'] ?? null) ?: DocumentNumberService::nextAdmission((int) now()->format('Y'));

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('students', 'local');
        }

        $studentData = \Illuminate\Support\Arr::only($data, [
            'admission_no', 'first_name', 'middle_name', 'last_name', 'date_of_birth',
            'gender', 'category', 'status', 'admission_date', 'current_section_id',
            'address', 'phone', 'email', 'nationality', 'religion', 'medical_notes',
            'previous_school', 'photo_path',
        ]);

        $student = DB::transaction(function () use ($request, $data, $studentData) {
            $year = AcademicYear::where('is_current', true)->first();

            if (! empty($studentData['current_section_id']) && $year) {
                $studentData['status'] = $studentData['status'] ?? 'active';
            }

            $student = Student::create($studentData);

            if ($request->filled('guardian_name') || $request->filled('guardian_phone')) {
                $guardian = Guardian::create([
                    'first_name' => $request->string('guardian_name')->toString(),
                    'last_name' => $request->string('guardian_last_name')->toString() ?: $data['last_name'],
                    'phone' => $request->string('guardian_phone')->toString(),
                    'email' => $request->input('guardian_email'),
                    'address' => $request->input('guardian_address') ?: ($data['address'] ?? null),
                    'occupation' => $request->input('guardian_occupation'),
                ]);

                $student->guardians()->attach($guardian->id, [
                    'relationship' => $request->input('guardian_relationship', 'parent'),
                    'is_primary' => true,
                    'is_emergency' => true,
                ]);
            }

            if ($student->current_section_id && $year) {
                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'class_section_id' => $student->current_section_id,
                    'status' => 'active',
                    'enrolled_on' => $student->admission_date ?? now()->toDateString(),
                ]);
            }

            StudentStatusHistory::create([
                'student_id' => $student->id,
                'status' => $student->status,
                'reason' => 'Student record created',
                'changed_by' => $request->user()->id,
            ]);

            Audit::record('student_created', $student, [], $student->only(['admission_no', 'first_name', 'last_name']), null, 'Student record created');

            return $student;
        });

        return redirect()->route('students.show', $student)->with('success', 'Student admitted successfully.');
    }

    public function show(Request $request, Student $student): Response
    {
        StudentAccess::authorize($request->user(), $student);
        $student->load([
            'currentSection.schoolClass.level',
            'guardians',
            'enrollments.academicYear',
            'enrollments.classSection.schoolClass',
        ]);

        $currentTerm = Term::where('is_current', true)->first();

        $results = $currentTerm && $request->user()->can('results.view')
            ? TermSubjectResult::with('resultSheet.subject')
                ->where('student_id', $student->id)
                ->whereHas('resultSheet', fn ($query) => $query->where('term_id', $currentTerm->id)
                    ->when(! StudentAccess::schoolWide($request->user()), fn ($query) => $query->where('teacher_id', $request->user()->id)))
                ->get()
                ->map(fn (TermSubjectResult $result) => [
                    'subject' => $result->resultSheet?->subject?->name,
                    'total_score' => $result->total_score,
                    'grade' => $result->grade,
                    'remark' => $result->remark,
                    'is_complete' => $result->is_complete,
                ])
            : [];

        return Inertia::render('Students/Show', [
            'student' => [
                'id' => $student->id,
                'admission_no' => $student->admission_no,
                'name' => $student->fullName(),
                'first_name' => $student->first_name,
                'middle_name' => $student->middle_name,
                'last_name' => $student->last_name,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'category' => $student->category,
                'status' => $student->status,
                'admission_date' => $student->admission_date?->toDateString(),
                'address' => $student->address,
                'phone' => $student->phone,
                'email' => $student->email,
                'nationality' => $student->nationality,
                'religion' => $student->religion,
                'medical_notes' => $request->user()->can('students.update') || $request->user()->can('attendance.record') ? $student->medical_notes : null,
                'previous_school' => $student->previous_school,
                'photo_url' => $student->photo_path ? '/student-photos/'.$student->id : null,
                'class' => $student->currentSection?->displayName(),
                'level' => $student->currentSection?->schoolClass?->level?->name,
                'section_id' => $student->current_section_id,
            ],
            'guardians' => $student->guardians->map(fn (Guardian $guardian) => [
                'id' => $guardian->id,
                'first_name' => $guardian->first_name,
                'last_name' => $guardian->last_name,
                'name' => trim($guardian->first_name.' '.$guardian->last_name),
                'phone' => $guardian->phone,
                'email' => $guardian->email,
                'address' => $guardian->address,
                'occupation' => $guardian->occupation,
                'relationship' => $guardian->pivot->relationship,
                'is_primary' => (bool) $guardian->pivot->is_primary,
                'is_emergency' => (bool) $guardian->pivot->is_emergency,
            ]),
            'guardianOptions' => $request->user()->can('guardians.create') ? Guardian::orderBy('last_name')->get()->map(fn ($guardian) => ['id' => $guardian->id, 'name' => $guardian->fullName().' · '.$guardian->phone]) : [],
            'siblings' => StudentAccess::students($request->user())->whereKeyNot($student->id)->whereHas('guardians', fn ($query) => $query->whereIn('guardians.id', $student->guardians->pluck('id')))->get()->map(fn ($sibling) => ['id' => $sibling->id, 'name' => $sibling->fullName()]),
            'enrollments' => $student->enrollments->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'year' => $enrollment->academicYear?->name,
                'class' => $enrollment->classSection?->displayName(),
                'status' => $enrollment->status,
                'enrolled_on' => $enrollment->enrolled_on?->toDateString(),
            ]),
            'attendance' => $currentTerm && $request->user()->can('attendance.view') ? $this->attendance->forStudentTerm($student, $currentTerm) : null,
            'results' => $results,
            'currentTerm' => $currentTerm?->only(['id', 'name']),
            'balance_minor' => $request->user()->can('reports.financial') ? $this->finance->currentBalance($student) : null,
            'ledger' => ! $request->user()->can('reports.financial') ? [] : $student->ledgerEntries()->latest('id')->limit(10)->get()->map(fn ($entry) => [
                'id' => $entry->id,
                'date' => $entry->entry_date?->toDateString(),
                'type' => $entry->type,
                'description' => $entry->description,
                'reference' => $entry->reference,
                'debit_minor' => $entry->debit_minor,
                'credit_minor' => $entry->credit_minor,
                'balance_minor' => $entry->balance_minor,
            ]),
            'invoices' => ! $request->user()->can('reports.financial') ? [] : $student->invoices()->latest('id')->limit(5)->get()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_minor' => $invoice->total_minor,
                'balance_minor' => $invoice->balance_minor,
                'status' => $invoice->status,
            ]),
            'payments' => ! $request->user()->can('reports.financial') ? [] : $student->payments()->where('status', 'confirmed')->latest('id')->limit(5)->get()->map(fn ($payment) => [
                'id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_minor' => $payment->amount_minor,
                'method' => $payment->method,
            ]),
            'sections' => $this->sectionOptions(),
        ]);
    }

    public function edit(Student $student): Response
    {
        StudentAccess::authorize(request()->user(), $student);
        $student->load('guardians');

        return Inertia::render('Students/Edit', [
            'student' => [
                'id' => $student->id,
                'admission_no' => $student->admission_no,
                'first_name' => $student->first_name,
                'middle_name' => $student->middle_name,
                'last_name' => $student->last_name,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'category' => $student->category,
                'status' => $student->status,
                'admission_date' => $student->admission_date?->toDateString(),
                'current_section_id' => $student->current_section_id,
                'address' => $student->address,
                'phone' => $student->phone,
                'email' => $student->email,
                'nationality' => $student->nationality,
                'religion' => $student->religion,
                'medical_notes' => $student->medical_notes,
                'previous_school' => $student->previous_school,
                'photo_url' => $student->photo_path ? '/student-photos/'.$student->id : null,
            ],
            'sections' => $this->sectionOptions(),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        StudentAccess::authorize($request->user(), $student);
        $data = $this->validated($request, $student);
        $oldSection = $student->current_section_id;
        $oldStatus = $student->status;

        DB::transaction(function () use ($request, $student, $data, $oldSection, $oldStatus) {
            if ($request->hasFile('photo')) {
                if ($student->photo_path) {
                    Storage::disk('public')->delete($student->photo_path);
                }

                $data['photo_path'] = $request->file('photo')->store('students', 'local');
            }

            $student->update(\Illuminate\Support\Arr::only($data, [
                'admission_no', 'first_name', 'middle_name', 'last_name', 'date_of_birth',
                'gender', 'category', 'status', 'admission_date', 'current_section_id',
                'address', 'phone', 'email', 'nationality', 'religion', 'medical_notes',
                'previous_school', 'photo_path',
            ]));

            if ((string) $oldSection !== (string) $student->current_section_id) {
                $year = AcademicYear::where('is_current', true)->first();

                if ($year) {
                    $student->enrollments()->where('academic_year_id', $year->id)
                        ->where('status', 'active')->update(['status' => 'transferred', 'left_on' => now()->toDateString()]);
                    if ($student->current_section_id) {
                        Enrollment::create([
                            'student_id' => $student->id,
                            'academic_year_id' => $year->id,
                            'class_section_id' => $student->current_section_id,
                            'status' => 'active',
                            'enrolled_on' => now()->toDateString(),
                            'promoted_from_section_id' => $oldSection,
                        ]);
                    }
                }
            }

            if ($oldStatus !== $student->status) {
                StudentStatusHistory::create([
                    'student_id' => $student->id,
                    'status' => $student->status,
                    'reason' => $request->input('status_reason', 'Status changed by staff'),
                    'changed_by' => $request->user()->id,
                ]);
            }

            Audit::record('student_updated', $student, [], $student->only(['admission_no', 'current_section_id', 'status']), null, 'Student record updated');
        });

        return redirect()->route('students.show', $student)->with('success', 'Student record updated.');
    }

    public function destroy(Request $request, Student $student): RedirectResponse
    {
        StudentAccess::authorize($request->user(), $student);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $student, $data) {
            $old = $student->status;
            $student->update(['status' => 'archived', 'archived_at' => now()]);
            StudentStatusHistory::create(['student_id' => $student->id, 'status' => 'archived', 'reason' => $data['reason'], 'changed_by' => $request->user()->id]);
            Audit::record('student_archived', $student, ['status' => $old], ['status' => 'archived'], $data['reason'], 'Student archived; historical records remain accessible');
        });

        return redirect()->route('students.index')->with('success', 'Student record archived. Historical financial and academic records are preserved.');
    }

    private function validated(Request $request, ?Student $student = null): array
    {
        return $request->validate([
            'admission_no' => [$student ? 'required' : 'nullable', 'string', 'max:50', Rule::unique('students', 'admission_no')->ignore($student?->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'category' => ['required', Rule::in(['day', 'boarding'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'graduated', 'transferred', 'withdrawn', 'archived'])],
            'admission_date' => ['nullable', 'date'],
            'current_section_id' => ['nullable', Rule::exists('class_sections', 'id')->whereIn('id', StudentAccess::sections($request->user())->pluck('id')->all())],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'religion' => ['nullable', 'string', 'max:100'],
            'medical_notes' => ['nullable', 'string'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'guardian_name' => ['nullable', 'required_with:guardian_phone', 'string', 'max:100'],
            'guardian_last_name' => ['nullable', 'string', 'max:100'],
            'guardian_phone' => ['nullable', 'required_with:guardian_name', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'guardian_relationship' => ['nullable', 'string', 'max:30'],
            'guardian_address' => ['nullable', 'string'],
            'guardian_occupation' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function sectionOptions(): \Illuminate\Support\Collection
    {
        return StudentAccess::sections(request()->user())->with('schoolClass.level')
            ->get()
            ->sortBy(fn (ClassSection $section) => [$section->schoolClass?->level?->sort_order ?? 0, $section->schoolClass?->sort_order ?? 0, $section->name])
            ->map(fn (ClassSection $section) => [
                'id' => $section->id,
                'name' => $section->displayName(),
                'level' => $section->schoolClass?->level?->name,
            ])
            ->values();
    }
}

