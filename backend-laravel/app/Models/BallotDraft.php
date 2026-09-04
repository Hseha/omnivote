<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-scoped, pre-submission ballot draft. Choices are stored here only so
 * `GET /api/ballot/me` can return "My Ballot"; the moment a ballot is
 * submitted the choices vanish from this table (status → submitted) and live
 * exclusively in the anonymous `vote_ledger`.
 */
class BallotDraft extends Model
{
    protected $fillable = [
        'user_id',
        'selections',
        'status',
        'receipt_token',
        'submitted_at',
    ];

    protected $casts = [
        'selections' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}