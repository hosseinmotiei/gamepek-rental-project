<?php

namespace App\Services\Otp;

use App\Services\Otp\Exceptions\OtpProviderException;

/**
 * Contract the OTP delivery channel must implement, so OtpService's
 * business logic (generate, hash, store, rate-limit, verify) never changes
 * if the underlying provider is ever swapped.
 */
interface OtpProviderInterface
{
    /**
     * Send an OTP to $mobile. The provider generates the code itself and
     * this returns the code that was actually sent, which is the one
     * OtpService hashes and stores.
     *
     * @throws OtpProviderException on network failure, timeout, or any
     *                              non-success response.
     */
    public function send(string $mobile): string;
}
