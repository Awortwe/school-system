<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'total_minor' => 'integer',
        'paid_minor' => 'integer',
        'balance_minor' => 'integer',
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

    public function feeStructure()
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function adjustments()
    {
        return $this->hasMany(InvoiceAdjustment::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function payments()
    {
        return $this->belongsToMany(Payment::class)->withPivot('amount_minor')->withTimestamps();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

