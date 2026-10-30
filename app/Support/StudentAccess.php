<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StudentAccess
{
    public static function schoolWide(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasAnyRole(['headteacher', 'administrator', 'accountant']);
    }

    public static function sections(User $user): Builder
    {
        $query = ClassSection::query();
        if (self::schoolWide($user)) {
            return $query;
        }

        $yearId = AcademicYear::where('is_current', true)->value('id');
        $termId = Term::where('is_current', true)->value('id');

        return $query->where(function (Builder $sections) use ($user, $yearId, $termId) {
            $sections->where('class_teacher_id', $user->id)
                ->orWhereHas('classSubjects', function (Builder $subjects) use ($user, $yearId, $termId) {
                    $subjects->where('teacher_id', $user->id)
                        ->where('academic_year_id', $yearId ?? 0)
                        ->where(fn (Builder $terms) => $terms->whereNull('term_id')->orWhere('term_id', $termId ?? 0));
                });
        });
    }

    public static function students(User $user): Builder
    {
        return Student::query()->when(! self::schoolWide($user), fn (Builder $query) =>
            $query->whereIn('current_section_id', self::sections($user)->select('id')));
    }

    public static function authorize(User $user, Student $student): void
    {
        abort_unless(self::students($user)->whereKey($student->id)->exists(), 403);
    }
}
