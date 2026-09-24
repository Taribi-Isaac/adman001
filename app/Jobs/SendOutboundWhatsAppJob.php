<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\WhatsAppOutboundService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOutboundWhatsAppJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function uniqueId(): string
    {
        return 'outbound-whatsapp-'.$this->messageId;
    }

    public function handle(WhatsAppOutboundService $whatsapp): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            return;
        }

        $whatsapp->deliverQueuedMessage($message);
    }
}
