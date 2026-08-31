<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit_categories');
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')->id;

        return [
            'name_fa'      => ['required', 'string', 'max:100'],
            'name_en'      => ['nullable', 'string', 'max:100'],
            'slug'         => ['required', 'string', 'max:120', Rule::unique('categories', 'slug')->ignore($categoryId)],
            'parent_id'    => ['nullable', 'exists:categories,id', "not_in:$categoryId"],
            'icon'         => ['nullable', 'string', 'max:100'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
            'is_active'    => ['boolean'],
            'show_in_menu' => ['boolean'],
            'image'        => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name_fa.required'  => 'نام فارسی دسته‌بندی الزامی است.',
            'slug.required'     => 'اسلاگ الزامی است.',
            'slug.unique'       => 'این اسلاگ قبلاً استفاده شده.',
            'parent_id.not_in'  => 'دسته‌بندی نمی‌تواند والد خودش باشد.',
            'image.max'         => 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active'    => $this->boolean('is_active'),
            'show_in_menu' => $this->boolean('show_in_menu'),
            'remove_image' => $this->boolean('remove_image'),
            'sort_order'   => (int) ($this->sort_order ?? 0),
            'parent_id'    => $this->parent_id ?: null,
        ]);
    }
}
