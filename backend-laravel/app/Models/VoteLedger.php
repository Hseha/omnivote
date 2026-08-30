<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoteLedger extends Model
{
    protected $table = 'vote_ledger';
    public $timestamps = false;
    protected $fillable = ['election_id', 'position_key', 'candidate_ref', 'receipt_hmac', 'ledger_sequence', 'recorded_at'];
}
