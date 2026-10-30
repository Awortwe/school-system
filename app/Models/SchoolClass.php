<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use HasFactory;

    protected $guarded = [];


    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function sections()
    {
        return $this->hasMany(ClassSection::class);
    }
}
