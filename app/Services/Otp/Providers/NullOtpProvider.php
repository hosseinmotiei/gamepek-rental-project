<?php

namespace App\Services\Otp\Providers;

use App\Services\Otp\OtpProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Local/testing-only stand-in for MelipayamakOtpProvider, used when
 * MELIPAYAMAK_API_KEY is empty so login isn't blocked while no real SMS
 * provider is configured (see CLAUDE.md "Deferred integrations"). Never
 * sends anything — generates the code itself and logs it. AppServiceProvider
 * only binds this outside production; MelipayamakOtpProvider is untouched.
 */
class NullOtpProvider implements OtpProviderInterface
{
    public function send(string $mobile): string
    {
        $length = (int) config('rental.otp.length', 5);
        $code = (string) random_int(
            (int) str_pad('1', $length, '0'),
            (int) str_pad('', $length, '9')
        );

        Log::info('NullOtpProvider: no SMS sent (no provider configured); OTP generated locally.', [
            'mobile' => $mobile,
            'code' => $code,
        ]);

        return json_encode(['code' => $code]);
    }
}
