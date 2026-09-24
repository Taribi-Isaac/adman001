<?php

namespace App\Console\Commands;

use App\Support\ProductionSafety;
use Illuminate\Console\Command;

class ProductionCheckCommand extends Command
{
    protected $signature = 'adman:production-check {--strict : Exit non-zero on risks as well as blockers}';

    protected $description = 'Validate ADMAN production-safety configuration (no secrets printed)';

    public function handle(): int
    {
        $env = (string) app()->environment();
        $this->info("Environment: {$env}");

        $issues = ProductionSafety::configurationIssues($env);

        if ($issues === []) {
            $this->info('No configuration blockers/risks detected for this environment profile.');
        }

        $hasBlocker = false;
        $hasRisk = false;

        foreach ($issues as $issue) {
            $line = "[{$issue['level']}] {$issue['code']}: {$issue['message']}";
            if ($issue['level'] === 'blocker') {
                $hasBlocker = true;
                $this->error($line);
            } else {
                $hasRisk = true;
                $this->warn($line);
            }
        }

        $health = ProductionSafety::infrastructureHealth();
        foreach ($health['checks'] as $name => $check) {
            $status = $check['ok'] ? 'ok' : 'fail';
            $this->line("health.{$name}: {$status} ({$check['detail']})");
        }

        if ($hasBlocker) {
            return self::FAILURE;
        }

        if ($this->option('strict') && $hasRisk) {
            return self::FAILURE;
        }

        if (! $health['ok'] && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
