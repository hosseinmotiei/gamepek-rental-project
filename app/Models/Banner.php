<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'badge', 'button_text', 'button_link',
        'image', 'bg_gradient', 'text_color', 'position', 'sort_order',
        'is_active', 'opens_in_new_tab', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'starts_at'       => 'datetime',
            'ends_at'         => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                     })
                     ->where(function ($q) {
                         $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                     });
    }

    public function scopeForPosition($query, string $position)
    {
        return $query->where('position', $position);
    }

    public function getSafeLinkAttribute(): ?string
    {
        $link = trim((string) $this->button_link);

        if ($link === '') {
            return null;
        }

        if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
            return $link;
        }

        if (filter_var($link, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
            return in_array($scheme, ['http', 'https'], true) ? $link : null;
        }

        return null;
    }
}
