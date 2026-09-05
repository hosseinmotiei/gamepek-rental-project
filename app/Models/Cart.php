<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $fillable = ['user_id', 'session_id', 'coupon_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->with('product');
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function getItemsCountAttribute(): int
    {
        return $this->items->filter(fn ($item) => $item->product !== null)->sum('quantity');
    }

    public function getSubtotalAttribute(): int
    {
        return $this->items->sum(function ($item) {
            $price = $item->product?->effective_price ?? 0;

            return (int) $price * $item->quantity;
        });
    }

    /**
     * Whether this cart needs a delivery address at checkout.
     *
     * Everything in the rental catalog is a physical device, so today this is
     * simply "does the cart hold anything". It is kept as a named method
     * rather than inlined because the rental flow will later distinguish
     * delivery from in-person pickup, and that decision belongs here.
     */
    public function hasPhysicalItems(): bool
    {
        return $this->items->contains(fn ($item) => $item->product !== null);
    }
}
