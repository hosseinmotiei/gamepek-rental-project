<?php

namespace App\Services\Identity;

use App\Enums\IdentityState;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\VerificationMedia;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\Contracts\IdentityProviderInterface;
use App\Services\Identity\Dto\IdentityCheckResult;
use App\Services\Providers\ProviderCallLogger;
use App\Services\Providers\ProviderConfig;
use App\Services\Providers\ProviderException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * KYC level 2.
 *
 * Two rules shape every method here:
 *
 *  1. Each check is a row with its own state. The parent identity's state is
 *     DERIVED from those rows by promote(), never assigned from a call site.
 *  2. Nothing auto-promotes to Verified while
 *     config('verification.identity.required_checks') is empty -- its default.
 *     No owner has said which checks constitute level 2, so the code refuses
 *     to decide (TODO(business) B1) and leaves the identity for an admin.
 */
class IdentityVerificationService
{
    public function __construct(
        private IdentityProviderInterface $provider,
    ) {}

    private function config(): ProviderConfig
    {
        return ProviderConfig::for('identity');
    }

    /**
     * Record the self-declared identity. Draft -> Submitted.
     *
     * The national code is encrypted on the way in and never returned.
     */
    public function submit(User $user, string $nationalCode, ?string $birthDate = null): UserIdentity
    {
        $digits = preg_replace('/\D/', '', $nationalCode) ?? '';

        if (! self::isValidNationalCode($digits)) {
            throw new \InvalidArgumentException('کد ملی وارد شده معتبر نیست.');
        }

        return DB::transaction(function () use ($user, $digits, $birthDate) {
            $identity = UserIdentity::firstOrNew(['user_id' => $user->id]);

            // A national code already bound to a different account is a fraud
            // signal, not a validation nicety -- the unique index would throw
            // anyway, but a Persian message is better than a 500.
            $existing = UserIdentity::where('national_code_hash', UserIdentity::hashNationalCode($digits))
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($existing) {
                AuditLogger::log(
                    action: 'identity.duplicate_national_code',
                    resourceType: 'User',
                    resourceId: $user->id,
                    result: AuditLogger::RESULT_DENIED,
                );

                throw new \InvalidArgumentException('این کد ملی قبلاً برای حساب دیگری ثبت شده است.');
            }

            $identity->setNationalCode($digits);
            $identity->birth_date = $birthDate;
            $identity->state = IdentityState::Submitted;
            $identity->kyc_level = max(1, (int) $identity->kyc_level);
            $identity->save();

            AuditLogger::log(
                action: 'identity.submitted',
                resourceType: 'UserIdentity',
                resourceId: $identity->id,
                context: ['national_code_mask' => $identity->national_code_mask],
            );

            return $identity;
        });
    }

    public function runShahkar(UserIdentity $identity): IdentityVerification
    {
        return $this->runCheck(
            $identity,
            IdentityVerification::TYPE_SHAHKAR,
            fn () => $this->provider->matchMobileToNationalId(
                (string) $identity->user->mobile,
                (string) $identity->nationalCode(),
            ),
        );
    }

    public function runCivilRegistry(UserIdentity $identity): IdentityVerification
    {
        $check = $this->runCheck(
            $identity,
            IdentityVerification::TYPE_CIVIL_REGISTRY,
            fn () => $this->provider->fetchCivilRecord(
                (string) $identity->nationalCode(),
                $identity->birth_date?->format('Y-m-d'),
            ),
        );

        $fields = $check->raw_response['fields'] ?? [];

        if ($check->state === IdentityVerification::STATE_PASSED && $fields) {
            $identity->update([
                'registry_first_name' => $fields['first_name'] ?? $identity->registry_first_name,
                'registry_last_name' => $fields['last_name'] ?? $identity->registry_last_name,
                'registry_father_name' => $fields['father_name'] ?? $identity->registry_father_name,
            ]);
        }

        return $check;
    }

    public function runLiveness(UserIdentity $identity, VerificationMedia $media): IdentityVerification
    {
        return $this->runCheck(
            $identity,
            IdentityVerification::TYPE_LIVENESS,
            fn () => $this->provider->checkLiveness((string) $media->disk, (string) $media->path),
        );
    }

