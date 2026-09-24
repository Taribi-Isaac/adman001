<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundAiMessage implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 60, 180];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $inboundMessageId,
    ) {}

    public function uniqueId(): string
    {
        return 'ai-inbound-'.$this->inboundMessageId;
    }

    public function handle(AiService $ai): void
    {
        $message = Message::query()->find($this->inboundMessageId);
        if ($message === null) {
            return;
        }

        $ai->processInboundMessage($message);
    }
}
