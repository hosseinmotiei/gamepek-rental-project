<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_title_snapshot', 'product_sku_snapshot',
        'product_type_snapshot', 'product_image_snapshot', 'quantity',
        'unit_price', 'sale_price', 'total_price', 'selected_options',
        'options_price_modifier',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'sale_price' => 'integer',
            'total_price' => 'integer',
            'selected_options' => 'array',
            'options_price_modifier' => 'integer',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
