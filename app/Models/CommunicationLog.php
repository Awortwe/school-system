<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunicationLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'follow_up_at' => 'datetime',
    ];


    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

