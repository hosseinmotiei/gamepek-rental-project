<?php

namespace Tests\Feature;

use App\Enums\IdentityState;
use App\Enums\MediaState;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Media\VerificationMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalChain;
use Tests\TestCase;

/**
 * The KYC page's UI, not its business rules: rejected-state resubmission
 * messaging and the media upload/status UI this page previously lacked,
 * even though VerificationController::storeMedia() and
 * IdentityVerificationService::submit() already supported it.
 */
class VerificationKycUiTest extends TestCase
{
    use BuildsRentalChain, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('verification');

        $this->customer = User::create([
            'full_name' => 'مستأجر آزمایشی',
            'mobile' => '09121110001',
            'status' => 'active',
        ]);
    }

    public function test_a_rejected_identity_shows_resubmission_messaging_and_reason(): void
    {
        $identity = UserIdentity::create([
            'user_id' => $this->customer->id,
            'state' => IdentityState::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => 'تصویر کارت ملی ناخوانا بود.',
        ]);

        $response = $this->actingAs($this->customer)->get(route('verification.index'));

        $response->assertOk()
            ->assertSee('رد شده است')
            ->assertSee('تصویر کارت ملی ناخوانا بود.');

        // The existing resubmission route -- unchanged -- is still the form
        // this page renders; a rejected identity does not lose access to it.
        $this->assertStringContainsString(
            route('verification.identity.store'),
            $response->getContent(),
        );
    }

    public function test_the_media_section_lists_only_the_identity_relevant_kinds(): void
    {
        $response = $this->actingAs($this->customer)->get(route('verification.index'));

        $response->assertOk()
            ->assertSee('تصویر کارت ملی')
            ->assertSee('تصویر سلفی همراه با کارت ملی')
            ->assertSee('ویدئوی احراز زنده بودن')
            ->assertSee('بارگذاری نشده');

        // handover_video / return_video belong to the device custody
        // workflow, which has no customer-facing attachment point yet --
        // they must not appear as an identity upload option.
        $response->assertDontSee('name="kind" value="handover_video"', false);
        $response->assertDontSee('name="kind" value="return_video"', false);
    }

    public function test_uploading_media_updates_the_status_and_offers_a_view_link(): void
    {
        $this->actingAs($this->customer)->post(route('verification.media.store'), [
            'kind' => 'national_card',
            'file' => UploadedFile::fake()->create('card.jpg', 64, 'image/jpeg'),
        ])->assertRedirect();

        $response = $this->get(route('verification.index'));

        $response->assertOk()
            ->assertSee(MediaState::Ready->label())
            ->assertSee('مشاهده فایل بارگذاری‌شده');
    }

    public function test_a_rejected_media_upload_shows_its_rejection_reason(): void
    {
        $media = app(VerificationMediaService::class)->store(
            $this->customer,
            UploadedFile::fake()->create('selfie.jpg', 64, 'image/jpeg'),
            'selfie',
        );

        $media->update([
            'state' => MediaState::Rejected,
            'rejection_reason' => 'چهره در تصویر مشخص نیست.',
        ]);

        $response = $this->actingAs($this->customer)->get(route('verification.index'));

        $response->assertOk()
            ->assertSee(MediaState::Rejected->label())
            ->assertSee('چهره در تصویر مشخص نیست.');
    }

    public function test_a_guest_cannot_view_the_kyc_page(): void
    {
        $this->get(route('verification.index'))->assertRedirect();
    }
}
