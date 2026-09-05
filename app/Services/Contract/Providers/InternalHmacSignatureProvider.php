<?php

namespace App\Services\Contract\Providers;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;
use App\Services\Contract\Contracts\SignatureProviderInterface;

/**
 * Keyed-HMAC signature over the contract\'s content hash, the signer and the
 * timestamp, with SMS-OTP as the signer challenge.
 *
 * WHAT THIS PROVES: the stored contract text has not been altered since it was
 * signed, and whoever signed held the account\'s phone at that moment.
 *
 * WHAT THIS IS NOT: a PKI / certificate-backed digital signature. There is no
 * certificate authority, no non-repudiation against us as the key holder (we
 * hold APP_KEY and could forge a signature), and no eIDAS-equivalent standing.
 * Do not describe it to a customer or a court as a legally binding digital
 * signature. A CA-backed provider is TODO(integration).
 */
class InternalHmacSignatureProvider implements SignatureProviderInterface
{
    public function sign(Contract $contract, User $signer, array $evidence): ContractSignature
    {
        $signedAt = now();
        $contentHash = $contract->currentHash();

        return ContractSignature::create([
            'contract_id' => $contract->id,
            'user_id' => $signer->id,
            'method' => 'sms_otp',
            'signed_content_hash' => $contentHash,
            'signature' => self::compute($contentHash, $signer->id, $signedAt->toIso8601String()),
            'otp_reference' => $evidence['otp_reference'] ?? null,
            'ip_address' => $evidence['ip'] ?? null,
            'user_agent' => $evidence['user_agent'] ?? null,
            'evidence' => array_merge($evidence, [
                'signed_at_iso' => $signedAt->toIso8601String(),
                'algorithm' => 'hmac-sha256',
                'not_pki' => true,
            ]),
            'signed_at' => $signedAt,
        ]);
    }

    /**
     * Two independent checks:
     *
     *  1. The HMAC still matches the hash recorded at signing time.
     *  2. The contract text still hashes to that same value.
     *
     * The second catches someone editing `rendered_html` directly in the
     * database, which the first alone would not.
     */
    public function verify(ContractSignature $signature): bool
    {
        $signature->loadMissing('contract');

        $expected = self::compute(
            (string) $signature->signed_content_hash,
            (int) $signature->user_id,
            (string) ($signature->evidence['signed_at_iso'] ?? ''),
        );

        if (! hash_equals($expected, (string) $signature->signature)) {
            return false;
        }

        return hash_equals(
            (string) $signature->signed_content_hash,
            $signature->contract->currentHash(),
        );
    }

    private static function compute(string $contentHash, int $userId, string $signedAtIso): string
    {
        return hash_hmac('sha256', $contentHash.'|'.$userId.'|'.$signedAtIso, config('app.key'));
    }
}
