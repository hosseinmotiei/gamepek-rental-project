<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    protected $fillable = [
        'key', 'title', 'subtitle', 'description', 'cta_text', 'cta_link',
        'image', 'background_color', 'sort_order', 'is_active',
        'item_limit', 'selection_mode', 'category_id', 'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'item_limit' => 'integer',
            'sort_order' => 'integer',
            'config'     => 'array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Resolves the actual products this section should show on the
     * homepage, from its own category_id/selection_mode/item_limit --
     * this is what makes a section's product list admin-editable instead
     * of hardcoded in a controller. "manual" mode has no product-picker UI
     * built yet, so it falls back to "latest" within the category.
     */
    public function resolveProducts(): \Illuminate\Support\Collection
    {
        $query = Product::active()->with('category');

        if ($this->category_id) {
            $query->where('category_id', $this->category_id);
        }

        match ($this->selection_mode) {
            'best_seller' => $query->orderBy('sales_count', 'desc'),
            'featured'    => $query->where('is_featured', true)->latest(),
            default       => $query->latest(),
        };

        return $query->limit($this->item_limit ?: 8)->get();
    }
}
