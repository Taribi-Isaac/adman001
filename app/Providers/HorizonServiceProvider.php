<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * Access is controlled via the system.horizon permission (and Super Administrator Gate::before).
     */
    protected function gate(): void
    {
        // Intentionally empty — AppServiceProvider defines viewHorizon using permissions.
    }
}
