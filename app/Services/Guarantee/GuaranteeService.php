<?php

namespace App\Services\Guarantee;

use App\Enums\GuaranteeState;
use App\Models\Guarantee;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Guarantee\Contracts\ChequeProviderInterface;
use App\Services\Guarantee\Dto\ChequeResult;
use App\Services\Providers\ProviderCallLogger;
use App\Services\Providers\ProviderConfig;
use App\Services\Providers\ProviderException;
use App\Support\Guarantee\SayadId;
use Illuminate\Support\Str;

/**
 * CHEQUE-01..07 orchestration.
 *
 * What is implemented: the state model, all seven inquiries, one row per
 * inquiry with its own state, idempotency per (guarantee, kind), and audit.
 *
 * What is deliberately NOT implemented: the issuance / verification / return /
 * cancel workflow, because no business requirement defines it. The three
 * decisions that would drive it are config keys that default to empty:
 *
 *   verification.guarantee.required_inquiries  TODO(business) B6
 *   verification.guarantee.amount_rule         TODO(business) B5
 *   verification.guarantee.risk_thresholds     TODO(business) B7
 *
 * While they are empty a guarantee never auto-verifies and never auto-rejects.
 * It runs its inquiries, records everything, and waits for an admin.
 */
class GuaranteeService
{
    public function __construct(private ChequeProviderInterface $provider) {}

    private function config(): ProviderConfig
    {
        return ProviderConfig::for('guarantee');
    }

