<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        Gate::define('view-admin', fn (User $user): bool => $user->hasRole([
            UserRole::Manager,
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('manage-catalog', fn (User $user): bool => $user->hasRole([
            UserRole::Manager,
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('manage-orders', fn (User $user): bool => $user->hasRole([
            UserRole::Manager,
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('manage-crm', fn (User $user): bool => $user->hasRole([
            UserRole::Manager,
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('manage-warehouse', fn (User $user): bool => $user->hasRole([
            UserRole::WarehouseOperator,
            UserRole::Manager,
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('manage-system-settings', fn (User $user): bool => $user->hasRole(UserRole::SuperAdministrator));
        Gate::define('manage-store-settings', fn (User $user): bool => $user->hasRole([
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('view-store-analytics', fn (User $user): bool => $user->hasRole([
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ]));
        Gate::define('manage-user-roles', fn (User $user): bool => $user->hasRole(UserRole::SuperAdministrator));

        RateLimiter::for('storefront-view', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('login', function (Request $request): array {
            $identifier = trim((string) $request->input('email'));
            $identifier = str_contains($identifier, '@')
                ? mb_strtolower($identifier)
                : PhoneNumber::normalize($identifier);

            return [
                Limit::perMinute(5)->by($identifier.'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });
        RateLimiter::for('auth-write', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('password-reset', function (Request $request): array {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(3)->by($email.'|'.$request->ip()),
                Limit::perMinute(10)->by($request->ip()),
            ];
        });

        VerifyEmail::createUrlUsing(function ($user): string {
            $signedPath = URL::temporarySignedRoute(
                'api.v1.auth.email.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
                absolute: false,
            );

            return rtrim((string) config('app.frontend_url', config('app.url')), '/').$signedPath;
        });

        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

            return $frontendUrl.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }
}
