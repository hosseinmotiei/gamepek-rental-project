<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'min_order_amount', 'max_discount',
        'usage_limit', 'per_user_limit', 'used_count',
        'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'value' => 'integer',
            'min_order_amount' => 'integer',
            'max_discount' => 'integer',
        ];
    }

    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(int $subtotal): int
    {
        if ($this->min_order_amount && $subtotal < $this->min_order_amount) {
            return 0;
        }

        $discount = $this->type === 'percentage'
            ? (int) round($subtotal * $this->value / 100)
            : $this->value;

        if ($this->max_discount) {
            $discount = min($discount, $this->max_discount);
        }

        return min($discount, $subtotal);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
