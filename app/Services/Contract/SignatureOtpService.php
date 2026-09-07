<?php

namespace App\Services\Contract;

use App\Enums\ContractState;
use App\Enums\RentalApplicationState;
use App\Models\Contract;
use App\Models\ContractSignatureOtp;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Otp\Exceptions\OtpProviderException;
use App\Services\Otp\OtpProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * The signing challenge: a one-time code bound to one contract and one signer.
 *
 * Separate from OtpService on purpose. That one issues LOGIN codes keyed only
 * by mobile number; if signing shared it, a code sent to authorise a signature
 * would also open a session, and a code issued for one contract would sign
 * another. Here the challenge row itself carries the binding.
 *
 * What is NOT claimed: this is an authenticated one-time-code event, not a
 * PKI/qualified digital signature -- see InternalHmacSignatureProvider.
 */
class SignatureOtpService
{
    /** Matches OtpService's existing limit; not a new policy. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private OtpProviderInterface $provider) {}

    /**
     * The rungs a signature may be requested from. AwaitingFinalApproval is
     * here only so a duplicate request after a completed signature is refused
     * by the contract-state check below rather than by an ambiguous one.
     */
    private const SIGNABLE_FROM = [
        RentalApplicationState::ContractAccepted,
        RentalApplicationState::AwaitingFinalApproval,
    ];

    /**
     * Issues (or replaces) the challenge and returns the delivered code.
     *
     * The return value exists only for the project's established local
     * `dev_otp` mechanism; the caller must not surface it outside the
     * conditions OtpService::canShowOtpInDevelopment() already defines.
     */
    public function request(Contract $contract, User $user, ?string $ip = null): string
    {
        $this->assertSignable($contract, $user, 'contract.signature_otp_denied');

        try {
            $raw = $this->provider->send((string) $user->mobile);
        } catch (OtpProviderException $e) {
            // Fail closed: no provider, no challenge, no signature. The
            // provider's own message is never forwarded to the customer.
            AuditLogger::log(
                action: 'contract.signature_otp_denied',
                resourceType: 'Contract',
                resourceId: $contract->id,
                result: AuditLogger::RESULT_FAILURE,
                context: ['reason' => 'provider_unavailable'],
                actor: $user,
            );

            throw new \RuntimeException('ارسال کد تأیید ممکن نشد. لطفاً دوباره تلاش کنید.');
        }

        $decoded = json_decode($raw, true);
        $code = is_array($decoded) && isset($decoded['code']) ? (string) $decoded['code'] : null;

        if ($code === null || $code === '') {
            AuditLogger::log(
                action: 'contract.signature_otp_denied',
                resourceType: 'Contract',
                resourceId: $contract->id,
                result: AuditLogger::RESULT_FAILURE,
                context: ['reason' => 'provider_response_unusable'],
                actor: $user,
            );

            throw new \RuntimeException('ارسال کد تأیید ممکن نشد. لطفاً دوباره تلاش کنید.');
        }

        $expiryMinutes = (int) config('rental.otp.expiry_minutes', 2);

        // updateOrCreate on the unique pair: a retry replaces the live
        // challenge instead of leaving two valid codes behind.
        ContractSignatureOtp::updateOrCreate(
            ['contract_id' => $contract->id, 'user_id' => $user->id],
            [
                'code_hash' => self::hash($code),
                'expires_at' => now()->addMinutes($expiryMinutes),
                'attempts' => 0,
                'consumed_at' => null,
                'requested_ip' => $ip,
                'correlation_id' => AuditLogger::correlationId(),
            ],
        );

        AuditLogger::log(
            action: 'contract.signature_otp_requested',
            resourceType: 'Contract',
            resourceId: $contract->id,
            context: [
                'rental_application_id' => $contract->rental_application_id,
                'number' => $contract->number,
                'expires_in_minutes' => $expiryMinutes,
            ],
            actor: $user,
        );

        return $code;
    }

    /**
     * One-time, expiring, attempt-limited, and bound to this contract.
     *
     * Returns false for every failure mode rather than saying which -- a
     * caller that distinguished "expired" from "wrong" would leak the state of
     * someone else's challenge.
     */
    public function verify(Contract $contract, User $user, string $code): bool
    {
        $this->assertSignable($contract, $user, 'contract.signature_otp_denied');

        $outcome = DB::transaction(function () use ($contract, $user, $code) {
            $challenge = ContractSignatureOtp::where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return 'missing';
            }

            // Consumed first: a replayed code must fail even before expiry.
            if ($challenge->isConsumed()) {
                return 'replayed';
            }

            if ($challenge->isExpired()) {
                return 'expired';
            }

            if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                return 'locked';
            }

            $challenge->increment('attempts');

            if (! hash_equals($challenge->code_hash, self::hash($code))) {
                return 'mismatch';
            }

            $challenge->update(['consumed_at' => now()]);

            return 'verified';
        });

        if ($outcome === 'verified') {
            AuditLogger::log(
                action: 'contract.signature_otp_verified',
                resourceType: 'Contract',
                resourceId: $contract->id,
                context: [
                    'rental_application_id' => $contract->rental_application_id,
                    'number' => $contract->number,
                ],
                actor: $user,
            );

            return true;
        }

        AuditLogger::log(
            action: 'contract.signature_otp_denied',
            resourceType: 'Contract',
            resourceId: $contract->id,
            result: AuditLogger::RESULT_DENIED,
            // The reason, never the code.
            context: ['reason' => $outcome],
            actor: $user,
        );

        return false;
    }

    /**
     * The gate both halves share.
     *
     * Deliberately checks the APPLICATION's state as well as the contract's:
     * a contract row sitting at `accepted` on an application that has been
     * cancelled or rolled back is not signable.
     */
    private function assertSignable(Contract $contract, User $user, string $deniedAction): void
    {
        $contract->loadMissing('application');
        $application = $contract->application;

        $ok = $application !== null
            && in_array($application->state, self::SIGNABLE_FROM, true)
            && $application->user_id === $user->id
            && $contract->state === ContractState::Accepted
            && $contract->fresh()?->isIntact();

        if ($ok) {
            return;
        }

        AuditLogger::log(
            action: $deniedAction,
            resourceType: 'Contract',
            resourceId: $contract->id,
            result: AuditLogger::RESULT_DENIED,
            context: [
                'reason' => 'not_signable',
                'application_state' => $application?->state->value,
                'contract_state' => $contract->state->value,
            ],
            actor: $user,
        );

        throw new \RuntimeException('این قرارداد در وضعیت قابل امضا نیست.');
    }

    /**
     * Keyed with APP_KEY, exactly as OtpService::otpHmacSecret() explains: a
     * bare hash of a 5-digit code is trivially reversible offline.
     */
    private static function hash(string $code): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', $code, $key);
    }
}
