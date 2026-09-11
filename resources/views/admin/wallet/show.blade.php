{{--
    One wallet's ledger. Read-only, same as the index -- every row here was
    written by WalletService inside a locked transaction; there is no form on
    this page that could write another.
--}}
@extends('admin.layouts.app')

@section('title', 'کیف پول کاربر')
@section('page_badge', 'کیف پول')
@section('breadcrumb')
    <a href="{{ route('admin.wallet.index') }}" class="text-gray-400 hover:text-brandBlue text-sm">کیف پول</a>
    <span class="text-gray-300 mx-1">/</span>
    <span class="text-gray-700 text-sm">{{ $wallet->user?->full_name ?? $wallet->id }}</span>
@endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">{{ $wallet->user?->full_name ?: '—' }}</h1>
        <p class="text-xs text-gray-500 mt-0.5 font-mono" dir="ltr">{{ $wallet->user?->mobile }}</p>
    </div>
    <div class="text-left">
        <p class="text-[11px] text-gray-400">موجودی فعلی</p>
        <p class="text-xl font-black text-gray-800" dir="ltr">
            {{ persian_number($wallet->balance) }} <span class="text-xs font-normal text-gray-500">تومان</span>
        </p>
    </div>
</div>

<x-admin.panel>
    @if($transactions->isEmpty())
        <x-admin.empty-state icon="fa-receipt" message="هنوز تراکنشی برای این کیف پول ثبت نشده است." />
    @else
        <x-admin.table :paginator="$transactions">
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">نوع</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">مبلغ</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">موجودی پس از تراکنش</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">دلیل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">شناسه مرجع</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">زمان</th>
                </tr>
            </x-slot:head>

            @foreach($transactions as $transaction)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3">
                    @if($transaction->isCredit())
                        <x-admin.status-badge color="green" label="واریز" />
                    @else
                        <x-admin.status-badge color="red" label="برداشت" />
                    @endif
                </td>
                <td class="px-4 py-3">
                    <span class="font-bold {{ $transaction->isCredit() ? 'text-green-600' : 'text-red-600' }}" dir="ltr">
                        {{ $transaction->isCredit() ? '+' : '-' }}{{ persian_number($transaction->amount) }}
                    </span>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-gray-700" dir="ltr">{{ persian_number($transaction->balance_after) }}</span>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    <span class="text-xs text-gray-500">{{ $transaction->reason }}</span>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-[11px] font-mono text-gray-400" dir="ltr">{{ $transaction->reference_number }}</span>
                </td>
                <td class="px-4 py-3 text-left">
                    <span class="text-xs text-gray-500">{{ $transaction->created_at->format('Y/m/d H:i') }}</span>
                </td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
