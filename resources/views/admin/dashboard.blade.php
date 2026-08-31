@extends('admin.layouts.app')

@section('title', 'داشبورد')
@section('page_badge', 'داشبورد')

@section('content')

{{-- Welcome --}}
<div class="mb-6">
    <h1 class="text-xl font-bold text-gray-800">به پنل مدیریت گیم‌پک اجاره خوش آمدید</h1>
    <p class="text-sm text-gray-500 mt-1">
        {{ now()->format('Y/m/d') }} —
        آخرین ورود:
        @if(auth()->user()->last_login_at)
            {{ auth()->user()->last_login_at->diffForHumans() }}
        @else
            اولین ورود
        @endif
    </p>
</div>

{{-- Stat cards. The Store hand-wrote six near-identical copies of this
     markup; they are one component and one loop here. --}}
@php
    $tiles = [
        ['icon' => 'fa-coins',         'color' => 'blue',   'label' => 'فروش امروز',        'value' => persian_number($stats['revenue_today']), 'suffix' => 'تومان'],
        ['icon' => 'fa-bag-shopping',  'color' => 'purple', 'label' => 'سفارش‌های امروز',   'value' => persian_number($stats['orders_today'])],
        ['icon' => 'fa-clock',         'color' => 'yellow', 'label' => 'در انتظار پرداخت',  'value' => persian_number($stats['pending_payment'])],
        ['icon' => 'fa-box',           'color' => 'green',  'label' => 'موارد فعال',        'value' => persian_number($stats['active_products'])],
        ['icon' => 'fa-users',         'color' => 'blue',   'label' => 'کاربران',           'value' => persian_number($stats['total_users'])],
    ];
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
    @foreach($tiles as $tile)
        <x-admin.stat-card
            :icon="$tile['icon']"
            :color="$tile['color']"
            :label="$tile['label']"
            :value="$tile['value']"
            :suffix="$tile['suffix'] ?? null" />
    @endforeach
</div>

@endsection
