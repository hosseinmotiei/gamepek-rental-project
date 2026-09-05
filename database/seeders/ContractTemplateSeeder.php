<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds ONE placeholder contract template so the chain is runnable end to end.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  THIS IS NOT LEGAL TEXT. TODO(business) B12.
 *
 *  The body below is a structural skeleton that names the parties, the device
 *  and the amounts so the generate/accept/sign flow can be exercised and
 *  tested. It states no obligations, no liability, no penalty and no
 *  cancellation terms, because those are the owner's decisions and a lawyer's
 *  wording -- inventing them would be worse than leaving them absent.
 *
 *  Replace it with the real agreement as a NEW version (contract_templates is
 *  versioned and a published row is immutable); do not edit this one.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $key = config('rental.contract.template_key', 'rental_agreement');

        if (ContractTemplate::where('key', $key)->exists()) {
            return;
        }

        ContractTemplate::create([
            'key' => $key,
            'version' => 1,
            'title' => 'قرارداد اجاره کنسول بازی (نسخه آزمایشی — فاقد اعتبار حقوقی)',
            'body' => <<<'HTML'
            <section dir="rtl">
                <h1>قرارداد اجاره</h1>

                <p class="notice">
                    این متن یک نمونه ساختاری است و هیچ اعتبار حقوقی ندارد.
                    متن رسمی قرارداد باید توسط مالک کسب‌وکار تأیید و به‌عنوان
                    نسخه جدید ثبت شود.
                </p>

                <h2>مشخصات طرفین</h2>
                <ul>
                    <li>شماره درخواست: {{application_number}}</li>
                    <li>نام مستأجر: {{customer_name}}</li>
                    <li>شماره موبایل: {{customer_mobile}}</li>
                    <li>کد ملی: {{customer_national_code_mask}}</li>
                </ul>

                <h2>موضوع اجاره</h2>
                <ul>
                    <li>دستگاه: {{product_title}}</li>
                    <li>تاریخ شروع: {{start_date}}</li>
                    <li>تاریخ پایان: {{end_date}}</li>
                    <li>مدت: {{days}} روز</li>
                </ul>

                <h2>مبالغ</h2>
                <ul>
                    <li>اجاره‌بها: {{rental_total}} تومان</li>
                    <li>مبلغ پرداختی در این مرحله: {{payable_now}} تومان</li>
                    <li>ودیعه (بلوکه، نه دریافتی): {{deposit_amount}} تومان</li>
                </ul>

                <h2>شرایط</h2>
                <p class="todo">
                    شرایط تحویل، بازگشت، خسارت، تأخیر، لغو و آزادسازی ودیعه
                    هنوز تعیین نشده‌اند و در نسخه رسمی قرارداد درج خواهند شد.
                </p>

                <p>تاریخ صدور: {{issued_at}}</p>
            </section>
            HTML,
            'variables' => [
                'application_number', 'customer_name', 'customer_mobile',
                'customer_national_code_mask', 'product_title',
                'start_date', 'end_date', 'days',
                'rental_total', 'payable_now', 'deposit_amount', 'issued_at',
            ],
            'is_active' => true,
            'published_at' => now(),
        ]);
    }
}
