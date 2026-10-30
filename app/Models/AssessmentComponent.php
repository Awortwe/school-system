<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentComponent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_exam' => 'boolean',
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

}
