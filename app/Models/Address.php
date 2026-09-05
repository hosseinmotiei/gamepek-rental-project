<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = [
        'user_id', 'receiver_name', 'receiver_mobile', 'province', 'city',
        'district', 'address_line', 'postal_code', 'plaque', 'unit',
        'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->province,
            $this->city,
            $this->district,
            $this->address_line,
            $this->plaque ? 'پلاک '.$this->plaque : null,
            $this->unit ? 'واحد '.$this->unit : null,
        ]);

        return implode('، ', $parts);
    }
}
