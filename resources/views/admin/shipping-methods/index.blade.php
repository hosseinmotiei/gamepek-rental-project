@extends('admin.layouts.app')

@section('title', 'روش‌های ارسال')
@section('page_badge', 'روش‌های ارسال')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">روش‌های ارسال</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">روش‌های ارسال</h1>
        <p class="text-sm text-gray-500 mt-0.5">مدیریت روش‌ها و هزینه‌های ارسال سفارش</p>
    </div>
    @can('create_shipping_methods')
    <a href="{{ route('admin.shipping-methods.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> روش ارسال جدید
    </a>
    @endcan
</div>

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عنوان</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">شهر</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">هزینه</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">زمان تحویل</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">ترتیب</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($methods as $method)
            <tr class="hover:bg-gray-50 transition-colors {{ $method->is_active ? '' : 'opacity-60' }}">
                <td class="px-4 py-3">
                    <p class="font-bold text-gray-800">{{ $method->title_fa }}</p>
                    @if($method->description)<p class="text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($method->description, 50) }}</p>@endif
                </td>
                <td class="px-4 py-3 text-gray-600">{{ $method->city ?: 'سراسری' }}</td>
                <td class="px-4 py-3 font-bold text-gray-800">{{ number_format($method->base_cost) }} ت</td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $method->estimated_delivery_text ?: ($method->min_days . ' تا ' . $method->max_days . ' روز') }}</td>
                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $method->sort_order }}</td>
                <td class="px-4 py-3">
                    <span class="text-xs font-bold px-3 py-1.5 rounded-full {{ $method->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $method->is_active ? 'فعال' : 'غیرفعال' }}
                    </span>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        @can('edit_shipping_methods')
                        <a href="{{ route('admin.shipping-methods.edit', $method) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                        @endcan
                        @can('delete_shipping_methods')
                        <form method="POST" action="{{ route('admin.shipping-methods.destroy', $method) }}" onsubmit="return confirm('این روش ارسال حذف شود؟')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                        </form>
                        @endcan
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center py-16 text-gray-400">
                <i class="fa-solid fa-truck-fast text-4xl mb-3 block"></i>
                هیچ روش ارسالی یافت نشد
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
