<?php

namespace App\Services\Banking\Providers;

use App\Services\Banking\Contracts\BankOwnershipProviderInterface;
use App\Services\Banking\Dto\OwnershipResult;
use App\Services\Providers\ProviderConfig;

/**
 * Deterministic local stand-in. Never makes a network call.
 *
 * This is what replaces the old frontend lookupHolderName() -- but note the
 * difference that matters: that function invented a plausible Persian name and
 * showed it to the customer as if it were a bank answer. This returns an
 * obviously synthetic name and, more importantly, the RESULT is a server-side
 * state, not a string rendered in a template.
 */
class FakeBankOwnershipProvider implements BankOwnershipProviderInterface
{
    public function __construct(private ProviderConfig $config) {}

    public function verifyCardOwnership(string $pan, string $nationalCode): OwnershipResult
    {
        return $this->outcome($pan, iban: 'IR'.str_pad(substr(preg_replace('/\D/', '', $pan), -22), 24, '0', STR_PAD_LEFT));
    }

    public function verifyIbanOwnership(string $iban, string $nationalCode): OwnershipResult
    {
        return $this->outcome($iban, iban: $iban);
    }

    public function cardToIban(string $pan): OwnershipResult
    {
        return $this->outcome($pan, iban: 'IR'.str_pad(substr(preg_replace('/\D/', '', $pan), -22), 24, '0', STR_PAD_LEFT));
    }

    private function outcome(string $subject, ?string $iban): OwnershipResult
    {
        return match ($this->config->fakeOutcomeFor($subject)) {
            'fail' => new OwnershipResult(
                matched: false,
                conclusive: true,
                providerStatus: 'FAKE_MISMATCH',
                raw: ['driver' => 'fake'],
            ),
            'unknown' => new OwnershipResult(
                matched: false,
                conclusive: false,
                providerStatus: 'FAKE_UNKNOWN',
                raw: ['driver' => 'fake'],
            ),
            default => new OwnershipResult(
                matched: true,
                conclusive: true,
                ownerName: 'دارنده آزمایشی حساب',
                bankName: 'بانک آزمایشی',
                iban: $iban,
                reference: 'FAKE-'.substr(hash('sha256', $subject), 0, 12),
                providerStatus: 'FAKE_MATCH',
                raw: ['driver' => 'fake'],
            ),
        };
    }
}
