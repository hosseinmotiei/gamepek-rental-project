<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
            'otp' => $toEnglishDigits($this->input('otp')),
        ]);
    }

    public function rules(): array
    {
        $otpLength = (int) config('rental.otp.length', 5);

        return [
            'mobile' => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
            'otp' => ['required', 'string', 'digits:'.$otpLength],
        ];
    }

    public function messages(): array
    {
        $otpLength = (int) config('rental.otp.length', 5);

        return [
            'mobile.required' => 'شماره موبایل الزامی است.',
            'mobile.regex' => 'فرمت شماره موبایل صحیح نیست.',
            'otp.required' => 'کد تأیید الزامی است.',
            'otp.digits' => "کد تأیید باید {$otpLength} رقم باشد.",
        ];
    }
}
