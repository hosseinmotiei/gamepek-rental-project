@extends('admin.layouts.app')

@section('title', 'کدهای تخفیف')
@section('page_badge', 'کدهای تخفیف')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">کدهای تخفیف</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">کدهای تخفیف</h1>
        <p class="text-sm text-gray-500 mt-0.5">مدیریت کدهای تخفیف فروشگاه</p>
    </div>
    @can('create_coupons')
    <a href="{{ route('admin.coupons.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> کد تخفیف جدید
    </a>
    @endcan
</div>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">کد</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">مقدار تخفیف</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">حداقل سفارش</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">استفاده شده</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">تاریخ انقضا</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($coupons as $coupon)
            <tr class="hover:bg-gray-50 transition-colors {{ $coupon->is_active ? '' : 'opacity-60' }}">
                <td class="px-4 py-3">
                    <code class="text-xs bg-gray-100 text-blue-700 font-bold px-2 py-1 rounded" dir="ltr">{{ $coupon->code }}</code>
                </td>
                <td class="px-4 py-3 font-bold text-gray-800">
                    {{ $coupon->type === 'percentage' ? persian_number($coupon->value) . '٪' : number_format($coupon->value) . ' ت' }}
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $coupon->min_order_amount ? number_format($coupon->min_order_amount) . ' ت' : '—' }}</td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / ' . $coupon->usage_limit : '' }}</td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600 text-xs">{{ $coupon->ends_at?->format('Y/m/d') ?? 'نامحدود' }}</td>
                <td class="px-4 py-3">
                    <span class="text-xs font-bold px-3 py-1.5 rounded-full {{ $coupon->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $coupon->is_active ? 'فعال' : 'غیرفعال' }}
                    </span>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        @can('edit_coupons')
                        <a href="{{ route('admin.coupons.edit', $coupon) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                        @endcan
                        @can('delete_coupons')
                        <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}" onsubmit="return confirm('این کد تخفیف حذف شود؟')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                        </form>
                        @endcan
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center py-16 text-gray-400">
                <i class="fa-solid fa-ticket text-4xl mb-3 block"></i>
                هیچ کد تخفیفی یافت نشد
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $coupons->links() }}</div>
@endsection
