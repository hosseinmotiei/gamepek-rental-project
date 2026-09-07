<?php

namespace Tests\Feature;

use App\Enums\ContractState;
use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\ContractSignatureOtp;
use App\Models\ContractTemplate;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Services\Contract\ContractService;
use App\Services\Contract\SignatureOtpService;
use App\Services\Otp\OtpProviderInterface;
use App\Services\OtpService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\ContractTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Step 9: ContractAccepted -> ContractSigned, and Step 10's derived
 * AwaitingFinalApproval.
 *
 * The signing challenge is bound to one contract and one signer, so most of
 * these cases are about what a code CANNOT do: sign a different contract, sign
 * twice, sign after expiry, or sign text that has changed since it was shown.
 */
class RentalContractSigningTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private const CODE = '13579';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContractTemplateSeeder::class);

        // TODO(business) B6, supplied so the chain can reach the contract rungs.
        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->user = User::create([
            'full_name' => 'مستأجر امضا',
            'mobile' => '09121115001',
            'status' => 'active',
        ]);

        $this->pinOtpCode(self::CODE);
    }

    // -- 1. requesting the challenge ---------------------------------------

    public function test_an_accepted_contract_can_request_a_signing_otp(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        $code = app(SignatureOtpService::class)->request($application->contract, $this->user, '127.0.0.1');

        $this->assertSame(self::CODE, $code);

        $challenge = ContractSignatureOtp::firstOrFail();
        $this->assertSame($application->contract->id, $challenge->contract_id);
        $this->assertSame($this->user->id, $challenge->user_id);
        $this->assertSame(0, $challenge->attempts);
        $this->assertNull($challenge->consumed_at);
        $this->assertTrue($challenge->expires_at->isFuture());

        $this->assertSame(1, AuditEvent::forAction('contract.signature_otp_requested')->count());
    }

    public function test_a_generated_but_unaccepted_contract_cannot_request_an_otp(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        $this->assertOtpRequestRefused($application);
    }

    public function test_a_draft_application_cannot_request_an_otp(): void
    {
        $application = app(RentalReservationService::class)
            ->openApplication($this->user);

        $this->assertOtpRequestRefused($application, plant: true);
    }

    public function test_a_paid_application_cannot_request_an_otp(): void
    {
        $this->assertOtpRequestRefused($this->paidApplication($this->user), plant: true);
    }

    public function test_a_guarantee_verified_application_cannot_request_an_otp(): void
    {
        $this->assertOtpRequestRefused($this->guaranteeVerifiedApplication($this->user), plant: true);
    }

    // -- 2. verifying the challenge ----------------------------------------

    public function test_the_correct_code_signs_the_contract(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        $this->actingAs($this->user)
            ->post(route('rental.applications.contract.sign.otp', $application))
            ->assertRedirect();

        $this->post(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ContractState::Signed, $application->contract->refresh()->state);
        $this->assertSame(1, ContractSignature::count());
        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
    }

    public function test_a_wrong_code_does_not_sign(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;

        app(SignatureOtpService::class)->request($contract, $this->user);

        $this->assertFalse(app(SignatureOtpService::class)->verify($contract, $this->user, '99999'));

        $this->assertSame(0, ContractSignature::count());
        $this->assertSame(1, ContractSignatureOtp::firstOrFail()->attempts);
        $this->assertNull(ContractSignatureOtp::firstOrFail()->consumed_at);
        $this->assertSame(1, AuditEvent::forAction('contract.signature_otp_denied')->count());
    }

    public function test_an_expired_code_does_not_sign(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;

        app(SignatureOtpService::class)->request($contract, $this->user);

        ContractSignatureOtp::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse(app(SignatureOtpService::class)->verify($contract, $this->user, self::CODE));
        $this->assertSame(0, ContractSignature::count());
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;
        $otp = app(SignatureOtpService::class);

        $otp->request($contract, $this->user);

        $this->assertTrue($otp->verify($contract, $this->user, self::CODE));
        $this->assertNotNull(ContractSignatureOtp::firstOrFail()->consumed_at);

        // Same code, second time.
        $this->assertFalse($otp->verify($contract, $this->user, self::CODE));
        $this->assertSame(1, AuditEvent::forAction('contract.signature_otp_verified')->count());
    }

    public function test_attempts_are_capped(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;
        $otp = app(SignatureOtpService::class);

        $otp->request($contract, $this->user);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($otp->verify($contract, $this->user, '00000'));
        }

        $this->assertSame(5, ContractSignatureOtp::firstOrFail()->attempts);

        // The correct code no longer helps once the cap is reached.
        $this->assertFalse($otp->verify($contract, $this->user, self::CODE));
        $this->assertSame(0, ContractSignature::count());
    }

    public function test_a_code_issued_for_another_contract_cannot_sign_this_one(): void
    {
        $mine = $this->contractAcceptedApplication($this->user);

        $other = User::create([
            'full_name' => 'مستأجر دوم',
            'mobile' => '09121115002',
            'status' => 'active',
        ]);
        // A second, independent chain for a different customer.
        $theirs = $this->contractAcceptedApplication($other, '9876543210987654', '1234567891');

        $otp = app(SignatureOtpService::class);
        $otp->request($theirs->contract, $other);

        // The code exists and is live -- but not for this contract/signer pair.
        $this->assertFalse($otp->verify($mine->contract, $this->user, self::CODE));
        $this->assertSame(0, ContractSignature::count());
        $this->assertSame(ContractState::Accepted, $mine->contract->refresh()->state);
    }

    public function test_a_login_otp_cannot_sign_a_contract(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        // A login code lives in otp_codes and is keyed only by mobile.
        app(OtpService::class)->generateAndSend((string) $this->user->mobile);

        $this->actingAs($this->user)
            ->postJson(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertStatus(422);

        $this->assertSame(0, ContractSignature::count());
        $this->assertSame(ContractState::Accepted, $application->contract->refresh()->state);
    }

    public function test_the_code_is_never_stored_in_plain_text(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        app(SignatureOtpService::class)->request($application->contract, $this->user);

        $row = (array) DB::table('contract_signature_otps')->first();

        $this->assertStringNotContainsString(self::CODE, json_encode($row));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['code_hash']);
    }

    // -- 3. authorization --------------------------------------------------

    public function test_another_user_cannot_request_or_submit_a_signing_otp(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        $intruder = User::create([
            'full_name' => 'کاربر دیگر',
            'mobile' => '09121115003',
            'status' => 'active',
        ]);

        $this->actingAs($intruder);

        $this->post(route('rental.applications.contract.sign.otp', $application))->assertForbidden();
        $this->post(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertForbidden();

        $this->assertSame(0, ContractSignatureOtp::count());
        $this->assertSame(0, ContractSignature::count());
        $this->assertSame(ContractState::Accepted, $application->contract->refresh()->state);
    }

    // -- 4. the snapshot is never touched ----------------------------------

    public function test_a_tampered_contract_cannot_be_signed(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;

        app(SignatureOtpService::class)->request($contract, $this->user);

        Contract::where('id', $contract->id)->update([
            'rendered_html' => $contract->rendered_html.'<p>یک بند اضافه‌شده</p>',
        ]);

        try {
            app(ContractService::class)->sign($contract->refresh(), $this->user, ['ip' => '127.0.0.1']);
            $this->fail('A tampered contract must not be signable.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, ContractSignature::count());
        $this->assertSame(ContractState::Accepted, $contract->refresh()->state);
        $this->assertGreaterThan(0, AuditEvent::forAction('contract.tamper_detected')->count());
    }

    public function test_signing_does_not_regenerate_the_contract(): void
    {
        $application = $this->contractAcceptedApplication($this->user);
        $contract = $application->contract;

        $before = [
            'html' => $contract->rendered_html,
            'hash' => $contract->content_hash,
            'variables' => $contract->variables,
            'key' => $contract->template_key,
            'version' => $contract->template_version,
            'number' => $contract->number,
        ];

        $otp = app(SignatureOtpService::class);
        $otp->request($contract, $this->user);
        $this->assertTrue($otp->verify($contract, $this->user, self::CODE));

        $signature = app(ContractService::class)->sign($contract, $this->user, ['ip' => '127.0.0.1']);
        $after = $contract->refresh();

        $this->assertSame($before['html'], $after->rendered_html);
        $this->assertSame($before['hash'], $after->content_hash);
        $this->assertSame($before['variables'], $after->variables);
        $this->assertSame($before['key'], $after->template_key);
        $this->assertSame($before['version'], $after->template_version);
        $this->assertSame($before['number'], $after->number);
        $this->assertTrue($after->isIntact());

        // The signature binds to that exact text.
        $this->assertSame($before['hash'], $signature->signed_content_hash);
        $this->assertTrue(app(ContractService::class)->verifySignature($signature));
        $this->assertSame(1, AuditEvent::forAction('contract.generated')->count());
    }

    // -- 5. state, idempotency ---------------------------------------------

    public function test_signing_derives_awaiting_final_approval(): void
    {
        $application = $this->signedApplication($this->user);

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->state);
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::AwaitingFinalApproval->value)
                ->count(),
        );
    }

    public function test_repeated_advance_after_signing_writes_no_further_transition(): void
    {
        $application = $this->signedApplication($this->user);
        $orchestrator = app(RentalChainOrchestrator::class);

        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->count();
        $audits = AuditEvent::forAction('rental_application.transition')->count();

        $orchestrator->advance($application->refresh());
        $orchestrator->advance($application->refresh());
        $orchestrator->advance($application->refresh());

        $this->assertSame(
            $transitions,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
        $this->assertSame($audits, AuditEvent::forAction('rental_application.transition')->count());
        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
    }

    public function test_repeated_signing_is_idempotent(): void
    {
        $application = $this->signedApplication($this->user);
        $contract = $application->load('contract')->contract;
        $contracts = app(ContractService::class);

        $first = ContractSignature::firstOrFail();
        $second = $contracts->sign($contract, $this->user, ['ip' => '10.0.0.1']);
        $third = $contracts->sign($contract->refresh(), $this->user, ['ip' => '10.0.0.2']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, ContractSignature::count());
        $this->assertSame(1, AuditEvent::forAction('contract.signed')->count());
    }

    public function test_repeated_route_signing_creates_one_signature(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        $this->actingAs($this->user);
        $this->post(route('rental.applications.contract.sign.otp', $application))->assertRedirect();

        $this->post(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertRedirect()->assertSessionHasNoErrors();

        // A double submit: the code is already consumed, so the second request
        // is refused and nothing is written twice.
        $this->post(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertRedirect();

        $this->assertSame(1, ContractSignature::count());
        $this->assertSame(1, AuditEvent::forAction('contract.signed')->count());
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::AwaitingFinalApproval->value)
                ->count(),
        );
    }

    public function test_verifying_a_signature_does_not_rewrite_when_it_was_signed(): void
    {
        $application = $this->signedApplication($this->user);
        $signature = ContractSignature::firstOrFail();

        $signedAt = $signature->signed_at;

        $this->travelTo(now()->addMinutes(10));
        $this->assertTrue(app(ContractService::class)->verifySignature($signature));
        $this->travelBack();

        $this->assertEquals($signedAt, $signature->refresh()->signed_at);
        $this->assertNotNull($signature->verified_at);
    }

    // -- 6. audit ----------------------------------------------------------

    public function test_no_audit_event_carries_the_code_or_the_contract_text(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        $this->actingAs($this->user);
        $this->post(route('rental.applications.contract.sign.otp', $application))->assertRedirect();
        $this->post(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertRedirect()->assertSessionHasNoErrors();

        $contract = $application->load('contract')->contract;

        $audit = json_encode(AuditEvent::pluck('context'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::CODE, (string) $audit);
        $this->assertStringNotContainsString('<section', (string) $audit);
        $this->assertStringNotContainsString(self::NATIONAL_CODE, (string) $audit);

        $event = AuditEvent::forAction('contract.signed')->firstOrFail();
        $this->assertSame('user', $event->actor_type);
        $this->assertSame($this->user->id, $event->actor_id);
        $this->assertSame('success', $event->result);
        $this->assertNotNull($event->correlation_id);
        $this->assertNotNull($event->request_id);
        $this->assertSame($contract->number, $event->context['number']);
        $this->assertSame($contract->content_hash, $event->context['content_hash']);
        $this->assertSame($contract->template_key, $event->context['template_key']);
        $this->assertTrue($event->context['not_pki']);
        $this->assertArrayNotHasKey('rendered_html', $event->context);
    }

    // -- helpers -----------------------------------------------------------

    /** Pins the delivered code; OTP hashes are unreadable from the database. */
    private function pinOtpCode(string $code): void
    {
        $this->app->bind(OtpProviderInterface::class, fn () => new class($code) implements OtpProviderInterface
        {
            public function __construct(private string $code) {}

            public function send(string $mobile): string
            {
                return json_encode(['code' => $this->code]);
            }
        });
    }

    private function assertOtpRequestRefused(RentalApplication $application, bool $plant = false): void
    {
        $contract = $plant ? $this->plantAcceptedContract($application) : $application->load('contract')->contract;

        try {
            app(SignatureOtpService::class)->request($contract, $this->user);
            $this->fail('An application at '.$application->state->value.' must not get a signing code.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, ContractSignatureOtp::count());
        $this->assertSame(0, ContractSignature::count());
        $this->assertSame(1, AuditEvent::forAction('contract.signature_otp_denied')->count());

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        $this->assertNotSame(RentalApplicationState::ContractSigned, $application->refresh()->state);
        $this->assertNotSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
    }

    /** An Accepted contract row planted directly, bypassing the Step 8 gate. */
    private function plantAcceptedContract(RentalApplication $application): Contract
    {
        $template = ContractTemplate::firstOrFail();
        $html = '<p>'.$application->application_number.'</p>';

        $contract = Contract::create([
            'rental_application_id' => $application->id,
            'contract_template_id' => $template->id,
            'template_key' => $template->key,
            'template_version' => $template->version,
            'number' => Contract::generateNumber(),
            'variables' => ['application_number' => $application->application_number],
            'rendered_html' => $html,
            'content_hash' => hash('sha256', $html),
            'state' => ContractState::Accepted,
            'generated_at' => now(),
            'accepted_at' => now(),
            'accepted_by_user_id' => $application->user_id,
        ]);

        $contract->setRelation('application', $application);

        return $contract;
    }
}
