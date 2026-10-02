<?php

namespace App\Jobs;

use App\Services\BroadcastService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Queues one small batch of broadcast recipients, then re-dispatches itself with a delay so a
 * broadcast never monopolises the limited Horizon workers. Duplicate jobs are harmless: each
 * recipient is claimed under a database row lock.
 */
class ProcessBroadcastJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $broadcastId,
    ) {}

    public function uniqueId(): string
    {
        return 'broadcast-'.$this->broadcastId;
    }

    public function handle(BroadcastService $broadcasts): void
    {
        if ($broadcasts->processNextBatch($this->broadcastId)) {
            self::dispatch($this->broadcastId)
                ->delay(now()->addSeconds(max(0, (int) config('adman.broadcasts.batch_delay_seconds', 10))));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(BroadcastService::class)->stop(
            $this->broadcastId,
            'Broadcast processing failed repeatedly; remaining recipients were not sent.',
        );
    }
}
