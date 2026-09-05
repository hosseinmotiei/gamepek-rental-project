<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrustBadge extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'icon', 'image',
        'sort_order', 'is_active', 'location', 'color_class',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeForLocation($query, string $location)
    {
        return $query->where('location', $location);
    }
}
