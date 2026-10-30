<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_core' => 'boolean',
        'is_examinable' => 'boolean',
    ];


    public function classSubjects()
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function resultSheets()
    {
        return $this->hasMany(ResultSheet::class);
    }
}
