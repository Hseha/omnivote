<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $fillable = [
        'slug',
        'label',
        'tier',
        'seat_count',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'seat_count' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->where('approval_status', 'approved');
    }

    public function allCandidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}