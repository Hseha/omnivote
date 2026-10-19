<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device registered for FCM phone push.
 *
 * `token` is the FCM registration token, unique across the platform: when a
 * device is handed to another account (re-login) `updateOrCreate` reassigns
 * the row rather than leaving a stale token that would deliver one account's
 * notifications to another's phone. Tokens that FCM reports as gone are
 * deleted by App\Support\FcmPush so the table stays clean without a janitor.
 */
class DeviceToken extends Model
{
    protected $table = 'device_tokens';

    protected $fillable = ['user_id', 'token', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
