<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.auth()->id()],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'نام و نام خانوادگی الزامی است.',
            'full_name.min' => 'نام باید حداقل ۳ کاراکتر باشد.',
            'email.email' => 'فرمت ایمیل معتبر نیست.',
            'email.max' => 'ایمیل نباید بیشتر از ۲۵۵ کاراکتر باشد.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
        ];
    }
}
