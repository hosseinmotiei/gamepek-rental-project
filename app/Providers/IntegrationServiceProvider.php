<?php

namespace App\Providers;

use App\Services\Banking\Contracts\BankOwnershipProviderInterface;
use App\Services\Banking\Providers\FakeBankOwnershipProvider;
use App\Services\Banking\Providers\UnconfiguredBankOwnershipProvider;
use App\Services\Contract\Contracts\SignatureProviderInterface;
use App\Services\Contract\Providers\InternalHmacSignatureProvider;
use App\Services\Guarantee\Contracts\ChequeProviderInterface;
use App\Services\Guarantee\Providers\FakeChequeProvider;
use App\Services\Guarantee\Providers\UnconfiguredChequeProvider;
use App\Services\Identity\Contracts\IdentityProviderInterface;
use App\Services\Identity\Providers\FakeIdentityProvider;
use App\Services\Identity\Providers\UnconfiguredIdentityProvider;
use App\Services\Notification\Contracts\SmsSenderInterface;
use App\Services\Notification\Senders\LogSmsSender;
use App\Services\Notification\Senders\UnconfiguredSmsSender;
use App\Services\Providers\ProviderConfig;
use Illuminate\Support\ServiceProvider;

/**
 * Binds every external-integration seam from config/verification.php.
 *
 * The binding rule, which matters more than the bindings themselves: a driver
 * with no registered adapter class resolves to the `unconfigured` provider,
 * which THROWS. It never silently falls back to the fake. A verification that
 * appears to have succeeded because nothing was wired up is the one failure
 * mode this whole layer exists to prevent -- the same reasoning as the
 * production guard in PaymentService/GatewayRegistry.
 *
 * The `fake` driver is additionally refused outside local/testing.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * service => [driver => adapter class]. Real vendor adapters are added
     * here, one line each, once the owner names a provider. No entry is
     * invented for a vendor that has not been chosen.
     */
    private const ADAPTERS = [
        'identity' => [
            'fake' => FakeIdentityProvider::class,
        ],
        'bank_ownership' => [
            'fake' => FakeBankOwnershipProvider::class,
        ],
        'guarantee' => [
            'fake' => FakeChequeProvider::class,
        ],
        'sms' => [
            'log' => LogSmsSender::class,
        ],
    ];

    private const UNCONFIGURED = [
        'identity' => UnconfiguredIdentityProvider::class,
        'bank_ownership' => UnconfiguredBankOwnershipProvider::class,
        'guarantee' => UnconfiguredChequeProvider::class,
        'sms' => UnconfiguredSmsSender::class,
    ];

    public function register(): void
    {
        $this->bindProvider('identity', IdentityProviderInterface::class);
        $this->bindProvider('bank_ownership', BankOwnershipProviderInterface::class);
        $this->bindProvider('guarantee', ChequeProviderInterface::class);
        $this->bindSms();

        // The signature provider has exactly one implementation and no
        // deferred vendor decision blocking it, so it is bound directly. The
        // interface exists only so a CA-backed provider can replace it without
        // touching ContractService.
        $this->app->bind(SignatureProviderInterface::class, InternalHmacSignatureProvider::class);
    }

    private function bindProvider(string $service, string $interface): void
    {
        $this->app->bind($interface, function () use ($service) {
            $config = ProviderConfig::for($service);
            $class = $this->resolveAdapterClass($service, $config->driver());

            return $class === self::UNCONFIGURED[$service]
                ? new $class
                : new $class($config);
        });
    }

    private function bindSms(): void
    {
        $this->app->bind(SmsSenderInterface::class, function () {
            $driver = config('verification.sms.driver', app()->isProduction() ? 'unconfigured' : 'log');
            $class = $this->resolveAdapterClass('sms', $driver);

            return new $class;
        });
    }

    private function resolveAdapterClass(string $service, string $driver): string
    {
        $class = self::ADAPTERS[$service][$driver] ?? null;

        if (! $class) {
            return self::UNCONFIGURED[$service];
        }

        // A fake must never answer a real customer's verification.
        if ($driver === 'fake' && ! $this->app->environment(['local', 'testing'])) {
            return self::UNCONFIGURED[$service];
        }

        return $class;
    }
}
