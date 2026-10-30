<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guardian extends Model
{
    use HasFactory;

    protected $guarded = [];


    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_guardian')->withPivot('relationship', 'is_primary', 'is_emergency', 'custody')->withTimestamps();
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
