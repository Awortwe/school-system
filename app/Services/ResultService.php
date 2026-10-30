<?php

namespace App\Services;

use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\PublishedReport;
use App\Models\ReportPublication;
use App\Models\ResultEvent;
use App\Models\ResultSheet;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResultService
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly ReportCardService $reportCards,
    ) {
    }

    public function submit(ResultSheet $sheet, User $user): void
    {
        DB::transaction(function () use ($sheet, $user) { $this->submitLocked(ResultSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail(), $user); });
    }

    private function submitLocked(ResultSheet $sheet, User $user): void
    {
        if (! in_array($sheet->status, ['draft'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft result sheets can be submitted.']);
        }

        $this->grading->recalculateSheet($sheet);

        $sheet->refresh()->load(['results.student']);
        $incomplete = $sheet->results->filter(fn ($result) => ! $result->is_complete)->map(fn ($result) => $result->student?->fullName())->filter()->values();

        $studentCount = Student::where('current_section_id', $sheet->class_section_id)->where('status', 'active')->count();
        if ($incomplete->isNotEmpty()) {
            throw ValidationException::withMessages([
                'scores' => 'Missing or incomplete scores: '.$incomplete->take(8)->implode(', ').($incomplete->count() > 8 ? ' and others' : ''),
            ]);
        }

        if ($studentCount === 0) {
            throw ValidationException::withMessages(['scores' => 'There are no active students in this class.']);
        }

        DB::transaction(function () use ($sheet, $user) {
            $sheet = ResultSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($sheet->status !== 'draft') throw ValidationException::withMessages(['status' => 'Sheet is no longer in draft.']);
            $sheet->update([
                'status' => 'submitted',
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);

            ResultEvent::create([
                'result_sheet_id' => $sheet->id,
                'class_section_id' => $sheet->class_section_id,
                'term_id' => $sheet->term_id,
                'event' => 'submitted',
                'user_id' => $user->id,
            ]);

            Audit::record('result_submitted', $sheet, [], ['status' => 'submitted'], null, 'Result sheet submitted');
        });
    }

    public function approve(ResultSheet $sheet, User $user): void
    {
        if ($sheet->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Only submitted result sheets can be approved.']);
        }

        DB::transaction(function () use ($sheet, $user) {
            $sheet = ResultSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($sheet->status !== 'submitted') throw ValidationException::withMessages(['status' => 'Sheet is no longer submitted.']);
            $sheet->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'is_locked' => true,
            ]);

            ResultEvent::create([
                'result_sheet_id' => $sheet->id,
                'class_section_id' => $sheet->class_section_id,
                'term_id' => $sheet->term_id,
                'event' => 'approved',
                'user_id' => $user->id,
            ]);

            Audit::record('result_approved', $sheet, [], ['status' => 'approved', 'is_locked' => true], null, 'Result sheet approved and locked');
        });
    }

    public function reopen(ResultSheet $sheet, User $user, string $reason): void
    {
        if (! in_array($sheet->status, ['submitted', 'approved', 'published'], true)) {
            throw ValidationException::withMessages(['status' => 'This result sheet cannot be reopened.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to reopen results.']);
        }

        DB::transaction(function () use ($sheet, $user, $reason) {
            $sheet = ResultSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sheet->status, ['submitted', 'approved', 'published'], true)) throw ValidationException::withMessages(['status' => 'Sheet cannot be reopened from its current stage.']);
            $old = $sheet->only(['status', 'is_locked']);

            $sheet->update([
                'status' => 'draft',
                'is_locked' => false,
                'reopened_count' => $sheet->reopened_count + 1,
            ]);

            ResultEvent::create([
                'result_sheet_id' => $sheet->id,
                'class_section_id' => $sheet->class_section_id,
                'term_id' => $sheet->term_id,
                'event' => 'reopened',
                'reason' => $reason,
                'user_id' => $user->id,
            ]);

            ReportPublication::where('class_section_id', $sheet->class_section_id)
                ->where('term_id', $sheet->term_id)
                ->update(['status' => 'reopened']);

            Audit::record('result_reopened', $sheet, $old, ['status' => 'draft', 'is_locked' => false], $reason, 'Result sheet reopened');
        });
    }

    public function publishSection(ClassSection $section, Term $term, User $user): int
    {
        $classSubjects = ClassSubject::with('subject')
            ->where('class_section_id', $section->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where(function ($query) use ($term) {
                $query->whereNull('term_id')->orWhere('term_id', $term->id);
            })
            ->get();

        $examinable = $classSubjects->filter(fn ($cs) => $cs->subject?->is_examinable);

        $isMontessori = $section->schoolClass?->level?->curriculum === 'Montessori';
        if ($examinable->isEmpty() && ! $isMontessori) {
            throw ValidationException::withMessages(['results' => 'No examinable subjects are assigned to this class.']);
        }

        $sheets = ResultSheet::where('class_section_id', $section->id)
            ->where('term_id', $term->id)
            ->whereIn('subject_id', $examinable->pluck('subject_id'))
            ->get();

        $missing = $examinable->filter(fn ($cs) => ! $sheets->firstWhere('subject_id', $cs->subject_id));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['results' => 'Result sheets have not been created for: '.$missing->map(fn ($cs) => $cs->subject?->name)->implode(', ')]);
        }

        $unapproved = $sheets->filter(fn ($sheet) => ! in_array($sheet->status, ['approved', 'published'], true));
        if ($unapproved->isNotEmpty()) {
            throw ValidationException::withMessages(['results' => 'All result sheets must be approved before publishing. Pending: '.$unapproved->map(fn ($sheet) => $sheet->subject?->name)->implode(', ')]);
        }

        $students = Student::where('current_section_id', $section->id)->where('status', 'active')->orderBy('last_name')->get();

        if ($students->isEmpty()) {
            throw ValidationException::withMessages(['results' => 'There are no active students in this class.']);
        }
        if ($isMontessori && $examinable->isEmpty()) {
            foreach ($students as $student) if (! DB::table('learning_observations')->where('student_id', $student->id)->where('term_id', $term->id)->exists()) throw ValidationException::withMessages(['results' => 'Every learner needs recorded observations before narrative reports can be published.']);
        }

        $snapshots = DB::transaction(function () use ($section, $term, $user, $students, $sheets) {
            ClassSection::whereKey($section->id)->lockForUpdate()->firstOrFail();
            $publication = ReportPublication::updateOrCreate(
                ['class_section_id' => $section->id, 'term_id' => $term->id],
                ['status' => 'published', 'published_by' => $user->id, 'published_at' => now()],
            );

            $created = [];

            foreach ($students as $student) {
                $version = ((int) PublishedReport::where('student_id', $student->id)->where('term_id', $term->id)->max('version')) + 1;
                $payload = $this->reportCards->buildPayload($student, $term, $section, false);

                $created[] = PublishedReport::create([
                    'student_id' => $student->id,
                    'term_id' => $term->id,
                    'class_section_id' => $section->id,
                    'version' => $version,
                    'payload' => $payload,
                    'published_by' => $user->id,
                    'published_at' => now(),
                ]);
            }

            $sheets->each(function (ResultSheet $sheet) use ($user) {
                $sheet->update(['status' => 'published']);
                ResultEvent::create([
                    'result_sheet_id' => $sheet->id,
                    'class_section_id' => $sheet->class_section_id,
                    'term_id' => $sheet->term_id,
                    'event' => 'published',
                    'user_id' => $user->id,
                ]);
            });

            ResultEvent::create([
                'class_section_id' => $section->id,
                'term_id' => $term->id,
                'event' => 'section_published',
                'user_id' => $user->id,
            ]);

            Audit::record('report_publication', $publication, [], ['status' => 'published', 'students' => count($created)], null, 'Class report cards published');

            return $created;
        });

        foreach (count($snapshots) <= 30 ? $snapshots : [] as $snapshot) {
            $path = $this->reportCards->storePublishedPdf($snapshot);
            $snapshot->update(['pdf_path' => $path]);
        }

        return count($snapshots);
    }
}
