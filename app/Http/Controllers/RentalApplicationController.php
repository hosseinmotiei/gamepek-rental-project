<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Order;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Services\Contract\ContractService;
use App\Services\Contract\SignatureOtpService;
use App\Services\Guarantee\GuaranteeService;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\Providers\ProviderException;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The customer-facing half of the rental chain.
 *
 * Every action here changes exactly one child record and then calls
 * $orchestrator->advance(). None of them writes the application's state -- see
 * RentalChainOrchestrator for why that separation is the whole design.
 */
class RentalApplicationController extends Controller
{
    public function __construct(
        private RentalReservationService $reservations,
        private RentalChainOrchestrator $orchestrator,
        private PaymentService $payments,
        private GuaranteeService $guarantees,
        private ContractService $contracts,
        private OtpService $otp,
        private SignatureOtpService $signatureOtp,
    ) {}

    /**
     * The customer's rental entry point: verification status, the one
     * application still in progress (if any), and the most recent others.
     *
     * Read-only. Scoped through the user relation exactly like index() below
     * -- no policy check per row is needed because the query itself can never
     * return another customer's data. Introduces no new fact: "active" means
     * "not terminal", using RentalApplicationState::isTerminal(), which
     * RentalChainOrchestrator already defines and enforces elsewhere.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $user->loadMissing(['identity', 'bankAccounts']);

        // A customer's own application count is inherently small (this is not
        // an admin listing), so a plain cap is enough -- no new pagination
        // concept is introduced for a page that shows only a handful of rows.
        $applications = $user->rentalApplications()
            ->with(['product', 'reservation'])
            ->latest()
            ->take(20)
            ->get();

        $active = $applications->first(fn (RentalApplication $application) => ! $application->state->isTerminal());

        return view('rental.dashboard', [
            'user' => $user,
            'identity' => $user->identity,
            'bankVerified' => $user->bankAccounts?->contains(fn ($account) => $account->isVerified()) ?? false,
            'bankAny' => $user->bankAccounts?->isNotEmpty() ?? false,
            'applications' => $applications,
            'active' => $active,
        ]);
    }

    /**
     * The customer's own rental applications, newest first.
     *
     * Scoped through the user relation rather than a policy check per row --
     * the same pattern OrderController::index() uses -- so this can never
     * return another customer's applications regardless of query input.
     */
    public function index(Request $request)
    {
        $applications = $request->user()
            ->rentalApplications()
            ->with('product')
            ->latest()
            ->paginate(10);

        return view('rental.index', ['applications' => $applications]);
    }

    public function store(Request $request)
    {
        $application = $this->reservations->openApplication($request->user());

        return $this->ok($request, 'درخواست اجاره ایجاد شد.', [
            'application_number' => $application->application_number,
            'state' => $application->state->value,
        ]);
    }

    public function show(Request $request, RentalApplication $application)
    {
        $this->authorize('view', $application);

        $application->loadMissing([
            'user.identity', 'user.bankAccounts',
            'reservation.product', 'order', 'guarantee.inquiries',
            'contract.signatures', 'transitions',
        ]);

        // Re-derive before rendering. A customer can finish a step elsewhere
        // (identity, bank ownership) and come back here; advance() is a no-op
        // when nothing changed, so this only ever catches the page up.
        $this->orchestrator->advance($application);

        $application->refresh()->loadMissing([
            'user.identity', 'user.bankAccounts',
            'reservation.product', 'order', 'guarantee.inquiries',
            'contract.signatures', 'transitions',
        ]);

        return view('rental.application', ['application' => $application]);
    }

