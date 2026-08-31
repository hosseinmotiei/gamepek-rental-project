<?php

namespace App\Services;

use App\Exceptions\ImageUploadFailedException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SettingService
{
    private const CACHE_KEY = 'gamepek_settings';
    private const CACHE_TTL = 3600;

    public function __construct(private ImageUploadService $imageUploads) {}

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all["{$group}.{$key}"] ?? $default;
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->keyBy(fn ($s) => "{$s->group}.{$s->key}")
                ->map->value
                ->toArray();
        });
    }

    public function group(string $group)
    {
        return Setting::where('group', $group)->orderBy('sort_order')->get();
    }

    /**
     * @return string[] labels of settings whose new image failed to upload
     *                   (existing value was preserved for each) -- empty if
     *                   everything succeeded.
     */
    public function updateGroup(string $group, array $values, array $files = []): array
    {
        $settings = Setting::where('group', $group)->get()->keyBy('key');
        $failedLabels = [];

        foreach ($settings as $key => $setting) {
            if ($setting->type === 'image') {
                if (isset($files[$key]) && $files[$key]->isValid()) {
                    try {
                        $path = $this->imageUploads->storeAndVerify($files[$key], 'settings');
                    } catch (ImageUploadFailedException $e) {
                        // Keep the existing value -- do not delete the old
                        // file or record a failed upload as the new value.
                        $failedLabels[] = $setting->label ?? $key;
                        continue;
                    }
                    if ($setting->value && Storage::disk('public')->exists($setting->value)) {
                        Storage::disk('public')->delete($setting->value);
                    }
                    $setting->update(['value' => $path]);
                }
                // If no new file, keep existing value
            } elseif ($setting->type === 'boolean') {
                $setting->update(['value' => (isset($values[$key]) && $values[$key]) ? '1' : '0']);
            } elseif (array_key_exists($key, $values)) {
                $setting->update(['value' => $values[$key]]);
            }
        }

        $this->clearCache();

        return $failedLabels;
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
