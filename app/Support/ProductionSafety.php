<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Small production/staging safety helpers. Does not expose secrets.
 */
final class ProductionSafety
{
    /**
     * Environments that must not run with debug or unsigned WhatsApp.
     *
     * @return list<string>
     */
    public static function hardenedEnvironments(): array
    {
        return ['production', 'staging'];
    }

    public static function isHardenedEnvironment(?string $env = null): bool
    {
        $env ??= (string) app()->environment();

        return in_array($env, self::hardenedEnvironments(), true);
    }

    /**
     * @return list<array{level: string, code: string, message: string}>
     */
    public static function configurationIssues(?string $env = null): array
    {
        $env ??= (string) app()->environment();
        $issues = [];
        $hardened = self::isHardenedEnvironment($env);

        if ($hardened && (bool) config('app.debug')) {
            $issues[] = [
                'level' => 'blocker',
                'code' => 'app_debug',
                'message' => 'APP_DEBUG must be false in '.$env.'.',
            ];
        }

        if ($env === 'production' && (string) config('adman.ai.provider') === 'fake') {
            $issues[] = [
                'level' => 'blocker',
                'code' => 'ai_fake_provider',
                'message' => 'ADMAN_AI_PROVIDER=fake is forbidden in production.',
            ];
        }

        if ($env === 'staging'
            && (bool) config('adman.ai.enabled', false)
            && (string) config('adman.ai.provider') === 'fake') {
            $issues[] = [
                'level' => 'blocker',
                'code' => 'ai_fake_provider_staging',
                'message' => 'When AI is enabled on staging, ADMAN_AI_PROVIDER must not be fake.',
            ];
        }

        if ($hardened && (string) config('queue.default') !== 'redis') {
            $issues[] = [
                'level' => 'risk',
                'code' => 'queue_not_redis',
                'message' => 'QUEUE_CONNECTION should be redis for Horizon and ADMAN workers.',
            ];
        }

        if ($hardened && ! (bool) config('session.secure')) {
            $issues[] = [
                'level' => 'risk',
                'code' => 'session_insecure',
                'message' => 'SESSION_SECURE_COOKIE should be true behind HTTPS.',
            ];
        }

        if ($hardened
            && (bool) config('adman.whatsapp.enabled', true)
            && ! filled(config('adman.whatsapp.app_secret'))) {
            $issues[] = [
                'level' => 'blocker',
                'code' => 'whatsapp_app_secret',
                'message' => 'WHATSAPP_APP_SECRET is required in '.$env.' when WhatsApp is enabled.',
            ];
        }

        if ($hardened
            && (bool) config('adman.whatsapp.enabled', true)
            && ! filled(config('adman.whatsapp.webhook_verify_token'))) {
            $issues[] = [
                'level' => 'blocker',
                'code' => 'whatsapp_verify_token',
                'message' => 'WHATSAPP_WEBHOOK_VERIFY_TOKEN is required in '.$env.' when WhatsApp is enabled.',
            ];
        }

        if ($hardened
            && (bool) config('adman.ai.enabled', false)
            && (string) config('adman.ai.provider') !== 'fake'
            && ! filled(config('adman.ai.api_key'))) {
            $issues[] = [
                'level' => 'risk',
                'code' => 'ai_api_key',
                'message' => 'ADMAN_AI_API_KEY is empty while ADMAN_AI_ENABLED is true.',
            ];
        }

        if ($hardened && ! str_starts_with((string) config('app.url'), 'https://')) {
            $issues[] = [
                'level' => 'risk',
                'code' => 'app_url_https',
                'message' => 'APP_URL should use https:// in '.$env.'.',
            ];
        }

        return $issues;
    }

    public static function logProductionWarnings(): void
    {
        if (! self::isHardenedEnvironment()) {
            return;
        }

        foreach (self::configurationIssues() as $issue) {
            if ($issue['level'] === 'blocker') {
                Log::critical('adman.production_safety', $issue);
            } else {
                Log::warning('adman.production_safety', $issue);
            }
        }
    }

    /**
     * @return array{ok: bool, checks: array<string, array{ok: bool, detail: string}>}
     */
    public static function infrastructureHealth(): array
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok' => true, 'detail' => 'connected'];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'detail' => 'unavailable'];
        }

        try {
            Redis::connection()->ping();
            $checks['redis'] = ['ok' => true, 'detail' => 'connected'];
        } catch (Throwable $e) {
            $checks['redis'] = ['ok' => false, 'detail' => 'unavailable'];
        }

        $queue = (string) config('queue.default');
        $checks['queue'] = [
            'ok' => in_array($queue, ['redis', 'sync', 'database'], true),
            'detail' => $queue,
        ];

        $ok = collect($checks)->every(fn (array $c) => $c['ok']);

        return ['ok' => $ok, 'checks' => $checks];
    }
}
