@extends('layouts.app')

@section('title', 'گیم‌پک | احراز هویت')

@php
    use App\Enums\IdentityState;
    use App\Enums\MediaState;

    $mediaLabels = [
        'national_card' => 'تصویر کارت ملی',
        'selfie' => 'تصویر سلفی همراه با کارت ملی',
        'liveness_video' => 'ویدئوی احراز زنده بودن',
    ];

    // Presentation-only Persian labels for the fixed set of check types/states
    // IdentityVerification already defines (TYPE_*/STATE_* constants) -- no
    // new check type or outcome is introduced here.
    $checkTypeLabels = [
        'shahkar' => 'استعلام شاهکار',
        'civil_registry' => 'استعلام ثبت احوال',
        'liveness' => 'احراز زنده بودن',
        'face_match' => 'تطبیق چهره',
    ];
    $checkStateLabels = [
        'checking' => 'در حال بررسی',
        'passed' => 'موفق',
        'failed' => 'ناموفق',
        'manual_review' => 'بررسی دستی',
    ];
@endphp

@section('content')
<main class="max-w-3xl mx-auto px-4 py-6 md:py-10" dir="rtl">

    <a href="{{ route('rental.dashboard') }}"
       class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-brandBlue mb-3 transition-colors">
        <i class="fa-solid fa-arrow-right text-[10px]"></i> داشبورد اجاره
    </a>

    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-2xl bg-blue-50 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-shield-halved text-brandBlue"></i>
        </div>
        <div>
            <h1 class="text-lg md:text-2xl font-black text-gray-800">احراز هویت</h1>
            <p class="text-[11px] md:text-sm text-gray-500">
                برای اجاره دستگاه، احراز هویت و تأیید مالکیت حساب بانکی الزامی است.
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs md:text-sm px-4 py-3">
            <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
            <div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ─── Identity ─────────────────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
        <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
            <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-id-card text-gray-400"></i>
                اطلاعات هویتی
            </h2>
            @if ($identity)
                <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full
                    {{ $identity->isVerified() ? 'bg-emerald-50 text-emerald-700' : ($identity->state === IdentityState::Rejected ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">
                    {{ $identity->state->label() }}
                </span>
            @endif
        </header>

        <div class="p-5">
            @if ($identity && $identity->state === IdentityState::Rejected)
                <div class="mb-4 flex items-start gap-2 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs md:text-sm px-4 py-3">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
                    <div>
                        <p class="font-bold mb-1">احراز هویت شما رد شده است.</p>
                        @if ($identity->rejection_reason)
                            <p>دلیل رد: {{ $identity->rejection_reason }}</p>
                        @endif
                        <p class="mt-1">می‌توانید اطلاعات هویتی را در فرم زیر دوباره ثبت کنید.</p>
                    </div>
                </div>
            @endif

            @if ($identity && $identity->national_code_mask)
                <div class="flex items-center justify-between text-xs md:text-sm mb-4 bg-gray-50 rounded-xl px-3 py-2.5">
                    <span class="text-gray-500">کد ملی ثبت‌شده</span>
                    <span class="font-bold text-gray-800" dir="ltr">{{ $identity->national_code_mask }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('verification.identity.store') }}"
                  data-loading-label="در حال ثبت…" class="grid gap-3 md:grid-cols-2">
                @csrf
                <div>
                    <label for="national_code" class="block text-[11px] md:text-xs text-gray-500 mb-1">
                        کد ملی <span class="text-red-500">*</span>
                    </label>
                    <input id="national_code" type="text" name="national_code" inputmode="numeric" maxlength="10" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100"
                           value="{{ old('national_code') }}">
                </div>
                <div>
                    <label for="birth_date" class="block text-[11px] md:text-xs text-gray-500 mb-1">تاریخ تولد (میلادی)</label>
                    <input id="birth_date" type="date" name="birth_date" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100"
                           value="{{ old('birth_date', $identity?->birth_date?->format('Y-m-d')) }}">
                </div>
                <div class="md:col-span-2">
                    <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5 hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-floppy-disk"></i> ثبت اطلاعات هویتی
                    </button>
                </div>
            </form>
        </div>
    </section>

    {{-- ─── Checks ───────────────────────────────────────────────────── --}}
    @if ($identity)
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
            <header class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass text-gray-400"></i>
                    استعلام‌ها
                </h2>
            </header>

            <div class="p-5 space-y-3">
                @forelse ($identity->verifications as $check)
                    <div class="flex items-center justify-between text-xs md:text-sm border-b border-gray-50 pb-2 last:border-0 last:pb-0">
                        <span class="text-gray-600">{{ $checkTypeLabels[$check->type] ?? $check->type }}</span>
                        <span class="font-bold px-2.5 py-0.5 rounded-full text-[11px]
                            {{ $check->state === 'passed' ? 'bg-emerald-50 text-emerald-700' : ($check->state === 'failed' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">
                            {{ $checkStateLabels[$check->state] ?? $check->state }}
                        </span>
                    </div>
                @empty
                    <p class="text-xs md:text-sm text-gray-400">هنوز استعلامی انجام نشده است.</p>
                @endforelse

                <div class="flex flex-wrap gap-2 pt-2">
                    @foreach (['shahkar' => 'استعلام شاهکار', 'civil_registry' => 'استعلام ثبت احوال'] as $type => $label)
                        <form method="POST" action="{{ route('verification.identity.run') }}" data-loading-label="در حال استعلام…">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <button type="submit"
                                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold rounded-xl px-4 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ─── Identity documents ───────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-file-shield text-gray-400"></i>
                مدارک احراز هویت
            </h2>
        </header>

        <div class="p-5 space-y-4">
            @foreach ($mediaKinds as $kind)
                @php
                    $entry = $latestMediaByKind[$kind] ?? null;
                    $media = $entry['media'] ?? null;
                    $accept = implode(',', config('verification.media.allowed_mimes.'.$kind, []));
                    $maxMb = round((config('verification.media.max_size_kb.'.$kind, 0)) / 1024, 1);
                    $inputId = 'media-'.$kind;
                @endphp
                <div class="border border-gray-100 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2 gap-2">
                        <span class="text-xs md:text-sm font-bold text-gray-800">{{ $mediaLabels[$kind] }}</span>
                        <span class="text-[11px] md:text-xs font-bold px-3 py-1 rounded-full shrink-0
                            {{ $media?->state === MediaState::Ready ? 'bg-emerald-50 text-emerald-700' : ($media?->state === MediaState::Rejected ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">
                            {{ $media?->state->label() ?? 'بارگذاری نشده' }}
                        </span>
                    </div>

                    @if ($media?->state === MediaState::Rejected && $media->rejection_reason)
                        <p class="text-xs text-red-600 mb-2">
                            <i class="fa-solid fa-circle-exclamation"></i> دلیل رد: {{ $media->rejection_reason }}
                        </p>
                    @endif

                    @if ($entry['url'] ?? null)
                        <a href="{{ $entry['url'] }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-xs text-brandBlue font-bold hover:underline mb-3">
                            <i class="fa-solid fa-eye"></i> مشاهده فایل بارگذاری‌شده
                        </a>
                    @endif

                    <form method="POST" action="{{ route('verification.media.store') }}" enctype="multipart/form-data"
                          data-loading-label="در حال بارگذاری…"
                          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <label for="{{ $inputId }}" class="sr-only">{{ $mediaLabels[$kind] }}</label>
                        <input id="{{ $inputId }}" type="file" name="file" accept="{{ $accept }}"
                               class="flex-1 text-xs md:text-sm border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100">
                        <button type="submit"
                                class="inline-flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold rounded-xl px-4 py-2.5 whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i>
                            {{ $media ? 'بارگذاری مجدد' : 'بارگذاری' }}
                        </button>
                    </form>
                    <p class="text-[11px] text-gray-400 mt-1.5">حداکثر حجم مجاز: {{ $maxMb }} مگابایت</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ─── Bank accounts ────────────────────────────────────────────── --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm">
        <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm md:text-base font-bold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-building-columns text-gray-400"></i>
                حساب بانکی
            </h2>
        </header>

        <div class="p-5 space-y-4">
            @forelse ($user->bankAccounts as $account)
                <div class="flex items-center justify-between gap-2 text-xs md:text-sm border-b border-gray-50 pb-3 last:border-0 last:pb-0">
                    <div class="flex flex-col gap-1 min-w-0">
                        <span class="text-gray-500">{{ $account->type === 'card' ? 'کارت' : 'شبا' }}</span>
                        <span class="font-bold text-gray-800" dir="ltr">{{ $account->value_mask }}</span>
                        @if ($account->owner_name)
                            <span class="text-[11px] text-gray-400">به نام: {{ $account->owner_name }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-[11px] font-bold px-3 py-1 rounded-full
                            {{ $account->isVerified() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $account->state->label() }}
                        </span>
                        @unless ($account->isVerified())
                            <form method="POST" action="{{ route('verification.bank.verify', $account) }}" data-loading-label="در حال استعلام…">
                                @csrf
                                <button type="submit"
                                        class="text-brandBlue text-xs font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 rounded disabled:opacity-60 disabled:cursor-not-allowed">
                                    استعلام
                                </button>
                            </form>
                        @endunless
                    </div>
                </div>
            @empty
                <p class="text-xs md:text-sm text-gray-400">هنوز حسابی ثبت نشده است.</p>
            @endforelse

            <form method="POST" action="{{ route('verification.bank.store') }}" data-loading-label="در حال ثبت…" class="grid gap-3 md:grid-cols-3">
                @csrf
                <div>
                    <label for="bank_type" class="block text-[11px] md:text-xs text-gray-500 mb-1">نوع</label>
                    <select id="bank_type" name="type"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100">
                        <option value="card">شماره کارت</option>
                        <option value="iban">شماره شبا</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="bank_value" class="block text-[11px] md:text-xs text-gray-500 mb-1">
                        شماره <span class="text-red-500">*</span>
                    </label>
                    <input id="bank_value" type="text" name="value" dir="ltr"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-brandBlue focus:ring-2 focus:ring-blue-100">
                </div>
                <div class="md:col-span-3">
                    <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-brandBlue text-white text-sm font-bold rounded-xl px-6 py-2.5 hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-plus"></i> ثبت حساب
                    </button>
                </div>
            </form>
        </div>
    </section>
</main>
@endsection

@push('scripts')
<script>
    // Presentational only: disable a plain (non-AJAX) form's submit button and
    // show a loading label while the request is in flight. Every form on this
    // page redirects back with success/errors on completion, so there is
    // nothing to re-enable here.
    document.querySelectorAll('main form[data-loading-label]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + form.dataset.loadingLabel;
        });
    });
</script>
@endpush
