<?php

namespace App\Services\Guarantee\Providers;

use App\Services\Guarantee\Contracts\ChequeProviderInterface;
use App\Services\Guarantee\Dto\ChequeResult;
use App\Services\Providers\ProviderConfig;
use App\Support\Guarantee\SayadId;

/**
 * Deterministic local stand-in. Never makes a network call.
 *
 * validateSayadId() is the exception: it runs the REAL local check-digit
 * algorithm, because that is arithmetic on the number itself and needs no
 * provider. A structurally invalid id therefore fails here exactly as it would
 * against a live service.
 */
class FakeChequeProvider implements ChequeProviderInterface
{
    public function __construct(private ProviderConfig $config) {}

    public function validateSayadId(string $sayadId): ChequeResult
    {
        $valid = SayadId::isValid($sayadId);

        return new ChequeResult(
            outcome: $valid ? ChequeResult::PASS : ChequeResult::FAIL,
            fields: ['sayad_id_valid' => $valid],
            providerStatus: $valid ? 'LOCAL_VALID' : 'LOCAL_INVALID',
            raw: ['driver' => 'local-checksum'],
        );
    }

    public function inquire(string $sayadId): ChequeResult
    {
        return $this->outcome($sayadId, ['registered' => true]);
    }

    public function matchOwnership(string $sayadId, string $nationalCode): ChequeResult
    {
        return $this->outcome($sayadId, ['ownership_match' => true]);
    }

    public function bouncedCheques(string $nationalCode): ChequeResult
    {
        return $this->outcome($nationalCode, ['bounced_count' => 0], score: 0);
    }

    public function creditRisk(string $nationalCode): ChequeResult
    {
        return $this->outcome($nationalCode, ['risk_band' => 'low'], score: 10);
    }

    public function aggregate(string $nationalCode): ChequeResult
    {
        return $this->outcome($nationalCode, ['bounced_count' => 0, 'risk_band' => 'low'], score: 10);
    }

    public function status(string $sayadId): ChequeResult
    {
        return $this->outcome($sayadId, ['status' => 'registered']);
    }

    private function outcome(string $subject, array $fields, ?int $score = null): ChequeResult
    {
        return match ($this->config->fakeOutcomeFor($subject)) {
            'fail' => new ChequeResult(ChequeResult::FAIL, $score, [], null, 'FAKE_FAIL', ['driver' => 'fake']),
            'unknown' => new ChequeResult(ChequeResult::UNKNOWN, null, [], null, 'FAKE_UNKNOWN', ['driver' => 'fake']),
            default => new ChequeResult(
                outcome: ChequeResult::PASS,
                score: $score,
                fields: $fields,
                reference: 'FAKE-'.substr(hash('sha256', $subject), 0, 12),
                providerStatus: 'FAKE_PASS',
                raw: ['driver' => 'fake'],
            ),
        };
    }
}
