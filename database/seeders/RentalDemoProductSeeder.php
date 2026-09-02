<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * LOCAL DEVELOPMENT FIXTURES — not real inventory.
 *
 * Four demo rental consoles carried over from the `Grok-show` rental
 * prototype so the catalog has something to click through while the rental
 * domain is still being designed. Every figure here — price, deposit, health
 * score, condition notes, ratings, review text — was invented for that
 * prototype. None of it is real stock, real pricing or a real customer
 * review, and none of it should ever reach production.
 *
 * Deliberately NOT registered in DatabaseSeeder: that class documents why
 * catalog content is not seeded, and this fixture set does not change that
 * reasoning. Run it explicitly when you want demo data:
 *
 *     php artisan db:seed --class=RentalDemoProductSeeder
 *
 * Idempotent (updateOrCreate on slug), so re-running refreshes the rows and
 * their availability windows rather than duplicating them.
 *
 * Rental-specific fields live in `products.attributes` (JSON) rather than in
 * new columns, which is the extension seam the products table was built with.
 * The scalar `price`/`stock_quantity` columns are still populated so the
 * existing catalog, cart and admin screens keep working unchanged.
 */
class RentalDemoProductSeeder extends Seeder
{
    /**
     * Where the demo imagery was copied to, relative to the public disk.
     */
    private const IMAGE_DIR = 'rental-demo';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('RentalDemoProductSeeder is demo data and must not run in production.');

