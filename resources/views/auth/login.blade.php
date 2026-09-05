<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
        if (!location.hash) { window.scrollTo(0, 0); }
    </script>
    <title>{{ setting('general.brand_name_fa', 'گیم‌پک') }} | {{ setting('auth.login_page_title', 'ورود یا ثبت‌نام') }}</title>
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
        @keyframes shake { 10%, 90% { transform: translateX(-1px); } 20%, 80% { transform: translateX(2px); } 30%, 50%, 70% { transform: translateX(-4px); } 40%, 60% { transform: translateX(4px); } }
        .animate-shake { animation: shake 0.5s; border-color: #EF4444 !important; }
        /* Keeps the submit button above the on-screen keyboard on mobile.
           --app-height is kept in sync with the actual visible viewport (via
           the Visual Viewport API where available) instead of the static
           100vh, which does not shrink when the keyboard opens. body must
           shrink together with #login-shell -- otherwise body's flex
           centering (items-center) floats the shorter shell in the middle of
           the old, taller viewport instead of flush against the visible
           (post-keyboard) bottom edge. Scoped to the mobile breakpoint only
           -- md:h-auto already governs desktop. */
        @media (max-width: 767px) {
            body { height: var(--app-height, 100vh); min-height: var(--app-height, 100vh); }
            #login-shell { height: var(--app-height, 100vh); }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">

    <div id="toast-container" class="fixed top-6 left-6 z-[60] flex flex-col gap-3 pointer-events-none"></div>

    <main id="login-shell" class="w-full max-w-[420px] bg-white h-screen md:h-auto md:rounded-2xl md:border border-gray-200 md:shadow-sm flex flex-col overflow-hidden relative">

        <header class="flex items-center justify-between px-6 pt-6 pb-4">
            <div class="w-6 h-6"></div>
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logos/logo-horizontal.png') }}" alt="GamePek" class="h-9 w-auto" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                <span class="hidden text-2xl font-black tracking-tight text-brandDark">گیم‌<span class="text-brandBlue">پک</span></span>
            </a>
            <a href="{{ route('home') }}" class="w-8 h-8 flex items-center justify-center text-gray-800 hover:bg-gray-100 rounded-full transition-colors">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
        </header>

        <!-- Step 1: Phone -->
        <section id="view-phone" class="flex-1 flex flex-col px-6 py-4 transition-opacity duration-300">
            <h1 class="text-[17px] font-bold text-gray-800 mb-2 mt-4">{{ setting('auth.login_page_title', 'ورود یا ثبت‌نام در گیم‌پک') }}</h1>
            <p class="text-xs text-gray-500 mb-8">{{ setting('auth.login_page_subtitle', 'لطفا شماره موبایل خود را وارد کنید') }}</p>
            <div class="relative mb-6">
                <input type="tel" id="phone-input" inputmode="numeric" class="peer w-full border border-gray-300 rounded-xl px-4 pt-6 pb-2 text-sm text-gray-800 outline-none focus:border-brandBlue focus:border-2 transition-all placeholder-transparent text-left" placeholder="09123456789" dir="ltr" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                <label class="absolute right-4 top-4 text-gray-400 text-xs transition-all pointer-events-none">شماره موبایل</label>
            </div>
            <p class="text-[10px] text-gray-400 mt-auto mb-4 text-center leading-relaxed px-4">ورود شما به معنای پذیرش <a href="{{ route('terms') }}" class="text-brandBlue">شرایط گیم‌پک</a> است</p>
            <button id="send-otp-btn" onclick="submitPhone()" class="w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-colors shadow-lg shadow-blue-500/20 text-sm mb-6 disabled:opacity-60">{{ setting('auth.send_otp_button_text', 'ورود به گیم‌پک') }}</button>
        </section>

        <!-- Step 2: OTP -->
        <section id="view-otp" class="flex-1 flex flex-col px-6 py-4 hidden opacity-0 transition-opacity duration-300">
            <h1 class="text-[17px] font-bold text-gray-800 mb-2 mt-4">کد تایید را وارد کنید</h1>
            <p class="text-xs text-gray-500 mb-8">کد تایید برای شماره <span id="display-phone" class="font-medium text-gray-800 dir-ltr"></span> پیامک شد</p>
            <div class="relative mb-4">
                <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ (int) config('rental.otp.length', 5) }}" id="otp-input" class="peer w-full border border-gray-300 rounded-xl pt-6 pb-2 text-center text-2xl font-black text-gray-800 outline-none focus:border-brandBlue focus:border-2 transition-all placeholder-transparent disabled:opacity-50 disabled:bg-gray-50" style="letter-spacing: .6em; padding-left: .6em;" placeholder="{{ str_repeat('1', (int) config('rental.otp.length', 5)) }}" dir="ltr">
                <label class="absolute right-4 top-4 text-gray-400 text-xs transition-all pointer-events-none">کد تایید</label>
            </div>
            <div class="mt-auto mb-4 text-center">
                <span id="otp-timer" class="text-xs font-medium text-gray-500"></span>
                <button id="resend-btn" onclick="submitPhone()" class="hidden text-xs font-bold text-brandBlue disabled:opacity-50">{{ setting('auth.resend_otp_text', 'ارسال مجدد کد') }}</button>
            </div>
            <button id="verify-btn" onclick="submitOTP()" class="w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-colors shadow-lg text-sm mb-6 flex items-center justify-center gap-2 disabled:opacity-60">
                <svg id="verify-spinner" class="hidden w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/></svg>
                <span id="verify-btn-text">تایید</span>
            </button>
        </section>

        <!-- Step 3: Complete Profile -->
        <section id="view-name" class="flex-1 flex flex-col px-6 py-4 hidden opacity-0 transition-opacity duration-300">
            <h1 class="text-[17px] font-bold text-gray-800 mb-2 mt-4">{{ setting('auth.complete_profile_title', 'تکمیل اطلاعات حساب') }}</h1>
            <p class="text-xs text-gray-500 mb-8">لطفا نام و نام خانوادگی خود را وارد کنید</p>
            <div class="relative mb-6">
                <input type="text" id="name-input" class="peer w-full border border-gray-300 rounded-xl px-4 pt-6 pb-2 text-sm text-gray-800 outline-none focus:border-brandBlue focus:border-2 transition-all placeholder-transparent" placeholder="نام و نام خانوادگی">
                <label class="absolute right-4 top-4 text-gray-400 text-xs transition-all pointer-events-none">نام و نام خانوادگی</label>
            </div>
            <button onclick="submitName()" class="w-full bg-brandBlue text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-colors shadow-lg text-sm mb-6 mt-auto">ثبت و ورود</button>
        </section>
    </main>

    <script>
        const CSRF_TOKEN = '{{ csrf_token() }}';
        const OTP_LENGTH = {{ (int) config('rental.otp.length', 5) }};
        let userPhone = '';
        let timerInterval;

        // Keep the submit button above the mobile keyboard: track the actual
        // visible viewport height (Visual Viewport API, supported on modern
        // Android Chrome and iOS Safari) and expose it as --app-height so the
        // CSS above can shrink #login-shell to fit. Falls back to
        // window.innerHeight + the resize event on browsers without it.
        function updateAppHeight() {
            const height = window.visualViewport ? window.visualViewport.height : window.innerHeight;
            document.documentElement.style.setProperty('--app-height', height + 'px');
        }
        updateAppHeight();
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', updateAppHeight);
            window.visualViewport.addEventListener('scroll', updateAppHeight);
        } else {
            window.addEventListener('resize', updateAppHeight);
        }

        // Ensure the focused input is never left hidden behind the keyboard --
        // wait for the keyboard-open viewport resize to settle, then scroll it
        // into view.
        document.addEventListener('focusin', function (e) {
            if (e.target.matches('input, textarea')) {
                const el = e.target;
                setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
            }
        });

        function showToast(msg, type='info') {
            const c = document.getElementById('toast-container');
            const t = document.createElement('div');
            const icons = {success:'<i class="fa-solid fa-circle-check text-green-400"></i>', error:'<i class="fa-solid fa-circle-exclamation text-red-400"></i>', info:'<i class="fa-solid fa-circle-info text-blue-400"></i>'};
            t.className = 'flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-white text-sm bg-gray-800 transform transition-all duration-300 -translate-y-full opacity-0';
            t.innerHTML = `${icons[type]||icons.info} <span>${msg}</span>`;
            c.appendChild(t);
            setTimeout(()=>t.classList.remove('-translate-y-full','opacity-0'),10);
            setTimeout(()=>{t.classList.add('-translate-y-full','opacity-0');setTimeout(()=>t.remove(),300)},3500);
        }

        function switchView(from, to) {
            const fromEl = document.getElementById(from), toEl = document.getElementById(to);
            fromEl.classList.add('opacity-0');
            setTimeout(()=>{ fromEl.classList.add('hidden'); toEl.classList.remove('hidden'); setTimeout(()=>{ toEl.classList.remove('opacity-0'); if(to==='view-otp') document.getElementById('otp-input').focus(); if(to==='view-name') document.getElementById('name-input').focus(); },10); },300);
        }

        function startTimer(seconds) {
            clearInterval(timerInterval);
            const timerEl = document.getElementById('otp-timer');
            const resendBtn = document.getElementById('resend-btn');
            resendBtn.classList.add('hidden'); timerEl.classList.remove('hidden');
            timerInterval = setInterval(()=>{
                const m = Math.floor(seconds/60), s = seconds % 60;
                timerEl.textContent = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')} مانده تا دریافت مجدد کد`;
                seconds--;
                if(seconds<0){
                    clearInterval(timerInterval);
                    timerEl.classList.add('hidden');
                    resendBtn.classList.remove('hidden');
                    // Only now is it safe to send another OTP request.
                    setSendingOtp(false);
                }
            },1000);
        }

        let sendingOtp = false;

        function setSendingOtp(isSending) {
            sendingOtp = isSending;
            const sendBtn = document.getElementById('send-otp-btn');
            const resendBtn = document.getElementById('resend-btn');
            if (sendBtn) {
                sendBtn.disabled = isSending;
                if (isSending) {
                    sendBtn.dataset.originalText = sendBtn.dataset.originalText ?? sendBtn.textContent;
                    sendBtn.textContent = 'در حال ارسال...';
                } else if (sendBtn.dataset.originalText !== undefined) {
                    sendBtn.textContent = sendBtn.dataset.originalText;
                }
            }
            if (resendBtn) resendBtn.disabled = isSending;
        }

        function submitPhone() {
            // Ignore taps (including very fast repeated taps) while a request
            // is already in flight or while disabled after a successful send.
            if (sendingOtp) return;
            const mobile = document.getElementById('phone-input').value.trim();
            if (!/^09[0-9]{9}$/.test(mobile)) { showToast('شماره موبایل صحیح نیست.','error'); return; }
            userPhone = mobile;
            setSendingOtp(true);
            fetch('{{ route('auth.send-otp') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ mobile })
            }).then(r=>r.json()).then(d=>{
                if(d.success){
                    document.getElementById('display-phone').textContent = mobile;
                    switchView('view-phone','view-otp');
                    startTimer(120);
                    showToast(d.message,'success');
                    if (d.dev_otp) showToast('کد تست ورود: ' + d.dev_otp, 'info');
                    // Stays disabled -- setSendingOtp(false) fires when the
                    // resend timer above actually expires.
                } else {
                    setSendingOtp(false);
                    showToast(d.message,'error');
                }
            }).catch(()=>{
                setSendingOtp(false);
                showToast('خطا در ارتباط با سرور. دوباره تلاش کنید.','error');
            });
        }

        let otpVerifying = false;

        function setOtpVerifying(isVerifying) {
            otpVerifying = isVerifying;
            document.getElementById('otp-input').disabled = isVerifying;
            document.getElementById('verify-btn').disabled = isVerifying;
            document.getElementById('verify-spinner').classList.toggle('hidden', !isVerifying);
            document.getElementById('verify-btn-text').textContent = isVerifying ? 'در حال بررسی...' : 'تایید';
        }

        function shakeAndResetOtp() {
            const input = document.getElementById('otp-input');
            input.classList.add('animate-shake');
            setTimeout(() => input.classList.remove('animate-shake'), 500);
            input.value = '';
            input.focus();
        }

        function submitOTP() {
            if (otpVerifying) return;
            const otp = document.getElementById('otp-input').value.trim();
            if (otp.length !== OTP_LENGTH || !/^[0-9]+$/.test(otp)) { showToast(`کد تایید باید ${OTP_LENGTH} رقم باشد.`,'error'); return; }
            setOtpVerifying(true);
            fetch('{{ route('auth.verify-otp') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ mobile: userPhone, otp })
            }).then(r=>r.json()).then(d=>{
                if(d.success){
                    if(d.needs_name){ setOtpVerifying(false); switchView('view-otp','view-name'); }
                    else if(d.is_new_user){ showPSWelcome(d.redirect_url); }
                    else { window.location.href = d.redirect_url; }
                } else {
                    setOtpVerifying(false);
                    shakeAndResetOtp();
                    showToast(d.message,'error');
                }
            }).catch(()=>{
                setOtpVerifying(false);
                shakeAndResetOtp();
                showToast('خطا در ارتباط با سرور. دوباره تلاش کنید.','error');
            });
        }

        // Auto-verify as soon as the configured OTP length is reached -- no
        // button press needed. OTP_LENGTH comes from gamepek.otp.length
        // (OTP_LENGTH env var), the same single source of truth the backend's
        // VerifyOtpRequest validates against, so frontend and backend always
        // agree without any hardcoded digit count here.
        document.getElementById('otp-input').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, OTP_LENGTH);
            if (otpVerifying) return;
            if (this.value.length === OTP_LENGTH) submitOTP();
        });

        function submitName() {
            const name = document.getElementById('name-input').value.trim();
            if (name.length < 3) { showToast('نام باید حداقل ۳ کاراکتر باشد.','error'); return; }
            fetch('{{ route('auth.complete-profile.store') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ full_name: name })
            }).then(r=>r.json()).then(d=>{
                if(d.success){ showPSWelcome(d.redirect_url); }
                else { showToast(d.message,'error'); }
            });
        }

        function showPSWelcome(redirectUrl) {
            document.getElementById('ps-welcome-modal').classList.remove('hidden');
            document.getElementById('ps-welcome-modal').classList.add('flex');
            setTimeout(() => { window.location.href = redirectUrl; }, 3500);
        }

        // Enter key support
        document.getElementById('phone-input').addEventListener('keypress', e=>{ if(e.key==='Enter') submitPhone(); });
        document.getElementById('otp-input').addEventListener('keypress', e=>{ if(e.key==='Enter') submitOTP(); });
        document.getElementById('name-input').addEventListener('keypress', e=>{ if(e.key==='Enter') submitName(); });
    </script>

    {{-- PlayStation Welcome Modal --}}
    <div id="ps-welcome-modal" class="hidden fixed inset-0 z-[999] items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="relative bg-[#003087] text-white rounded-3xl px-8 py-10 max-w-sm w-full mx-4 text-center overflow-hidden shadow-2xl">
            {{-- PS background pattern --}}
            <div class="absolute inset-0 opacity-5 pointer-events-none select-none text-[120px] font-black leading-none overflow-hidden flex flex-wrap gap-4 p-4" aria-hidden="true">
                <span>×</span><span>○</span><span>△</span><span>□</span><span>×</span><span>○</span>
            </div>
            {{-- PS Logo --}}
            <div class="relative z-10 flex justify-center mb-5">
                <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center border-2 border-white/20">
                    <i class="fa-brands fa-playstation text-3xl text-white"></i>
                </div>
            </div>
            <div class="relative z-10">
                <h2 class="text-xl font-black mb-2">خوش اومدی گیمر!</h2>
                <p class="text-sm text-blue-200 mb-5 leading-relaxed">حساب PlayStation تو در گیم‌پک آماده‌ست.<br>بریم بازی کنیم!</p>
                <div class="flex justify-center gap-3 mb-6">
                    <span class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-xs font-black">×</span>
                    <span class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center text-xs font-black">○</span>
                    <span class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center text-xs font-black">△</span>
                    <span class="w-8 h-8 rounded-full bg-pink-400 flex items-center justify-center text-xs font-black">□</span>
                </div>
                <div class="flex items-center justify-center gap-2 text-xs text-blue-300">
                    <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/></svg>
                    در حال ورود به فروشگاه...
                </div>
            </div>
        </div>
    </div>
</body>
</html>