    public function submit(RentalApplication $application, array $data): Guarantee
    {
        $sayadId = SayadId::normalise((string) ($data['sayad_id'] ?? ''));

        if ($sayadId !== '' && ! SayadId::isValid($sayadId)) {
            throw new \InvalidArgumentException('شناسه صیاد وارد شده معتبر نیست.');
        }

        $guarantee = Guarantee::updateOrCreate(
            ['rental_application_id' => $application->id],
            [
                'type' => $data['type'] ?? Guarantee::TYPE_CHEQUE,
                'sayad_id' => $sayadId ?: null,
                // TODO(business) B5: no amount rule is defined, so the
                // submitted amount is recorded as-is and NOT validated against
                // the deposit or the device value.
                'amount' => $data['amount'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'bank_code' => $data['bank_code'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'state' => GuaranteeState::Submitted,
            ],
        );

        AuditLogger::log(
            action: 'guarantee.submitted',
            resourceType: 'Guarantee',
            resourceId: $guarantee->id,
            context: [
                'type' => $guarantee->type,
                'sayad_id_mask' => $sayadId ? SayadId::mask($sayadId) : null,
                'amount_rule' => $this->config()->get('amount_rule') ?? 'undefined',
            ],
        );

        return $guarantee;
    }

    /**
     * Runs CHEQUE-01..07. Idempotent per (guarantee, kind) within the TTL.
     */
    public function runInquiries(Guarantee $guarantee): Guarantee
    {
        $guarantee->update(['state' => GuaranteeState::Inquiring]);

        $nationalCode = $guarantee->application?->user?->identity?->nationalCode();

        foreach (GuaranteeInquiry::ALL_KINDS as $kind) {
            $this->runInquiry($guarantee, $kind, $nationalCode);
        }

        return $this->promote($guarantee);
    }

    public function runInquiry(Guarantee $guarantee, string $kind, ?string $nationalCode): GuaranteeInquiry
    {
        $config = $this->config();

        $cached = $this->recentInquiry($guarantee, $kind);
        if ($cached) {
            return $cached;
        }

        $row = GuaranteeInquiry::create([
            'guarantee_id' => $guarantee->id,
            'kind' => $kind,
            'provider' => $config->driver(),
            'state' => 'checking',
            'request_id' => (string) Str::uuid(),
            'correlation_id' => AuditLogger::correlationId(),
            'retention_until' => $config->rawRetentionDays()
                ? now()->addDays($config->rawRetentionDays())
                : null,
        ]);

        $logger = new ProviderCallLogger($config);
        $sayadId = (string) $guarantee->sayad_id;

        try {
            ['result' => $result, 'duration_ms' => $durationMs] = $logger->around(
                operation: $kind,
                subjectKey: (string) $guarantee->id,
                call: fn () => $this->dispatch($kind, $sayadId, (string) $nationalCode),
                auditContext: ['guarantee_id' => $guarantee->id, 'kind' => $kind],
            );
        } catch (ProviderException $e) {
            $row->update([
                'state' => 'failed',
                'result' => ChequeResult::UNKNOWN,
                'provider_status_code' => $e->providerCode,
                'raw_response' => ['error' => $e->getMessage()],
            ]);

            return $row->refresh();
        }

        /** @var ChequeResult $result */
        $row->update([
            'state' => 'completed',
            'result' => $result->outcome,
            'score' => $result->score,
            'provider_status_code' => $result->providerStatus,
            'fields' => $result->fields,
            'raw_response' => $result->raw,
            'duration_ms' => $durationMs,
        ]);

        if ($kind === GuaranteeInquiry::KIND_OWNERSHIP_MATCH) {
            $guarantee->update(['ownership_match' => $result->passed()]);
        }

        if (in_array($kind, [GuaranteeInquiry::KIND_CREDIT_RISK, GuaranteeInquiry::KIND_AGGREGATE], true)
            && $result->score !== null) {
            $guarantee->update(['risk_score' => $result->score]);
        }

        return $row->refresh();
    }

    private function dispatch(string $kind, string $sayadId, string $nationalCode): ChequeResult
    {
        return match ($kind) {
            GuaranteeInquiry::KIND_SAYAD_VALIDATE => $this->provider->validateSayadId($sayadId),
            GuaranteeInquiry::KIND_CHEQUE_INQUIRY => $this->provider->inquire($sayadId),
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH => $this->provider->matchOwnership($sayadId, $nationalCode),
            GuaranteeInquiry::KIND_BOUNCED_CHEQUE => $this->provider->bouncedCheques($nationalCode),
            GuaranteeInquiry::KIND_CREDIT_RISK => $this->provider->creditRisk($nationalCode),
            GuaranteeInquiry::KIND_AGGREGATE => $this->provider->aggregate($nationalCode),
            GuaranteeInquiry::KIND_STATUS => $this->provider->status($sayadId),
            default => throw new \InvalidArgumentException('Unknown inquiry kind: '.$kind),
        };
    }

    /**
     * Derive the guarantee state from its inquiry rows.
     *
     * With required_inquiries empty (the default) this NEVER promotes to
     * Verified -- it leaves the guarantee at Inquiring for an admin, and says
     * so in the audit trail. Inventing a rule here would be inventing lending
     * policy.
     */
    public function promote(Guarantee $guarantee): Guarantee
    {
        $required = (array) $this->config()->get('required_inquiries', []);
        $thresholds = (array) $this->config()->get('risk_thresholds', []);

        $guarantee->load('inquiries');

        if ($guarantee->ownership_match === false) {
            $guarantee->update(['state' => GuaranteeState::OwnershipMismatch]);

            AuditLogger::log(
                action: 'guarantee.ownership_mismatch',
                resourceType: 'Guarantee',
                resourceId: $guarantee->id,
                result: AuditLogger::RESULT_DENIED,
            );

            return $guarantee->refresh();
        }

        if ($thresholds !== [] && $guarantee->risk_score !== null) {
            $max = $thresholds['max_risk_score'] ?? null;

            if ($max !== null && $guarantee->risk_score > (int) $max) {
                $guarantee->update(['state' => GuaranteeState::RiskRejected]);

                return $guarantee->refresh();
            }
        }

        if ($required === []) {
            AuditLogger::log(
                action: 'guarantee.policy_undefined',
                resourceType: 'Guarantee',
                resourceId: $guarantee->id,
                result: AuditLogger::RESULT_DENIED,
                context: ['note' => 'verification.guarantee.required_inquiries is empty; awaiting a business decision'],
            );

            return $guarantee->refresh();
        }

        $passed = $guarantee->inquiries
            ->where('result', ChequeResult::PASS)
            ->pluck('kind')
            ->unique()
            ->all();

        if (array_diff($required, $passed) === []) {
            $guarantee->update(['state' => GuaranteeState::Verified, 'verified_at' => now()]);

            AuditLogger::log(
                action: 'guarantee.verified',
                resourceType: 'Guarantee',
                resourceId: $guarantee->id,
                context: ['inquiries' => $passed],
            );
        }

        return $guarantee->refresh();
    }

    public function verifyManually(Guarantee $guarantee, User $admin, ?string $note = null): Guarantee
    {
        $guarantee->update(['state' => GuaranteeState::Verified, 'verified_at' => now()]);

        AuditLogger::log(
            action: 'guarantee.manual_verify',
            resourceType: 'Guarantee',
            resourceId: $guarantee->id,
            context: ['note' => $note, 'bypassed_automated_policy' => true],
            actor: $admin,
        );

        return $guarantee->refresh();
    }

    public function reject(Guarantee $guarantee, string $reason, ?User $admin = null): Guarantee
    {
        $guarantee->update([
            'state' => GuaranteeState::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        AuditLogger::log(
            action: 'guarantee.rejected',
            resourceType: 'Guarantee',
            resourceId: $guarantee->id,
            result: AuditLogger::RESULT_DENIED,
            context: ['reason' => $reason],
            actor: $admin,
        );

        return $guarantee->refresh();
    }

    /**
     * TODO(business): when a guarantee is released (on clean return? after a
     * damage window? partially?) is undecided. This records the release an
     * admin performs; it does not decide when one is due.
     */
    public function release(Guarantee $guarantee, User $admin, ?string $note = null): Guarantee
    {
        $guarantee->update(['state' => GuaranteeState::Released, 'released_at' => now()]);

        AuditLogger::log(
            action: 'guarantee.released',
            resourceType: 'Guarantee',
            resourceId: $guarantee->id,
            context: ['note' => $note],
            actor: $admin,
        );

        return $guarantee->refresh();
    }

    private function recentInquiry(Guarantee $guarantee, string $kind): ?GuaranteeInquiry
    {
        $ttlDays = $this->config()->cacheTtlDays();

        if ($ttlDays <= 0) {
            return null;
        }

        return GuaranteeInquiry::where('guarantee_id', $guarantee->id)
            ->where('kind', $kind)
            ->where('state', 'completed')
            ->where('created_at', '>=', now()->subDays($ttlDays))
            ->latest('id')
            ->first();
    }
}
