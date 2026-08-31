<?php

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body'    => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'وارد کردن موضوع پیام الزامی است.',
            'subject.min'      => 'موضوع پیام باید حداقل ۳ کاراکتر باشد.',
            'body.required'    => 'متن پیام نمی‌تواند خالی باشد.',
            'body.min'         => 'متن پیام باید حداقل ۳ کاراکتر باشد.',
            'body.max'         => 'متن پیام نمی‌تواند بیشتر از ۵۰۰۰ کاراکتر باشد.',
        ];
    }
}
