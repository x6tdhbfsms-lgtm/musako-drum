<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('trial-application', function (Request $request): Limit {
            return Limit::perMinute((int) config('musako.public_forms.trial_rate_limit_per_minute'))
                ->by(Str::lower($request->string('email')).'|'.$request->ip());
        });

        RateLimiter::for('admission-application', function (Request $request): Limit {
            return Limit::perMinute((int) config('musako.public_forms.admission_rate_limit_per_minute'))
                ->by(Str::lower($request->string('email')).'|'.$request->ip());
        });
    }
}
