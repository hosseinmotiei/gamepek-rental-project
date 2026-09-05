<?php

namespace App\Services\Identity\Contracts;

use App\Services\Identity\Dto\IdentityCheckResult;

/**
 * KYC level-2 checks.
 *
 * Four operations rather than four interfaces: in Iran these are commonly
 * resold by a single aggregator, and splitting them would force four
 * near-identical config blocks for what is usually one contract, one bill and
 * one rate limit. If the owner picks separate vendors, an adapter can delegate
 * per method without changing any caller.
 *
 * No implementation here talks to a real service. Which providers exist, at
 * what URLs, with what response shapes, is not documented anywhere in this
 * project and is NOT invented -- see config/verification.php.
 */
interface IdentityProviderInterface
{
    /** Shahkar: is this mobile number registered to this national code? */
    public function matchMobileToNationalId(string $mobile, string $nationalCode): IdentityCheckResult;

    /** Civil registry (ثبت احوال): name and birth date for a national code. */
    public function fetchCivilRecord(string $nationalCode, ?string $birthDate): IdentityCheckResult;

    /** Liveness on an uploaded video: is this a live human, not a replay? */
    public function checkLiveness(string $disk, string $path): IdentityCheckResult;

    /** Face match between a submitted image and the registry photo. */
    public function matchFace(string $disk, string $probePath, string $nationalCode): IdentityCheckResult;
}
