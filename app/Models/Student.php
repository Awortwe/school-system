<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'archived_at' => 'datetime',
    ];


    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')->withPivot('relationship', 'is_primary', 'is_emergency', 'custody')->withTimestamps();
    }

    public function currentSection()
    {
        return $this->belongsTo(ClassSection::class, 'current_section_id');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(StudentLedgerEntry::class)->orderBy('id');
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function publishedReports()
    {
        return $this->hasMany(PublishedReport::class);
    }

    public function siblingGroups()
    {
        return $this->belongsToMany(SiblingGroup::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->middle_name.' '.$this->last_name);
    }
}
