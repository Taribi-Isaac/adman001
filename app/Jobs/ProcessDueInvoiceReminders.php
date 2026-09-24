<?php

namespace App\Jobs;

use App\Services\ReminderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessDueInvoiceReminders implements ShouldQueue
{
    use Queueable;

    public function handle(ReminderService $reminders): void
    {
        $claimed = $reminders->processDue();

        Log::info('Invoice reminders claimed', [
            'count' => $claimed->count(),
        ]);
    }
}
