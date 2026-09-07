<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\RentalChainOrchestrator;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The post-approval lifecycle: Approved -> Active -> Returned -> Closed.
 *
 * NONE of those three transitions has a defined trigger (TODO(business) B14),
 * so none of them can happen. These tests exist to prove exactly that, and to
 * protect the one rule that IS settled: an approved rental stays approved.
 *
 * Where a test sets a trigger in config it is testing the MECHANISM, and says
 * so -- it is not a business rule, and the shipped configuration is null.
 */
class RentalPostApprovalLifecycleTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private const CODE = '13579';

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContractTemplateSeeder::class);
        $this->seed(UserSeeder::class);

        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->app->bind(OtpProviderInterface::class, fn () => new class implements OtpProviderInterface
        {
            public function send(string $mobile): string
            {
                return json_encode(['code' => '13579']);
            }
        });

        $this->user = User::create([
            'full_name' => 'مستأجر چرخه',
            'mobile' => '09121118001',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'full_name' => 'مدیر چرخه',
            'mobile' => '09121118002',
            'email' => 'lifecycle.admin@test.local',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['super_admin']);
    }

    // -- 1. the shipped configuration defines no trigger -------------------

    public function test_no_post_approval_trigger_is_configured(): void
    {
        // If one of these ever becomes non-null, it must be an owner decision
        // recorded in the documentation -- not a default someone guessed.
        $this->assertNull(config('rental.lifecycle.activation_trigger'));
        $this->assertNull(config('rental.lifecycle.return_trigger'));
        $this->assertNull(config('rental.lifecycle.closure_trigger'));
    }

    // -- 2. Approved never regresses ---------------------------------------

    public function test_advance_leaves_an_approved_application_approved(): void
    {
        $application = $this->approvedApplication();
        $orchestrator = app(RentalChainOrchestrator::class);

        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->count();

        for ($i = 0; $i < 5; $i++) {
            $orchestrator->advance($application->refresh());
        }

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
        $this->assertSame(
            $transitions,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
    }

    public function test_viewing_the_application_does_not_downgrade_approval(): void
    {
        $application = $this->approvedApplication();

        // The customer's own page calls advance() on every render.
        $this->actingAs($this->user)
            ->get(route('rental.applications.show', $application))
            ->assertOk();

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
    }

    public function test_a_customer_cannot_revoke_or_change_approval(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->user);

        // Every customer route that touches the chain, on an approved rental.
        $this->postJson(route('rental.applications.contract.accept', $application))->assertStatus(422);
        $this->postJson(route('rental.applications.contract.sign.otp', $application))->assertStatus(422);
        $this->postJson(route('rental.applications.contract.sign', $application), ['code' => self::CODE])
            ->assertStatus(422);

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
    }

    public function test_another_user_cannot_touch_an_approved_application(): void
    {
        $application = $this->approvedApplication();

        $intruder = User::create([
            'full_name' => 'کاربر دیگر',
            'mobile' => '09121118003',
            'status' => 'active',
        ]);

        $this->actingAs($intruder);

        $this->get(route('rental.applications.show', $application))->assertForbidden();
        $this->post(route('rental.applications.contract.sign.otp', $application))->assertForbidden();
        $this->post(route('rental.applications.contract.accept', $application))->assertForbidden();

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
    }

    // -- 3. Active / Returned / Closed are policy-blocked -------------------

    public function test_activation_fails_closed_while_its_trigger_is_undefined(): void
    {
        $this->assertPostApprovalRefused(RentalApplicationState::Active);
    }

    public function test_marking_returned_fails_closed_while_its_trigger_is_undefined(): void
    {
        $this->assertPostApprovalRefused(RentalApplicationState::Returned);
    }

    public function test_closure_fails_closed_while_its_trigger_is_undefined(): void
    {
        $this->assertPostApprovalRefused(RentalApplicationState::Closed);
    }

    public function test_advance_never_derives_a_post_approval_state(): void
    {
        $application = $this->approvedApplication();
        $orchestrator = app(RentalChainOrchestrator::class);

        // Even with the rental period long past, nothing activates or closes:
        // "the date arrived" is not a rule this project defines.
        $this->travelTo(now()->addMonths(6));
        $orchestrator->advance($application->refresh());
        $orchestrator->advance($application->refresh());
        $this->travelBack();

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);

        foreach ([
            RentalApplicationState::Active,
            RentalApplicationState::Returned,
            RentalApplicationState::Closed,
        ] as $state) {
            $this->assertSame(
                0,
                RentalApplicationTransition::where('rental_application_id', $application->id)
                    ->where('to_state', $state->value)->count(),
            );
        }
    }

    public function test_no_route_exposes_a_post_approval_transition(): void
    {
        $targets = ['active', 'returned', 'closed', 'activate', 'return', 'close'];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_contains($name, 'rental-applications.') && ! str_contains($name, 'rental.applications.')) {
                continue;
            }

            foreach ($targets as $target) {
                $this->assertStringNotContainsString(
                    $target,
                    $name,
                    'A route exposes a post-approval transition whose policy is undefined.',
                );
            }
        }
    }

    // -- 4. no rung may be skipped -----------------------------------------

    public function test_the_ladder_refuses_every_post_approval_shortcut(): void
    {
        $approved = RentalApplicationState::Approved;
        $active = RentalApplicationState::Active;

        // Forbidden jumps.
        $this->assertFalse($approved->canTransitionTo(RentalApplicationState::Returned));
        $this->assertFalse($approved->canTransitionTo(RentalApplicationState::Closed));
        $this->assertFalse($active->canTransitionTo(RentalApplicationState::Closed));
        $this->assertFalse(
            RentalApplicationState::AwaitingFinalApproval->canTransitionTo($active),
        );

        // The only permitted post-approval steps, one rung at a time.
        $this->assertTrue($approved->canTransitionTo($active));
        $this->assertTrue($active->canTransitionTo(RentalApplicationState::Returned));
        $this->assertTrue(RentalApplicationState::Returned->canTransitionTo(RentalApplicationState::Closed));
    }

    public function test_no_state_regresses(): void
    {
        $this->assertFalse(RentalApplicationState::Active->canTransitionTo(RentalApplicationState::Approved));
        $this->assertFalse(RentalApplicationState::Returned->canTransitionTo(RentalApplicationState::Active));
        $this->assertFalse(RentalApplicationState::Closed->canTransitionTo(RentalApplicationState::Returned));
        $this->assertFalse(RentalApplicationState::Approved->canTransitionTo(RentalApplicationState::AwaitingFinalApproval));
    }

    public function test_closed_is_terminal(): void
    {
        $closed = RentalApplicationState::Closed;

        $this->assertTrue($closed->isTerminal());

        foreach (RentalApplicationState::cases() as $state) {
            $this->assertFalse($closed->canTransitionTo($state), 'Closed reopened into '.$state->value);
        }
    }

    // -- 5. the state column is not writable from outside -------------------

    public function test_the_state_column_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $this->user->id,
            'state' => RentalApplicationState::Closed->value,
        ]);
    }

    public function test_request_input_cannot_set_the_state(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->user)
            ->postJson(route('rental.applications.contract.accept', $application), [
                'state' => 'closed',
                'application' => ['state' => 'active'],
            ])
            ->assertStatus(422);

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
    }

    public function test_the_orchestrator_is_still_the_only_writer_of_state(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (str_ends_with($file, 'RentalChainOrchestrator.php')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            // `=> RentalApplicationState::class` is the model's cast, not a write.
            if (preg_match('/\'state\'\s*=>\s*RentalApplicationState::(?!class)/', $source)
                || preg_match('/->state\s*=\s*RentalApplicationState::(?!class)/', $source)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders);
    }

    // -- 6. the mechanism, once a policy exists ----------------------------

    /**
     * Proves the plumbing works and stays idempotent. The config value here is
     * a TEST fixture standing in for a decision the owner has not made; the
     * shipped value is null, as test_no_post_approval_trigger_is_configured
     * asserts.
     */
    public function test_with_a_trigger_defined_the_mechanism_is_idempotent_and_ordered(): void
    {
        config()->set('rental.lifecycle.activation_trigger', 'test_fixture');

        $application = $this->approvedApplication();
        $orchestrator = app(RentalChainOrchestrator::class);

        $orchestrator->transitionPostApproval($application, RentalApplicationState::Active, $this->admin);
        $orchestrator->transitionPostApproval($application->refresh(), RentalApplicationState::Active, $this->admin);
        $orchestrator->transitionPostApproval($application->refresh(), RentalApplicationState::Active, $this->admin);

        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::Active->value)->count(),
        );

        // Returned still has no trigger, so the chain stops here anyway.
        try {
            $orchestrator->transitionPostApproval($application->refresh(), RentalApplicationState::Returned, $this->admin);
            $this->fail('Returned must stay policy-blocked.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(RentalApplicationState::Active, $application->refresh()->state);
    }

    public function test_even_with_a_trigger_a_rung_cannot_be_skipped(): void
    {
        config()->set('rental.lifecycle.closure_trigger', 'test_fixture');

        $application = $this->approvedApplication();

        $this->expectException(\RuntimeException::class);

        app(RentalChainOrchestrator::class)
            ->transitionPostApproval($application, RentalApplicationState::Closed, $this->admin);
    }

    // -- 7. audit ----------------------------------------------------------

    public function test_the_policy_undefined_audit_names_the_blocker_and_leaks_nothing(): void
    {
        $application = $this->approvedApplication();

        try {
            app(RentalChainOrchestrator::class)
                ->transitionPostApproval($application, RentalApplicationState::Active, $this->admin);
        } catch (\RuntimeException) {
            // expected
        }

        $event = AuditEvent::forAction('rental_application.policy_undefined')->firstOrFail();

        $this->assertSame('admin', $event->actor_type);
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame('RentalApplication', $event->resource_type);
        $this->assertSame($application->id, (int) $event->resource_id);
        $this->assertSame('denied', $event->result);
        $this->assertNotNull($event->correlation_id);
        $this->assertSame('approved', $event->context['from']);
        $this->assertSame('active', $event->context['to']);
        $this->assertStringContainsString('B14', $event->context['note']);

        $audit = json_encode(AuditEvent::pluck('context'), JSON_UNESCAPED_UNICODE);
        foreach ([self::CODE, self::NATIONAL_CODE, '1234567890123456', $this->user->mobile, '<section'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $audit);
        }
    }

    // -- 8. the lifecycle as it actually stands ----------------------------

    /**
     * The whole chain in one place, with each rung labelled by WHY it moves.
     * The last three are not "not yet written" -- they are refused, because the
     * rule that would permit them does not exist.
     */
    public function test_the_lifecycle_ends_at_approved_and_says_why(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        // Derived by advance() from the child records.
        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->state);

        // A human decision.
        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);
        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);

        // Policy-blocked: no trigger is defined for any of the three.
        foreach ([
            RentalApplicationState::Active,
            RentalApplicationState::Returned,
            RentalApplicationState::Closed,
        ] as $target) {
            try {
                app(RentalChainOrchestrator::class)
                    ->transitionPostApproval($application->refresh(), $target, $this->admin);
                $this->fail($target->value.' must remain policy-blocked.');
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
        $this->assertSame(3, AuditEvent::forAction('rental_application.policy_undefined')->count());

        // The transition log stops where the policy does.
        $this->assertSame(
            'approved',
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->latest('id')->firstOrFail()->to_state,
        );
    }

    // -- helpers -----------------------------------------------------------

    private function approvedApplication(): RentalApplication
    {
        $application = $this->signedApplication($this->user, self::CODE);

        app(RentalChainOrchestrator::class)->approve($application, $this->admin, null);

        return $application->refresh();
    }

    private function assertPostApprovalRefused(RentalApplicationState $target): void
    {
        $application = $this->approvedApplication();

        try {
            app(RentalChainOrchestrator::class)
                ->transitionPostApproval($application, $target, $this->admin);
            $this->fail($target->value.' must stay policy-blocked while its trigger is undefined.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
        $this->assertSame(
            0,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', $target->value)->count(),
        );
        $this->assertSame(1, AuditEvent::forAction('rental_application.policy_undefined')->count());
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
