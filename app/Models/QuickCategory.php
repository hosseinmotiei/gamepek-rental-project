<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickCategory extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'icon', 'image', 'link',
        'category_id', 'color_class', 'bg_class',
        'highlight', 'sort_order', 'is_active', 'opens_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'highlight'       => 'boolean',
            'is_active'       => 'boolean',
            'opens_in_new_tab' => 'boolean',
            'sort_order'      => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
