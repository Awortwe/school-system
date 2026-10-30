<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionRule extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'min_average' => 'decimal:2',
        'min_pass_subjects_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

}
