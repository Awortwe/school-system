<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashbookEntry extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
        'inflow_minor' => 'integer',
        'outflow_minor' => 'integer',
        'reconciled_at' => 'datetime',
    ];


    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