    public function runFaceMatch(UserIdentity $identity, VerificationMedia $media): IdentityVerification
    {
        return $this->runCheck(
            $identity,
            IdentityVerification::TYPE_FACE_MATCH,
            fn () => $this->provider->matchFace(
                (string) $media->disk,
                (string) $media->path,
                (string) $identity->nationalCode(),
            ),
        );
    }

    /**
     * Idempotent per (identity, type) within the configured TTL: a check that
     * already passed recently is returned as-is rather than paid for twice.
     */
    private function runCheck(UserIdentity $identity, string $type, callable $call): IdentityVerification
    {
        $config = $this->config();

        $cached = $this->recentPassedCheck($identity, $type);
        if ($cached) {
            return $cached;
        }

        $this->guardAttemptCap($identity, $type);

        // An in-flight check IS the Checking state. Without this the identity
        // stays Submitted, and promote() -- which may only move on from
        // Checking -- can never route it to ManualReview or Failed.
        $this->transition($identity, IdentityState::Checking);

        $row = IdentityVerification::create([
            'user_identity_id' => $identity->id,
            'type' => $type,
            'provider' => $config->driver(),
            'state' => IdentityVerification::STATE_CHECKING,
            'request_id' => (string) Str::uuid(),
            'correlation_id' => AuditLogger::correlationId(),
            'retention_until' => $config->rawRetentionDays()
                ? now()->addDays($config->rawRetentionDays())
                : null,
        ]);

        $logger = new ProviderCallLogger($config);

        try {
            ['result' => $result, 'duration_ms' => $durationMs] = $logger->around(
                operation: $type,
                subjectKey: (string) $identity->id,
                call: $call,
                auditContext: ['user_identity_id' => $identity->id, 'type' => $type],
            );
        } catch (ProviderException $e) {
            $row->update([
                'state' => IdentityVerification::STATE_FAILED,
                'provider_status_code' => $e->providerCode,
                'checked_at' => now(),
                // Only the technical message is stored; the Persian one is what
                // surfaces to the customer.
                'raw_response' => ['error' => $e->getMessage()],
            ]);

            $this->promote($identity);

            throw $e;
        }

        /** @var IdentityCheckResult $result */
        $row->update([
            'state' => $this->stateFor($result),
            'score' => $result->score,
            'provider_reference' => $result->reference,
            'provider_status_code' => $result->providerStatus,
            'checked_at' => now(),
            'duration_ms' => $durationMs,
            'raw_response' => ['fields' => $result->fields, 'raw' => $result->raw],
        ]);

        $this->promote($identity);

        return $row->refresh();
    }

    /**
     * The three-way mapping that matters:
     *
     *  - matched                       -> passed, unless a score threshold is
     *                                     configured and the score misses it
     *  - not matched, conclusive       -> failed (the registry said no)
     *  - not matched, inconclusive     -> manual review (we could not find out)
     *
     * With min_score null (its default, TODO(business) B2) any scored check
     * routes to manual review rather than passing on an invented threshold.
     */
    private function stateFor(IdentityCheckResult $result): string
    {
        if (! $result->matched) {
            return $result->conclusive
                ? IdentityVerification::STATE_FAILED
                : IdentityVerification::STATE_MANUAL_REVIEW;
        }

        if ($result->score === null) {
            return IdentityVerification::STATE_PASSED;
        }

        $minScore = $this->config()->get('min_score');

        if ($minScore === null) {
            return IdentityVerification::STATE_MANUAL_REVIEW;
        }

        return $result->score >= (int) $minScore
            ? IdentityVerification::STATE_PASSED
            : IdentityVerification::STATE_FAILED;
    }

