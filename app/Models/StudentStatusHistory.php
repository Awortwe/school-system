<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentStatusHistory extends Model
{
    protected $table = 'student_status_history';
    use HasFactory;

    protected $guarded = [];

}
