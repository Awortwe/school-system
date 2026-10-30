<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\Term;

class AttendanceSummaryService
{
    public function forStudentTerm(Student $student, Term $term): array
    {
        $counts = AttendanceRecord::query()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $present = (int) ($counts['present'] ?? 0);
        $absent = (int) ($counts['absent'] ?? 0);
        $late = (int) ($counts['late'] ?? 0);
        $excused = (int) ($counts['excused'] ?? 0);
        $total = $present + $absent + $late + $excused;
        $attended = $present + $late;

        return [
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'total' => $total,
            'percentage' => $total > 0 ? round(($attended / $total) * 100, 1) : null,
        ];
    }

    public function forSectionTerm(int $sectionId, Term $term): array
    {
        return AttendanceRecord::query()
            ->where('class_section_id', $sectionId)
            ->where('term_id', $term->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }
}
