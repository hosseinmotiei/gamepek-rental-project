<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuaranteeInquiry extends Model
{
    public const KIND_SAYAD_VALIDATE = 'sayad_validate';

    public const KIND_CHEQUE_INQUIRY = 'cheque_inquiry';

    public const KIND_OWNERSHIP_MATCH = 'ownership_match';

    public const KIND_BOUNCED_CHEQUE = 'bounced_cheque';

    public const KIND_CREDIT_RISK = 'credit_risk';

    public const KIND_AGGREGATE = 'aggregate';

    public const KIND_STATUS = 'status';

    /** CHEQUE-01..07, in order. */
    public const ALL_KINDS = [
        self::KIND_SAYAD_VALIDATE,
        self::KIND_CHEQUE_INQUIRY,
        self::KIND_OWNERSHIP_MATCH,
        self::KIND_BOUNCED_CHEQUE,
        self::KIND_CREDIT_RISK,
        self::KIND_AGGREGATE,
        self::KIND_STATUS,
    ];

    protected $fillable = [
        'guarantee_id', 'kind', 'provider', 'state', 'result', 'score',
        'provider_status_code', 'request_id', 'correlation_id',
        'fields', 'raw_request', 'raw_response', 'retention_until', 'duration_ms',
    ];

    protected $hidden = ['raw_request', 'raw_response'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'retention_until' => 'date',
            'score' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }
}
