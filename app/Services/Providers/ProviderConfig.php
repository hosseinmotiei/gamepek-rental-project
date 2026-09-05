<?php

namespace App\Services\Providers;

/**
 * Typed reader over one block of config/verification.php.
 *
 * Every external service is configured with the same fourteen concerns
 * (sandbox/production, timeout, retry, rate limit, SLA, callback, error map,
 * response logging, retention, storage location, legal basis, data source,
 * cost per inquiry, plus the driver). Reading them through one object is what
 * makes a real adapter later "one class plus one binding" instead of a
 * per-vendor config archaeology exercise.
 */
readonly class ProviderConfig
{
    public function __construct(
        public string $service,
        private array $config,
    ) {}

    public static function for(string $service): self
    {
        return new self($service, config('verification.'.$service, []));
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'unconfigured');
    }

    public function isSandbox(): bool
    {
        return ($this->config['environment'] ?? 'sandbox') !== 'production';
    }

    public function baseUrl(): ?string
    {
        return $this->config['base_url'] ?? null;
    }

    public function credential(string $key): ?string
    {
        return $this->config['credentials'][$key] ?? null;
    }

    public function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 10);
    }

    public function connectTimeout(): int
    {
        return (int) ($this->config['connect_timeout'] ?? 5);
    }

    /** @return array{times:int, sleep_ms:int, on:list<int>} */
    public function retry(): array
    {
        return array_merge(['times' => 0, 'sleep_ms' => 0, 'on' => []], $this->config['retry'] ?? []);
    }

    public function rateLimitPerMinute(): ?int
    {
        return $this->config['rate_limit']['per_minute'] ?? null;
    }

    public function rateLimitPerUserPerDay(): ?int
    {
        return $this->config['rate_limit']['per_user_per_day'] ?? null;
    }

    public function slaAlertAfterMs(): ?int
    {
        return $this->config['sla']['alert_after_ms'] ?? null;
    }

    public function webhookEnabled(): bool
    {
        return (bool) ($this->config['callback']['enabled'] ?? false);
    }

    public function webhookSecret(): ?string
    {
        return $this->config['callback']['secret'] ?? null;
    }

    /** Provider status code => internal outcome. Empty until a vendor is chosen. */
    public function mapError(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return $this->config['error_map'][$code] ?? null;
    }

    public function logsResponses(): bool
    {
        return (bool) ($this->config['logging']['log_response'] ?? true);
    }

    /** @return list<string> */
    public function redactKeys(): array
    {
        return $this->config['logging']['redact'] ?? [];
    }

    public function rawRetentionDays(): ?int
    {
        return $this->config['retention']['raw_days'] ?? null;
    }

    public function resultRetentionDays(): ?int
    {
        return $this->config['retention']['result_days'] ?? null;
    }

    public function storageDisk(): string
    {
        return (string) ($this->config['storage']['disk'] ?? 'verification');
    }

    public function storagePath(): ?string
    {
        return $this->config['storage']['path'] ?? null;
    }

    /** null until the owner supplies it -- deliberately not invented. */
    public function legalPermissionReference(): ?string
    {
        return $this->config['legal']['permission_reference'] ?? null;
    }

    public function dataSourceAuthority(): ?string
    {
        return $this->config['source']['authority'] ?? null;
    }

    public function costPerInquiryRial(): ?int
    {
        return $this->config['cost']['per_inquiry_rial'] ?? null;
    }

    public function cacheTtlDays(): int
    {
        return (int) ($this->config['cache_ttl_days'] ?? 0);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /** The deterministic-fake knobs. */
    public function fakeOutcomeFor(?string $subject): string
    {
        $fixtures = $this->config['fake']['fixtures'] ?? [];

        if ($subject !== null && array_key_exists($subject, $fixtures)) {
            return (string) $fixtures[$subject];
        }

        return (string) ($this->config['fake']['default_outcome'] ?? 'pass');
    }
}
