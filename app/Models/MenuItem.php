<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
            'is_active' => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'sort_order' => 'integer',
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

    /**
     * The category menu tree, read by the header mega-menu, the mobile
     * fullscreen menu and the homepage's categories section — one source, so
     * an edit in پنل ادمین > منوها updates all three at once.
     *
     * MenuSeeder leaves `category_menu` empty (its contents are catalog
     * taxonomy, a domain decision), so when no rows exist this falls back to
     * the real Category tree shaped to the same
     * title/icon/resolved_url/children contract. An empty menu is what made
     * the navigation look broken; there is no third code path for it.
     */
    public static function categoryTree(): Collection
    {
        $items = static::forLocation('category_menu')
            ->active()
            ->whereNull('parent_id')
            // `category` is eager-loaded at every level on purpose:
            // getResolvedUrlAttribute() reads it, and with
            // Model::shouldBeStrict() a lazy load there throws — which the
            // accessor's own catch turns into a silent '#', i.e. a dead link.
            ->with([
                'category',
                'children' => fn ($q) => $q->active()->with('category'),
                'children.children' => fn ($q) => $q->active()->with('category'),
            ])
            ->orderBy('sort_order')
            ->get();

        if ($items->isNotEmpty()) {
            return $items;
        }

        $toNode = function (Category $category) use (&$toNode) {
            return (object) [
                'id' => 'cat-'.$category->id,
                'title' => $category->name_fa,
                'icon' => $category->icon ?: 'fa-solid fa-tag',
                'resolved_url' => route('products.index', ['category' => $category->slug]),
                'opens_in_new_tab' => false,
                'children' => $category->relationLoaded('children')
                    ? $category->children->map($toNode)
                    : collect(),
            ];
        };

        return Category::menuVisible()
            ->root()
            ->with(['children' => fn ($q) => $q->menuVisible()->with([
                'children' => fn ($q2) => $q2->menuVisible(),
            ])])
            ->orderBy('sort_order')
            ->get()
            ->map($toNode);
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
