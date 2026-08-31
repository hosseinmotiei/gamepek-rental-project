<?php

namespace App\Services\Otp\Exceptions;

/**
 * Thrown by any OtpProviderInterface implementation on network failure,
 * timeout, or a non-success response from the provider. The message on
 * this exception is a safe, generic string only -- callers must never
 * surface a provider's raw error body to the end user (see OtpService,
 * which catches this and maps it to a fixed Persian message).
 */
class OtpProviderException extends \RuntimeException {}
