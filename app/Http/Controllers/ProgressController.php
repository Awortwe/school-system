<?php
namespace App\Http\Controllers;

use App\Models\{AcademicYear, ClassSection, ConductAssessment, Enrollment, PromotionRecord, PromotionRule, PublishedReport, Student, Term};
use App\Support\{Audit, StudentAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProgressController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Academics/Progress', [
            'students' => StudentAccess::students($request->user())->get()->map(fn ($student) => ['id' => $student->id, 'name' => $student->fullName()]),
            'terms' => Term::with('academicYear')->get()->map(fn ($term) => ['id' => $term->id, 'name' => $term->academicYear?->name.' · '.$term->name]),
            'years' => AcademicYear::get(['id', 'name']),
            'rules' => PromotionRule::where('is_active', true)->get(),
            'sections' => StudentAccess::sections($request->user())->with('schoolClass')->get()->map(fn ($section) => ['id' => $section->id, 'name' => $section->displayName()]),
            'observations' => DB::table('learning_observations')->whereIn('student_id', StudentAccess::students($request->user())->select('id'))->latest()->paginate(20),
            'conduct' => ConductAssessment::whereIn('student_id', StudentAccess::students($request->user())->select('id'))->latest()->paginate(15, ['*'], 'conduct_page'),
            'promotions' => $request->user()->can('results.approve') ? PromotionRecord::with('student')->latest()->paginate(15, ['*'], 'promotion_page') : [],
        ]);
    }
    private function authorizeClassTeacher(Request $request, Student $student): void
    {
        StudentAccess::authorize($request->user(), $student);
        abort_unless(StudentAccess::schoolWide($request->user()) || ClassSection::whereKey($student->current_section_id)->where('class_teacher_id', $request->user()->id)->exists(), 403);
    }
    public function observation(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'exists:students,id'], 'term_id' => ['required', 'exists:terms,id'], 'area' => ['required', 'string', 'max:150'], 'skill' => ['required', 'string', 'max:200'], 'progress' => ['required', 'in:introduced,practising,independent,mastered'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $student = Student::findOrFail($data['student_id']); $this->authorizeClassTeacher($request, $student);
        abort_if(PublishedReport::where('student_id', $student->id)->where('term_id', $data['term_id'])->exists(), 422, 'Published observations are locked. Use a later term for new observations.');
        DB::table('learning_observations')->insert($data + ['recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('learning_observation_recorded', $student, [], $data, null, 'Montessori progress observation recorded');
        return back()->with('success', 'Learning observation recorded.');
    }
    public function conduct(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'exists:students,id'], 'term_id' => ['required', 'exists:terms,id'], 'conduct' => ['nullable', 'string', 'max:200'], 'attitude' => ['nullable', 'string', 'max:200'], 'interest' => ['nullable', 'string', 'max:200'], 'class_teacher_comment' => ['nullable', 'string', 'max:2000'], 'headteacher_comment' => ['nullable', 'string', 'max:2000']]);
        $student = Student::findOrFail($data['student_id']); $this->authorizeClassTeacher($request, $student);
        if (! $request->user()->can('results.approve')) unset($data['headteacher_comment']);
        abort_if(PublishedReport::where('student_id', $student->id)->where('term_id', $data['term_id'])->exists(), 422, 'Comments are locked after publication. Published snapshots are retained.');
        $record = ConductAssessment::updateOrCreate(['student_id' => $student->id, 'term_id' => $data['term_id']], $data + ['updated_by' => $request->user()->id]);
        Audit::record('report_comments_saved', $record, [], $data, null, 'Report comments and conduct saved');
        return back()->with('success', 'Report comments saved.');
    }
    public function rule(Request $request)
    {
        $data = $request->validate(['academic_year_id' => ['required', 'exists:academic_years,id'], 'min_average' => ['required', 'numeric', 'between:0,100'], 'min_pass_subjects_percent' => ['required', 'numeric', 'between:0,100'], 'max_core_failures' => ['required', 'integer', 'between:0,100'], 'subject_pass_mark' => ['required', 'numeric', 'between:0,100']]);
        DB::transaction(function () use ($data) {
            PromotionRule::where('academic_year_id', $data['academic_year_id'])->update(['is_active' => false]);
            PromotionRule::create(\Illuminate\Support\Arr::except($data, ['subject_pass_mark']) + ['is_active' => true]);
            \App\Models\Setting::set('promotion_pass_mark_'.$data['academic_year_id'], $data['subject_pass_mark'], 'academics');
            Audit::record('promotion_rule_versioned', null, [], $data, null, 'Promotion rule version saved');
        });
        return back()->with('success', 'Promotion rule saved. Explicit academic review is still required.');
    }
    public function promote(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'exists:students,id'], 'term_id' => ['required', 'exists:terms,id'], 'target_year_id' => ['required', 'exists:academic_years,id'], 'to_section_id' => ['nullable', 'exists:class_sections,id'], 'decision' => ['required', 'in:promoted,retained,graduated'], 'notes' => ['required', 'string', 'max:2000'], 'override_rule' => ['boolean']]);
        DB::transaction(function () use ($request, $data) {
            $student = Student::whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
            $term = Term::findOrFail($data['term_id']); $target = AcademicYear::findOrFail($data['target_year_id']);
            abort_unless($target->start_date > $term->academicYear->start_date, 422, 'Choose a later academic year.');
            abort_if(PromotionRecord::where('student_id', $student->id)->where('academic_year_id', $term->academic_year_id)->exists(), 422, 'A progression decision already exists for this academic year.');
            abort_unless($data['decision'] === 'graduated' || ! empty($data['to_section_id']), 422, 'Choose a target section.');
            $report = PublishedReport::where('student_id', $student->id)->where('term_id', $term->id)->latest('version')->first();
            $rule = PromotionRule::where('academic_year_id', $term->academic_year_id)->where('is_active', true)->latest('id')->first();
            if ($rule && $data['decision'] === 'promoted' && empty($data['override_rule'])) {
                $subjects = collect($report?->payload['subjects'] ?? []);
                $passMark = (float) \App\Models\Setting::get('promotion_pass_mark_'.$term->academic_year_id, 50);
                $passed = $subjects->filter(fn ($subject) => $subject['is_complete'] && $subject['total_score'] !== null && $subject['total_score'] >= $passMark)->count();
                $coreFailures = $subjects->filter(fn ($subject) => ($subject['is_core'] ?? true) && (! $subject['is_complete'] || $subject['total_score'] === null || $subject['total_score'] < $passMark))->count();
                abort_unless($report && $subjects->isNotEmpty() && $subjects->every(fn ($subject) => $subject['is_complete']) && ($report->payload['summary']['average'] ?? -1) >= $rule->min_average && $passed / $subjects->count() * 100 >= $rule->min_pass_subjects_percent && $coreFailures <= $rule->max_core_failures, 422, 'Published results do not meet the configured promotion rule. An academic approver may record an explicit justified override.');
            }
            // Default is an explicit staff decision, not an invented automatic promotion policy.
            PromotionRecord::create(['student_id' => $student->id, 'academic_year_id' => $term->academic_year_id, 'from_section_id' => $student->current_section_id, 'to_section_id' => $data['to_section_id'] ?? null, 'decision' => $data['decision'], 'average' => $report?->payload['summary']['average'] ?? null, 'notes' => $data['notes'], 'decided_by' => $request->user()->id, 'decided_at' => now()]);
            $student->enrollments()->where('status', 'active')->update(['status' => 'completed', 'left_on' => $term->end_date]);
            if ($data['decision'] === 'graduated') $student->update(['status' => 'graduated']);
            else {
                Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $target->id, 'class_section_id' => $data['to_section_id'], 'status' => 'active', 'enrolled_on' => $target->start_date, 'promoted_from_section_id' => $student->current_section_id]);
                $student->update(['current_section_id' => $data['to_section_id']]);
            }
            Audit::record('student_progression', $student, [], $data, $data['notes'], 'Staff-reviewed student progression');
        });
        return back()->with('success', 'Progression recorded. Academic and financial history is retained.');
    }
}
