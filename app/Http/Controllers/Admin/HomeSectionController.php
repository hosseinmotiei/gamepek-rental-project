<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadFailedException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\HomeSection;
use App\Services\ActivityLogService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HomeSectionController extends Controller
{
    public function __construct(private ImageUploadService $imageUploads) {}

    public function index()
    {
        abort_if(! auth()->user()->can('view_home_sections'), 403);
        $sections = HomeSection::orderBy('sort_order')->get();

        return view('admin.home-sections.index', compact('sections'));
    }

    /**
     * Only for admin-added, category-driven product sections (the ones
     * rendered by the generic loop in home.blade.php) -- the original fixed
     * set of sections (hero, quick_categories, gta_vi, etc.) are wired to
     * specific spots in the homepage template by their fixed "key" and
     * aren't meant to be duplicated or deleted.
     */
    public function create()
    {
        abort_if(! auth()->user()->can('manage_home_sections'), 403);
        $categories = Category::orderBy('name_fa')->get();

        return view('admin.home-sections.create', compact('categories'));
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()->can('manage_home_sections'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'cta_text' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:500',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'item_limit' => 'nullable|integer|min:1|max:20',
            'selection_mode' => 'required|in:latest,best_seller,featured',
            'category_id' => 'required|exists:categories,id',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['key'] = 'custom_'.Str::slug($data['title'], '_').'_'.Str::random(6);

        $section = HomeSection::create($data);

        ActivityLogService::log('home_section.create', $section, "ایجاد بخش «{$section->title}»");

        return redirect()->route('admin.home-sections.index')->with('success', 'بخش جدید ایجاد شد.');
    }

    public function destroy(HomeSection $homeSection)
    {
        abort_if(! auth()->user()->can('manage_home_sections'), 403);
        abort_unless(str_starts_with($homeSection->key, 'custom_'), 403, 'بخش‌های ثابت صفحه اصلی قابل حذف نیستند.');

        if ($homeSection->image) {
            Storage::disk('public')->delete($homeSection->image);
        }

        ActivityLogService::log('home_section.delete', $homeSection, "حذف بخش «{$homeSection->title}»");
        $homeSection->delete();

        return redirect()->route('admin.home-sections.index')->with('success', 'بخش حذف شد.');
    }

    public function edit(HomeSection $homeSection)
    {
        abort_if(! auth()->user()->can('manage_home_sections'), 403);
        $categories = Category::orderBy('name_fa')->get();

        return view('admin.home-sections.edit', compact('homeSection', 'categories'));
    }

    public function update(Request $request, HomeSection $homeSection)
    {
        abort_if(! auth()->user()->can('manage_home_sections'), 403);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:1000',
            'cta_text' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:500',
            'background_color' => 'nullable|string|max:50',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'item_limit' => 'nullable|integer|min:1|max:20',
            'selection_mode' => 'required|in:latest,best_seller,featured,manual',
            'category_id' => 'nullable|exists:categories,id',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:3072',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('image')) {
            try {
                $newPath = $this->imageUploads->storeAndVerify($request->file('image'), 'home-sections');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
            if ($homeSection->image) {
                Storage::disk('public')->delete($homeSection->image);
            }
            $data['image'] = $newPath;
        }

        $before = $homeSection->only(['is_active', 'sort_order', 'selection_mode']);
        $homeSection->update($data);

        ActivityLogService::log('home_section.update', $homeSection, "به‌روزرسانی بخش «{$homeSection->key}»", [
            'before' => $before, 'after' => $homeSection->only(['is_active', 'sort_order', 'selection_mode']),
        ]);

        return redirect()->route('admin.home-sections.index')->with('success', 'بخش صفحه اصلی بروزرسانی شد.');
    }

    public function toggle(HomeSection $homeSection)
    {
        abort_if(! auth()->user()->can('manage_home_sections'), 403);
        $before = $homeSection->is_active;
        $homeSection->update(['is_active' => ! $homeSection->is_active]);

        ActivityLogService::log('home_section.toggle_status', $homeSection, "تغییر وضعیت بخش «{$homeSection->key}»", [
            'before' => $before, 'after' => $homeSection->is_active,
        ]);

        return back()->with('success', 'وضعیت بخش تغییر کرد.');
    }
}
