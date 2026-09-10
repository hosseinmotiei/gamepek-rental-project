@extends('admin.layouts.app')

@section('title', 'مالکان دستگاه')
@section('page_badge', 'ناوگان')
@section('breadcrumb') <span class="text-gray-700 text-sm">مالکان</span> @endsection

@section('content')
<div class="mb-5">
    <h1 class="text-lg font-black text-gray-800">مالکان دستگاه</h1>
    <p class="text-xs text-gray-500 mt-1">مالکان شخص ثالثی که دستگاه خود را در اختیار گیم‌پک قرار داده‌اند.</p>
</div>

@if ($owners->isEmpty())
    <div class="bg-white rounded-xl border border-gray-200 p-10 text-center">
        <p class="text-sm text-gray-500">هنوز مالکی ثبت نشده است.</p>
    </div>
@else
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm min-w-[640px]">
            <thead class="bg-gray-50 text-gray-500 text-xs">
                <tr>
                    <th class="px-4 py-3 text-right font-medium">مالک</th>
                    <th class="px-4 py-3 text-right font-medium">موبایل</th>
                    <th class="px-4 py-3 text-right font-medium">وضعیت</th>
                    <th class="px-4 py-3 text-right font-medium">تعداد دستگاه</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($owners as $owner)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-800">{{ $owner->displayName() }}</td>
                        <td class="px-4 py-3 font-mono text-gray-600" dir="ltr">{{ $owner->user?->mobile ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $owner->state->label() }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ persian_number($owner->devices_count) }}</td>
                        <td class="px-4 py-3 text-left">
                            <a href="{{ route('admin.owners.show', $owner) }}" class="text-brandBlue text-xs font-bold hover:underline">جزئیات</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $owners->links() }}</div>
@endif
@endsection
