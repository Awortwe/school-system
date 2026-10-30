<?php

namespace App\Services;

use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\ConductAssessment;
use App\Models\Enrollment;
use App\Models\PublishedReport;
use App\Models\ReportSignature;
use App\Models\ResultSheet;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ReportCardService
{
    private function embeddedImage(?string $path): ?string
    {
        if (! $path || ! is_file($path)) return null;
        $mime = mime_content_type($path);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return null;
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }
    public function __construct(
        private readonly GradingService $grading,
        private readonly AttendanceSummaryService $attendance,
    ) {
    }

    public function buildPayload(Student $student, Term $term, ?ClassSection $section = null, bool $draft = false): array
    {
        $enrollment = Enrollment::where('student_id', $student->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where(function ($query) use ($term) {
                $query->where('term_id', $term->id)->orWhereNull('term_id');
            })
            ->orderByDesc('id')
            ->first();

        $section ??= $enrollment?->classSection ?? $student->currentSection;
        $section?->loadMissing('schoolClass.level');

        $classSubjects = ClassSubject::with('subject')
            ->where('class_section_id', $section?->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where(function ($query) use ($term) {
                $query->whereNull('term_id')->orWhere('term_id', $term->id);
            })
            ->get()
            ->filter(fn ($classSubject) => $classSubject->subject?->is_examinable)
            ->sortBy(fn ($classSubject) => $classSubject->subject?->sort_order)
            ->values();

        $sheets = ResultSheet::with(['scores', 'results'])
            ->where('class_section_id', $section?->id)
            ->where('term_id', $term->id)
            ->get()
            ->keyBy('subject_id');

        $components = $sheets->first() ? $this->grading->componentsFor($sheets->first()) : collect();

        $rows = [];
        $totals = [];

        foreach ($classSubjects as $classSubject) {
            $sheet = $sheets->get($classSubject->subject_id);
            $result = $sheet?->results->firstWhere('student_id', $student->id);
            $scores = $sheet?->scores->where('student_id', $student->id)->keyBy('assessment_component_id') ?? collect();

            $componentRows = $components->map(function ($component) use ($scores) {
                $score = $scores->get($component->id);

                return [
                    'name' => $component->name,
                    'code' => $component->code,
                    'score' => $score?->score,
                    'max_score' => (float) $component->max_score,
                    'weight' => (float) $component->weight,
                    'is_exempt' => (bool) $score?->is_exempt,
                    'is_exam' => (bool) $component->is_exam,
                    'missing' => ! $score || (! $score->is_exempt && $score->score === null),
                ];
            })->all();

            if ($result?->is_complete && $result->total_score !== null) {
                $totals[] = (float) $result->total_score;
            }

            $rows[] = [
                'subject' => $classSubject->subject->name,
                'subject_code' => $classSubject->subject->code,
                'subject_id' => $classSubject->subject_id,
                'is_core' => (bool) $classSubject->subject->is_core,
                'components' => $componentRows,
                'total_score' => $result?->total_score !== null ? (float) $result->total_score : null,
                'grade' => $result?->grade,
                'remark' => $result?->remark,
                'points' => $result?->points !== null ? (float) $result->points : null,
                'teacher_comment' => $result?->teacher_comment,
                'is_complete' => (bool) $result?->is_complete,
                'result_status' => $sheet?->status,
            ];
        }

        $sectionRows = $section ? $this->grading->sectionResults($section, $term, ! $draft) : [];
        $studentRow = collect($sectionRows)->first(fn ($row) => $row['student']->id === $student->id);

        $average = count($totals) ? round(array_sum($totals) / count($totals), 1) : null;
        $conduct = ConductAssessment::where('student_id', $student->id)->where('term_id', $term->id)->first();
        $attendance = $this->attendance->forStudentTerm($student, $term);

        $nextTerm = Term::where('academic_year_id', $term->academic_year_id)
            ->where('sequence', '>', $term->sequence)
            ->orderBy('sequence')
            ->first();

        $signatures = ReportSignature::where('is_active', true)->orderBy('sort_order')->get()->map(fn ($signature) => [
            'title' => $signature->title,
            'name' => $signature->name,
            'image_path' => $signature->image_path ? $this->embeddedImage(Storage::disk(Storage::disk('local')->exists($signature->image_path) ? 'local' : 'public')->path($signature->image_path)) : null,
        ])->all();

        if (empty($signatures)) {
            $signatures = [
                ['title' => 'Class Teacher', 'name' => Setting::get('class_teacher_signature_name', 'Class Teacher'), 'image_path' => null],
                ['title' => 'Headteacher', 'name' => Setting::get('headteacher_signature_name', 'Headteacher'), 'image_path' => null],
            ];
        }

        $school = Setting::school();
        $logo = $school['logo_url'] ?? null;
        $school['logo_data_uri'] = $logo && str_starts_with($logo, '/') ? $this->embeddedImage(public_path(ltrim($logo, '/'))) : null;
        $photoDisk = $student->photo_path && Storage::disk('local')->exists($student->photo_path) ? 'local' : 'public';
        $template = \App\Models\ReportCardTemplate::where('is_default', true)->latest('id')->first()?->config ?? [];
        return [
            'is_draft' => $draft,
            'template' => $template,
            'school' => $school,
            'term' => [
                'id' => $term->id,
                'name' => $term->name,
                'academic_year' => $term->academicYear?->name,
                'start_date' => $term->start_date?->toDateString(),
                'end_date' => $term->end_date?->toDateString(),
                'closing_date' => $term->end_date?->toDateString(),
                'reopening_date' => $nextTerm?->reopening_date?->toDateString() ?? $nextTerm?->start_date?->toDateString(),
            ],
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'admission_no' => $student->admission_no,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'gender' => $student->gender,
                'photo_path' => $student->photo_path && Storage::disk($photoDisk)->exists($student->photo_path)
                    ? $this->embeddedImage(Storage::disk($photoDisk)->path($student->photo_path))
                    : null,
                'class' => $section?->displayName() ?? 'Unassigned',
                'class_section' => $section?->name,
                'level' => $section?->schoolClass?->level?->name,
            ],
            'subjects' => $rows,
            'observations' => \Illuminate\Support\Facades\DB::table('learning_observations')->where('student_id', $student->id)->where('term_id', $term->id)->orderBy('area')->get()->map(fn ($row) => (array) $row)->all(),
            'summary' => [
                'total' => count($totals) ? round(array_sum($totals), 1) : null,
                'average' => $average,
                'position' => $studentRow['position'] ?? null,
                'class_size' => $studentRow['class_size'] ?? null,
                'subjects_counted' => count($totals),
            ],
            'attendance' => $attendance,
            'conduct' => [
                'conduct' => $conduct?->conduct,
                'attitude' => $conduct?->attitude,
                'interest' => $conduct?->interest,
                'class_teacher_comment' => $conduct?->class_teacher_comment,
                'headteacher_comment' => $conduct?->headteacher_comment,
                'height' => $conduct?->height,
                'weight' => $conduct?->weight,
            ],
            'signatures' => $signatures,
            'settings' => [
                'show_position' => (bool) ($template['show_position'] ?? Setting::get('report_show_position', false)),
                'show_attendance' => (bool) ($template['show_attendance'] ?? Setting::get('report_show_attendance', true)),
                'show_conduct' => (bool) ($template['show_conduct'] ?? Setting::get('report_show_conduct', true)),
                'show_signatures' => (bool) ($template['show_signatures'] ?? Setting::get('report_show_signatures', true)),
                'headteacher_title' => Setting::get('headteacher_title', 'Headteacher'),
            ],
            'published_at' => now()->toDateTimeString(),
        ];
    }

    public function render(array $payload, bool $copy = false)
    {
        return Pdf::loadView('pdf.report-card', [
            'report' => $payload,
            'copy' => $copy,
        ])->setPaper('a4', 'portrait')->setOption([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
    }

    public function renderBatch(Collection $reports)
    {
        return Pdf::loadView('pdf.report-cards', [
            'reports' => $reports->map->payload->all(),
        ])->setPaper('a4', 'portrait')->setOption([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
    }

    public function storePublishedPdf(PublishedReport $report): string
    {
        $year = $report->term?->academicYear?->name ?? (string) now()->year;
        $year = preg_replace('/[^A-Za-z0-9_-]/', '-', $year);
        $path = "reports/{$year}/term-{$report->term_id}/student-{$report->student_id}-v{$report->version}.pdf";

        $pdf = $this->render($report->payload);
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }
}
