<?php

namespace App\Services\Guarantee\Providers;

use App\Services\Guarantee\Contracts\ChequeProviderInterface;
use App\Services\Guarantee\Dto\ChequeResult;
use App\Services\Providers\ProviderNotConfiguredException;

class UnconfiguredChequeProvider implements ChequeProviderInterface
{
    public function validateSayadId(string $sayadId): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }

    public function inquire(string $sayadId): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }

    public function matchOwnership(string $sayadId, string $nationalCode): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }

    public function bouncedCheques(string $nationalCode): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }

    public function creditRisk(string $nationalCode): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }

    public function aggregate(string $nationalCode): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }

    public function status(string $sayadId): ChequeResult
    {
        throw new ProviderNotConfiguredException('guarantee');
    }
}
