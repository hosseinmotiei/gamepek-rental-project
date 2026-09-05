<?php

namespace App\Services\Contract\Contracts;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;

/**
 * CON-04 / CON-05.
 *
 * The only implementation today is InternalHmacSignatureProvider, which is
 * explicitly NOT a PKI signature. The interface exists because a CA-backed
 * provider is a known, deferred deliverable, and swapping one in must not
 * touch ContractService.
 */
interface SignatureProviderInterface
{
    public function sign(Contract $contract, User $signer, array $evidence): ContractSignature;

    public function verify(ContractSignature $signature): bool;
}
