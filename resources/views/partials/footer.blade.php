<footer class="bg-white border-t border-gray-200 pt-10 md:pt-16 pb-8 mb-[70px] md:mb-0">
    @php
        $footerPhone = setting('general.contact_phone', setting('general.support_mobile', '۰۹۱۲۱۲۳۴۵۶۷')) ?: '۰۹۱۲۱۲۳۴۵۶۷';
        $footerPhoneHref = preg_replace('/[^0-9+]/', '', strtr($footerPhone, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        $footerInstagram = setting('general.contact_instagram', 'gamepek.ir') ?: 'gamepek.ir';
        $footerInstagramLabel = ltrim(str_replace(['https://instagram.com/', 'http://instagram.com/', 'https://www.instagram.com/', 'http://www.instagram.com/'], '', $footerInstagram), '@/');
        $footerInstagramUrl = str_starts_with($footerInstagram, 'http://') || str_starts_with($footerInstagram, 'https://')
            ? $footerInstagram
            : 'https://instagram.com/' . ltrim($footerInstagram, '@/');
    @endphp
    <div class="max-w-[1400px] mx-auto px-4">

        <!-- Features Row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 md:gap-8 mb-10 md:mb-12 border-b border-gray-100 pb-10 md:pb-12">
            <div class="flex flex-col items-center text-center">
                <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-brandBlue/10 flex items-center justify-center mb-3 md:mb-4">
                    <i class="fa-solid fa-truck-fast text-2xl md:text-3xl text-brandBlue"></i>
                </div>
                <h5 class="font-bold text-gray-800 text-xs md:text-sm mb-1">ارسال سریع</h5>
                <p class="text-[10px] md:text-xs text-gray-500">تحویل اکسپرس در تهران</p>
            </div>
            <div class="flex flex-col items-center text-center">
                <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-amber-100 flex items-center justify-center mb-3 md:mb-4">
                    <i class="fa-solid fa-certificate text-2xl md:text-3xl text-amber-500"></i>
                </div>
                <h5 class="font-bold text-gray-800 text-xs md:text-sm mb-1">تضمین اصالت</h5>
                <p class="text-[10px] md:text-xs text-gray-500">کالاهای ۱۰۰٪ اورجینال</p>
            </div>
            <div class="flex flex-col items-center text-center">
                <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-green-100 flex items-center justify-center mb-3 md:mb-4">
                    <i class="fa-solid fa-shield-halved text-2xl md:text-3xl text-green-600"></i>
                </div>
                <h5 class="font-bold text-gray-800 text-xs md:text-sm mb-1">پرداخت امن</h5>
                <p class="text-[10px] md:text-xs text-gray-500">درگاه‌های بانکی معتبر</p>
            </div>
            <div class="flex flex-col items-center text-center">
                <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-purple-100 flex items-center justify-center mb-3 md:mb-4">
                    <i class="fa-solid fa-headset text-2xl md:text-3xl text-purple-600"></i>
                </div>
                <h5 class="font-bold text-gray-800 text-xs md:text-sm mb-1">پشتیبانی ۲۴/۷</h5>
                <p class="text-[10px] md:text-xs text-gray-500">پاسخگویی در تمام ایام</p>
            </div>
        </div>

        <!-- Main Footer Links -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-8 mb-10 md:mb-12 text-center md:text-right">

            <!-- Logo + About -->
            <div class="md:col-span-6">
                <div class="flex items-center justify-center md:justify-start gap-2 mb-4">
                    <img src="{{ asset('images/logos/logo-horizontal.png') }}" alt="GamePek" class="h-9 md:h-10 w-auto" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                    <span class="hidden text-xl md:text-2xl font-black tracking-tight text-brandDark">گیم‌<span class="text-brandBlue">پک</span></span>
                </div>
                <p class="text-xs md:text-sm text-gray-600 mb-4 leading-relaxed text-justify px-4 md:px-0">{{ setting('footer.footer_about_text', 'گیم‌پک اجاره؛ سرویس اجاره کنسول بازی و لوازم جانبی از خانواده گیم‌پک.') }}</p>
                <a href="{{ route('contact') }}" class="inline-flex items-center justify-center gap-2 text-sm font-bold text-brandBlue bg-brandLightBlue hover:bg-blue-100 rounded-xl px-5 py-3 transition-colors">
                    <i class="fa-solid fa-headset"></i> تماس با ما
                </a>
                <div class="mt-4 flex flex-wrap justify-center md:justify-start gap-3 text-xs text-gray-500">
                    <a href="tel:{{ $footerPhoneHref }}" class="inline-flex items-center gap-1 hover:text-brandBlue transition-colors" dir="ltr">
                        <i class="fa-solid fa-phone text-brandBlue"></i> {{ $footerPhone }}
                    </a>
                    <a href="{{ $footerInstagramUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 hover:text-pink-500 transition-colors" dir="ltr">
                        <i class="fa-brands fa-instagram text-pink-500"></i> {{ $footerInstagramLabel }}
                    </a>
                </div>
            </div>

            <!-- Customer Links -->
            <div class="md:col-span-3 md:col-start-8">
                <h4 class="font-bold text-gray-800 mb-4 text-sm">راهنمای مشتریان</h4>
                <ul class="space-y-3 text-xs md:text-sm text-gray-600">
                    <li><a href="{{ route('contact') }}" class="hover:text-brandBlue transition-colors">تماس با ما</a></li>
                    <li><a href="{{ route('terms') }}" class="hover:text-brandBlue transition-colors">قوانین و مقررات</a></li>
                    <li><a href="{{ route('about') }}" class="hover:text-brandBlue transition-colors">درباره ما</a></li>
                    {{-- Moved out of the top header: order tracking belongs
                         with the customer's own account, not in site chrome. --}}
                    <li><a href="{{ route('orders.index') }}" class="hover:text-brandBlue transition-colors">پیگیری سفارش</a></li>
                </ul>
            </div>

            <!-- Trust Seals -->
            <div class="md:col-span-3 flex flex-col items-center md:items-start">
                <h4 class="font-bold text-gray-800 mb-4 text-sm">{{ setting('footer.trust_badges_title', 'نمادهای اعتماد') }}</h4>
                <div class="flex gap-2">
                    <div class="bg-gray-100 w-16 h-16 md:w-20 md:h-20 rounded-xl flex items-center justify-center p-2 border border-gray-200 overflow-hidden">
                        <a referrerpolicy='origin' target='_blank' href='https://trustseal.enamad.ir/?id=752344&Code=rCVs6mBPgO4sF1djIit2jKzlyEQquCeb'><img referrerpolicy='origin' src='https://trustseal.enamad.ir/logo.aspx?id=752344&Code=rCVs6mBPgO4sF1djIit2jKzlyEQquCeb' alt='' style='cursor:pointer' code='rCVs6mBPgO4sF1djIit2jKzlyEQquCeb'></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Footer -->
        <div class="flex flex-col md:flex-row justify-between items-center pt-6 md:pt-8 border-t border-gray-100 text-[10px] md:text-xs text-gray-500 gap-4 text-center md:text-left">
            <p>{{ setting('footer.copyright_text', 'کلیه حقوق این سایت متعلق به فروشگاه گیم‌پک می‌باشد.') }} {{ date('Y') }} ©</p>
            <div class="flex gap-4 text-base md:text-lg">
                {{-- Only links that are actually configured. They used to fall back to
                     href="#", which rendered an icon that went nowhere on every page. --}}
                @if ($footerInstagramUrl && $footerInstagramUrl !== '#')
                    <a href="{{ $footerInstagramUrl }}" target="_blank" rel="noopener noreferrer" aria-label="اینستاگرام گیم‌پک" class="p-2 -m-2 text-gray-400 hover:text-pink-500 transition-colors"><i class="fa-brands fa-instagram" aria-hidden="true"></i></a>
                @endif
                @if ($footerTelegramUrl = setting('general.telegram_url', ''))
                    <a href="{{ $footerTelegramUrl }}" aria-label="تلگرام گیم‌پک" class="p-2 -m-2 text-gray-400 hover:text-blue-400 transition-colors"><i class="fa-brands fa-telegram" aria-hidden="true"></i></a>
                @endif
                @if ($footerYoutubeUrl = setting('general.youtube_url', ''))
                    <a href="{{ $footerYoutubeUrl }}" aria-label="یوتیوب گیم‌پک" class="p-2 -m-2 text-gray-400 hover:text-red-600 transition-colors"><i class="fa-brands fa-youtube" aria-hidden="true"></i></a>
                @endif
            </div>
        </div>
    </div>
</footer>
