@extends('admin.layouts.app')

@section('title', 'ویرایش کد تخفیف')
@section('page_badge', 'ویرایش کد تخفیف')
@section('breadcrumb')
    <a href="{{ route('admin.coupons.index') }}" class="hover:text-brandBlue">کدهای تخفیف</a>
    <i class="fa-solid fa-chevron-left text-[10px]"></i>
    <span class="text-gray-800 font-medium">ویرایش</span>
@endsection

@section('content')
<form method="POST" action="{{ route('admin.coupons.update', $coupon) }}" class="max-w-2xl bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-5">
@csrf @method('PUT')

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">کد تخفیف <span class="text-red-500">*</span></label>
        <input type="text" name="code" value="{{ old('code', $coupon->code) }}" required dir="ltr"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-brandBlue @error('code') border-red-400 @enderror">
        @error('code')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">نوع تخفیف <span class="text-red-500">*</span></label>
        <select name="type" id="typeSelect" required
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
            <option value="percentage" {{ old('type', $coupon->type) === 'percentage' ? 'selected' : '' }}>درصدی</option>
            <option value="fixed" {{ old('type', $coupon->type) === 'fixed' ? 'selected' : '' }}>مبلغ ثابت (تومان)</option>
        </select>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5" id="valueLabel">مقدار تخفیف <span class="text-red-500">*</span></label>
        <input type="number" name="value" value="{{ old('value', $coupon->value) }}" required min="1"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('value') border-red-400 @enderror">
        @error('value')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">حداکثر مبلغ تخفیف (تومان — اختیاری)</label>
        <input type="number" name="max_discount" value="{{ old('max_discount', $coupon->max_discount) }}" min="0"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <p class="text-xs text-gray-400 mt-1">فقط برای تخفیف درصدی کاربرد دارد</p>
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">حداقل مبلغ سفارش (تومان — اختیاری)</label>
    <input type="number" name="min_order_amount" value="{{ old('min_order_amount', $coupon->min_order_amount) }}" min="0"
           class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">سقف تعداد استفاده کل (اختیاری)</label>
        <input type="number" name="usage_limit" value="{{ old('usage_limit', $coupon->usage_limit) }}" min="1"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <p class="text-xs text-gray-400 mt-1">خالی = نامحدود — استفاده شده تاکنون: {{ $coupon->used_count }}</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">سقف استفاده هر کاربر (اختیاری)</label>
        <input type="number" name="per_user_limit" value="{{ old('per_user_limit', $coupon->per_user_limit) }}" min="1"
               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
        <p class="text-xs text-gray-400 mt-1">خالی = نامحدود</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">تاریخ شروع (اختیاری)</label>
        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d\TH:i')) }}" dir="ltr"
               class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1.5">تاریخ انقضا (اختیاری)</label>
        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $coupon->ends_at?->format('Y-m-d\TH:i')) }}" dir="ltr"
               class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue @error('ends_at') border-red-400 @enderror">
        @error('ends_at')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
    </div>
</div>

<label class="flex items-center gap-3 cursor-pointer">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $coupon->is_active) ? 'checked' : '' }} class="w-4 h-4 text-brandBlue rounded">
    <span class="text-sm font-medium text-gray-700">فعال</span>
</label>

<div class="flex gap-3 pt-2">
    <button type="submit" class="bg-brandBlue text-white font-bold px-6 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm">ذخیره تغییرات</button>
    <a href="{{ route('admin.coupons.index') }}" class="text-center bg-gray-100 text-gray-700 font-medium px-6 py-2.5 rounded-xl hover:bg-gray-200 transition-colors text-sm">انصراف</a>
</div>

</form>
@endsection

@push('scripts')
<script>
(function() {
    const typeSelect = document.getElementById('typeSelect');
    const valueLabel = document.getElementById('valueLabel');
    function syncLabel() {
        valueLabel.innerHTML = (typeSelect.value === 'percentage' ? 'مقدار تخفیف (٪) ' : 'مقدار تخفیف (تومان) ') + '<span class="text-red-500">*</span>';
    }
    typeSelect.addEventListener('change', syncLabel);
    syncLabel();
})();
</script>
@endpush
