<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = [
        'title', 'url', 'route_name', 'type', 'location',
        'icon', 'parent_id', 'category_id', 'product_id',
        'sort_order', 'is_active', 'opens_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'is_active'        => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'sort_order'       => 'integer',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('sort_order');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeForLocation($query, string $location)
    {
        return $query->where('location', $location);
    }

    public function getResolvedUrlAttribute(): string
    {
        if ($this->type === 'route' && $this->route_name) {
            try {
                return route($this->route_name);
            } catch (\Exception) {
                return '#';
            }
        }
        if ($this->type === 'category' && $this->category_id) {
            try {
                $cat = $this->category;
                return $cat ? route('products.index', ['category' => $cat->slug]) : '#';
            } catch (\Exception) {
                return '#';
            }
        }
        return $this->url ?: '#';
    }
}
