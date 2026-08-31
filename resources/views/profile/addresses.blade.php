@extends('layouts.app')

@section('title', 'گیم‌پک | آدرس‌های من')

@section('content')
<div class="max-w-[900px] mx-auto px-4 py-6 md:py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-xl font-black text-gray-800">{{ setting('profile.addresses_section_title', 'آدرس‌های من') }}</h1>
        <button onclick="showAddressModal()" class="bg-brandBlue text-white font-bold py-2.5 px-5 rounded-xl hover:bg-blue-600 transition-colors text-sm">
            <i class="fa-solid fa-plus ml-1"></i> افزودن آدرس
        </button>
    </div>

    @if($addresses->isEmpty())
    <div class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
        <i class="fa-solid fa-location-dot text-5xl text-gray-200 mb-4"></i>
        <p class="text-gray-500 text-sm mb-4">{{ setting('profile.address_empty_message', 'هیچ آدرسی ثبت نشده است.') }}</p>
        <button onclick="showAddressModal()" class="bg-brandBlue text-white font-bold py-2.5 px-6 rounded-xl hover:bg-blue-600 transition-colors text-sm">افزودن آدرس جدید</button>
    </div>
    @else
    <div class="flex flex-col gap-4">
        @foreach($addresses as $address)
        <div class="bg-white rounded-2xl p-5 shadow-sm border {{ $address->is_default ? 'border-brandBlue' : 'border-gray-100' }}">
            <div class="flex justify-between items-start">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="font-bold text-gray-800">{{ $address->receiver_name }}</span>
                        @if($address->is_default) <span class="text-xs bg-brandLightBlue text-brandBlue font-bold px-2 py-0.5 rounded-full">پیش‌فرض</span> @endif
                    </div>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $address->full_address }}</p>
                    <p class="text-xs text-gray-500 mt-1 font-mono" dir="ltr">{{ $address->receiver_mobile }}</p>
                </div>
                <div class="flex flex-col gap-2 mr-4 shrink-0">
                    @if(!$address->is_default)
                    <form action="{{ route('addresses.set-default', $address->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="text-xs text-gray-500 hover:text-brandBlue transition-colors whitespace-nowrap">پیش‌فرض کردن</button>
                    </form>
                    @endif
                    <button onclick="editAddress({{ $address->id }}, {{ json_encode($address) }})" class="text-xs text-brandBlue hover:underline">ویرایش</button>
                    <form action="{{ route('addresses.destroy', $address->id) }}" method="POST" onsubmit="return confirm('آیا از حذف این آدرس مطمئن هستید؟')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-500 hover:underline">حذف</button>
                    </form>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>

<!-- Address Modal -->
<div id="address-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl overflow-y-auto max-h-[90vh]">
        <div class="flex justify-between items-center p-5 border-b border-gray-100">
            <h3 id="modal-title" class="font-bold text-gray-800">افزودن آدرس جدید</h3>
            <button onclick="closeAddressModal()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500">✕</button>
        </div>
        <form id="address-form" class="p-5 flex flex-col gap-4">
            <input type="hidden" id="address-id">
            <input type="hidden" id="address-method" value="POST">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="text-xs text-gray-600 mb-1.5 block">نام گیرنده</label>
                    <input type="text" id="addr-receiver_name" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue" required>
                </div>
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block">موبایل گیرنده</label>
                    <input type="tel" id="addr-receiver_mobile" inputmode="numeric" maxlength="11" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue text-left" dir="ltr" required oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                </div>
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block">کد پستی</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" id="addr-postal_code" maxlength="10" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue text-left" dir="ltr" required oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                </div>
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block">استان</label>
                    <input type="text" id="addr-province" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue" required>
                </div>
                <div>
                    <label class="text-xs text-gray-600 mb-1.5 block">شهر</label>
                    <input type="text" id="addr-city" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue" required>
                </div>
                <div class="col-span-2">
                    <label class="text-xs text-gray-600 mb-1.5 block">آدرس کامل</label>
                    <textarea id="addr-address_line" rows="3" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue resize-none" required></textarea>
                </div>
                <div class="col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="addr-is_default" class="accent-brandBlue">
                        <span class="text-sm text-gray-700">به عنوان آدرس پیش‌فرض ذخیره شود</span>
                    </label>
                </div>
            </div>
            <div class="flex gap-3 mt-2">
                <button type="submit" class="flex-1 bg-brandBlue text-white font-bold py-3 rounded-xl hover:bg-blue-600 transition-colors text-sm">ذخیره آدرس</button>
                <button type="button" onclick="closeAddressModal()" class="px-5 border border-gray-200 text-gray-600 font-bold py-3 rounded-xl hover:bg-gray-50 transition-colors text-sm">لغو</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function showAddressModal(title = 'افزودن آدرس جدید') {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('address-modal').classList.remove('hidden');
    document.getElementById('address-modal').classList.add('flex');
}
function closeAddressModal() {
    document.getElementById('address-modal').classList.add('hidden');
    document.getElementById('address-modal').classList.remove('flex');
    document.getElementById('address-form').reset();
    document.getElementById('address-id').value = '';
    document.getElementById('address-method').value = 'POST';
}
function editAddress(id, data) {
    document.getElementById('address-id').value = id;
    document.getElementById('address-method').value = 'PATCH';
    ['receiver_name','receiver_mobile','postal_code','province','city','address_line'].forEach(f => {
        const el = document.getElementById('addr-'+f);
        if (el) el.value = data[f] ?? '';
    });
    document.getElementById('addr-is_default').checked = !!data.is_default;
    showAddressModal('ویرایش آدرس');
}
document.getElementById('address-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const id = document.getElementById('address-id').value;
    const method = document.getElementById('address-method').value;
    const url = id ? `/addresses/${id}` : '/addresses';
    const body = {
        receiver_name: document.getElementById('addr-receiver_name').value,
        receiver_mobile: document.getElementById('addr-receiver_mobile').value,
        postal_code: document.getElementById('addr-postal_code').value,
        province: document.getElementById('addr-province').value,
        city: document.getElementById('addr-city').value,
        address_line: document.getElementById('addr-address_line').value,
        is_default: document.getElementById('addr-is_default').checked,
        _method: method,
    };
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify(body)
    }).then(r=>r.json()).then(d=>{ showToast(d.message, d.success?'success':'error'); if(d.success) location.reload(); });
});
</script>
@endpush
