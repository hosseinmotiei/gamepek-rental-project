<?php

namespace App\Services\Banking;

use App\Enums\BankAccountState;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Banking\Contracts\BankOwnershipProviderInterface;
use App\Services\Providers\ProviderCallLogger;
use App\Services\Providers\ProviderConfig;
use App\Services\Providers\ProviderException;
use App\Support\Banking\CardNumber;
use App\Support\Banking\Iban;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2: card / IBAN ownership.
 *
 * Replaces the frontend prototype's lookupHolderName(), which hashed the card
 * number into one of six hardcoded Persian names and displayed it as if a bank
 * had answered. Here the holder name is whatever the provider returned, and
 * the ownership decision is a persisted state -- not a string in a template.
 */
class BankAccountService
{
    public function __construct(private BankOwnershipProviderInterface $provider) {}

    private function config(): ProviderConfig
    {
        return ProviderConfig::for('bank_ownership');
    }

    /**
     * Register a card or IBAN. Local format validation only -- ownership is a
     * separate, paid step.
     */
    public function add(User $user, string $type, string $value): BankAccount
    {
        $type = in_array($type, [BankAccount::TYPE_CARD, BankAccount::TYPE_IBAN], true)
            ? $type
            : BankAccount::TYPE_CARD;

        $normalised = BankAccount::normalise($type, $value);

        if ($type === BankAccount::TYPE_CARD && ! CardNumber::isValid($normalised)) {
            throw new \InvalidArgumentException('شماره کارت وارد شده معتبر نیست.');
        }

        if ($type === BankAccount::TYPE_IBAN && ! Iban::isValid($normalised)) {
            throw new \InvalidArgumentException('شماره شبا وارد شده معتبر نیست.');
        }

        return DB::transaction(function () use ($user, $type, $normalised) {
            $hash = BankAccount::hashValue($normalised);

            $account = BankAccount::firstOrNew([
                'user_id' => $user->id,
                'value_hash' => $hash,
            ]);

            $account->type = $type;
            $account->setValue($normalised);

            if (! $account->exists) {
                $account->state = BankAccountState::Pending;
            }

            $account->save();

            AuditLogger::log(
                action: 'bank_account.added',
                resourceType: 'BankAccount',
                resourceId: $account->id,
                context: ['type' => $type, 'mask' => $account->value_mask],
            );

            return $account;
        });
    }

    /**
     * Ownership inquiry. Idempotent within the configured TTL: an account
     * already verified recently is returned untouched rather than re-queried.
     */
    public function verify(BankAccount $account): BankAccount
    {
        $ttlDays = $this->config()->cacheTtlDays();

        if (
            $account->state === BankAccountState::Verified
            && $ttlDays > 0
            && $account->verified_at
            && $account->verified_at->gt(now()->subDays($ttlDays))
        ) {
            return $account;
        }

        $identity = $account->user->identity;

        if (! $identity || ! $identity->nationalCode()) {
            throw new \RuntimeException('برای تأیید مالکیت حساب، ابتدا باید کد ملی خود را ثبت و تأیید کنید.');
        }

        $account->update(['state' => BankAccountState::Inquiring, 'provider' => $this->config()->driver()]);

        $logger = new ProviderCallLogger($this->config());

        try {
            ['result' => $result] = $logger->around(
                operation: 'ownership_'.$account->type,
                subjectKey: (string) $account->id,
                call: fn () => $account->type === BankAccount::TYPE_CARD
                    ? $this->provider->verifyCardOwnership((string) $account->value(), (string) $identity->nationalCode())
                    : $this->provider->verifyIbanOwnership((string) $account->value(), (string) $identity->nationalCode()),
                auditContext: ['bank_account_id' => $account->id, 'type' => $account->type],
            );
        } catch (ProviderException $e) {
            $account->update([
                'state' => BankAccountState::Failed,
                'failure_reason' => $e->persianMessage,
            ]);

            throw $e;
        }

        if (! $result->conclusive) {
            $account->update([
                'state' => BankAccountState::Failed,
                'failure_reason' => 'استعلام مالکیت حساب در حال حاضر ممکن نیست. لطفاً بعداً دوباره تلاش کنید.',
            ]);

            AuditLogger::log(
                action: 'bank_account.ownership_unknown',
                resourceType: 'BankAccount',
                resourceId: $account->id,
                result: AuditLogger::RESULT_FAILURE,
            );

            return $account->refresh();
        }

        if (! $result->matched) {
            $account->update([
                'state' => BankAccountState::Mismatch,
                'owner_name' => $result->ownerName,
                'failure_reason' => 'این حساب به نام شما نیست.',
                'provider_reference' => $result->reference,
            ]);

            AuditLogger::log(
                action: 'bank_account.ownership_mismatch',
                resourceType: 'BankAccount',
                resourceId: $account->id,
                result: AuditLogger::RESULT_DENIED,
            );

            return $account->refresh();
        }

        $account->update([
            'state' => BankAccountState::Verified,
            'owner_name' => $result->ownerName,
            'bank_name' => $result->bankName,
            'linked_iban_mask' => $result->iban ? Iban::mask($result->iban) : null,
            'provider_reference' => $result->reference,
            'verified_at' => now(),
            'failure_reason' => null,
        ]);

        AuditLogger::log(
            action: 'bank_account.ownership_verified',
            resourceType: 'BankAccount',
            resourceId: $account->id,
            context: ['type' => $account->type, 'mask' => $account->value_mask],
        );

        return $account->refresh();
    }
}
