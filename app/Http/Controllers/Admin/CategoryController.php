<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\ActivityLogService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function __construct(private ImageUploadService $imageUploads) {}

    public function index(Request $request)
    {
        abort_if(!auth()->user()->can('view_categories'), 403);

        $query = Category::with(['parent', 'children'])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name_fa');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name_fa', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $categories = $query->paginate(30)->withQueryString();

        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        abort_if(!auth()->user()->can('create_categories'), 403);

        $parents = Category::whereNull('parent_id')->orderBy('sort_order')->get();
        return view('admin.categories.create', compact('parents'));
    }

    public function store(StoreCategoryRequest $request)
    {
        $data = collect($request->validated())->except(['image'])->toArray();

        if ($request->hasFile('image')) {
            try {
                $data['image'] = $this->imageUploads->storeAndVerify($request->file('image'), 'categories');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
        }

        $category = Category::create($data);

        ActivityLogService::log('category.create', $category, "ایجاد دسته‌بندی «{$category->name_fa}»");

        return redirect()->route('admin.categories.index')
            ->with('success', 'دسته‌بندی «' . $category->name_fa . '» با موفقیت ایجاد شد.');
    }

    public function edit(Category $category)
    {
        abort_if(!auth()->user()->can('edit_categories'), 403);

        $parents = Category::whereNull('parent_id')
            ->where('id', '!=', $category->id)
            ->orderBy('sort_order')
            ->get();

        return view('admin.categories.edit', compact('category', 'parents'));
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $data = collect($request->validated())->except(['image', 'remove_image'])->toArray();

        if ($request->hasFile('image')) {
            try {
                $newPath = $this->imageUploads->storeAndVerify($request->file('image'), 'categories');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
            if ($category->image) Storage::disk('public')->delete($category->image);
            $data['image'] = $newPath;
        } elseif ($request->boolean('remove_image') && $category->image) {
            Storage::disk('public')->delete($category->image);
            $data['image'] = null;
        }

        $category->update($data);

        ActivityLogService::log('category.update', $category, "به‌روزرسانی دسته‌بندی «{$category->name_fa}»", [
            'changed_fields' => array_keys($category->getChanges()),
        ]);

        return redirect()->route('admin.categories.index')
            ->with('success', 'دسته‌بندی «' . $category->name_fa . '» به‌روزرسانی شد.');
    }

    public function destroy(Category $category)
    {
        abort_if(!auth()->user()->can('delete_categories'), 403);

        if ($category->products()->withTrashed()->count() > 0) {
            return back()->with('error', 'این دسته‌بندی دارای محصول است و حذف نمی‌شود. ابتدا محصولات را جابجا کنید.');
        }

        if ($category->children()->count() > 0) {
            return back()->with('error', 'این دسته‌بندی دارای زیرمجموعه است و حذف نمی‌شود.');
        }

        ActivityLogService::log('category.delete', $category, "حذف دسته‌بندی «{$category->name_fa}»");

        if ($category->image) Storage::disk('public')->delete($category->image);

        $category->delete();

        return redirect()->route('admin.categories.index')
            ->with('success', 'دسته‌بندی «' . $category->name_fa . '» حذف شد.');
    }

    public function toggle(Category $category)
    {
        abort_if(!auth()->user()->can('edit_categories'), 403);

        $category->update(['is_active' => !$category->is_active]);
        $label = $category->is_active ? 'فعال' : 'غیرفعال';

        ActivityLogService::log('category.toggle_status', $category, "{$label}‌سازی دسته‌بندی «{$category->name_fa}»", [
            'is_active' => $category->is_active,
        ]);

        return back()->with('success', 'دسته‌بندی «' . $category->name_fa . '» ' . $label . ' شد.');
    }
}
