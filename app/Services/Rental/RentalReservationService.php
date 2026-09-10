<?php

namespace App\Services\Rental;

use App\Enums\ReservationState;
use App\Exceptions\ReservationConflictException;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\RentalPricingService;
use App\Support\Rental\RentalItem;
use App\Support\Rental\RentalQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Selection before payment; reservation after it.
 *
 * Confirmed business rules C-15 and C-16: a reservation happens ONLY after a
 * successful, verified payment, and there is no unpaid hold. This class is
 * therefore split in two halves that must not be confused:
 *
 *  - recordSelection() stores WHAT the customer wants on the application
 *    itself. It writes no `rental_reservations` row and blocks no inventory.
 *    An application that is abandoned here (C-17) simply sits in the database
 *    forever, harmlessly.
 *
 *  - materialiseAfterPayment() is the only writer of `rental_reservations`.
 *    It runs after the payment is server-verified, takes a lock, re-checks
 *    availability inside that lock, and writes a reservation already in the
 *    Paid state. That row is what blocks inventory, and it is also where the
 *    operational pickup task is opened -- in the same transaction, so the two
 *    cannot disagree about whether a paid rental exists.
 *
 * Two things this deliberately does NOT do:
 *
 *  - It does not read `stock_quantity`. Scalar stock is the wrong question for
 *    a rental; interval availability is answered by RentalAvailabilityService.
 *
 *  - It does not accept a price from the client. The quote always comes from
 *    RentalPricingService and is snapshotted at selection, so the figure the
 *    customer agreed to is the figure charged and recorded.
 */
class RentalReservationService
{
    public function __construct(
        private RentalPricingService $pricing,
        private RentalChainOrchestrator $orchestrator,
        private RentalAvailabilityService $availability,
        private RentalOperationService $operations,
    ) {}

