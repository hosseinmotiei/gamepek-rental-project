@extends('layouts.app')

@section('title', 'گیم‌پک | احراز هویت')

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <h1 class="text-lg md:text-2xl font-black text-gray-800 mb-2">احراز هویت</h1>
    <p class="text-xs md:text-sm text-gray-500 mb-6">
        برای اجاره دستگاه، احراز هویت و تأیید مالکیت حساب بانکی الزامی است.
    </p>

    @if (session('success'))
        <div class="mb-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs md:text-sm px-4 py-3">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- ─── Identity ─────────────────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
        <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm md:text-base font-bold text-gray-800">اطلاعات هویتی</h2>
            @if ($identity)
                <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full
                    {{ $identity->isVerified() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                    {{ $identity->state->label() }}
                </span>
            @endif
        </header>

        <div class="p-5">
            @if ($identity && $identity->national_code_mask)
                <div class="flex items-center justify-between text-xs md:text-sm mb-4">
                    <span class="text-gray-500">کد ملی ثبت‌شده</span>
                    <span class="font-bold text-gray-800" dir="ltr">{{ $identity->national_code_mask }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('verification.identity.store') }}" class="grid gap-3 md:grid-cols-2">
                @csrf
                <div>
                    <label class="block text-[11px] md:text-xs text-gray-500 mb-1">کد ملی</label>
                    <input type="text" name="national_code" inputmode="numeric" maxlength="10" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-brandBlue"
                           value="{{ old('national_code') }}">
                </div>
                <div>
                    <label class="block text-[11px] md:text-xs text-gray-500 mb-1">تاریخ تولد (میلادی)</label>
                    <input type="date" name="birth_date" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-brandBlue"
                           value="{{ old('birth_date', $identity?->birth_date?->format('Y-m-d')) }}">
                </div>
                <div class="md:col-span-2">
                    <button type="submit"
                            class="w-full md:w-auto bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5">
                        ثبت اطلاعات هویتی
                    </button>
                </div>
            </form>
        </div>
    </section>

    {{-- ─── Checks ───────────────────────────────────────────────────── --}}
    @if ($identity)
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
            <header class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm md:text-base font-bold text-gray-800">استعلام‌ها</h2>
            </header>

            <div class="p-5 space-y-3">
                @forelse ($identity->verifications as $check)
                    <div class="flex items-center justify-between text-xs md:text-sm border-b border-gray-50 pb-2">
                        <span class="text-gray-600">{{ $check->type }}</span>
                        <span class="font-bold text-gray-800">{{ $check->state }}</span>
                    </div>
                @empty
                    <p class="text-xs md:text-sm text-gray-400">هنوز استعلامی انجام نشده است.</p>
                @endforelse

                <div class="flex flex-wrap gap-2 pt-2">
                    @foreach (['shahkar' => 'استعلام شاهکار', 'civil_registry' => 'استعلام ثبت احوال'] as $type => $label)
                        <form method="POST" action="{{ route('verification.identity.run') }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <button type="submit"
                                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold rounded-xl px-4 py-2">
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ─── Bank accounts ────────────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800">حساب بانکی</h2>
        </header>

        <div class="p-5 space-y-4">
            @forelse ($user->bankAccounts as $account)
                <div class="flex items-center justify-between text-xs md:text-sm border-b border-gray-50 pb-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-gray-500">{{ $account->type === 'card' ? 'کارت' : 'شبا' }}</span>
                        <span class="font-bold text-gray-800" dir="ltr">{{ $account->value_mask }}</span>
                        @if ($account->owner_name)
                            <span class="text-[11px] text-gray-400">به نام: {{ $account->owner_name }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold px-3 py-1 rounded-full
                            {{ $account->isVerified() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $account->state->label() }}
                        </span>
                        @unless ($account->isVerified())
                            <form method="POST" action="{{ route('verification.bank.verify', $account) }}">
                                @csrf
                                <button type="submit" class="text-brandBlue text-xs font-bold">استعلام</button>
                            </form>
                        @endunless
                    </div>
                </div>
            @empty
                <p class="text-xs md:text-sm text-gray-400">هنوز حسابی ثبت نشده است.</p>
            @endforelse

            <form method="POST" action="{{ route('verification.bank.store') }}" class="grid gap-3 md:grid-cols-3">
                @csrf
                <div>
                    <label class="block text-[11px] md:text-xs text-gray-500 mb-1">نوع</label>
                    <select name="type" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                        <option value="card">شماره کارت</option>
                        <option value="iban">شماره شبا</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-[11px] md:text-xs text-gray-500 mb-1">شماره</label>
                    <input type="text" name="value" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-brandBlue">
                </div>
                <div class="md:col-span-3">
                    <button type="submit"
                            class="w-full md:w-auto bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5">
                        ثبت حساب
                    </button>
                </div>
            </form>
        </div>
    </section>
</main>
@endsection
