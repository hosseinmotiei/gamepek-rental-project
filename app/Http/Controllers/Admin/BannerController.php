<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadFailedException;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\ActivityLogService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{
    public function __construct(private ImageUploadService $imageUploads) {}

    public function index(Request $request)
    {
        abort_if(! auth()->user()->can('view_banners'), 403);

        $query = Banner::orderBy('sort_order')->orderBy('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subtitle', 'like', "%{$search}%")
                    ->orWhere('badge', 'like', "%{$search}%");
            });
        }

        $banners = $query->paginate(20)->withQueryString();

        return view('admin.banners.index', compact('banners'));
    }

    public function create()
    {
        abort_if(! auth()->user()->can('manage_banners'), 403);

        return view('admin.banners.create');
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()->can('manage_banners'), 403);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'badge' => 'nullable|string|max:100',
            'button_text' => 'nullable|string|max:100',
            'button_link' => ['nullable', 'string', 'max:500', $this->safeBannerLinkRule()],
            'bg_gradient' => 'nullable|string|max:255',
            'text_color' => 'nullable|string|max:20',
            'position' => 'required|string|in:hero,secondary,sidebar',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:3072',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['opens_in_new_tab'] = $request->boolean('opens_in_new_tab');
        $data['text_color'] = $data['text_color'] ?? '#FFFFFF';
        $data['button_link'] = isset($data['button_link']) ? trim((string) $data['button_link']) : null;

        if ($request->hasFile('image')) {
            try {
                $data['image'] = $this->imageUploads->storeAndVerify($request->file('image'), 'banners');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
        }

        $banner = Banner::create($data);

        ActivityLogService::log('banner.create', $banner, "ایجاد بنر «{$banner->title}»", ['position' => $banner->position]);

        return redirect()->route('admin.banners.index')->with('success', 'بنر با موفقیت ایجاد شد.');
    }

    public function edit(Banner $banner)
    {
        abort_if(! auth()->user()->can('manage_banners'), 403);

        return view('admin.banners.edit', compact('banner'));
    }

    public function update(Request $request, Banner $banner)
    {
        abort_if(! auth()->user()->can('manage_banners'), 403);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'badge' => 'nullable|string|max:100',
            'button_text' => 'nullable|string|max:100',
            'button_link' => ['nullable', 'string', 'max:500', $this->safeBannerLinkRule()],
            'bg_gradient' => 'nullable|string|max:255',
            'text_color' => 'nullable|string|max:20',
            'position' => 'required|string|in:hero,secondary,sidebar',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:3072',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['opens_in_new_tab'] = $request->boolean('opens_in_new_tab');
        $data['text_color'] = $data['text_color'] ?? '#FFFFFF';
        $data['button_link'] = isset($data['button_link']) ? trim((string) $data['button_link']) : null;

        if ($request->hasFile('image')) {
            try {
                $newPath = $this->imageUploads->storeAndVerify($request->file('image'), 'banners');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
            if ($banner->image) {
                Storage::disk('public')->delete($banner->image);
            }
            $data['image'] = $newPath;
        }

        $before = $banner->only(['is_active', 'position', 'sort_order']);
        $banner->update($data);

        ActivityLogService::log('banner.update', $banner, "به‌روزرسانی بنر «{$banner->title}»", [
            'before' => $before, 'after' => $banner->only(['is_active', 'position', 'sort_order']),
        ]);

        return redirect()->route('admin.banners.index')->with('success', 'بنر با موفقیت بروزرسانی شد.');
    }

    public function destroy(Banner $banner)
    {
        abort_if(! auth()->user()->can('manage_banners'), 403);

        if ($banner->image) {
            Storage::disk('public')->delete($banner->image);
        }

        $position = $banner->position;
        $banner->delete();
        ActivityLogService::log('banner.delete', $banner, "حذف بنر «{$banner->title}»", ['position' => $position]);

        return redirect()->route('admin.banners.index')->with('success', 'بنر حذف شد.');
    }

    public function toggle(Banner $banner)
    {
        abort_if(! auth()->user()->can('manage_banners'), 403);
        $before = $banner->is_active;
        $banner->update(['is_active' => ! $banner->is_active]);

        ActivityLogService::log('banner.toggle_status', $banner, "تغییر وضعیت بنر «{$banner->title}»", [
            'before' => $before, 'after' => $banner->is_active,
        ]);

        return back()->with('success', 'وضعیت بنر تغییر کرد.');
    }

    private function safeBannerLinkRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $link = trim((string) $value);

            if ($link === '') {
                return;
            }

            if (str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
                return;
            }

            if (filter_var($link, FILTER_VALIDATE_URL)) {
                $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
                if (in_array($scheme, ['http', 'https'], true)) {
                    return;
                }
            }

            $fail('لینک بنر باید یک مسیر داخلی مثل /products یا یک لینک کامل http/https باشد.');
        };
    }
}
