<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private const ALLOWED_GROUPS = [
        'general', 'theme', 'header', 'footer',
        'seo', 'notifications', 'checkout', 'auth', 'profile', 'product_display',
    ];

    private const GROUP_META = [
        'general' => ['title' => 'تنظیمات عمومی',     'description' => 'اطلاعات اصلی فروشگاه، تماس و شبکه‌های اجتماعی', 'icon' => 'fa-store'],
        'theme' => ['title' => 'تنظیمات ظاهری',     'description' => 'رنگ‌ها، تصاویر پیش‌فرض و شکل ظاهری سایت',        'icon' => 'fa-palette'],
        'header' => ['title' => 'تنظیمات هدر',       'description' => 'متن‌های نوار بالا، جستجو و هدر سایت',             'icon' => 'fa-heading'],
        'footer' => ['title' => 'تنظیمات فوتر',      'description' => 'متن‌های فوتر، نمادهای اعتماد و کپی‌رایت',        'icon' => 'fa-shoe-prints'],
        'seo' => ['title' => 'تنظیمات SEO',       'description' => 'عنوان، توضیحات متا و موتورهای جستجو',            'icon' => 'fa-magnifying-glass-chart'],
        'notifications' => ['title' => 'متن‌های اعلان',     'description' => 'قالب پیام‌های SMS و اعلان‌های سیستمی',           'icon' => 'fa-bell'],
        'checkout' => ['title' => 'متن‌های خرید',      'description' => 'پیام‌های سبد خرید، پرداخت و تکمیل سفارش',       'icon' => 'fa-cart-shopping'],
        'auth' => ['title' => 'متن‌های ورود',      'description' => 'متن‌های صفحه ورود، OTP و ثبت‌نام',              'icon' => 'fa-right-to-bracket'],
        'profile' => ['title' => 'متن‌های پروفایل',  'description' => 'عنوان‌ها و پیام‌های صفحه حساب کاربری',          'icon' => 'fa-user-circle'],
        'product_display' => ['title' => 'نمایش محصولات',    'description' => 'متن‌های کارت محصول، دکمه‌ها و وضعیت موجودی',    'icon' => 'fa-tag'],
    ];

    public function __construct(private SettingService $settings) {}

    public function index()
    {
        abort_if(
            ! auth()->user()->canAny(['manage_settings', 'manage_theme_settings', 'manage_seo_settings', 'manage_notification_settings']),
            403
        );

        $user = auth()->user();
        $groups = collect(self::GROUP_META)->map(function ($meta, $group) use ($user) {
            $perm = $this->groupPermission($group);

            return array_merge($meta, [
                'key' => $group,
                'access' => $user->can($perm),
            ]);
        });

        return view('admin.settings.index', compact('groups'));
    }

    public function show(string $group)
    {
        $this->abortIfBadGroup($group);

        $settings = $this->settings->group($group);
        $meta = self::GROUP_META[$group];

        return view('admin.settings.group', compact('group', 'settings', 'meta'));
    }

    public function update(Request $request, string $group)
    {
        $this->abortIfBadGroup($group);

        $settings = $this->settings->group($group);

        // Build dynamic validation rules
        $rules = [];
        foreach ($settings as $setting) {
            $rules[$setting->key] = $this->rulesForType($setting->type, $setting->options ?? []);
        }
        $validated = $request->validate($rules);

        // Separate uploaded files from regular values
        $files = [];
        foreach ($settings as $setting) {
            if ($setting->type === 'image' && $request->hasFile($setting->key)) {
                $file = $request->file($setting->key);
                if ($file->isValid()) {
                    $files[$setting->key] = $file;
                }
            }
        }

        $failedLabels = $this->settings->updateGroup($group, $validated, $files);

        ActivityLogService::log('settings.update', null, 'به‌روزرسانی تنظیمات «'.(self::GROUP_META[$group]['title'] ?? $group).'»', [
            'group' => $group,
            'changed_keys' => array_keys($validated),
        ]);

        if (! empty($failedLabels)) {
            return back()->with('error', 'تنظیمات ذخیره شد، اما آپلود تصویر برای «'.implode('، ', $failedLabels).'» ناموفق بود. تصویر قبلی حفظ شد؛ لطفاً دوباره تلاش کنید.');
        }

        return back()->with('success', 'تنظیمات با موفقیت ذخیره شد.');
    }

    public function clearCache()
    {
        abort_if(
            ! auth()->user()->canAny(['manage_settings', 'manage_theme_settings', 'manage_seo_settings', 'manage_notification_settings']),
            403
        );

        $this->settings->clearCache();

        return back()->with('success', 'کش تنظیمات پاک شد.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function groupPermission(string $group): string
    {
        return match ($group) {
            'theme' => 'manage_theme_settings',
            'seo' => 'manage_seo_settings',
            'notifications' => 'manage_notification_settings',
            default => 'manage_settings',
        };
    }

    private function abortIfBadGroup(string $group): void
    {
        abort_if(! in_array($group, self::ALLOWED_GROUPS, true), 404);
        abort_if(! auth()->user()->can($this->groupPermission($group)), 403);
    }

    private function rulesForType(string $type, array $options = []): array|string
    {
        return match ($type) {
            'text' => ['nullable', 'string', 'max:500'],
            'textarea' => ['nullable', 'string', 'max:5000'],
            'number' => ['nullable', 'numeric'],
            'boolean' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'url' => ['nullable', 'url', 'max:500'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'select' => ['nullable', Rule::in(array_keys($options))],
            'json' => ['nullable', 'string', 'max:10000', function ($attr, $val, $fail) {
                if ($val !== null && $val !== '') {
                    json_decode($val);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $fail('فرمت JSON نامعتبر است.');
                    }
                }
            }],
            default => ['nullable', 'string', 'max:1000'],
        };
    }
}
