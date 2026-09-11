<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One custody event of the physical promissory note. Append-only; written by
 * GuaranteeNoteService alone. Never money.
 */
class GuaranteeNoteEvent extends Model
{
    public const RECEIVED = 'received';

    public const RETURNED_TO_CUSTOMER = 'returned_to_customer';

    public const TRANSFERRED_TO_OWNER = 'transferred_to_owner';

    /** GamePek-owned device, damage unpaid: there is no owner, GamePek keeps it. */
    public const RETAINED_BY_GAMEPEK = 'retained_by_gamepek';

    public const BASIS_NO_DAMAGE = 'no_damage';

    public const BASIS_DAMAGE_PAID = 'damage_paid';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Promissory-note events are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Promissory-note events are append-only.');
    }
}
