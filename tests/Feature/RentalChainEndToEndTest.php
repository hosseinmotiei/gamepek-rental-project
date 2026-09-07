<?php

namespace Tests\Feature;

use App\Enums\BankAccountState;
use App\Enums\ContractState;
use App\Enums\GuaranteeState;
use App\Enums\IdentityState;
use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\ContractTemplate;
use App\Models\Guarantee;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Models\VerificationMedia;
use App\Services\Banking\BankAccountService;
use App\Services\Contract\ContractService;
use App\Services\Guarantee\GuaranteeService;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Media\VerificationMediaService;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\PaymentService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\ContractTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The whole chain, end to end, against the fake providers and the mock
 * gateway:
 *
 *   new user -> KYC -> Shahkar -> civil registry -> identity verified
 *   -> card ownership -> IBAN ownership -> liveness video (private disk)
 *   -> reservation -> payment (+ replay) -> guarantee (7 inquiries)
 *   -> contract -> acceptance -> signature -> final approval
 *
 * and asserts that every state transition left an audit trail.
 */
class RentalChainEndToEndTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('verification');
        Storage::fake('public');

        $this->seed(ContractTemplateSeeder::class);

        // The chain cannot verify an identity without an owner-approved policy
        // (TODO(business) B1). The test supplies one so the automated path is
        // exercised; production ships with `required_checks` empty, which is
        // asserted separately in test_identity_never_auto_verifies_without_a_policy.
        config()->set('verification.identity.required_checks', ['shahkar', 'civil_registry']);
        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->user = User::create([
            'full_name' => 'مستأجر آزمایشی',
            'mobile' => '09121112233',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'full_name' => 'مدیر آزمایشی',
            'mobile' => '09121112299',
            'status' => 'active',
        ]);
    }

    public function test_the_whole_chain_runs_and_every_transition_is_audited(): void
    {
        $orchestrator = app(RentalChainOrchestrator::class);

        // ── 1. Identity (KYC level 2) ────────────────────────────────────
        $identityService = app(IdentityVerificationService::class);

        $identity = $identityService->submit($this->user, self::NATIONAL_CODE, '1995-03-21');
        $this->assertSame(IdentityState::Submitted, $identity->state);
        $this->assertNotSame(self::NATIONAL_CODE, $identity->national_code_mask);

        $identity->loadMissing('user');
        $identityService->runShahkar($identity);
        $identityService->runCivilRegistry($identity);

        $identity->refresh();
        $this->assertSame(IdentityState::Verified, $identity->state);
        $this->assertSame(2, $identity->kyc_level);

        // ── 2. Bank ownership ────────────────────────────────────────────
        $bankService = app(BankAccountService::class);

        $card = $bankService->add($this->user, 'card', '6037997599999993');
        $card->load('user.identity');
        $card = $bankService->verify($card);
        $this->assertSame(BankAccountState::Verified, $card->state);

        $iban = $bankService->add($this->user, 'iban', 'IR820540102680020817909002');
        $iban->load('user.identity');
        $iban = $bankService->verify($iban);
        $this->assertSame(BankAccountState::Verified, $iban->state);

        // ── 3. Liveness video lands on the PRIVATE disk ──────────────────
        $mediaService = app(VerificationMediaService::class);

        $media = $mediaService->store(
            $this->user,
            UploadedFile::fake()->create('liveness.mp4', 512, 'video/mp4'),
            'liveness_video',
        );

        $this->assertSame('verification', $media->disk);
        Storage::disk('verification')->assertExists($media->path);
        // The whole point: nothing verification-related on the web-served disk.
        $this->assertEmpty(Storage::disk('public')->allFiles());

        // ── 4. Reservation ───────────────────────────────────────────────
        $reservations = app(RentalReservationService::class);
        $product = $this->makeRentableProduct();

        $application = $reservations->openApplication($this->user);
        $reservation = $reservations->reserve($application, $product, now()->addDay()->toDateString(), 5);

        $application->refresh();
        $this->assertSame(RentalApplicationState::ReservationHeld, $application->state);

        // Server-computed price, never taken from the client.
        $this->assertSame(500_000, $reservation->daily_rate);
        $this->assertGreaterThan(0, $reservation->payable_now);
        // The deposit is a hold, not a charge.
        $this->assertSame(3_000_000, $reservation->deposit_amount);
        $this->assertNotSame($reservation->payable_now, $reservation->payable_now + $reservation->deposit_amount);

        // ── 5. Payment ───────────────────────────────────────────────────
        $this->actingAs($this->user);

        $payResponse = $this->postJson(route('rental.applications.pay', $application));
        $payResponse->assertOk()->assertJson(['success' => true]);

        $application->refresh();
        $this->assertNotNull($application->order_id);
        $this->assertSame(RentalApplicationState::PaymentPending, $application->state);

        $transaction = $application->order->paymentTransactions()->firstOrFail();

        // What the in-app confirmation page records SERVER-SIDE.
        MockGateway::recordOutcome($transaction->authority, 'paid');

        $callback = app(PaymentService::class)->handleCallback(['Authority' => $transaction->authority], 'mock');
        $this->assertTrue($callback['success']);

        // Replay: still exactly one paid order, one order.paid audit row.
        app(PaymentService::class)->handleCallback(['Authority' => $transaction->authority], 'mock');

        $this->assertSame('paid', $application->order->fresh()->payment_status);
        $this->assertSame($callback['tracking_code'], $application->order->fresh()->payment_tracking_code);
        $this->assertSame(1, AuditEvent::forAction('order.paid')->count());

        $orchestrator->advance($application->refresh(), 'payment settled');
        $this->assertSame(RentalApplicationState::Paid, $application->refresh()->state);

        // ── 6. Guarantee: all seven CHEQUE inquiries ─────────────────────
        $guarantees = app(GuaranteeService::class);

        $guarantee = $guarantees->submit($application, [
            'type' => Guarantee::TYPE_CHEQUE,
            'sayad_id' => '1234567890123456',
            'amount' => 5_000_000,
        ]);
        $guarantee->setRelation('application', $application->load('user.identity'));
        $guarantees->runInquiries($guarantee);

        $this->assertSame(7, GuaranteeInquiry::where('guarantee_id', $guarantee->id)->count());
        $this->assertSame(GuaranteeState::Verified, $guarantee->refresh()->state);

        $orchestrator->advance($application->refresh(), 'guarantee verified');
        $this->assertSame(RentalApplicationState::GuaranteeVerified, $application->refresh()->state);

        // ── 7. Contract: generate, accept, sign ──────────────────────────
        $contracts = app(ContractService::class);

        $contract = $contracts->generate($application);
        $this->assertSame(ContractState::Generated, $contract->state);
        $this->assertSame(1, $contract->template_version);
        $this->assertTrue($contract->isIntact());
        // No raw national code in a document people forward around.
        $this->assertStringNotContainsString(self::NATIONAL_CODE, $contract->rendered_html);

        $orchestrator->advance($application->refresh(), 'contract generated');
        $this->assertSame(RentalApplicationState::ContractGenerated, $application->refresh()->state);

        $contracts->accept($contract, $this->user, '127.0.0.1', 'phpunit');
        $orchestrator->advance($application->refresh(), 'contract accepted');
        $this->assertSame(RentalApplicationState::ContractAccepted, $application->refresh()->state);

        $signature = $contracts->sign($contract->refresh(), $this->user, [
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'otp_reference' => 'mobile:'.$this->user->mobile,
        ]);

        $this->assertTrue($contracts->verifySignature($signature));
        $this->assertSame(ContractState::Signed, $contract->refresh()->state);

        $orchestrator->advance($application->refresh(), 'contract signed');
        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);

        // ── 8. Final approval: an explicit admin act, never derived ──────
        $orchestrator->approve($application->refresh(), $this->admin, 'همه بررسی‌ها انجام شد');
        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);

        // ── 9. Every transition is auditable ─────────────────────────────
        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->get();

        $this->assertGreaterThanOrEqual(7, $transitions->count());

        foreach ($transitions as $transition) {
            $this->assertNotNull($transition->correlation_id);
            $this->assertNotSame($transition->from_state, $transition->to_state);
        }

        $this->assertSame(
            $transitions->count(),
            AuditEvent::forAction('rental_application.transition')->count(),
            'every state transition must have a matching audit_events row',
        );

        foreach ([
            'identity.submitted', 'identity.verified',
            'bank_account.ownership_verified',
            'media.uploaded',
            'reservation.held',
            'payment.request', 'payment.verified', 'order.paid',
            'guarantee.submitted', 'guarantee.verified',
            'contract.generated', 'contract.accepted', 'contract.signed',
        ] as $action) {
            $this->assertGreaterThan(
                0,
                AuditEvent::forAction($action)->count(),
                "missing audit trail for: {$action}",
            );
        }

        // Every audit row carries the five required fields.
        foreach (AuditEvent::all() as $event) {
            $this->assertNotEmpty($event->actor_type);
            $this->assertNotEmpty($event->action);
            $this->assertNotEmpty($event->resource_type);
            $this->assertNotEmpty($event->result);
            $this->assertNotNull($event->correlation_id);
            $this->assertNotNull($event->occurred_at);
        }
    }

    public function test_identity_never_auto_verifies_without_a_business_policy(): void
    {
        // The shipped default: no owner has said which checks constitute
        // KYC level 2, so the code must refuse to decide.
        config()->set('verification.identity.required_checks', []);

        $service = app(IdentityVerificationService::class);
        $identity = $service->submit($this->user, self::NATIONAL_CODE, '1995-03-21');
        $identity->loadMissing('user');

        $service->runShahkar($identity);
        $service->runCivilRegistry($identity);

        $this->assertSame(IdentityState::ManualReview, $identity->refresh()->state);
        $this->assertSame(1, $identity->kyc_level);
        $this->assertGreaterThan(0, AuditEvent::forAction('identity.policy_undefined')->count());
    }

    public function test_a_guarantee_never_auto_verifies_without_a_business_policy(): void
    {
        config()->set('verification.guarantee.required_inquiries', []);

        $application = $this->paidApplication($this->user);

        $guarantees = app(GuaranteeService::class);
        $guarantee = $guarantees->submit($application, ['sayad_id' => '1234567890123456']);
        $guarantee->setRelation('application', $application->load('user.identity'));
        $guarantees->runInquiries($guarantee);

        $this->assertSame(GuaranteeState::Inquiring, $guarantee->refresh()->state);
        $this->assertGreaterThan(0, AuditEvent::forAction('guarantee.policy_undefined')->count());
    }

    public function test_advancing_twice_writes_no_second_transition(): void
    {
        $orchestrator = app(RentalChainOrchestrator::class);
        $application = app(RentalReservationService::class)->openApplication($this->user);

        $before = RentalApplicationTransition::where('rental_application_id', $application->id)->count();

        $orchestrator->advance($application);
        $orchestrator->advance($application);
        $orchestrator->advance($application);

        $this->assertSame(
            $before,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
    }

    public function test_a_tampered_contract_fails_signature_verification(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;

        $signature = app(ContractService::class)->sign($contract->refresh(), $this->user, [
            'ip' => '127.0.0.1', 'user_agent' => 'phpunit',
        ]);

        $this->assertTrue(app(ContractService::class)->verifySignature($signature));

        // Someone edits the stored text directly in the database.
        Contract::where('id', $contract->id)->update([
            'rendered_html' => $contract->rendered_html.'<p>یک بند اضافه‌شده</p>',
        ]);

        $this->assertFalse(app(ContractService::class)->verifySignature($signature->refresh()));
    }

    public function test_a_contract_cannot_be_signed_twice(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contracts = app(ContractService::class);

        $contract = $application->contract;

        $first = $contracts->sign($contract->refresh(), $this->user, ['ip' => '127.0.0.1']);
        $second = $contracts->sign($contract->refresh(), $this->user, ['ip' => '127.0.0.1']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContractSignature::where('contract_id', $contract->id)->count());
    }

    public function test_a_published_contract_template_cannot_be_edited(): void
    {
        $template = ContractTemplate::firstOrFail();

        $this->expectException(\RuntimeException::class);

        $template->update(['body' => 'متن جایگزین']);
    }

    public function test_an_overlapping_reservation_is_rejected(): void
    {
        $reservations = app(RentalReservationService::class);
        $product = $this->makeRentableProduct();
        $start = now()->addDays(3)->toDateString();

        $first = $reservations->openApplication($this->user);
        $reservations->reserve($first, $product, $start, 4);

        $other = User::create(['full_name' => 'کاربر دوم', 'mobile' => '09121114455', 'status' => 'active']);
        $second = $reservations->openApplication($other);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('این دستگاه در بازه انتخابی شما رزرو شده است');

        $reservations->reserve($second, $product, now()->addDays(5)->toDateString(), 3);
    }

    public function test_verification_media_is_not_reachable_without_a_valid_signature(): void
    {
        $media = app(VerificationMediaService::class)->store(
            $this->user,
            UploadedFile::fake()->create('card.jpg', 64, 'image/jpeg'),
            'national_card',
        );

        $this->actingAs($this->user);

        // Unsigned.
        $this->get(route('verification.media.show', $media))->assertForbidden();

        // Signed and owned.
        $this->get(app(VerificationMediaService::class)->temporaryUrl($media))->assertOk();

        // Signed but someone else's.
        $url = app(VerificationMediaService::class)->temporaryUrl($media);
        $intruder = User::create(['full_name' => 'مزاحم', 'mobile' => '09121116677', 'status' => 'active']);

        $this->actingAs($intruder);
        $this->get($url)->assertForbidden();
    }

    public function test_purge_skips_media_whose_retention_policy_is_undefined(): void
    {
        $media = app(VerificationMediaService::class)->store(
            $this->user,
            UploadedFile::fake()->create('card.jpg', 64, 'image/jpeg'),
            'national_card',
        );

        // The shipped default is null retention for every kind.
        $this->assertNull($media->retention_until);
        $this->assertSame(0, app(VerificationMediaService::class)->purgeExpired());
        Storage::disk('verification')->assertExists($media->fresh()->path);

        // With a policy set and elapsed, the file goes and the row stays.
        VerificationMedia::where('id', $media->id)->update(['retention_until' => now()->subDay()->toDateString()]);

        $this->assertSame(1, app(VerificationMediaService::class)->purgeExpired());
        $this->assertDatabaseHas('verification_media', ['id' => $media->id, 'state' => 'purged']);
        $this->assertNull($media->fresh()->path);
    }
}
