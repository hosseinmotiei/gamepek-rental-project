<?php

namespace App\Services\Rental;

use App\Enums\ReservationState;
use App\Models\Product;
use App\Models\RentalApplication;
use App\Models\RentalReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\RentalPricingService;
use App\Support\Rental\RentalItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates the held date range and its price snapshot.
 *
 * Two things this deliberately does NOT do:
 *
 *  - It does not read `stock_quantity`. Scalar stock is the wrong question for
 *    a rental ("is this device free between these two dates?"), and
 *    .claude/rules/backend-services.md forbids introducing a second
 *    availability concept alongside Product::isInStock(). Interval
 *    availability is answered here by an overlap query against
 *    `rental_reservations`, which is what Product::isInStock() itself will be
 *    rewritten to delegate to once the owner decides what a rentable unit is.
 *    See the TODO below -- that rewrite is a domain decision, not a cleanup.
 *
 *  - It does not accept a price from the client. The quote always comes from
 *    RentalPricingService::quote(); the JS preview in
 *    partials/rental-panel.blade.php is display only.
 */
class RentalReservationService
{
    public function __construct(
        private RentalPricingService $pricing,
        private RentalChainOrchestrator $orchestrator,
    ) {}

    /**
     * @throws \RuntimeException with a Persian message when the range is taken
     */
    public function reserve(
        RentalApplication $application,
        Product $product,
        string $startDate,
        int $days,
        bool $withExtraController = false,
    ): RentalReservation {
        $item = RentalItem::for($product);

        if (! $item || ! $item->isRentable()) {
            throw new \RuntimeException('این محصول قابل اجاره نیست.');
        }

        if ($days < 1) {
            throw new \RuntimeException('مدت اجاره باید حداقل یک روز باشد.');
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = $start->copy()->addDays($days - 1);

        $quote = $this->pricing->quote(
            dailyRate: $item->dailyRate(),
            days: $days,
            extraControllerDaily: $item->extraControllerDaily(),
            withExtraController: $withExtraController,
            gameFee: 0,
            deliveryFee: $item->deliveryFee(),
            deposit: $item->deposit(),
        );

        return DB::transaction(function () use ($application, $product, $start, $end, $days, $quote, $item) {
            // Lock the product row first so two requests racing for the same
            // device serialise here rather than both passing the overlap check.
            Product::where('id', $product->id)->lockForUpdate()->first();

            $conflict = RentalReservation::query()
                ->overlapping($product->id, $start->toDateString(), $end->toDateString())
                ->blocking()
                ->exists();

            if ($conflict) {
                AuditLogger::log(
                    action: 'reservation.conflict',
                    resourceType: 'Product',
                    resourceId: $product->id,
                    result: AuditLogger::RESULT_DENIED,
                    context: ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                );

                throw new \RuntimeException('این دستگاه در بازه انتخابی شما رزرو شده است. لطفاً تاریخ دیگری انتخاب کنید.');
            }

            $reservation = RentalReservation::updateOrCreate(
                ['rental_application_id' => $application->id],
                [
                    'product_id' => $product->id,
                    'product_snapshot' => [
                        'title' => $product->title_fa,
                        'slug' => $product->slug,
                        'daily_rate' => $item->dailyRate(),
                        'deposit' => $item->deposit(),
                        'delivery_fee' => $item->deliveryFee(),
                    ],
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'days' => $days,
                    'daily_rate' => $quote->dailyRate,
                    'subtotal' => $quote->subtotal,
                    'discount' => $quote->discount,
                    'delivery_fee' => $quote->deliveryFee,
                    'rental_total' => $quote->rentalTotal,
                    'deposit_amount' => $quote->deposit,
                    'payable_now' => $quote->payableNow,
                    'quote' => $quote->toArray(),
                    'state' => ReservationState::Held,

                    // TODO(business) B10: how long a hold survives before it
                    // expires is undecided. With hold_minutes null the hold
                    // never auto-expires and the expiry sweep no-ops -- a
                    // customer's reservation is never dropped on a guessed
                    // timeout.
                    'held_until' => config('rental.reservation.hold_minutes')
                        ? now()->addMinutes((int) config('rental.reservation.hold_minutes'))
                        : null,
                ],
            );

            AuditLogger::log(
                action: 'reservation.held',
                resourceType: 'RentalReservation',
                resourceId: $reservation->id,
                context: [
                    'product_id' => $product->id,
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'days' => $days,
                    'payable_now' => $quote->payableNow,
                    'deposit' => $quote->deposit,
                ],
            );

            $this->orchestrator->advance($application, 'reservation held');

            return $reservation->refresh();
        });
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

    /**
     * Interval availability for one product.
     *
     * TODO(domain): Product::isInStock() is still the scalar check inherited
     * from the Store. Rewriting its body to call this is the planned move
     * (see the Product docblock), but it needs the owner to decide whether one
     * catalog row is one physical device or a pool of them -- with a pool, an
     * overlap is not automatically a conflict. Until that is decided, this
     * method is the interval answer and isInStock() stays scalar; there is
     * deliberately no third availability concept.
     */
    public function isFree(Product $product, string $startDate, string $endDate, ?int $ignoreReservationId = null): bool
    {
        return ! RentalReservation::query()
            ->overlapping($product->id, $startDate, $endDate)
            ->blocking()
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->exists();
    }
}
