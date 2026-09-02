{{--
    Rental detail sections — availability calendar, health breakdown, package
    contents, deposit terms and condition facts.

    Rendered below the main product columns by products/show.blade.php, only
    for rentable products. Split from rental-panel.blade.php because these are
    read-only disclosure sections: the panel is the booking control, this is
    the evidence a customer needs to trust a used device.

    Requires: $rental (RentalItem), $rentalCalendar, $rentalSuggestion.
--}}
@php
    use App\Support\Rental\Availability;

    $health = $rental->health();
    $included = $rental->included();
    $games = $rental->games();
    $facts = $rental->conditionFacts();

    // Day status is otherwise conveyed by cell colour alone.
    $dayLabels = [
        Availability::KIND_AVAILABLE => 'آزاد',
        Availability::KIND_RESERVED => 'رزرو شده',
        Availability::KIND_PENDING => 'در انتظار تایید',
        Availability::KIND_PAST => 'گذشته',
    ];

    $dayClasses = [
        Availability::KIND_AVAILABLE => 'bg-white text-gray-700 border-gray-200',
        Availability::KIND_RESERVED => 'bg-red-50 text-red-400 border-red-100 line-through',
        Availability::KIND_PENDING => 'bg-amber-50 text-amber-500 border-amber-100',
        Availability::KIND_PAST => 'bg-gray-50 text-gray-300 border-gray-100',
    ];
@endphp

{{-- Availability calendar --}}
<section class="mt-8 bg-white rounded-2xl border border-gray-200 p-5 lg:max-w-lg">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="font-bold text-gray-800">تقویم موجودی</h2>
        <div class="flex flex-wrap items-center gap-3 text-[11px] text-gray-500">
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded border border-gray-200 bg-white"></span> آزاد</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded border border-red-100 bg-red-50"></span> رزرو شده</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded border border-amber-100 bg-amber-50"></span> در انتظار</span>
        </div>
    </div>

    <div class="grid grid-cols-7 gap-1 text-center text-[10px] text-gray-400 mb-1">
        @foreach(['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $wd)
        <div class="py-1">{{ $wd }}</div>
        @endforeach
    </div>

    <div class="grid grid-cols-7 gap-1">
        @foreach($rentalCalendar['lead'] as $ignored)
        <div></div>
        @endforeach
        @foreach($rentalCalendar['days'] as $day)
        <div class="h-10 flex items-center justify-center rounded-lg border text-xs {{ $dayClasses[$day['kind']] ?? $dayClasses[Availability::KIND_AVAILABLE] }}"
             title="{{ $day['label'] }} — {{ $dayLabels[$day['kind']] ?? '' }}"
             aria-label="{{ $day['label'] }} — {{ $dayLabels[$day['kind']] ?? '' }}">
            {{ $day['dayNumber'] }}
        </div>
        @endforeach
    </div>

    <p class="mt-3 text-xs text-gray-500">
        {{ $rentalCalendar['monthLabel'] }}
        @if($rentalSuggestion)
        · نزدیک‌ترین بازه آزاد: <span class="font-bold text-gray-700">{{ $rentalSuggestion['label'] }}</span>
        @endif
    </p>
</section>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    {{-- Health --}}
    @if($health)
    <section class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-gray-800">سلامت دستگاه</h2>
            <span class="text-sm font-black text-brandBlue">{{ persian_number($rental->healthScore()) }}<span class="text-xs text-gray-400"> / ۱۰۰</span></span>
        </div>
        <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden mb-4">
            <div class="h-full bg-brandBlue rounded-full" style="width: {{ max(0, min(100, $rental->healthScore())) }}%"></div>
        </div>
        <dl class="grid gap-2 sm:grid-cols-2">
            @foreach($health as $label => $value)
            <div class="rounded-lg bg-gray-50 px-3 py-2">
                <dt class="text-[11px] text-gray-500">{{ $label }}</dt>
                <dd class="mt-0.5 text-sm text-gray-800">{{ $value }}</dd>
            </div>
            @endforeach
        </dl>
    </section>
    @endif

    {{-- Package contents --}}
    @if($included)
    <section class="bg-white rounded-2xl border border-gray-200 p-5">
        <h2 class="font-bold text-gray-800 mb-4">محتویات بسته</h2>
        <ul class="flex flex-col gap-2">
            @foreach($included as $item)
            <li class="flex items-start gap-2 text-sm {{ $item['included'] ? 'text-gray-700' : 'text-gray-400' }}">
                <i class="fa-solid {{ $item['included'] ? 'fa-circle-check text-green-500' : 'fa-circle-xmark text-gray-300' }} mt-0.5"></i>
                <span>
                    {{ $item['label'] }}
                    @if($item['note'])
                    <span class="text-[11px] text-gray-400">— {{ $item['note'] }}</span>
                    @endif
                </span>
            </li>
            @endforeach
        </ul>
    </section>
    @endif
</div>

{{-- Games --}}
@if($games)
<section class="mt-4 bg-white rounded-2xl border border-gray-200 p-5">
    <h2 class="font-bold text-gray-800 mb-4">بازی‌های قابل انتخاب</h2>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($games as $game)
        <div class="rounded-xl border border-gray-200 p-3 {{ $game['in_stock'] ? '' : 'opacity-50' }}">
            <p class="text-sm font-medium text-gray-800 leading-relaxed">{{ $game['title'] }}</p>
            <p class="mt-1 text-[11px] text-gray-500">
                {{ $game['kind'] === 'disc' ? 'دیسک فیزیکی' : 'نصب‌شده' }}
            </p>
            <p class="mt-2 text-xs font-bold {{ $game['free'] ? 'text-green-600' : 'text-gray-700' }}">
                {{ $game['free'] ? 'رایگان همراه اجاره' : persian_number($game['extra_fee']) . ' تومان' }}
            </p>
            @unless($game['in_stock'])
            <p class="mt-1 text-[10px] text-red-500">ناموجود</p>
            @endunless
        </div>
        @endforeach
    </div>
</section>
@endif

{{-- Deposit --}}
<section class="mt-4 bg-brandLightBlue rounded-2xl p-5">
    <h2 class="font-bold text-gray-800">ضمانت / ودیعه</h2>
    <p class="mt-2 text-xl font-black text-gray-900">{{ persian_number($rental->deposit()) }} <span class="text-sm font-medium text-gray-500">تومان</span></p>
    <p class="mt-2 text-sm leading-7 text-gray-600">
        مبلغ ودیعه هنگام تحویل دستگاه دریافت و پس از پایان دوره اجاره و بررسی سلامت دستگاه، طبق قوانین اجاره بازگردانده می‌شود.
        این مبلغ بخشی از هزینه اجاره نیست.
    </p>
</section>

{{-- Condition facts --}}
@if($facts)
<section class="mt-4 bg-white rounded-2xl border border-gray-200 p-5">
    <h2 class="font-bold text-gray-800">وضعیت واقعی دستگاه</h2>
    <dl class="mt-4 grid gap-2 sm:grid-cols-2">
        @foreach($facts as $label => $value)
        <div class="rounded-lg bg-gray-50 px-3 py-2">
            <dt class="text-[11px] text-gray-500">{{ $label }}</dt>
            <dd class="mt-0.5 text-sm text-gray-800">{{ $value }}</dd>
        </div>
        @endforeach
    </dl>
</section>
@endif
