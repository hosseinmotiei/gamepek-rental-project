<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create_products');
    }

    public function rules(): array
    {
        return [
            'title_fa'          => ['required', 'string', 'max:255'],
            'title_en'          => ['nullable', 'string', 'max:255'],
            'slug'              => ['required', 'string', 'max:255', 'unique:products,slug'],
            'sku'               => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'category_id'       => ['required', 'exists:categories,id'],
            'brand'             => ['nullable', 'string', 'max:100'],
            'model'             => ['nullable', 'string', 'max:100'],
            'color'             => ['nullable', 'string', 'max:100'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string'],
            'price'             => ['required', 'integer', 'min:0'],
            'sale_price'        => ['nullable', 'integer', 'min:0', 'lt:price'],
            'stock_quantity'    => ['required', 'integer', 'min:0'],
            'stock_status'      => ['required', 'in:in_stock,out_of_stock,coming_soon'],
            'is_active'         => ['boolean'],
            'is_featured'       => ['boolean'],
            'is_best_seller'    => ['boolean'],
            'is_flash_sale'     => ['boolean'],
            'meta_title'        => ['nullable', 'string', 'max:255'],
            'meta_description'  => ['nullable', 'string', 'max:500'],
            'main_image'        => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'],
            'gallery_images.*'  => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'],
            'attributes_json'   => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'title_fa.required'      => 'عنوان فارسی الزامی است.',
            'slug.required'          => 'اسلاگ الزامی است.',
            'slug.unique'            => 'این اسلاگ قبلاً استفاده شده.',
            'sku.unique'             => 'این SKU قبلاً ثبت شده.',
            'category_id.required'   => 'دسته‌بندی الزامی است.',
            'category_id.exists'     => 'دسته‌بندی انتخاب‌شده معتبر نیست.',
            'price.required'         => 'قیمت الزامی است.',
            'price.min'              => 'قیمت نمی‌تواند منفی باشد.',
            'sale_price.lt'          => 'قیمت تخفیف‌دار باید کمتر از قیمت اصلی باشد.',
            'sale_price.min'         => 'قیمت تخفیف‌دار نمی‌تواند منفی باشد.',
            'stock_quantity.min'     => 'موجودی نمی‌تواند منفی باشد.',
            'stock_status.required'  => 'وضعیت موجودی الزامی است.',
            'main_image.image'       => 'فایل تصویر اصلی باید تصویر باشد.',
            'main_image.max'         => 'حجم تصویر اصلی نباید بیشتر از ۲ مگابایت باشد.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active'      => $this->boolean('is_active'),
            'is_featured'    => $this->boolean('is_featured'),
            'is_best_seller' => $this->boolean('is_best_seller'),
            'is_flash_sale'  => $this->boolean('is_flash_sale'),
            'price'          => (int) str_replace(',', '', $this->price ?? 0),
            'sale_price'     => $this->sale_price ? (int) str_replace(',', '', $this->sale_price) : null,
            'stock_quantity' => (int) ($this->stock_quantity ?? 0),
        ]);
    }
}
