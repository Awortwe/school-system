<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeStructureItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
    ];


    public function feeStructure()
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function feeItem()
    {
        return $this->belongsTo(FeeItem::class);
    }
}
