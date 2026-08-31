<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = $this->definitions();

        foreach ($groups as $group => $items) {
            foreach ($items as $item) {
                Setting::firstOrCreate(
                    ['group' => $group, 'key' => $item['key']],
                    [
                        'label'       => $item['label'],
                        'value'       => $item['value'] ?? null,
                        'type'        => $item['type'] ?? 'text',
                        'options'     => $item['options'] ?? null,
                        'description' => $item['description'] ?? null,
                        'sort_order'  => $item['sort'] ?? 0,
                        'is_public'   => $item['public'] ?? true,
                    ]
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function definitions(): array
    {
        return [

            // ── GENERAL ──────────────────────────────────────────────────────

            'general' => [
                ['key' => 'site_name',         'label' => 'نام سایت',             'value' => 'گیم‌پک',          'type' => 'text',     'sort' => 10],
                ['key' => 'brand_name_fa',      'label' => 'نام برند (فارسی)',     'value' => 'گیم‌پک',          'type' => 'text',     'sort' => 20],
                ['key' => 'brand_name_en',      'label' => 'نام برند (انگلیسی)',   'value' => 'GamePek',          'type' => 'text',     'sort' => 30],
                ['key' => 'brand_slogan',       'label' => 'شعار فروشگاه',         'value' => 'اجاره کنسول بازی و لوازم جانبی', 'type' => 'text', 'sort' => 40],
                ['key' => 'short_about_text',   'label' => 'درباره ما (کوتاه)',   'value' => 'گیم‌پک اجاره — اجاره کنسول بازی و لوازم جانبی.', 'type' => 'textarea', 'sort' => 50, 'description' => 'متن کوتاه درباره فروشگاه برای نمایش در صفحات مختلف'],
                ['key' => 'footer_about_text',  'label' => 'متن درباره ما (فوتر)', 'value' => 'گیم‌پک اجاره، سرویس اجاره کنسول بازی و لوازم جانبی از خانواده گیم‌پک است.', 'type' => 'textarea', 'sort' => 60],
                ['key' => 'support_phone',      'label' => 'تلفن پشتیبانی',       'value' => '021-12345678',    'type' => 'text',     'sort' => 70,  'description' => 'تلفن ثابت پشتیبانی فروشگاه'],
                ['key' => 'contact_phone',      'label' => 'شماره تماس',          'value' => '۰۹۱۲۱۲۳۴۵۶۷',     'type' => 'text',     'sort' => 71,  'description' => 'شماره تماس نمایش داده شده در صفحه تماس با ما'],
                ['key' => 'support_mobile',     'label' => 'موبایل پشتیبانی',     'value' => '0912-0000000',    'type' => 'text',     'sort' => 80],
                ['key' => 'support_hours',      'label' => 'ساعات پشتیبانی',      'value' => 'شنبه تا پنج‌شنبه ۹ تا ۱۸', 'type' => 'text', 'sort' => 90],
                ['key' => 'store_address',      'label' => 'آدرس فروشگاه',        'value' => 'تهران',           'type' => 'textarea', 'sort' => 100],
                ['key' => 'contact_address',    'label' => 'آدرس',                'value' => 'تهران، سعادت‌آباد', 'type' => 'textarea', 'sort' => 101, 'description' => 'آدرس نمایش داده شده در صفحه تماس با ما'],
                ['key' => 'default_city',       'label' => 'شهر پیش‌فرض',        'value' => 'تهران',           'type' => 'text',     'sort' => 110],
                ['key' => 'default_currency',   'label' => 'واحد پول',            'value' => 'تومان',           'type' => 'text',     'sort' => 120, 'description' => 'واحد نمایش قیمت در سایت'],
                ['key' => 'contact_email',      'label' => 'ایمیل تماس',          'value' => '',                'type' => 'text',     'sort' => 130],
                ['key' => 'instagram_url',      'label' => 'لینک اینستاگرام',     'value' => '',                'type' => 'url',      'sort' => 140],
                ['key' => 'contact_instagram',  'label' => 'اینستاگرام',          'value' => 'gamepek.ir',      'type' => 'text',     'sort' => 141, 'description' => 'آیدی یا لینک اینستاگرام صفحه تماس با ما'],
                ['key' => 'telegram_url',       'label' => 'لینک تلگرام',         'value' => '',                'type' => 'url',      'sort' => 150],
                ['key' => 'youtube_url',        'label' => 'لینک یوتیوب',         'value' => '',                'type' => 'url',      'sort' => 160],
                ['key' => 'whatsapp_url',       'label' => 'لینک واتساپ',         'value' => '',                'type' => 'url',      'sort' => 170],
            ],

            // ── THEME ─────────────────────────────────────────────────────────

            'theme' => [
                ['key' => 'primary_color',              'label' => 'رنگ اصلی (Primary)',           'value' => '#0066FF', 'type' => 'color', 'sort' => 10, 'description' => 'رنگ اصلی برند و دکمه‌ها'],
                ['key' => 'secondary_color',            'label' => 'رنگ ثانویه (Secondary)',       'value' => '#1a2847', 'type' => 'color', 'sort' => 20],
                ['key' => 'dark_color',                 'label' => 'رنگ تیره (Dark)',              'value' => '#111111', 'type' => 'color', 'sort' => 30],
                ['key' => 'light_background_color',     'label' => 'رنگ پس‌زمینه روشن',          'value' => '#F5F5F5', 'type' => 'color', 'sort' => 40],
                ['key' => 'flash_sale_color',           'label' => 'رنگ حراج فلش',                'value' => '#EF4056', 'type' => 'color', 'sort' => 50, 'description' => 'رنگ برچسب قیمت حراج و تخفیف ویژه'],
                ['key' => 'success_color',              'label' => 'رنگ موفقیت',                  'value' => '#22c55e', 'type' => 'color', 'sort' => 60],
                ['key' => 'warning_color',              'label' => 'رنگ هشدار',                   'value' => '#f59e0b', 'type' => 'color', 'sort' => 70],
                ['key' => 'error_color',                'label' => 'رنگ خطا',                     'value' => '#ef4444', 'type' => 'color', 'sort' => 80],
                ['key' => 'button_radius',              'label' => 'گردی دکمه‌ها',               'value' => '12px',    'type' => 'select', 'sort' => 90,
                    'options' => ['4px' => '4px (کمی گرد)', '8px' => '8px (متوسط)', '12px' => '12px (گرد)', '16px' => '16px (خیلی گرد)', '9999px' => 'کاملاً گرد'],
                    'description' => 'شعاع گوشه دکمه‌های سایت'],
                ['key' => 'card_radius',                'label' => 'گردی کارت‌ها',               'value' => '16px',    'type' => 'select', 'sort' => 100,
                    'options' => ['8px' => '8px', '12px' => '12px', '16px' => '16px', '20px' => '20px', '24px' => '24px'],
                    'description' => 'شعاع گوشه کارت‌های محصول و بخش‌ها'],
                ['key' => 'default_placeholder_image',  'label' => 'تصویر پیش‌فرض عمومی',      'value' => null,      'type' => 'image',  'sort' => 110],
                ['key' => 'product_placeholder_image',  'label' => 'تصویر پیش‌فرض محصول',       'value' => null,      'type' => 'image',  'sort' => 120, 'description' => 'اگر محصول تصویر نداشته باشد این تصویر نمایش داده می‌شود'],
                ['key' => 'banner_placeholder_image',   'label' => 'تصویر پیش‌فرض بنر',         'value' => null,      'type' => 'image',  'sort' => 140],
            ],

            // ── HEADER ────────────────────────────────────────────────────────

            'header' => [
                ['key' => 'topbar_support_text',    'label' => 'متن پشتیبانی (نوار بالا)',   'value' => 'پشتیبانی ۷ روز هفته',                         'type' => 'text', 'sort' => 10],
                ['key' => 'topbar_support_phone',   'label' => 'تلفن (نوار بالا)',            'value' => '021-12345678',                                  'type' => 'text', 'sort' => 20],
                ['key' => 'topbar_trust_text',      'label' => 'متن اعتماد (نوار بالا)',     'value' => 'تحویل سریع | پرداخت امن | ضمانت اصالت کالا',  'type' => 'text', 'sort' => 30],
                ['key' => 'search_placeholder',     'label' => 'متن جستجو (placeholder)',    'value' => 'جستجو در کنسول‌ها و لوازم جانبی...',    'type' => 'text', 'sort' => 40],
                ['key' => 'location_label',         'label' => 'برچسب مکان',                 'value' => 'ارسال به',                                       'type' => 'text', 'sort' => 50],
                ['key' => 'location_default_text',  'label' => 'شهر پیش‌فرض (هدر)',        'value' => 'تهران',                                           'type' => 'text', 'sort' => 60],
                ['key' => 'login_button_text',      'label' => 'متن دکمه ورود',              'value' => 'ورود | ثبت‌نام',                                'type' => 'text', 'sort' => 70],
                ['key' => 'cart_label',             'label' => 'برچسب سبد خرید',            'value' => 'سبد خرید',                                       'type' => 'text', 'sort' => 80],
                ['key' => 'category_menu_title',    'label' => 'عنوان منوی دسته‌بندی',      'value' => 'دسته‌بندی کالاها',                              'type' => 'text', 'sort' => 100],
                ['key' => 'header_promo_text',      'label' => 'متن تبلیغاتی هدر (اختیاری)', 'value' => '',                                             'type' => 'text', 'sort' => 110, 'description' => 'متن اعلان یا تبلیغ ویژه در بالای هدر. خالی بگذارید تا نمایش داده نشود.'],
            ],

            // ── FOOTER ────────────────────────────────────────────────────────

            'footer' => [
                ['key' => 'footer_about_text',       'label' => 'متن درباره (فوتر)',           'value' => 'گیم‌پک اجاره — تحویل سریع، پشتیبانی واقعی.', 'type' => 'textarea', 'sort' => 10],
                ['key' => 'copyright_text',          'label' => 'متن کپی‌رایت',               'value' => 'کلیه حقوق این سایت متعلق به فروشگاه گیم‌پک می‌باشد.',                                         'type' => 'text',     'sort' => 20],
                ['key' => 'support_text',            'label' => 'متن پشتیبانی (فوتر)',        'value' => 'پشتیبانی گیم‌پک',                                                                               'type' => 'text',     'sort' => 30],
                ['key' => 'product_links_title',     'label' => 'عنوان لینک‌های محصولات',    'value' => 'محصولات',                                                                                         'type' => 'text',     'sort' => 40],
                ['key' => 'customer_links_title',    'label' => 'عنوان لینک‌های خدمات',      'value' => 'خدمات مشتریان',                                                                                   'type' => 'text',     'sort' => 50],
                ['key' => 'trust_badges_title',      'label' => 'عنوان نمادهای اعتماد',      'value' => 'نمادهای اعتماد',                                                                                  'type' => 'text',     'sort' => 60],
                ['key' => 'enamad_image',            'label' => 'تصویر نماد اینماد',          'value' => null,                                                                                               'type' => 'image',    'sort' => 70],
                ['key' => 'enamad_link',             'label' => 'لینک اینماد',                'value' => '',                                                                                                 'type' => 'url',      'sort' => 80],
                ['key' => 'samandehi_image',         'label' => 'تصویر نماد ساماندهی',       'value' => null,                                                                                               'type' => 'image',    'sort' => 90],
                ['key' => 'samandehi_link',          'label' => 'لینک ساماندهی',              'value' => '',                                                                                                 'type' => 'url',      'sort' => 100],
                ['key' => 'newsletter_title',        'label' => 'عنوان خبرنامه',              'value' => 'دریافت آخرین اخبار و پیشنهادات',                                                                'type' => 'text',     'sort' => 110],
                ['key' => 'newsletter_placeholder',  'label' => 'متن placeholder خبرنامه',   'value' => 'ایمیل خود را وارد کنید...',                                                                      'type' => 'text',     'sort' => 120],
                ['key' => 'newsletter_button_text',  'label' => 'متن دکمه خبرنامه',          'value' => 'عضویت',                                                                                           'type' => 'text',     'sort' => 130],
            ],

            // ── SEO ───────────────────────────────────────────────────────────

            'seo' => [
                ['key' => 'home_meta_title',                      'label' => 'عنوان متا (صفحه اصلی)',                'value' => 'گیم‌پک اجاره | اجاره کنسول بازی و لوازم جانبی',                                           'type' => 'text',     'sort' => 10],
                ['key' => 'home_meta_description',                'label' => 'توضیحات متا (صفحه اصلی)',             'value' => 'اجاره آنلاین کنسول بازی و لوازم جانبی از گیم‌پک', 'type' => 'textarea', 'sort' => 20],
                ['key' => 'default_product_meta_title_pattern',   'label' => 'الگوی عنوان متا (محصول)',            'value' => '{product_title} | خرید از گیم‌پک',                                                    'type' => 'text',     'sort' => 30, 'description' => 'متغیر: {product_title}'],
                ['key' => 'default_product_meta_description_pattern', 'label' => 'الگوی توضیحات متا (محصول)',     'value' => 'خرید {product_title} با بهترین قیمت از گیم‌پک. تحویل سریع، پرداخت امن.',             'type' => 'textarea', 'sort' => 40, 'description' => 'متغیرها: {product_title}'],
                ['key' => 'open_graph_image',                     'label' => 'تصویر Open Graph',                   'value' => null,                                                                                   'type' => 'image',    'sort' => 70, 'description' => 'تصویر پیش‌فرض برای اشتراک‌گذاری در شبکه‌های اجتماعی (1200×630 پیکسل)'],
                ['key' => 'robots_index',                         'label' => 'robots: index',                       'value' => '1',                                                                                    'type' => 'boolean',  'sort' => 80, 'description' => 'اجازه ایندکس شدن سایت توسط موتورهای جستجو'],
                ['key' => 'robots_follow',                        'label' => 'robots: follow',                      'value' => '1',                                                                                    'type' => 'boolean',  'sort' => 90, 'description' => 'اجازه دنبال کردن لینک‌ها توسط موتورهای جستجو'],
            ],

            // ── NOTIFICATIONS ─────────────────────────────────────────────────

            'notifications' => [
                ['key' => 'otp_sms_text',                   'label' => 'متن SMS کد تایید',          'value' => "کد تایید گیم‌پک: {code}\nاین کد ۵ دقیقه اعتبار دارد.",                             'type' => 'textarea', 'sort' => 10,  'description' => 'متغیرها: {code}, {mobile}', 'public' => false],
                ['key' => 'order_placed_text',              'label' => 'متن SMS ثبت سفارش',         'value' => 'سلام {name}، سفارش شما با شماره {order_number} ثبت شد. جهت پیگیری به پروفایل مراجعه کنید.', 'type' => 'textarea', 'sort' => 20, 'description' => 'متغیرها: {name}, {order_number}'],
                ['key' => 'payment_success_text',           'label' => 'متن SMS پرداخت موفق',       'value' => 'پرداخت شما برای سفارش {order_number} با موفقیت انجام شد.',                          'type' => 'textarea', 'sort' => 30,  'description' => 'متغیرها: {order_number}, {amount}'],
                ['key' => 'payment_failed_text',            'label' => 'متن SMS پرداخت ناموفق',     'value' => 'پرداخت سفارش {order_number} ناموفق بود. لطفاً مجدداً تلاش کنید.',                  'type' => 'textarea', 'sort' => 40,  'description' => 'متغیرها: {order_number}'],
                ['key' => 'restock_notification_text',      'label' => 'متن اعلان موجود شدن کالا', 'value' => 'سلام {name}، محصول {product_title} مجدداً موجود شد. همین الان از گیم‌پک خریداری کنید!', 'type' => 'textarea', 'sort' => 70, 'description' => 'متغیرها: {name}, {product_title}'],
            ],

            // ── CHECKOUT ──────────────────────────────────────────────────────

            'checkout' => [
                ['key' => 'cart_empty_message',                'label' => 'پیام سبد خالی',                    'value' => 'سبد خرید شما خالی است',                                                         'type' => 'text',     'sort' => 10],
                ['key' => 'cart_cta_text',                     'label' => 'متن دکمه بازگشت به فروشگاه',      'value' => 'رفتن به فروشگاه',                                                               'type' => 'text',     'sort' => 20],
                ['key' => 'shipping_page_title',               'label' => 'عنوان صفحه ارسال',                 'value' => 'اطلاعات ارسال',                                                                 'type' => 'text',     'sort' => 30],
                ['key' => 'shipping_description',              'label' => 'توضیح صفحه ارسال',                 'value' => 'آدرس و اطلاعات تحویل سفارش خود را وارد کنید.',                                'type' => 'textarea', 'sort' => 40],
                ['key' => 'payment_button_text',               'label' => 'متن دکمه پرداخت',                 'value' => 'پرداخت و نهایی کردن سفارش',                                                     'type' => 'text',     'sort' => 70],
                ['key' => 'payment_success_message',           'label' => 'پیام پرداخت موفق',               'value' => 'پرداخت شما با موفقیت انجام شد!',                                                 'type' => 'text',     'sort' => 80],
                ['key' => 'payment_failed_message',            'label' => 'پیام پرداخت ناموفق',             'value' => 'پرداخت ناموفق بود. لطفاً دوباره امتحان کنید.',                                  'type' => 'text',     'sort' => 90],
                ['key' => 'order_placed_message',              'label' => 'پیام ثبت سفارش',                  'value' => 'سفارش شما با موفقیت ثبت شد!',                                                   'type' => 'text',     'sort' => 100],
            ],

            // ── AUTH ──────────────────────────────────────────────────────────

            'auth' => [
                ['key' => 'login_page_title',         'label' => 'عنوان صفحه ورود',               'value' => 'ورود به حساب کاربری',                    'type' => 'text', 'sort' => 10],
                ['key' => 'login_page_subtitle',      'label' => 'زیرعنوان صفحه ورود',            'value' => 'با شماره موبایل خود وارد شوید',          'type' => 'text', 'sort' => 20],
                ['key' => 'mobile_input_placeholder', 'label' => 'placeholder شماره موبایل',      'value' => 'شماره موبایل خود را وارد کنید',          'type' => 'text', 'sort' => 30],
                ['key' => 'otp_input_placeholder',    'label' => 'placeholder کد OTP',            'value' => 'کد ۵ رقمی ارسال‌شده را وارد کنید',     'type' => 'text', 'sort' => 40],
                ['key' => 'send_otp_button_text',     'label' => 'متن دکمه ارسال کد',             'value' => 'دریافت کد تایید',                        'type' => 'text', 'sort' => 50],
                ['key' => 'resend_otp_text',          'label' => 'متن ارسال مجدد کد',             'value' => 'ارسال مجدد کد',                          'type' => 'text', 'sort' => 60],
                ['key' => 'login_success_message',    'label' => 'پیام ورود موفق',                'value' => 'خوش آمدید!',                             'type' => 'text', 'sort' => 70],
                ['key' => 'login_error_message',      'label' => 'پیام خطای ورود',               'value' => 'کد وارد شده نامعتبر است.',               'type' => 'text', 'sort' => 80],
                ['key' => 'complete_profile_title',   'label' => 'عنوان تکمیل پروفایل',          'value' => 'تکمیل اطلاعات حساب',                     'type' => 'text', 'sort' => 90],
            ],

            // ── PROFILE ───────────────────────────────────────────────────────

            'profile' => [
                ['key' => 'profile_page_title',               'label' => 'عنوان صفحه پروفایل',               'value' => 'حساب کاربری',                          'type' => 'text', 'sort' => 10],
                ['key' => 'orders_section_title',             'label' => 'عنوان بخش سفارش‌ها',             'value' => 'سفارش‌های من',                         'type' => 'text', 'sort' => 20],
                ['key' => 'addresses_section_title',          'label' => 'عنوان بخش آدرس‌ها',              'value' => 'آدرس‌های من',                          'type' => 'text', 'sort' => 40],
                ['key' => 'empty_order_message',              'label' => 'پیام نبود سفارش',                 'value' => 'هنوز سفارشی ثبت نکرده‌اید',           'type' => 'text', 'sort' => 60],
                ['key' => 'address_empty_message',            'label' => 'پیام نبود آدرس',                  'value' => 'آدرسی ثبت نکرده‌اید',                 'type' => 'text', 'sort' => 90],
            ],

            // ── PRODUCT DISPLAY ───────────────────────────────────────────────

            'product_display' => [
                ['key' => 'add_to_cart_text',              'label' => 'متن دکمه افزودن به سبد',          'value' => 'افزودن به سبد',         'type' => 'text',    'sort' => 10],
                ['key' => 'out_of_stock_text',             'label' => 'متن وضعیت ناموجود',               'value' => 'ناموجود',               'type' => 'text',    'sort' => 20],
                ['key' => 'notify_me_text',                'label' => 'متن دکمه اطلاع از موجودی',        'value' => 'اطلاع از موجودی',       'type' => 'text',    'sort' => 30],
                ['key' => 'coming_soon_text',              'label' => 'متن وضعیت بزودی',                 'value' => 'بزودی',                 'type' => 'text',    'sort' => 40],
                ['key' => 'preorder_text',                 'label' => 'متن وضعیت پیش‌خرید',             'value' => 'پیش‌خرید',             'type' => 'text',    'sort' => 50],
                ['key' => 'show_discount_badge',           'label' => 'نمایش برچسب درصد تخفیف',         'value' => '1',                     'type' => 'boolean', 'sort' => 70],
                ['key' => 'show_old_price',                'label' => 'نمایش قیمت قبل از تخفیف',        'value' => '1',                     'type' => 'boolean', 'sort' => 80],
                ['key' => 'show_stock_badge',              'label' => 'نمایش وضعیت موجودی روی کارت',    'value' => '1',                     'type' => 'boolean', 'sort' => 90],
                ['key' => 'show_region_badge',             'label' => 'نمایش ریجن روی کارت محصول',      'value' => '1',                     'type' => 'boolean', 'sort' => 100],
            ],
        ];
    }
}
