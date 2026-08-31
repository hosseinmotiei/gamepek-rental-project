<?php

namespace App\Services\Otp\Providers;

use App\Services\Otp\Exceptions\OtpProviderException;
use App\Services\Otp\OtpProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * MeliPayamak OTP delivery.
 *
 * Rewritten rather than cloned. The Store's version was a pasted vendor
 * snippet that:
 *   - embedded the live API key directly in the URL in source control,
 *   - set CURLOPT_SSL_VERIFYPEER = false, disabling TLS certificate
 *     verification on a request that carries a customer's phone number,
 *   - had no timeout, so a hung provider hung the login request, and
 *   - ignored the config/services.php melipayamak.* keys that already existed.
 *
 * The contract is unchanged: MeliPayamak generates the code itself and this
 * returns its raw response body verbatim; OtpService extracts the "code"
 * field, hashes it and stores it. See OtpProviderInterface.
 */
class MelipayamakOtpProvider implements OtpProviderInterface
{
    public function send(string $mobile): string
    {
        $apiKey = (string) config('services.melipayamak.api_key');

        if ($apiKey === '') {
            throw new OtpProviderException('کلید سرویس پیامک تنظیم نشده است.');
        }

        $endpoint = rtrim((string) config('services.melipayamak.endpoint'), '/').'/'.$apiKey;
        $timeout = (int) config('services.melipayamak.timeout', 10);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, ['to' => $mobile]);
        } catch (\Throwable $e) {
            throw new OtpProviderException('ارتباط با سرویس پیامک برقرار نشد.', 0, $e);
        }

        if (! $response->successful()) {
            throw new OtpProviderException(
                'سرویس پیامک پاسخ نامعتبر داد (کد '.$response->status().').'
            );
        }

        return $response->body();
    }
}
