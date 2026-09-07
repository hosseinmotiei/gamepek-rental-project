<?php

namespace Tests\Feature;

use App\Enums\GuaranteeState;
use App\Enums\RentalApplicationState;
use App\Models\AuditEvent;
use App\Models\Guarantee;
use App\Models\GuaranteeInquiry;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\User;
use App\Providers\IntegrationServiceProvider;
use App\Services\Banking\BankAccountService;
use App\Services\Guarantee\Contracts\ChequeProviderInterface;
use App\Services\Guarantee\Dto\ChequeResult;
use App\Services\Guarantee\GuaranteeService;
use App\Services\Guarantee\Providers\FakeChequeProvider;
use App\Services\Guarantee\Providers\UnconfiguredChequeProvider;
use App\Services\Rental\RentalChainOrchestrator;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Step 6: Paid -> GuaranteePending -> GuaranteeVerified.
 *
 * The theme running through every case is fail closed: a guarantee reaches
 * Verified only when the stored inquiry rows satisfy an owner-approved policy,
 * and the application's state is written by the orchestrator or by nobody.
 */
class RentalGuaranteeTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private const SAYAD_ID = '1234567890123456';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // TODO(business) B6 is undecided, so production ships with this empty.
        // The tests that exercise the automated path supply a policy; the ones
        // that assert fail-closed behaviour leave it empty on purpose.
        config()->set('verification.guarantee.required_inquiries', [
            GuaranteeInquiry::KIND_SAYAD_VALIDATE,
            GuaranteeInquiry::KIND_OWNERSHIP_MATCH,
        ]);

        $this->user = User::create([
            'full_name' => 'مستأجر ضمانت',
            'mobile' => '09121110001',
            'status' => 'active',
        ]);
    }

    // -- 1. the happy rungs ------------------------------------------------

    public function test_a_paid_application_enters_guarantee_pending(): void
    {
        $application = $this->paidApplication($this->user);
        $this->assertSame(RentalApplicationState::Paid, $application->state);

        // Submitted but not yet inquired: pending, never verified.
        app(GuaranteeService::class)->submit($application, ['sayad_id' => self::SAYAD_ID]);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'guarantee submitted');

        $this->assertSame(RentalApplicationState::GuaranteePending, $application->refresh()->state);
    }

    public function test_a_passing_guarantee_reaches_guarantee_verified(): void
    {
        $application = $this->paidApplication($this->user);
        $guarantee = $this->submitGuarantee($application);

        $this->assertSame(7, GuaranteeInquiry::where('guarantee_id', $guarantee->id)->count());
        $this->assertSame(GuaranteeState::Verified, $guarantee->refresh()->state);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'guarantee verified');

        $this->assertSame(RentalApplicationState::GuaranteeVerified, $application->refresh()->state);
    }

    // -- 2. payment cannot be skipped --------------------------------------

    public function test_an_unpaid_application_cannot_submit_a_guarantee(): void
    {
        $application = $this->reservedApplication($this->user);

        $this->expectException(\RuntimeException::class);

        app(GuaranteeService::class)->submit($application, ['sayad_id' => self::SAYAD_ID]);
    }

    public function test_a_guarantee_row_on_an_unpaid_application_never_derives_guarantee_pending(): void
    {
        // Bypass the service gate entirely to prove the ladder itself holds.
        $application = $this->reservedApplication($this->user);

        $guarantee = new Guarantee([
            'rental_application_id' => $application->id,
            'state' => GuaranteeState::Verified,
        ]);
        $guarantee->setSayadId(self::SAYAD_ID);
        $guarantee->save();

        app(RentalChainOrchestrator::class)->advance($application->refresh());

        $this->assertSame(RentalApplicationState::ReservationHeld, $application->refresh()->state);
    }

    // -- 3. failure paths --------------------------------------------------

    public function test_a_failed_sayad_validation_cannot_verify(): void
    {
        $this->bindProvider(sayadOutcome: ChequeResult::FAIL);

        $application = $this->paidApplication($this->user);
        $guarantee = $this->submitGuarantee($application);

        $this->assertNotSame(GuaranteeState::Verified, $guarantee->refresh()->state);
        $this->assertSame(
            ChequeResult::FAIL,
            GuaranteeInquiry::where('guarantee_id', $guarantee->id)
                ->where('kind', GuaranteeInquiry::KIND_SAYAD_VALIDATE)->value('result'),
        );

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        $this->assertSame(RentalApplicationState::GuaranteePending, $application->refresh()->state);
    }

    public function test_a_failed_ownership_match_cannot_verify(): void
    {
        $this->bindProvider(ownershipOutcome: ChequeResult::FAIL);

        $application = $this->paidApplication($this->user);
        $guarantee = $this->submitGuarantee($application)->refresh();

        $this->assertFalse($guarantee->ownership_match);
        $this->assertSame(GuaranteeState::OwnershipMismatch, $guarantee->state);
        $this->assertGreaterThan(0, AuditEvent::forAction('guarantee.ownership_mismatch')->count());

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        $this->assertSame(RentalApplicationState::GuaranteePending, $application->refresh()->state);
    }

    public function test_an_unconfigured_provider_fails_closed(): void
    {
        $this->app->bind(ChequeProviderInterface::class, fn () => new UnconfiguredChequeProvider);

        $application = $this->paidApplication($this->user);
        $guarantee = $this->submitGuarantee($application)->refresh();

        $this->assertNotSame(GuaranteeState::Verified, $guarantee->state);

        // Every inquiry recorded as failed/unknown -- never silently passed.
        $rows = GuaranteeInquiry::where('guarantee_id', $guarantee->id)->get();
        $this->assertCount(7, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row->state === 'failed'));
        $this->assertTrue($rows->every(fn ($row) => $row->result === ChequeResult::UNKNOWN));
    }

    public function test_the_fake_driver_is_refused_outside_local_and_testing(): void
    {
        config()->set('verification.guarantee.driver', 'fake');

        $this->assertInstanceOf(FakeChequeProvider::class, $this->resolveProviderAsEnvironment('testing'));
        $this->assertInstanceOf(UnconfiguredChequeProvider::class, $this->resolveProviderAsEnvironment('production'));
    }

    public function test_an_undefined_mandatory_inquiry_policy_stays_fail_closed(): void
    {
        config()->set('verification.guarantee.required_inquiries', []);

        $application = $this->paidApplication($this->user);
        $guarantee = $this->submitGuarantee($application)->refresh();

        $this->assertSame(GuaranteeState::Inquiring, $guarantee->state);
        $this->assertGreaterThan(0, AuditEvent::forAction('guarantee.policy_undefined')->count());

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        $this->assertSame(RentalApplicationState::GuaranteePending, $application->refresh()->state);
    }

    // -- 4. no invented business rules -------------------------------------

    public function test_the_guarantee_amount_is_never_invented(): void
    {
        // TODO(business) B5. Nothing may derive an amount from the deposit or
        // the device value while the rule is undefined.
        $this->assertNull(config('verification.guarantee.amount_rule'));

        $application = $this->paidApplication($this->user);

        $submitted = app(GuaranteeService::class)->submit($application, ['sayad_id' => self::SAYAD_ID]);
        $this->assertNull($submitted->amount);

        $withAmount = app(GuaranteeService::class)->submit($application, [
            'sayad_id' => self::SAYAD_ID,
            'amount' => 4_200_000,
        ]);
        $this->assertSame(4_200_000, $withAmount->refresh()->amount);
    }

    // -- 5. authorization / IDOR -------------------------------------------

    public function test_a_user_cannot_reach_another_users_guarantee(): void
    {
        $application = $this->paidApplication($this->user);
        $this->submitGuarantee($application);

        $intruder = User::create([
            'full_name' => 'کاربر دیگر',
            'mobile' => '09121110002',
            'status' => 'active',
        ]);

        $this->actingAs($intruder);

        $this->get(route('rental.applications.show', $application))->assertForbidden();

        $this->postJson(route('rental.applications.guarantee', $application), [
            'type' => 'cheque',
            'sayad_id' => '9876543210987654',
        ])->assertForbidden();

        // The intruder's submission did not land on someone else's row.
        $this->assertSame(1, Guarantee::count());
        $this->assertSame($application->id, Guarantee::first()->rental_application_id);
    }

    public function test_an_admin_without_the_permission_cannot_decide_a_guarantee(): void
    {
        $this->seed(UserSeeder::class);

        $application = $this->paidApplication($this->user);
        $this->submitGuarantee($application);

        // `support` may view rental applications but has no manage_guarantees.
        $staff = User::create([
            'full_name' => 'پشتیبان',
            'mobile' => '09121110003',
            'status' => 'active',
        ]);
        $staff->syncRoles(['support']);

        $this->actingAs($staff)
            ->post(route('admin.rental-applications.guarantee.verify', $application), ['note' => 'ok'])
            ->assertForbidden();

        // The refused request decided nothing.
        $this->assertSame(0, AuditEvent::forAction('guarantee.manual_verify')->count());
    }

    public function test_an_admin_rejection_is_audited_and_leaves_the_state_to_the_orchestrator(): void
    {
        $this->seed(UserSeeder::class);

        $application = $this->paidApplication($this->user);
        $this->submitGuarantee($application);

        $admin = User::create([
            'full_name' => 'مدیر ضمانت',
            'mobile' => '09121110004',
            'status' => 'active',
        ]);
        $admin->syncRoles(['super_admin']);

        $this->actingAs($admin)
            ->post(route('admin.rental-applications.guarantee.reject', $application), ['reason' => 'چک مخدوش است'])
            ->assertRedirect();

        $this->assertSame(GuaranteeState::Rejected, Guarantee::first()->refresh()->state);

        $event = AuditEvent::forAction('guarantee.rejected')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('admin', $event->actor_type);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('Guarantee', $event->resource_type);
        $this->assertSame('denied', $event->result);
        $this->assertNotNull($event->correlation_id);
        $this->assertNotNull($event->request_id);
    }

    // -- 6. idempotency ----------------------------------------------------

    public function test_repeated_submission_and_verification_is_idempotent(): void
    {
        $application = $this->paidApplication($this->user);

        $this->submitGuarantee($application);
        $this->submitGuarantee($application);

        app(RentalChainOrchestrator::class)->advance($application->refresh(), 'guarantee verified');

        $transitions = RentalApplicationTransition::where('rental_application_id', $application->id)->count();

        app(RentalChainOrchestrator::class)->advance($application->refresh());
        app(RentalChainOrchestrator::class)->advance($application->refresh());

        $this->assertSame(1, Guarantee::count());
        $this->assertSame(7, GuaranteeInquiry::count());
        $this->assertSame(1, AuditEvent::forAction('guarantee.submitted')->count());
        $this->assertSame(1, AuditEvent::forAction('guarantee.verified')->count());
        $this->assertSame(
            $transitions,
            RentalApplicationTransition::where('rental_application_id', $application->id)->count(),
        );
    }

    // -- 7. sensitive data -------------------------------------------------

    public function test_no_raw_sensitive_value_is_stored_or_audited(): void
    {
        $card = '6037997599999993';
        $iban = 'IR820540102680020817909002';

        $application = $this->paidApplication($this->user);

        $bank = app(BankAccountService::class);
        $bank->verify($bank->add($this->user, 'card', $card)->load('user.identity'));
        $bank->verify($bank->add($this->user, 'iban', $iban)->load('user.identity'));

        $guarantee = $this->submitGuarantee($application)->refresh();

        // Stored: encrypted, hashed, masked -- never the digits.
        $row = (array) DB::table('guarantees')->where('id', $guarantee->id)->first();
        $this->assertArrayNotHasKey('sayad_id', $row);
        $this->assertStringNotContainsString(self::SAYAD_ID, json_encode($row));
        $this->assertSame('1234********3456', $guarantee->sayad_id_mask);
        $this->assertSame(self::SAYAD_ID, $guarantee->sayadId());

        // Audited: nothing raw, anywhere, at any nesting depth.
        $audit = json_encode(AuditEvent::pluck('context'), JSON_UNESCAPED_UNICODE);

        foreach ([self::SAYAD_ID, $card, $iban, self::NATIONAL_CODE] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }

        $submitted = AuditEvent::forAction('guarantee.submitted')->first();
        $this->assertSame('1234********3456', $submitted->context['sayad_id_mask']);
    }

    public function test_the_sayad_id_is_not_mass_assignable(): void
    {
        $application = $this->paidApplication($this->user);

        $this->expectException(MassAssignmentException::class);

        Guarantee::create([
            'rental_application_id' => $application->id,
            'sayad_id_encrypted' => 'attacker-supplied',
            'sayad_id_hash' => str_repeat('a', 64),
        ]);
    }

    // -- 8. structural guarantees ------------------------------------------

    public function test_the_guarantee_route_uses_the_named_limiter(): void
    {
        $middleware = Route::getRoutes()->getByName('rental.applications.guarantee')->gatherMiddleware();

        $this->assertContains('throttle:rental-guarantee', $middleware);
        $this->assertNotNull(RateLimiter::limiter('rental-guarantee'));

        // No inline throttle:N,M -- that bucket collides for authenticated users.
        foreach ($middleware as $entry) {
            $this->assertDoesNotMatchRegularExpression('/^throttle:\d+/', (string) $entry);
        }
    }

    public function test_the_orchestrator_is_the_only_writer_of_application_state(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (str_ends_with($file, 'RentalChainOrchestrator.php')) {
                continue;
            }

            // `=> RentalApplicationState::class` is the model cast, and
            // `=> RentalApplicationState::Draft` is the value a brand new row is
            // created with. Neither is a transition.
            if (preg_match('/\'state\'\s*=>\s*RentalApplicationState::(?!class|Draft)/', (string) file_get_contents($file))) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders);

        // And behaviourally: the guarantee step alone moves nothing.
        $application = $this->paidApplication($this->user);
        $this->submitGuarantee($application);

        $this->assertSame(RentalApplicationState::Paid, $application->refresh()->state);
    }

    // -- helpers -----------------------------------------------------------

    private function submitGuarantee(RentalApplication $application, array $data = []): Guarantee
    {
        $guarantees = app(GuaranteeService::class);

        $guarantee = $guarantees->submit($application, $data + [
            'type' => Guarantee::TYPE_CHEQUE,
            'sayad_id' => self::SAYAD_ID,
        ]);

        $guarantee->setRelation('application', $application->load('user.identity'));

        return $guarantees->runInquiries($guarantee);
    }

    /** A provider that fails exactly one inquiry, so the rest still pass. */
    private function bindProvider(?string $sayadOutcome = null, ?string $ownershipOutcome = null): void
    {
        $this->app->bind(
            ChequeProviderInterface::class,
            fn () => new class($sayadOutcome, $ownershipOutcome) implements ChequeProviderInterface
            {
                public function __construct(private ?string $sayad, private ?string $ownership) {}

                public function validateSayadId(string $sayadId): ChequeResult
                {
                    return new ChequeResult($this->sayad ?? ChequeResult::PASS);
                }

                public function inquire(string $sayadId): ChequeResult
                {
                    return new ChequeResult(ChequeResult::PASS);
                }

                public function matchOwnership(string $sayadId, string $nationalCode): ChequeResult
                {
                    return new ChequeResult($this->ownership ?? ChequeResult::PASS);
                }

                public function bouncedCheques(string $nationalCode): ChequeResult
                {
                    return new ChequeResult(ChequeResult::PASS, 0);
                }

                public function creditRisk(string $nationalCode): ChequeResult
                {
                    return new ChequeResult(ChequeResult::PASS, 10);
                }

                public function aggregate(string $nationalCode): ChequeResult
                {
                    return new ChequeResult(ChequeResult::PASS, 10);
                }

                public function status(string $sayadId): ChequeResult
                {
                    return new ChequeResult(ChequeResult::PASS);
                }
            },
        );
    }

    private function resolveProviderAsEnvironment(string $environment): ChequeProviderInterface
    {
        $original = $this->app['env'];
        $this->app['env'] = $environment;

        try {
            $this->app->register(IntegrationServiceProvider::class, true);

            return $this->app->make(ChequeProviderInterface::class);
        } finally {
            $this->app['env'] = $original;
            $this->app->register(IntegrationServiceProvider::class, true);
        }
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
