<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_actor_resource_action_result_and_correlation_id(): void
    {
        $user = User::create([
            'full_name' => 'کاربر آزمایشی',
            'mobile' => '09120000001',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        AuditLogger::log(
            action: 'identity.submitted',
            resourceType: 'UserIdentity',
            resourceId: 42,
            result: AuditLogger::RESULT_SUCCESS,
            context: ['source' => 'test'],
        );

        $event = AuditEvent::firstOrFail();

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('identity.submitted', $event->action);
        $this->assertSame('UserIdentity', $event->resource_type);
        $this->assertSame(42, (int) $event->resource_id);
        $this->assertSame('success', $event->result);
        $this->assertNotNull($event->correlation_id);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_it_redacts_sensitive_context_keys_at_any_depth(): void
    {
        AuditLogger::log(
            action: 'bank.inquiry',
            resourceType: 'BankAccount',
            resourceId: 7,
            context: [
                'national_code' => '0012345678',
                'nested' => ['pan' => '6037991122334455', 'bank' => 'ملی'],
                'kept' => 'visible',
            ],
        );

        $context = AuditEvent::firstOrFail()->context;

        $this->assertSame('[redacted]', $context['national_code']);
        $this->assertSame('[redacted]', $context['nested']['pan']);
        $this->assertSame('ملی', $context['nested']['bank']);
        $this->assertSame('visible', $context['kept']);
    }

    public function test_an_unauthenticated_write_is_attributed_to_the_system_actor(): void
    {
        AuditLogger::log('media.purged', 'VerificationMedia', 3);

        $event = AuditEvent::firstOrFail();

        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
    }

    public function test_every_event_in_one_request_shares_a_correlation_id(): void
    {
        AuditLogger::log('a', 'X', 1);
        AuditLogger::log('b', 'X', 1);

        $ids = AuditEvent::pluck('correlation_id')->unique();

        $this->assertCount(1, $ids);
    }

    public function test_a_failure_to_persist_never_propagates_to_the_caller(): void
    {
        // An action name far longer than the column can hold. The audit write
        // must fail silently rather than break the business action it records.
        AuditLogger::log(str_repeat('x', 500), 'X', 1);

        $this->assertSame(0, AuditEvent::count());
    }
}
