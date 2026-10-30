<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TermSubjectResult extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'total_score' => 'decimal:2',
        'points' => 'decimal:2',
        'is_complete' => 'boolean',
    ];


    public function resultSheet()
    {
        return $this->belongsTo(ResultSheet::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}

