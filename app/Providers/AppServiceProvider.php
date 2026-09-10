<?php

namespace App\Providers;

use App\Models\Address;
use App\Models\Device;
use App\Models\Order;
use App\Models\Owner;
use App\Models\RentalApplication;
use App\Models\RentalOperation;
use App\Models\VerificationMedia;
use App\Policies\AddressPolicy;
use App\Policies\DevicePolicy;
use App\Policies\OrderPolicy;
use App\Policies\OwnerPolicy;
use App\Policies\RentalApplicationPolicy;
use App\Policies\RentalOperationPolicy;
use App\Policies\VerificationMediaPolicy;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Otp\Providers\MelipayamakOtpProvider;
use App\Services\Otp\Providers\NullOtpProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Address::class => AddressPolicy::class,
        Device::class => DevicePolicy::class,
        Order::class => OrderPolicy::class,
        Owner::class => OwnerPolicy::class,
        RentalApplication::class => RentalApplicationPolicy::class,
        RentalOperation::class => RentalOperationPolicy::class,
        VerificationMedia::class => VerificationMediaPolicy::class,
    ];

    public function register(): void
    {
        // BUG: config/database.php doesn't exist in this app, so Laravel's
        // MySqlConnector never issues `SET time_zone=...` on connect and the
        // session falls back to MySQL's own "SYSTEM" timezone. Since
        // otp_codes.expires_at/created_at/updated_at are TIMESTAMP columns
        // (MySQL converts these based on session timezone, unlike DATETIME),
        // a SYSTEM timezone that differs from PHP's UTC clock (app.timezone)
        // causes `WHERE expires_at > NOW()`-style comparisons to silently
        // exclude rows that are not actually expired -- reproduced directly:
        // a freshly-issued, unexpired OTP fails verification whenever the
        // row is re-fetched through a scope that filters on expires_at.
        // Forcing the session to a fixed UTC offset makes MySQL's timestamp
        // conversion consistent with PHP's own UTC clock everywhere.
        config([
            'database.connections.mysql.timezone' => '+00:00',
            'database.connections.mariadb.timezone' => '+00:00',
        ]);

        // Outside production, an unconfigured MELIPAYAMAK_API_KEY must not
        // block login entirely -- fall back to a local-only provider that
        // never sends real SMS (see NullOtpProvider docblock).
        $this->app->bind(OtpProviderInterface::class, fn () => (
            ! app()->isProduction() && (string) config('services.melipayamak.api_key') === ''
        ) ? new NullOtpProvider : new MelipayamakOtpProvider);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! app()->isProduction());

        Paginator::useTailwind();

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(function ($user, $ability) {
            if ($user->hasAnyRole(['super_admin', 'admin'])) {
                return true;
            }
        });

        $this->configureRateLimiters();
    }

    /**
     * Named limiters for the rental chain.
     *
     * `throttle:6,10` cannot be used for these. For an authenticated request
     * ThrottleRequests::resolveRequestSignature() keys on the user id ALONE --
     * not the route -- so every inline-throttled route shares one bucket per
     * user. In practice a customer who ran their identity checks, added a bank
     * account and submitted a guarantee had already spent the allowance and
     * got a 429 when asking for the signature OTP, locked out halfway through
     * signing. Each limiter below adds its own prefix to the key, which is
     * what actually separates the buckets.
     */
    private function configureRateLimiters(): void
    {
        $perUser = fn (string $name, int $attempts, int $minutes) => RateLimiter::for(
            $name,
            fn ($request) => Limit::perMinutes($minutes, $attempts)
                ->by($name.':'.($request->user()?->id ?: $request->ip())),
        );

        $perUser('verification-identity', 6, 10);
        $perUser('verification-media', 10, 10);
        $perUser('verification-bank', 6, 10);
        $perUser('rental-guarantee', 6, 10);
        // Deliberately its own bucket: signing a contract must never be able
        // to exhaust the login OTP allowance, or vice versa.
        $perUser('rental-signature-otp', 5, 10);
        $perUser('rental-signature', 5, 10);
    }
}
