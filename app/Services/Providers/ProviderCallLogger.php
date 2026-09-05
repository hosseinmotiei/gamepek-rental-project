<?php

namespace App\Services\Providers;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wraps one provider call: rate limit, timing, SLA warning, redacted logging,
 * and an audit_events row. Every adapter goes through here so those concerns
 * exist once rather than per vendor.
 */
class ProviderCallLogger
{
    public function __construct(private ProviderConfig $config) {}

    /**
     * @template T
     *
     * @param  callable():T  $call
     * @return array{result: T, duration_ms: int}
     *
     * @throws ProviderException when the per-minute rate limit is exhausted
     */
    public function around(string $operation, ?string $subjectKey, callable $call, array $auditContext = []): array
    {
        $this->enforceRateLimit($operation);

        $startedAt = microtime(true);
        $result = null;
        $failure = null;

        try {
            $result = $call();
        } catch (\Throwable $e) {
            $failure = $e;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $slaLimit = $this->config->slaAlertAfterMs();
        if ($slaLimit !== null && $durationMs > $slaLimit) {
            Log::warning('Provider call exceeded its SLA', [
                'service' => $this->config->service,
                'operation' => $operation,
                'duration_ms' => $durationMs,
                'sla_alert_after_ms' => $slaLimit,
            ]);
        }

        AuditLogger::log(
            action: 'provider.'.$this->config->service.'.'.$operation,
            resourceType: 'ProviderCall',
            resourceId: null,
            result: $failure ? AuditLogger::RESULT_FAILURE : AuditLogger::RESULT_SUCCESS,
            context: array_merge($auditContext, [
                'service' => $this->config->service,
                'driver' => $this->config->driver(),
                'environment' => $this->config->isSandbox() ? 'sandbox' : 'production',
                'duration_ms' => $durationMs,
                'cost_per_inquiry_rial' => $this->config->costPerInquiryRial(),
                'data_source' => $this->config->dataSourceAuthority(),
                'error' => $failure?->getMessage(),
            ]),
        );

        if ($failure) {
            throw $failure;
        }

        return ['result' => $result, 'duration_ms' => $durationMs];
    }

    private function enforceRateLimit(string $operation): void
    {
        $perMinute = $this->config->rateLimitPerMinute();

        if (! $perMinute) {
            return;
        }

        $key = 'provider:'.$this->config->service.':'.$operation;

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw new ProviderException(
                message: sprintf('Rate limit for %s.%s exhausted.', $this->config->service, $operation),
                persianMessage: 'تعداد درخواست‌های استعلام بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.',
                service: $this->config->service,
            );
        }

        RateLimiter::hit($key, 60);
    }
}
