<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDueInvoiceReminders;
use App\Services\ReminderService;
use Illuminate\Console\Command;

class ProcessInvoiceRemindersCommand extends Command
{
    protected $signature = 'reminders:process-due {--sync : Process synchronously instead of queueing}';

    protected $description = 'Find and process invoice reminders due in the business timezone today';

    public function handle(ReminderService $reminders): int
    {
        if ($this->option('sync')) {
            $claimed = $reminders->processDue(dispatchJobs: false);
            foreach ($claimed as $occurrence) {
                $reminders->processOccurrence($occurrence);
            }
            $this->info('Processed '.$claimed->count().' reminder occurrence(s).');

            return self::SUCCESS;
        }

        ProcessDueInvoiceReminders::dispatch();
        $this->info('Dispatched invoice reminder processing job.');

        return self::SUCCESS;
    }
}
