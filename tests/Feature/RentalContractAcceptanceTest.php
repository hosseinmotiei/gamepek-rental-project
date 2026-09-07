<?php

namespace Tests\Feature;

use App\Enums\ContractState;
use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Services\Contract\ContractService;
use App\Services\Guarantee\GuaranteeService;
use App\Services\Rental\RentalChainOrchestrator;
use App\Services\Rental\RentalReservationService;
use Database\Seeders\ContractTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Step 8: ContractGenerated -> ContractAccepted.
 *
 * Acceptance is an explicit act by the customer, and it changes exactly one
 * thing: the contract's own state and its acceptance evidence. It never
 * re-renders, re-hashes or re-templates the agreement being accepted.
 */
class RentalContractAcceptanceTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

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
            'full_name' => 'مستأجر پذیرش',
            'mobile' => '09121114001',
            'status' => 'active',
        ]);
    }

    // -- 1. the rung -------------------------------------------------------

    public function test_a_generated_contract_can_be_accepted(): void
    {
        $application = $this->contractGeneratedApplication($this->user);
        $this->assertSame(RentalApplicationState::ContractGenerated, $application->state);

        $contract = app(ContractService::class)
            ->accept($application->contract, $this->user, '127.0.0.1', 'phpunit');

        $this->assertSame(ContractState::Accepted, $contract->state);
        $this->assertNotNull($contract->accepted_at);
        $this->assertSame($this->user->id, $contract->accepted_by_user_id);
        $this->assertSame('127.0.0.1', $contract->accepted_ip);
    }

    public function test_acceptance_derives_contract_accepted_through_the_orchestrator(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        app(ContractService::class)->accept($application->contract, $this->user, '127.0.0.1', 'phpunit');

        // The service moved the contract and nothing else.
        $this->assertSame(RentalApplicationState::ContractGenerated, $application->refresh()->state);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract accepted');

        $this->assertSame(RentalApplicationState::ContractAccepted, $application->refresh()->state);
    }

    public function test_the_owner_can_accept_through_the_route(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        $this->actingAs($this->user)
            ->post(route('rental.applications.contract.accept', $application))
            ->assertRedirect();

        $this->assertSame(ContractState::Accepted, $application->contract->refresh()->state);
        $this->assertSame(RentalApplicationState::ContractAccepted, $application->refresh()->state);
    }

    public function test_viewing_the_contract_does_not_accept_it(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);

        $this->actingAs($this->user)
            ->get(route('rental.applications.contract', $application))
            ->assertOk();

        $this->assertSame(ContractState::Generated, Contract::firstOrFail()->state);
        $this->assertSame(RentalApplicationState::ContractGenerated, $application->refresh()->state);
        $this->assertSame(0, AuditEvent::forAction('contract.accepted')->count());
    }

    // -- 2. earlier rungs are refused --------------------------------------

    public function test_a_draft_application_cannot_accept(): void
    {
        $application = app(RentalReservationService::class)
            ->openApplication($this->user);

        $this->assertAcceptanceRefused($application);
    }

    public function test_a_paid_application_cannot_accept(): void
    {
        $this->assertAcceptanceRefused($this->paidApplication($this->user));
    }

    public function test_a_guarantee_pending_application_cannot_accept(): void
    {
        $this->assertAcceptanceRefused($this->guaranteePendingApplication($this->user));
    }

    public function test_a_guarantee_verified_application_cannot_accept(): void
    {
        $this->assertAcceptanceRefused($this->guaranteeVerifiedApplication($this->user));
    }

    public function test_a_guarantee_verified_application_cannot_accept_before_generation(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        // Generated but not yet derived onto the application: the customer has
        // not been shown anything, so there is nothing to accept.
        $this->assertSame(RentalApplicationState::GuaranteeVerified, $application->refresh()->state);

        $this->expectException(\RuntimeException::class);

        app(ContractService::class)->accept($contract, $this->user, '127.0.0.1', 'phpunit');
    }

    // -- 3. integrity: the snapshot is never touched -----------------------

    public function test_acceptance_does_not_regenerate_the_snapshot(): void
    {
        $application = $this->contractGeneratedApplication($this->user);
        $contract = $application->contract;

        $before = [
            'html' => $contract->rendered_html,
            'hash' => $contract->content_hash,
            'variables' => $contract->variables,
            'key' => $contract->template_key,
            'version' => $contract->template_version,
            'number' => $contract->number,
            'template_id' => $contract->contract_template_id,
        ];

        app(ContractService::class)->accept($contract, $this->user, '127.0.0.1', 'phpunit');

        $after = $contract->refresh();

        $this->assertSame($before['html'], $after->rendered_html);
        $this->assertSame($before['hash'], $after->content_hash);
        $this->assertSame($before['variables'], $after->variables);
        $this->assertSame($before['key'], $after->template_key);
        $this->assertSame($before['version'], $after->template_version);
        $this->assertSame($before['number'], $after->number);
        $this->assertSame($before['template_id'], $after->contract_template_id);
        $this->assertTrue($after->isIntact());

        // Exactly one generation happened.
        $this->assertSame(1, AuditEvent::forAction('contract.generated')->count());
    }

    public function test_a_newer_template_version_does_not_alter_an_accepted_contract(): void
    {
        $application = $this->contractGeneratedApplication($this->user);
        $contract = app(ContractService::class)
            ->accept($application->contract, $this->user, '127.0.0.1', 'phpunit');

        $before = [$contract->rendered_html, $contract->content_hash, $contract->template_version];

        $key = config('rental.contract.template_key', 'rental_agreement');

        ContractTemplate::create([
            'key' => $key,
            'version' => ContractTemplate::nextVersionFor($key),
            'title' => 'نسخه سوم آزمایشی',
            'body' => '<p>متن جایگزین {{application_number}}</p>',
            'variables' => ['application_number'],
            'is_active' => true,
            'published_at' => now(),
        ]);

        $contract->refresh();

        $this->assertSame($before[0], $contract->rendered_html);
        $this->assertSame($before[1], $contract->content_hash);
        $this->assertSame($before[2], $contract->template_version);
        $this->assertTrue($contract->isIntact());
    }

    public function test_a_tampered_contract_cannot_be_accepted(): void
    {
        $application = $this->contractGeneratedApplication($this->user);
        $contract = $application->contract;

        Contract::where('id', $contract->id)->update([
            'rendered_html' => $contract->rendered_html.'<p>یک بند اضافه‌شده</p>',
        ]);

        try {
            app(ContractService::class)->accept($contract->refresh(), $this->user, '127.0.0.1', 'phpunit');
            $this->fail('A tampered contract must not be acceptable.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(ContractState::Generated, $contract->refresh()->state);
        $this->assertGreaterThan(0, AuditEvent::forAction('contract.tamper_detected')->count());
        $this->assertSame(0, AuditEvent::forAction('contract.accepted')->count());
    }

    public function test_a_missing_contract_fails_closed(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);

        $this->actingAs($this->user)
            ->postJson(route('rental.applications.contract.accept', $application))
            ->assertStatus(422);

        $this->assertSame(0, Contract::count());
        $this->assertSame(
            RentalApplicationState::GuaranteeVerified,
            $application->refresh()->state,
        );
    }

    // -- 4. idempotency / concurrency --------------------------------------

    public function test_repeated_acceptance_is_idempotent(): void
    {
        $application = $this->contractGeneratedApplication($this->user);
        $contracts = app(ContractService::class);

        $first = $contracts->accept($application->contract, $this->user, '127.0.0.1', 'phpunit');
        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract accepted');

        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->count();

        // Double submit, then a refresh, then a retry after a timeout.
        $second = $contracts->accept($application->refresh()->load('contract')->contract, $this->user, '10.0.0.1', 'retry');
        $third = $contracts->accept($application->refresh()->load('contract')->contract, $this->user, '10.0.0.2', 'retry');

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        app(RentalChainOrchestrator::class)->advance($application->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        // The first acceptance's evidence stands; a retry does not overwrite it.
        $this->assertSame('127.0.0.1', $third->accepted_ip);
        $this->assertEquals($first->accepted_at, $third->accepted_at);

        $this->assertSame(1, Contract::count());
        $this->assertSame(1, AuditEvent::forAction('contract.accepted')->count());
        $this->assertSame(
            $transitions,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
        $this->assertSame(RentalApplicationState::ContractAccepted, $application->refresh()->state);
    }

    public function test_repeated_route_posts_do_not_duplicate_anything(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        $this->actingAs($this->user);

        $this->post(route('rental.applications.contract.accept', $application))->assertRedirect();
        $this->post(route('rental.applications.contract.accept', $application))->assertRedirect();
        $this->post(route('rental.applications.contract.accept', $application))->assertRedirect();

        $this->assertSame(1, Contract::count());
        $this->assertSame(1, AuditEvent::forAction('contract.accepted')->count());
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::ContractAccepted->value)
                ->count(),
        );
    }

    public function test_repeated_advance_writes_no_further_transition(): void
    {
        $application = $this->contractGeneratedApplication($this->user);
        app(ContractService::class)->accept($application->contract, $this->user, '127.0.0.1', 'phpunit');

        $orchestrator = app(RentalChainOrchestrator::class);
        $orchestrator->advance($application->refresh(), 'contract accepted');

        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->count();
        $audits = AuditEvent::forAction('rental_application.transition')->count();

        $orchestrator->advance($application->refresh());
        $orchestrator->advance($application->refresh());

        $this->assertSame(
            $transitions,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
        $this->assertSame($audits, AuditEvent::forAction('rental_application.transition')->count());
    }

    // -- 5. authorization --------------------------------------------------

    public function test_another_user_cannot_accept_a_contract(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        $intruder = $this->makeUser('09121114201');

        $this->actingAs($intruder)
            ->post(route('rental.applications.contract.accept', $application))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('rental.applications.contract', $application))
            ->assertForbidden();

        // Nothing moved.
        $this->assertSame(ContractState::Generated, $application->contract->refresh()->state);
        $this->assertNull($application->contract->refresh()->accepted_at);
        $this->assertSame(RentalApplicationState::ContractGenerated, $application->refresh()->state);
        $this->assertSame(0, AuditEvent::forAction('contract.accepted')->count());
    }

    public function test_a_guest_cannot_accept_a_contract(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        $this->post(route('rental.applications.contract.accept', $application))
            ->assertRedirect();

        $this->assertSame(ContractState::Generated, $application->contract->refresh()->state);
    }

    // -- 6. audit ----------------------------------------------------------

    public function test_the_acceptance_audit_carries_identifiers_and_no_content(): void
    {
        $application = $this->contractGeneratedApplication($this->user);

        $this->actingAs($this->user)
            ->post(route('rental.applications.contract.accept', $application))
            ->assertRedirect();

        $contract = $application->contract->refresh();
        $event = AuditEvent::forAction('contract.accepted')->firstOrFail();

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($this->user->id, $event->actor_id);
        $this->assertSame('Contract', $event->resource_type);
        $this->assertSame($contract->id, (int) $event->resource_id);
        $this->assertSame('success', $event->result);
        $this->assertNotNull($event->correlation_id);
        $this->assertNotNull($event->request_id);

        $this->assertSame($application->id, $event->context['rental_application_id']);
        $this->assertSame($contract->number, $event->context['number']);
        $this->assertSame($contract->template_key, $event->context['template_key']);
        $this->assertSame($contract->template_version, $event->context['template_version']);
        $this->assertSame($contract->content_hash, $event->context['content_hash']);

        // The agreement's text never enters the audit trail.
        $context = json_encode($event->context, JSON_UNESCAPED_UNICODE);
        $this->assertArrayNotHasKey('rendered_html', $event->context);
        $this->assertStringNotContainsString('<section', (string) $context);
        $this->assertStringNotContainsString(self::NATIONAL_CODE, (string) $context);
        $this->assertStringNotContainsString($this->user->mobile, (string) $context);
    }

    // -- 7. structural -----------------------------------------------------

    public function test_no_controller_writes_the_application_state(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/RentalApplicationController.php'))
            .file_get_contents(app_path('Http/Controllers/Admin/RentalApplicationController.php'))
            .file_get_contents(app_path('Services/Contract/ContractService.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/\'state\'\s*=>\s*RentalApplicationState::/',
            (string) $source,
        );
    }

    // -- helpers -----------------------------------------------------------

    /**
     * Plants a Generated contract row directly, bypassing the Step 7 gate, and
     * proves the acceptance gate still refuses and moves nothing.
     */
    private function assertAcceptanceRefused(RentalApplication $application): void
    {
        $before = $application->state;
        $contract = $this->plantContract($application);

        try {
            app(ContractService::class)->accept($contract, $this->user, '127.0.0.1', 'phpunit');
            $this->fail('An application at '.$before->value.' must not accept a contract.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(ContractState::Generated, $contract->refresh()->state);
        $this->assertNull($contract->accepted_at);
        $this->assertNull($contract->accepted_by_user_id);

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        $this->assertNotSame(RentalApplicationState::ContractAccepted, $application->refresh()->state);

        $this->assertSame(1, AuditEvent::forAction('contract.acceptance_denied')->count());
        $this->assertSame(0, AuditEvent::forAction('contract.accepted')->count());
    }

    private function makeUser(string $mobile): User
    {
        return User::create([
            'full_name' => 'کاربر آزمایشی '.$mobile,
            'mobile' => $mobile,
            'status' => 'active',
        ]);
    }

    /** Paid + guarantee submitted, but the inquiries never run. */
    private function guaranteePendingApplication(User $user): RentalApplication
    {
        $application = $this->paidApplication($user);

        app(GuaranteeService::class)
            ->submit($application, ['sayad_id' => '1234567890123456']);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'guarantee submitted');

        return $application->refresh();
    }

    /** A Generated contract row planted directly, bypassing the Step 7 gate. */
    private function plantContract(RentalApplication $application): Contract
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
            'state' => ContractState::Generated,
            'generated_at' => now(),
        ]);

        $contract->setRelation('application', $application);

        return $contract;
    }
}
