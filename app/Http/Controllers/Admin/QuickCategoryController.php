<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadFailedException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\QuickCategory;
use App\Services\ActivityLogService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QuickCategoryController extends Controller
{
    public function __construct(private ImageUploadService $imageUploads) {}

    public function index()
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);
        $quickCategories = QuickCategory::orderBy('sort_order')->get();

        return view('admin.quick-categories.index', compact('quickCategories'));
    }

    public function create()
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);
        $categories = Category::orderBy('name_fa')->get();

        return view('admin.quick-categories.create', compact('categories'));
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:100',
            'link' => 'nullable|string|max:500',
            'category_id' => 'nullable|exists:categories,id',
            'color_class' => 'nullable|string|max:100',
            'bg_class' => 'nullable|string|max:100',
            'highlight' => 'boolean',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:2048',
        ]);

        $data['highlight'] = $request->boolean('highlight');
        $data['is_active'] = $request->boolean('is_active');
        $data['opens_in_new_tab'] = $request->boolean('opens_in_new_tab');

        if ($request->hasFile('image')) {
            try {
                $data['image'] = $this->imageUploads->storeAndVerify($request->file('image'), 'quick-categories');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
        }

        $quickCategory = QuickCategory::create($data);

        ActivityLogService::log('quick_category.create', $quickCategory, "ایجاد دسته‌بندی سریع «{$quickCategory->title}»");

        return redirect()->route('admin.quick-categories.index')->with('success', 'دسته‌بندی سریع ایجاد شد.');
    }

    public function edit(QuickCategory $quickCategory)
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);
        $categories = Category::orderBy('name_fa')->get();

        return view('admin.quick-categories.edit', compact('quickCategory', 'categories'));
    }

    public function update(Request $request, QuickCategory $quickCategory)
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:100',
            'link' => 'nullable|string|max:500',
            'category_id' => 'nullable|exists:categories,id',
            'color_class' => 'nullable|string|max:100',
            'bg_class' => 'nullable|string|max:100',
            'highlight' => 'boolean',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:2048',
        ]);

        $data['highlight'] = $request->boolean('highlight');
        $data['is_active'] = $request->boolean('is_active');
        $data['opens_in_new_tab'] = $request->boolean('opens_in_new_tab');

        if ($request->hasFile('image')) {
            try {
                $newPath = $this->imageUploads->storeAndVerify($request->file('image'), 'quick-categories');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
            if ($quickCategory->image) {
                Storage::disk('public')->delete($quickCategory->image);
            }
            $data['image'] = $newPath;
        }

        $quickCategory->update($data);

        ActivityLogService::log('quick_category.update', $quickCategory, "به‌روزرسانی دسته‌بندی سریع «{$quickCategory->title}»", [
            'changed_fields' => array_keys($quickCategory->getChanges()),
        ]);

        return redirect()->route('admin.quick-categories.index')->with('success', 'دسته‌بندی سریع بروزرسانی شد.');
    }

    public function destroy(QuickCategory $quickCategory)
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);

        ActivityLogService::log('quick_category.delete', $quickCategory, "حذف دسته‌بندی سریع «{$quickCategory->title}»");

        if ($quickCategory->image) {
            Storage::disk('public')->delete($quickCategory->image);
        }
        $quickCategory->delete();

        return redirect()->route('admin.quick-categories.index')->with('success', 'دسته‌بندی سریع حذف شد.');
    }

    public function toggle(QuickCategory $quickCategory)
    {
        abort_if(! auth()->user()->can('manage_quick_categories'), 403);
        $quickCategory->update(['is_active' => ! $quickCategory->is_active]);

        ActivityLogService::log('quick_category.toggle_status', $quickCategory, "تغییر وضعیت دسته‌بندی سریع «{$quickCategory->title}»", [
            'is_active' => $quickCategory->is_active,
        ]);

        return back()->with('success', 'وضعیت تغییر کرد.');
    }
}
