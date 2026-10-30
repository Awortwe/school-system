<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'discount_minor' => 'integer',
        'net_minor' => 'integer',
    ];


    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function feeItem()
    {
        return $this->belongsTo(FeeItem::class);
    }
}
