<?php

namespace App\Jobs;

use App\Support\FcmPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one notification to a batch of users' registered phones via FCM.
 *
 * Push runs off the request that produced the notification: Notifier writes
 * the in-app row first (the in-app feed is the source of truth), then
 * dispatches this job so Google round-trips never sit on a voting,
 * registration or admin request. One job per chunk keeps the fan-out of a
 * school-sized broadcast at one queue row per 500 students rather than one
 * per student.
 *
 * Failures are contained inside FcmPush (log + skip); with no Firebase
 * configured the job is a no-op, so the queue stays safe to run unconfigured.
 */
class SendPushToUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int a transient FCM outage should not drop the notification entirely */
    public int $tries = 3;

    /** @var array<int, int> seconds between retries */
    public array $backoff = [30, 120];

    /**
     * @param  array<int, int>  $userIds  recipients (chunk-sized by the caller)
     * @param  string  $title  push headline (same as the in-app row)
     * @param  string|null  $body  supporting detail
     * @param  string|null  $link  frontend route carried for tap deep-linking
     */
    public function __construct(
        public array $userIds,
        public string $title,
        public ?string $body = null,
        public ?string $link = null,
    ) {}

    public function handle(): void
    {
        FcmPush::sendToUsers($this->userIds, $this->title, $this->body, $this->link);
    }
}
