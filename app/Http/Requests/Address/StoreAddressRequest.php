<?php

namespace App\Http\Requests\Address;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $toEnglishDigits = function ($value) {
            if ($value === null) {
                return null;
            }

            $value = strtr((string) $value, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);

            return preg_replace('/[^0-9]/', '', $value);
        };

        $this->merge([
            'receiver_mobile' => $toEnglishDigits($this->input('receiver_mobile')),
            'postal_code'     => $this->filled('postal_code') ? $toEnglishDigits($this->input('postal_code')) : null,
            'province'        => 'تهران',
            'city'            => 'تهران',
        ]);
    }

    public function rules(): array
    {
        return [
            'receiver_name'   => ['required', 'string', 'max:100'],
            'receiver_mobile' => ['required', 'regex:/^09[0-9]{9}$/'],
            'province'        => ['required', 'in:تهران'],
            'city'            => ['required', 'in:تهران'],
            'district'        => ['nullable', 'string', 'max:50'],
            'address_line'    => ['required', 'string', 'max:500'],
            'postal_code'     => ['nullable', 'digits:10'],
            'plaque'          => ['nullable', 'string', 'max:10'],
            'unit'            => ['nullable', 'string', 'max:10'],
            'is_default'      => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_name.required'   => 'نام گیرنده الزامی است.',
            'receiver_mobile.required' => 'شماره موبایل گیرنده الزامی است.',
            'receiver_mobile.regex'    => 'فرمت شماره موبایل صحیح نیست.',
            'province.required'        => 'استان الزامی است.',
            'province.in'              => 'در حال حاضر فقط استان تهران فعال است.',
            'city.required'            => 'شهر الزامی است.',
            'city.in'                  => 'در حال حاضر فقط ارسال به تهران انجام می‌شود.',
            'address_line.required'    => 'آدرس الزامی است.',
            'postal_code.digits'       => 'کد پستی باید ۱۰ رقم باشد.',
        ];
    }
}
