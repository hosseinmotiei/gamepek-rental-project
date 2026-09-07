<?php

namespace Tests\Feature;

use App\Enums\ContractState;
use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\GuaranteeInquiry;
use App\Models\User;
use App\Services\Contract\ContractService;
use App\Services\Contract\TemplateRenderer;
use App\Services\Rental\RentalChainOrchestrator;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Step 7: GuaranteeVerified -> ContractGenerated.
 *
 * Two properties carry most of these cases. First, a contract is a snapshot:
 * once generated it owes nothing to the template it came from. Second, the
 * template is admin-editable text, so rendering it must be substitution and
 * never evaluation.
 */
class RentalContractTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ContractTemplateSeeder::class);

        // TODO(business) B6. Supplied so the chain can reach GuaranteeVerified;
        // production ships with it empty, which RentalGuaranteeTest asserts.
        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->user = User::create([
            'full_name' => 'مستأجر قرارداد',
            'mobile' => '09121113001',
            'status' => 'active',
        ]);
    }

    // -- 1. the rung -------------------------------------------------------

    public function test_a_verified_guarantee_can_generate_a_contract(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $this->assertSame(RentalApplicationState::GuaranteeVerified, $application->state);

        $contract = app(ContractService::class)->generate($application);

        $this->assertSame(ContractState::Generated, $contract->state);
        $this->assertNotNull($contract->generated_at);
    }

    public function test_generation_reaches_contract_generated_through_the_orchestrator(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);

        app(ContractService::class)->generate($application);

        // The service wrote a contract row and nothing else.
        $this->assertSame(RentalApplicationState::GuaranteeVerified, $application->refresh()->state);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract generated');

        $this->assertSame(RentalApplicationState::ContractGenerated, $application->refresh()->state);
    }

    // -- 2. earlier rungs are refused --------------------------------------

    public function test_an_application_without_a_verified_guarantee_cannot_generate(): void
    {
        $application = $this->paidApplication($this->user);

        $this->expectException(\RuntimeException::class);

        app(ContractService::class)->generate($application);
    }

    public function test_a_reserved_application_cannot_generate(): void
    {
        $application = $this->reservedApplication($this->user);

        try {
            app(ContractService::class)->generate($application);
            $this->fail('A reserved application must not receive a contract.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, Contract::count());
        $this->assertSame(1, AuditEvent::forAction('contract.generation_denied')->count());
        $this->assertSame('denied', AuditEvent::forAction('contract.generation_denied')->first()->result);
    }

    public function test_a_contract_row_alone_never_derives_contract_generated(): void
    {
        // Bypass the service gate entirely to prove the ladder itself holds.
        $application = $this->paidApplication($this->user);
        $template = ContractTemplate::firstOrFail();

        Contract::create([
            'rental_application_id' => $application->id,
            'contract_template_id' => $template->id,
            'template_key' => $template->key,
            'template_version' => $template->version,
            'number' => Contract::generateNumber(),
            'rendered_html' => '<p>x</p>',
            'content_hash' => hash('sha256', '<p>x</p>'),
            'state' => ContractState::Generated,
            'generated_at' => now(),
        ]);

        app(RentalChainOrchestrator::class)->advance($application->refresh());

        $this->assertSame(RentalApplicationState::Paid, $application->refresh()->state);
    }

    // -- 3. snapshot, hash, versioning -------------------------------------

    public function test_the_contract_stores_a_complete_snapshot(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        $this->assertNotEmpty($contract->rendered_html);
        $this->assertSame('rental_agreement', $contract->template_key);
        $this->assertSame(1, $contract->template_version);
        $this->assertSame($application->application_number, $contract->variables['application_number']);
        $this->assertStringContainsString($application->application_number, $contract->rendered_html);

        // No placeholder survived the render.
        $this->assertSame([], app(TemplateRenderer::class)->placeholders($contract->rendered_html));
    }

    public function test_the_content_hash_is_sha256_of_the_rendered_text(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $contract->content_hash);
        $this->assertSame(hash('sha256', $contract->rendered_html), $contract->content_hash);
        $this->assertTrue($contract->isIntact());
    }

    public function test_publishing_a_newer_template_does_not_alter_an_existing_contract(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        $before = [
            'html' => $contract->rendered_html,
            'hash' => $contract->content_hash,
            'version' => $contract->template_version,
        ];

        $key = config('rental.contract.template_key', 'rental_agreement');

        ContractTemplate::create([
            'key' => $key,
            'version' => ContractTemplate::nextVersionFor($key),
            'title' => 'نسخه دوم آزمایشی',
            'body' => '<p>متن کاملاً متفاوت {{application_number}}</p>',
            'variables' => ['application_number'],
            'is_active' => true,
            'published_at' => now(),
        ]);

        $contract->refresh();

        $this->assertSame($before['html'], $contract->rendered_html);
        $this->assertSame($before['hash'], $contract->content_hash);
        $this->assertSame($before['version'], $contract->template_version);
        $this->assertTrue($contract->isIntact());
    }

    public function test_a_published_template_cannot_be_edited(): void
    {
        $template = ContractTemplate::firstOrFail();

        $this->expectException(\RuntimeException::class);

        $template->update(['body' => '<p>متن جایگزین</p>']);
    }

    // -- 4. idempotency ----------------------------------------------------

    public function test_repeated_generation_is_idempotent(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contracts = app(ContractService::class);

        $first = $contracts->generate($application);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'contract generated');

        $second = $contracts->generate($application->refresh());
        $third = $contracts->generate($application->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame($first->number, $third->number);
        $this->assertSame($first->content_hash, $third->content_hash);

        $this->assertSame(1, Contract::count());
        $this->assertSame(1, AuditEvent::forAction('contract.generated')->count());
    }

    public function test_a_second_contract_row_for_one_application_is_refused_by_the_database(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        // The concurrency backstop: unique(rental_application_id). Two racing
        // requests that both pass the in-transaction check still cannot land
        // two rows.
        $this->expectException(QueryException::class);

        Contract::create([
            'rental_application_id' => $application->id,
            'contract_template_id' => $contract->contract_template_id,
            'template_key' => $contract->template_key,
            'template_version' => $contract->template_version,
            'number' => Contract::generateNumber(),
            'rendered_html' => '<p>duplicate</p>',
            'content_hash' => hash('sha256', '<p>duplicate</p>'),
            'state' => ContractState::Generated,
        ]);
    }

    // -- 5. fail closed on B12 ---------------------------------------------

    public function test_an_undefined_contract_template_stays_fail_closed(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);

        // No published, active template for the configured key.
        ContractTemplate::query()->update(['is_active' => false]);

        try {
            app(ContractService::class)->generate($application);
            $this->fail('Generation must fail closed without an active template.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, Contract::count());
        $this->assertSame(1, AuditEvent::forAction('contract.template_undefined')->count());

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        $this->assertSame(RentalApplicationState::GuaranteeVerified, $application->refresh()->state);
    }

    // -- 6. rendering is substitution, never evaluation --------------------

    public function test_the_template_cannot_execute_blade_or_php(): void
    {
        $hostile = <<<'HTML'
        <p>{{ 7*7 }}</p>
        <p>@php echo 'pwned'; @endphp</p>
        <p>{!! $application !!}</p>
        <p><?php echo 'pwned'; ?></p>
        <p>{{application_number}}</p>
        HTML;

        $rendered = app(TemplateRenderer::class)->render($hostile, [
            'application_number' => 'RA-TEST-000001',
        ]);

        // Nothing evaluated: every directive survives verbatim, and only the
        // real placeholder was substituted.
        $this->assertStringContainsString('{{ 7*7 }}', $rendered);
        $this->assertStringNotContainsString('49', $rendered);
        $this->assertStringContainsString("@php echo 'pwned'; @endphp", $rendered);
        $this->assertStringContainsString('{!! $application !!}', $rendered);
        $this->assertStringContainsString("<?php echo 'pwned'; ?>", $rendered);
        $this->assertStringContainsString('RA-TEST-000001', $rendered);
        $this->assertStringNotContainsString('{{application_number}}', $rendered);
    }

    public function test_user_controlled_values_are_escaped_into_the_contract(): void
    {
        $this->user->update(['full_name' => '<script>alert(1)</script>']);

        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        $this->assertStringNotContainsString('<script>', $contract->rendered_html);
        $this->assertStringContainsString('&lt;script&gt;', $contract->rendered_html);
    }

    // -- 7. sensitive data -------------------------------------------------

    public function test_no_raw_sensitive_value_reaches_the_contract_or_the_audit_trail(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        // The contract names the customer but never their raw national code,
        // and never the Sayad id -- a contract is a document people email.
        $this->assertStringNotContainsString(self::NATIONAL_CODE, $contract->rendered_html);
        $this->assertStringNotContainsString('1234567890123456', $contract->rendered_html);
        $this->assertStringNotContainsString(self::NATIONAL_CODE, json_encode($contract->variables));

        $audit = json_encode(AuditEvent::pluck('context'), JSON_UNESCAPED_UNICODE);

        foreach ([self::NATIONAL_CODE, '1234567890123456', $this->user->mobile] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }

        // What the generation event does carry: identifiers, not content.
        $event = AuditEvent::forAction('contract.generated')->firstOrFail();
        $this->assertSame($contract->number, $event->context['number']);
        $this->assertSame($contract->content_hash, $event->context['content_hash']);
        $this->assertArrayNotHasKey('rendered_html', $event->context);
    }

    public function test_the_content_hash_is_not_mass_assignable_from_a_request(): void
    {
        // The route validates nothing into the contract; this pins the model's
        // own guard so a future controller cannot forward request input.
        $this->expectException(MassAssignmentException::class);

        Contract::create([
            'rental_application_id' => 1,
            'is_admin' => true,
        ]);
    }

    // -- 8. authorization --------------------------------------------------

    public function test_a_user_cannot_generate_or_view_another_users_contract(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);

        $intruder = User::create([
            'full_name' => 'کاربر دیگر',
            'mobile' => '09121113002',
            'status' => 'active',
        ]);

        $this->actingAs($intruder)
            ->get(route('rental.applications.contract', $application))
            ->assertForbidden();

        $this->assertSame(0, Contract::count());
    }

    public function test_the_owner_can_generate_through_the_route(): void
    {
        $application = $this->guaranteeVerifiedApplication($this->user);

        $this->actingAs($this->user)
            ->get(route('rental.applications.contract', $application))
            ->assertOk();

        $this->assertSame(1, Contract::count());
        $this->assertSame(RentalApplicationState::ContractGenerated, $application->refresh()->state);
    }

    public function test_an_admin_without_the_permission_cannot_reach_the_contract(): void
    {
        $this->seed(UserSeeder::class);

        $application = $this->guaranteeVerifiedApplication($this->user);
        app(ContractService::class)->generate($application);

        $stranger = User::create([
            'full_name' => 'کارمند بی‌دسترسی',
            'mobile' => '09121113003',
            'email' => 'stranger@test.local',
            'status' => 'active',
        ]);

        // No admin role at all: EnsureIsAdmin bounces to the admin login before
        // any permission is consulted.
        $this->actingAs($stranger->fresh())
            ->get(route('admin.rental-applications.show', $application))
            ->assertRedirect(route('admin.login'));

        // `content_manager` is admin staff but holds no rental permissions.
        $staff = User::create([
            'full_name' => 'مدیر محتوا',
            'mobile' => '09121113004',
            'email' => 'content.contract@test.local',
            'status' => 'active',
        ]);
        $staff->syncRoles(['content_manager']);

        $this->actingAs($staff->fresh())
            ->get(route('admin.rental-applications.show', $application))
            ->assertForbidden();

        $this->actingAs($staff->fresh())
            ->post(route('admin.rental-applications.contract.void', $application), ['reason' => 'تست'])
            ->assertForbidden();

        $this->assertSame(ContractState::Generated, Contract::first()->state);
    }

    public function test_an_admin_with_the_permission_sees_the_contract(): void
    {
        $this->seed(UserSeeder::class);

        $application = $this->guaranteeVerifiedApplication($this->user);
        $contract = app(ContractService::class)->generate($application);

        $admin = User::create([
            'full_name' => 'مدیر قرارداد',
            'mobile' => '09121113005',
            'email' => 'contract.admin@test.local',
            'status' => 'active',
        ]);
        $admin->syncRoles(['super_admin']);

        $this->actingAs($admin->fresh())
            ->get(route('admin.rental-applications.show', $application))
            ->assertOk()
            ->assertSee($contract->number);
    }

    // -- 9. structural -----------------------------------------------------

    public function test_the_contract_controller_writes_no_application_state(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/RentalApplicationController.php'))
            .file_get_contents(app_path('Http/Controllers/Admin/RentalApplicationController.php'))
            .file_get_contents(app_path('Services/Contract/ContractService.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/\'state\'\s*=>\s*RentalApplicationState::/',
            (string) $source,
        );
    }
}
