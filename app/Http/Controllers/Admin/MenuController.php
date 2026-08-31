<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Category;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    private const LOCATIONS = [
        'header'          => 'هدر اصلی',
        'header_nav_links' => 'لینک‌های ناوبری هدر (کنار دسته‌بندی کالاها)',
        'footer'          => 'فوتر',
        'mobile'          => 'منوی موبایل',
        'category_menu'   => 'منوی دسته‌بندی‌ها',
        'footer_products' => 'لینک‌های محصولات فوتر',
        'footer_customer' => 'لینک‌های مشتریان فوتر',
    ];

    private const TYPES = [
        'custom_url' => 'لینک دلخواه',
        'route'      => 'مسیر سیستم',
        'category'   => 'دسته‌بندی',
        'product'    => 'محصول',
        'page'       => 'صفحه',
    ];

    public function index()
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);
        $items = MenuItem::with(['children' => fn ($q) => $q->orderBy('sort_order'), 'children.children' => fn ($q) => $q->orderBy('sort_order')])
            ->whereNull('parent_id')
            ->orderBy('location')->orderBy('sort_order')->get();
        $locations = self::LOCATIONS;
        return view('admin.menus.index', compact('items', 'locations'));
    }

    public function create()
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);
        $categories = Category::orderBy('name_fa')->get();
        $parents    = $this->possibleParents();
        $locations  = self::LOCATIONS;
        $types      = self::TYPES;
        return view('admin.menus.create', compact('categories', 'parents', 'locations', 'types'));
    }

    public function store(Request $request)
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'url'              => 'nullable|string|max:500',
            'route_name'       => 'nullable|string|max:255',
            'type'             => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'location'         => 'required|in:' . implode(',', array_keys(self::LOCATIONS)),
            'icon'             => 'nullable|string|max:100',
            'parent_id'        => 'nullable|exists:menu_items,id',
            'category_id'      => 'nullable|exists:categories,id',
            'sort_order'       => 'required|integer|min:0',
            'is_active'        => 'boolean',
            'opens_in_new_tab' => 'boolean',
        ]);

        $data['is_active']        = $request->boolean('is_active');
        $data['opens_in_new_tab'] = $request->boolean('opens_in_new_tab');

        $menu = MenuItem::create($data);

        ActivityLogService::log('menu.create', $menu, "ایجاد آیتم منو «{$menu->title}»", [
            'location' => $menu->location, 'parent_id' => $menu->parent_id,
        ]);

        return redirect()->route('admin.menus.index')->with('success', 'آیتم منو ایجاد شد.');
    }

    public function edit(MenuItem $menu)
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);
        $categories = Category::orderBy('name_fa')->get();
        $parents    = $this->possibleParents($menu->id);
        $locations  = self::LOCATIONS;
        $types      = self::TYPES;
        return view('admin.menus.edit', compact('menu', 'categories', 'parents', 'locations', 'types'));
    }

    public function update(Request $request, MenuItem $menu)
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'url'              => 'nullable|string|max:500',
            'route_name'       => 'nullable|string|max:255',
            'type'             => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'location'         => 'required|in:' . implode(',', array_keys(self::LOCATIONS)),
            'icon'             => 'nullable|string|max:100',
            'parent_id'        => 'nullable|exists:menu_items,id',
            'category_id'      => 'nullable|exists:categories,id',
            'sort_order'       => 'required|integer|min:0',
            'is_active'        => 'boolean',
            'opens_in_new_tab' => 'boolean',
        ]);

        $data['is_active']        = $request->boolean('is_active');
        $data['opens_in_new_tab'] = $request->boolean('opens_in_new_tab');

        $menu->update($data);

        ActivityLogService::log('menu.update', $menu, "به‌روزرسانی آیتم منو «{$menu->title}»", [
            'changed_fields' => array_keys($menu->getChanges()),
        ]);

        return redirect()->route('admin.menus.index')->with('success', 'آیتم منو بروزرسانی شد.');
    }

    public function destroy(MenuItem $menu)
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);

        $title = $menu->title;
        $descendantCount = $this->countDescendants($menu);

        ActivityLogService::log('menu.delete', $menu, "حذف آیتم منو «{$title}»" . ($descendantCount ? " به‌همراه {$descendantCount} زیرمجموعه" : ''), [
            'descendant_count' => $descendantCount,
        ]);

        $this->deleteWithDescendants($menu);
        return redirect()->route('admin.menus.index')->with('success', 'آیتم منو حذف شد.');
    }

    private function countDescendants(MenuItem $menu): int
    {
        $count = 0;
        foreach ($menu->children as $child) {
            $count += 1 + $this->countDescendants($child);
        }
        return $count;
    }

    // Deletes an item and every descendant beneath it, at any depth -- a
    // plain $menu->children()->delete() only removes one level, which would
    // orphan grandchildren (e.g. leaf links under a category_menu group).
    private function deleteWithDescendants(MenuItem $menu): void
    {
        foreach ($menu->children as $child) {
            $this->deleteWithDescendants($child);
        }
        $menu->delete();
    }

    /**
     * Flat, ordered list of items eligible to be picked as a parent in the
     * form's "منوی والد" select -- both top-level items AND their direct
     * children, so a 3rd-level item (e.g. a link nested under a column
     * heading, itself nested under a top tab) can be created/edited. Caps
     * depth at what the header mega-menu actually renders (3 levels); a
     * child is never offered a spot under itself or its own children.
     */
    private function possibleParents(?int $excludeId = null)
    {
        $topLevel = MenuItem::with(['children' => fn ($q) => $q->orderBy('sort_order')])
            ->whereNull('parent_id')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('location')->orderBy('sort_order')
            ->get();

        $flat = collect();
        foreach ($topLevel as $item) {
            $flat->push(['item' => $item, 'depth' => 0]);
            foreach ($item->children as $child) {
                if ($excludeId && $child->id === $excludeId) {
                    continue;
                }
                $flat->push(['item' => $child, 'depth' => 1]);
            }
        }

        return $flat;
    }

    public function toggle(MenuItem $menu)
    {
        abort_if(!auth()->user()->can('manage_menus'), 403);
        $menu->update(['is_active' => !$menu->is_active]);

        ActivityLogService::log('menu.toggle_status', $menu, "تغییر وضعیت آیتم منو «{$menu->title}»", [
            'is_active' => $menu->is_active,
        ]);

        return back()->with('success', 'وضعیت منو تغییر کرد.');
    }
}
