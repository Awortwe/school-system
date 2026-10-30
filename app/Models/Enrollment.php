<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'enrolled_on' => 'date',
        'left_on' => 'date',
    ];


    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function classSection()
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function promotedFromSection()
    {
        return $this->belongsTo(ClassSection::class, 'promoted_from_section_id');
    }
}

