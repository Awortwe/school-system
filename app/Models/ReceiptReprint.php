<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceiptReprint extends Model
{
    use HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'reprinted_at' => 'datetime',
    ];


    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function reprintedBy()
    {
        return $this->belongsTo(User::class, 'reprinted_by');
    }
}
