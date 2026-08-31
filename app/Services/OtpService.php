<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Otp\Exceptions\OtpProviderException;
use App\Services\Otp\OtpProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    public function __construct(private OtpProviderInterface $otpProvider) {}

    public function generateAndSend(string $mobile): string
    {
        $rateLimitKey = "otp_request:{$mobile}";
        $maxAttempts = (int) config('rental.otp.rate_limit', 5);
        $decaySeconds = (int) config('rental.otp.rate_limit_decay_minutes', 15) * 60;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            throw new \Exception('درخواست بیش از حد. لطفاً چند دقیقه دیگر تلاش کنید.', 429);
        }

        RateLimiter::hit($rateLimitKey, $decaySeconds);

        // Invalidate any existing unused login OTPs for this mobile only --
        // scoped to purpose so a future non-login OTP flow can't clobber it.
        OtpCode::forMobile($mobile)->valid()->where('purpose', 'login')->update(['is_used' => true]);

        try {
            // The provider returns MeliPayamak's raw HTTP response body
            // verbatim (untouched, by design). The actual OTP digits are
            // inside its "code" field -- e.g. {"status":1,"code":"54321"} --
            // so it must be extracted here before hashing/storing/returning it.
            $rawResponse = $this->otpProvider->send($mobile);
            $decoded = json_decode($rawResponse, true);
            $sentCode = is_array($decoded) && isset($decoded['code']) ? (string) $decoded['code'] : null;

            if ($sentCode === null) {
                Log::error('MeliPayamak response did not contain a usable code.', [
                    'mobile' => $mobile,
                    'raw_response' => $rawResponse,
                ]);

                throw new OtpProviderException('پاسخ نامعتبر از سرویس پیامک دریافت شد.');
            }
        } catch (OtpProviderException $e) {
            Log::error('OTP provider send failed.', [
                'mobile' => $mobile,
                'exception' => $e->getMessage(),
            ]);

            // TEMPORARY — while APP_DEBUG=true, surface the real provider
            // error instead of the generic safe message. Revert once the
            // MeliPayamak failure is diagnosed.
            $message = config('app.debug')
                ? $e->getMessage()
                : 'ارسال کد تایید با مشکل مواجه شد. لطفاً چند لحظه دیگر تلاش کنید.';

            throw new \Exception($message, 503, $e);
        }

        $expiryMinutes = (int) config('rental.otp.expiry_minutes', 2);

        OtpCode::create([
            'mobile' => $mobile,
            'code' => hash_hmac('sha256', $sentCode, $this->otpHmacSecret()),  // keyed hash only, never plain text
            'purpose' => 'login',
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        return $sentCode;
    }

    public function verify(string $mobile, string $code): bool
    {
        $otp = OtpCode::forMobile($mobile)
            ->valid()
            ->where('purpose', 'login')
            ->latest()
            ->first();

        if (! $otp) {
            return false;
        }

        $otp->increment('attempts');

        // Max 5 wrong attempts
        if ($otp->attempts > 5) {
            $otp->update(['is_used' => true]);

            return false;
        }

        if (! hash_equals($otp->code, hash_hmac('sha256', $code, $this->otpHmacSecret()))) {
            return false;
        }

        $otp->update(['is_used' => true]);

        return true;
    }

    public function getRemainingSeconds(string $mobile): int
    {
        $rateLimitKey = "otp_request:{$mobile}";

        return RateLimiter::availableIn($rateLimitKey);
    }

    public function canShowOtpInDevelopment(): bool
    {
        return (bool) config('rental.otp.show_in_dev', false)
            && ! app()->environment('production')
            && (app()->environment(['local', 'development']) || (bool) config('app.debug'));
    }

    /**
     * Derive the OTP HMAC key from the app's own encryption key, never a
     * separate hardcoded secret. A DB-only compromise cannot recover this
     * (it lives in .env/APP_KEY, not the database), so stored OTP hashes
     * can no longer be brute-forced offline over the 6-digit keyspace.
     */
    private function otpHmacSecret(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return $key;
    }
}
