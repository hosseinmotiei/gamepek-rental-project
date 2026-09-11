{{--
    Rental quote box — the desktop sidebar CTA for a rentable product.

    Replaces the shop buy box (scalar price + quantity stepper + add to cart),
    which is the wrong control for a rental: a rental is priced by duration,
    and you take one device, not N of them.

    Figures are server-rendered from RentalPricingService so the first paint is
    authoritative; rental-panel.blade.php's script then keeps them in sync as
    the customer changes duration, controllers or delivery. Any actual
    reservation must be re-priced server-side -- this is a preview.

    Requires: $rental (RentalItem), $rentalQuote (RentalQuote).
--}}
<div class="border-t border-gray-200 pt-4 mb-4">
    <div class="flex items-end gap-1 mb-4">
        <span class="text-2xl font-black text-gray-900">{{ persian_number($rental->dailyRate()) }}</span>
        <span class="text-xs text-gray-600 mb-1">تومان / روز</span>
    </div>

    <dl class="flex flex-col gap-2 text-xs {{ $rental->isRentable() ? '' : 'hidden' }}">
        <div class="flex items-center justify-between">
            <dt class="text-gray-500">اجاره (<span data-quote="days">{{ persian_number($rentalQuote->days) }}</span> روز)</dt>
            <dd class="text-gray-800 font-medium"><span data-quote="base">{{ persian_number($rentalQuote->dailyRate * $rentalQuote->days) }}</span></dd>
        </div>

        <div class="flex items-center justify-between {{ $rentalQuote->extraControllerFee > 0 ? '' : 'hidden' }}" data-quote-row="controller">
            <dt class="text-gray-500">دسته دوم</dt>
            <dd class="text-gray-800 font-medium"><span data-quote="controller">{{ persian_number($rentalQuote->extraControllerFee) }}</span></dd>
        </div>

        <div class="flex items-center justify-between {{ $rentalQuote->discount > 0 ? '' : 'hidden' }}" data-quote-row="discount">
            <dt class="text-green-600">تخفیف مدت (<span data-quote="discount-rate">{{ persian_number((int) round($rentalQuote->discountRate * 100)) }}</span>٪)</dt>
            <dd class="text-green-600 font-medium">- <span data-quote="discount">{{ persian_number($rentalQuote->discount) }}</span></dd>
        </div>

        <div class="flex items-center justify-between" data-quote-row="delivery">
            <dt class="text-gray-500">هزینه ارسال</dt>
            <dd class="text-gray-800 font-medium"><span data-quote="delivery">{{ persian_number($rentalQuote->deliveryFee) }}</span></dd>
        </div>

        <div class="flex items-center justify-between border-t border-gray-200 pt-3 mt-1">
            <dt class="font-bold text-gray-800 text-sm">قابل پرداخت</dt>
            <dd class="font-black text-gray-900 text-lg">
                <span data-quote="payable">{{ persian_number($rentalQuote->payableNow) }}</span>
                <span class="text-xs font-medium text-gray-500">تومان</span>
            </dd>
        </div>

        <div class="flex items-center justify-between">
            <dt class="text-gray-500">ضمانت</dt>
            <dd class="text-gray-600">سفته فیزیکی</dd>
        </div>
    </dl>
</div>


@if($rental->isRentable())
<button type="button"
        class="rental-reserve-btn w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-colors shadow-lg shadow-blue-500/20 text-sm flex justify-center items-center gap-2">
    <i class="fa-solid fa-calendar-check"></i> ادامه رزرو
</button>
{{-- No delivery/return workflow exists yet, so this must not promise a
     collection or refund timeline -- only that nothing is charged now,
     which the reservation flow already guarantees (C-15/C-16). --}}
<p class="mt-2 text-[10px] text-gray-400 text-center">مبلغ کامل اجاره پرداخت می‌شود؛ ضمانت با سفته است و ودیعه نقدی ندارد.</p>
{{-- Online reservation is live: the CTA opens an application and reserves
     into it (see rental-panel.blade.php). --}}
@else
<button disabled class="w-full bg-gray-200 text-gray-400 font-bold py-3.5 rounded-xl cursor-not-allowed text-sm">
    فعلاً قابل اجاره نیست
</button>
@endif
