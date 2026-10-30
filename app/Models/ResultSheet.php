<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultSheet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'configuration' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_locked' => 'boolean',
    ];


    public function classSection()
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scores()
    {
        return $this->hasMany(AssessmentScore::class);
    }

    public function results()
    {
        return $this->hasMany(TermSubjectResult::class);
    }

    public function events()
    {
        return $this->hasMany(ResultEvent::class);
    }
}
