<?php

namespace App\Jobs;

use App\Models\ReminderOccurrence;
use App\Services\ReminderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInvoiceReminderOccurrence implements ShouldBeUnique, ShouldQueue
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
        public readonly int $occurrenceId,
    ) {}

    public function uniqueId(): string
    {
        return 'invoice-reminder-occurrence-'.$this->occurrenceId;
    }

    public function handle(ReminderService $reminders): void
    {
        $occurrence = ReminderOccurrence::query()->find($this->occurrenceId);
        if ($occurrence === null) {
            return;
        }

        $reminders->processOccurrence($occurrence);
    }
}
