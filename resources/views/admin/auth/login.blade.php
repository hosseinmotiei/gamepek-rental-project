<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ورود به پنل مدیریت | گیم‌پک</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logos/logo-icon.png') }}">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandBlue: '#0066FF',
                        sidebar:   '#0F1729',
                    },
                    fontFamily: {
                        sans: ['Vazirmatn', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-sidebar flex items-center justify-center p-4">

    <div class="w-full max-w-sm">

        {{-- Logo --}}
        <div class="flex flex-col items-center mb-8">
            <img src="{{ asset('images/logos/logo-icon.png') }}" alt="GamePek" class="w-14 h-14 rounded-2xl object-contain mb-4 shadow-2xl shadow-blue-500/30 bg-white/5" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
            <div class="hidden w-14 h-14 bg-brandBlue rounded-2xl flex items-center justify-center mb-4 shadow-2xl shadow-blue-500/30 text-white font-black">GP</div>
            <h1 class="text-white text-xl font-bold">گیم‌پک</h1>
            <p class="text-white/40 text-sm mt-1">پنل مدیریت</p>
        </div>

        {{-- Card --}}
        <div class="bg-white rounded-2xl shadow-2xl shadow-black/40 overflow-hidden">

            <div class="px-6 pt-6 pb-2 border-b border-gray-100">
                <h2 class="text-gray-800 font-bold text-base">ورود به پنل</h2>
                <p class="text-gray-400 text-xs mt-0.5">با ایمیل و رمز عبور وارد شوید</p>
            </div>

            <form method="POST" action="{{ route('admin.login.post') }}" class="p-6 flex flex-col gap-4" novalidate>
                @csrf

                {{-- Global error --}}
                @if($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl flex items-start gap-2">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
                @endif

                @if(session('success'))
                <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-xl flex items-center gap-2">
                    <i class="fa-solid fa-circle-check"></i>
                    {{ session('success') }}
                </div>
                @endif

                {{-- Email --}}
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">ایمیل</label>
                    <div class="relative">
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            placeholder="admin@gamepek.local"
                            class="w-full bg-gray-50 border {{ $errors->has('email') ? 'border-red-400 bg-red-50' : 'border-gray-200' }} rounded-xl px-4 py-3 text-sm text-gray-800 placeholder-gray-400 outline-none focus:border-brandBlue focus:bg-white transition-all"
                            dir="ltr"
                        >
                    </div>
                </div>

                {{-- Password --}}
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">رمز عبور</label>
                    <div class="relative">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="w-full bg-gray-50 border {{ $errors->has('password') ? 'border-red-400 bg-red-50' : 'border-gray-200' }} rounded-xl px-4 py-3 text-sm text-gray-800 placeholder-gray-400 outline-none focus:border-brandBlue focus:bg-white transition-all"
                            dir="ltr"
                        >
                        <button type="button" onclick="togglePassword()" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i id="eye-icon" class="fa-regular fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                {{-- Remember --}}
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="remember" class="w-4 h-4 rounded border-gray-300 text-brandBlue accent-blue-600">
                    <span class="text-sm text-gray-600">مرا به خاطر بسپار</span>
                </label>

                {{-- Submit --}}
                <button type="submit"
                        class="w-full bg-brandBlue hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-colors shadow-lg shadow-blue-500/25 text-sm mt-1">
                    ورود به پنل
                </button>
            </form>
        </div>

        {{-- Footer --}}
        <p class="text-center text-white/20 text-xs mt-6">
            گیم‌پک © {{ date('Y') }} — دسترسی محدود به مدیران
        </p>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eye-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
