<?php

namespace App\Services;

use App\Models\AssessmentComponent;
use App\Models\AssessmentScore;
use App\Models\ClassSection;
use App\Models\ClassSubject;
use App\Models\GradingScale;
use App\Models\Level;
use App\Models\ResultSheet;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermSubjectResult;
use App\Models\Setting;

class GradingService
{
    public function scaleFor(?Level $level): ?GradingScale
    {
        $curriculum = $level?->curriculum ?? 'GES';
        $selected = Setting::get('grading_scale_level_'.$level?->id);
        if ($selected) return GradingScale::find($selected);

        return GradingScale::where('curriculum', $curriculum)->orderByDesc('is_default')->first()
            ?? GradingScale::where('curriculum', 'GES')->orderByDesc('is_default')->first();
    }

    public function gradeFor(float $score, ?GradingScale $scale): array
    {
        if (! $scale) {
            return ['grade' => null, 'remark' => null, 'points' => null];
        }

        $band = $scale->bands()
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->orderBy('sort_order')
            ->first();

        return [
            'grade' => $band?->grade,
            'remark' => $band?->remark,
            'points' => $band?->points,
        ];
    }

    public function componentsFor(ResultSheet $sheet): \Illuminate\Support\Collection
    {
        if (! empty($sheet->configuration['components'])) return collect($sheet->configuration['components'])->map(fn ($row) => (object) $row);
        $level = $sheet->classSection?->schoolClass?->level;
        $query = AssessmentComponent::query()->where('is_active', true)
            ->where('curriculum', $level?->curriculum ?? 'GES')
            ->where('level_id', $level?->id);
        if (! $query->exists()) $query = AssessmentComponent::where('is_active', true)->whereNull('level_id')->where('curriculum', $level?->curriculum ?? 'GES');
        return $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function calculate(ResultSheet $sheet, Student $student): TermSubjectResult
    {
        $components = $this->componentsFor($sheet);
        if (! $sheet->configuration) {
            $sheet->update(['configuration' => ['decimal_places' => (int) Setting::get('score_decimal_places', 1), 'components' => $components->map(fn ($component) => $component->toArray())->all(), 'bands' => $this->scaleFor($sheet->classSection?->schoolClass?->level)?->bands()->get()->toArray() ?? []]]);
        }
        $scores = AssessmentScore::where('result_sheet_id', $sheet->id)
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('assessment_component_id');

        $weighted = 0.0;
        $availableWeight = 0.0;
        $complete = $components->isNotEmpty();

        foreach ($components as $component) {
            $score = $scores->get($component->id);

            if (! $score) {
                $complete = false;
                continue;
            }

            if ($score->is_exempt) {
                continue;
            }

            if ($score->score === null) {
                $complete = false;
                continue;
            }

            $weight = (float) $component->weight;
            $max = (float) $component->max_score;

            if ($max <= 0) {
                continue;
            }

            $weighted += ((float) $score->score / $max) * $weight;
            $availableWeight += $weight;
        }

        $total = null;
        if ($complete && $availableWeight > 0) {
            $total = round(($weighted / $availableWeight) * 100, (int) ($sheet->configuration['decimal_places'] ?? Setting::get('score_decimal_places', 1)));
        }
        if ($availableWeight <= 0) $complete = false;

        $scale = $this->scaleFor($sheet->classSection?->schoolClass?->level);
        $grade = $total !== null ? $this->gradeFor($total, $scale) : ['grade' => null, 'remark' => null, 'points' => null];
        if ($total !== null && isset($sheet->configuration['bands'])) {
            $band = collect($sheet->configuration['bands'])->first(fn ($band) => $total >= (float) $band['min_score'] && $total <= (float) $band['max_score']);
            $grade = ['grade' => $band['grade'] ?? null, 'remark' => $band['remark'] ?? null, 'points' => $band['points'] ?? null];
        }

        return TermSubjectResult::updateOrCreate(
            ['result_sheet_id' => $sheet->id, 'student_id' => $student->id],
            [
                'total_score' => $total,
                'grade' => $grade['grade'],
                'remark' => $grade['remark'],
                'points' => $grade['points'],
                'is_complete' => $complete,
            ],
        );
    }

    public function recalculateSheet(ResultSheet $sheet): void
    {
        $students = Student::where('current_section_id', $sheet->class_section_id)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        foreach ($students as $student) {
            $this->calculate($sheet, $student);
        }
    }

    public function sectionResults(ClassSection $section, Term $term, bool $onlyApproved = false): array
    {
        $subjectIds = ClassSubject::where('class_section_id', $section->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where(function ($query) use ($term) {
                $query->whereNull('term_id')->orWhere('term_id', $term->id);
            })
            ->pluck('subject_id');

        $sheets = ResultSheet::with(['subject', 'results'])
            ->where('class_section_id', $section->id)
            ->where('term_id', $term->id)
            ->whereIn('subject_id', $subjectIds)
            ->when($onlyApproved, fn ($query) => $query->whereIn('status', ['approved', 'published']))
            ->get();

        $students = Student::where('current_section_id', $section->id)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $rows = [];
        foreach ($students as $student) {
            $subjectResults = [];
            $totals = [];
            foreach ($sheets as $sheet) {
                $result = $sheet->results->firstWhere('student_id', $student->id);
                if ($result?->is_complete && $result->total_score !== null) {
                    $totals[] = (float) $result->total_score;
                }
                $subjectResults[] = [
                    'subject' => $sheet->subject->name,
                    'subject_id' => $sheet->subject_id,
                    'result' => $result,
                ];
            }

            $average = count($totals) ? round(array_sum($totals) / count($totals), 1) : null;

            $rows[] = [
                'student' => $student,
                'subjects' => $subjectResults,
                'total' => count($totals) ? round(array_sum($totals), 1) : null,
                'average' => $average,
                'subjects_counted' => count($totals),
            ];
        }

        $completeRows = array_values(array_filter($rows, fn ($row) => $row['average'] !== null));
        usort($completeRows, fn ($a, $b) => $b['average'] <=> $a['average']);

        $position = 0;
        $lastAverage = null;
        $rank = 0;
        foreach ($completeRows as $index => $row) {
            $rank++;
            if ($lastAverage === null || $row['average'] < $lastAverage) {
                $position = Setting::get('ranking_method', 'competition') === 'dense' ? $position + 1 : $rank;
                $lastAverage = $row['average'];
            }
            $completeRows[$index]['position'] = $position;
        }

        $positions = collect($completeRows)->pluck('position', 'student.id')->all();

        foreach ($rows as $index => $row) {
            $rows[$index]['position'] = $positions[$row['student']->id] ?? null;
            $rows[$index]['class_size'] = count($rows);
        }

        return $rows;
    }
}
