<?php

namespace App\Providers;

use App\Models\Address;
use App\Models\Order;
use App\Policies\AddressPolicy;
use App\Policies\OrderPolicy;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Otp\Providers\MelipayamakOtpProvider;
use App\Services\Otp\Providers\NullOtpProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Address::class => AddressPolicy::class,
        Order::class => OrderPolicy::class,
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
    }
}
