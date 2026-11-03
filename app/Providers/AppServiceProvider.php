<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // El rol "admin" tiene acceso total, incluso a permisos agregados después
        Gate::before(function ($user) {
            return method_exists($user, 'hasRole') && $user->hasRole('admin') ? true : null;
        });

        // Intentos de inicio de sesión: por correo + IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')) . '|' . $request->ip());
        });
    }
}
