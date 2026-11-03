<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaptureJob extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'taken_at' => 'datetime',
        'confidence' => 'float',
        'prediction_result' => 'array',
    ];
}
