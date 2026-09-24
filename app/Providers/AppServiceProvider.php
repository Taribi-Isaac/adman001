<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\EmailDeliveryAdapter;
use App\Contracts\WhatsAppDeliveryAdapter;
use App\Ai\Providers\FakeAiProvider;
use App\Ai\Providers\OpenAiCompatibleProvider;
use App\Mail\Adapters\LaravelMailEmailDeliveryAdapter;
use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use App\Support\ProductionSafety;
use App\WhatsApp\Adapters\WhatsAppCloudApiAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmailDeliveryAdapter::class, LaravelMailEmailDeliveryAdapter::class);
        $this->app->bind(WhatsAppDeliveryAdapter::class, WhatsAppCloudApiAdapter::class);
        $this->app->bind(\App\Contracts\WhatsAppMediaClient::class, function () {
            if (app()->environment('testing')) {
                return new \App\WhatsApp\Adapters\FakeWhatsAppMediaClient;
            }

            return new \App\WhatsApp\Adapters\WhatsAppCloudMediaClient;
        });
        $this->app->bind(AiProvider::class, function () {
            $provider = strtolower((string) config('adman.ai.provider', 'openai'));

            // Fake provider is for automated tests and explicit local use only.
            if (app()->environment('testing')) {
                return new FakeAiProvider;
            }

            if ($provider === 'fake') {
                if (app()->isProduction()) {
                    // Never fabricate customer replies in production. Fall through to real
                    // provider which fails safely when credentials are missing.
                    report(new \RuntimeException(
                        'ADMAN_AI_PROVIDER=fake is forbidden in production; using OpenAI-compatible provider.'
                    ));

                    return new OpenAiCompatibleProvider;
                }

                return new FakeAiProvider;
            }

            return new OpenAiCompatibleProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureBusinessTimezone();
        $this->configureProductionUrlScheme();
        ProductionSafety::logProductionWarnings();
    }

    protected function configureProductionUrlScheme(): void
    {
        if (app()->environment('production', 'staging')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Super Administrators bypass permission checks; everyone else is permission-driven.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (?User $user, string $ability) {
            if ($user === null) {
                return null;
            }

            if ($user->isSuperAdministrator()) {
                return true;
            }

            return null;
        });

        Gate::define('viewHorizon', function (?User $user) {
            return $user?->can(Permissions::SYSTEM_HORIZON) ?? false;
        });
    }

    /**
     * Apply the configured business timezone when the businesses table exists.
     */
    protected function configureBusinessTimezone(): void
    {
        try {
            if (! Schema::hasTable('businesses')) {
                return;
            }

            $timezone = Business::query()->value('timezone');

            if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
            }
        } catch (Throwable) {
            // Database may be unavailable during early bootstrap (e.g. package discovery).
        }
    }
}
