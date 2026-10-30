<?php
namespace App\Jobs;
use App\Models\{PublishedReport, User};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Storage};
class BuildClassReports implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 900;
    public function __construct(public int $jobId) {}
    public function handle(): void {
        $job = DB::table('report_jobs')->find($this->jobId);
        if ($job->status !== 'pending') return;
        $user = User::findOrFail($job->user_id);
        abort_unless($user->is_active && $user->can('results.view'), 403);
        abort_unless(\App\Support\StudentAccess::schoolWide($user) || \App\Models\ClassSection::whereKey($job->class_section_id)->where('class_teacher_id', $user->id)->exists(), 403);
        DB::table('report_jobs')->where('id', $job->id)->update(['status' => 'processing', 'updated_at' => now()]);
        try {
            $reports = PublishedReport::where('class_section_id', $job->class_section_id)->where('term_id', $job->term_id)
                ->whereIn('id', fn ($query) => $query->selectRaw('MAX(id)')->from('published_reports')->where('class_section_id', $job->class_section_id)->where('term_id', $job->term_id)->groupBy('student_id'))->get();
            if ($reports->isEmpty()) throw new \RuntimeException('No published reports found.');
            $path = 'report-jobs/'.$job->id.'.pdf';
            Storage::disk('local')->put($path, app(\App\Services\ReportCardService::class)->renderBatch($reports)->output());
            DB::table('report_jobs')->where('id', $job->id)->update(['status' => 'completed', 'path' => $path, 'updated_at' => now()]);
        } catch (\Throwable $exception) { report($exception); $this->failed($exception); }
    }
    public function failed(?\Throwable $exception): void { DB::table('report_jobs')->where('id', $this->jobId)->update(['status' => 'failed', 'error' => 'Report generation failed. Check published results and server logs.', 'updated_at' => now()]); }
}
