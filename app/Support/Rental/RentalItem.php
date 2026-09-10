<?php

namespace App\Support\Rental;

use App\Models\Product;

/**
 * Reads the rental facts a Product carries in its `attributes` JSON.
 *
 * The rental domain has no tables of its own yet (see CLAUDE.md — it is
 * deliberately undesigned), so rental facts live under the single `_rental`
 * key of `products.attributes`. That is the extension seam, but it means
 * every consumer would otherwise have to know the shape of a raw array and
 * cope with it being absent on the ~all products that are not rentals.
 *
 * This class is that single point of knowledge. It answers `supports()` for
 * "is this product rentable at all", and exposes the stored values as typed
 * accessors so views and controllers never index into the blob directly.
 *
 * It reads only. When the rental domain gets real columns or a
 * `rental_items` table, this is the one class that has to change.
 */
class RentalItem
{
    /**
     * @param  array<string, mixed>  $data  the product's `_rental` payload
     */
    private function __construct(private readonly array $data) {}

    /**
     * Whether this product carries rental data, i.e. can be rented rather
     * than sold. Products without the `_rental` key are ordinary shop items.
     */
    public static function supports(Product $product): bool
    {
        return is_array($product->attributes['_rental'] ?? null);
    }

    public static function for(Product $product): ?self
    {
        return self::supports($product)
            ? new self($product->attributes['_rental'])
            : null;
    }

    public function dailyRate(): int
    {
        return (int) ($this->data['daily_rate'] ?? 0);
    }

    public function deposit(): int
    {
        return (int) ($this->data['deposit'] ?? 0);
    }

    public function deliveryFee(): int
    {
        return (int) ($this->data['delivery_fee'] ?? 0);
    }

    public function extraControllerAvailable(): bool
    {
        return (bool) ($this->data['extra_controller_available'] ?? false);
    }

    public function extraControllerDaily(): int
    {
        return (int) ($this->data['extra_controller_daily'] ?? 0);
    }

    public function baseControllers(): int
    {
        return (int) ($this->data['base_controllers'] ?? 1);
    }

    /**
     * One of the Availability::KIND_* item statuses: available, pending,
     * reserved or maintenance.
     */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? 'available');
    }

    public function isRentable(): bool
    {
        return $this->status() === 'available';
    }

    public function healthScore(): int
    {
        return (int) ($this->data['health_score'] ?? 0);
    }

    /**
     * Health breakdown as label => value pairs, ready to render.
     *
     * Stored as {technical: {label, value}, ...}; the sub-keys are the
     * prototype's field names and carry no meaning the UI needs, so only the
     * human-readable label and value survive.
     *
     * @return array<string, string>
     */
    public function health(): array
    {
        $out = [];

        foreach ($this->data['health'] ?? [] as $entry) {
            if (isset($entry['label'], $entry['value'])) {
                $out[$entry['label']] = $entry['value'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> label => value
     */
    public function conditionFacts(): array
    {
        $out = [];

        foreach ($this->data['condition_facts'] ?? [] as $fact) {
            if (isset($fact['label'], $fact['value'])) {
                $out[$fact['label']] = $fact['value'];
            }
        }

        return $out;
    }

    /**
     * Package contents, each with `label`, `included` and an optional `note`.
     *
     * @return list<array{label: string, included: bool, note: string|null}>
     */
    public function included(): array
    {
        return array_map(fn (array $item) => [
            'label' => (string) ($item['label'] ?? ''),
            'included' => (bool) ($item['included'] ?? false),
            'note' => isset($item['note']) ? (string) $item['note'] : null,
        ], $this->data['included'] ?? []);
    }

    /**
     * Selectable game titles.
     *
     * @return list<array{id: string, title: string, kind: string, extra_fee: int, in_stock: bool, free: bool}>
     */
    public function games(): array
    {
        return array_map(fn (array $g) => [
            'id' => (string) ($g['id'] ?? ''),
            'title' => (string) ($g['title'] ?? ''),
            'kind' => (string) ($g['kind'] ?? 'installed'),
            'extra_fee' => (int) ($g['extraFee'] ?? $g['extra_fee'] ?? 0),
            'in_stock' => (bool) ($g['inStock'] ?? $g['in_stock'] ?? true),
            'free' => (bool) ($g['freeWithRental'] ?? $g['free_with_rental'] ?? false),
        ], $this->data['games'] ?? []);
    }

    /**
     * Availability windows as domain objects.
     *
     * Malformed ranges are skipped rather than fatal: a bad blackout date in
     * stored JSON should grey out less of the calendar, never break the
     * product page.
     *
     * @return list<BlockedRange>
     */
    /**
     * Blocked ranges declared in the product's `_rental` blob.
     *
     * NOT A SOURCE OF LIVE AVAILABILITY. Confirmed business rule C-18 makes
     * `rental_reservations` the only authority; ask
     * App\Services\Rental\RentalAvailabilityService instead.
     *
     * This blob is seeder/fixture data. While the product page rendered its
     * calendar from here, the page and the search results contradicted each
     * other in both directions -- free dates shown as reserved, and paid dates
     * shown as free. Nothing in the live availability path calls this any more.
     *
     * @return list<BlockedRange>
     */
    public function blocked(): array
    {
        $out = [];

        foreach ($this->data['blocked'] ?? [] as $range) {
            try {
                $out[] = new BlockedRange(
                    (string) $range['from'],
                    (string) $range['to'],
                    (string) ($range['kind'] ?? BlockedRange::KIND_RESERVED),
                );
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function badges(): array
    {
        return array_values(array_map('strval', $this->data['badges'] ?? []));
    }

    public function rating(): float
    {
        return (float) ($this->data['rating'] ?? 0);
    }

    public function rentalCount(): int
    {
        return (int) ($this->data['rental_count'] ?? 0);
    }
}
