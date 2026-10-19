<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This voter already received this reminder stage" ledger, written by
 * `omnivote:send-vote-reminders`. The unique (user_id, stage) pair is the
 * dedupe: the systemd timer may fire every few minutes for the whole closing
 * window, but each voter is messaged once per stage.
 */
class VoteReminder extends Model
{
    protected $table = 'vote_reminders';

    protected $fillable = ['user_id', 'stage', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
