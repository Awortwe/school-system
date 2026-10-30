<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'receipt_snapshot' => 'array',
        'payment_date' => 'date',
        'amount_minor' => 'integer',
        'allocated_minor' => 'integer',
        'unallocated_minor' => 'integer',
        'reversed_at' => 'datetime',
    ];


    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function invoices()
    {
        return $this->belongsToMany(Invoice::class)->withPivot('amount_minor')->withTimestamps();
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reprints()
    {
        return $this->hasMany(ReceiptReprint::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }
}

