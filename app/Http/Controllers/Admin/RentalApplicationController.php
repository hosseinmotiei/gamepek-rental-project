<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RentalApplicationState;
use App\Http\Controllers\Controller;
use App\Models\RentalApplication;
use App\Services\Contract\ContractService;
use App\Services\Guarantee\GuaranteeService;
use App\Services\Rental\GuaranteeNoteService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalClosureReadiness;
use App\Services\Rental\RentalDamageAssessmentService;
use App\Services\Rental\RentalSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin side of the rental chain.
 *
 * Every action here routes through the same services the public flow uses --
 * the orchestrator is the only writer of `state`, so an admin can never move
 * an application to a state the chain itself would refuse.
 */
class RentalApplicationController extends Controller
{
    public function __construct(
        private RentalChainOrchestrator $orchestrator,
        private GuaranteeService $guarantees,
        private ContractService $contracts,
    ) {}

    public function index(Request $request): View
    {
        abort_if(! auth()->user()->can('view_rental_applications'), 403);

        $query = RentalApplication::with(['user', 'reservation.product'])->latest();

        if ($state = $request->get('state')) {
            $query->where('state', $state);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('application_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%"));
            });
        }

        return view('admin.rental-applications.index', [
            'applications' => $query->paginate(25)->withQueryString(),
            'states' => RentalApplicationState::cases(),
        ]);
    }

    public function show(RentalApplication $rentalApplication): View
    {
        abort_if(! auth()->user()->can('view_rental_applications'), 403);

        $rentalApplication->load([
            'user.identity', 'order', 'reservation.product', 'reservation.device', 'reservation.settlement.credit',
            'guarantee.inquiries', 'contract.signatures', 'transitions',
            'damageAssessments.assessor',
        ]);

        $reservation = $rentalApplication->reservation;

        return view('admin.rental-applications.show', [
            'application' => $rentalApplication,
            // Read-only reports; neither writes anything.
            'closureReadiness' => $rentalApplication->state === RentalApplicationState::Returned
                ? app(RentalClosureReadiness::class)->check($rentalApplication)
                : null,
            'settlementPreview' => $reservation && ! $reservation->settlement
                ? app(RentalSettlementService::class)->preview($reservation)
                : null,
            'damageStatus' => app(RentalDamageAssessmentService::class)->statusFor($rentalApplication->id),
            'noteStatus' => app(GuaranteeNoteService::class)->statusFor($rentalApplication->id),
            // Calculation only: no late fee is charged, settled or split.
            'lateReturn' => $reservation?->lateReturn(),
        ]);
    }

    /** GamePek physically received the customer's promissory note. */
    public function receiveNote(Request $request, RentalApplication $rentalApplication, GuaranteeNoteService $notes): RedirectResponse
    {
        return $this->closeoutAction($request, fn () => $notes->receive($rentalApplication, $request->user(), $this->noteText($request)), 'دریافت سفته ثبت شد.');
    }

    /** Note back to the customer: no damage, or damage paid. */
    public function returnNote(Request $request, RentalApplication $rentalApplication, GuaranteeNoteService $notes): RedirectResponse
    {
        return $this->closeoutAction($request, fn () => $notes->returnToCustomer($rentalApplication, $request->user(), $this->noteText($request)), 'بازگرداندن سفته به مشتری ثبت شد.');
    }

    /** GamePek-owned device, unpaid damage: the note stays with GamePek. */
    public function retainNote(Request $request, RentalApplication $rentalApplication, GuaranteeNoteService $notes): RedirectResponse
    {
        return $this->closeoutAction($request, fn () => $notes->retainByGamePek($rentalApplication, $request->user(), $this->noteText($request)), 'نگهداری سفته نزد گیم‌پک ثبت شد.');
    }

    /** Unpaid damage: note handed to the loss-bearing owner. */
    public function transferNote(Request $request, RentalApplication $rentalApplication, GuaranteeNoteService $notes): RedirectResponse
    {
        return $this->closeoutAction($request, fn () => $notes->transferToOwner($rentalApplication, $request->user(), $this->noteText($request)), 'تحویل سفته به مالک ثبت شد.');
    }

    /** The customer paid the current assessed damage directly. */
    public function recordDamagePayment(Request $request, RentalApplication $rentalApplication, RentalDamageAssessmentService $damage): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_rental_applications'), 403);

        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
        ], ['payment_reference.required' => 'ثبت شناسه پرداخت الزامی است.']);

        return $this->closeoutAction($request, fn () => $damage->recordPayment($rentalApplication, $request->user(), $data['payment_reference']), 'پرداخت خسارت ثبت شد.');
    }

    /** Credit the owner's share to the owner's wallet, once. */
    public function finalizeSettlement(Request $request, RentalApplication $rentalApplication, RentalSettlementService $settlements): RedirectResponse
    {
        return $this->closeoutAction($request, fn () => $settlements->finalize($rentalApplication, $request->user()), 'سهم مالک به کیف پول او واریز شد.');
    }

    /** Returned -> Closed, only when every prerequisite is met. */
    public function close(Request $request, RentalApplication $rentalApplication): RedirectResponse
    {
        return $this->closeoutAction($request, fn () => $this->orchestrator->close($rentalApplication, $request->user()), 'اجاره بسته شد.');
    }

    private function closeoutAction(Request $request, callable $action, string $success): RedirectResponse
    {
        abort_if(! $request->user()->can('manage_rental_applications'), 403);

        try {
            $action();
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function noteText(Request $request): ?string
    {
        return $request->validate(['notes' => ['nullable', 'string', 'max:1000']])['notes'] ?? null;
    }

    /**
     * Record the 35/65 CALCULATION for a returned owner rental. Moves no
     * money. Refused (and audited) while the gross basis is undecided.
     */
    public function calculateSettlement(
        Request $request,
        RentalApplication $rentalApplication,
        RentalSettlementService $settlements,
    ): RedirectResponse {
        abort_if(! auth()->user()->can('manage_rental_applications'), 403);

        try {
            $settlements->calculate($rentalApplication, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'سهم گیم‌پک و مالک محاسبه شد. هیچ پرداختی انجام نشده است.');
    }

    /** Re-derives the state from the child facts. Never forces a target. */
    public function refresh(RentalApplication $rentalApplication): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_rental_applications'), 403);

        $this->orchestrator->advance($rentalApplication);

        return back()->with('success', 'وضعیت درخواست بازخوانی شد.');
    }

    public function approve(Request $request, RentalApplication $rentalApplication): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_rental_applications'), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        try {
            $this->orchestrator->approve($rentalApplication, $request->user(), $note);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['application' => $e->getMessage()]);
        }

        return back()->with('success', 'درخواست اجاره تأیید نهایی شد.');
    }

    public function reject(Request $request, RentalApplication $rentalApplication): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_rental_applications'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'ثبت دلیل رد الزامی است.']);

        try {
            $this->orchestrator->reject($rentalApplication, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['application' => $e->getMessage()]);
        }

        return back()->with('success', 'درخواست اجاره رد شد.');
    }

    public function verifyGuarantee(Request $request, RentalApplication $rentalApplication): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_guarantees'), 403);

        $guarantee = $rentalApplication->guarantee;
        abort_if($guarantee === null, 404);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $this->guarantees->verifyManually($guarantee, $request->user(), $note);
        $this->orchestrator->advance($rentalApplication);

        return back()->with('success', 'ضمانت به‌صورت دستی تأیید شد.');
    }

    public function rejectGuarantee(Request $request, RentalApplication $rentalApplication): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_guarantees'), 403);

        $guarantee = $rentalApplication->guarantee;
        abort_if($guarantee === null, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'ثبت دلیل رد الزامی است.']);

        $this->guarantees->reject($guarantee, $data['reason'], $request->user());
        $this->orchestrator->advance($rentalApplication);

        return back()->with('success', 'ضمانت رد شد.');
    }

    public function voidContract(Request $request, RentalApplication $rentalApplication): RedirectResponse
    {
        abort_if(! auth()->user()->can('manage_contracts'), 403);

        $contract = $rentalApplication->contract;
        abort_if($contract === null, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'ثبت دلیل ابطال الزامی است.']);

        $this->contracts->void($contract, $request->user(), $data['reason']);
        $this->orchestrator->advance($rentalApplication);

        return back()->with('success', 'قرارداد باطل شد.');
    }
}
