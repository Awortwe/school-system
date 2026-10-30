<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionRecord extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'average' => 'decimal:2',
        'decided_at' => 'datetime',
    ];


    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function fromSection()
    {
        return $this->belongsTo(ClassSection::class, 'from_section_id');
    }

    public function toSection()
    {
        return $this->belongsTo(ClassSection::class, 'to_section_id');
    }
}

