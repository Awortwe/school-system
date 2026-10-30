<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use HasFactory;

    protected $guarded = [];


    public function schoolClasses()
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function assessmentComponents()
    {
        return $this->hasMany(AssessmentComponent::class);
    }

    public function gradingScales()
    {
        return $this->hasMany(GradingScale::class, 'curriculum', 'curriculum');
    }
}
