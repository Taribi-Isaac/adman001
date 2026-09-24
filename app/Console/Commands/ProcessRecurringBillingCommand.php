<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDueRecurringBillingSchedules;
use Illuminate\Console\Command;

class ProcessRecurringBillingCommand extends Command
{
    protected $signature = 'recurring-billing:process-due {--sync : Run inline instead of queueing}';

    protected $description = 'Process due recurring billing schedules and generate invoices';

    public function handle(): int
    {
        if ($this->option('sync')) {
            ProcessDueRecurringBillingSchedules::dispatchSync();
            $this->info('Recurring billing processed synchronously.');
        } else {
            ProcessDueRecurringBillingSchedules::dispatch();
            $this->info('Recurring billing job dispatched.');
        }

        return self::SUCCESS;
    }
}
