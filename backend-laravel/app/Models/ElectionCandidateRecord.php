<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permanent, denormalized snapshot of a candidate from a concluded election
 * cycle. Kept for review after a clean-slate reset removes the live row.
 *
 * Foreign-key free by design: a record must survive the deletion of its
 * source candidate, user, and position rows.
 */
class ElectionCandidateRecord extends Model
{
    protected $table = 'election_candidate_records';

    protected $guarded = [];

    protected $casts = [
        'vote_total' => 'integer',
        'winner_rank' => 'integer',
        'certified_winner' => 'boolean',
        'snapshot_at' => 'datetime',
    ];
}
