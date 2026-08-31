<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group', 'key', 'label', 'value', 'type',
        'options', 'description', 'sort_order', 'is_public',
    ];

    protected $casts = [
        'options'    => 'array',
        'is_public'  => 'boolean',
        'sort_order' => 'integer',
    ];
}
