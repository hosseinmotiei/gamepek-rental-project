<?php

namespace App\Http\Requests\Checkout;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        // Every rental item is a physical device that has to reach the
        // customer, so a delivery address and method are always required.
        // Kept as a named flag because the rental flow will later add
        // in-person pickup, which is the case that makes it false.
        $needsShipping = true;

        return [
            'shipping_address_id' => $needsShipping
                ? ['required', 'integer', Rule::exists('addresses', 'id')->where('user_id', auth()->id())]
                : ['nullable', 'integer'],
            'shipping_method_id'  => $needsShipping
                ? ['required', 'integer', 'exists:shipping_methods,id']
                : ['nullable', 'integer'],
            'customer_note'       => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'shipping_address_id.required' => 'انتخاب آدرس ارسال الزامی است.',
            'shipping_address_id.exists'   => 'آدرس انتخابی معتبر نیست.',
            'shipping_method_id.required'  => 'انتخاب روش ارسال الزامی است.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! auth()->user()?->full_name) {
                $validator->errors()->add('full_name', 'برای ثبت سفارش، تکمیل نام و نام خانوادگی الزامی است.');
            }
        });
    }
}
