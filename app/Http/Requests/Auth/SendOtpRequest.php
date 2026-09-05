<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
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
            'mobile' => $toEnglishDigits($this->input('mobile')),
        ]);
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => 'شماره موبایل الزامی است.',
            'mobile.regex' => 'فرمت شماره موبایل صحیح نیست.',
        ];
    }
}
