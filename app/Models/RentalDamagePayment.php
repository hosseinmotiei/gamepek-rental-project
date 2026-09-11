<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The customer's direct payment of one assessed damage amount. Append-only;
 * written by RentalDamageAssessmentService alone, together with the GamePek
 * wallet credit it points to (wallet_transaction_id).
 */
class RentalDamagePayment extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Damage payments are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Damage payments are append-only.');
    }
}
