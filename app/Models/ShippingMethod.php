<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    protected $fillable = [
        'title', 'title_fa', 'description', 'base_cost', 'city',
        'estimated_delivery_text', 'min_days', 'max_days', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'base_cost' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCity($query, string $city)
    {
        return $query->where(function ($q) use ($city) {
            $q->where('city', $city)->orWhereNull('city');
        });
    }
}
