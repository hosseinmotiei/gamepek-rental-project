<?php

namespace App\Services\Banking\Providers;

use App\Services\Banking\Contracts\BankOwnershipProviderInterface;
use App\Services\Banking\Dto\OwnershipResult;
use App\Services\Providers\ProviderNotConfiguredException;

class UnconfiguredBankOwnershipProvider implements BankOwnershipProviderInterface
{
    public function verifyCardOwnership(string $pan, string $nationalCode): OwnershipResult
    {
        throw new ProviderNotConfiguredException('bank_ownership');
    }

    public function verifyIbanOwnership(string $iban, string $nationalCode): OwnershipResult
    {
        throw new ProviderNotConfiguredException('bank_ownership');
    }

    public function cardToIban(string $pan): OwnershipResult
    {
        throw new ProviderNotConfiguredException('bank_ownership');
    }
}
