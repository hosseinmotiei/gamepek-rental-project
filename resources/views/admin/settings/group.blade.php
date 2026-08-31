@extends('admin.layouts.app')

@section('title', $meta['title'])
@section('page_badge', $meta['title'])
@section('breadcrumb')
    <a href="{{ route('admin.settings.index') }}" class="hover:text-brandBlue transition-colors text-sm">تنظیمات</a>
    <i class="fa-solid fa-chevron-left text-[10px] mx-1"></i>
    <span class="text-gray-700 text-sm">{{ $meta['title'] }}</span>
@endsection

@section('content')

@if($errors->any())
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl">
    <p class="font-bold mb-1"><i class="fa-solid fa-circle-exclamation ml-1"></i>خطاهای فرم:</p>
    <ul class="list-disc list-inside text-xs space-y-0.5">
        @foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ route('admin.settings.update', $group) }}" enctype="multipart/form-data"
      onsubmit="return submitFormWithProgress(this, {progressWrap:'settings-upload-progress', progressBar:'settings-upload-bar', progressText:'settings-upload-pct'})">
    @csrf
    @method('PUT')

    <div class="flex flex-col lg:flex-row gap-6">

        {{-- Main settings card --}}
        <div class="flex-1">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-3">
                    <i class="fa-solid {{ $meta['icon'] }} text-brandBlue text-sm"></i>
                    <div>
                        <p class="font-bold text-gray-800 text-sm">{{ $meta['title'] }}</p>
                        <p class="text-xs text-gray-400">{{ $meta['description'] }}</p>
                    </div>
                </div>

                @foreach($settings as $setting)
                <div class="flex flex-col md:flex-row gap-4 px-5 py-4 border-b border-gray-50 last:border-b-0 hover:bg-gray-50/50 transition-colors">

                    {{-- Label + description column --}}
                    <div class="w-full md:w-56 shrink-0 pt-0.5">
                        <label for="field_{{ $setting->key }}" class="block text-sm font-medium text-gray-700">
                            {{ $setting->label }}
                        </label>
                        @if($setting->description)
                        <p class="text-[11px] text-gray-400 mt-0.5 leading-relaxed">{{ $setting->description }}</p>
                        @endif
                        <span class="inline-block mt-1 text-[9px] text-gray-300 border border-gray-100 px-1.5 py-0.5 rounded font-mono">{{ $setting->type }}</span>
                    </div>

                    {{-- Input column --}}
                    <div class="flex-1">

                        @if($setting->type === 'boolean')
                        {{-- Toggle --}}
                        <label class="relative inline-flex items-center gap-3 cursor-pointer">
                            <input type="hidden" name="{{ $setting->key }}" value="0">
                            <input type="checkbox" name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                                   value="1" {{ old($setting->key, $setting->value) == '1' ? 'checked' : '' }}
                                   class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 rounded-full peer
                                        peer-checked:after:translate-x-5 rtl:peer-checked:after:-translate-x-5
                                        peer-checked:bg-brandBlue
                                        after:content-[''] after:absolute after:top-[2px] after:start-[2px]
                                        after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all
                                        relative"></div>
                            <span class="text-sm text-gray-600">{{ $setting->value == '1' ? 'فعال' : 'غیرفعال' }}</span>
                        </label>

                        @elseif($setting->type === 'color')
                        {{-- Color picker --}}
                        <div class="flex items-center gap-3">
                            <input type="color" id="color_picker_{{ $setting->key }}"
                                   value="{{ old($setting->key, $setting->value ?: '#000000') }}"
                                   class="w-10 h-10 rounded-lg border border-gray-200 cursor-pointer p-0.5 bg-white"
                                   oninput="document.getElementById('field_{{ $setting->key }}').value = this.value">
                            <input type="text" name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                                   value="{{ old($setting->key, $setting->value) }}"
                                   class="w-36 bg-gray-50 border {{ $errors->has($setting->key) ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2 text-sm font-mono outline-none focus:border-brandBlue focus:bg-white transition-all"
                                   placeholder="#0066FF" dir="ltr" maxlength="7"
                                   oninput="this.previousElementSibling.previousElementSibling.value = this.value">
                            <span class="text-xs text-gray-400">فرمت: #RRGGBB</span>
                        </div>
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        @elseif($setting->type === 'image')
                        {{-- Image upload --}}
                        <div class="flex flex-col gap-2">
                            @if($setting->value)
                            <div class="flex items-center gap-3">
                                <img src="{{ media_url($setting->value) }}" alt="{{ $setting->label }}"
                                     class="w-20 h-20 rounded-xl object-cover border border-gray-200">
                                <div class="text-xs text-gray-400">
                                    <p>تصویر فعلی</p>
                                    <p class="font-mono text-[10px] mt-0.5 break-all">{{ $setting->value }}</p>
                                </div>
                            </div>
                            @else
                            <p class="text-xs text-gray-400">هیچ تصویری آپلود نشده</p>
                            @endif
                            <input type="file" name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                                   accept="image/jpg,image/jpeg,image/png,image/webp"
                                   onchange="adminPreviewImage(this, 'preview_{{ $setting->key }}')"
                                   class="block text-sm text-gray-500 file:ml-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brandBlue/10 file:text-brandBlue hover:file:bg-brandBlue/20 cursor-pointer">
                            <p class="text-[10px] text-gray-400">فرمت‌های مجاز: JPG، PNG، WebP | حداکثر ۲ مگابایت</p>
                            <img id="preview_{{ $setting->key }}" class="hidden mt-1 w-20 h-20 rounded-xl object-cover border border-gray-200">
                        </div>
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        @elseif($setting->type === 'textarea' || $setting->type === 'json')
                        {{-- Textarea / JSON --}}
                        <textarea name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                                  rows="{{ $setting->type === 'json' ? 6 : 3 }}"
                                  class="w-full bg-gray-50 border {{ $errors->has($setting->key) ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all resize-y {{ $setting->type === 'json' ? 'font-mono text-xs' : '' }}"
                                  {{ $setting->type === 'json' ? 'dir=ltr spellcheck=false' : '' }}>{{ old($setting->key, $setting->value) }}</textarea>
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        @elseif($setting->type === 'select')
                        {{-- Select --}}
                        <select name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                                class="w-full md:w-auto bg-gray-50 border {{ $errors->has($setting->key) ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-3 py-2.5 text-sm outline-none focus:border-brandBlue">
                            @foreach($setting->options ?? [] as $optVal => $optLabel)
                            <option value="{{ $optVal }}" {{ old($setting->key, $setting->value) == $optVal ? 'selected' : '' }}>
                                {{ $optLabel }}
                            </option>
                            @endforeach
                        </select>
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        @elseif($setting->type === 'url')
                        {{-- URL --}}
                        <input type="url" name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                               value="{{ old($setting->key, $setting->value) }}"
                               class="w-full bg-gray-50 border {{ $errors->has($setting->key) ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm font-mono outline-none focus:border-brandBlue focus:bg-white transition-all"
                               placeholder="https://" dir="ltr">
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        @elseif($setting->type === 'number')
                        {{-- Number --}}
                        <input type="number" name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                               value="{{ old($setting->key, $setting->value) }}"
                               class="w-40 bg-gray-50 border {{ $errors->has($setting->key) ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                               dir="ltr">
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        @else
                        {{-- Default: text --}}
                        <input type="text" name="{{ $setting->key }}" id="field_{{ $setting->key }}"
                               value="{{ old($setting->key, $setting->value) }}"
                               class="w-full bg-gray-50 border {{ $errors->has($setting->key) ? 'border-red-400' : 'border-gray-200' }} rounded-xl px-4 py-2.5 text-sm outline-none focus:border-brandBlue focus:bg-white transition-all"
                               {{ in_array($group, ['auth', 'checkout', 'profile', 'notifications']) ? '' : '' }}>
                        @error($setting->key) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        @endif

                    </div>
                </div>
                @endforeach

                @if($settings->isEmpty())
                <div class="py-12 text-center text-gray-400">
                    <i class="fa-solid fa-gear text-3xl mb-2"></i>
                    <p class="text-sm">هیچ تنظیماتی در این بخش یافت نشد.</p>
                    <p class="text-xs mt-1">Seeder را اجرا کنید: <code class="bg-gray-100 px-1.5 py-0.5 rounded font-mono text-[11px]">php artisan db:seed --class=SettingsSeeder</code></p>
                </div>
                @endif
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="w-full lg:w-56 shrink-0 flex flex-col gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                <div id="settings-upload-progress" class="hidden mb-3">
                    <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
                        <span>در حال آپلود...</span>
                        <span id="settings-upload-pct">0%</span>
                    </div>
                    <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div id="settings-upload-bar" class="h-full bg-brandBlue transition-all duration-150" style="width:0%"></div>
                    </div>
                </div>
                <button type="submit"
                        class="w-full bg-brandBlue text-white text-sm font-bold py-2.5 rounded-xl hover:bg-blue-700 transition-colors mb-2">
                    <i class="fa-solid fa-floppy-disk ml-1"></i> ذخیره تنظیمات
                </button>
                <a href="{{ route('admin.settings.index') }}"
                   class="block text-center text-sm text-gray-500 border border-gray-200 py-2 rounded-xl hover:bg-gray-50 transition-colors">
                    بازگشت
                </a>
            </div>

            {{-- Group nav --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                <p class="text-xs font-bold text-gray-600 mb-2">سایر بخش‌ها</p>
                @php
                $allGroups = [
                    'general' => 'تنظیمات عمومی', 'theme' => 'ظاهری', 'header' => 'هدر',
                    'footer' => 'فوتر', 'seo' => 'SEO', 'notifications' => 'اعلان‌ها',
                    'checkout' => 'خرید', 'auth' => 'ورود', 'profile' => 'پروفایل',
                    'product_display' => 'محصولات',
                ];
                $settingsPerms = [
                    'theme' => 'manage_theme_settings', 'seo' => 'manage_seo_settings',
                    'notifications' => 'manage_notification_settings',
                ];
                @endphp
                <div class="flex flex-col gap-0.5">
                    @foreach($allGroups as $gKey => $gLabel)
                    @php $perm = $settingsPerms[$gKey] ?? 'manage_settings'; @endphp
                    @if(auth()->user()->can($perm))
                    <a href="{{ route('admin.settings.show', $gKey) }}"
                       class="text-xs px-2 py-1.5 rounded-lg transition-colors {{ $group === $gKey ? 'bg-brandBlue text-white font-medium' : 'text-gray-600 hover:bg-gray-100' }}">
                        {{ $gLabel }}
                    </a>
                    @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
// Sync color pickers with text inputs on page load
document.querySelectorAll('input[type="color"]').forEach(picker => {
    const key = picker.id.replace('color_picker_', '');
    const textInput = document.getElementById('field_' + key);
    if (textInput) {
        picker.addEventListener('input', () => textInput.value = picker.value);
        textInput.addEventListener('input', () => {
            if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                picker.value = textInput.value;
            }
        });
    }
});

// Boolean toggle label update
document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', function () {
        const label = this.closest('label').querySelector('span:last-child');
        if (label) label.textContent = this.checked ? 'فعال' : 'غیرفعال';
    });
});
</script>
@endpush

@endsection
