<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Banking\BankAccountService;
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

    /**
     * A fresh national code that passes the real check-digit algorithm.
     *
     * `user_identities.national_code_hash` is unique, so a test needing two
     * verified customers cannot reuse NATIONAL_CODE for both.
     */
    protected function uniqueNationalCode(): string
    {
        do {
            $base = str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT);

            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((int) $base[$i]) * (10 - $i);
            }

            $remainder = $sum % 11;
            $check = $remainder < 2 ? $remainder : 11 - $remainder;

            $code = $base.$check;

            // All-identical digits are rejected by the validator.
        } while (preg_match('/^(\d)\1{9}$/', $code));

        return $code;
    }

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

    /**
     * Puts the user through the full mandatory pre-payment chain: identity
     * verified, then a verified bank account.
     *
     * Both are hard gates before payment (C-13/C-14), so every fixture above
     * the payment rung has to go through them. Identity is promoted by an
     * admin rather than by a policy, because required_checks ships empty
     * (TODO(business) B1) and correctly refuses to auto-verify.
     */
    protected function completeKyc(User $user, string $nationalCode = self::NATIONAL_CODE): void
    {
        $identity = app(IdentityVerificationService::class)->submit($user, $nationalCode, '1995-03-21');

        $admin = User::create([
            'full_name' => 'KYC Reviewer',
            'mobile' => '0912'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        ]);

        app(IdentityVerificationService::class)->approveManually($identity, $admin, 'test fixture');

        $account = app(BankAccountService::class)->add($user, 'card', '6037997599999993');
        app(BankAccountService::class)->verify($account);

        $user->load(['identity', 'bankAccounts']);
    }

    /**
     * Selection made and every payment prerequisite satisfied -- the rung
     * directly below payment.
     *
     * NOTE: despite the historical name, NOTHING is reserved here. C-15/C-16
     * put the reservation strictly after a verified payment, so no
     * `rental_reservations` row exists at this point and no inventory is
     * blocked. The product and dates live on the application itself.
     */
    protected function reservedApplication(User $user, string $nationalCode = self::NATIONAL_CODE): RentalApplication
    {
        $this->completeKyc($user, $nationalCode);

        $application = app(RentalReservationService::class)->openApplication($user);

        app(RentalReservationService::class)->recordSelection(
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
     *
     * The reservation is created through the real post-payment path, so these
     * fixtures exercise the same code the callback does.
     */
    protected function paidApplication(User $user, string $nationalCode = self::NATIONAL_CODE): RentalApplication
    {
        $application = $this->reservedApplication($user, $nationalCode);
        $quote = (array) $application->quote;

        $order = Order::create([
            'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => $quote['rental_total'] ?? 0,
            'shipping_cost' => $quote['delivery_fee'] ?? 0,
            'total' => $quote['payable_now'] ?? 0,
            'paid_at' => now(),
        ]);

        $application->update(['order_id' => $order->id]);

        app(RentalReservationService::class)->materialiseAfterPayment($application->refresh());

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
    protected function signedApplication(
        User $user,
        string $code = '13579',
        string $nationalCode = self::NATIONAL_CODE,
        string $sayadId = '1234567890123456',
    ): RentalApplication {
        $application = $this->contractAcceptedApplication($user, $sayadId, $nationalCode);
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
