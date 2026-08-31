<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Generic catalog item — the foundation a rental item will be built on.
 *
 * Availability is deliberately expressed through the single `isInStock()`
 * seam rather than being read as a raw column across the app. Rental
 * availability is an interval-overlap question ("is this device free between
 * these two dates?") rather than a scalar count, so replacing the body of
 * that one method is what the rental phase will need to do.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'title_fa', 'title_en', 'slug', 'sku',
        'short_description', 'description', 'brand', 'model', 'color',
        'price', 'sale_price', 'stock_quantity', 'stock_status',
        'is_active', 'is_featured', 'is_best_seller', 'is_flash_sale',
        'main_image', 'gallery_images', 'attributes',
        'views_count', 'sales_count',
        'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'gallery_images' => 'array',
            'attributes' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_flash_sale' => 'boolean',
            'price' => 'integer',
            'sale_price' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class)->orderBy('sort_order');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // Alias: views use $product->images to get gallery
    public function getImagesAttribute(): array
    {
        return $this->gallery_images ?? [];
    }

    // Alias: views use $product->stock for quantity
    public function getStockAttribute(): int
    {
        return $this->stock_quantity;
    }

    public function getEffectivePriceAttribute(): ?int
    {
        // BUG-090 (inherited): a zero/negative price must never be treated as
        // a valid charge — funnel it into the same null-price rejection path
        // callers (OrderService::createFromCart(), etc.) already use.
        $price = $this->sale_price ?? $this->price;

        return ($price !== null && $price > 0) ? $price : null;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (!$this->price || !$this->sale_price || $this->sale_price >= $this->price) {
            return 0;
        }
        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    /**
     * The single availability seam. Rental replaces the body of this method
     * with a date-range check; every caller (cart, checkout, catalog, detail
     * page) already goes through here, so no call site has to change.
     */
    public function isInStock(): bool
    {
        if ($this->stock_status !== 'in_stock') {
            return false;
        }

        return $this->stock_quantity > 0;
    }

    public function cartPrice(): int
    {
        return $this->sale_price ?? $this->price ?? 0;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true));
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)->active();
    }

    public function scopeBestSeller($query)
    {
        return $query->where('is_best_seller', true)->active();
    }

    public function scopeFlashSale($query)
    {
        return $query->where('is_flash_sale', true)->whereNotNull('sale_price')->active();
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_status', 'in_stock');
    }

    public function scopeSearch($query, string $term)
    {
        // Escape LIKE metacharacters (backslash first, then % and _) so the user's
        // literal input can't be interpreted as SQL wildcards by the LIKE engine.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = "%{$escaped}%";

        return $query->where(function ($q) use ($pattern) {
            $q->where('title_fa', 'LIKE', $pattern)
              ->orWhere('title_en', 'LIKE', $pattern)
              ->orWhere('slug', 'LIKE', $pattern)
              ->orWhere('sku', 'LIKE', $pattern)
              ->orWhereHas('category', function ($categoryQuery) use ($pattern) {
                  $categoryQuery->where('name_fa', 'LIKE', $pattern)
                                ->orWhere('slug', 'LIKE', $pattern);
              });
        });
    }
}
