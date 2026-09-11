{{--
    Rental search bar: شهر | از تاریخ | تا تاریخ | جستجو

    One component. The home hero, the results page and the catalog listing all
    include this same file — there is no second copy to drift.

    Dates are picked from the Jalali calendar in partials/jalali-datepicker,
    which is Jalali in its logic, not just its labels. The visible field shows
    the Persian date and is readonly (nothing is typed by hand); the hidden
    field carries the Gregorian ISO date the backend parses.

    It is a plain GET form to the existing `products.search` route — no new
    route — so the parameters land in the URL (`/search?city=…&from=…&to=…`)
    and a result page stays shareable and re-runnable.

    Server-side validation in ProductController::rentalWindow() is the
    authority; the JS here only saves a round trip.

    @param string|null $city, $from, $to   currently applied values (optional)
    @param string|null $variant            unique per page instance
--}}
@php
    $cities = (array) config('rental.search.cities', []);
    $city = $city ?? request('city');
    $from = $from ?? request('from');
    $to = $to ?? request('to');
    $variant = $variant ?? 'hero';
    $today = now()->toDateString();
    $formId = 'rental-search-'.$variant;
@endphp

@include('partials.jalali-datepicker')

<form method="GET" action="{{ route('products.search') }}" id="{{ $formId }}" novalidate
      class="w-full bg-white rounded-2xl shadow-lg border border-gray-100 p-3 {{ $variant === 'hero' ? 'md:rounded-[26px]' : '' }}">
    <div class="flex flex-col md:flex-row md:items-stretch gap-2 md:gap-0">

        {{-- شهر --}}
        <label class="flex items-center gap-3 flex-1 min-w-0 px-3 py-2.5 rounded-xl md:rounded-none hover:bg-gray-50 md:hover:bg-transparent transition-colors cursor-pointer">
            <i class="fa-solid fa-location-dot text-brandBlue text-lg shrink-0"></i>
            <span class="flex flex-col min-w-0 flex-1">
                <span class="text-[10px] text-gray-400 mb-0.5">شهر یا مقصد</span>
                <select name="city" required
                        class="w-full bg-transparent text-sm font-bold text-gray-800 outline-none cursor-pointer">
                    <option value="">انتخاب شهر</option>
                    @foreach($cities as $option)
                        <option value="{{ $option }}" {{ $city === $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
            </span>
        </label>

        <span class="hidden md:block w-px bg-gray-200 my-2 shrink-0"></span>

        {{-- از تاریخ (شمسی) --}}
        <div class="relative flex items-center gap-3 flex-1 min-w-0 px-3 py-2.5 rounded-xl md:rounded-none hover:bg-gray-50 md:hover:bg-transparent transition-colors"
             data-jdp data-jdp-min="{{ $today }}" data-jdp-role="from">
            <i class="fa-regular fa-calendar text-brandBlue text-lg shrink-0"></i>
            <span class="flex flex-col min-w-0 flex-1">
                <span class="text-[10px] text-gray-400 mb-0.5">از تاریخ</span>
                <input type="hidden" name="from" value="{{ $from }}">
                <input type="text" readonly data-jdp-display aria-label="از تاریخ" placeholder="انتخاب تاریخ"
                       class="w-full bg-transparent text-sm font-bold text-gray-800 outline-none cursor-pointer">
            </span>
        </div>

        <span class="hidden md:block w-px bg-gray-200 my-2 shrink-0"></span>

        {{-- تا تاریخ (شمسی) --}}
        <div class="relative flex items-center gap-3 flex-1 min-w-0 px-3 py-2.5 rounded-xl md:rounded-none hover:bg-gray-50 md:hover:bg-transparent transition-colors"
             data-jdp data-jdp-min="{{ $today }}" data-jdp-role="to">
            <i class="fa-regular fa-calendar-check text-brandBlue text-lg shrink-0"></i>
            <span class="flex flex-col min-w-0 flex-1">
                <span class="text-[10px] text-gray-400 mb-0.5">تا تاریخ</span>
                <input type="hidden" name="to" value="{{ $to }}">
                <input type="text" readonly data-jdp-display aria-label="تا تاریخ" placeholder="انتخاب تاریخ"
                       class="w-full bg-transparent text-sm font-bold text-gray-800 outline-none cursor-pointer">
            </span>
        </div>

        {{-- جستجو --}}
        <button type="submit"
                class="shrink-0 flex items-center justify-center gap-2 bg-brandBlue hover:bg-blue-600 disabled:opacity-60 text-white font-bold text-sm px-6 py-3.5 rounded-xl transition-colors shadow-md shadow-blue-500/20 md:mr-2">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span data-search-label>جستجو</span>
        </button>
    </div>

    <p data-search-error class="hidden mt-2 px-3 text-xs text-red-600"></p>
</form>

@once
@push('scripts')
<script>
    // Range behaviour + client-side guard for every rental search bar on the
    // page. The server re-validates the same rules, so this is convenience.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[id^="rental-search-"]').forEach(form => {
            const fromRoot = form.querySelector('[data-jdp-role="from"]');
            const toRoot = form.querySelector('[data-jdp-role="to"]');
            const fromInput = form.querySelector('input[name="from"]');
            const toInput = form.querySelector('input[name="to"]');
            const errorBox = form.querySelector('[data-search-error]');
            const button = form.querySelector('button[type="submit"]');
            const label = form.querySelector('[data-search-label]');

            const showError = message => {
                errorBox.textContent = message;
                errorBox.classList.remove('hidden');
            };
            const clearError = () => {
                errorBox.textContent = '';
                errorBox.classList.add('hidden');
            };

            // The end date is not merely validated against the start — days
            // before it are not offerable at all. Its floor is the start date
            // ITSELF, not the day after: the window is inclusive on both ends,
            // so from == to is a one-day rental, which C-04 makes the shortest
            // rental GamePek sells. This used to floor at start + 1, which made
            // the shortest real rental unselectable.
            //
            // This mirrors the server rule in ProductController::rentalWindow()
            // for convenience only. The server is the authority and re-checks.
            function syncEndFloor() {
                if (!fromInput.value || !toRoot.__jdp) return;
                toRoot.__jdp.setMin(fromInput.value);
            }

            fromInput.addEventListener('change', () => { syncEndFloor(); clearError(); });
            toInput.addEventListener('change', clearError);
            syncEndFloor();

            form.addEventListener('submit', e => {
                const city = form.querySelector('select[name="city"]').value;

                if (!city) { e.preventDefault(); showError('لطفاً شهر یا مقصد را انتخاب کنید.'); return; }
                if (!fromInput.value) { e.preventDefault(); showError('تاریخ شروع اجاره را انتخاب کنید.'); fromRoot.__jdp?.open(); return; }
                if (!toInput.value) { e.preventDefault(); showError('تاریخ پایان اجاره را انتخاب کنید.'); toRoot.__jdp?.open(); return; }
                if (toInput.value < fromInput.value) {
                    e.preventDefault();
                    showError('تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.');
                    return;
                }

                clearError();
                button.disabled = true;
                label.textContent = 'در حال جستجو…';
            });

            // Restore the button if the visitor returns via the bfcache.
            window.addEventListener('pageshow', () => {
                button.disabled = false;
                label.textContent = 'جستجو';
            });
        });
    });
</script>
@endpush
@endonce
