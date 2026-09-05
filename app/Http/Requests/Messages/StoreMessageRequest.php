<?php

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'متن پیام نمی‌تواند خالی باشد.',
            'body.max' => 'متن پیام نمی‌تواند بیشتر از ۵۰۰۰ کاراکتر باشد.',
        ];
    }
}
