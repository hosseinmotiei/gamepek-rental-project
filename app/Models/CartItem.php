<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'quantity', 'selected_options', 'options_hash', 'options_price_modifier'];

    protected function casts(): array
    {
        return [
            'selected_options' => 'array',
            'options_price_modifier' => 'integer',
        ];
    }

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Base product price + this line's snapshotted option price modifier
    // (never below 0, however negative the modifiers are).
    public function getEffectiveUnitPriceAttribute(): int
    {
        $base = $this->product?->effective_price ?? 0;

        return max(0, (int) $base + (int) ($this->options_price_modifier ?? 0));
    }

    public function getLineTotalAttribute(): int
    {
        return $this->effective_unit_price * $this->quantity;
    }
}
