@extends('layouts.app')

@section('title', 'گیم‌پک | پرداخت')

@section('content')
<div class="max-w-[600px] mx-auto px-4 py-12">
    <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
        <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fa-solid fa-lock text-brandBlue text-3xl"></i>
        </div>
        <h2 class="text-xl font-black text-gray-800 mb-2">پرداخت امن</h2>
        <p class="text-sm text-gray-500 mb-2">سفارش: <span class="font-bold text-gray-800">{{ $order->order_number }}</span></p>
        <p class="text-xs text-gray-400 mb-8">مبلغ قابل پرداخت:</p>
        <div class="text-3xl font-black text-brandDark mb-2">{{ persian_number($order->total) }}</div>
        <p class="text-sm text-gray-500 mb-8">تومان</p>

        @if(config('rental.payment.gateway') === 'mock')
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-3 mb-6 text-sm text-yellow-800">
            <i class="fa-solid fa-triangle-exclamation ml-1"></i>
            این درگاه پرداخت تستی (Mock) است. در محیط واقعی با درگاه بانکی جایگزین می‌شود.
        </div>
        @endif

        <button id="pay-btn" onclick="startPayment()" class="w-full bg-brandBlue text-white font-bold py-4 rounded-xl hover:bg-blue-600 transition-colors shadow-lg shadow-blue-500/20 text-base">
            <i class="fa-solid fa-credit-card ml-2"></i> پرداخت آنلاین
        </button>
        <a href="{{ route('checkout.shipping') }}" class="block mt-3 text-sm text-gray-500 hover:text-gray-700 transition-colors">بازگشت به مرحله قبل</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
function startPayment() {
    const btn = document.getElementById('pay-btn');
    btn.disabled = true; btn.textContent = 'در حال اتصال به درگاه...';
    fetch('/checkout/payment/{{ $order->id }}/start', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }
    }).then(r=>r.json()).then(d=>{
        if(d.success){ window.location.href = d.redirect_url; }
        else { showToast(d.message,'error'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-credit-card ml-2"></i> پرداخت آنلاین'; }
    }).catch(()=>{
        showToast('خطا در ارتباط با سرور. دوباره تلاش کنید.','error');
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-credit-card ml-2"></i> پرداخت آنلاین';
    });
}
</script>
@endpush

