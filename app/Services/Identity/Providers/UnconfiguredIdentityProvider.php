<?php

namespace App\Services\Identity\Providers;

use App\Services\Identity\Contracts\IdentityProviderInterface;
use App\Services\Identity\Dto\IdentityCheckResult;
use App\Services\Providers\ProviderNotConfiguredException;

/**
 * Bound whenever IDENTITY_DRIVER names something with no adapter class.
 *
 * It throws. A KYC check must never look like it happened because nothing was
 * wired up -- that is the failure mode that silently lets an unverified person
 * through the whole chain.
 */
class UnconfiguredIdentityProvider implements IdentityProviderInterface
{
    public function matchMobileToNationalId(string $mobile, string $nationalCode): IdentityCheckResult
    {
        throw new ProviderNotConfiguredException('identity');
    }

    public function fetchCivilRecord(string $nationalCode, ?string $birthDate): IdentityCheckResult
    {
        throw new ProviderNotConfiguredException('identity');
    }

    public function checkLiveness(string $disk, string $path): IdentityCheckResult
    {
        throw new ProviderNotConfiguredException('identity');
    }

    public function matchFace(string $disk, string $probePath, string $nationalCode): IdentityCheckResult
    {
        throw new ProviderNotConfiguredException('identity');
    }
}
