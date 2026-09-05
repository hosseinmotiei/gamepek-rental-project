<?php

namespace App\Services\Guarantee\Contracts;

use App\Services\Guarantee\Dto\ChequeResult;

/**
 * CHEQUE-01..07.
 *
 * The interface and the state model exist; the WORKFLOW does not, because the
 * business requirement does not. Which of these inquiries is mandatory before
 * a guarantee counts as verified, what the guaranteed amount should be, and
 * what risk score rejects an applicant are all TODO(business) -- see
 * config('verification.guarantee.*'), which defaults to empty so nothing
 * auto-approves.
 *
 * No implementation here talks to a real service.
 */
interface ChequeProviderInterface
{
    /** CHEQUE-01 — is this a structurally valid Sayad id? */
    public function validateSayadId(string $sayadId): ChequeResult;

    /** CHEQUE-02 — the cheque's registered details. */
    public function inquire(string $sayadId): ChequeResult;

    /** CHEQUE-03 — does the cheque belong to this national code? */
    public function matchOwnership(string $sayadId, string $nationalCode): ChequeResult;

    /** CHEQUE-04 — returned/bounced cheque history. */
    public function bouncedCheques(string $nationalCode): ChequeResult;

    /** CHEQUE-05 — credit / risk score. */
    public function creditRisk(string $nationalCode): ChequeResult;

    /** CHEQUE-06 — aggregated view across the above. */
    public function aggregate(string $nationalCode): ChequeResult;

    /** CHEQUE-07 — current status of one cheque. */
    public function status(string $sayadId): ChequeResult;
}
