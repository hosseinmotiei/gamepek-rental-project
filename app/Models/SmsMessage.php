<?php

namespace App\Models;

use App\Enums\SmsState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $fillable = [
        'user_id', 'mobile', 'template_key', 'params', 'body',
        'provider', 'provider_message_id', 'state', 'attempts', 'last_error',
        'queued_at', 'sent_at', 'delivered_at', 'failed_at', 'delivery_checked_at',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'delivery_checked_at' => 'datetime',
            'attempts' => 'integer',
            'state' => SmsState::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
