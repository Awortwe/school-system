<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentScore extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'score' => 'decimal:2',
        'is_exempt' => 'boolean',
    ];


    public function resultSheet()
    {
        return $this->belongsTo(ResultSheet::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function component()
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }
}

