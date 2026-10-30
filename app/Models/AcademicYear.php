<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];


    public function terms()
    {
        return $this->hasMany(Term::class)->orderBy('sequence');
    }

    public function currentTerm()
    {
        return $this->hasOne(Term::class)->where('is_current', true);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}
