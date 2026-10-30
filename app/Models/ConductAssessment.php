<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConductAssessment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'height' => 'decimal:1',
        'weight' => 'decimal:1',
    ];


    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}

