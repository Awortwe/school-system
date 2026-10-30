<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentFeeAssignment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'discount_minor' => 'integer',
    ];

}
