<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RentalApplicationState;
use App\Http\Controllers\Controller;
use App\Models\RentalApplication;
use App\Services\Contract\ContractService;
use App\Services\Guarantee\GuaranteeService;
use App\Services\Rental\RentalChainOrchestrator;
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
            'user.identity', 'order', 'reservation.product',
            'guarantee.inquiries', 'contract.signatures', 'transitions',
        ]);

        return view('admin.rental-applications.show', ['application' => $rentalApplication]);
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
