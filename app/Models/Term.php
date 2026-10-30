<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'reopening_date' => 'date',
        'is_current' => 'boolean',
    ];


    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function resultSheets()
    {
        return $this->hasMany(ResultSheet::class);
    }

    public function reportPublications()
    {
        return $this->hasMany(ReportPublication::class);
    }
}
