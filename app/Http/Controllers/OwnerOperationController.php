<?php

namespace App\Http\Controllers;

use App\Models\GuaranteeNoteEvent;
use App\Models\RentalOperation;
use App\Services\Rental\DeviceCustodyService;
use Illuminate\Http\Request;

/**
 * What an owner sees and does about a pickup of their own device.
 *
 * Visibility plus one action: confirming that GamePek's record of the handover
 * matches what they did. Everything else about the task -- scheduling,
 * starting, recording receipt, marking failure -- is GamePek's operational
 * decision and is not reachable from here at all.
 *
 * What is deliberately NOT exposed: the customer's identity, the customer's
 * contact details, payment or bank data, and any device that is not this
 * owner's. The screens read the operation and its custody record and nothing
 * else, and RentalOperationPolicy denies cross-owner access server-side --
 * hiding a link is not authorization.
 *
 * Owner settlement and income belong to a later phase and appear nowhere here.
 */
class OwnerOperationController extends Controller
{
    public function __construct(private DeviceCustodyService $custody) {}

    public function index(Request $request)
    {
        $owner = $request->user()->owner;

        abort_if($owner === null, 403, 'این بخش مخصوص مالکان دستگاه است.');

        $this->authorize('view', $owner);

        $operations = RentalOperation::with(['device.product', 'custodyTransfer'])
            ->forOwner($owner->id)
            ->latest('id')
            ->paginate(15);

        return view('owner.operations.index', compact('owner', 'operations'));
    }

    public function show(Request $request, RentalOperation $operation)
    {
        $this->authorize('view', $operation);

        $operation->loadMissing(['device.product', 'custodyTransfer', 'reservation.settlement.credit']);

        // Only a note handed to THIS owner is theirs to know about.
        $noteTransferred = $operation->owner_id !== null && GuaranteeNoteEvent::where('rental_application_id', $operation->rental_application_id)
            ->where('event', GuaranteeNoteEvent::TRANSFERRED_TO_OWNER)
            ->where('owner_id', $operation->owner_id)
            ->exists();

        return view('owner.operations.show', compact('operation', 'noteTransferred'));
    }

    /**
     * Confirm GamePek's record of the handover.
     *
     * Changes no custody: possession already moved when GamePek recorded
     * receipt. This carries NO legal weight -- it is not a signature, not
     * acceptance, and says nothing about the condition of the device.
     */
    public function acknowledgeCustody(Request $request, RentalOperation $operation)
    {
        $this->authorize('acknowledgeCustody', $operation);

        $transfer = $operation->custodyTransfer()->first();

        if ($transfer === null) {
            return back()->with('error', 'برای این عملیات تحویلی ثبت نشده است.');
        }

        try {
            $this->custody->acknowledgeByOwner($transfer, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تحویل دستگاه توسط شما تأیید شد.');
    }
}
