<?php

namespace App\Services;

use App\Models\Category;
use App\Models\HomeSection;
use App\Models\Product;
use App\Services\Rental\RentalAvailabilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catalog querying for the public listing, search and homepage.
 *
 * Replaces the Store's ProductService. The Store version hardcoded
 * PlayStation facets (region, capacity, gift-card value, GTA flags) as real
 * columns and real query branches, which is exactly the kind of Store
 * assumption this foundation must not inherit.
 *
 * Here, variable per-item facets live in the `attributes` JSON column and are
 * declared as data — globally in config('rental.catalog.facets') and
 * per-category in `categories.filters` — so rental items can gain their own
 * fields (device condition, generation, controller count, …) without a schema
 * change or an edit to this class.
 */
class CatalogService
{
    public function getFilteredProducts(array $filters, string $sort = 'newest', int $perPage = 20): LengthAwarePaginator
    {
        $query = Product::with('category')->active();

        if (! empty($filters['q'])) {
            $query->search($filters['q']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['stock'])) {
            $query->where('stock_status', $filters['stock']);
        } elseif (! empty($filters['in_stock'])) {
            $query->inStock();
        }

        if (! empty($filters['min_price'])) {
            $query->where('price', '>=', (int) $filters['min_price']);
        }
        if (! empty($filters['max_price'])) {
            $query->where('price', '<=', (int) $filters['max_price']);
        }

        if (! empty($filters['color'])) {
            $query->where('color', $filters['color']);
        }
        if (! empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }

        if (! empty($filters['featured'])) {
            $query->where('is_featured', true);
        }
        if (! empty($filters['best_seller'])) {
            $query->where('is_best_seller', true);
        }
        if (! empty($filters['flash_sale'])) {
            $query->flashSale();
        }

        // Rental date window. Two independent conditions, both real:
        //   1. the item must actually be rentable (it carries a `_rental`
        //      blob -- Support\Rental\RentalItem::supports() reads the same
        //      key), so a buy-only product never appears in a date search;
        //   2. no blocking reservation may overlap the requested range.
        // Availability outside reservations (maintenance, owner holds) is
        // still an undecided domain question -- see Product::isInStock().
        if (! empty($filters['rental_from']) && ! empty($filters['rental_to'])) {
            $from = $filters['rental_from'];
            $to = $filters['rental_to'];

            $query->whereNotNull('attributes->_rental');

            // Routed through the single availability authority so the listing
            // and the product page's calendar can never disagree -- they used
            // to, because the calendar read a static JSON blob instead.
            app(RentalAvailabilityService::class)->constrainProductQuery($query, $from, $to);
        }

        $this->applyAttributeFacets($query, $filters['attributes'] ?? []);

        return $this->applySorting($query, $sort)->paginate($perPage)->withQueryString();
    }

    /**
     * Filter on the `attributes` JSON column, one declared facet at a time.
     *
     * Only keys declared in availableFacets() are ever queried, so a crafted
     * query string cannot probe arbitrary JSON paths.
     */
    private function applyAttributeFacets(Builder $query, array $selected): void
    {
        if (empty($selected)) {
            return;
        }

        $allowed = array_keys($this->availableFacets());

        foreach ($selected as $key => $value) {
            if ($value === null || $value === '' || ! in_array($key, $allowed, true)) {
                continue;
            }

            $query->whereJsonContains("attributes->{$key}", (string) $value);
        }
    }

    /**
     * The facet list the listing page renders its filter sidebar from.
     *
     * Shape: ['key' => ['label' => 'نمایشی', 'options' => ['value' => 'برچسب']]]
     *
     * A category may narrow or extend the global set through its own
     * `filters` JSON column, which is editable from the admin panel — so
     * adding a rental facet later is a data change, not a deploy.
     */
    public function availableFacets(?Category $category = null): array
    {
        $facets = (array) config('rental.catalog.facets', []);

        if ($category && is_array($category->filters)) {
            foreach ($category->filters as $key => $definition) {
                if (is_array($definition)) {
                    $facets[$key] = $definition;
                }
            }
        }

        return $facets;
    }

    private function applySorting(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'cheapest' => $query->orderByRaw('COALESCE(sale_price, price) ASC'),
            'expensive' => $query->orderByRaw('COALESCE(sale_price, price) DESC'),
            'best_selling' => $query->orderBy('sales_count', 'desc'),
            'most_viewed' => $query->orderBy('views_count', 'desc'),
            'discount' => $query->orderByRaw('(price - COALESCE(sale_price, price)) DESC'),
            default => $query->orderBy('created_at', 'desc'), // newest
        };
    }

    public function incrementViews(Product $product): void
    {
        $product->increment('views_count');
    }

    /**
     * Homepage collections. Every entry is a generic, admin-controlled
     * concept; the Store's GTA-campaign and PSN gift-card collections are
     * deliberately absent. Admin-defined category sections are resolved by
     * HomeController from the `home_sections` table, not hardcoded here.
     */
    public function getHomePageData(): array
    {
        return [
            'flash_sale' => Product::flashSale()->with('category')->limit(8)->get(),
            'best_sellers' => Product::bestSeller()->with('category')->limit(8)->get(),
            'featured' => Product::featured()->with('category')->limit(8)->get(),

            // Rentable devices for the homepage's suggested row. Featured is
            // an editorial flag an admin may never have set, so this asks the
            // real question instead: does the item carry a `_rental` blob?
            // Same key Support\Rental\RentalItem::supports() reads.
            'rentable' => Product::active()
                ->whereNotNull('attributes->_rental')
                ->with('category')
                ->latest('id')
                ->limit(8)
                ->get(),
        ];
    }

    /**
     * Products for one admin-configured homepage section.
     */
    public function resolveSection(string $key)
    {
        return HomeSection::where('key', $key)->first()?->resolveProducts() ?? collect();
    }
}
