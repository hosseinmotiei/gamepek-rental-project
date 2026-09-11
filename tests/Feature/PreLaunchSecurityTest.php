<?php

namespace Tests\Feature;

use App\Enums\PaymentVerificationState;
use App\Models\ContractSignature;
use App\Models\GuaranteeInquiry;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Otp\OtpProviderInterface;
use App\Services\PardakhtNovinGateway;
use Database\Seeders\ContractTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The pre-launch findings, pinned so they cannot quietly return.
 *
 * Each test is one concrete production risk that was found and closed: identity
 * media reachable through the framework's file-serving route, bank response
 * bodies and tokens in the logs, mutable signature evidence, and an unindexed
 * lookup behind a public endpoint.
 */
class PreLaunchSecurityTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    // ── Identity media ───────────────────────────────────────────────────

    public function test_identity_media_is_never_served_by_the_framework_file_route(): void
    {
        Storage::disk('verification')->put('probe/national-id.jpg', 'IDENTITY-DOCUMENT-BYTES');

        // Every plausible spelling of the path under the local disk's root.
        foreach (['/storage/verification/probe/national-id.jpg', '/storage/probe/national-id.jpg'] as $url) {
            $response = $this->get($url);

            $this->assertNotSame(200, $response->status(), $url.' must not serve identity media');
            $this->assertStringNotContainsString('IDENTITY-DOCUMENT-BYTES', (string) $response->getContent());
        }

        // And the route that would serve private files on a signature alone
        // is not registered at all.
        $this->assertFalse(app('router')->has('storage.local'),
            'the local disk must not expose a signature-only file route');
    }

    // ── Gateway logs ─────────────────────────────────────────────────────

    public function test_bank_responses_and_tokens_never_reach_the_logs(): void
    {
        config()->set('rental.payment.pardakhtnovin.corporation_pin', 'test-pin-not-real');
        Http::fake(['*' => Http::response([
            'Status' => '0',
            'Token' => 'BANK-SESSION-TOKEN-XYZ',
            'RRN' => 'RRN-998877',
            'CardNumberMasked' => '6037-99**-****-4321',
        ], 200)]);
        Log::spy();

        $gateway = app(PardakhtNovinGateway::class);
        $gateway->requestToken(1, 5_000_000, 'https://example.test/callback');
        $gateway->confirm('BANK-SESSION-TOKEN-XYZ');
        $gateway->reverse('BANK-SESSION-TOKEN-XYZ');

        $leaks = fn ($message, $context = []) => (bool) preg_match(
            '/BANK-SESSION-TOKEN-XYZ|RRN-998877|4321|test-pin-not-real/',
            json_encode($context),
        );

        Log::shouldNotHaveReceived('info', $leaks);
        Log::shouldNotHaveReceived('error', $leaks);
        Log::shouldNotHaveReceived('warning', $leaks);
    }

    public function test_a_transport_failure_log_carries_no_token(): void
    {
        config()->set('rental.payment.pardakhtnovin.corporation_pin', 'test-pin-not-real');
        Http::fake(fn () => throw new ConnectionException('timed out'));
        Log::spy();

        app(PardakhtNovinGateway::class)->confirm('BANK-SESSION-TOKEN-XYZ');

        Log::shouldNotHaveReceived('error', fn ($m, $c = []) => str_contains(json_encode($c), 'BANK-SESSION-TOKEN-XYZ'));
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'transport failure'))->once();
    }

    // ── Signature evidence ───────────────────────────────────────────────

    public function test_signature_evidence_is_immutable_but_can_still_be_verified(): void
    {
        $signature = $this->signedContractSignature();

        foreach ([
            'signed_content_hash' => str_repeat('0', 64),
            'signature' => 'forged',
            'ip_address' => '10.0.0.1',
            'signed_at' => now()->subYear(),
            'user_id' => User::create(['full_name' => 'x', 'mobile' => '09129990001', 'status' => 'active'])->id,
        ] as $column => $value) {
            try {
                $signature->fresh()->update([$column => $value]);
                $this->fail("signature column {$column} must not be changeable");
            } catch (\LogicException) {
                // expected
            }
        }

        try {
            $signature->fresh()->delete();
            $this->fail('signature evidence must not be deletable');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame(1, ContractSignature::count());

        // The one legitimate later write still works.
        $signature->fresh()->update(['verified_at' => now()]);
        $this->assertNotNull($signature->fresh()->verified_at);
    }

    // ── Public endpoint performance ──────────────────────────────────────

    public function test_the_payment_callback_lookup_is_indexed(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM payment_transactions'))
            ->groupBy('Key_name')
            ->map(fn ($cols) => $cols->sortBy('Seq_in_index')->pluck('Column_name')->all());

        $this->assertContains(['gateway', 'authority'], $indexes->values()->all(),
            'every public callback looks up (gateway, authority); it must not scan the table');

        // And the lookup still resolves the right row.
        $user = User::create(['full_name' => 'پرداخت', 'mobile' => '09129990002', 'status' => 'active']);
        $order = Order::create([
            'order_number' => 'RNT-IDX-1', 'user_id' => $user->id, 'status' => 'pending_payment',
            'payment_status' => 'unpaid', 'subtotal' => 1000, 'total' => 1000,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id, 'user_id' => $user->id, 'gateway' => 'mock', 'amount' => 1000,
            'status' => 'pending', 'authority' => 'AUTH-IDX-1',
            'verification_state' => PaymentVerificationState::Unverified->value,
        ]);

        $this->assertSame(1, PaymentTransaction::where('gateway', 'mock')->where('authority', 'AUTH-IDX-1')->count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function signedContractSignature(): ContractSignature
    {
        $this->seed(ContractTemplateSeeder::class);

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

        $application = $this->signedApplication(
            User::create(['full_name' => 'امضاکننده', 'mobile' => '09129990003', 'status' => 'active']),
        );

        return ContractSignature::where('contract_id', $application->contract->id)->sole();
    }
}
