<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogService;
use App\Services\RentalPricingService;
use App\Support\Rental\RentalCalendar;
use App\Support\Rental\RentalItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private CatalogService $catalogService)
    {
    }

    public function index(Request $request): View
    {
        $categories = Category::active()->root()->orderBy('sort_order')->get();

        $filters = [];

        if ($categorySlug = $request->get('category')) {
            // Look up across ALL active categories, not just $categories
            // (which is root-only, for the sidebar filter tree) -- a slug
            // that belongs to a subcategory would otherwise silently fail
            // to match here, the filter would never get applied, and the
            // page would fall back to showing every product regardless of
            // category.
            $category = Category::active()->where('slug', $categorySlug)->first();
            if ($category) {
                $filters['category_id'] = $category->id;
            }
        }
        // Item-specific facets are declared as data, never hardcoded here --
        // see CatalogService::availableFacets(). Only declared keys are read
        // off the query string, so the filter set can grow for rental items
        // without touching this controller.
        $facets = $this->catalogService->availableFacets($category ?? null);
        $selectedFacets = [];
        foreach (array_keys($facets) as $facetKey) {
            $value = $request->get($facetKey);
            if ($value !== null && $value !== '') {
                $selectedFacets[$facetKey] = $value;
            }
        }
        $filters['attributes'] = $selectedFacets;

        // CatalogService::applySorting() already falls back to 'newest' for any
        // unrecognized sort key, so no extra whitelist is needed here.
        $sort = (string) $request->get('sort', 'newest');

        $products = $this->catalogService->getFilteredProducts($filters, $sort, 24);

        $sidebarBanners = Banner::active()->forPosition('sidebar')->orderBy('sort_order')->get();

        return view('products.index', compact('products', 'categories', 'sidebarBanners', 'facets', 'selectedFacets'));
    }

    public function show(string $slug, RentalPricingService $pricing): View
    {
        $product = Product::where('slug', $slug)
            ->active()
            ->with(['category', 'optionGroups.values'])
            ->firstOrFail();

        $this->catalogService->incrementViews($product);

        $relatedProducts = Product::active()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit(6)
            ->get();

        // Rentable products carry their rental facts in `attributes._rental`
        // and get an extra booking panel. Everything else is an ordinary shop
        // product and renders exactly as before, so `$rental` stays null and
        // the view skips those sections entirely.
        $rental = RentalItem::for($product);
        $rentalCalendar = null;
        $rentalSuggestion = null;
        $rentalQuote = null;

        if ($rental) {
            $blocked = $rental->blocked();
            $rentalCalendar = RentalCalendar::build($blocked);

            // The default duration the panel opens on. Kept in sync with the
            // first duration pill in partials/rental-panel.blade.php.
            $defaultDays = 3;

            $rentalSuggestion = RentalCalendar::suggestion($defaultDays, $blocked);

            // Server-computed opening quote. The panel recomputes live in JS
            // as the customer changes options, but the figure first painted
            // -- and the only one that is authoritative -- comes from here.
            $rentalQuote = $pricing->quote(
                dailyRate: $rental->dailyRate(),
                days: $defaultDays,
                extraControllerDaily: $rental->extraControllerDaily(),
                withExtraController: false,
                gameFee: 0,
                deliveryFee: $rental->deliveryFee(),
                deposit: $rental->deposit(),
            );
        }

        return view('products.show', compact(
            'product',
            'relatedProducts',
            'rental',
            'rentalCalendar',
            'rentalSuggestion',
            'rentalQuote',
        ));
    }

    public function searchPage(Request $request): View
    {
        $q = trim($request->get('q', ''));
        $products = collect();

        if (strlen($q) >= 2) {
            $filters = ['q' => $q];

            $categoryId = (int) $request->get('category');
            if ($categoryId > 0) {
                $filters['category_id'] = $categoryId;
            }

            $minPrice = $request->get('min_price');
            if (is_numeric($minPrice) && (int) $minPrice >= 0) {
                $filters['min_price'] = (int) $minPrice;
            }

            $maxPrice = $request->get('max_price');
            if (is_numeric($maxPrice) && (int) $maxPrice >= 0) {
                $filters['max_price'] = (int) $maxPrice;
            }

            // CatalogService::applySorting() already falls back to 'newest'
            // for any unrecognized sort key, so no extra whitelist is needed
            // here.
            $sort = (string) $request->get('sort', 'newest');

            $products = $this->catalogService->getFilteredProducts($filters, $sort);
        }

        return view('search.index', compact('products', 'q'));
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 1) {
            return response()->json(['results' => []]);
        }

        // Categories
        $categories = Category::active()
            ->where(function ($query) use ($q) {
                $query->where('name_fa', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%");
            })
            ->withCount(['products' => fn ($query) => $query->active()])
            ->orderByDesc('products_count')
            ->orderBy('sort_order')
            ->limit(4)
            ->get()
            ->map(fn (Category $c) => [
                'type'      => 'category',
                'id'        => $c->id,
                'title'     => $c->name_fa,
                'subtitle'  => persian_number($c->products_count) . ' محصول',
                'url'       => route('products.index', ['category' => $c->slug]),
                'icon'      => $c->icon ?: 'fa-solid fa-border-all',
                'image_url' => $c->image ? asset('storage/' . $c->image) : null,
                'price'     => null,
            ]);

        // Products
        $products = Product::active()
            ->where(function ($query) use ($q) {
                $query->where('title_fa', 'like', "%{$q}%")
                    ->orWhere('title_en', 'like', "%{$q}%")
                    ->orWhere('short_description', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%");
            })
            ->orderByDesc('views_count')
            ->limit(5)
            ->get()
            ->map(fn (Product $p) => [
                'type'      => 'product',
                'id'        => $p->id,
                'title'     => $p->title_fa,
                'subtitle'  => $p->title_en ?? '',
                'url'       => route('products.show', $p->slug),
                'icon'      => null,
                'image_url' => product_image_url($p),
                'price'     => $p->sale_price ?? $p->price,
                'price_fa'  => $p->price ? (persian_number($p->sale_price ?? $p->price) . ' تومان') : null,
            ]);

        $results = $categories->concat($products)->values();

        return response()->json(['results' => $results]);
    }

}
