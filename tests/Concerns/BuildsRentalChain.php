<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Contract\ContractService;
use App\Services\Contract\SignatureOtpService;
use App\Services\Guarantee\GuaranteeService;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Illuminate\Support\Str;

/**
 * Scaffolding for tests that need a rental application already standing on a
 * particular rung. There are no model factories in this project; everything is
 * built through the real services so the fixtures cannot drift from the chain.
 */
trait BuildsRentalChain
{
    /** A national code that passes the real check-digit algorithm. */
    protected const NATIONAL_CODE = '0499370899';

    protected function makeRentableProduct(): Product
    {
        $category = Category::create([
            'name_fa' => 'کنسول اجاره‌ای',
            'slug' => 'rental-console-'.uniqid(),
            'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'title_fa' => 'پلی‌استیشن ۵ اجاره‌ای',
            'slug' => 'ps5-rental-'.uniqid(),
            'price' => 500_000,
            'stock_status' => 'in_stock',
            'stock_quantity' => 1,
            'is_active' => true,
            'attributes' => [
                '_rental' => [
                    'daily_rate' => 500_000,
                    'deposit' => 3_000_000,
                    'delivery_fee' => 80_000,
                    'status' => 'available',
                    'extra_controller_daily' => 50_000,
                ],
            ],
        ]);
    }

    /** Identity + reservation only -- the rung below payment. */
    protected function reservedApplication(User $user, string $nationalCode = self::NATIONAL_CODE): RentalApplication
    {
        app(IdentityVerificationService::class)->submit($user, $nationalCode, '1995-03-21');

        $application = app(RentalReservationService::class)->openApplication($user);

        app(RentalReservationService::class)->reserve(
            $application,
            $this->makeRentableProduct(),
            now()->addDay()->toDateString(),
            3,
        );

        return $application->refresh();
    }

    /**
     * The rung the guarantee step starts from. The gateway round trip is
     * exercised by PaymentCallbackTest; here the settled order is the fixture,
     * and the state still comes from the orchestrator.
     */
    protected function paidApplication(User $user, string $nationalCode = self::NATIONAL_CODE): RentalApplication
    {
        $application = $this->reservedApplication($user, $nationalCode);
        $reservation = $application->reservation;

        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => $reservation->rental_total,
            'shipping_cost' => $reservation->delivery_fee,
            'total' => $reservation->payable_now,
            'paid_at' => now(),
        ]);

        $application->update(['order_id' => $order->id]);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'payment settled');

        return $application->refresh();
    }

    /**
     * The rung contract generation starts from.
     *
     * The caller must have set verification.guarantee.required_inquiries --
     * TODO(business) B6 leaves it empty by default, and with no policy the
     * guarantee correctly refuses to verify itself.
     */
    protected function guaranteeVerifiedApplication(
        User $user,
        string $sayadId = '1234567890123456',
        string $nationalCode = self::NATIONAL_CODE,
    ): RentalApplication {
        $application = $this->paidApplication($user, $nationalCode);

        $guarantees = app(GuaranteeService::class);
        $guarantee = $guarantees->submit($application, ['sayad_id' => $sayadId]);
        $guarantee->setRelation('application', $application->load('user.identity'));
        $guarantees->runInquiries($guarantee);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'guarantee verified');

        return $application->refresh();
    }

    /** The rung contract acceptance starts from; `contract` is eager loaded. */
    protected function contractGeneratedApplication(
        User $user,
        string $sayadId = '1234567890123456',
        string $nationalCode = self::NATIONAL_CODE,
    ): RentalApplication {
        $application = $this->guaranteeVerifiedApplication($user, $sayadId, $nationalCode);

        app(ContractService::class)->generate($application);
        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract generated');

        return $application->refresh()->load('contract');
    }

    /** The rung contract signing starts from; `contract` is eager loaded. */
    protected function contractAcceptedApplication(
        User $user,
        string $sayadId = '1234567890123456',
        string $nationalCode = self::NATIONAL_CODE,
    ): RentalApplication {
        $application = $this->contractGeneratedApplication($user, $sayadId, $nationalCode);

        app(ContractService::class)->accept($application->contract, $user, '127.0.0.1', 'phpunit');
        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract accepted');

        return $application->refresh()->load('contract');
    }

    /**
     * The rung final approval starts from: signed contract, application already
     * derived to AwaitingFinalApproval. The signing code is delivered by
     * whatever OtpProviderInterface the calling test has pinned.
     */
    protected function signedApplication(User $user, string $code = '13579'): RentalApplication
    {
        $application = $this->contractAcceptedApplication($user);
        $contract = $application->contract;

        $otp = app(SignatureOtpService::class);
        $otp->request($contract, $user);

        if (! $otp->verify($contract, $user, $code)) {
            throw new \RuntimeException('The pinned signing code was rejected.');
        }

        app(ContractService::class)->sign($contract->refresh(), $user, [
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract signed');

        return $application->refresh()->load('contract.signatures');
    }
}
