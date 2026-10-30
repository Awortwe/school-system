<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentLedgerEntry extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
        'debit_minor' => 'integer',
        'credit_minor' => 'integer',
        'balance_minor' => 'integer',
    ];


    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

