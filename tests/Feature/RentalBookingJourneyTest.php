<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Models\Category;
use App\Models\GuaranteeInquiry;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Rental\RentalChainOrchestrator;
use Database\Seeders\ContractTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The journey a customer actually clicks through: product page → reserve →
 * KYC → bank ownership → payment → guarantee → contract → signature → the
 * admin's final approval.
 *
 * The end-to-end service test (RentalChainEndToEndTest) drives the services
 * directly; this one drives the HTTP routes the buttons post to, so a broken
 * form action or a wrong redirect fails here.
 */
class RentalBookingJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_CODE = '0499370899';

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContractTemplateSeeder::class);

        // Production ships with no owner-approved policy, so nothing
        // auto-verifies. The journey supplies one to exercise the happy path;
        // the fail-closed default is asserted in RentalChainEndToEndTest.
        config()->set('verification.identity.required_checks', ['shahkar', 'civil_registry']);
        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی',
            'mobile' => '09121112233',
            'email' => 'renter@test.local',
            'status' => 'active',
        ]);

        $this->product = $this->makeRentableProduct();
    }

    public function test_the_product_page_offers_online_reservation(): void
    {
        $response = $this->get(route('products.show', $this->product->slug))->assertOk();

        $response->assertSee('ادامه رزرو', false);
        // The window is chosen in the search bar now, not on this page, so
        // without one the panel asks for it instead of offering a picker.
        $response->assertSee('ابتدا تاریخ شروع و پایان اجاره را انتخاب کنید', false);
        $response->assertDontSee('id="rental-start-date"', false);
        // The old "not available online, call support" note must be gone.
        $response->assertDontSee('ثبت رزرو آنلاین هنوز فعال نشده است', false);
    }

    public function test_the_whole_booking_journey_runs_through_the_http_routes(): void
    {
        $this->actingAs($this->customer->fresh());

        // ── 1. Open an application and reserve into it ───────────────────
        $opened = $this->postJson(route('rental.applications.store'))->assertOk()->json();
        $number = $opened['application_number'];

        $this->postJson(route('rental.applications.reserve', $number), [
            'product_id' => $this->product->id,
            'start_date' => now()->addDays(2)->toDateString(),
            'days' => 3,
            'extra_controller' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $application = RentalApplication::where('application_number', $number)->firstOrFail();
        $reservation = $application->refresh()->reservation;

        $this->assertNotNull($reservation);
        // The price is the server's, never the browser's.
        $this->assertSame(0, $reservation->payable_now % 1);
        $this->assertGreaterThan(0, $reservation->payable_now);
        $this->assertGreaterThan(0, $reservation->deposit_amount);

        // ── 2. The application page shows the journey ────────────────────
        $this->get(route('rental.applications.show', $application))
            ->assertOk()
            ->assertSee('مراحل درخواست', false)
            ->assertSee('احراز هویت', false);

        // ── 3. KYC level 2 + bank ownership, through their own routes ────
        $this->post(route('verification.identity.store'), [
            'national_code' => self::NATIONAL_CODE,
            'birth_date' => '1995-03-21',
        ])->assertRedirect();

        foreach (['shahkar', 'civil_registry'] as $check) {
            $this->post(route('verification.identity.run'), ['type' => $check])->assertRedirect();
        }

        $this->assertTrue($this->customer->fresh()->identity->isVerified());

        $account = $this->postJson(route('verification.bank.store'), [
            'type' => 'card',
            'value' => '6037997599999993',
        ])->assertOk()->json();

        $this->postJson(route('verification.bank.verify', $account['id']))
            ->assertOk()
            ->assertJson(['state' => 'verified']);

        // ── 4. Payment: the form post must land on the gateway ───────────
        $this->post(route('rental.applications.pay', $application))
            ->assertRedirect();

        $application->refresh()->loadMissing('order');
        $order = $application->order;
        $this->assertNotNull($order);
        // The deposit is a hold, never a charge.
        $this->assertSame($reservation->payable_now, (int) $order->total);

        $this->settlePayment($order);

        $this->assertSame('paid', $order->fresh()->payment_status);

        // ── 5. Guarantee ─────────────────────────────────────────────────
        $this->post(route('rental.applications.guarantee', $application), [
            'type' => 'cheque',
            'sayad_id' => '1234567890123456',
            'amount' => 5_000_000,
        ])->assertRedirect();

        $this->assertTrue($application->refresh()->guarantee->isVerified());

        // ── 6. Contract → acceptance → signature ─────────────────────────
        $this->get(route('rental.applications.contract', $application))->assertOk();

        $this->post(route('rental.applications.contract.accept', $application))->assertRedirect();

        $this->pinOtpCode('13579');
        $this->post(route('rental.applications.contract.sign.otp', $application))->assertRedirect();

        $code = '13579';
        $this->post(route('rental.applications.contract.sign', $application), ['code' => $code])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('signed', $application->refresh()->contract->state->value);

        // ── 7. Final approval is the admin's, never derived ──────────────
        $this->assertSame(
            RentalApplicationState::AwaitingFinalApproval,
            app(RentalChainOrchestrator::class)->advance($application->fresh())->state,
        );
    }

    public function test_a_guest_cannot_reserve(): void
    {
        $this->post(route('rental.applications.store'))->assertRedirect(route('auth.login'));
    }

    public function test_a_customer_cannot_open_someone_elses_application(): void
    {
        $other = User::create([
            'full_name' => 'کاربر دیگر', 'mobile' => '09121119999', 'status' => 'active',
        ]);

        // `state` is not mass assignable; the column's default is draft.
        $application = RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $other->id,
        ]);

        $this->actingAs($this->customer->fresh())
            ->get(route('rental.applications.show', $application))
            ->assertForbidden();
    }

    public function test_paying_twice_creates_only_one_order(): void
    {
        $this->actingAs($this->customer->fresh());

        $number = $this->postJson(route('rental.applications.store'))->json('application_number');
        $this->postJson(route('rental.applications.reserve', $number), [
            'product_id' => $this->product->id,
            'start_date' => now()->addDays(2)->toDateString(),
            'days' => 2,
        ])->assertOk();

        $application = RentalApplication::where('application_number', $number)->firstOrFail();

        $this->post(route('rental.applications.pay', $application))->assertRedirect();
        $this->post(route('rental.applications.pay', $application))->assertRedirect();

        $this->assertSame(1, Order::where('user_id', $this->customer->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Drives the mock gateway the way the in-app confirmation page does. */
    private function settlePayment(Order $order): void
    {
        $transaction = $order->paymentTransactions()->latest('id')->firstOrFail();

        MockGateway::recordOutcome((string) $transaction->authority, 'paid');

        $application = RentalApplication::where('order_id', $order->id)->firstOrFail();

        // A rental order must come back to its application, not to the shop's
        // basket-shaped success page.
        $this->get(route('payment.callback', [
            'Authority' => $transaction->authority,
            'gateway' => 'mock',
        ]))->assertRedirect(route('rental.applications.show', $application));
    }

    /**
     * OTP codes are stored as a keyed hash, never in plain text, so the test
     * cannot read the code back out of the database. It pins the provider
     * instead -- the same seam NullOtpProvider fills in local development.
     */
    private function pinOtpCode(string $code): void
    {
        $this->app->bind(OtpProviderInterface::class, fn () => new class($code) implements OtpProviderInterface
        {
            public function __construct(private string $code) {}

            public function send(string $mobile): string
            {
                return json_encode(['code' => $this->code]);
            }
        });
    }

    private function makeRentableProduct(): Product
    {
        $category = Category::create([
            'name_fa' => 'کنسول اجاره‌ای',
            'slug' => 'journey-category-'.uniqid(),
            'is_active' => true,
            'show_in_menu' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'title_fa' => 'پلی‌استیشن ۵ اجاره‌ای',
            'slug' => 'ps5-journey-'.uniqid(),
            'price' => 500_000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 1,
            'is_active' => true,
            'attributes' => ['_rental' => [
                'daily_rate' => 500_000,
                'deposit' => 3_000_000,
                'delivery_fee' => 80_000,
                'status' => 'available',
                'extra_controller_daily' => 50_000,
            ]],
        ]);
    }
}
