<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ setting('general.brand_name_fa', 'گیم‌پک') }} | {{ setting('auth.complete_profile_title', 'تکمیل اطلاعات حساب') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logos/logo-icon.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>tailwind.config = { theme: { extend: { colors: { brandBlue: '#0066FF', brandDark: '#111111', brandGray: '#F5F5F5' }, fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } } } }</script>
    <style>
        body { font-family: 'Vazirmatn', sans-serif; background-color: #F5F5F5; color: #111111; }
        @media (max-width: 768px) { body { background-color: #FFFFFF; } }
        .peer:focus ~ label, .peer:not(:placeholder-shown) ~ label { top: 0.5rem; font-size: 0.65rem; }
        .peer:not(.error-border):focus ~ label { color: #0066FF; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">

    <div id="toast-container" class="fixed top-6 left-6 z-[60] flex flex-col gap-3 pointer-events-none"></div>

    <main class="w-full max-w-[420px] bg-white h-screen md:h-auto md:rounded-2xl md:border border-gray-200 md:shadow-sm flex flex-col overflow-hidden relative">

        <header class="flex items-center justify-between px-6 pt-6 pb-4">
            <div class="w-6 h-6"></div>
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logos/logo-horizontal.png') }}" alt="GamePek" class="h-9 w-auto" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                <span class="hidden text-2xl font-black tracking-tight text-brandDark">گیم‌<span class="text-brandBlue">پک</span></span>
            </a>
            <a aria-label="بازگشت به صفحه اصلی" href="{{ route('home') }}" class="w-8 h-8 flex items-center justify-center text-gray-800 hover:bg-gray-100 rounded-full transition-colors">
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </header>

        <section class="flex-1 flex flex-col px-6 py-4">
            <h1 class="text-[17px] font-bold text-gray-800 mb-2 mt-4">{{ setting('auth.complete_profile_title', 'تکمیل اطلاعات حساب') }}</h1>
            <p class="text-xs text-gray-500 mb-8">برای استفاده از خدمات گیم‌پک، لطفا نام خود را وارد کنید.</p>

            <div id="error-box" class="hidden mb-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-xs text-red-600"></div>

            <div class="relative mb-4">
                <input aria-label="نام و نام خانوادگی" type="text" id="name-input" name="full_name"
                       class="peer w-full border border-gray-300 rounded-xl px-4 pt-6 pb-2 text-sm text-gray-800 outline-none focus:border-brandBlue focus:border-2 transition-all placeholder-transparent"
                       placeholder="نام و نام خانوادگی" autocomplete="name" autofocus>
                <label class="absolute right-4 top-4 text-gray-400 text-xs transition-all pointer-events-none">نام و نام خانوادگی <span class="text-red-400">*</span></label>
            </div>

            <div class="relative mb-6">
                <input type="email" id="email-input" name="email"
                       class="peer w-full border border-gray-300 rounded-xl px-4 pt-6 pb-2 text-sm text-gray-800 outline-none focus:border-brandBlue focus:border-2 transition-all placeholder-transparent"
                       placeholder="email@example.com" autocomplete="email" dir="ltr">
                <label class="absolute right-4 top-4 text-gray-400 text-xs transition-all pointer-events-none">ایمیل (اختیاری)</label>
            </div>

            <button id="submit-btn" onclick="submitProfile()"
                    class="w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-colors shadow-lg shadow-blue-500/20 text-sm mb-6 mt-auto">
                ثبت و ادامه
            </button>
        </section>
    </main>

    <script>
        const CSRF_TOKEN = '{{ csrf_token() }}';

        function showToast(msg, type = 'info') {
            const c = document.getElementById('toast-container');
            const t = document.createElement('div');
            const icons = {
                success: '<i class="fa-solid fa-circle-check text-green-400"></i>',
                error:   '<i class="fa-solid fa-circle-exclamation text-red-400"></i>',
                info:    '<i class="fa-solid fa-circle-info text-blue-400"></i>'
            };
            t.className = 'flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-white text-sm bg-gray-800 transform transition-all duration-300 -translate-y-full opacity-0';
            t.innerHTML = `${icons[type] || icons.info} <span>${msg}</span>`;
            c.appendChild(t);
            setTimeout(() => t.classList.remove('-translate-y-full', 'opacity-0'), 10);
            setTimeout(() => { t.classList.add('-translate-y-full', 'opacity-0'); setTimeout(() => t.remove(), 300); }, 3500);
        }

        function submitProfile() {
            const name  = document.getElementById('name-input').value.trim();
            const email = document.getElementById('email-input').value.trim();
            const errorBox = document.getElementById('error-box');
            const btn = document.getElementById('submit-btn');

            errorBox.classList.add('hidden');
            errorBox.textContent = '';

            if (name.length < 3) {
                showToast('نام باید حداقل ۳ کاراکتر باشد.', 'error');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'در حال ثبت...';

            const payload = { full_name: name };
            if (email) payload.email = email;

            fetch('{{ route('auth.complete-profile.store') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    showToast(d.message || 'اطلاعات ثبت شد.', 'success');
                    setTimeout(() => { window.location.href = d.redirect_url || '{{ route('profile.index') }}'; }, 500);
                } else {
                    if (d.errors) {
                        const msgs = Object.values(d.errors).flat().join(' | ');
                        errorBox.textContent = msgs;
                        errorBox.classList.remove('hidden');
                    } else {
                        showToast(d.message || 'خطایی رخ داد.', 'error');
                    }
                    btn.disabled = false;
                    btn.textContent = 'ثبت و ادامه';
                }
            })
            .catch(() => {
                showToast('خطا در ارتباط با سرور.', 'error');
                btn.disabled = false;
                btn.textContent = 'ثبت و ادامه';
            });
        }

        document.getElementById('name-input').addEventListener('keypress', e => { if (e.key === 'Enter') document.getElementById('email-input').focus(); });
        document.getElementById('email-input').addEventListener('keypress', e => { if (e.key === 'Enter') submitProfile(); });
    </script>
</body>
</html>
