{{--
    Rental panel — the booking UI for a rentable product.

    Included by products/show.blade.php only when RentalItem::supports() is
    true, so ordinary shop products are completely unaffected and keep their
    existing quantity + add-to-cart box.

    A rental is priced by duration, not by quantity, so this replaces the
    quantity stepper rather than sitting beside it. The price shown here is a
    live preview computed in JS; per CLAUDE.md the backend stays the source of
    truth, and the same arithmetic exists authoritatively in
    RentalPricingService, which is what a reservation must be priced with.

    Requires: $product, $rental (RentalItem), $rentalQuote (RentalQuote),
    $rentalCalendar, $rentalSuggestion.
--}}
@php
    $statusLabels = [
        'available' => ['label' => 'آماده اجاره', 'class' => 'bg-green-100 text-green-700'],
        'pending' => ['label' => 'در انتظار تایید', 'class' => 'bg-amber-100 text-amber-700'],
        'reserved' => ['label' => 'رزرو شده', 'class' => 'bg-red-100 text-red-700'],
        'maintenance' => ['label' => 'در تعمیر', 'class' => 'bg-gray-200 text-gray-600'],
    ];
    $status = $statusLabels[$rental->status()] ?? $statusLabels['available'];
@endphp

<div id="rental-panel"
     class="mt-6"
     data-daily-rate="{{ $rental->dailyRate() }}"
     data-extra-controller="{{ $rental->extraControllerDaily() }}"
     data-delivery-fee="{{ $rental->deliveryFee() }}"
     data-deposit="{{ $rental->deposit() }}"
     data-discounts="{{ json_encode(config('rental.pricing.duration_discounts', [])) }}">

    {{-- Daily rate + status --}}
    <div class="flex flex-wrap items-center gap-3 mb-5">
        <span class="text-xs font-bold px-2.5 py-1 rounded-full {{ $status['class'] }}">{{ $status['label'] }}</span>
        @if($rental->rentalCount() > 0)
        <span class="text-xs text-gray-500">
            <i class="fa-solid fa-star text-amber-400"></i>
            {{ persian_number($rental->rating(), 1) }} · {{ persian_number($rental->rentalCount()) }} بار اجاره شده
        </span>
        @endif
    </div>

    <div class="flex items-end gap-2 mb-6">
        <span class="text-3xl font-black text-gray-900">{{ persian_number($rental->dailyRate()) }}</span>
        <span class="text-sm text-gray-500 mb-1">تومان / روز</span>
    </div>

    @if($rental->isRentable())
    {{-- Duration --}}
    <div class="mb-5">
        <p class="text-xs font-bold text-gray-600 mb-2">مدت اجاره</p>
        <div class="flex flex-wrap gap-2">
            @foreach([3, 7, 14, 30] as $d)
            <button type="button"
                    data-rental-days="{{ $d }}"
                    aria-pressed="{{ $d === 3 ? 'true' : 'false' }}"
                    class="rental-days-pill px-4 py-2 rounded-xl border text-xs md:text-sm font-medium transition-colors {{ $d === 3 ? 'border-brandBlue text-brandBlue bg-brandLightBlue' : 'border-gray-200 text-gray-700 hover:border-gray-300' }}">
                {{ persian_number($d) }} روز
                @php $rate = app(App\Services\RentalPricingService::class)->discountRateForDays($d); @endphp
                @if($rate > 0)
                <span class="text-[10px] text-green-600 font-bold">{{ persian_number((int) round($rate * 100)) }}٪ تخفیف</span>
                @endif
            </button>
            @endforeach
        </div>
    </div>

    {{-- Controllers --}}
    @if($rental->extraControllerAvailable())
    <div class="mb-5">
        <p class="text-xs font-bold text-gray-600 mb-2">تعداد دسته</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" data-rental-controllers="1" aria-pressed="true"
                    class="rental-ctrl-pill px-4 py-2 rounded-xl border text-xs md:text-sm font-medium border-brandBlue text-brandBlue bg-brandLightBlue transition-colors">
                {{ persian_number($rental->baseControllers()) }} دسته (پیش‌فرض)
            </button>
            <button type="button" data-rental-controllers="2" aria-pressed="false"
                    class="rental-ctrl-pill px-4 py-2 rounded-xl border text-xs md:text-sm font-medium border-gray-200 text-gray-700 hover:border-gray-300 transition-colors">
                ۲ دسته
                <span class="text-[10px] text-gray-500">+{{ persian_number($rental->extraControllerDaily()) }} / روز</span>
            </button>
        </div>
    </div>
    @endif

    {{-- Delivery --}}
    <div class="mb-5">
        <p class="text-xs font-bold text-gray-600 mb-2">روش تحویل</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" data-rental-delivery="courier" aria-pressed="true"
                    class="rental-delivery-pill px-4 py-2 rounded-xl border text-xs md:text-sm font-medium border-brandBlue text-brandBlue bg-brandLightBlue transition-colors">
                <i class="fa-solid fa-truck-fast"></i> پیک گیم‌پک
                <span class="text-[10px] text-gray-500">+{{ persian_number($rental->deliveryFee()) }}</span>
            </button>
            <button type="button" data-rental-delivery="pickup" aria-pressed="false"
                    class="rental-delivery-pill px-4 py-2 rounded-xl border text-xs md:text-sm font-medium border-gray-200 text-gray-700 hover:border-gray-300 transition-colors">
                <i class="fa-solid fa-store"></i> تحویل حضوری
                <span class="text-[10px] text-green-600">رایگان</span>
            </button>
        </div>
    </div>
    @else
    <div class="mb-5 rounded-xl bg-gray-100 border border-gray-200 p-4 text-sm text-gray-600">
        <i class="fa-solid fa-circle-info text-gray-400"></i>
        این دستگاه در حال حاضر قابل اجاره نیست.
        @if($rentalSuggestion)
        نزدیک‌ترین زمان آزاد: <span class="font-bold text-gray-800">{{ $rentalSuggestion['label'] }}</span>
        @endif
    </div>
    @endif
