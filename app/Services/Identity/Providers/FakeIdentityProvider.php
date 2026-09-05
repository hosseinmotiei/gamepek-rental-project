<?php

namespace App\Services\Identity\Providers;

use App\Services\Identity\Contracts\IdentityProviderInterface;
use App\Services\Identity\Dto\IdentityCheckResult;
use App\Services\Providers\ProviderConfig;

/**
 * Deterministic local stand-in. Never makes a network call.
 *
 * Outcomes come from config('verification.identity.fake.fixtures'), keyed by
 * national code, falling back to `default_outcome`. Deterministic on purpose:
 * a random fake makes the end-to-end test flaky and hides real bugs.
 *
 * Recognised outcomes: pass | fail | unknown.
 */
class FakeIdentityProvider implements IdentityProviderInterface
{
    public function __construct(private ProviderConfig $config) {}

    public function matchMobileToNationalId(string $mobile, string $nationalCode): IdentityCheckResult
    {
        return $this->outcome($nationalCode, ['mobile_matched' => true]);
    }

    public function fetchCivilRecord(string $nationalCode, ?string $birthDate): IdentityCheckResult
    {
        return $this->outcome($nationalCode, [
            // Clearly synthetic placeholder names. Real registry names arrive
            // only from a real provider.
            'first_name' => 'نام‌آزمایشی',
            'last_name' => 'خانوادگی‌آزمایشی',
            'father_name' => 'پدرآزمایشی',
            'birth_date' => $birthDate,
        ]);
    }

    public function checkLiveness(string $disk, string $path): IdentityCheckResult
    {
        return $this->outcome($path, ['liveness' => true], score: 90);
    }

    public function matchFace(string $disk, string $probePath, string $nationalCode): IdentityCheckResult
    {
        return $this->outcome($nationalCode, ['face_match' => true], score: 88);
    }

    private function outcome(?string $subject, array $fields, ?int $score = null): IdentityCheckResult
    {
        $latency = (int) $this->config->get('fake.latency_ms', 0);
        if ($latency > 0) {
            usleep($latency * 1000);
        }

        return match ($this->config->fakeOutcomeFor($subject)) {
            'fail' => new IdentityCheckResult(
                matched: false,
                conclusive: true,
                score: $score !== null ? 0 : null,
                providerStatus: 'FAKE_FAIL',
                raw: ['driver' => 'fake'],
            ),
            'unknown' => new IdentityCheckResult(
                matched: false,
                conclusive: false,
                providerStatus: 'FAKE_UNKNOWN',
                raw: ['driver' => 'fake'],
            ),
            default => new IdentityCheckResult(
                matched: true,
                conclusive: true,
                score: $score,
                reference: 'FAKE-'.substr(hash('sha256', (string) $subject), 0, 12),
                providerStatus: 'FAKE_PASS',
                fields: $fields,
                raw: ['driver' => 'fake'],
            ),
        };
    }
}
