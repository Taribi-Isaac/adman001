<?php

namespace App\Console\Commands;

use App\Support\ProductionSafety;
use Illuminate\Console\Command;

class HealthCheckCommand extends Command
{
    protected $signature = 'adman:health';

    protected $description = 'Check database, Redis, and queue configuration without exposing secrets';

    public function handle(): int
    {
        $health = ProductionSafety::infrastructureHealth();

        foreach ($health['checks'] as $name => $check) {
            if ($check['ok']) {
                $this->info("{$name}: ok ({$check['detail']})");
            } else {
                $this->error("{$name}: fail ({$check['detail']})");
            }
        }

        return $health['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
