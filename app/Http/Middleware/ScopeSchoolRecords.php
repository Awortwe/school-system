<?php

namespace App\Http\Middleware;

use App\Models\ClassSection;
use App\Models\PublishedReport;
use App\Models\ResultSheet;
use App\Models\Student;
use App\Support\StudentAccess;
use Closure;
use Illuminate\Http\Request;

class ScopeSchoolRecords
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || StudentAccess::schoolWide($user)) return $next($request);
        foreach ($request->route()->parameters() as $record) {
            if ($record instanceof Student) StudentAccess::authorize($user, $record);
            if ($record instanceof ClassSection) abort_unless(StudentAccess::sections($user)->whereKey($record->id)->exists(), 403);
            if ($record instanceof ResultSheet) abort_unless($record->teacher_id === $user->id, 403);
            if ($record instanceof PublishedReport) abort_unless(ClassSection::whereKey($record->class_section_id)->where('class_teacher_id', $user->id)->exists(), 403);
        }
        foreach (['class_section_id', 'section_id'] as $key) {
            if ($request->filled($key)) abort_unless(StudentAccess::sections($user)->whereKey($request->input($key))->exists(), 403);
        }
        if (str_starts_with($request->path(), 'report-cards/')) {
            $section = $request->route('section')?->id ?? $request->route('student')?->current_section_id ?? $request->route('report')?->class_section_id;
            abort_unless(ClassSection::whereKey($section)->where('class_teacher_id', $user->id)->exists(), 403);
        }
        return $next($request);
    }
}
