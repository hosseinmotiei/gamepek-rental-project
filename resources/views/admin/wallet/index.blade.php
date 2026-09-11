{{--
    Real, persisted Wallet data (WalletService), replacing the earlier
    "coming soon" placeholder. Read-only: no credit/debit control exists
    here on purpose -- see Admin\WalletController's docblock.
--}}
@extends('admin.layouts.app')

@section('title', 'کیف پول')
@section('page_badge', 'کیف پول')
@section('breadcrumb') <span class="text-gray-700 text-sm">کیف پول</span> @endsection

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg font-bold text-gray-800">کیف پول کاربران</h1>
        <p class="text-xs text-gray-500 mt-0.5">{{ persian_number($wallets->total()) }} کیف پول</p>
    </div>
</div>

<x-admin.panel>
    @if($wallets->isEmpty())
        <x-admin.empty-state icon="fa-wallet" message="هنوز کیف پولی ایجاد نشده است." />
    @else
        <x-admin.table :paginator="$wallets">
            <x-slot:head>
                <tr class="border-b border-gray-100 text-right">
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">کاربر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden md:table-cell">موبایل</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs">موجودی</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs hidden lg:table-cell">آخرین تغییر</th>
                    <th class="px-4 py-3 font-semibold text-gray-600 text-xs text-left">عملیات</th>
                </tr>
            </x-slot:head>

            @foreach($wallets as $wallet)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3">
                    <p class="font-medium text-gray-800 text-sm">{{ $wallet->user?->full_name ?: '—' }}</p>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-sm font-mono text-gray-700" dir="ltr">{{ $wallet->user?->mobile }}</span>
                </td>
                <td class="px-4 py-3">
                    <span class="font-bold text-gray-800" dir="ltr">{{ persian_number($wallet->balance) }}</span>
                    <span class="text-[10px] text-gray-400">تومان</span>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell">
                    <span class="text-xs text-gray-500">{{ $wallet->updated_at->format('Y/m/d H:i') }}</span>
                </td>
                <td class="px-4 py-3 text-left">
                    <a href="{{ route('admin.wallet.show', $wallet) }}"
                       class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-brandBlue hover:bg-blue-50 transition-all" title="مشاهده تراکنش‌ها">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </a>
                </td>
            </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.panel>

@endsection
