<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id', 'user_id', 'gateway', 'amount', 'status',
        'authority', 'tracking_code', 'raw_request', 'raw_response', 'paid_at',
        'reversed_at', 'reverse_raw_request', 'reverse_raw_response',
    ];

    protected $hidden = [
        'raw_request', 'raw_response', 'reverse_raw_request', 'reverse_raw_response',
    ];

    protected function casts(): array
    {
        return [
            'raw_request' => 'array',
            'raw_response' => 'array',
            'reverse_raw_request' => 'array',
            'reverse_raw_response' => 'array',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
            'amount' => 'integer',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
