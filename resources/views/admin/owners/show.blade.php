@extends('admin.layouts.app')

@section('title', 'جزئیات مالک')
@section('page_badge', 'ناوگان')
@section('breadcrumb') <a href="{{ route('admin.owners.index') }}" class="text-gray-500 text-sm hover:text-gray-700">مالکان</a> <span class="text-gray-300 mx-1">/</span> <span class="text-gray-700 text-sm">جزئیات</span> @endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <div class="bg-white rounded-xl border border-gray-200 p-5 h-fit">
        <h2 class="text-base font-black text-gray-800 mb-4">{{ $owner->displayName() }}</h2>

        <dl class="text-sm divide-y divide-gray-100">
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">موبایل</dt>
                <dd class="font-mono text-gray-800" dir="ltr">{{ $owner->user?->mobile ?? '—' }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">وضعیت</dt>
                <dd class="font-medium text-gray-800">{{ $owner->state->label() }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
                <dt class="text-gray-500">تعداد دستگاه</dt>
                <dd class="font-medium text-gray-800">{{ persian_number($devices->count()) }}</dd>
            </div>
        </dl>

        <p class="text-[11px] text-gray-500 leading-6 mt-4 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5">
            احراز هویت مالک از فرایند احراز هویت مشتری جداست و در فاز بعد تکمیل می‌شود.
        </p>
    </div>

    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-bold text-gray-800 mb-4">دستگاه‌های این مالک</h3>

        @if ($devices->isEmpty())
            <p class="text-sm text-gray-500 py-6 text-center">این مالک هنوز دستگاهی ثبت نکرده است.</p>
        @else
            <div class="divide-y divide-gray-100">
                @foreach ($devices as $device)
                    <a href="{{ route('admin.devices.show', $device) }}" class="flex items-center justify-between gap-3 py-3 hover:bg-gray-50 -mx-2 px-2 rounded-lg transition-colors">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-800 truncate">{{ $device->product?->title_fa ?? '—' }}</p>
                            <p class="text-[11px] text-gray-500 font-mono mt-0.5" dir="ltr">{{ $device->maskedSerial() }}</p>
                        </div>
                        <span class="shrink-0 text-[11px] text-gray-600">{{ $device->state->label() }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