    /**
     * Derive the identity state from its check rows. Never called with an
     * explicit target -- that is the point.
     */
    public function promote(UserIdentity $identity): UserIdentity
    {
        $required = (array) $this->config()->get('required_checks', []);

        $identity->load('verifications');

        $anyFailed = $identity->verifications
            ->where('state', IdentityVerification::STATE_FAILED)
            ->isNotEmpty();

        $anyManual = $identity->verifications
            ->where('state', IdentityVerification::STATE_MANUAL_REVIEW)
            ->isNotEmpty();

        if ($required === []) {
            // No owner-approved policy exists. Refuse to decide.
            Log::info('identity.policy_undefined', ['user_identity_id' => $identity->id]);

            AuditLogger::log(
                action: 'identity.policy_undefined',
                resourceType: 'UserIdentity',
                resourceId: $identity->id,
                result: AuditLogger::RESULT_DENIED,
                context: ['note' => 'verification.identity.required_checks is empty; awaiting a business decision'],
            );

            $this->transition($identity, IdentityState::ManualReview);

            return $identity;
        }

        $passed = $identity->verifications
            ->where('state', IdentityVerification::STATE_PASSED)
            ->pluck('type')
            ->unique()
            ->all();

        $missing = array_diff($required, $passed);

        if ($missing === []) {
            $this->transition($identity, IdentityState::Verified);
            $identity->update(['kyc_level' => 2, 'verified_at' => now()]);

            AuditLogger::log(
                action: 'identity.verified',
                resourceType: 'UserIdentity',
                resourceId: $identity->id,
                context: ['checks' => $passed],
            );

            return $identity->refresh();
        }

        if ($anyManual) {
            $this->transition($identity, IdentityState::ManualReview);

            return $identity;
        }

        if ($anyFailed) {
            $this->transition($identity, IdentityState::Failed);

            return $identity;
        }

        $this->transition($identity, IdentityState::Checking);

        return $identity;
    }

    public function approveManually(UserIdentity $identity, User $admin, ?string $note = null): UserIdentity
    {
        $this->transition($identity, IdentityState::Verified, force: true);
        $identity->update(['kyc_level' => 2, 'verified_at' => now()]);

        AuditLogger::log(
            action: 'identity.manual_approve',
            resourceType: 'UserIdentity',
            resourceId: $identity->id,
            context: ['note' => $note, 'bypassed_automated_checks' => true],
            actor: $admin,
        );

        return $identity->refresh();
    }

    public function reject(UserIdentity $identity, string $reason, ?User $admin = null): UserIdentity
    {
        $this->transition($identity, IdentityState::Rejected, force: true);
        $identity->update(['rejected_at' => now(), 'rejection_reason' => $reason]);

        AuditLogger::log(
            action: 'identity.rejected',
            resourceType: 'UserIdentity',
            resourceId: $identity->id,
            result: AuditLogger::RESULT_DENIED,
            context: ['reason' => $reason],
            actor: $admin,
        );

        return $identity->refresh();
    }

    private function transition(UserIdentity $identity, IdentityState $target, bool $force = false): void
    {
        if ($identity->state === $target) {
            return;
        }

        if (! $force && ! $identity->state->canTransitionTo($target)) {
            return;
        }

        $identity->update(['state' => $target]);
    }

    private function recentPassedCheck(UserIdentity $identity, string $type): ?IdentityVerification
    {
        $ttlDays = $this->config()->cacheTtlDays();

        if ($ttlDays <= 0) {
            return null;
        }

        return IdentityVerification::where('user_identity_id', $identity->id)
            ->where('type', $type)
            ->where('state', IdentityVerification::STATE_PASSED)
            ->where('checked_at', '>=', now()->subDays($ttlDays))
            ->latest('checked_at')
            ->first();
    }

    private function guardAttemptCap(UserIdentity $identity, string $type): void
    {
        $max = $this->config()->get('max_attempts');

        if ($max === null) {
            return;
        }

        $attempts = IdentityVerification::where('user_identity_id', $identity->id)
            ->where('type', $type)
            ->count();

        if ($attempts >= (int) $max) {
            AuditLogger::log(
                action: 'identity.attempt_cap_reached',
                resourceType: 'UserIdentity',
                resourceId: $identity->id,
                result: AuditLogger::RESULT_DENIED,
                context: ['type' => $type, 'attempts' => $attempts],
            );

            throw new \RuntimeException('تعداد دفعات مجاز استعلام برای این مورد به پایان رسیده است. لطفاً با پشتیبانی تماس بگیرید.');
        }
    }

    /**
     * The standard Iranian national-code check digit. Pure arithmetic on the
     * number itself, so it needs no provider and costs nothing -- it stops a
     * typo before a paid inquiry.
     */
    public static function isValidNationalCode(string $code): bool
    {
        if (! preg_match('/^\d{10}$/', $code)) {
            return false;
        }

        if (preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $code[$i]) * (10 - $i);
        }

        $remainder = $sum % 11;
        $check = (int) $code[9];

        return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
    }
}
