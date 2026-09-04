<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidate extends Model
{
    protected $fillable = [
        'user_id',
        'position_id',
        'candidate_ref',
        'slogan',
        'party_name',
        'platform_statement',
        'platform_points',
        'photo_path',
        'approval_status',
    ];

    protected $casts = [
        'platform_points' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