</div>

<script>
    // Live quote preview. This mirrors RentalPricingService::quote() exactly
    // -- same order of operations, same Math.round on the discount -- so the
    // figure a customer sees while choosing matches what the server computes.
    // It is a preview only: per CLAUDE.md the backend is the trusted source
    // for price and totals, so a reservation must be re-priced server-side
    // and never trust anything submitted from here.
    // Deferred to DOMContentLoaded because this script runs inline inside the
    // details column, before the desktop quote box (a later sibling in the
    // DOM) has been parsed -- the same ordering constraint the shop's
    // updateDisplayedPrice() documents.
    document.addEventListener('DOMContentLoaded', function () {
        const panel = document.getElementById('rental-panel');
        if (!panel) return;

        const dailyRate = +panel.dataset.dailyRate;
        const extraControllerDaily = +panel.dataset.extraController;
        const deliveryFee = +panel.dataset.deliveryFee;
        const deposit = +panel.dataset.deposit;
        const discountTiers = JSON.parse(panel.dataset.discounts || '{}');

        const state = { days: 3, controllers: 1, delivery: 'courier' };

        const faDigits = n =>
            Math.round(n).toLocaleString('en-US').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

        // Longest qualifying tier wins, matching
        // RentalPricingService::discountRateForDays().
        function discountRateForDays(days) {
            let rate = 0, best = -1;
            for (const [minDays, tierRate] of Object.entries(discountTiers)) {
                const min = +minDays;
                if (days >= min && min > best) { best = min; rate = +tierRate; }
            }
            return rate;
        }

        // Quote figures live in the desktop sidebar and the mobile sticky bar,
        // both outside #rental-panel, so these are looked up document-wide and
        // every matching target is updated.
        function set(name, value) {
            document.querySelectorAll('[data-quote="' + name + '"]')
                .forEach(el => { el.textContent = value; });
        }

        function row(name, show) {
            document.querySelectorAll('[data-quote-row="' + name + '"]')
                .forEach(el => el.classList.toggle('hidden', !show));
        }

        function render() {
            const base = dailyRate * state.days;
            const controller = state.controllers === 2 ? extraControllerDaily * state.days : 0;
            const subtotal = base + controller;
            const discountRate = discountRateForDays(state.days);
            const discount = Math.round(subtotal * discountRate);
            const delivery = state.delivery === 'courier' ? deliveryFee : 0;
            const payable = subtotal - discount + delivery;

            set('days', faDigits(state.days));
            set('base', faDigits(base));
            set('controller', faDigits(controller));
            set('discount-rate', faDigits(Math.round(discountRate * 100)));
            set('discount', faDigits(discount));
            set('delivery', faDigits(delivery));
            set('payable', faDigits(payable));
            set('deposit', faDigits(deposit));

            row('controller', controller > 0);
            row('discount', discount > 0);
        }

        // Pills are mutually exclusive within their own group, so selecting
        // one clears the styling from its siblings.
        function bind(selector, apply) {
            const pills = panel.querySelectorAll(selector);
            pills.forEach(pill => pill.addEventListener('click', () => {
                pills.forEach(p => {
                    p.classList.remove('border-brandBlue', 'text-brandBlue', 'bg-brandLightBlue');
                    p.classList.add('border-gray-200', 'text-gray-700');
                    p.setAttribute('aria-pressed', 'false');
                });
                pill.classList.remove('border-gray-200', 'text-gray-700');
                pill.classList.add('border-brandBlue', 'text-brandBlue', 'bg-brandLightBlue');
                pill.setAttribute('aria-pressed', 'true');
                apply(pill);
                render();
            }));
        }

        bind('.rental-days-pill', p => { state.days = +p.dataset.rentalDays; });
        bind('.rental-ctrl-pill', p => { state.controllers = +p.dataset.rentalControllers; });
        bind('.rental-delivery-pill', p => { state.delivery = p.dataset.rentalDelivery; });

        // The reservation flow has no backend yet (the rental domain is still
        // being designed), so the CTA states that plainly rather than leading
        // into a checkout that would price a rental as a shop purchase.
        document.querySelectorAll('.rental-reserve-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.rental-reserve-note')
                    .forEach(note => note.classList.remove('hidden'));
            });
        });

        render();
    });
</script>
