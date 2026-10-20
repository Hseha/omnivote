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

    /**
     * The table has no `created_at` / `updated_at` columns — it is a frozen
     * record of a past cycle, and `snapshot_at` is the only instant it
     * carries. Leaving Eloquent's timestamps on made every insert fail with
     * "Unknown column 'updated_at'", which the archive's catch swallowed: the
     * Election Records archive silently wrote nothing at all.
     */
    public $timestamps = false;

    protected $casts = [
        'vote_total' => 'integer',
        'winner_rank' => 'integer',
        'certified_winner' => 'boolean',
        'snapshot_at' => 'datetime',
    ];
}
