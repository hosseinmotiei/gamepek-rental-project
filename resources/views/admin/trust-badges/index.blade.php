@extends('admin.layouts.app')

@section('title', 'نشان‌های اعتماد')
@section('page_badge', 'نشان‌های اعتماد')
@section('breadcrumb')
    <span class="text-gray-800 font-medium">نشان‌های اعتماد</span>
@endsection

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-black text-gray-800">نشان‌های اعتماد</h1>
        <p class="text-sm text-gray-500 mt-0.5">مدیریت آیتم‌های اعتماد و ویژگی‌ها</p>
    </div>
    <a href="{{ route('admin.trust-badges.create') }}" class="bg-brandBlue text-white font-bold px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-colors text-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> نشان جدید
    </a>
</div>

@foreach($locations as $locKey => $locLabel)
@php $locBadges = $badges->where('location', $locKey); @endphp
@if($locBadges->count())
<div class="mb-6">
    <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">{{ $locLabel }}</h2>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">آیکون</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">عنوان</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">زیرعنوان</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium hidden md:table-cell">ترتیب</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">وضعیت</th>
                    <th class="text-right px-4 py-3 text-gray-500 font-medium">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($locBadges as $badge)
                <tr class="hover:bg-gray-50 transition-colors {{ $badge->is_active ? '' : 'opacity-60' }}">
                    <td class="px-4 py-3">
                        <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center {{ $badge->color_class ?? 'text-brandBlue' }}">
                            <i class="{{ $badge->icon ?: 'fa-solid fa-shield' }}"></i>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-bold text-gray-800">{{ $badge->title }}</td>
                    <td class="px-4 py-3 hidden md:table-cell text-gray-500 text-xs">{{ $badge->subtitle }}</td>
                    <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ $badge->sort_order }}</td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('admin.trust-badges.toggle', $badge) }}" class="inline">
                            @csrf @method('PATCH')
                            <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-full transition-colors {{ $badge->is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                                {{ $badge->is_active ? 'فعال' : 'غیرفعال' }}
                            </button>
                        </form>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.trust-badges.edit', $badge) }}" class="text-xs bg-blue-50 text-brandBlue hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors font-medium">ویرایش</a>
                            <form method="POST" action="{{ route('admin.trust-badges.destroy', $badge) }}" onsubmit="return confirm('حذف شود؟')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded-lg transition-colors font-medium">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endforeach

@if($badges->isEmpty())
<div class="bg-white rounded-2xl p-16 text-center text-gray-400 border border-gray-100">
    <i class="fa-solid fa-shield-halved text-4xl mb-3 block"></i>هیچ نشانی یافت نشد
</div>
@endif
@endsection
