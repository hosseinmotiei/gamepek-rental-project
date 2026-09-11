<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The real, persisted Wallet backend: ownership, balance, the immutable
 * ledger, idempotency, and the fail-closed insufficient-balance guard.
 *
 * Deliberately NOT covered here: settlement, owner payout, deposit, refund,
 * or damage-charge behaviour. None of that exists yet and this test file
 * does not invent it -- see WalletService's own docblock.
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی', 'mobile' => '09121110001', 'status' => 'active',
        ]);

        $this->other = User::create([
            'full_name' => 'کاربر دیگر', 'mobile' => '09121110002', 'status' => 'active',
        ]);
    }

    // ── A. Creation, credit, debit ─────────────────────────────────────────

    public function test_a_wallet_is_created_on_first_use_with_zero_balance(): void
    {
        $wallet = app(WalletService::class)->walletFor($this->customer);

        $this->assertSame($this->customer->id, $wallet->user_id);
        $this->assertSame(0, $wallet->balance);
        $this->assertSame(1, Wallet::where('user_id', $this->customer->id)->count());
    }

    public function test_calling_wallet_for_twice_returns_the_same_wallet(): void
    {
        $service = app(WalletService::class);

        $first = $service->walletFor($this->customer);
        $second = $service->walletFor($this->customer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Wallet::where('user_id', $this->customer->id)->count());
    }

    public function test_credit_increases_the_balance_and_writes_a_ledger_entry(): void
    {
        $entry = app(WalletService::class)->credit($this->customer, 50_000, 'test credit');

        $this->assertSame(50_000, app(WalletService::class)->balance($this->customer));
        $this->assertTrue($entry->isCredit());
        $this->assertSame(50_000, $entry->amount);
        $this->assertSame(50_000, $entry->balance_after);
        $this->assertNotEmpty($entry->reference_number);
        $this->assertStringStartsWith('WAL-', $entry->reference_number);
    }

    public function test_debit_decreases_the_balance_and_writes_a_ledger_entry(): void
    {
        $service = app(WalletService::class);
        $service->credit($this->customer, 100_000, 'seed');

        $entry = $service->debit($this->customer, 30_000, 'test debit');

        $this->assertSame(70_000, $service->balance($this->customer));
        $this->assertFalse($entry->isCredit());
        $this->assertSame(30_000, $entry->amount);
        $this->assertSame(70_000, $entry->balance_after);
    }

    public function test_monetary_amounts_are_stored_as_exact_integers(): void
    {
        $service = app(WalletService::class);
        $entry = $service->credit($this->customer, 1_234_567, 'precision check');

        $this->assertSame(1_234_567, $entry->amount);
        $this->assertSame(1_234_567, $entry->fresh()->amount);
        $this->assertIsInt($entry->fresh()->amount);
        $this->assertSame(1_234_567, $service->balance($this->customer));
    }

    // ── B. Insufficient balance / rollback safety ──────────────────────────

    public function test_debit_refuses_when_the_balance_is_insufficient(): void
    {
        $service = app(WalletService::class);
        $service->credit($this->customer, 10_000, 'seed');

        try {
            $service->debit($this->customer, 20_000, 'too much');
            $this->fail('An insufficient-balance debit must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('موجودی کیف پول کافی نیست.', $e->getMessage());
        }

        // Nothing moved: the balance is untouched and no debit row exists.
        $this->assertSame(10_000, $service->balance($this->customer));
        $this->assertSame(
            1,
            WalletTransaction::where('wallet_id', $service->walletFor($this->customer)->id)->count(),
        );
    }

    public function test_a_zero_or_negative_amount_is_refused_for_both_directions(): void
    {
        $service = app(WalletService::class);

        foreach ([0, -1] as $amount) {
            try {
                $service->credit($this->customer, $amount, 'invalid');
                $this->fail('A non-positive credit amount must be refused.');
            } catch (\InvalidArgumentException) {
                // expected
            }

            try {
                $service->debit($this->customer, $amount, 'invalid');
                $this->fail('A non-positive debit amount must be refused.');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, $service->balance($this->customer));
        $this->assertSame(0, WalletTransaction::count());
    }

    // ── C. Idempotency ──────────────────────────────────────────────────────

    public function test_a_repeated_credit_with_the_same_idempotency_key_is_applied_once(): void
    {
        $service = app(WalletService::class);

        $first = $service->credit($this->customer, 25_000, 'reason', 'idem-credit-1');
        $second = $service->credit($this->customer, 25_000, 'reason', 'idem-credit-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(25_000, $service->balance($this->customer));
        $this->assertSame(1, WalletTransaction::where('idempotency_key', 'idem-credit-1')->count());
    }

    /**
     * Regression: the denial audit used to be written inside the debit
     * transaction and rolled back by the throw that followed it, so no
     * insufficient-balance refusal ever reached the audit trail.
     */
    public function test_an_insufficient_balance_denial_is_audited_durably(): void
    {
        $service = app(WalletService::class);
        $service->credit($this->customer, 10_000, 'seed');

        try {
            $service->debit($this->customer, 20_000, 'too much');
            $this->fail('An insufficient-balance debit must throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $event = AuditEvent::forAction('wallet.debit_denied')->firstOrFail();
        $this->assertSame('denied', $event->result);
        $this->assertSame(20_000, $event->context['amount']);
        $this->assertNotNull($event->correlation_id);
    }

    public function test_replaying_a_settled_debit_is_not_a_denial_even_after_the_balance_dropped(): void
    {
        $service = app(WalletService::class);
        $service->credit($this->customer, 30_000, 'seed');

        $first = $service->debit($this->customer, 20_000, 'reason', 'idem-debit-replay');
        // Balance is now 10_000 < 20_000, yet the replay must return the
        // original entry rather than refuse and audit a denial.
        $replay = $service->debit($this->customer, 20_000, 'reason', 'idem-debit-replay');

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(10_000, $service->balance($this->customer));
        $this->assertFalse(AuditEvent::forAction('wallet.debit_denied')->exists());
    }

    public function test_a_repeated_debit_with_the_same_idempotency_key_is_applied_once(): void
    {
        $service = app(WalletService::class);
        $service->credit($this->customer, 100_000, 'seed');

        $first = $service->debit($this->customer, 10_000, 'reason', 'idem-debit-1');
        $second = $service->debit($this->customer, 10_000, 'reason', 'idem-debit-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(90_000, $service->balance($this->customer));
        $this->assertSame(1, WalletTransaction::where('idempotency_key', 'idem-debit-1')->count());
    }

    public function test_the_database_refuses_a_duplicate_idempotency_key_per_wallet_even_bypassing_the_service(): void
    {
        $wallet = app(WalletService::class)->walletFor($this->customer);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'reference_number' => WalletTransaction::generateReference(),
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 1000,
            'balance_after' => 1000,
            'reason' => 'first',
            'idempotency_key' => 'dup-key',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'reference_number' => WalletTransaction::generateReference(),
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 1000,
            'balance_after' => 2000,
            'reason' => 'second',
            'idempotency_key' => 'dup-key',
            'created_at' => now(),
        ]);
    }

    public function test_different_wallets_may_reuse_the_same_idempotency_key(): void
    {
        $service = app(WalletService::class);

        $mine = $service->credit($this->customer, 1000, 'reason', 'shared-key');
        $theirs = $service->credit($this->other, 1000, 'reason', 'shared-key');

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame(1000, $service->balance($this->customer));
        $this->assertSame(1000, $service->balance($this->other));
    }

    // ── D. Immutable ledger ─────────────────────────────────────────────────

    public function test_a_ledger_entry_cannot_be_updated(): void
    {
        $entry = app(WalletService::class)->credit($this->customer, 5000, 'reason');

        $this->expectException(\LogicException::class);
        $entry->update(['reason' => 'tampered']);
    }

    public function test_a_ledger_entry_cannot_be_saved_after_being_loaded_and_mutated(): void
    {
        $entry = app(WalletService::class)->credit($this->customer, 5000, 'reason');
        $reloaded = WalletTransaction::findOrFail($entry->id);
        $reloaded->reason = 'tampered';

        $this->expectException(\LogicException::class);
        $reloaded->save();
    }

    public function test_a_ledger_entry_cannot_be_deleted(): void
    {
        $entry = app(WalletService::class)->credit($this->customer, 5000, 'reason');

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_the_database_refuses_a_non_positive_ledger_amount(): void
    {
        $wallet = app(WalletService::class)->walletFor($this->customer);

        $this->expectException(QueryException::class);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'reference_number' => WalletTransaction::generateReference(),
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 0,
            'balance_after' => 0,
            'reason' => 'invalid',
            'created_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unknown_ledger_type(): void
    {
        $wallet = app(WalletService::class)->walletFor($this->customer);

        $this->expectException(QueryException::class);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'reference_number' => WalletTransaction::generateReference(),
            'type' => 'refund',
            'amount' => 1000,
            'balance_after' => 1000,
            'reason' => 'invalid',
            'created_at' => now(),
        ]);
    }

    // ── E. Mass-assignment / model safety ───────────────────────────────────

    public function test_the_balance_column_is_not_mass_assignable(): void
    {
        // Model::shouldBeStrict() (outside production) turns a mass-assignment
        // attempt against a non-fillable attribute into a real exception
        // rather than a silent no-op -- exactly what should happen for the
        // one column WalletService alone may write.
        $this->expectException(MassAssignmentException::class);

        (new Wallet)->fill(['balance' => 999_999]);
    }

    // ── F. Isolation between wallets ────────────────────────────────────────

    public function test_two_customers_have_fully_isolated_wallets(): void
    {
        $service = app(WalletService::class);
        $service->credit($this->customer, 40_000, 'reason');
        $service->credit($this->other, 15_000, 'reason');

        $this->assertSame(40_000, $service->balance($this->customer));
        $this->assertSame(15_000, $service->balance($this->other));
        $this->assertNotSame(
            $service->walletFor($this->customer)->id,
            $service->walletFor($this->other)->id,
        );
    }

    // ── G. Admin screen authorization ───────────────────────────────────────

    public function test_a_guest_cannot_view_the_admin_wallet_screens(): void
    {
        $wallet = app(WalletService::class)->walletFor($this->customer);

        $this->get(route('admin.wallet.index'))->assertRedirect();
        $this->get(route('admin.wallet.show', $wallet))->assertRedirect();
    }

    public function test_an_admin_without_the_view_payments_permission_cannot_view_the_wallet_screens(): void
    {
        $this->seed(UserSeeder::class);

        $wallet = app(WalletService::class)->walletFor($this->customer);

        // product_manager has no view_payments permission.
        $staff = User::create([
            'full_name' => 'کارشناس محصول', 'mobile' => '09121110003', 'email' => 'staff.wallet@test.local', 'status' => 'active',
        ]);
        $staff->syncRoles(['product_manager']);

        $this->actingAs($staff)->get(route('admin.wallet.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.wallet.show', $wallet))->assertForbidden();
    }

    public function test_an_admin_with_permission_sees_real_wallet_data(): void
    {
        $this->seed(UserSeeder::class);

        $admin = User::create([
            'full_name' => 'مدیر آزمایشی', 'mobile' => '09121110004', 'email' => 'admin.wallet@test.local', 'status' => 'active',
        ]);
        $admin->syncRoles(['super_admin']);

        $service = app(WalletService::class);
        $wallet = $service->credit($this->customer, 20_000, 'reason')->wallet;

        $this->actingAs($admin)
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee($this->customer->full_name);

        $this->actingAs($admin)
            ->get(route('admin.wallet.show', $wallet))
            ->assertOk()
            ->assertSee($this->customer->full_name);
    }
}
