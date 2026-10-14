<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
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
     * Throttle chip transfers per authenticated user.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('chip-transfer', function (Request $request) {
            $user = $request->user();
            $key = $user === null ? $request->ip() : 'chip-transfer:'.$user->id;

            return Limit::perMinute((int) config('chips.transfer_rate_per_minute', 10))->by($key);
        });

        RateLimiter::for('room-mutations', function (Request $request) {
            $user = $request->user();
            $key = $user === null ? $request->ip() : 'room-mutations:'.$user->id;

            return Limit::perMinute((int) config('chips.room_rate_per_minute', 30))->by($key);
        });
    }
}
