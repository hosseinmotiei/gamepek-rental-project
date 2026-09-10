<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Home → search → results, the site's primary flow.
 *
 * The date window is the part with real consequences: a device already held
 * for those days must not be offered again, and a malformed window must be
 * refused by the server, not only by the browser.
 */
class HomeSearchFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = now()->addDays(2)->toDateString();
        $this->to = now()->addDays(5)->toDateString();
    }

    public function test_the_home_page_renders_the_rental_search_bar(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('rental-search-hero', false)
            ->assertSee(route('products.search'), false);
    }

    public function test_a_date_search_needs_no_text_query(): void
    {
        $product = $this->makeRentableProduct('کنسول آزاد');

        $this->get(route('products.search', [
            'city' => 'تهران', 'from' => $this->from, 'to' => $this->to,
        ]))
            ->assertOk()
            ->assertSee($product->title_fa, false);
    }

    public function test_an_item_reserved_for_those_dates_is_not_offered(): void
    {
        $free = $this->makeRentableProduct('دستگاه آزاد');
        $booked = $this->makeRentableProduct('دستگاه رزروشده');

        $this->hold($booked, $this->from, $this->to);

        $response = $this->get(route('products.search', [
            'city' => 'تهران', 'from' => $this->from, 'to' => $this->to,
        ]))->assertOk();

        $response->assertSee($free->title_fa, false);
        $response->assertDontSee($booked->title_fa, false);
    }

    public function test_the_item_returns_once_its_reservation_window_has_passed(): void
    {
        $booked = $this->makeRentableProduct('دستگاه رزروشده');
        $this->hold($booked, $this->from, $this->to);

        $this->get(route('products.search', [
            'city' => 'تهران',
            'from' => now()->addDays(10)->toDateString(),
            'to' => now()->addDays(12)->toDateString(),
        ]))->assertOk()->assertSee($booked->title_fa, false);
    }

    public function test_a_buy_only_product_never_appears_in_a_date_search(): void
    {
        $buyOnly = Product::create([
            'category_id' => $this->category()->id,
            'title_fa' => 'کالای فروشی',
            'slug' => 'buy-only-'.uniqid(),
            'price' => 100000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $this->get(route('products.search', [
            'city' => 'تهران', 'from' => $this->from, 'to' => $this->to,
        ]))->assertOk()->assertDontSee($buyOnly->title_fa, false);
    }

    /** The browser is not the authority — these are server rules. */
    public function test_an_invalid_window_is_refused_by_the_server(): void
    {
        $cases = [
            ['from' => $this->to, 'to' => $this->from, 'message' => 'تاریخ پایان باید بعد از تاریخ شروع باشد.'],
            ['from' => now()->subDay()->toDateString(), 'to' => $this->to, 'message' => 'تاریخ شروع نمی‌تواند در گذشته باشد.'],
            ['from' => 'not-a-date', 'to' => $this->to, 'message' => 'فرمت تاریخ نامعتبر است.'],
            ['from' => $this->from, 'to' => '', 'message' => 'هر دو تاریخ شروع و پایان را وارد کنید.'],
            ['from' => $this->from, 'to' => now()->addDays(400)->toDateString(), 'message' => 'حداکثر مدت اجاره'],
        ];

        foreach ($cases as $case) {
            $this->get(route('products.search', ['city' => 'تهران', 'from' => $case['from'], 'to' => $case['to']]))
                ->assertOk()
                ->assertSee($case['message'], false);
        }
    }

    public function test_an_unsupported_city_is_rejected(): void
    {
        $this->get(route('products.search', ['city' => 'Dubai', 'from' => $this->from, 'to' => $this->to]))
            ->assertOk()
            ->assertSee('شهر انتخاب‌شده در حال حاضر پشتیبانی نمی‌شود.', false);
    }

    public function test_a_text_search_still_works(): void
    {
        $product = $this->makeRentableProduct('پلی‌استیشن اجاره‌ای');

        $this->get(route('products.search', ['q' => 'پلی‌استیشن']))
            ->assertOk()
            ->assertSee($product->title_fa, false);
    }

    public function test_the_menu_falls_back_to_real_categories_when_no_menu_items_exist(): void
    {
        $category = Category::create([
            'name_fa' => 'کنسول‌های اجاره‌ای',
            'slug' => 'rental-consoles',
            'is_active' => true,
            'show_in_menu' => true,
        ]);

        // menu_items is empty here, exactly as MenuSeeder leaves it.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee($category->name_fa, false)
            ->assertSee(route('products.index', ['category' => $category->slug]), false);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'search-test-category'],
            ['name_fa' => 'دسته آزمایشی', 'is_active' => true, 'show_in_menu' => true],
        );
    }

    private function makeRentableProduct(string $title): Product
    {
        return Product::create([
            'category_id' => $this->category()->id,
            'title_fa' => $title,
            'slug' => 'rental-'.uniqid(),
            'price' => 500000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 1,
            'is_active' => true,
            'attributes' => ['_rental' => [
                'daily_rate' => 500000,
                'deposit' => 3000000,
                'delivery_fee' => 80000,
                'status' => 'available',
            ]],
        ]);
    }

    private function hold(Product $product, string $from, string $to): void
    {
        $user = User::create(['full_name' => 'مستأجر', 'mobile' => '0912'.random_int(1000000, 9999999), 'status' => 'active']);

        // `state` is not mass assignable; the column's default is draft.
        $application = RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $user->id,
        ]);

        RentalReservation::create([
            'rental_application_id' => $application->id,
            'product_id' => $product->id,
            'product_snapshot' => ['title' => $product->title_fa],
            'start_date' => $from, 'end_date' => $to, 'days' => 3,
            'daily_rate' => 1, 'subtotal' => 1, 'discount' => 0, 'delivery_fee' => 0,
            'rental_total' => 1, 'deposit_amount' => 0, 'payable_now' => 1, 'quote' => [],

            // C-16: `held` no longer blocks inventory -- there is no unpaid
            // hold. Only a paid (or active) reservation takes a device off the
            // market, so that is what a blocking fixture must be.
            'state' => 'paid',
        ]);
    }
}
