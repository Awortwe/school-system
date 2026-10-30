<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_tuition' => 'boolean',
        'is_active' => 'boolean',
    ];


    public function feeCategory()
    {
        return $this->belongsTo(FeeCategory::class);
    }
}
