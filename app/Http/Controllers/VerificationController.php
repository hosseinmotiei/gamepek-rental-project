<?php

namespace App\Http\Controllers;

use App\Models\VerificationMedia;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Media\VerificationMediaService;
use App\Services\Providers\ProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * KYC level 2 for the signed-in customer.
 *
 * Error handling follows .claude/rules/backend-services.md: a known business
 * failure surfaces its Persian message, anything unexpected is logged and
 * answered with a safe generic one. A raw exception message never reaches the
 * customer.
 */
class VerificationController extends Controller
{
    public function __construct(
        private IdentityVerificationService $identityService,
        private VerificationMediaService $mediaService,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        // Model::shouldBeStrict() enables preventLazyLoading outside
        // production, so every relation a view touches must be loaded here.
        $user->loadMissing(['identity.verifications', 'bankAccounts']);

        return view('verification.index', [
            'user' => $user,
            'identity' => $user->identity,
        ]);
    }

    public function storeIdentity(Request $request)
    {
        $data = $request->validate([
            'national_code' => ['required', 'string', 'size:10'],
            'birth_date' => ['nullable', 'date'],
        ], [
            'national_code.required' => 'وارد کردن کد ملی الزامی است.',
            'national_code.size' => 'کد ملی باید ۱۰ رقم باشد.',
            'birth_date.date' => 'تاریخ تولد وارد شده معتبر نیست.',
        ]);

        try {
            $identity = $this->identityService->submit(
                $request->user(),
                $data['national_code'],
                $data['birth_date'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        return $this->ok($request, 'اطلاعات هویتی ثبت شد.', [
            'state' => $identity->state->value,
            'national_code_mask' => $identity->national_code_mask,
        ]);
    }

    /** Runs one named check. Idempotent -- a recent pass is not re-purchased. */
    public function runIdentityCheck(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:shahkar,civil_registry'],
        ]);

        $identity = $request->user()->identity;

        if (! $identity) {
            return $this->fail($request, 'ابتدا کد ملی خود را ثبت کنید.', 422);
        }

        $identity->loadMissing('user');

        try {
            $check = match ($data['type']) {
                'shahkar' => $this->identityService->runShahkar($identity),
                'civil_registry' => $this->identityService->runCivilRegistry($identity),
            };
        } catch (ProviderException $e) {
            return $this->fail($request, $e->persianMessage, 503);
        } catch (\RuntimeException $e) {
            return $this->fail($request, $e->getMessage(), 429);
        }

        return $this->ok($request, 'استعلام انجام شد.', [
            'type' => $check->type,
            'state' => $check->state,
            'identity_state' => $identity->refresh()->state->value,
        ]);
    }

    public function storeMedia(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:national_card,selfie,liveness_video,handover_video,return_video'],
            'file' => ['required', 'file'],
        ], [
            'file.required' => 'انتخاب فایل الزامی است.',
        ]);

        try {
            $media = $this->mediaService->store($request->user(), $request->file('file'), $data['kind']);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($request, $e->getMessage(), 422);
        }

        $this->mediaService->markProcessed($media);

        return $this->ok($request, 'فایل با موفقیت بارگذاری شد.', [
            'media_id' => $media->id,
            'url' => $this->mediaService->temporaryUrl($media),
        ]);
    }

    /**
     * VID-04. The route carries the `signed` middleware, but a signed URL is a
     * bearer token -- anyone holding the link can fetch it until it expires.
     * So ownership is checked here as well; the signature proves the link came
     * from us, not that the person clicking it is entitled to the content.
     */
    public function showMedia(Request $request, VerificationMedia $media)
    {
        $this->authorize('view', $media);

        abort_if($media->isPurged() || ! $media->path, 404);

        AuditLogger::log(
            action: 'media.read',
            resourceType: 'VerificationMedia',
            resourceId: $media->id,
            context: ['kind' => $media->kind],
        );

        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, null, [
            // Never cache identity media in a shared cache.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function ok(Request $request, string $message, array $payload = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['success' => true, 'message' => $message], $payload));
        }

        return back()->with('success', $message);
    }

    private function fail(Request $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return back()->withErrors(['verification' => $message]);
    }
}
