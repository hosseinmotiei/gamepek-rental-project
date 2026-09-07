# مستند توسعه — زنجیره اجاره، ناوبری و تقویم شمسی

<div dir="rtl">

> این سند فارسی و راست‌به‌چپ است. کد، نام فایل، نام route و پیام‌های خطای فنی
> عمداً انگلیسی مانده‌اند تا قابل جستجو باشند.
>
> آخرین به‌روزرسانی: ۱۵ شهریور ۱۴۰۵ (2026-09-06)
> وضعیت تست‌ها هنگام نگارش: `OK (170 tests, 1135 assertions)`

---

## فهرست

1. [خلاصه یک‌خطی](#۱-خلاصه-یکخطی)
2. [نقشه کلی زنجیره اجاره](#۲-نقشه-کلی-زنجیره-اجاره)
3. [تقویم شمسی — چطور کار می‌کند](#۳-تقویم-شمسی--چطور-کار-میکند)
4. [جستجو و رزرو — مسیر داده](#۴-جستجو-و-رزرو--مسیر-داده)
5. [صفحه خانه](#۵-صفحه-خانه)
6. [ناوبری، دسته‌بندی‌ها و هدر](#۶-ناوبری-دستهبندیها-و-هدر)
7. [پرداخت](#۷-پرداخت)
8. [احراز هویت، بانک، ضمانت، قرارداد](#۸-احراز-هویت-بانک-ضمانت-قرارداد)
9. [پنل ادمین](#۹-پنل-ادمین)
10. [لایه ممیزی (Audit)](#۱۰-لایه-ممیزی-audit)
11. [باگ‌های واقعی که پیدا و رفع شدند](#۱۱-باگهای-واقعی-که-پیدا-و-رفع-شدند)
12. [قواعد RTL و فارسی‌نویسی در این پروژه](#۱۲-قواعد-rtl-و-فارسینویسی-در-این-پروژه)
13. [تست‌ها](#۱۳-تستها)
14. [راه‌اندازی محلی و سناریوی تست دستی](#۱۴-راهاندازی-محلی-و-سناریوی-تست-دستی)
15. [TODOهای کسب‌وکار (اختراع نشده‌اند)](#۱۵-todoهای-کسبوکار-اختراع-نشدهاند)
16. [فهرست فایل‌های تغییریافته](#۱۶-فهرست-فایلهای-تغییریافته)

---

## ۱. خلاصه یک‌خطی

کاتالوگ فقط-خواندنی اجاره تبدیل شد به یک مسیر کامل و قابل ممیزی:
**انتخاب شهر و بازه شمسی → دیدن دستگاه‌های آزاد → رزرو → احراز هویت سطح ۲ →
تأیید مالکیت بانکی → پرداخت → ضمانت → قرارداد → امضا → تأیید نهایی ادمین**؛
به‌همراه بازسازی صفحه خانه، منوی دسته‌بندی و یک تقویم شمسی واقعی.

---

## ۲. نقشه کلی زنجیره اجاره

```
Draft
 └─ IdentityPending → IdentityVerified
     └─ BankPending → BankVerified
         └─ ReservationHeld
             └─ PaymentPending → Paid
                 └─ GuaranteePending → GuaranteeVerified
                     └─ ContractGenerated → ContractAccepted → ContractSigned
                         └─ AwaitingFinalApproval → Approved
                             └─ Active → Returned → Closed   ← سیاست تعریف نشده
```

### چرخه پس از تأیید نهایی — مسدودشده به‌دلیل نبود سیاست

`Approved → Active → Returned → Closed` در enum وجود دارد اما **هیچ‌کدام رخ
نمی‌دهد**، چون هیچ‌جای پروژه ماشه‌ای برای آن‌ها تعریف نکرده است
(`TODO(business) B14`):

- **Active** — فعال‌شدن با تحویل دستگاه است، با تاریخ شروع رزرو، یا با اقدام
  ادمین؟ تعریف نشده. `advance()` هرگز `Active` را استنتاج نمی‌کند و گذشتنِ
  تاریخ شروع هیچ اثری ندارد.
- **Returned** — چه کسی بازگشت را تأیید می‌کند و آیا `return_video`، بازرسی یا
  ارزیابی خسارت لازم است؟ تعریف نشده.
- **Closed** — بستن پرونده احتمالاً به آزادسازی ودیعه (B4) و مدت نگهداری مدیا
  (B11) وابسته است؛ هر دو تعیین‌نشده‌اند.

آنچه پیاده شده، فقط مکانیزم است:
`RentalChainOrchestrator::transitionPostApproval()` تنها نقطه اتصال ماشه در
آینده است. تا وقتی `config('rental.lifecycle.*_trigger')` برابر `null` باشد،
هر فراخوانی رد می‌شود و `rental_application.policy_undefined` ثبت می‌گردد.
هیچ route ای این متد را در معرض قرار نمی‌دهد.

قواعد قطعی‌شده:

- `Approved` هرگز پس‌رفت نمی‌کند؛ `nextState()` برای درخواست تأییدشده همان
  `Approved` را برمی‌گرداند، پس باز کردن صفحه توسط مشتری آن را باطل نمی‌کند.
- پس از تأیید نهایی، نردبان یک‌پله‌ای است: `Approved → Returned`،
  `Approved → Closed` و `Active → Closed` رد می‌شوند.
- `Closed` وضعیت پایانی است؛ هیچ گردش‌کار بازگشایی تعریف نشده و ساخته نشده است.
- `state` در `RentalApplication` **fillable نیست**؛ نه ورودی درخواست، نه فیلد
  مخفی و نه پارامتر route نمی‌تواند آن را بنویسد.

### اصل معماری: وضعیت «استنتاج» می‌شود، «دستور» داده نمی‌شود

`App\Services\Rental\RentalChainOrchestrator` تنها نویسنده ستون
`rental_applications.state` است. هیچ کنترلری وضعیت را مستقیم نمی‌نویسد؛ هر
کنترلر فقط **یک رکورد فرزند** را تغییر می‌دهد (هویت، حساب بانکی، رزرو، سفارش،
ضمانت، قرارداد) و بعد `advance()` را صدا می‌زند.

`nextState()` یک نردبان گزاره‌ای **خالص** است: از بالا می‌خواند و اولین شرطی که
برقرار باشد، دورترین نقطه‌ای است که درخواست واقعاً به آن رسیده. چون فقط داده‌ی
ذخیره‌شده را می‌خواند، اجرای دوباره‌اش بعد از کرش همان نتیجه را می‌دهد.

```php
// app/Services/Rental/RentalChainOrchestrator.php
if ($contract?->state === ContractState::Signed) {
    return RentalApplicationState::AwaitingFinalApproval;
}
```

سه یال هستند که استنتاج نمی‌شوند و تصمیم انسان‌اند: `approve()`، `reject()`،
`cancel()`.

### Idempotency

`advance()` ردیف را با `lockForUpdate()` قفل می‌کند، وضعیت را استنتاج می‌کند و
اگر تغییری نبود **هیچ‌چیز نمی‌نویسد** — نه transition، نه ردیف ممیزی. این با
تست `test_advancing_twice_writes_no_second_transition` قفل شده است.

---

## ۳. تقویم شمسی — چطور کار می‌کند

**فایل:** `resources/views/partials/jalali-datepicker.blade.php`

### چرا کتابخانه نصب نشد

این پروژه **build step ندارد**: نه `package.json`، نه Vite، نه `resources/js`.
Tailwind و Vazirmatn از CDN می‌آیند و همه JS داخل Blade است (طبق `CLAUDE.md`).
افزودن یک dependency جدید تصمیمی است که باید با شما باشد، پس تبدیل تاریخ
**پورت مستقیم** `App\Support\Rental\Jalali` است — همان الگوریتم، همان قاعده
کبیسه.

### شمسی در منطق، نه فقط در ظاهر

| موضوع | پیاده‌سازی |
|---|---|
| ماه‌ها | ۶ ماه اول ۳۱ روز، ۵ ماه بعد ۳۰ روز، اسفند ۲۹ یا ۳۰ روز |
| کبیسه | جدول `LEAP_BREAKS` (همان جدول PHP) |
| شروع هفته | شنبه (ستون اول جدول) |
| ارقام | فارسی (`۰۱۲۳۴۵۶۷۸۹`) |
| جهت پیمایش ماه | `chevron-right` = ماه قبل، `chevron-left` = ماه بعد (منطق RTL) |

### قرارداد HTML

```html
<div data-jdp data-jdp-min="2026-09-05" data-jdp-role="from">
    <input type="hidden" name="from" value="2026-09-25">   <!-- میلادی ISO → بک‌اند -->
    <input type="text" readonly data-jdp-display>          <!-- شمسی → کاربر -->
</div>
```

- کاربر **هرگز تایپ نمی‌کند**؛ فیلد نمایشی `readonly` است و با کلیک یا
  `Enter`/`Space` تقویم باز می‌شود.
- مقدار میلادی از انتخاب شمسی **مشتق** می‌شود، نه برعکس.
- `Escape` و کلیک بیرون، تقویم را می‌بندد.

### تاریخ پایان قبل از شروع

فقط اعتبارسنجی نیست — **اصلاً قابل انتخاب نیست**:

```js
// partials/rental-search-bar.blade.php
function syncEndFloor() {
    const next = new Date(fromInput.value + 'T00:00:00');
    next.setDate(next.getDate() + 1);
    toRoot.__jdp.setMin(next.toISOString().slice(0, 10));   // روزهای قبل disable می‌شوند
}
```

`setMin()` اگر انتخاب قبلیِ تاریخ پایان با کف جدید نامعتبر شده باشد، آن را
**پاک می‌کند** — وگرنه دقیقاً از همین‌جا یک بازه معکوس رد می‌شد.

### صحت تبدیل، اثبات‌شده

سمت PHP با `test_the_jalali_conversion_round_trips` و
`test_esfand_length_follows_the_jalali_leap_rule` تست شده؛ خروجی JS با اجرای
مستقیم همان توابع در Node با همان ورودی‌ها مقایسه شد:

```
2026-09-05 => 1405-6-14 => 2026-09-05
2025-03-21 => 1404-1-1  => 2025-03-21   (نوروز)
2024-03-19 => 1402-12-29 => 2024-03-19
2024-03-20 => 1403-1-1  => 2024-03-20   (نوروز)
len 1403/12 = 30   len 1404/12 = 29
```

> **قاعده نگهداری:** اگر `App\Support\Rental\Jalali` تغییر کرد، پورت JS هم باید
> همان تغییر را بگیرد. این وابستگی در docblock هر دو فایل نوشته شده.

---

## ۴. جستجو و رزرو — مسیر داده

### یک کامپوننت، سه جا

`resources/views/partials/rental-search-bar.blade.php` — هیچ کپی دومی وجود
ندارد. در سه جا `@include` می‌شود:

| صفحه | variant |
|---|---|
| Hero صفحه خانه | `hero` |
| صفحه نتایج `/search` | `compact` |
| لیست محصولات `/products` | `catalog` |

فرم یک `GET` ساده به route **موجود** `products.search` است — route جدیدی ساخته
نشد:

```
/search?city=تهران&from=2026-09-25&to=2026-09-28
```

### فیلتر تاریخ واقعی است

```php
// app/Services/CatalogService.php
if (! empty($filters['rental_from']) && ! empty($filters['rental_to'])) {
    $query->whereNotNull('attributes->_rental')          // فقط اقلام اجاره‌ای
        ->whereDoesntHave('rentalReservations', fn ($q) => $q
            ->blocking()                                  // held / awaiting_payment / paid / active
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from));
}
```

یعنی دستگاهی که در آن بازه رزرو دارد، در نتایج **نمایش داده نمی‌شود** و بعد از
پایان بازه دوباره برمی‌گردد. هر دو حالت تست دارند.

### اعتبارسنجی سمت سرور (مرجع نهایی)

`ProductController::rentalWindow()` — مرورگر مرجع نیست:

| حالت | پیام |
|---|---|
| فقط یکی از دو تاریخ | «برای جستجو بر اساس تاریخ، هر دو تاریخ شروع و پایان را وارد کنید.» |
| فرمت خراب | «فرمت تاریخ نامعتبر است.» |
| شروع در گذشته | «تاریخ شروع نمی‌تواند در گذشته باشد.» |
| پایان ≤ شروع | «تاریخ پایان باید بعد از تاریخ شروع باشد.» |
| بیش از سقف | «حداکثر مدت اجاره ۹۰ روز است.» |
| شهر خارج از فهرست | «شهر انتخاب‌شده در حال حاضر پشتیبانی نمی‌شود.» |

### شهرها

`config('rental.search.cities')` فعلاً `['تهران']` است. این **داده جعلی نیست**:
خود اپ در `StoreAddressRequest` قانون `city.in:تهران` دارد. اضافه‌کردن شهر
جدید باید همراه با تغییر همان Request باشد.

### بازه یک‌بار انتخاب می‌شود

مسیر انتقال بازه:

```
نوار جستجو (خانه یا لیست محصولات)
   └─ query string:  ?city=…&from=…&to=…
       └─ product-card:  لینک محصول همان پارامترها را حمل می‌کند
           └─ صفحه محصول:  request('from') / request('to') را می‌خواند
               └─ رزرو:  start_date = from ، days = diff(from, to)
```

**در صفحه محصول تقویم دوم وجود ندارد** — دو تقویم یعنی دو جای متناقض برای یک
بازه. اگر بازه‌ای انتخاب نشده باشد، پنل به‌جای فرم، پیام «ابتدا تاریخ شروع و
پایان اجاره را انتخاب کنید» و لینک بازگشت به جستجو را نشان می‌دهد.

> **نکته شمارش روز:** رزرو، روزها را **شامل** می‌شمارد
> (`end = start + days - 1`). پس اگر کاربر ۳ تا ۶ مهر را انتخاب کند
> (۳ روز)، بازه رزروشده ۳ تا ۵ مهر است و روز ششم روز بازگشت. پنل همین بازهٔ
> واقعیِ رزرو را نمایش می‌دهد، نه `to` خام را.

### هیچ قیمتی از فرانت پذیرفته نمی‌شود

`RentalApplicationController::reserve()` فقط `product_id`، `start_date`،
`days` و `extra_controller` را می‌خواند. قیمت از `RentalPricingService::quote()`
سمت سرور می‌آید و در رزرو snapshot می‌شود. JS داخل پنل فقط **پیش‌نمایش زنده**
است.

---

## ۵. صفحه خانه

ترتیب نهایی:

```
Header
 └─ Hero  (عنوان + توضیح + نوار جستجو + سه نشانه اعتماد)
     └─ محصولات پیشنهادی
         └─ دسته‌بندی‌ها
             └─ بنرها / ویژگی‌ها / دسته‌های سریع / پیشنهاد ویژه / پرطرفدارها
                 └─ Footer
```

### محصولات پیشنهادی

داده واقعی، نه hardcode:

```php
// app/Services/CatalogService.php — getHomePageData()
'rentable' => Product::active()
    ->whereNotNull('attributes->_rental')   // همان کلیدی که RentalItem::supports() می‌خواند
    ->with('category')->latest('id')->limit(8)->get(),
```

چرا `featured` نه؟ چون یک فلگ تحریریه است که ممکن است هیچ‌وقت ست نشده باشد؛
سؤال واقعی «آیا این قلم اجاره‌ای است؟» است.

رندر با **همان تک‌کارت کاتالوگ** `partials/product-card.blade.php` انجام می‌شود
(کارت از قبل اجاره‌آگاه است: نرخ روزانه نشان می‌دهد، دکمه افزودن به سبد ندارد).
اگر هیچ قلم اجاره‌ای نباشد، کل بخش رندر نمی‌شود — ریل خالی نمایش داده نمی‌شود.

### دسته‌بندی‌ها

از همان درختی می‌خواند که هدر می‌خواند (`MenuItem::categoryTree()`), پس یک
ویرایش در پنل ادمین، هر سه جا (هدر، منوی موبایل، صفحه خانه) را با هم به‌روز
می‌کند.

---

## ۶. ناوبری، دسته‌بندی‌ها و هدر

### ساختار منو

```
کرایه کنسول            → /products?category=console-rental
   ├─ Xbox             → /products?category=xbox-rental
   └─ PlayStation      → /products?category=playstation-rental
کرایه بازی‌های دیسکی   → /products?category=disc-games-rental
پشتیبانی               → /contact
قوانین و مقررات        → /terms
درباره ما              → /about
```

**دو لایه، عمداً:**

- تاکسونومی کاتالوگ (کرایه کنسول، Xbox، PlayStation، بازی‌های دیسکی) به‌صورت
  ردیف واقعی در جدول `categories` ساخته می‌شود تا محصول واقعاً زیرشان ثبت شود
  و فیلتر `/products?category=…` واقعاً کار کند.
- ورودی‌های اطلاعاتی (پشتیبانی، قوانین، درباره ما) صرفاً `menu_items` هستند که
  به routeهای موجود اشاره می‌کنند. اینها ناوبری‌اند، نه تاکسونومی.

Seeder: `database/seeders/RentalNavigationSeeder.php` — کاملاً idempotent
(`firstOrCreate` روی کلید طبیعی)، پس اجرای دوباره‌اش چیزی را تکراری یا بازنویسی
نمی‌کند.

### چرا منو قبلاً «کار نمی‌کرد»

دو علت واقعی، نه CSS:

1. جدول `menu_items` برای location های `category_menu` و `header_nav_links`
   **صفر ردیف** داشت (MenuSeeder عمداً خالی‌شان گذاشته بود). کل dropdown داخل
   `@if($categoryMenuTabs->isNotEmpty())` بود → دکمه رندر می‌شد ولی هیچ پنلی
   وجود نداشت. مودال موبایل هم باز می‌شد و خالی بود.
2. مگا-منو فقط `group-hover` بود → روی لمس و کیبورد کاملاً مرده.

**رفع:**

- `MenuItem::categoryTree()` اگر منو خالی باشد، به درخت واقعی `Category` سقوط
  می‌کند و آن را به همان قرارداد `title/icon/resolved_url/children` نگاشت
  می‌کند → **یک مسیر markup، نه دو تا**.
- باز/بسته با کلیک + hover + `Escape` + کلیک بیرون + `aria-expanded`.
- اگر واقعاً هیچ دسته‌ای نبود: empty-state با لینک به `/products` — نه پنل سفید.

### هدر

- «پیگیری سفارش» و «قوانین و مقررات» از **نوار بالایی حذف شدند**.
- **routeها دست‌نخورده‌اند:** `/orders` و `/terms` هر دو کار می‌کنند و صفحاتشان
  حذف نشده‌اند.
- محل جدید: هر دو در فوتر (بخش «راهنمای مشتریان»)، به‌علاوه «قوانین و مقررات»
  در منوی دسته‌بندی‌ها.
- دکمه «ارسال به تهران» که `<button>` بدون handler بود (کلیک‌پذیر به‌نظر، مرده
  در عمل) به `<div>` اطلاعاتی تبدیل شد.

ساختار فعلی هدر:

```
دسکتاپ:  Logo | جستجو | ارسال به | حساب کاربری | سبد خرید
          ─────────────────────────────────────────────────
          دسته‌بندی کالاها ▾ | لینک‌های ناوبری

موبایل:  Logo | جستجو            (+ نوار پایین: خانه | دسته‌بندی | سبد | جستجو | پروفایل)
```

---

## ۷. پرداخت

### قرارداد Gateway

`App\Services\Payment\Contracts\PaymentGatewayInterface`:

| متد | نقش | کد سند |
|---|---|---|
| `request()` | ایجاد تراکنش و URL انتقال | PAY-01 |
| `parseCallback()` | فقط **تجزیه** بازگشت — تصمیم نمی‌گیرد | PAY-02 |
| `verify()` | **تنها منبع «پرداخت شد»** | PAY-03 |
| `status()` | استعلام وضعیت | PAY-04 |
| `refund()` | بازگشت وجه | PAY-05 |
| — | مغایرت‌گیری (`payments:reconcile`) | PAY-06 |

`GatewayCallback` عمداً فیلد `success` **ندارد**. موفقیت فقط از `verify()`
می‌آید.

### الگوی تسویه

```php
// app/Services/PaymentService.php — settle()
DB::transaction(function () {
    $locked = PaymentTransaction::where('id', $id)->lockForUpdate()->first();

    if ($locked->status === 'success') return  /* همان نتیجه ذخیره‌شده */;
    if ($locked->status !== 'pending')  return  /* قبلاً پردازش شده */;

    $verification = $adapter->verify($locked);
    // Unknown            → pending می‌ماند برای reconcile
    // !isVerifiedPaid()  → failed
    // مبلغ نامنطبق       → Mismatch + failed
    // در غیر این صورت    → success + markAsPaid + audit
});
```

### سفارش اجاره ≠ سفارش فروشگاه

سفارش اجاره `order_items` ندارد (رزرو است، نه سبد). پس callback تشخیص می‌دهد و
به صفحه درخواست برمی‌گرداند:

```php
// CheckoutController::paymentCallback()
$rentalApplication = RentalApplication::where('order_id', $order->id)->first();
if ($rentalApplication) {
    app(RentalChainOrchestrator::class)->advance($rentalApplication, 'payment verified');
    return redirect()->route('rental.applications.show', $rentalApplication)
        ->with('success', 'پرداخت با موفقیت انجام شد.');
}
```

مبلغ سفارش `payable_now` است. **ودیعه هرگز شارژ نمی‌شود** — بلوکه است، نه
دریافتی (TODO B4).

---

## ۸. احراز هویت، بانک، ضمانت، قرارداد

### داده حساس

| داده | ذخیره |
|---|---|
| کد ملی | رمزنگاری‌شده + HMAC برای جستجو + ماسک برای نمایش |
| کارت / شبا | همان الگو |
| شناسه صیاد | همان الگو — `sayad_id_encrypted` + `sayad_id_hash` (یکتا) + `sayad_id_mask` |
| کد OTP | فقط hash کلیددار — **متن ساده هرگز ذخیره نمی‌شود** |
| ویدیو/سند | دیسک خصوصی `verification`، خارج از `public/storage` |

فایل حساس فقط با `URL::temporarySignedRoute` + بررسی مالکیت + هدر
`Cache-Control: private, no-store` سرو می‌شود و **هر خواندن یک ردیف ممیزی**
می‌نویسد.

### هیچ provider واقعی وصل نیست

همه استعلام‌ها (شاهکار، ثبت احوال، liveness، face match، مالکیت بانکی، صیاد)
`interface` + `Fake` + `Unconfigured` دارند. `Fake` قطعی است و به شبکه نمی‌رود؛
`Unconfigured` استثنا پرتاب می‌کند (fail closed). driver `fake` خارج از
`local|testing` رد می‌شود.

**هیچ endpoint، کد خطا، مرجع حقوقی یا تعرفه‌ای اختراع نشده است.**

### امضای قرارداد

- صدور قرارداد فقط از `GuaranteeVerified` ممکن است؛ رده‌های قرارداد در
  `nextState()` هم به ضمانت تأییدشده مشروط‌اند تا یک ردیف قرارداد نتواند
  پرداخت و ضمانت را دور بزند.
- پذیرش قرارداد فقط از `ContractGenerated` ممکن است و اقدامی صریح از سوی
  کاربر است؛ صرفِ باز کردن صفحه قرارداد پذیرش محسوب نمی‌شود. پذیرش هیچ‌گاه
  snapshot را بازتولید نمی‌کند و `accepted_by_user_id` پذیرنده را ثبت می‌کند.
- قالب **نسخه‌بندی‌شده** است؛ ردیف منتشرشده تغییرناپذیر است.
- متن رندرشده در قرارداد **snapshot** می‌شود + `content_hash` (sha256) — قرارداد
  امضاشده دیگر به قالب وابسته نیست.
- رندر با `strtr` روی مقادیر `e()`-شده انجام می‌شود، **نه** `Blade::render()`؛
  قالبِ ادمین‌ویرایش‌پذیر نباید مسیر RCE شود.
- امضا `hash_hmac('sha256', contentHash|userId|signedAt, APP_KEY)` است.
  **این اعتبار حقوقی/PKI ندارد** و در docblock صریح گفته شده.
- کد امضا در جدول `contract_signature_otps` به همان قرارداد و همان امضاکننده
  گره خورده است؛ فقط hash کلیددار ذخیره می‌شود، یک‌بارمصرف است، انقضا و سقف
  تلاش دارد و کد ورود (`otp_codes`) هرگز قرارداد را امضا نمی‌کند.
- `Approved` هیچ‌گاه از `advance()` مشتق نمی‌شود؛ تنها `approve()` ادمین آن را
  می‌نویسد و پیش از آن امضا، یکپارچگی متن و اعتبار امضا بررسی می‌شود.

---

## ۹. پنل ادمین

| مسیر | کار |
|---|---|
| `/admin/verifications` | صف KYC، تأیید/رد دستی، مشاهده استعلام‌ها |
| `/admin/rental-applications` | لیست + جزئیات + بازخوانی وضعیت + تأیید/رد نهایی |
| `/admin/rental-applications/{n}` | ضمانت (تأیید/رد)، قرارداد (ابطال)، تاریخچه |
| `/admin/audit-events` | لاگ ممیزی با فیلتر action / result / correlation_id |

همه از کامپوننت‌های مشترک `resources/views/components/admin/` ساخته شده‌اند
(الگوی مرجع `admin/users/index.blade.php`)، اقدامات مخرب با `data-confirm`.

هر ۱۱ permission جدید در `UserSeeder` ثبت شده — هیچ `can()` بدون seed نمانده.

**مسیر URL درخواست‌ها با `application_number` است، نه id** (شماره یکتاست، همان
چیزی است که مشتری می‌بیند، و id ترتیبی در URL منتشر نمی‌شود).

---

## ۱۰. لایه ممیزی (Audit)

جدول `audit_events` — فقط-افزودنی، بدون `updated_at`.

هر ردیف: `actor_type`/`actor_id`/`actor_label`، `action`، `resource_type`/
`resource_id`، `result` (success | failure | denied)، `correlation_id`،
`request_id`، `ip_address`، `user_agent`، `context` (JSON)، `occurred_at`.

- `AssignCorrelationId` middleware در ابتدای هر درخواست یک UUID می‌سازد، پس
  همه ردیف‌های یک درخواست با هم قابل ردیابی‌اند. در تاریخچه درخواست، خود
  `correlation_id` به لاگ ممیزی لینک است.
- `AuditLogger` کلیدهای حساس را در **هر عمقی** پاک می‌کند:
  `national_code`، `pan`، `card_number`، `iban`، `sheba`، `sayad_id`، `otp`،
  `code`، `password`، `secret`، `token`، `api_key`.
- خطای ثبت ممیزی هرگز به کاربر منتشر نمی‌شود (لاگ می‌شود).

`activity_logs` و `user_activity_logs` قدیمی دست‌نخورده‌اند و همچنان نوشته
می‌شوند.

---

## ۱۱. باگ‌های واقعی که پیدا و رفع شدند

| # | باگ | اثر واقعی |
|---|---|---|
| ۱ | `items.digitalCode` در ۵ نقطه eager-load می‌شد ولی چنین رابطه‌ای وجود ندارد | callback پرداخت mock و صفحه سفارش ادمین هر دو `RelationNotFoundException` می‌دادند |
| ۲ | `markAsPaid()` پارامتر `$trackingCode` را داخل closure نمی‌برد | کد رهگیری هرگز ذخیره نمی‌شد |
| ۳ | `$user->national_code` / `birth_date` ستون ندارند و `shouldBeStrict()` روشن است | کرش صفحه پروفایل |
| ۴ | **حفره امنیتی:** gateway ساختگی موفقیت را از `Status=OK` در query string می‌خواند | هر بازدیدکننده با ویرایش URL می‌توانست هر سفارش pending را «پرداخت‌شده» کند |
| ۵ | `throttle:6,10` برای کاربر لاگین‌شده فقط با **user id** کلید می‌خورد، نه route | احراز هویت + بانک + ضمانت سطل OTP امضا را خالی می‌کرد → کاربر وسط امضا **429** می‌گرفت |
| ۶ | `runCheck()` هویت را به `Checking` نمی‌برد | `promote()` هرگز نمی‌توانست به `ManualReview` برود؛ پرونده در `Submitted` گیر می‌کرد |
| ۷ | `getResolvedUrlAttribute()` رابطه `category` را lazy-load می‌کرد؛ با strict mode استثنا می‌داد و catch خودش آن را به `#` تبدیل می‌کرد | **همه لینک‌های دسته‌بندی در منو مرده بودند** |
| ۸ | `lookupHolderName()` در تب کیف پول، کد کارت را hash می‌کرد و یکی از ۶ نام فارسی جعلی را نشان می‌داد | داده ساختگی به‌جای پاسخ بانک |
| ۹ | سفارش اجاره روی صفحه موفقیت فروشگاه می‌نشست که `order_items` می‌خواند | صفحه پرداخت موفق اجاره خراب بود |
| ۱۰ | `paymentCallback` نوع بازگشتی `View` داشت | redirect غیرممکن بود (TypeError) |
| ۱۱ | selector دکمه جستجوی موبایل: `.md\:hidden input[type=text]` | اولین input صفحه را focus می‌کرد، گاهی input داخل مودال دسته‌بندی |
| ۱۲ | `href="#"` در صفحه ورود، `<button>` بدون handler در هدر | لینک/دکمه مرده |

### درباره باگ ۵ (مهم برای توسعه‌های بعدی)

```php
// vendor/…/ThrottleRequests.php
protected function resolveRequestSignature($request) {
    if ($user = $request->user()) {
        return $this->formatIdentifier($user->getAuthIdentifier());  // ← فقط user id
    }
    …
}
```

پس **`throttle:N,M` را روی مسیرهای کاربر لاگین‌شده استفاده نکنید.** limiterهای
نام‌دار در `AppServiceProvider::configureRateLimiters()` تعریف شده‌اند و کلیدشان
نام limiter را هم شامل می‌شود:

```
verification-identity   ۶ / ۱۰ دقیقه
verification-media     ۱۰ / ۱۰ دقیقه
verification-bank       ۶ / ۱۰ دقیقه
rental-guarantee        ۶ / ۱۰ دقیقه
rental-signature-otp    ۵ / ۱۰ دقیقه   ← سطل جدا از OTP ورود
rental-signature        ۵ / ۱۰ دقیقه
```

---

## ۱۲. قواعد RTL و فارسی‌نویسی در این پروژه

### چیدمان

- `<html lang="fa" dir="rtl">` در `layouts/app.blade.php`.
- در RTL، `mr-*` یعنی فاصله از **راست‌چین شدن به سمت چپ**؛ برای فاصله بعد از
  آیکون از `ml-1` استفاده می‌شود (الگوی موجود پروژه).
- جهت فلش‌ها معکوس است: «بعدی» = `fa-chevron-left`، «قبلی» =
  `fa-chevron-right`. تقویم شمسی هم همین را رعایت می‌کند.
- توکن‌های طراحی فقط در `partials/design-tokens.blade.php`. رنگ inline در صفحه
  تعریف نمی‌شود.

### اعداد و تاریخ

| کاربرد | تابع |
|---|---|
| عدد فارسی در Blade | `persian_number($n)` |
| تاریخ شمسی با ارقام فارسی | `Jalali::formatLong($iso)` → «۳ مهر ۱۴۰۵» |
| تاریخ شمسی با ارقام لاتین | `Jalali::format($iso)` → «3 مهر 1405» |

**قاعده:** هر تاریخی که به مشتری نشان داده می‌شود `formatLong()` است. (این در
همین کار اصلاح شد: پنل اجاره، صفحه درخواست، قرارداد و صفحه نتایج همگی به
`formatLong` منتقل شدند.)

### فیلدهای مخلوط LTR

شماره موبایل، کارت، شبا، شماره سفارش، شماره درخواست، `correlation_id` و
شناسه صیاد همیشه با `dir="ltr"` رندر می‌شوند تا ارقام به‌هم نریزند:

```blade
<span class="font-mono text-gray-700" dir="ltr">{{ $application->application_number }}</span>
```

### پیام‌ها

- همه پیام‌های خطا و موفقیت **فارسی روشن**اند.
- `$e->getMessage()` خام هرگز به کاربر نمی‌رود. استثنای شناخته‌شده کسب‌وکار
  پیام فارسی دارد؛ استثنای غیرمنتظره لاگ می‌شود و پیام عمومی امن برمی‌گردد
  (`ProviderException` برای همین دو فیلد جدا دارد: فنی و `persianMessage`).
- در پنل ادمین: `data-confirm` به‌جای `confirm()` و `adminToast()` به‌جای
  `alert()`.

### موبایل

- نوار جستجو در موبایل به‌صورت عمودی stack می‌شود (`flex-col md:flex-row`).
- جدول‌های عریض داخل `overflow-x-auto` خودشان اسکرول می‌شوند؛ بدنه صفحه
  اسکرول افقی ندارد.
- مودال دسته‌بندی تمام‌صفحه با انیمیشن `translate-x-full` (از راست وارد می‌شود
  — جهت درست RTL).

---

## ۱۳. تست‌ها

```bash
vendor/bin/phpunit            # OK (52 tests, 517 assertions)
```

> تست‌ها روی **MySQL** (`gamepek_rental_test`) اجرا می‌شوند، نه SQLite: دو
> migration تاریخی از `fullText()` و `ALTER TABLE … ADD CONSTRAINT` استفاده
> می‌کنند که SQLite پشتیبانی نمی‌کند و ویرایش migration تاریخی ممنوع است.

| فایل | پوشش |
|---|---|
| `RentalChainEndToEndTest` | کل زنجیره در سطح سرویس + ممیزی هر transition + fail-closed بودن سیاست‌ها |
| `RentalBookingJourneyTest` | همان سفر ولی از روی **routeها** (فرم‌ها و redirectها) |
| `HomeSearchFlowTest` | جستجوی تاریخ، حذف دستگاه رزروشده، ۵ حالت بازه نامعتبر، شهر نامعتبر |
| `NavigationAndCalendarTest` | ساختار منو، لینک مرده، فیلتر زیر‌دسته، هدر، محصولات پیشنهادی، تقویم |
| `PaymentCallbackTest` | idempotency، replay، عدم اعتماد به callback خام |
| `AdminRentalScreensTest` | رندر صفحات ادمین، empty state، مجوزها، ممیزی خواندن هویت |
| `AuditLoggerTest` | actor/resource/result، redaction، correlation مشترک |

---

## ۱۴. راه‌اندازی محلی و سناریوی تست دستی

```bash
composer install
php artisan migrate            # هرگز migrate:fresh / refresh / db:wipe
php artisan db:seed            # کاربران، تنظیمات، منو، ناوبری اجاره، قالب قرارداد
php artisan db:seed --class=RentalDemoProductSeeder    # کنسول‌های دمو (فقط local)
php artisan serve
```

### فعال‌سازی مسیر خودکار برای تست

```dotenv
# فقط تست محلی — سیاست تأییدشده کسب‌وکار نیست (TODO B1 / B6)
VERIFICATION_IDENTITY_REQUIRED_CHECKS=shahkar,civil_registry
VERIFICATION_GUARANTEE_REQUIRED_INQUIRIES=sayad_validate,ownership_match
```

> ⚠️ **قبل از هر deploy تولیدی این دو خط را حذف کنید.** پیش‌فرض کد خالی و
> fail-closed است؛ با این مقادیر، هویت واقعی افراد روی یک حدس auto-verify
> می‌شود.

### داده تست

| قلم | مقدار |
|---|---|
| کد ملی معتبر | `0499370899` |
| کارت (Luhn معتبر) | `6037997599999993` |
| شبا (mod-97 معتبر) | `IR820540102680020817909002` |
| شناسه صیاد | `1234567890123456` |
| کاربر تست | `09120000002` |
| ادمین توسعه | `admin@gamepek-rental.local` / `password` |

کد OTP در `storage/logs/laravel.log` (خط `NullOtpProvider`) و در پاسخ JSON
`dev_otp` می‌آید — SMS واقعی ارسال نمی‌شود.

### سناریوی کامل

1. صفحه خانه → شهر «تهران» + تاریخ شروع و پایان از **تقویم شمسی** → جستجو
2. یک دستگاه از نتایج → صفحه محصول (بازه در بالای پنل نمایش داده می‌شود)
3. «ادامه رزرو» → اگر وارد نشده‌اید، ورود با OTP و بازگشت به همان محصول
4. صفحه درخواست: مراحل ۷گانه، اولین مرحله ناتمام دکمه دارد
5. احراز هویت → کد ملی + استعلام‌ها؛ سپس کارت/شبا + استعلام مالکیت
6. پرداخت → صفحه mock داخل اپ → «پرداخت موفق»
7. ضمانت (شناسه صیاد) → قرارداد → پذیرش → OTP → امضا
8. ورود ادمین → `/admin/rental-applications` → تأیید نهایی → `approved`

اجرای زنده همین سناریو انجام شد: ۸ transition و ۹ ردیف ممیزی ثبت شد.

---

## ۱۵. TODOهای کسب‌وکار (اختراع نشده‌اند)

هر کدام در کد با `TODO(business)` کنار نقطه تصمیم علامت خورده و مقدار پیش‌فرضش
خالی/`null` است. **کد در نبود سیاست هرگز خودکار تأیید، حذف یا ارسال نمی‌کند** و
رویداد `*.policy_undefined` در لاگ ممیزی ثبت می‌کند.

| کد | تصمیم |
|---|---|
| B1 | کدام استعلام‌ها برای KYC سطح ۲ الزامی‌اند |
| B2 | آستانه امتیاز liveness / face match |
| B3 | سقف تلاش استعلام |
| B4 | سیاست ودیعه — چه زمانی بلوکه، چه زمانی آزاد |
| B5 | فرمول مبلغ ضمانت |
| B6 | کدام‌یک از CHEQUE-01..07 الزامی‌اند |
| B7 | آستانه ریسک اعتباری |
| B8 | معیار تأیید نهایی (خودکار یا دستی؟ چه نقشی؟) |
| B11 | مدت نگهداری هر نوع مدیا |
| B14 | ماشه‌های چرخه پس از تأیید: Active / Returned / Closed |
| B12 | متن رسمی قرارداد (قالب فعلی **فاقد اعتبار حقوقی** است) |
| B13 | قالب‌های SMS رویدادهای اجاره |
| — | لحظه بلوکه‌شدن تقویم: هنگام رزرو یا هنگام پرداخت؟ |
| — | تیرهای تخفیف مدت در `config('rental.pricing.duration_discounts')` هنوز placeholder است |

---

## ۱۶. فهرست فایل‌های تغییریافته

### جدید — تقویم و جستجو
```
resources/views/partials/jalali-datepicker.blade.php     تقویم شمسی (بدون dependency)
resources/views/partials/rental-search-bar.blade.php     نوار جستجو (تک‌کامپوننت)
resources/views/about.blade.php                          صفحه درباره ما
database/seeders/RentalNavigationSeeder.php              درخت دسته‌بندی + منو
```

### جدید — زنجیره اجاره
```
app/Enums/*.php                                          ۹ enum وضعیت
app/Services/Audit/AuditLogger.php                       لایه ممیزی
app/Http/Middleware/AssignCorrelationId.php
app/Services/Payment/**                                  PAY-01..06
app/Services/{Identity,Banking,Guarantee,Contract,Media,Notification,Rental}/**
app/Services/Providers/**                                قرارداد provider + Fake/Unconfigured
app/Http/Controllers/{Verification,BankAccount,RentalApplication,MockPayment}Controller.php
app/Http/Controllers/Admin/{Verification,RentalApplication,AuditEvent}Controller.php
app/Models/*                                             ۱۴ مدل جدید
database/migrations/2026_09_05_0000{01..17}_*.php        ۱۷ migration forward-only
config/verification.php
resources/views/{verification,rental,admin/verifications,admin/rental-applications,admin/audit-events}/**
```

### ویرایش‌شده
```
resources/views/home.blade.php                 Hero + محصولات پیشنهادی + دسته‌بندی‌ها
resources/views/partials/header.blade.php      منوی کلیک‌پذیر، fallback، حذف دو لینک بالا
resources/views/partials/footer.blade.php      پیگیری سفارش + درباره ما
resources/views/partials/mobile-nav.blade.php  رفع selector جستجو
resources/views/partials/product-card.blade.php حمل بازه در لینک
resources/views/partials/rental-panel.blade.php حذف تقویم دوم + اتصال CTA به رزرو
resources/views/partials/rental-quote-box.blade.php حذف پیام «فعال نشده»
resources/views/products/index.blade.php       نوار جستجو
resources/views/search/index.blade.php         شهر/بازه/تعداد روز + تقویم
resources/views/profile/index.blade.php        استعلام واقعی بانک + کد ملی از user_identities
app/Models/{MenuItem,Product,RentalApplication,Order,User}.php
app/Http/Controllers/{Product,Home,Profile,Checkout}Controller.php
app/Services/{CatalogService,PaymentService,OrderService}.php
app/Providers/AppServiceProvider.php           limiterهای نام‌دار
config/{rental,verification,filesystems}.php
routes/{web,console}.php
database/seeders/{DatabaseSeeder,UserSeeder}.php
```

</div>
