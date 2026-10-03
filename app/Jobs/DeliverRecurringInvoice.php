<?php

namespace App\Jobs;

use App\Services\RecurringInvoiceDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverRecurringInvoice implements ShouldBeUnique, ShouldQueue
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
        public readonly int $generationId,
    ) {}

    public function uniqueId(): string
    {
        return 'recurring-invoice-delivery-'.$this->generationId;
    }

    public function handle(RecurringInvoiceDeliveryService $deliveries): void
    {
        $deliveries->process($this->generationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RecurringInvoiceDeliveryService::class)->markUndelivered(
            $this->generationId,
            'Delivery could not be completed after several attempts: '.($exception?->getMessage() ?? 'unknown error'),
        );
    }
}
