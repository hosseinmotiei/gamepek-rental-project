<?php

namespace App\Services\Contract;

use App\Enums\ContractState;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\ContractTemplate;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Contract\Contracts\SignatureProviderInterface;
use App\Support\Rental\Jalali;
use Illuminate\Support\Facades\DB;

/**
 * CON-01..CON-07.
 *
 * The versioning contract, in one sentence: a contract snapshots the rendered
 * text, its template key and its template version at generation time, so no
 * later template edit can alter an agreement someone already accepted.
 */
class ContractService
{
    public function __construct(
        private TemplateRenderer $renderer,
        private SignatureProviderInterface $signatureProvider,
    ) {}

    /** CON-01 + CON-02. */
    public function generate(RentalApplication $application): Contract
    {
        $key = config('rental.contract.template_key', 'rental_agreement');
        $template = ContractTemplate::activeFor($key);

        if (! $template) {
            throw new \RuntimeException('قالب قرارداد فعالی برای اجاره تعریف نشده است.');
        }

        return DB::transaction(function () use ($application, $template) {
            $existing = Contract::where('rental_application_id', $application->id)->lockForUpdate()->first();

            // Idempotent: regenerating an accepted or signed contract would
            // silently replace the text someone agreed to.
            if ($existing && $existing->state !== ContractState::Draft) {
                return $existing;
            }

            $variables = $this->variablesFor($application);
            $rendered = $this->renderer->render($template->body, $variables);

            $missing = $this->renderer->missing($template->body, $variables);
            if ($missing !== []) {
                throw new \RuntimeException(
                    'قالب قرارداد به متغیرهایی نیاز دارد که در دسترس نیست: '.implode('، ', $missing)
                );
            }

            $contract = Contract::updateOrCreate(
                ['rental_application_id' => $application->id],
                [
                    'contract_template_id' => $template->id,
                    'template_key' => $template->key,
                    'template_version' => $template->version,
                    'number' => $existing->number ?? Contract::generateNumber(),
                    'variables' => $variables,
                    'rendered_html' => $rendered,
                    'content_hash' => hash('sha256', $rendered),
                    'state' => ContractState::Generated,
                    'generated_at' => now(),
                ],
            );

            AuditLogger::log(
                action: 'contract.generated',
                resourceType: 'Contract',
                resourceId: $contract->id,
                context: [
                    'number' => $contract->number,
                    'template_key' => $contract->template_key,
                    'template_version' => $contract->template_version,
                    'content_hash' => $contract->content_hash,
                ],
            );

            return $contract;
        });
    }

    /** CON-03. */
    public function accept(Contract $contract, User $user, string $ip, string $userAgent): Contract
    {
        return DB::transaction(function () use ($contract, $user, $ip, $userAgent) {
            $locked = Contract::where('id', $contract->id)->lockForUpdate()->first();

            if ($locked->state === ContractState::Accepted || $locked->state === ContractState::Signed) {
                return $locked;
            }

            if (! $locked->state->canTransitionTo(ContractState::Accepted)) {
                throw new \RuntimeException('این قرارداد در وضعیت قابل پذیرش نیست.');
            }

            if (! $locked->isIntact()) {
                AuditLogger::log(
                    action: 'contract.tamper_detected',
                    resourceType: 'Contract',
                    resourceId: $locked->id,
                    result: AuditLogger::RESULT_DENIED,
                );

                throw new \RuntimeException('متن قرارداد معتبر نیست. لطفاً با پشتیبانی تماس بگیرید.');
            }

            $locked->update([
                'state' => ContractState::Accepted,
                'accepted_at' => now(),
                'accepted_ip' => $ip,
                'accepted_user_agent' => $userAgent,
            ]);

            AuditLogger::log(
                action: 'contract.accepted',
                resourceType: 'Contract',
                resourceId: $locked->id,
                context: ['number' => $locked->number, 'content_hash' => $locked->content_hash],
                actor: $user,
            );

            return $locked->refresh();
        });
    }

    /** CON-04. */
    public function sign(Contract $contract, User $signer, array $evidence): ContractSignature
    {
        return DB::transaction(function () use ($contract, $signer, $evidence) {
            $locked = Contract::where('id', $contract->id)->lockForUpdate()->first();

            $existing = ContractSignature::where('contract_id', $locked->id)
                ->where('user_id', $signer->id)
                ->first();

            // The unique(contract_id, user_id) index is the real backstop; this
            // turns the race loser into a clean idempotent return instead of a
            // constraint violation.
            if ($existing) {
                return $existing;
            }

            if ($locked->state !== ContractState::Accepted) {
                throw new \RuntimeException('پیش از امضا باید قرارداد را بپذیرید.');
            }

            if (! $locked->isIntact()) {
                throw new \RuntimeException('متن قرارداد معتبر نیست. لطفاً با پشتیبانی تماس بگیرید.');
            }

            $signature = $this->signatureProvider->sign($locked, $signer, $evidence);

            $locked->update(['state' => ContractState::Signed, 'signed_at' => now()]);

            AuditLogger::log(
                action: 'contract.signed',
                resourceType: 'Contract',
                resourceId: $locked->id,
                context: [
                    'number' => $locked->number,
                    'signature_id' => $signature->id,
                    'method' => $signature->method,
                    'not_pki' => true,
                ],
                actor: $signer,
            );

            return $signature;
        });
    }

    /** CON-05. */
    public function verifySignature(ContractSignature $signature): bool
    {
        $valid = $this->signatureProvider->verify($signature);

        if ($valid) {
            $signature->update(['verified_at' => now()]);
        }

        AuditLogger::log(
            action: 'contract.signature_verified',
            resourceType: 'ContractSignature',
            resourceId: $signature->id,
            result: $valid ? AuditLogger::RESULT_SUCCESS : AuditLogger::RESULT_FAILURE,
        );

        return $valid;
    }

    public function void(Contract $contract, User $admin, string $reason): Contract
    {
        if ($contract->state === ContractState::Signed) {
            throw new \RuntimeException('قرارداد امضاشده قابل ابطال نیست.');
        }

        $contract->update(['state' => ContractState::Void, 'voided_at' => now()]);

        AuditLogger::log(
            action: 'contract.voided',
            resourceType: 'Contract',
            resourceId: $contract->id,
            context: ['reason' => $reason],
            actor: $admin,
        );

        return $contract->refresh();
    }

    /**
     * The substitution set. Deliberately contains no raw national code, PAN or
     * IBAN -- a contract is a document people email around.
     */
    private function variablesFor(RentalApplication $application): array
    {
        $application->loadMissing(['user.identity', 'reservation.product']);

        $reservation = $application->reservation;
        $identity = $application->user->identity;

        return [
            'application_number' => $application->application_number,
            'customer_name' => $application->user->full_name ?? '',
            'customer_mobile' => $application->user->mobile ?? '',
            'customer_national_code_mask' => $identity?->national_code_mask ?? '',
            'product_title' => $reservation?->product_snapshot['title'] ?? ($reservation?->product?->title_fa ?? ''),
            'start_date' => $reservation ? Jalali::format($reservation->start_date->format('Y-m-d')) : '',
            'end_date' => $reservation ? Jalali::format($reservation->end_date->format('Y-m-d')) : '',
            'days' => (string) ($reservation?->days ?? ''),
            'rental_total' => number_format((int) ($reservation?->rental_total ?? 0)),
            'deposit_amount' => number_format((int) ($reservation?->deposit_amount ?? 0)),
            'payable_now' => number_format((int) ($reservation?->payable_now ?? 0)),
            'issued_at' => Jalali::format(now()->format('Y-m-d')),
        ];
    }
}
