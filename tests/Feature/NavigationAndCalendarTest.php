<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Support\Rental\Jalali;
use Database\Seeders\RentalNavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Navigation (categories menu, header, footer) and the Jalali search window.
 *
 * The date picker itself is browser JS, but the conversion it uses is a port
 * of App\Support\Rental\Jalali — so the cases below pin the PHP side that the
 * JS must agree with, and the HTTP tests pin what the picker submits.
 */
class NavigationAndCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RentalNavigationSeeder::class);
    }

    public function test_the_category_menu_has_the_requested_structure(): void
    {
        $tree = MenuItem::categoryTree();
        $titles = $tree->pluck('title')->all();

        foreach (['کرایه کنسول', 'کرایه بازی‌های دیسکی', 'پشتیبانی', 'قوانین و مقررات', 'درباره ما'] as $expected) {
            $this->assertContains($expected, $titles);
        }

        $consoles = $tree->firstWhere('title', 'کرایه کنسول');
        $this->assertSame(['Xbox', 'PlayStation'], $consoles->children->pluck('title')->all());
    }

    /** A menu entry that renders as `#` is a dead link, not a link. */
    public function test_every_category_menu_link_resolves_to_a_real_url(): void
    {
        foreach (MenuItem::categoryTree() as $tab) {
            $this->assertNotSame('#', $tab->resolved_url, "dead link: {$tab->title}");

            foreach ($tab->children as $child) {
                $this->assertNotSame('#', $child->resolved_url, "dead link: {$child->title}");
            }
        }
    }

    public function test_the_console_subcategories_filter_the_catalog(): void
    {
        $xbox = Category::where('slug', 'xbox-rental')->firstOrFail();

        $product = Product::create([
            'category_id' => $xbox->id,
            'title_fa' => 'ایکس‌باکس اجاره‌ای',
            'slug' => 'xbox-test-'.uniqid(),
            'price' => 400000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 1,
            'is_active' => true,
            'attributes' => ['_rental' => ['daily_rate' => 400000, 'deposit' => 2000000, 'status' => 'available']],
        ]);

        $this->get(route('products.index', ['category' => 'xbox-rental']))
            ->assertOk()
            ->assertSee($product->title_fa, false);
    }

    public function test_the_menu_renders_on_the_home_page_with_its_submenu(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('کرایه کنسول', false);
        $response->assertSee('Xbox', false);
        $response->assertSee('PlayStation', false);
        $response->assertSee('کرایه بازی‌های دیسکی', false);
    }

    public function test_the_top_header_no_longer_carries_those_two_links_but_the_routes_live_on(): void
    {
        $home = $this->get(route('home'))->assertOk()->getContent();

        // Both were removed from the top bar. They survive in the footer /
        // categories menu, so assert on position rather than absence: whatever
        // remains must come after the header markup ends.
        $headerEnd = strpos($home, '</header>');
        $this->assertNotFalse($headerEnd);

        $trackingInHeader = strpos(substr($home, 0, $headerEnd), 'پیگیری سفارش');
        $this->assertFalse($trackingInHeader, 'پیگیری سفارش must not be in the header');

        // The routes themselves are untouched.
        $this->get(route('terms'))->assertOk();
        $this->get(route('about'))->assertOk();
        $this->get(route('orders.index'))->assertRedirect(route('auth.login'));

        // And both are still reachable from the footer.
        $this->assertStringContainsString('پیگیری سفارش', substr($home, $headerEnd));
        $this->assertStringContainsString('قوانین و مقررات', substr($home, $headerEnd));
    }

    public function test_the_home_page_shows_suggested_rentable_products(): void
    {
        $product = Product::create([
            'category_id' => Category::where('slug', 'console-rental')->value('id'),
            'title_fa' => 'کنسول پیشنهادی',
            'slug' => 'suggested-'.uniqid(),
            'price' => 500000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 1,
            'is_active' => true,
            'attributes' => ['_rental' => ['daily_rate' => 500000, 'deposit' => 3000000, 'status' => 'available']],
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('محصولات پیشنهادی', false)
            ->assertSee($product->title_fa, false)
            ->assertSee(route('products.show', $product->slug), false);
    }

    public function test_the_search_bar_uses_the_jalali_picker_not_a_native_date_input(): void
    {
        $home = $this->get(route('home'))->assertOk();

        $home->assertSee('data-jdp', false);
        $home->assertSee('فروردین', false);          // month names ship with the picker
        $home->assertDontSee('type="date"', false);  // no Gregorian native input

        // The catalog listing carries the same component.
        $this->get(route('products.index'))->assertOk()->assertSee('data-jdp', false);
    }

    public function test_the_product_page_has_no_second_date_picker(): void
    {
        $product = Product::create([
            'category_id' => Category::where('slug', 'console-rental')->value('id'),
            'title_fa' => 'کنسول تست تقویم',
            'slug' => 'calendar-'.uniqid(),
            'price' => 500000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 1,
            'is_active' => true,
            'attributes' => ['_rental' => ['daily_rate' => 500000, 'deposit' => 3000000, 'status' => 'available']],
        ]);

        // Without a window it asks the visitor to pick one first.
        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertDontSee('id="rental-start-date"', false)
            ->assertSee('ابتدا تاریخ شروع و پایان اجاره را انتخاب کنید', false);

        // With one, it shows the chosen window in Jalali.
        $from = now()->addDays(2)->toDateString();
        $to = now()->addDays(5)->toDateString();

        $this->get(route('products.show', ['slug' => $product->slug, 'from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertSee('بازه انتخابی', false)
            // Persian digits, and the END the reservation will actually hold
            // (start + days - 1), not the raw `to` from the search bar.
            ->assertSee(Jalali::formatLong($from), false)
            ->assertSee(Jalali::formatLong(Jalali::addDays($from, Jalali::diffDays($from, $to) - 1)), false);
    }

    /** The JS picker is a port of this; these cases pin both. */
    public function test_the_jalali_conversion_round_trips(): void
    {
        $cases = [
            ['2026-09-05', 1405, 6, 14],
            ['2025-03-21', 1404, 1, 1],   // Nowruz
            ['2024-03-19', 1402, 12, 29],
            ['2024-03-20', 1403, 1, 1],   // Nowruz 1403
            ['2026-01-01', 1404, 10, 11],
        ];

        foreach ($cases as [$iso, $jy, $jm, $jd]) {
            [$y, $m, $d] = array_values(Jalali::parseIso($iso));
            $jalali = Jalali::fromGregorian($y, $m, $d);

            $this->assertSame([$jy, $jm, $jd], [$jalali['jy'], $jalali['jm'], $jalali['jd']], "gregorian→jalali for {$iso}");
            $this->assertSame($iso, Jalali::jalaliToIso($jy, $jm, $jd), "jalali→gregorian for {$iso}");
        }
    }

    public function test_esfand_length_follows_the_jalali_leap_rule(): void
    {
        $this->assertSame(30, Jalali::monthLength(1403, 12), '1403 is a leap year');
        $this->assertSame(29, Jalali::monthLength(1404, 12), '1404 is not');
        $this->assertSame(31, Jalali::monthLength(1405, 1));
        $this->assertSame(30, Jalali::monthLength(1405, 7));
    }
}
