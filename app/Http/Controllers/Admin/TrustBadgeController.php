<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadFailedException;
use App\Http\Controllers\Controller;
use App\Models\TrustBadge;
use App\Services\ActivityLogService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrustBadgeController extends Controller
{
    public function __construct(private ImageUploadService $imageUploads) {}

    private const LOCATIONS = [
        'top_features' => 'ویژگی‌های بالا',
        'footer_features' => 'ویژگی‌های فوتر',
        'why_gamepek' => 'چرا گیم‌پک',
    ];

    public function index()
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);
        $badges = TrustBadge::orderBy('location')->orderBy('sort_order')->get();
        $locations = self::LOCATIONS;

        return view('admin.trust-badges.index', compact('badges', 'locations'));
    }

    public function create()
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);
        $locations = self::LOCATIONS;

        return view('admin.trust-badges.create', compact('locations'));
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:100',
            'color_class' => 'nullable|string|max:100',
            'location' => 'required|in:'.implode(',', array_keys(self::LOCATIONS)),
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:1024',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('image')) {
            try {
                $data['image'] = $this->imageUploads->storeAndVerify($request->file('image'), 'trust-badges');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
        }

        $trustBadge = TrustBadge::create($data);

        ActivityLogService::log('trust_badge.create', $trustBadge, "ایجاد نشان اعتماد «{$trustBadge->title}»");

        return redirect()->route('admin.trust-badges.index')->with('success', 'نشان اعتماد ایجاد شد.');
    }

    public function edit(TrustBadge $trustBadge)
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);
        $locations = self::LOCATIONS;

        return view('admin.trust-badges.edit', compact('trustBadge', 'locations'));
    }

    public function update(Request $request, TrustBadge $trustBadge)
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:100',
            'color_class' => 'nullable|string|max:100',
            'location' => 'required|in:'.implode(',', array_keys(self::LOCATIONS)),
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,webp,gif|max:1024',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('image')) {
            try {
                $newPath = $this->imageUploads->storeAndVerify($request->file('image'), 'trust-badges');
            } catch (ImageUploadFailedException $e) {
                return back()->withInput()->withErrors(['image' => $e->getMessage()]);
            }
            if ($trustBadge->image) {
                Storage::disk('public')->delete($trustBadge->image);
            }
            $data['image'] = $newPath;
        }

        $trustBadge->update($data);

        ActivityLogService::log('trust_badge.update', $trustBadge, "به‌روزرسانی نشان اعتماد «{$trustBadge->title}»", [
            'changed_fields' => array_keys($trustBadge->getChanges()),
        ]);

        return redirect()->route('admin.trust-badges.index')->with('success', 'نشان اعتماد بروزرسانی شد.');
    }

    public function destroy(TrustBadge $trustBadge)
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);

        ActivityLogService::log('trust_badge.delete', $trustBadge, "حذف نشان اعتماد «{$trustBadge->title}»");

        if ($trustBadge->image) {
            Storage::disk('public')->delete($trustBadge->image);
        }
        $trustBadge->delete();

        return redirect()->route('admin.trust-badges.index')->with('success', 'نشان اعتماد حذف شد.');
    }

    public function toggle(TrustBadge $trustBadge)
    {
        abort_if(! auth()->user()->can('manage_trust_badges'), 403);
        $trustBadge->update(['is_active' => ! $trustBadge->is_active]);

        ActivityLogService::log('trust_badge.toggle_status', $trustBadge, "تغییر وضعیت نشان اعتماد «{$trustBadge->title}»", [
            'is_active' => $trustBadge->is_active,
        ]);

        return back()->with('success', 'وضعیت تغییر کرد.');
    }
}
