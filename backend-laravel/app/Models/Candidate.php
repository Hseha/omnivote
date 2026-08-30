<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    protected $fillable = [
        'user_id',
        'position_id',
        'slogan',
        'platform_statement',
        'photo_path',
        'approval_status',
    ];

    protected $casts = [
        'platform_points' => 'array',
    ];
}