            return;
        }

        $category = Category::firstOrCreate(
            ['slug' => 'console-rental'],
            [
                'name_fa' => 'اجاره کنسول',
                'name_en' => 'Console Rental',
                'description' => 'کنسول‌های بازی آماده اجاره — نمونه‌های نمایشی برای توسعه محلی.',
                'icon' => 'fa-solid fa-gamepad',
                'sort_order' => 1,
                'is_active' => true,
                'show_in_menu' => true,
            ]
        );

        // Availability windows are stored as offsets from "today" so the demo
        // calendar never drifts into the past as the fixture ages.
        $today = Carbon::today();

        foreach ($this->products() as $data) {
            $blocked = array_map(fn (array $range) => [
                'from' => $today->copy()->addDays($range['from_offset'])->toDateString(),
                'to' => $today->copy()->addDays($range['to_offset'])->toDateString(),
                'kind' => $range['kind'],
            ], $data['blocked']);

            $gallery = array_map(
                fn (array $image) => self::IMAGE_DIR . '/' . basename($image['src']),
                $data['images']
            );

            Product::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'category_id' => $category->id,
                    'title_fa' => $data['name'],
                    'title_en' => $data['model'],
                    'sku' => 'RNT-' . strtoupper(str_replace('-', '', $data['slug'])),
                    'short_description' => $data['conditionSummary'],
                    'description' => $data['conditionSummary'],
                    'brand' => 'Sony',
                    'model' => $data['model'],

                    // The daily rental rate. The catalog and cart read `price`
                    // as a scalar amount; the rental quote is calculated from
                    // attributes.rental by RentalPricingService.
                    'price' => $data['dailyRate'],

                    // A rental unit is a single physical device: one at a time.
                    // `status` drives whether it is currently rentable at all.
                    'stock_quantity' => $data['status'] === 'available' ? 1 : 0,
                    'stock_status' => $data['status'] === 'available' ? 'in_stock' : 'out_of_stock',

                    'is_active' => true,
                    'is_featured' => $data['status'] === 'available',

                    'main_image' => $gallery[0] ?? null,
                    'gallery_images' => $gallery,

                    // `attributes` is a FLAT key => scalar map by contract:
                    // products/show.blade.php renders it as the spec table and
                    // CatalogService::applyAttributeFacets() matches
                    // `attributes->{key}` against a string. So the prototype's
                    // label/value specs are flattened to the top level, and
                    // everything structural is parked under the single
                    // `_rental` key. The leading underscore marks it as
                    // non-displayable: the spec table skips "_"-prefixed keys,
                    // and the facet filter only ever reads declared facet keys.
                    'attributes' => $this->specAttributes($data) + [
                        '_rental' => [
                            'daily_rate' => $data['dailyRate'],
                            'deposit' => $data['deposit'],
                            'delivery_fee' => $data['deliveryFee'],
                            'extra_controller_available' => $data['extraControllerAvailable'],
                            'extra_controller_daily' => $data['extraControllerDaily'],
                            'base_controllers' => $data['baseControllers'],
                            'status' => $data['status'],
                            'blocked' => $blocked,
                            'health_score' => $data['healthScore'],
                            'health' => $data['health'],
                            'condition_facts' => $data['conditionFacts'],
                            'included' => $data['included'],
                            'games' => $data['games'],
                            'default_game_id' => $data['defaultGameId'],
                            'game_delivery_default' => $data['gameDeliveryDefault'],
                            'badges' => $data['badges'],
                            'related' => $data['related'],
                            'image_alts' => array_column($data['images'], 'alt', 'id'),
                            'installed_games' => $data['installedGames'],
                            'disc_drive' => $data['discDrive'],
                            'rating' => $data['rating'],
                            'review_count' => $data['reviewCount'],
                            'rental_count' => $data['rentalCount'],
                        ],
                    ],

                    'meta_title' => $data['name'],
                    'meta_description' => $data['conditionSummary'],
                ]
            );
        }

        $this->command->info('Seeded ' . count($this->products()) . ' demo rental consoles (local fixtures).');
    }

    /**
     * The prototype's `specs` list flattened into the flat label => value map
     * that products/show.blade.php renders as the "مشخصات فنی" table.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function specAttributes(array $data): array
    {
        return array_column($data['specs'], 'value', 'label');
    }

    /**
     * Verbatim fixture data from the prototype, with availability windows
     * expressed as day offsets from today.
     *
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        return [
            [
                'slug' => 'ps5-slim-digital',
                'name' => 'PlayStation 5 Slim — اجاره کنسول',
                'model' => 'PS5 Slim Digital Edition',
                'generation' => 'نسل نهم',
                'deviceType' => 'کنسول خانگی',
                'storageLabel' => '۱ ترابایت',
                'storageNominalGb' => 1000,
                'storageUsableGb' => 892,
                'installedGameCount' => 3,
                'installedGames' => ['Marvel\'s Spider-Man 2', 'God of War Ragnarök', 'Grand Theft Auto V'],
                'internetAvailable' => true,
                'wifi' => true,
                'bluetooth' => true,
                'discDrive' => false,
                'baseControllers' => 1,
                'extraControllerAvailable' => true,
                'extraControllerDaily' => 45000,
                'dailyRate' => 350000,
                'deposit' => 12000000,
                'deliveryFee' => 75000,
                'healthScore' => 94,
                'health' => [
                    'technical' => [
                        'label' => 'وضعیت فنی',
                        'value' => 'عالی',
                    ],
                    'appearance' => [
                        'label' => 'وضعیت ظاهری',
                        'value' => 'خوب',
                    ],
                    'controller' => [
                        'label' => 'دسته',
                        'value' => 'عالی',
                    ],
                    'ports' => [
                        'label' => 'پورت‌ها',
                        'value' => 'سالم',
                    ],
                    'cooling' => [
                        'label' => 'سیستم خنک‌کننده',
                        'value' => 'سالم',
                    ],
                    'discDrive' => [
                        'label' => 'دیسک‌خوان',
                        'value' => 'ندارد',
                    ],
                ],
                'conditionSummary' => 'دستگاه کارکرده است و دارای خط‌وخش جزئی روی پنل پایین بدنه می‌باشد. این مورد در سلامت عملکرد دستگاه تأثیری ندارد.',
                'conditionFacts' => [
                    [
                        'label' => 'میزان کارکرد تقریبی',
                        'value' => 'حدود ۴۲۰ ساعت',
                    ],
                    [
                        'label' => 'خط‌وخش',
                        'value' => 'جزئی، روی لبه پایین بدنه سفید',
                    ],
                    [
                        'label' => 'تعمیرات قبلی',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'تعویض قطعه',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'وضعیت دسته',
                        'value' => 'بدون آنالوگ دریفت، دکمه‌ها سالم',
                    ],
                    [
                        'label' => 'وضعیت پورت‌ها',
                        'value' => 'HDMI، USB و برق بدون لقّی',
                    ],
                    [
                        'label' => 'وضعیت کابل‌ها',
                        'value' => 'اصلی، سالم، بدون پارگی',
                    ],
                    [
                        'label' => 'ایراد شناخته‌شده',
                        'value' => 'هیچ ایراد عملکردی ثبت نشده',
                    ],
                ],
                'status' => 'available',
                'badges' => ['موجود', 'آماده اجاره', 'محبوب'],
                'included' => [
                    [
                        'id' => 'console',
                        'label' => 'کنسول PS5 Slim Digital',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad1',
                        'label' => 'دسته اول',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad2',
                        'label' => 'دسته دوم',
                        'included' => false,
                        'note' => 'قابل افزودن',
                    ],
                    [
                        'id' => 'power',
                        'label' => 'کابل برق',
                        'included' => true,
                    ],
                    [
                        'id' => 'hdmi',
                        'label' => 'کابل HDMI',
                        'included' => true,
                    ],
                    [
                        'id' => 'charge',
                        'label' => 'کابل شارژ دسته',
                        'included' => true,
                    ],
                    [
                        'id' => 'manual',
                        'label' => 'دفترچه / راهنما',
                        'included' => false,
                    ],
                    [
                        'id' => 'game',
                        'label' => 'بازی فیزیکی',
                        'included' => false,
                        'note' => 'قابل انتخاب',
                    ],
                    [
                        'id' => 'box',
                        'label' => 'بسته‌بندی محافظ',
                        'included' => true,
                    ],
                ],
                'defaultGameId' => 'spiderman2',
                'gameDeliveryDefault' => 'installed',
                'blocked' => [
                    [
                        'from_offset' => 6,
                        'to_offset' => 9,
                        'kind' => 'reserved',
                    ],
                    [
                        'from_offset' => 20,
                        'to_offset' => 22,
                        'kind' => 'pending',
                    ],
                ],
                'rating' => 4.8,
                'reviewCount' => 41,
                'rentalCount' => 127,
                'specs' => [
                    [
                        'label' => 'دستگاه',
                        'value' => 'PlayStation 5 Slim',
                    ],
                    [
                        'label' => 'مدل',
                        'value' => 'Digital Edition',
                    ],
                    [
                        'label' => 'حافظه',
                        'value' => '۱ ترابایت (قابل استفاده ۸۹۲ گیگابایت)',
                    ],
                    [
                        'label' => 'سلامت فنی',
                        'value' => 'عالی',
                    ],
                    [
                        'label' => 'سلامت ظاهری',
                        'value' => 'خوب',
                    ],
                    [
                        'label' => 'اینترنت',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'Wi-Fi',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'Bluetooth',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'تعداد دسته پایه',
                        'value' => '۱',
                    ],
                    [
                        'label' => 'دسته اضافه',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'دیسک‌خوان',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'بازی همراه',
                        'value' => '۳ عنوان نصب‌شده',
                    ],
                    [
                        'label' => 'نوع بازی',
                        'value' => 'Digital / امکان افزودن دیسک',
                    ],
                    [
                        'label' => 'وضعیت',
                        'value' => 'آماده اجاره',
                    ],
                ],
                'related' => ['ps5-slim-disc', 'ps5-slim-unit-b'],
                'games' => [
                    [
                        'id' => 'fc26',
                        'title' => 'EA Sports FC 26',
                        'kind' => 'disc',
                        'extraFee' => 180000,
                        'inStock' => true,
                        'freeWithRental' => false,
                    ],
                    [
                        'id' => 'gtav',
                        'title' => 'Grand Theft Auto V',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'spiderman2',
                        'title' => 'Marvel\'s Spider-Man 2',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'gow',
                        'title' => 'God of War Ragnarök',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                ],
                'images' => [
                    [
                        'id' => 'front',
                        'src' => '/rental/ps5-front.jpg',
                        'alt' => 'نمای روبه‌رو کنسول',
                    ],
                    [
                        'id' => 'back',
                        'src' => '/rental/ps5-back.jpg',
                        'alt' => 'نمای پشت و پورت‌های کنسول',
                    ],
                    [
                        'id' => 'pads',
                        'src' => '/rental/ps5-controllers.jpg',
                        'alt' => 'دسته‌های همراه دستگاه',
                    ],
                    [
                        'id' => 'kit',
                        'src' => '/rental/ps5-accessories.jpg',
                        'alt' => 'کابل برق، HDMI و کابل شارژ',
                    ],
                    [
                        'id' => 'box',
                        'src' => '/rental/ps5-packaging.jpg',
                        'alt' => 'بسته‌بندی و اقلام داخل جعبه',
                    ],
                ],
            ],
            [
                'slug' => 'ps5-slim-disc',
                'name' => 'PlayStation 5 Slim — نسخه دیسک',
                'model' => 'PS5 Slim Disc Edition',
                'generation' => 'نسل نهم',
                'deviceType' => 'کنسول خانگی',
                'storageLabel' => '۱ ترابایت',
                'storageNominalGb' => 1000,
                'storageUsableGb' => 892,
                'installedGameCount' => 1,
                'installedGames' => ['God of War Ragnarök'],
                'internetAvailable' => true,
                'wifi' => true,
                'bluetooth' => true,
                'discDrive' => true,
                'baseControllers' => 1,
                'extraControllerAvailable' => true,
                'extraControllerDaily' => 45000,
                'dailyRate' => 380000,
                'deposit' => 13000000,
                'deliveryFee' => 75000,
                'healthScore' => 91,
                'health' => [
                    'technical' => [
                        'label' => 'وضعیت فنی',
                        'value' => 'عالی',
                    ],
                    'appearance' => [
                        'label' => 'وضعیت ظاهری',
                        'value' => 'خوب',
                    ],
                    'controller' => [
                        'label' => 'دسته',
                        'value' => 'خوب',
                    ],
                    'ports' => [
                        'label' => 'پورت‌ها',
                        'value' => 'سالم',
                    ],
                    'cooling' => [
                        'label' => 'سیستم خنک‌کننده',
                        'value' => 'سالم',
                    ],
                    'discDrive' => [
                        'label' => 'دیسک‌خوان',
                        'value' => 'سالم',
                    ],
                ],
                'conditionSummary' => 'نسخه دیسک‌خوان. بدنه تمیز است با چند خط ریز روی استند. دیسک‌خوان در تست خواندن دیسک مشکلی نداشته است.',
                'conditionFacts' => [
                    [
                        'label' => 'میزان کارکرد تقریبی',
                        'value' => 'حدود ۶۱۰ ساعت',
                    ],
                    [
                        'label' => 'خط‌وخش',
                        'value' => 'ریز، روی استند و لبه بالایی',
                    ],
                    [
                        'label' => 'تعمیرات قبلی',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'تعویض قطعه',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'وضعیت دسته',
                        'value' => 'سالم، آنالوگ کمی نرم‌تر از نو',
                    ],
                    [
                        'label' => 'وضعیت پورت‌ها',
                        'value' => 'سالم',
                    ],
                    [
                        'label' => 'وضعیت کابل‌ها',
                        'value' => 'اصلی و سالم',
                    ],
                    [
                        'label' => 'ایراد شناخته‌شده',
                        'value' => 'هیچ',
                    ],
                ],
                'status' => 'available',
                'badges' => ['موجود', 'آماده اجاره', 'دیسک‌خوان'],
                'included' => [
                    [
                        'id' => 'console',
                        'label' => 'کنسول PS5 Slim Disc',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad1',
                        'label' => 'دسته اول',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad2',
                        'label' => 'دسته دوم',
                        'included' => false,
                        'note' => 'قابل افزودن',
                    ],
                    [
                        'id' => 'power',
                        'label' => 'کابل برق',
                        'included' => true,
                    ],
                    [
                        'id' => 'hdmi',
                        'label' => 'کابل HDMI',
                        'included' => true,
                    ],
                    [
                        'id' => 'charge',
                        'label' => 'کابل شارژ دسته',
                        'included' => true,
                    ],
                    [
                        'id' => 'manual',
                        'label' => 'دفترچه / راهنما',
                        'included' => false,
                    ],
                    [
                        'id' => 'game',
                        'label' => 'بازی فیزیکی',
                        'included' => false,
                        'note' => 'قابل انتخاب',
                    ],
                    [
                        'id' => 'box',
                        'label' => 'بسته‌بندی محافظ',
                        'included' => true,
                    ],
                ],
                'defaultGameId' => 'fc26',
                'gameDeliveryDefault' => 'disc',
                'blocked' => [
                    [
                        'from_offset' => 2,
                        'to_offset' => 4,
                        'kind' => 'reserved',
                    ],
                    [
                        'from_offset' => 16,
                        'to_offset' => 23,
                        'kind' => 'reserved',
                    ],
                ],
                'rating' => 4.7,
                'reviewCount' => 28,
                'rentalCount' => 64,
                'specs' => [
                    [
                        'label' => 'دستگاه',
                        'value' => 'PlayStation 5 Slim',
                    ],
                    [
                        'label' => 'مدل',
                        'value' => 'Disc Edition',
                    ],
                    [
                        'label' => 'حافظه',
                        'value' => '۱ ترابایت',
                    ],
                    [
                        'label' => 'سلامت فنی',
                        'value' => 'عالی',
                    ],
                    [
                        'label' => 'سلامت ظاهری',
                        'value' => 'خوب',
                    ],
                    [
                        'label' => 'اینترنت',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'Wi-Fi',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'Bluetooth',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'تعداد دسته پایه',
                        'value' => '۱',
                    ],
                    [
                        'label' => 'دسته اضافه',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'دیسک‌خوان',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'بازی همراه',
                        'value' => 'قابل انتخاب (دیسک)',
                    ],
                    [
                        'label' => 'نوع بازی',
                        'value' => 'Physical / Digital',
                    ],
                    [
                        'label' => 'وضعیت',
                        'value' => 'آماده اجاره',
                    ],
                ],
                'related' => ['ps5-slim-digital', 'ps5-slim-unit-b'],
                'games' => [
                    [
                        'id' => 'fc26',
                        'title' => 'EA Sports FC 26',
                        'kind' => 'disc',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'gtav',
                        'title' => 'Grand Theft Auto V',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'spiderman2',
                        'title' => 'Marvel\'s Spider-Man 2',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'gow',
                        'title' => 'God of War Ragnarök',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                ],
                'images' => [
                    [
                        'id' => 'front',
                        'src' => '/rental/ps5-front.jpg',
                        'alt' => 'نمای روبه‌رو کنسول',
                    ],
                    [
                        'id' => 'back',
                        'src' => '/rental/ps5-back.jpg',
                        'alt' => 'نمای پشت و پورت‌های کنسول',
                    ],
                    [
                        'id' => 'pads',
                        'src' => '/rental/ps5-controllers.jpg',
                        'alt' => 'دسته‌های همراه دستگاه',
                    ],
                    [
                        'id' => 'kit',
                        'src' => '/rental/ps5-accessories.jpg',
                        'alt' => 'کابل برق، HDMI و کابل شارژ',
                    ],
                    [
                        'id' => 'box',
                        'src' => '/rental/ps5-packaging.jpg',
                        'alt' => 'بسته‌بندی و اقلام داخل جعبه',
                    ],
                ],
            ],
            [
                'slug' => 'ps5-slim-unit-b',
                'name' => 'PlayStation 5 Slim — دستگاه دوم',
                'model' => 'PS5 Slim Digital Edition',
                'generation' => 'نسل نهم',
                'deviceType' => 'کنسول خانگی',
                'storageLabel' => '۱ ترابایت',
                'storageNominalGb' => 1000,
                'storageUsableGb' => 840,
                'installedGameCount' => 2,
                'installedGames' => ['Marvel\'s Spider-Man 2', 'Grand Theft Auto V'],
                'internetAvailable' => true,
                'wifi' => true,
                'bluetooth' => true,
                'discDrive' => false,
                'baseControllers' => 1,
                'extraControllerAvailable' => true,
                'extraControllerDaily' => 45000,
                'dailyRate' => 320000,
                'deposit' => 11000000,
                'deliveryFee' => 75000,
                'healthScore' => 86,
                'health' => [
                    'technical' => [
                        'label' => 'وضعیت فنی',
                        'value' => 'خوب',
                    ],
                    'appearance' => [
                        'label' => 'وضعیت ظاهری',
                        'value' => 'متوسط',
                    ],
                    'controller' => [
                        'label' => 'دسته',
                        'value' => 'خوب',
                    ],
                    'ports' => [
                        'label' => 'پورت‌ها',
                        'value' => 'سالم',
                    ],
                    'cooling' => [
                        'label' => 'سیستم خنک‌کننده',
                        'value' => 'سالم',
                    ],
                    'discDrive' => [
                        'label' => 'دیسک‌خوان',
                        'value' => 'ندارد',
                    ],
                ],
                'conditionSummary' => 'دستگاه کارکرده با خط‌وخش مشخص‌تر روی بدنه. فن در سرویس دوره‌ای تمیز شده. عملکرد بازی پایدار است.',
                'conditionFacts' => [
                    [
                        'label' => 'میزان کارکرد تقریبی',
                        'value' => 'حدود ۹۸۰ ساعت',
                    ],
                    [
                        'label' => 'خط‌وخش',
                        'value' => 'مشخص روی پنل کناری و پایه',
                    ],
                    [
                        'label' => 'تعمیرات قبلی',
                        'value' => 'سرویس فن و خمیر حرارتی، شهریور ۱۴۰۴',
                    ],
                    [
                        'label' => 'تعویض قطعه',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'وضعیت دسته',
                        'value' => 'سالم',
                    ],
                    [
                        'label' => 'وضعیت پورت‌ها',
                        'value' => 'سالم',
                    ],
                    [
                        'label' => 'وضعیت کابل‌ها',
                        'value' => 'HDMI جایگزین متفرقه، سالم',
                    ],
                    [
                        'label' => 'ایراد شناخته‌شده',
                        'value' => 'صدای فن در بازی‌های سنگین کمی شنیده می‌شود',
                    ],
                ],
                'status' => 'available',
                'badges' => ['موجود', 'قیمت مناسب‌تر'],
                'included' => [
                    [
                        'id' => 'console',
                        'label' => 'کنسول PS5 Slim Digital',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad1',
                        'label' => 'دسته اول',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad2',
                        'label' => 'دسته دوم',
                        'included' => false,
                        'note' => 'قابل افزودن',
                    ],
                    [
                        'id' => 'power',
                        'label' => 'کابل برق',
                        'included' => true,
                    ],
                    [
                        'id' => 'hdmi',
                        'label' => 'کابل HDMI',
                        'included' => true,
                    ],
                    [
                        'id' => 'charge',
                        'label' => 'کابل شارژ دسته',
                        'included' => true,
                    ],
                    [
                        'id' => 'manual',
                        'label' => 'دفترچه / راهنما',
                        'included' => false,
                    ],
                    [
                        'id' => 'game',
                        'label' => 'بازی فیزیکی',
                        'included' => false,
                    ],
                    [
                        'id' => 'box',
                        'label' => 'بسته‌بندی محافظ',
                        'included' => true,
                    ],
                ],
                'defaultGameId' => 'gtav',
                'gameDeliveryDefault' => 'installed',
                'blocked' => [
                    [
                        'from_offset' => 10,
                        'to_offset' => 14,
                        'kind' => 'pending',
                    ],
                ],
                'rating' => 4.5,
                'reviewCount' => 19,
                'rentalCount' => 48,
                'specs' => [
                    [
                        'label' => 'دستگاه',
                        'value' => 'PlayStation 5 Slim',
                    ],
                    [
                        'label' => 'مدل',
                        'value' => 'Digital Edition',
                    ],
                    [
                        'label' => 'حافظه',
                        'value' => '۱ ترابایت',
                    ],
                    [
                        'label' => 'سلامت فنی',
                        'value' => 'خوب',
                    ],
                    [
                        'label' => 'سلامت ظاهری',
                        'value' => 'متوسط',
                    ],
                    [
                        'label' => 'اینترنت',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'Wi-Fi',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'Bluetooth',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'تعداد دسته پایه',
                        'value' => '۱',
                    ],
                    [
                        'label' => 'دسته اضافه',
                        'value' => 'دارد',
                    ],
                    [
                        'label' => 'دیسک‌خوان',
                        'value' => 'ندارد',
                    ],
                    [
                        'label' => 'بازی همراه',
                        'value' => '۲ عنوان نصب‌شده',
                    ],
                    [
                        'label' => 'نوع بازی',
                        'value' => 'Digital',
                    ],
                    [
                        'label' => 'وضعیت',
                        'value' => 'آماده اجاره',
                    ],
                ],
                'related' => ['ps5-slim-digital', 'ps5-slim-disc'],
                'games' => [
                    [
                        'id' => 'gtav',
                        'title' => 'Grand Theft Auto V',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'spiderman2',
                        'title' => 'Marvel\'s Spider-Man 2',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                    [
                        'id' => 'gow',
                        'title' => 'God of War Ragnarök',
                        'kind' => 'installed',
                        'extraFee' => 0,
                        'inStock' => true,
                        'freeWithRental' => true,
                    ],
                ],
                'images' => [
                    [
                        'id' => 'front',
                        'src' => '/rental/ps5-front.jpg',
                        'alt' => 'نمای روبه‌رو کنسول',
                    ],
                    [
                        'id' => 'back',
                        'src' => '/rental/ps5-back.jpg',
                        'alt' => 'نمای پشت و پورت‌های کنسول',
                    ],
                    [
                        'id' => 'pads',
                        'src' => '/rental/ps5-controllers.jpg',
                        'alt' => 'دسته‌های همراه دستگاه',
                    ],
                    [
                        'id' => 'kit',
                        'src' => '/rental/ps5-accessories.jpg',
                        'alt' => 'کابل برق، HDMI و کابل شارژ',
                    ],
                    [
                        'id' => 'wear',
                        'src' => '/rental/ps5-condition.jpg',
                        'alt' => 'نمای نزدیک از خط‌وخش بدنه',
                    ],
                ],
            ],
            [
                'slug' => 'ps5-slim-service',
                'name' => 'PlayStation 5 Slim — در تعمیر',
                'model' => 'PS5 Slim Digital Edition',
                'generation' => 'نسل نهم',
                'deviceType' => 'کنسول خانگی',
                'storageLabel' => '۱ ترابایت',
                'storageNominalGb' => 1000,
                'storageUsableGb' => 892,
                'installedGameCount' => 0,
                'installedGames' => [],
                'internetAvailable' => true,
                'wifi' => true,
                'bluetooth' => true,
                'discDrive' => false,
                'baseControllers' => 1,
                'extraControllerAvailable' => false,
                'extraControllerDaily' => 0,
                'dailyRate' => 350000,
                'deposit' => 12000000,
                'deliveryFee' => 75000,
                'healthScore' => 72,
                'health' => [
                    'technical' => [
                        'label' => 'وضعیت فنی',
                        'value' => 'در بررسی',
                    ],
                    'appearance' => [
                        'label' => 'وضعیت ظاهری',
                        'value' => 'متوسط',
                    ],
                    'controller' => [
                        'label' => 'دسته',
                        'value' => 'در بررسی',
                    ],
                    'ports' => [
                        'label' => 'پورت‌ها',
                        'value' => 'سالم',
                    ],
                    'cooling' => [
                        'label' => 'سیستم خنک‌کننده',
                        'value' => 'در سرویس',
                    ],
                    'discDrive' => [
                        'label' => 'دیسک‌خوان',
                        'value' => 'ندارد',
                    ],
                ],
                'conditionSummary' => 'این دستگاه فعلاً در واحد نگهداری است و تا پایان سرویس فن قابل اجاره نیست.',
                'conditionFacts' => [
                    [
                        'label' => 'میزان کارکرد تقریبی',
                        'value' => 'حدود ۱٬۲۰۰ ساعت',
                    ],
                    [
                        'label' => 'خط‌وخش',
                        'value' => 'متوسط',
                    ],
                    [
                        'label' => 'تعمیرات قبلی',
                        'value' => 'سرویس فن در جریان',
                    ],
                    [
                        'label' => 'تعویض قطعه',
                        'value' => 'در حال بررسی',
                    ],
                    [
                        'label' => 'وضعیت دسته',
                        'value' => 'در بررسی',
                    ],
                    [
                        'label' => 'وضعیت پورت‌ها',
                        'value' => 'سالم',
                    ],
                    [
                        'label' => 'وضعیت کابل‌ها',
                        'value' => 'سالم',
                    ],
                    [
                        'label' => 'ایراد شناخته‌شده',
                        'value' => 'صدای غیرعادی فن — در تعمیر',
                    ],
                ],
                'status' => 'maintenance',
                'badges' => ['در تعمیر'],
                'included' => [
                    [
                        'id' => 'console',
                        'label' => 'کنسول PS5 Slim Digital',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad1',
                        'label' => 'دسته اول',
                        'included' => true,
                    ],
                    [
                        'id' => 'pad2',
                        'label' => 'دسته دوم',
                        'included' => false,
                    ],
                    [
                        'id' => 'power',
                        'label' => 'کابل برق',
                        'included' => true,
                    ],
                    [
                        'id' => 'hdmi',
                        'label' => 'کابل HDMI',
                        'included' => true,
                    ],
                    [
                        'id' => 'charge',
                        'label' => 'کابل شارژ دسته',
                        'included' => true,
                    ],
                    [
                        'id' => 'manual',
                        'label' => 'دفترچه / راهنما',
                        'included' => false,
                    ],
                    [
                        'id' => 'game',
                        'label' => 'بازی فیزیکی',
                        'included' => false,
                    ],
                    [
                        'id' => 'box',
                        'label' => 'بسته‌بندی محافظ',
                        'included' => true,
                    ],
                ],
                'defaultGameId' => null,
                'gameDeliveryDefault' => 'none',
                'blocked' => [
                    [
                        'from_offset' => 0,
                        'to_offset' => 43,
                        'kind' => 'reserved',
                    ],
                ],
                'rating' => 4.2,
                'reviewCount' => 11,
                'rentalCount' => 22,
                'specs' => [
                    [
                        'label' => 'دستگاه',
                        'value' => 'PlayStation 5 Slim',
                    ],
                    [
                        'label' => 'مدل',
                        'value' => 'Digital Edition',
                    ],
                    [
                        'label' => 'وضعیت',
                        'value' => 'در تعمیر / نگهداری',
                    ],
                ],
                'related' => ['ps5-slim-digital', 'ps5-slim-disc'],
                'games' => [],
                'images' => [
                    [
                        'id' => 'front',
                        'src' => '/rental/ps5-front.jpg',
                        'alt' => 'نمای روبه‌رو کنسول',
                    ],
                    [
                        'id' => 'back',
                        'src' => '/rental/ps5-back.jpg',
                        'alt' => 'نمای پشت و پورت‌های کنسول',
                    ],
                    [
                        'id' => 'pads',
                        'src' => '/rental/ps5-controllers.jpg',
                        'alt' => 'دسته‌های همراه دستگاه',
                    ],
                    [
                        'id' => 'kit',
                        'src' => '/rental/ps5-accessories.jpg',
                        'alt' => 'کابل برق، HDMI و کابل شارژ',
                    ],
                    [
                        'id' => 'box',
                        'src' => '/rental/ps5-packaging.jpg',
                        'alt' => 'بسته‌بندی و اقلام داخل جعبه',
                    ],
                ],
            ],

        ];
    }
}
