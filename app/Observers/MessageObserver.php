<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\BroadcastDeliveryGuard;
use App\Services\BroadcastService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

/**
 * Keeps broadcast recipient rows in step with their message's delivery status (provider
 * response and status webhooks). Runs after commit so it never affects the message update.
 */
class MessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
    ) {}

    public function updated(Message $message): void
    {
        if (! $message->wasChanged('status') || ! BroadcastDeliveryGuard::isBroadcastMessage($message)) {
            return;
        }

        try {
            $this->broadcasts->syncRecipientFromMessage($message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
