<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'user_id', 'status', 'payment_status',
        'subtotal', 'discount_total', 'shipping_cost', 'total',
        'coupon_id', 'shipping_address_id', 'shipping_method_id',
        'shipping_address_snapshot', 'customer_note', 'admin_note',
        'paid_at', 'shipped_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'user_id' => 'integer',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'shipping_cost' => 'integer',
            'total' => 'integer',
            'shipping_address_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function latestTransaction()
    {
        return $this->hasOne(PaymentTransaction::class)->latestOfMany();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function getReceiverNameAttribute(): ?string
    {
        return $this->shipping_address_snapshot['receiver_name']
            ?? $this->shippingAddress?->receiver_name;
    }

    public function getReceiverMobileAttribute(): ?string
    {
        return $this->shipping_address_snapshot['receiver_mobile']
            ?? $this->shippingAddress?->receiver_mobile;
    }

    public function getReceiverAddressTextAttribute(): string
    {
        $snapshot = $this->shipping_address_snapshot;

        if (is_array($snapshot) && !empty($snapshot['full_address'])) {
            return (string) $snapshot['full_address'];
        }

        if (is_array($snapshot)) {
            $parts = array_filter([
                $snapshot['province'] ?? null,
                $snapshot['city'] ?? null,
                $snapshot['district'] ?? null,
                $snapshot['address_line'] ?? null,
                !empty($snapshot['plaque']) ? 'پلاک ' . $snapshot['plaque'] : null,
                !empty($snapshot['unit']) ? 'واحد ' . $snapshot['unit'] : null,
            ]);

            if ($parts) {
                return implode('، ', $parts);
            }
        }

        if ($this->shippingAddress) {
            return $this->shippingAddress->full_address;
        }

        return 'آدرس گیرنده برای این سفارش ثبت نشده است.';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isDeliverable(): bool
    {
        return in_array($this->status, ['paid', 'processing', 'shipped']);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending_payment' => 'در انتظار پرداخت',
            'paid'            => 'پرداخت شده',
            'processing'      => 'در حال پردازش',
            'shipped'         => 'ارسال شده',
            'delivered'       => 'تحویل داده شده',
            'cancelled'       => 'لغو شده',
            'refunded'        => 'مسترد شده',
            'failed'          => 'ناموفق',
            default           => $this->status,
        };
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $random = str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $number = "RNT-{$date}-{$random}";
        } while (static::where('order_number', $number)->exists());

        return $number;
    }

    /**
     * BUG-015: generateOrderNumber()'s own exists()-check-then-insert is
     * a TOCTOU race -- two concurrent requests can both pass the check for
     * the same candidate before either inserts. The `order_number` column
     * already has a real DB unique constraint (the authoritative guard);
     * this wraps creation with a bounded retry that only fires on that
     * exact collision, generating a fresh candidate each time. Any other
     * SQL failure is rethrown immediately, unswallowed.
     */
    public static function createWithUniqueNumber(array $attributes, int $maxAttempts = 5): self
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return static::create(array_merge($attributes, [
                    'order_number' => static::generateOrderNumber(),
                ]));
            } catch (\Illuminate\Database\QueryException $e) {
                $isOrderNumberCollision = (int) ($e->errorInfo[1] ?? 0) === 1062
                    && str_contains($e->getMessage(), 'order_number');

                if (!$isOrderNumberCollision || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        // Unreachable: the loop always returns or throws.
        throw new \RuntimeException('Order number generation failed unexpectedly.');
    }
}
