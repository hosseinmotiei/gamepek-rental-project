<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable once published. Editing legal text people have already signed
 * would silently rewrite their agreement, so the model refuses it outright --
 * a change means a new version under the same key.
 */
class ContractTemplate extends Model
{
    protected $fillable = ['key', 'version', 'title', 'body', 'variables', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $template) {
            $wasPublished = $template->getOriginal('published_at') !== null;

            if (! $wasPublished) {
                return;
            }

            $frozen = ['key', 'version', 'title', 'body', 'variables'];

            foreach ($frozen as $column) {
                if ($template->isDirty($column)) {
                    throw new \RuntimeException(
                        'قالب قرارداد منتشرشده قابل ویرایش نیست؛ نسخه جدیدی ایجاد کنید.'
                    );
                }
            }
        });
    }

    public static function activeFor(string $key): ?self
    {
        return static::where('key', $key)
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first();
    }

    public static function nextVersionFor(string $key): int
    {
        return (int) static::where('key', $key)->max('version') + 1;
    }
}