    public function reserve(Request $request, RentalApplication $application)
    {
        $this->authorize('update', $application);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'start_date' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'extra_controller' => ['sometimes', 'boolean'],
        ], [
            'start_date.required' => 'انتخاب تاریخ شروع الزامی است.',
            'days.required' => 'تعیین مدت اجاره الزامی است.',
            'days.min' => 'مدت اجاره باید حداقل یک روز باشد.',
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            // Note what is NOT read from the request: any price. The quote is
            // computed server-side by RentalPricingService.
            //
            // This records the choice only. It creates no reservation and
            // blocks no inventory -- C-15/C-16 put the reservation strictly
            // after a verified payment.
            $application = $this->reservations->recordSelection(
                $application,
                $product,
                $data['start_date'],
                (int) $data['days'],
                (bool) ($data['extra_controller'] ?? false),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        $quote = (array) $application->quote;

        return $this->ok($request, 'انتخاب شما ثبت شد.', [
            'payable_now' => $quote['payable_now'] ?? 0,
            'deposit' => $quote['deposit'] ?? 0,
            'state' => $application->refresh()->state->value,
        ]);
    }

    /**
     * Creates the Order from the reservation snapshot and starts payment.
     *
     * The order total is `payable_now`, which by RentalQuote's own contract
     * excludes the deposit -- a deposit is a refundable hold, not a charge,
     * and TODO(business) B4 leaves how it is held undecided. Nothing here
     * charges it.
     */
    public function pay(Request $request, RentalApplication $application)
    {
        $this->authorize('update', $application);

        $application->loadMissing(['order', 'user.identity', 'user.bankAccounts']);

        // THE PAYMENT GATE (C-13/C-14). Server-side and authoritative: the UI
        // hiding the button is a convenience, this is the control. A direct
        // POST from a client that skipped verification lands here and stops.
        if ($reason = $this->orchestrator->paymentBlockedReason($application)) {
            return $this->fail($request, $this->paymentBlockedMessage($reason), 422);
        }

        $order = DB::transaction(function () use ($application) {
            $locked = RentalApplication::where('id', $application->id)->lockForUpdate()->first();

            if ($locked->order_id) {
                return Order::find($locked->order_id);
            }

            // The quote snapshotted at selection is what is charged. It was
            // computed server-side and has never been through the browser.
            $quote = (array) $locked->quote;

            $order = Order::create([
                'order_number' => 'RNT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'user_id' => $locked->user_id,
                'status' => 'pending_payment',
                'payment_status' => 'unpaid',
                'subtotal' => $quote['rental_total'] ?? 0,
                'shipping_cost' => $quote['delivery_fee'] ?? 0,
                'total' => $quote['payable_now'] ?? 0,
            ]);

            $locked->update(['order_id' => $order->id]);

            return $order;
        });

        $this->orchestrator->advance($application->refresh(), 'order created');

        $result = $this->payments->initiatePayment($order);

        if (! ($result['success'] ?? false)) {
            return $this->fail($request, $result['message'] ?? 'درگاه پرداخت در دسترس نیست.', 422);
        }

        // A browser form post has to actually land on the gateway; only an
        // API caller wants the URL handed back to it.
        if (! $request->expectsJson()) {
            return redirect()->away($result['redirect_url']);
        }

        return $this->ok($request, 'در حال انتقال به درگاه پرداخت.', [
            'redirect_url' => $result['redirect_url'],
            'order_number' => $order->order_number,
        ]);
    }

    public function storeGuarantee(Request $request, RentalApplication $application)
    {
        $this->authorize('update', $application);

        $data = $request->validate([
            'type' => ['sometimes', 'in:cheque,promissory_note'],
            'sayad_id' => ['required_if:type,cheque', 'nullable', 'string', 'size:16'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'bank_code' => ['nullable', 'string', 'max:8'],
            'bank_name' => ['nullable', 'string', 'max:100'],
        ], [
            'sayad_id.required_if' => 'وارد کردن شناسه صیاد چک الزامی است.',
            'sayad_id.size' => 'شناسه صیاد باید ۱۶ رقم باشد.',
        ]);

        $application->loadMissing('user.identity');

        try {
            $guarantee = $this->guarantees->submit($application, $data);
            $guarantee->setRelation('application', $application);
            $this->guarantees->runInquiries($guarantee);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        } catch (ProviderException $e) {
            return $this->fail($request, $e->persianMessage, 503);
        }

        $this->orchestrator->advance($application->refresh(), 'guarantee submitted');

        return $this->ok($request, 'اطلاعات ضمانت ثبت و استعلام شد.', [
            'guarantee_state' => $guarantee->refresh()->state->value,
            'state' => $application->refresh()->state->value,
        ]);
    }

    public function contract(Request $request, RentalApplication $application)
    {
        $this->authorize('view', $application);

        try {
            $contract = $this->contracts->generate($application);
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        $this->orchestrator->advance($application->refresh(), 'contract generated');

        return view('rental.contract', [
            'application' => $application,
            'contract' => $contract,
        ]);
    }

    public function acceptContract(Request $request, RentalApplication $application)
    {
        $this->authorize('update', $application);

        $application->loadMissing('contract');
        $contract = $application->contract;

        if (! $contract) {
            return $this->fail($request, 'ابتدا باید قرارداد صادر شود.', 422);
        }

        try {
            $this->contracts->accept(
                $contract,
                $request->user(),
                (string) $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        $this->orchestrator->advance($application->refresh(), 'contract accepted');

        return $this->ok($request, 'قرارداد پذیرفته شد.', [
            'state' => $application->refresh()->state->value,
        ]);
    }

    /**
     * Sends the signing OTP.
     *
     * This route carries its OWN throttle name, separate from
     * `auth.send-otp`'s. If they shared a bucket, signing a contract could
     * exhaust the login OTP allowance and lock the customer out of their own
     * account halfway through the signature.
     */
    public function requestSignatureOtp(Request $request, RentalApplication $application)
    {
        $this->authorize('update', $application);

        $application->loadMissing('contract');
        $contract = $application->contract;

        if (! $contract) {
            return $this->fail($request, 'قراردادی برای امضا وجود ندارد.', 422);
        }

        $contract->setRelation('application', $application);

        try {
            $code = $this->signatureOtp->request($contract, $request->user(), (string) $request->ip());
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Contract signature OTP failed', ['exception' => $e->getMessage()]);

            return $this->fail($request, 'ارسال کد تأیید ممکن نشد. لطفاً دوباره تلاش کنید.', 503);
        }

        $payload = [];

        // The project's existing local-only mechanism, unchanged: the code is
        // surfaced solely under the conditions OtpService already defines, and
        // never in production.
        if ($this->otp->canShowOtpInDevelopment()) {
            $payload['dev_otp'] = $code;
            session()->flash('dev_otp_message', 'کد تست امضا: '.$code);
        }

        return $this->ok($request, 'کد تأیید امضا ارسال شد.', $payload);
    }

    public function signContract(Request $request, RentalApplication $application)
    {
        $this->authorize('update', $application);

        $data = $request->validate([
            'code' => ['required', 'string'],
        ], [
            'code.required' => 'وارد کردن کد تأیید الزامی است.',
        ]);

        $application->loadMissing('contract');
        $contract = $application->contract;

        if (! $contract) {
            return $this->fail($request, 'قراردادی برای امضا وجود ندارد.', 422);
        }

        $contract->setRelation('application', $application);

        try {
            // Bound to THIS contract, not merely to the customer's mobile: a
            // login code, or a code issued for another contract, cannot sign.
            if (! $this->signatureOtp->verify($contract, $request->user(), $data['code'])) {
                return $this->fail($request, 'کد تأیید نادرست یا منقضی شده است.', 422);
            }

            $signature = $this->contracts->sign($contract, $request->user(), [
                'ip' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'otp_reference' => 'contract:'.$contract->id,
            ]);
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        $this->orchestrator->advance($application->refresh(), 'contract signed');

        return $this->ok($request, 'قرارداد با موفقیت امضا شد.', [
            'signature_id' => $signature->id,
            'state' => $application->refresh()->state->value,
        ]);
    }

    /**
     * Turns the gate's internal reason key into a clear Persian message.
     *
     * The key itself never reaches the customer: it names internal states and
     * would leak the shape of the chain. Each message says what the customer
     * must do next, not what the system checked.
     */
    private function paymentBlockedMessage(string $reason): string
    {
        return match ($reason) {
            'identity_missing',
            'identity_not_verified' => 'برای پرداخت، ابتدا باید احراز هویت شما تکمیل و تأیید شود.',
            'bank_missing',
            'bank_not_verified' => 'برای پرداخت، ابتدا باید حساب بانکی شما ثبت و تأیید شود.',
            'selection_missing' => 'ابتدا دستگاه و بازه اجاره را انتخاب کنید.',
            'application_closed' => 'این درخواست بسته شده است و امکان پرداخت ندارد.',
            default => 'در حال حاضر امکان پرداخت برای این درخواست وجود ندارد.',
        };
    }

    private function ok(Request $request, string $message, array $payload = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['success' => true, 'message' => $message], $payload));
        }

        return back()->with('success', $message);
    }

    private function fail(Request $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return back()->withErrors(['rental' => $message]);
    }
}
