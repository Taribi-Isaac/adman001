<?php

namespace App\Jobs;

use App\Services\RecurringBillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessDueRecurringBillingSchedules implements ShouldQueue
{
    use Queueable;

    public function handle(RecurringBillingService $service): void
    {
        $results = $service->processDueSchedules();

        Log::info('Recurring billing processed', [
            'count' => $results->count(),
            'succeeded' => $results->where('status', 'succeeded')->count(),
            'failed' => $results->where('status', 'failed')->count(),
        ]);
    }
}
