<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\MenuItem;
use App\Models\QuickCategory;
use App\Services\CatalogService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(private CatalogService $catalogService) {}

    public function index(): View
    {
        $banners = Banner::active()->forPosition('hero')->orderBy('sort_order')->get();
        $secondaryBanners = Banner::active()->forPosition('secondary')->orderBy('sort_order')->get();
        $categories = Category::menuVisible()->root()->with('children')->orderBy('sort_order')->get();
        $homeData = $this->catalogService->getHomePageData();
        $quickCategories = QuickCategory::active()->limit(10)->get();

        // Same tree the header menu renders (MenuItem::categoryTree), so the
        // homepage section and the navigation can never disagree.
        $categoryMenu = MenuItem::categoryTree();

        // Admin-configured Home Sections visibility (defaults to visible when a
        // key has no matching row, so an unseeded install renders unaffected).
        $homeSections = HomeSection::pluck('is_active', 'key');

        // Sections an admin added from پنل ادمین > بخش‌های صفحه اصلی — always
        // category-driven and rendered generically. This is the extension
        // point for rental-specific homepage content: a new section is added
        // as data, not as new markup.
        $customProductSections = HomeSection::where('key', 'like', 'custom_%')
            ->active()
            ->with('category')
            ->orderBy('sort_order')
            ->get();

        return view('home', compact(
            'banners',
            'secondaryBanners',
            'categories',
            'homeData',
            'quickCategories',
            'homeSections',
            'customProductSections',
            'categoryMenu'
        ));
    }
}
