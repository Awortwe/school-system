<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSection extends Model
{
    use HasFactory;

    protected $guarded = [];


    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function classTeacher()
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'current_section_id');
    }

    public function classSubjects()
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function resultSheets()
    {
        return $this->hasMany(ResultSheet::class);
    }

    public function timetableEntries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function displayName(): string
    {
        return trim(($this->schoolClass?->name ?? '').' '.$this->name);
    }
}
