<?php

use App\Services\SettingService;

if (! function_exists('array_find_ci')) {
    /**
     * Case-insensitive array key lookup. Some external APIs (e.g. Pardakht
     * Novin) return field names in different casing than their own
     * documentation specifies -- match whichever casing is actually present
     * instead of trusting the documented casing literally.
     */
    function array_find_ci(array $data, string $key): mixed
    {
        foreach ($data as $k => $v) {
            if (strcasecmp((string) $k, $key) === 0) {
                return $v;
            }
        }

        return null;
    }
}

if (! function_exists('setting')) {
    /**
     * Get a setting value by "group.key" dot notation.
     * Returns $default if the setting does not exist.
     *
     * @example setting('general.site_name', 'GamePek')
     */
    function setting(string $dotKey, mixed $default = null): mixed
    {
        [$group, $key] = array_pad(explode('.', $dotKey, 2), 2, '');

        return app(SettingService::class)->get($group, $key, $default);
    }
}

if (! function_exists('media_url')) {
    /**
     * Single, centralized image/media URL resolver for the whole app --
     * products, banners, categories, blog posts, settings images, anything
     * stored on the "public" disk. Every place in the codebase that used to
     * build its own "asset('storage/'.$path)" string, or (for products
     * only) go through a separate helper with its own logic, now goes
     * through this one function, so there is exactly one place that knows
     * how a stored path becomes a public URL.
     *
     * Deliberately does NOT gate on file_exists()/Storage::exists() -- a
     * stat-cache/permission/path-resolution false negative here would
     * silently swap a real, valid image for a placeholder with no error
     * anywhere, which is exactly the bug this function replaced. Falls back
     * to $placeholder only when $path itself is empty, never based on
     * whether the file can currently be confirmed to exist.
     */
    function media_url(?string $path, string $placeholder = 'images/product-placeholder.svg'): string
    {
        if (! $path) {
            return preg_match('/^https?:\/\//i', $placeholder) ? $placeholder : asset($placeholder);
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }
}

if (! function_exists('setting_image_url')) {
    /**
     * Get the public URL for an image setting.
     * Returns null if no image is stored.
     */
    function setting_image_url(string $dotKey): ?string
    {
        $path = setting($dotKey);

        return $path ? media_url($path) : null;
    }
}

if (! function_exists('persian_number')) {
    function persian_number(int|float|string $number, int $decimals = 0): string
    {
        $formatted = number_format((float) $number, $decimals, '.', ',');

        return strtr($formatted, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}

if (! function_exists('status_label')) {
    function status_label(string $type, string $status): string
    {
        $labels = [
            'order' => [
                'pending_payment' => 'در انتظار پرداخت',
                'paid' => 'پرداخت شده',
                'processing' => 'در حال پردازش',
                'shipped' => 'ارسال شده',
                'delivered' => 'تحویل داده شده',
                'cancelled' => 'لغو شده',
                'refunded' => 'مرجوع شده',
                'failed' => 'ناموفق',
            ],
            'product_stock' => [
                'in_stock' => 'موجود',
                'out_of_stock' => 'ناموجود',
                'coming_soon' => 'بزودی',
                'preorder' => 'پیش‌خرید',
            ],
            'digital_code' => [
                'available' => 'آماده فروش',
                'reserved' => 'رزروشده',
                'sold' => 'فروخته‌شده',
                'disabled' => 'غیرفعال',
            ],
        ];

        return $labels[$type][$status] ?? $status;
    }
}

if (! function_exists('persian_digits')) {
    function persian_digits(int|float|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtr((string) $value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}

if (! function_exists('toman')) {
    function toman(int|float|string|null $amount): string
    {
        return persian_number((int) ($amount ?? 0)).' تومان';
    }
}

if (! function_exists('blog_category_color')) {
    /**
     * Deterministic color per blog category (by id) so the same category
     * always gets the same accent across the featured post, grid cards,
     * and sidebar -- instead of every category sharing one blue badge.
     */
    function blog_category_color(?int $categoryId): array
    {
        $palette = [
            ['solid' => 'bg-brandBlue', 'light' => 'bg-blue-50', 'text' => 'text-brandBlue', 'ring' => 'border-brandBlue'],
            ['solid' => 'bg-purple-600', 'light' => 'bg-purple-50', 'text' => 'text-purple-600', 'ring' => 'border-purple-600'],
            ['solid' => 'bg-pink-600', 'light' => 'bg-pink-50', 'text' => 'text-pink-600', 'ring' => 'border-pink-600'],
            ['solid' => 'bg-amber-500', 'light' => 'bg-amber-50', 'text' => 'text-amber-600', 'ring' => 'border-amber-500'],
            ['solid' => 'bg-green-600', 'light' => 'bg-green-50', 'text' => 'text-green-600', 'ring' => 'border-green-600'],
            ['solid' => 'bg-cyan-600', 'light' => 'bg-cyan-50', 'text' => 'text-cyan-600', 'ring' => 'border-cyan-600'],
        ];

        $index = $categoryId ? $categoryId % count($palette) : 0;

        return $palette[$index];
    }
}

if (! function_exists('reading_time')) {
    /**
     * Rough reading-time estimate in minutes from HTML body content.
     * 150 wpm assumes Persian text, which reads slower than English.
     */
    function reading_time(?string $html): int
    {
        $words = str_word_count(strip_tags((string) $html));

        return max(1, (int) ceil($words / 150));
    }
}

if (! function_exists('product_image_url')) {
    /**
     * Product-specific image URL resolver: figures out WHICH path to use
     * (explicit $path, else main_image, else the first gallery image), then
     * delegates the actual path-to-URL logic to media_url() -- the same
     * function every other image type in the app uses.
     */
    function product_image_url($product = null, ?string $path = null): string
    {
        $imagePath = $path;

        if (! $imagePath && $product) {
            $imagePath = $product->main_image ?? null;
            if (! $imagePath && ! empty($product->gallery_images) && is_array($product->gallery_images)) {
                $imagePath = $product->gallery_images[0] ?? null;
            }
        }

        return media_url($imagePath);
    }
}
