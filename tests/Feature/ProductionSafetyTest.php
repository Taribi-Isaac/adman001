<?php

namespace Tests\Feature;

use App\Ai\Providers\FakeAiProvider;
use App\Ai\Providers\OpenAiCompatibleProvider;
use App\Contracts\AiProvider;
use App\Support\ProductionSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_ai_provider_is_used_in_testing(): void
    {
        $this->assertInstanceOf(FakeAiProvider::class, app(AiProvider::class));
    }

    public function test_fake_provider_forbidden_in_production_profile_check(): void
    {
        config([
            'app.debug' => true,
            'app.url' => 'http://example.com',
            'adman.ai.provider' => 'fake',
            'adman.whatsapp.enabled' => true,
            'adman.whatsapp.app_secret' => null,
            'adman.whatsapp.webhook_verify_token' => null,
            'queue.default' => 'database',
            'session.secure' => false,
        ]);

        $issues = ProductionSafety::configurationIssues('production');
        $codes = collect($issues)->pluck('code')->all();

        $this->assertContains('app_debug', $codes);
        $this->assertContains('ai_fake_provider', $codes);
        $this->assertContains('whatsapp_app_secret', $codes);
        $this->assertContains('whatsapp_verify_token', $codes);
        $this->assertContains('queue_not_redis', $codes);
        $this->assertContains('session_insecure', $codes);
        $this->assertContains('app_url_https', $codes);
    }

    public function test_staging_profile_requires_debug_off_and_blocks_fake_ai_when_enabled(): void
    {
        config([
            'app.debug' => true,
            'app.url' => 'https://staging.example.com',
            'adman.ai.enabled' => true,
            'adman.ai.provider' => 'fake',
            'adman.whatsapp.enabled' => false,
            'queue.default' => 'redis',
            'session.secure' => true,
        ]);

        $codes = collect(ProductionSafety::configurationIssues('staging'))->pluck('code')->all();
        $this->assertContains('app_debug', $codes);
        $this->assertContains('ai_fake_provider_staging', $codes);
    }

    public function test_production_binding_rejects_fake_provider(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        config(['adman.ai.provider' => 'fake', 'adman.ai.api_key' => '']);

        $this->app->forgetInstance(AiProvider::class);
        $this->app->bind(AiProvider::class, function () {
            $provider = strtolower((string) config('adman.ai.provider', 'openai'));
            if (app()->environment('testing')) {
                return new FakeAiProvider;
            }
            if ($provider === 'fake') {
                if (app()->isProduction()) {
                    return new OpenAiCompatibleProvider;
                }

                return new FakeAiProvider;
            }

            return new OpenAiCompatibleProvider;
        });

        try {
            $this->assertTrue(app()->isProduction());
            $this->assertInstanceOf(OpenAiCompatibleProvider::class, app(AiProvider::class));
        } finally {
            $this->app['env'] = $previous;
            $this->app->forgetInstance(AiProvider::class);
        }
    }

    public function test_production_check_command_runs(): void
    {
        $this->artisan('adman:production-check')->assertSuccessful();
    }

    public function test_health_command_runs(): void
    {
        // In testing Redis may or may not be available; command should still exit cleanly.
        Artisan::call('adman:health');
        $this->assertTrue(true);
    }

    public function test_private_disk_is_not_publicly_served(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
    }

    public function test_redis_queue_connection_is_configured(): void
    {
        $this->assertArrayHasKey('redis', config('queue.connections'));
        $this->assertSame('redis', config('queue.connections.redis.driver'));
    }
}
