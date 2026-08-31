<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOptionValue extends Model
{
    protected $fillable = ['option_group_id', 'label', 'sort_order', 'is_default', 'price_modifier'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'price_modifier' => 'integer',
        ];
    }

    public function group()
    {
        return $this->belongsTo(ProductOptionGroup::class, 'option_group_id');
    }
}
