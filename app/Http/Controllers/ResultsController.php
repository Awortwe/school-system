<?php

namespace App\Http\Controllers;

use App\Models\AssessmentScore;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\PublishedReport;
use App\Models\ResultSheet;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermSubjectResult;
use App\Services\GradingService;
use App\Services\ResultService;
use App\Support\Audit;
use App\Support\StudentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ResultsController extends Controller
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly ResultService $results,
    ) {
    }

    public function index(Request $request): Response
    {
        $sections = $this->sections();
        $section = $sections->firstWhere('id', (int) $request->input('section_id')) ?? $sections->first();
        $section = $section ? (object) $section : null;
        $term = Term::find($request->input('term_id')) ?? Term::where('is_current', true)->first();

        $sheets = collect();

        if ($section && $term) {
            $sheets = ResultSheet::with(['subject', 'teacher'])
                ->when(! StudentAccess::schoolWide($request->user()), fn ($query) => $query->where('teacher_id', $request->user()->id))
                ->where('class_section_id', $section->id)
                ->where('term_id', $term->id)
                ->orderBy('subject_id')
                ->get()
                ->map(function (ResultSheet $sheet) {
                    $studentCount = Student::where('current_section_id', $sheet->class_section_id)->where('status', 'active')->count();
                    $complete = TermSubjectResult::where('result_sheet_id', $sheet->id)->where('is_complete', true)->count();
                    $entered = AssessmentScore::where('result_sheet_id', $sheet->id)->whereNotNull('score')->count();

                    return [
                        'id' => $sheet->id,
                        'subject' => $sheet->subject?->name,
                        'teacher' => $sheet->teacher?->name,
                        'status' => $sheet->status,
                        'is_locked' => $sheet->is_locked,
                        'students' => $studentCount,
                        'complete' => $complete,
                        'entered' => $entered,
                        'updated_at' => $sheet->updated_at?->toDateTimeString(),
                    ];
                });
        }

        return Inertia::render('Results/Index', [
            'sections' => $sections,
            'terms' => $this->terms(),
            'sheets' => $sheets,
            'selectedSectionId' => $section?->id,
            'selectedTermId' => $term?->id,
            'publications' => $section && $term
                ? \App\Models\ReportPublication::where('class_section_id', $section->id)->where('term_id', $term->id)->first()
                : null,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'term_id' => ['required', 'exists:terms,id'],
        ]);

        $term = Term::findOrFail($data['term_id']);
        $section = ClassSection::findOrFail($data['class_section_id']);

        $classSubjects = ClassSubject::with('subject')
            ->when(! StudentAccess::schoolWide($request->user()), fn ($query) => $query->where('teacher_id', $request->user()->id))
            ->where('class_section_id', $section->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where(function ($query) use ($term) {
                $query->whereNull('term_id')->orWhere('term_id', $term->id);
            })
            ->get()
            ->filter(fn (ClassSubject $assignment) => $assignment->subject?->is_examinable);

        $created = 0;

        foreach ($classSubjects as $assignment) {
            $sheet = ResultSheet::firstOrCreate([
                'class_section_id' => $section->id,
                'subject_id' => $assignment->subject_id,
                'term_id' => $term->id,
            ], [
                'academic_year_id' => $term->academic_year_id,
                'teacher_id' => $assignment->teacher_id,
                'status' => 'draft',
            ]);

            if ($sheet->wasRecentlyCreated) {
                $created++;
            }
        }

        Audit::record('result_sheets_generated', null, [], ['class_section_id' => $section->id, 'term_id' => $term->id, 'created' => $created], null, 'Result sheets generated');

        return back()->with('success', $created.' result sheet(s) created.');
    }

    public function show(ResultSheet $sheet): Response
    {
        $sheet->load(['subject', 'teacher', 'term.academicYear', 'classSection.schoolClass.level']);

        $components = $this->grading->componentsFor($sheet);

        $students = Student::where('current_section_id', $sheet->class_section_id)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'admission_no' => $student->admission_no,
            ]);

        $scores = AssessmentScore::where('result_sheet_id', $sheet->id)
            ->get()
            ->groupBy('student_id')
            ->map(fn ($studentScores) => $studentScores->keyBy('assessment_component_id')->map(fn (AssessmentScore $score) => [
                'score' => $score->score,
                'is_exempt' => $score->is_exempt,
            ]));

        $results = TermSubjectResult::where('result_sheet_id', $sheet->id)
            ->get()
            ->keyBy('student_id')
            ->map(fn (TermSubjectResult $result) => [
                'total_score' => $result->total_score,
                'grade' => $result->grade,
                'remark' => $result->remark,
                'is_complete' => $result->is_complete,
                'teacher_comment' => $result->teacher_comment,
            ]);

        return Inertia::render('Results/Show', [
            'sheet' => [
                'id' => $sheet->id,
                'status' => $sheet->status,
                'is_locked' => $sheet->is_locked,
                'reopened_count' => $sheet->reopened_count,
                'subject' => $sheet->subject?->name,
                'class' => $sheet->classSection?->displayName(),
                'term' => $sheet->term?->name,
                'year' => $sheet->term?->academicYear?->name,
                'teacher' => $sheet->teacher?->name,
            ],
            'components' => $components->map(fn ($component) => [
                'id' => $component->id,
                'name' => $component->name,
                'code' => $component->code,
                'max_score' => $component->max_score,
                'weight' => $component->weight,
                'is_exam' => $component->is_exam,
            ]),
            'students' => $students,
            'scores' => $scores,
            'results' => $results,
            'canEdit' => $sheet->status === 'draft' && ! $sheet->is_locked,
        ]);
    }

    public function updateScores(Request $request, ResultSheet $sheet): RedirectResponse
    {
        if ($sheet->status !== 'draft' || $sheet->is_locked) {
            return back()->with('error', 'This result sheet is locked and cannot be edited.');
        }

        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.*.student_id' => ['required', 'exists:students,id'],
            'scores.*.assessment_component_id' => ['required', 'exists:assessment_components,id'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0'],
            'scores.*.is_exempt' => ['boolean'],
            'comments' => ['array'],
            'comments.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $components = $this->grading->componentsFor($sheet)->keyBy('id');
        $validStudents = Student::where('current_section_id', $sheet->class_section_id)->where('status', 'active')->pluck('id')->all();

        DB::transaction(function () use ($request, $sheet, $data, $components, $validStudents) {
            $sheet = ResultSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($sheet->status !== 'draft' || $sheet->is_locked) throw \Illuminate\Validation\ValidationException::withMessages(['scores' => 'The sheet was locked by another staff member. Reload before continuing.']);
            foreach ($data['scores'] as $row) {
                $component = $components->get($row['assessment_component_id']);

                if (! $component || ! in_array((int) $row['student_id'], $validStudents, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['scores' => 'A student or component does not belong to this result sheet.']);
                }

                if ($row['score'] !== null && (float) $row['score'] > (float) $component->max_score) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'scores' => 'A score cannot exceed the maximum of '.$component->max_score.' for '.$component->name.'.',
                    ]);
                }

                AssessmentScore::updateOrCreate(
                    [
                        'result_sheet_id' => $sheet->id,
                        'student_id' => $row['student_id'],
                        'assessment_component_id' => $row['assessment_component_id'],
                    ],
                    [
                        'score' => ! empty($row['is_exempt']) || $row['score'] === null ? null : $row['score'],
                        'is_exempt' => (bool) ($row['is_exempt'] ?? false),
                        'entered_by' => $request->user()->id,
                    ],
                );
            }

            $this->grading->recalculateSheet($sheet);

            foreach ($data['comments'] ?? [] as $studentId => $comment) {
                if (! in_array((int) $studentId, $validStudents, true)) throw \Illuminate\Validation\ValidationException::withMessages(['comments' => 'Comment student does not belong to this class.']);
                TermSubjectResult::where('result_sheet_id', $sheet->id)->where('student_id', $studentId)->update([
                    'teacher_comment' => $comment,
                ]);
            }

            Audit::record('result_scores_saved', $sheet, [], ['students' => count($data['scores'])], null, 'Assessment scores saved');
        });

        return back()->with('success', 'Scores saved and totals recalculated.');
    }

    public function submit(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $this->results->submit($sheet, $request->user());

        return back()->with('success', 'Result sheet submitted for approval.');
    }

    public function approve(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $this->results->approve($sheet, $request->user());

        return back()->with('success', 'Result sheet approved and locked.');
    }

    public function reopen(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->results->reopen($sheet, $request->user(), $data['reason']);

        return back()->with('success', 'Result sheet reopened. Published report snapshots are preserved.');
    }

    public function publish(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_section_id' => ['required', 'exists:class_sections,id'],
            'term_id' => ['required', 'exists:terms,id'],
        ]);

        $count = $this->results->publishSection(
            ClassSection::findOrFail($data['class_section_id']),
            Term::findOrFail($data['term_id']),
            $request->user(),
        );

        return back()->with('success', $count.' report card(s) published.');
    }

    public function reports(Request $request): Response
    {
        $filters = $request->only(['term_id', 'section_id', 'search']);

        $reports = PublishedReport::with(['student', 'term.academicYear', 'classSection.schoolClass'])
            ->when(! StudentAccess::schoolWide($request->user()), fn ($query) => $query->whereHas('classSection', fn ($query) => $query->where('class_teacher_id', $request->user()->id)))
            ->when($filters['term_id'] ?? null, fn ($query, $term) => $query->where('term_id', $term))
            ->when($filters['section_id'] ?? null, fn ($query, $section) => $query->where('class_section_id', $section))
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->whereHas('student', function ($query) use ($search) {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('admission_no', 'like', "%{$search}%");
                });
            })
            ->latest('published_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PublishedReport $report) => [
                'id' => $report->id,
                'student' => $report->student?->fullName(),
                'admission_no' => $report->student?->admission_no,
                'class' => $report->classSection?->displayName(),
                'term' => $report->term?->name,
                'year' => $report->term?->academicYear?->name,
                'version' => $report->version,
                'published_at' => $report->published_at?->toDateTimeString(),
                'has_pdf' => (bool) $report->pdf_path,
            ]);

        return Inertia::render('Results/Reports', [
            'reports' => $reports,
            'filters' => $filters,
            'terms' => $this->terms(),
            'sections' => $this->sections(),
        ]);
    }

    private function sections(): \Illuminate\Support\Collection
    {
        return StudentAccess::sections(request()->user())->with('schoolClass.level')->get()
            ->sortBy(fn (ClassSection $section) => [
                $section->schoolClass?->level?->sort_order ?? 0,
                $section->schoolClass?->sort_order ?? 0,
                $section->name,
            ])
            ->map(fn (ClassSection $section) => [
                'id' => $section->id,
                'name' => $section->displayName(),
            ])
            ->values();
    }

    private function terms(): \Illuminate\Support\Collection
    {
        return Term::with('academicYear')
            ->orderByDesc('academic_year_id')
            ->orderBy('sequence')
            ->get()
            ->map(fn (Term $term) => [
                'id' => $term->id,
                'name' => ($term->academicYear?->name ? $term->academicYear->name.' - ' : '').$term->name,
            ]);
    }
}
