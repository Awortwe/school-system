<?php

namespace App\Http\Controllers;

use App\Models\ClassSection;
use App\Models\PublishedReport;
use App\Models\Student;
use App\Models\Term;
use App\Services\ReportCardService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportCardController extends Controller
{
    public function __construct(private readonly ReportCardService $reportCards)
    {
    }

    public function jobs(Request $request)
    {
        return \Inertia\Inertia::render('Results/Jobs', ['jobs' => \Illuminate\Support\Facades\DB::table('report_jobs')->where('user_id', $request->user()->id)->orderByDesc('id')->paginate(20)]);
    }
    public function jobDownload(Request $request, int $job)
    {
        $record = \Illuminate\Support\Facades\DB::table('report_jobs')->find($job);
        abort_unless($record && $record->user_id === $request->user()->id && $record->status === 'completed', 404);
        if (! \App\Support\StudentAccess::schoolWide($request->user())) abort_unless(ClassSection::whereKey($record->class_section_id)->where('class_teacher_id', $request->user()->id)->exists(), 403);
        return \Illuminate\Support\Facades\Storage::disk('local')->download($record->path, 'class-report-cards.pdf');
    }

    public function preview(Request $request, Student $student, Term $term): Response
    {
        $student->loadMissing('currentSection.schoolClass.level');

        $payload = $this->reportCards->buildPayload($student, $term, $student->currentSection, true);

        Audit::record('report_card_preview', $student, [], ['term_id' => $term->id], null, 'Draft report card previewed');

        return $this->reportCards->render($payload, $request->boolean('copy'))
            ->stream('report-card-'.$student->admission_no.'-draft.pdf');
    }

    public function published(Request $request, PublishedReport $report): Response
    {
        $report->loadMissing(['student', 'term.academicYear']);

        Audit::record('report_card_printed', $report, [], ['version' => $report->version], null, $request->boolean('copy') ? 'Published report card copy printed' : 'Published report card printed');

        return $this->reportCards->render($report->payload, $request->boolean('copy'))
            ->stream('report-card-'.$report->student?->admission_no.'-v'.$report->version.'.pdf');
    }

    public function download(PublishedReport $report): Response
    {
        $report->loadMissing(['student', 'term.academicYear']);

        return $this->reportCards->render($report->payload)
            ->download('report-card-'.$report->student?->admission_no.'-v'.$report->version.'.pdf');
    }

    public function batch(ClassSection $section, Term $term): Response
    {
        if (PublishedReport::where('class_section_id', $section->id)->where('term_id', $term->id)->count() > 30) {
            $job = \Illuminate\Support\Facades\DB::table('report_jobs')->insertGetId(['user_id' => auth()->id(), 'class_section_id' => $section->id, 'term_id' => $term->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            \App\Jobs\BuildClassReports::dispatch($job);
            return redirect('/report-jobs')->with('success', 'Class PDF queued for background generation.');
        }
        $reports = PublishedReport::with(['student', 'term.academicYear'])
            ->where('class_section_id', $section->id)
            ->where('term_id', $term->id)
            ->whereIn('id', function ($query) use ($section, $term) {
                $query->selectRaw('MAX(id)')
                    ->from('published_reports')
                    ->where('class_section_id', $section->id)
                    ->where('term_id', $term->id)
                    ->groupBy('student_id');
            })
            ->orderBy('student_id')
            ->get();

        abort_if($reports->isEmpty(), 404, 'No published report cards were found for this class and term.');

        return $this->reportCards->renderBatch($reports)
            ->stream('report-cards-'.$section->id.'-term-'.$term->id.'.pdf');
    }
}
