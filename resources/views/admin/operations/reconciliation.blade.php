@extends('admin.layouts.app')

@section('title', 'مغایرت‌های عملیات و تحویل')
@section('page_badge', 'عملیات')
@section('breadcrumb') <a href="{{ route('admin.operations.index') }}" class="text-gray-500 text-sm hover:text-gray-700">عملیات</a> <span class="text-gray-300 mx-1">/</span> <span class="text-gray-700 text-sm">مغایرت‌ها</span> @endsection

@section('content')
<div class="mb-5">
    <h1 class="text-lg font-black text-gray-800">مغایرت‌های عملیات و تحویل</h1>
    <p class="text-xs text-gray-500 mt-1 leading-6">
        این گزارش فقط خواندنی است و هیچ رکوردی را اصلاح نمی‌کند.
        نحوهٔ رفع هر مغایرت یک تصمیم کسب‌وکاری است و باید توسط کارشناس بررسی شود.
    </p>
</div>

@if ($findings->isEmpty())
    <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
        <p class="text-sm text-gray-500">هیچ مغایرتی یافت نشد.</p>
        <p class="text-xs text-gray-400 mt-2">عملیات‌ها و سوابق تحویل با یکدیگر هم‌خوان هستند.</p>
    </div>
@else
    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-3 mb-5">
        {{ persian_number($findings->count()) }} مغایرت یافت شد. هیچ‌کدام به‌صورت خودکار اصلاح نشده است.
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs">
                    <tr>
                        <th class="text-right font-medium px-4 py-3">مغایرت</th>
                        <th class="text-right font-medium px-4 py-3">عملیات</th>
                        <th class="text-right font-medium px-4 py-3">شناسه تحویل</th>
                        <th class="text-right font-medium px-4 py-3">توضیح</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($findings as $finding)
                        <tr class="hover:bg-gray-50 align-top">
                            <td class="px-4 py-3">
                                <span class="text-[11px] border border-red-200 bg-red-50 text-red-700 rounded-full px-3 py-1">{{ $finding['label'] }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($finding['operation_id'])
                                    <a href="{{ route('admin.operations.show', $finding['operation_id']) }}"
                                       class="font-mono text-xs text-brandBlue hover:underline" dir="ltr">{{ $finding['operation_number'] }}</a>
                                @else
                                    <span class="text-gray-400 text-xs">—</span>
                                @endif
                            </td>
                            {{-- Operational handle only. Not a receipt number. --}}
                            <td class="px-4 py-3 font-mono text-xs text-gray-500" dir="ltr">{{ $finding['transfer_reference'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 text-xs leading-6">{{ $finding['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
