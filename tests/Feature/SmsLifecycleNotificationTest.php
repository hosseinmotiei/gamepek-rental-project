<?php

namespace Tests\Feature;

use App\Enums\RentalApplicationState;
use App\Enums\SmsState;
use App\Models\AuditEvent;
use App\Models\RentalApplication;
use App\Models\RentalApplicationTransition;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Notification\Contracts\SmsSenderInterface;
use App\Services\Notification\Dto\SmsSendResult;
use App\Services\Notification\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * Lifecycle SMS: wired, silent until approved copy exists, sent at most once
 * per state change, and unable to harm the rental whatever the provider does.
 *
 * No provider exists. These tests never pretend one does: success is only ever
 * the explicit local `log` driver, and every "provider" failure is a sender
 * this file defines.
 */
class SmsLifecycleNotificationTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private function user(string $mobile = '09123334455'): User
    {
        return User::create(['full_name' => 'مشتری پیامک', 'mobile' => $mobile, 'status' => 'active']);
    }

    private function application(User $user): RentalApplication
    {
        return RentalApplication::create([
            'application_number' => RentalApplication::generateNumber(),
            'user_id' => $user->id,
        ]);
    }

    /** A transition row: the one record every committed state change writes. */
    private function transition(RentalApplication $application, string $to = 'paid'): RentalApplicationTransition
    {
        return RentalApplicationTransition::create([
            'rental_application_id' => $application->id,
            'from_state' => 'payment_pending',
            'to_state' => $to,
            'actor_type' => 'system',
            'created_at' => now(),
        ]);
    }

    private function approveCopyFor(string $state, string $body = 'درخواست {{application_number}} ثبت شد.'): void
    {
        config()->set('rental.sms.templates.rental_'.$state, $body);
        config()->set('rental.sms.state_templates.'.$state, 'rental_'.$state);
    }

    // ── Silent by default ────────────────────────────────────────────────

    public function test_with_no_approved_copy_a_state_change_sends_nothing_and_logs_nothing(): void
    {
        $this->transition($this->application($this->user()));

        $this->assertSame(0, SmsMessage::count());
        // An empty map is the feature switched off, not a fault per transition.
        $this->assertSame(0, AuditEvent::forAction('sms.template_undefined')->count());
    }

    public function test_a_real_chain_moves_through_every_rung_without_a_single_sms(): void
    {
        $this->paidApplication($this->user('09123334400'));

        $this->assertGreaterThan(0, RentalApplicationTransition::count());
        $this->assertSame(0, SmsMessage::count(), 'no copy is approved, so the real chain must stay silent');
    }

    // ── Wired ────────────────────────────────────────────────────────────

    public function test_an_approved_transition_sends_one_message_keyed_to_it(): void
    {
        $this->approveCopyFor('paid');
        $application = $this->application($this->user());

        $transition = $this->transition($application);

        $message = SmsMessage::sole();
        $this->assertSame('transition:'.$transition->id, $message->dedupe_key);
        $this->assertSame(SmsState::Sent, $message->state);
        $this->assertSame('log', $message->provider, 'the only success in tests is the explicit local driver');
        $this->assertSame('درخواست '.$application->application_number.' ثبت شد.', $message->body);
    }

    public function test_the_real_orchestrator_path_triggers_it_through_the_ledger(): void
    {
        $this->approveCopyFor(RentalApplicationState::Paid->value);

        $application = $this->paidApplication($this->user('09123334401'));

        $paid = RentalApplicationTransition::where('rental_application_id', $application->id)
            ->where('to_state', RentalApplicationState::Paid->value)->sole();

        $this->assertSame(1, SmsMessage::where('dedupe_key', 'transition:'.$paid->id)->count());
    }

    public function test_one_event_can_never_send_twice(): void
    {
        config()->set('rental.sms.templates.rental_test', 'متن آزمایشی');
        $user = $this->user();
        $sms = app(SmsService::class);

        $first = $sms->send('rental_test', $user->mobile, [], $user, dedupeKey: 'transition:999');
        $second = $sms->send('rental_test', $user->mobile, [], $user, dedupeKey: 'transition:999');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SmsMessage::count());
    }

    public function test_a_rolled_back_transition_sends_nothing(): void
    {
        $this->approveCopyFor('paid');
        $application = $this->application($this->user());

        try {
            DB::transaction(function () use ($application) {
                $this->transition($application);

                throw new \RuntimeException('the state change failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, RentalApplicationTransition::count());
        $this->assertSame(0, SmsMessage::count(), 'a message must never announce a change that did not happen');
    }

    // ── Failure isolation / no false success ─────────────────────────────

    public function test_a_throwing_provider_cannot_touch_the_rental(): void
    {
        $this->app->bind(SmsSenderInterface::class, fn () => new class implements SmsSenderInterface
        {
            public function key(): string
            {
                return 'exploding';
            }

            public function send(string $mobile, string $body, ?string $templateKey = null): SmsSendResult
            {
                throw new \RuntimeException('https://provider.test/send?api_key=LEAKED-KEY');
            }

            public function deliveryStatus(string $providerMessageId): string
            {
                return 'unknown';
            }
        });

        $this->approveCopyFor(RentalApplicationState::Paid->value);

        // The whole real chain runs to Paid while every SMS attempt explodes.
        $application = $this->paidApplication($this->user('09123334402'));

        $this->assertSame(RentalApplicationState::Paid, $application->fresh()->state);
        $this->assertNotNull($application->fresh()->reservation, 'the reservation is untouched by a provider failure');

        $message = SmsMessage::sole();
        $this->assertSame(SmsState::Failed, $message->state);
        $this->assertSame('unexpected: RuntimeException', $message->last_error);
        $this->assertStringNotContainsString('LEAKED-KEY', (string) $message->last_error,
            'an unexpected exception message may carry a secret; only its class is stored');
    }

    public function test_an_unconfigured_provider_fails_honestly_and_never_claims_a_send(): void
    {
        config()->set('verification.sms.driver', 'unconfigured');
        $this->approveCopyFor('paid');

        $this->transition($this->application($this->user()));

        $message = SmsMessage::sole();
        $this->assertSame(SmsState::Failed, $message->state);
        $this->assertNull($message->sent_at);
        $this->assertNull($message->delivered_at);
    }

    public function test_the_log_driver_never_reports_a_delivery(): void
    {
        $this->approveCopyFor('paid');
        $this->transition($this->application($this->user()));

        $message = app(SmsService::class)->syncDeliveryStatus(SmsMessage::sole());

        $this->assertSame(SmsState::Sent, $message->state, 'nothing was delivered, so nothing may say so');
        $this->assertNull($message->delivered_at);
    }

    public function test_the_log_driver_is_refused_outside_local_and_testing(): void
    {
        config()->set('verification.sms.driver', 'log');
        $this->app->detectEnvironment(fn () => 'staging');

        $this->assertSame('unconfigured', app(SmsSenderInterface::class)->key(),
            'a staging box must not record messages as sent that never left');

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('unconfigured', app(SmsSenderInterface::class)->key());
    }
}