    /**
     * Record the customer's product and date choice on the application.
     *
     * Writes NO reservation and blocks NO inventory. The availability check
     * here is advisory only -- it stops the customer walking into a range that
     * is already gone, but it grants nothing. The binding check is the locked
     * one in materialiseAfterPayment().
     *
     * @throws \RuntimeException with a Persian message when the product is not
     *                           rentable or the range is invalid
     * @throws ReservationConflictException when the range is already taken
     */
    public function recordSelection(
        RentalApplication $application,
        Product $product,
        string $startDate,
        int $days,
        bool $withExtraController = false,
    ): RentalApplication {
        $item = RentalItem::for($product);

        if (! $item || ! $item->isRentable()) {
            throw new \RuntimeException('این محصول قابل اجاره نیست.');
        }

        // C-20: minimum rental duration is one day. C-21 leaves the maximum
        // unlimited, so no upper bound is imposed here.
        if ($days < 1) {
            throw new \RuntimeException('مدت اجاره باید حداقل یک روز باشد.');
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = $start->copy()->addDays($days - 1);

        if (! $this->availability->isFree($product->id, $start->toDateString(), $end->toDateString())) {
            $conflict = new ReservationConflictException(
                productId: $product->id,
                startDate: $start->toDateString(),
                endDate: $end->toDateString(),
                rentalApplicationId: $application->id,
            );

            $this->auditConflict($conflict, 'selection');

            throw $conflict;
        }

        $quote = $this->quoteFor($item, $days, $withExtraController);

        $application->update([
            'product_id' => $product->id,
            'selected_start_date' => $start->toDateString(),
            'selected_end_date' => $end->toDateString(),
            'selected_days' => $days,
            'selected_extra_controller' => $withExtraController,
            'quote' => $quote->toArray(),
        ]);

        AuditLogger::log(
            action: 'rental_application.selection_recorded',
            resourceType: 'RentalApplication',
            resourceId: $application->id,
            context: [
                'product_id' => $product->id,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'payable_now' => $quote->payableNow,
                'deposit' => $quote->deposit,
            ],
        );

        return $this->orchestrator->advance($application->refresh(), 'selection recorded');
    }

    /**
     * Create the reservation for an application whose payment has cleared.
     *
     * The only writer of `rental_reservations`. Idempotent: the table has a
     * unique index on `rental_application_id`, and an existing row is returned
     * untouched, so a replayed gateway callback cannot double-book.
     *
     * Concurrency: the product row is locked first, so two payments clearing
     * for the same device and dates serialise here rather than both passing the
     * overlap check. The loser gets a ReservationConflictException.
     *
     * The caller decides what a conflict means AFTER a successful payment --
     * this class refuses to invent a refund rule (see the controller).
     *
     * @throws \RuntimeException when the application has no selection or is unpaid
     * @throws ReservationConflictException when the range was taken while paying
     */
    public function materialiseAfterPayment(RentalApplication $application): RentalReservation
    {
        $application->loadMissing(['order', 'reservation']);

        if ($application->reservation) {
            return $application->reservation;
        }

        if (! $application->hasSelection()) {
            throw new \RuntimeException('برای این درخواست انتخابی ثبت نشده است.');
        }

        if ($application->order?->payment_status !== 'paid') {
            throw new \RuntimeException('پرداخت این درخواست تأیید نشده است.');
        }

        try {
            return DB::transaction(function () use ($application) {
                // Lock the product first so racing payments serialise here.
                Product::where('id', $application->product_id)->lockForUpdate()->first();

                // Re-read under the lock: another payment may have landed
                // between the gateway verifying and this transaction starting.
                $existing = RentalReservation::where('rental_application_id', $application->id)->first();

                if ($existing) {
                    return $existing;
                }

                $start = $application->selected_start_date->toDateString();
                $end = $application->selected_end_date->toDateString();

                if (! $this->availability->isFree($application->product_id, $start, $end)) {
                    throw new ReservationConflictException(
                        productId: $application->product_id,
                        startDate: $start,
                        endDate: $end,
                        rentalApplicationId: $application->id,
                    );
                }

                $quote = (array) $application->quote;
                $product = $application->product;

                $reservation = RentalReservation::create([
                    'rental_application_id' => $application->id,
                    'product_id' => $application->product_id,
                    'product_snapshot' => [
                        'title' => $product?->title_fa,
                        'slug' => $product?->slug,
                        'daily_rate' => $quote['daily_rate'] ?? 0,
                        'deposit' => $quote['deposit'] ?? 0,
                        'delivery_fee' => $quote['delivery_fee'] ?? 0,
                    ],
                    'start_date' => $start,
                    'end_date' => $end,
                    'days' => $application->selected_days,
                    'daily_rate' => $quote['daily_rate'] ?? 0,
                    'subtotal' => $quote['subtotal'] ?? 0,
                    'discount' => $quote['discount'] ?? 0,
                    'delivery_fee' => $quote['delivery_fee'] ?? 0,
                    'rental_total' => $quote['rental_total'] ?? 0,
                    'deposit_amount' => $quote['deposit'] ?? 0,
                    'payable_now' => $quote['payable_now'] ?? 0,
                    'quote' => $quote,

                    // Created already paid: under C-15/C-16 no other kind of
                    // reservation can exist. This is the state that blocks
                    // inventory (see RentalReservation::scopeBlocking).
                    'state' => ReservationState::Paid,

                    // No hold window: there is nothing to expire.
                    'held_until' => null,
                ]);

                AuditLogger::log(
                    action: 'reservation.created',
                    resourceType: 'RentalReservation',
                    resourceId: $reservation->id,
                    context: [
                        'rental_application_id' => $application->id,
                        'application_number' => $application->application_number,
                        'product_id' => $application->product_id,
                        'order_id' => $application->order_id,
                        'start' => $start,
                        'end' => $end,
                        'days' => $application->selected_days,
                    ],
                );

                // The operational task is opened in THIS transaction, so it can
                // never exist for a reservation that failed to be written, and
                // a payment that never settled produces neither. It is
                // idempotent and backed by a unique index, so a replayed
                // gateway callback still yields exactly one task.
                //
                // It is born without a device: allocation is undecided, and
                // nothing here picks one.
                $this->operations->openPickupForReservation($reservation);

                return $reservation;
            });
        } catch (ReservationConflictException $e) {
            // Written OUTSIDE the transaction on purpose. An audit row created
            // inside a transaction that then throws is rolled back with it --
            // which is exactly why real conflicts previously left no trace.
            $this->auditConflict($e, 'after_payment');

            throw $e;
        }
    }

    public function openApplication(User $user): RentalApplication
    {
        $application = RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $user->id,
            'correlation_id' => AuditLogger::correlationId(),
            'submitted_at' => now(),
        ]);

        // `state` is not fillable, so the row's default lands in the database
        // rather than on this instance.
        $application->refresh();

        AuditLogger::log(
            action: 'rental_application.opened',
            resourceType: 'RentalApplication',
            resourceId: $application->id,
            context: ['application_number' => $application->application_number],
        );

        return $this->orchestrator->advance($application, 'application opened');
    }

    /** Read-only interval availability. Delegates to the single authority. */
    public function isFree(Product $product, string $startDate, string $endDate, ?int $ignoreReservationId = null): bool
    {
        return $this->availability->isFree($product->id, $startDate, $endDate, $ignoreReservationId);
    }

    private function quoteFor(RentalItem $item, int $days, bool $withExtraController): RentalQuote
    {
        return $this->pricing->quote(
            dailyRate: $item->dailyRate(),
            days: $days,
            extraControllerDaily: $item->extraControllerDaily(),
            withExtraController: $withExtraController,
            // TODO(business) C-11: the selected game may affect price. There is
            // no game selection in the flow yet, so nothing is added here.
            gameFee: 0,
            deliveryFee: $item->deliveryFee(),
            deposit: $item->deposit(),
        );
    }

    private function auditConflict(ReservationConflictException $e, string $stage): void
    {
        AuditLogger::log(
            action: 'reservation.conflict',
            resourceType: 'Product',
            resourceId: $e->productId,
            result: AuditLogger::RESULT_DENIED,
            context: $e->auditContext() + ['stage' => $stage],
        );
    }
}
