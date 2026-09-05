<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'selected_options' => ['sometimes', 'nullable', 'array'],
            'selected_options.*' => ['string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'شناسه محصول الزامی است.',
            'product_id.integer' => 'شناسه محصول نامعتبر است.',
            'product_id.exists' => 'محصول مورد نظر یافت نشد.',
            'quantity.integer' => 'تعداد محصول نامعتبر است.',
            'quantity.min' => 'حداقل تعداد ۱ است.',
            'quantity.max' => 'حداکثر تعداد ۱۰ است.',
        ];
    }
}
