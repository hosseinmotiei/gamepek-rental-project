<?php

namespace Tests\Feature;

use App\Enums\ContractState;
use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\Rental\RentalChainOrchestrator;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Steps 10 and 11: AwaitingFinalApproval, then the admin's explicit Approved.
 *
 * Approved is the one state the chain never derives. advance() will sit at
 * AwaitingFinalApproval forever; only a person with the permission moves it,
 * and only when the signed contract still verifies.
 *
 * TODO(business) B8: what makes an application worth approving is the owner's
 * decision and is not encoded anywhere here.
 */
class RentalFinalApprovalTest extends TestCase
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
                return json_encode(['code' => RentalFinalApprovalTest::code()]);
            }
        });

        $this->user = User::create([
            'full_name' => 'مستأجر تأیید',
            'mobile' => '09121117001',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'full_name' => 'مدیر تأیید',
            'mobile' => '09121117002',
            'email' => 'final.admin@test.local',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['super_admin']);
    }

    public static function code(): string
    {
        return self::CODE;
    }

    // -- Step 10: the derived waiting rung ---------------------------------

    public function test_a_signed_contract_derives_awaiting_final_approval(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        $this->assertSame(ContractState::Signed, $application->contract->state);
        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->state);
    }

    public function test_advance_never_reaches_approved_on_its_own(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);
        $orchestrator = app(RentalChainOrchestrator::class);

        for ($i = 0; $i < 5; $i++) {
            $orchestrator->advance($application->refresh());
        }

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
        $this->assertSame(0, AuditEvent::forAction('rental_application.approved')->count());
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::AwaitingFinalApproval->value)
                ->count(),
        );
    }

    public function test_an_unsigned_contract_never_reaches_awaiting_final_approval(): void
    {
        $application = $this->contractAcceptedApplication($this->user);

        app(RentalChainOrchestrator::class)->advance($application->refresh());

        $this->assertSame(RentalApplicationState::ContractAccepted, $application->refresh()->state);
    }

    // -- Step 11: the human decision ---------------------------------------

    public function test_an_authorized_admin_can_approve_a_signed_application(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        $this->actingAs($this->admin->fresh())
            ->post(route('admin.rental-applications.approve', $application), ['note' => 'بررسی شد'])
            ->assertRedirect();

        $application->refresh();

        $this->assertSame(RentalApplicationState::Approved, $application->state);
        $this->assertNotNull($application->approved_at);
        $this->assertSame('بررسی شد', $application->admin_note);
    }

    public function test_a_customer_cannot_approve_their_own_application(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        // No admin role: EnsureIsAdmin bounces before any permission check.
        $this->actingAs($this->user->fresh())
            ->post(route('admin.rental-applications.approve', $application))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
        $this->assertSame(0, AuditEvent::forAction('rental_application.approved')->count());
    }

    public function test_an_admin_without_the_permission_cannot_approve(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        // `support` is admin staff, may view rental applications, and has no
        // manage_rental_applications.
        $staff = User::create([
            'full_name' => 'پشتیبان',
            'mobile' => '09121117003',
            'email' => 'support.final@test.local',
            'status' => 'active',
        ]);
        $staff->syncRoles(['support']);

        $this->actingAs($staff->fresh())
            ->post(route('admin.rental-applications.approve', $application))
            ->assertForbidden();

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
        $this->assertSame(0, AuditEvent::forAction('rental_application.approved')->count());
    }

    // -- Step 11: everything that must still hold --------------------------

    public function test_an_application_not_awaiting_final_approval_cannot_be_approved(): void
    {
        foreach ([
            'accepted' => fn () => $this->contractAcceptedApplication($this->user),
        ] as $label => $build) {
            $application = $build();

            $this->assertApprovalRefused($application, $label);
        }
    }

    public function test_a_paid_application_cannot_be_approved(): void
    {
        $this->assertApprovalRefused($this->paidApplication($this->user), 'paid');
    }

    public function test_a_guarantee_verified_application_cannot_be_approved(): void
    {
        $this->assertApprovalRefused($this->guaranteeVerifiedApplication($this->user), 'guarantee_verified');
    }

    public function test_a_generated_but_unsigned_contract_cannot_be_approved(): void
    {
        $this->assertApprovalRefused($this->contractGeneratedApplication($this->user), 'generated');
    }

    public function test_a_tampered_contract_cannot_be_approved(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        Contract::where('id', $application->contract->id)->update([
            'rendered_html' => $application->contract->rendered_html.'<p>یک بند اضافه‌شده</p>',
        ]);

        $this->actingAs($this->admin->fresh())
            ->post(route('admin.rental-applications.approve', $application))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
        $this->assertSame(0, AuditEvent::forAction('rental_application.approved')->count());
        $this->assertSame(
            'contract_tampered',
            AuditEvent::forAction('rental_application.approval_denied')->firstOrFail()->context['reason'],
        );
    }

    public function test_a_missing_signature_cannot_be_approved(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        // The contract still says signed, but its evidence is gone.
        ContractSignature::query()->delete();

        $this->actingAs($this->admin->fresh())
            ->post(route('admin.rental-applications.approve', $application))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
        $this->assertSame(
            'signature_missing',
            AuditEvent::forAction('rental_application.approval_denied')->firstOrFail()->context['reason'],
        );
    }

    public function test_a_forged_signature_cannot_be_approved(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        ContractSignature::query()->update(['signature' => str_repeat('a', 64)]);

        $this->actingAs($this->admin->fresh())
            ->post(route('admin.rental-applications.approve', $application))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(RentalApplicationState::AwaitingFinalApproval, $application->refresh()->state);
        $this->assertSame(
            'signature_invalid',
            AuditEvent::forAction('rental_application.approval_denied')->firstOrFail()->context['reason'],
        );
    }

    // -- Step 11: idempotency / concurrency --------------------------------

    public function test_repeated_approval_is_idempotent(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);
        $orchestrator = app(RentalChainOrchestrator::class);

        $orchestrator->approve($application, $this->admin, 'first');
        $approvedAt = $application->refresh()->approved_at;

        $orchestrator->approve($application->refresh(), $this->admin, 'second');
        $orchestrator->approve($application->refresh(), $this->admin, 'third');

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
        $this->assertSame('first', $application->refresh()->admin_note);
        $this->assertEquals($approvedAt, $application->refresh()->approved_at);

        $this->assertSame(1, AuditEvent::forAction('rental_application.approved')->count());
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::Approved->value)
                ->count(),
        );
    }

    public function test_a_second_admin_observes_the_existing_approval(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        $second = User::create([
            'full_name' => 'مدیر دوم',
            'mobile' => '09121117004',
            'email' => 'final.admin2@test.local',
            'status' => 'active',
        ]);
        $second->syncRoles(['super_admin']);

        $orchestrator = app(RentalChainOrchestrator::class);

        $orchestrator->approve($application, $this->admin, 'first admin');
        $orchestrator->approve($application->refresh(), $second, 'second admin');

        $event = AuditEvent::forAction('rental_application.approved')->get();

        $this->assertCount(1, $event);
        $this->assertSame($this->admin->id, $event->first()->actor_id);
        $this->assertSame('first admin', $application->refresh()->admin_note);
    }

    public function test_repeated_route_approval_creates_one_approval(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);

        $this->actingAs($this->admin->fresh());

        $this->post(route('admin.rental-applications.approve', $application))->assertRedirect();
        $this->post(route('admin.rental-applications.approve', $application))->assertRedirect();

        $this->assertSame(1, AuditEvent::forAction('rental_application.approved')->count());
        $this->assertSame(
            1,
            RentalApplicationTransition::where('rental_application_id', $application->id)
                ->where('to_state', RentalApplicationState::Approved->value)
                ->count(),
        );
    }

    public function test_advance_after_approval_writes_no_further_transition(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);
        $orchestrator = app(RentalChainOrchestrator::class);

        $orchestrator->approve($application, $this->admin, null);

        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->count();

        $orchestrator->advance($application->refresh());
        $orchestrator->advance($application->refresh());

        $this->assertSame(RentalApplicationState::Approved, $application->refresh()->state);
        $this->assertSame(
            $transitions,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
    }

    // -- Step 11: audit ----------------------------------------------------

    public function test_the_approval_audit_identifies_the_decision_and_leaks_nothing(): void
    {
        $application = $this->signedApplication($this->user, self::CODE);
        $contract = $application->contract;

        $this->actingAs($this->admin->fresh())
            ->post(route('admin.rental-applications.approve', $application), ['note' => 'تأیید شد'])
            ->assertRedirect();

        $event = AuditEvent::forAction('rental_application.approved')->firstOrFail();

        $this->assertSame('admin', $event->actor_type);
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame('RentalApplication', $event->resource_type);
        $this->assertSame($application->id, (int) $event->resource_id);
        $this->assertSame('success', $event->result);
        $this->assertNotNull($event->correlation_id);
        $this->assertNotNull($event->request_id);

        $this->assertSame($application->application_number, $event->context['application_number']);
        $this->assertSame($contract->number, $event->context['contract_number']);
        $this->assertSame($contract->content_hash, $event->context['content_hash']);
        $this->assertSame($contract->template_key, $event->context['template_key']);

        $audit = json_encode(AuditEvent::pluck('context'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::CODE, (string) $audit);
        $this->assertStringNotContainsString('<section', (string) $audit);
        $this->assertStringNotContainsString(self::NATIONAL_CODE, (string) $audit);
        $this->assertStringNotContainsString($this->user->mobile, (string) $audit);
    }

    // -- helpers -----------------------------------------------------------

    private function assertApprovalRefused(RentalApplication $application, string $label): void
    {
        $before = $application->state;

        $this->actingAs($this->admin->fresh())
            ->post(route('admin.rental-applications.approve', $application))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame($before, $application->refresh()->state, $label);
        $this->assertNull($application->approved_at, $label);
        $this->assertSame(0, AuditEvent::forAction('rental_application.approved')->count(), $label);
        $this->assertGreaterThan(
            0,
            AuditEvent::forAction('rental_application.approval_denied')->count(),
            $label,
        );
    }
}
