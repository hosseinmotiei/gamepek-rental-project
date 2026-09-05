<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\UnconfiguredGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a gateway key to its adapter.
 *
 * Preserves the production guard that used to live at the top of
 * PaymentService::initiatePayment(): an unrecognised or misconfigured
 * PAYMENT_GATEWAY value must NEVER fall through to mock outside
 * local/testing. Here that guard is structural -- mock is simply not
 * resolvable in any other environment.
 */
class GatewayRegistry
{
    public function __construct(private Container $container) {}

    public function default(): PaymentGatewayInterface
    {
        return $this->for(config('rental.payment.gateway', 'mock'));
    }

    public function for(?string $key): PaymentGatewayInterface
    {
        $key = $key ?: 'unconfigured';

        if ($key === MockGateway::class || $key === 'mock') {
            return $this->allowsMock()
                ? $this->container->make(MockGateway::class)
                : new UnconfiguredGateway('mock');
        }

        $class = config('rental.payment.gateways.'.$key);

        if (! $class || ! class_exists($class)) {
            return new UnconfiguredGateway($key);
        }

        $gateway = $this->container->make($class);

        return $gateway instanceof PaymentGatewayInterface
            ? $gateway
            : new UnconfiguredGateway($key);
    }

    /** @return list<string> */
    public function configuredKeys(): array
    {
        return array_keys(config('rental.payment.gateways', []));
    }

    private function allowsMock(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
