<?php

namespace App\Services\Banking\Contracts;

use App\Services\Banking\Dto\OwnershipResult;

/**
 * Card / IBAN ownership. Kept separate from IdentityProviderInterface because
 * bank inquiry is a different vendor category with its own contract, tariff
 * and rate limit, even where an aggregator resells both.
 *
 * No implementation here talks to a real service.
 */
interface BankOwnershipProviderInterface
{
    public function verifyCardOwnership(string $pan, string $nationalCode): OwnershipResult;

    public function verifyIbanOwnership(string $iban, string $nationalCode): OwnershipResult;

    /** Card-to-IBAN resolution, the usual shape of Iranian bank-inquiry APIs. */
    public function cardToIban(string $pan): OwnershipResult;
}
