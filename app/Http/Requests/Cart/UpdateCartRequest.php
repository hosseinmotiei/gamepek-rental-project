<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'تعداد محصول الزامی است.',
            'quantity.integer' => 'تعداد محصول نامعتبر است.',
            'quantity.min' => 'تعداد محصول نمی‌تواند منفی باشد.',
            'quantity.max' => 'حداکثر تعداد ۱۰ است.',
        ];
    }
}
