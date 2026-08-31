@extends('layouts.app')

@section('title', 'گیم‌پک | آدرس و زمان ارسال')

@push('styles')
<style>
    .address-selected { border-color: #0066FF !important; background-color: #F0F7FF; }
    .time-selected { border-color: #0066FF !important; color: #0066FF; }
</style>
@endpush

@section('content')

{{-- Mobile Header --}}
<div class="md:hidden sticky top-[104px] z-30 w-full bg-white border-b border-gray-100 flex items-center px-4 py-4 gap-3 shadow-sm">
    <a href="{{ route('cart.index') }}" class="text-gray-600 hover:text-brandBlue transition-colors">
        <i class="fa-solid fa-arrow-right text-lg"></i>
    </a>
    <h1 class="font-bold text-gray-800 text-base flex-1">{{ setting('checkout.shipping_page_title', 'آدرس و زمان ارسال') }}</h1>
</div>

{{-- Desktop Checkout Steps (shown inside content area) --}}
<div class="hidden md:flex items-center justify-center gap-4 py-4 mb-2 text-sm font-bold border-b border-gray-100 bg-white">
    <a href="{{ route('cart.index') }}" class="flex items-center gap-2 text-brandBlue hover:text-blue-700 transition-colors">
        <i class="fa-solid fa-cart-shopping"></i>
        <span>سبد خرید</span>
    </a>
    <div class="w-12 h-px bg-gray-300"></div>
    <div class="flex items-center gap-2 text-brandBlue">
        <i class="fa-solid fa-truck-fast"></i>
        <span>زمان و ارسال</span>
    </div>
    <div class="w-12 h-px bg-gray-300"></div>
    <div class="flex items-center gap-2 text-gray-400">
        <i class="fa-solid fa-credit-card"></i>
        <span>پرداخت</span>
    </div>
</div>

<div class="max-w-[1400px] mx-auto px-4 py-4 md:py-8 pb-32 md:pb-8">
    <div class="flex flex-col md:flex-row gap-6 items-start">

        {{-- Left: Shipping Details --}}
        <div class="flex-1 w-full flex flex-col gap-4">

            
            @if(true)

            {{-- Address Selection --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 shadow-sm">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="font-bold text-gray-800 flex items-center gap-2">
                        <i class="fa-solid fa-location-dot text-brandBlue"></i> آدرس تحویل
                    </h2>
                    <button onclick="toggleNewAddress()" class="flex items-center gap-1.5 text-xs text-brandBlue font-bold hover:text-blue-700 transition-colors">
                        <i class="fa-solid fa-plus"></i> آدرس جدید
                    </button>
                </div>

                @if($addresses->isNotEmpty())
                <div class="flex flex-col gap-3 mb-4" id="address-list">
                    @foreach($addresses as $address)
                    <div class="address-item flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all {{ $address->is_default ? 'address-selected' : 'border-gray-200 hover:border-gray-300' }}"
                         onclick="selectAddress({{ $address->id }}, this)">
                        <i class="fa-solid fa-location-dot {{ $address->is_default ? 'text-brandBlue' : 'text-gray-400' }} mt-1"></i>
                        <div class="flex flex-col gap-1 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm text-gray-800">{{ $address->receiver_name }}</span>
                                @if($address->is_default)
                                <span class="text-[10px] bg-brandBlue text-white px-2 py-0.5 rounded-full">پیش‌فرض</span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-600 leading-relaxed">{{ $address->full_address }}</p>
                            <p class="text-xs text-gray-500"><i class="fa-solid fa-phone ml-1"></i>{{ $address->receiver_mobile }}</p>
                        </div>
                        <input type="radio" name="shipping_address_id" value="{{ $address->id }}" {{ $address->is_default ? 'checked' : '' }} class="mt-1 accent-brandBlue hidden">
                        @if($address->is_default)
                        <i class="fa-solid fa-circle-check text-brandBlue text-lg shrink-0"></i>
                        @endif
                    </div>
                    @endforeach
                </div>
                @else
                <p class="text-sm text-gray-500 mb-4">هنوز آدرسی ثبت نکرده‌اید.</p>
                @endif

                {{-- New Address Form --}}
                <div id="new-address-form" class="hidden mt-4 p-4 bg-gray-50 rounded-xl border border-gray-200">
                    <h4 class="font-bold text-gray-800 text-sm mb-4">آدرس جدید</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="text-xs text-gray-600 mb-1 block">نام گیرنده</label><input type="text" id="new-receiver-name" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue" placeholder="نام و نام خانوادگی"></div>
                        <div><label class="text-xs text-gray-600 mb-1 block">موبایل گیرنده</label><input type="tel" id="new-receiver-mobile" inputmode="numeric" maxlength="11" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue text-left" dir="ltr" placeholder="09xxxxxxxxx" oninput="this.value=this.value.replace(/[^0-9]/g,'')"></div>
                        <div><label class="text-xs text-gray-600 mb-1 block">استان</label><input type="text" id="new-province" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm bg-gray-50 text-gray-500 cursor-not-allowed" value="تهران" readonly></div>
                        <div><label class="text-xs text-gray-600 mb-1 block">شهر</label><input type="text" id="new-city" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm bg-gray-50 text-gray-500 cursor-not-allowed" value="تهران" readonly></div>
                        <div class="sm:col-span-2"><label class="text-xs text-gray-600 mb-1 block">آدرس کامل</label><textarea id="new-address" rows="2" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue resize-none" placeholder="خیابان، کوچه، پلاک..."></textarea></div>
                        <div><label class="text-xs text-gray-600 mb-1 block">پلاک</label><input type="text" id="new-plaque" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue" placeholder="۱۲"></div>
                        <div><label class="text-xs text-gray-600 mb-1 block">کدپستی</label><input type="text" inputmode="numeric" pattern="[0-9]*" id="new-postal" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue text-left" dir="ltr" maxlength="10" placeholder="1234567890" oninput="this.value=this.value.replace(/[^0-9]/g,'')"></div>
                    </div>
                    <div class="flex gap-3 mt-3">
                        <button onclick="saveNewAddress()" class="bg-brandBlue text-white font-bold text-sm py-2.5 px-6 rounded-xl hover:bg-blue-600 transition-colors">ذخیره آدرس</button>
                        <button onclick="toggleNewAddress()" class="border border-gray-200 text-gray-600 text-sm font-bold py-2.5 px-4 rounded-xl hover:bg-gray-50 transition-colors">انصراف</button>
                    </div>
                </div>
            </div>

            {{-- Shipping Methods --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 shadow-sm">
                <div class="flex items-center gap-3 border-b border-gray-100 pb-4 mb-4">
                    <div class="w-12 h-12 bg-blue-50 text-brandBlue rounded-xl flex items-center justify-center text-xl">
                        <i class="fa-solid fa-truck-fast"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-800 text-sm md:text-base">روش ارسال</h3>
                        <span class="text-xs text-gray-500">موجود در انبار گیم‌پک</span>
                    </div>
                </div>

                {{-- Cart Item Thumbnails --}}
                <div class="flex gap-3 overflow-x-auto hide-scrollbar mb-5">
                    @foreach($summary['items'] as $item)
                    @php $product = $item->product; @endphp
                    @continue(!$product)
                    <div class="w-20 h-20 bg-gray-50 rounded-xl flex items-center justify-center shrink-0 border border-gray-100 p-2 relative">
                        <span class="absolute bottom-1 right-1 bg-gray-200 text-gray-700 text-[10px] font-bold px-1.5 rounded-sm">{{ persian_number($item->quantity) }}</span>
                        <img src="{{ media_url($product->main_image, 'https://placehold.co/100x100/F5F5F5/111?text=P') }}"
                             class="w-full h-full object-contain mix-blend-multiply rounded-xl">
                    </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-3">
                    @foreach($shippingMethods as $method)
                    <label class="flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all border-gray-200 hover:border-brandBlue shipping-method-option">
                        <input type="radio" name="shipping_method_id" value="{{ $method->id }}" {{ $loop->first ? 'checked' : '' }} class="accent-brandBlue">
                        <div class="flex-1">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-sm text-gray-800">{{ $method->title_fa }}</span>
                                <span class="text-sm font-bold text-{{ $method->base_cost > 0 ? 'gray-800' : 'green-600' }}">
                                    {{ $method->base_cost > 0 ? persian_number($method->base_cost).' تومان' : 'رایگان' }}
                                </span>
                            </div>
                            @if($method->estimated_delivery_text)
                            <p class="text-xs text-gray-500 mt-1">{{ $method->estimated_delivery_text }}</p>
                            @endif
                        </div>
                    </label>
                    @endforeach
                </div>

                <p class="text-[10px] text-gray-500 mt-3"><i class="fa-solid fa-circle-info text-blue-400 ml-1"></i> ساعت تحویل بین بازه ۹ صبح تا ۲۱ شب می‌باشد.</p>
            </div>

            @endif

            {{-- Note --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 shadow-sm">
                <h3 class="font-bold text-gray-800 mb-3 text-sm">یادداشت سفارش <span class="text-gray-400 font-normal">(اختیاری)</span></h3>
                <textarea id="customer-note" rows="3"
                          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm outline-none focus:bg-white focus:border-brandBlue resize-none transition-all"
                          placeholder="توضیحاتی برای سفارش..."></textarea>
            </div>
        </div>

        {{-- Desktop Order Summary --}}
        <div class="hidden md:block w-[360px] shrink-0 sticky top-[140px]">
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm flex flex-col gap-4">
                @foreach($summary['items'] as $item)
                @php $product = $item->product; @endphp
                @continue(!$product)
                <div class="flex gap-3 border-b border-gray-50 pb-3 last:border-0 last:pb-0">
                    <img src="{{ media_url($product->main_image, 'https://placehold.co/60x60/F5F5F5/111?text=P') }}"
                         class="w-12 h-12 object-contain bg-gray-50 rounded-xl shrink-0 mix-blend-multiply">
                    <div class="flex-1">
                        <p class="text-xs font-bold text-gray-800 line-clamp-2">{{ $product->title_fa }}</p>
                        <div class="flex justify-between mt-1">
                            <span class="text-[10px] text-gray-400">x{{ persian_number($item->quantity) }}</span>
                            <span class="text-xs font-bold">{{ persian_number($item->line_total) }} ت</span>
                        </div>
                    </div>
                </div>
                @endforeach

                <div class="w-full h-px bg-gray-100"></div>

                <div class="flex justify-between items-center text-sm text-gray-600">
                    <span>قیمت کالاها ({{ persian_number($summary['items']->count()) }})</span>
                    <span>{{ persian_number($summary['subtotal']) }} <span class="text-[10px]">تومان</span></span>
                </div>

                @if(true)
                <div class="flex justify-between items-center text-sm text-gray-600">
                    <span>هزینه ارسال</span>
                    <span id="shipping-cost-display" class="text-green-600 font-bold text-xs">انتخاب روش ارسال</span>
                </div>
                @endif

                @if($summary['discount'] > 0)
                <div class="flex justify-between items-center text-sm text-flashRed">
                    <span>سود شما از خرید</span>
                    <span>({{ persian_number($summary['discount']) }}) <span class="text-[10px]">تومان</span></span>
                </div>
                @endif

                <div class="w-full h-px bg-gray-100"></div>

                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-gray-800">مبلغ قابل پرداخت</span>
                    <span class="text-xl font-black text-brandDark">{{ persian_number($summary['subtotal'] - $summary['discount']) }} <span class="text-[11px] font-normal text-gray-500">تومان</span></span>
                </div>

                <button onclick="placeOrder()" id="continue-btn-desktop" class="js-continue-btn w-full bg-brandBlue text-white font-bold text-sm py-4 rounded-xl shadow-lg shadow-blue-500/20 hover:bg-blue-600 transition-colors flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-brandBlue"
                    {{ !$addresses->isNotEmpty() ? 'disabled' : '' }}>
                    پرداخت و ثبت نهایی
                </button>

                <div class="flex items-center justify-between bg-yellow-50/50 p-3 rounded-xl border border-yellow-100">
                    <div class="flex items-center gap-2 text-xs font-bold text-yellow-600">
                        <i class="fa-solid fa-star text-yellow-500"></i> امتیاز گیم‌پک کلاب
                    </div>
                    <span class="text-xs font-bold text-yellow-700">+امتیاز</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Mobile Fixed Bottom Bar --}}
<div class="md:hidden fixed bottom-[70px] left-0 w-full bg-white border-t border-gray-200 z-40 shadow-[0_-4px_15px_rgba(0,0,0,0.05)]">
    <div class="flex items-center gap-3 p-3">
        <div class="flex flex-col items-end flex-1 min-w-0">
            <span class="text-[10px] text-gray-500 mb-0.5">مبلغ قابل پرداخت</span>
            <span class="text-sm font-black text-brandDark truncate">{{ persian_number($summary['subtotal'] - $summary['discount']) }} <span class="text-[10px] font-normal">تومان</span></span>
        </div>
        <button onclick="placeOrder()" id="continue-btn-mobile" class="js-continue-btn bg-brandBlue text-white text-sm font-bold py-3 px-6 rounded-xl shadow-lg shadow-blue-500/20 hover:bg-blue-600 transition-colors shrink-0 whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-brandBlue"
            {{ !$addresses->isNotEmpty() ? 'disabled' : '' }}>
            پرداخت و ثبت
        </button>
    </div>
</div>

@endsection

@push('scripts')
<script>
// throughout order history).
let selectedAddressId = {{ $addresses->where('is_default', true)->first()?->id ?? ($addresses->first()?->id ?? 'null') }};

function updateContinueButtonState(hasAddress) {
    document.querySelectorAll('.js-continue-btn').forEach(btn => { btn.disabled = !hasAddress; });
}

function selectAddress(id, el) {
    selectedAddressId = id;
    updateContinueButtonState(true);
    document.querySelectorAll('.address-item').forEach(item => {
        item.classList.remove('address-selected');
        item.classList.add('border-gray-200');
        const check = item.querySelector('.fa-circle-check');
        if (check) check.classList.add('hidden');
        const icon = item.querySelector('.fa-location-dot');
        if (icon) { icon.classList.remove('text-brandBlue'); icon.classList.add('text-gray-400'); }
        const radio = item.querySelector('input[type="radio"]');
        if (radio) radio.checked = false;
    });
    el.classList.add('address-selected');
    el.classList.remove('border-gray-200');
    const check = el.querySelector('.fa-circle-check');
    if (check) check.classList.remove('hidden');
    const icon = el.querySelector('.fa-location-dot');
    if (icon) { icon.classList.add('text-brandBlue'); icon.classList.remove('text-gray-400'); }
    const radio = el.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
}

function toggleNewAddress() {
    const form = document.getElementById('new-address-form');
    form.classList.toggle('hidden');
    if (!form.classList.contains('hidden')) form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function normalizeDigits(value) {
    return String(value || '')
        .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
        .replace(/[^0-9]/g, '');
}

function saveNewAddress() {
    const btn = document.querySelector('button[onclick="saveNewAddress()"]');
    if (btn) { btn.disabled = true; btn.textContent = 'در حال ذخیره...'; }
    const data = {
        receiver_name: document.getElementById('new-receiver-name').value,
        receiver_mobile: normalizeDigits(document.getElementById('new-receiver-mobile').value),
        province: 'تهران',
        city: 'تهران',
        address_line: document.getElementById('new-address').value,
        plaque: document.getElementById('new-plaque').value,
        postal_code: normalizeDigits(document.getElementById('new-postal').value),
    };
    fetch('/addresses', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => r.json().then(d => ({ status: r.status, body: d }))).then(({ status, body }) => {
        if (status === 200 || status === 201 || body.success) {
            showToast(body.message || 'آدرس ذخیره شد.', 'success');
            if (body.address?.id) { selectedAddressId = body.address.id; updateContinueButtonState(true); }
            setTimeout(() => location.reload(), 800);
        } else {
            const errors = body.errors ? Object.values(body.errors).flat().join(' ') : '';
            showToast(errors || body.message || 'خطا در ذخیره آدرس', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'ذخیره آدرس'; }
        }
    }).catch(() => {
        showToast('خطای شبکه. لطفاً مجدداً تلاش کنید.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'ذخیره آدرس'; }
    });
}

document.querySelectorAll('input[name="shipping_method_id"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const costEl = this.closest('label').querySelector('.text-gray-800, .text-green-600');
        if (costEl) document.getElementById('shipping-cost-display').textContent = costEl.textContent;
    });
});

function placeOrder() {
    const addressId = selectedAddressId;
    const methodId = document.querySelector('input[name="shipping_method_id"]:checked')?.value;
    const note = document.getElementById('customer-note')?.value || '';
    fetch('/checkout/place-order', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ shipping_address_id: addressId || null, shipping_method_id: methodId || null, customer_note: note })
    }).then(r=>r.json()).then(d=>{
        if (d.success) { window.location.href = d.redirect; }
        else { showToast(d.message, 'error'); }
    });
}
</script>
@endpush
