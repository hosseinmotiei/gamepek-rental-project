<?php

namespace App\Services\Media;

use App\Enums\MediaState;
use App\Models\RentalApplication;
use App\Models\User;
use App\Models\VerificationMedia;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * VID-01..VID-06.
 *
 * THE rule this class exists to enforce: verification media never touches the
 * `public` disk. That disk\'s root is env(\'STORAGE_PUBLIC_PATH\') -- a real
 * directory Apache serves directly, sitting above the Laravel root on the
 * production host (see config/filesystems.php and
 * .claude/rules/frontend-blade.md). A file written there is readable by anyone
 * who guesses the URL. Identity documents and liveness video go to the private
 * `verification` disk and are reachable only through an expiring signed route
 * that re-checks ownership on every hit.
 */
class VerificationMediaService
{
    /** VID-01 + VID-02. */
    public function store(User $user, UploadedFile $file, string $kind, ?RentalApplication $application = null): VerificationMedia
    {
        $this->assertAllowed($file, $kind);

        $disk = config('verification.media.disk', 'verification');

        // Never trust the client-supplied filename for the stored path.
        $path = sprintf(
            'users/%d/%s/%s.%s',
            $user->id,
            $kind,
            Str::uuid(),
            $file->extension() ?: 'bin',
        );

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()), 'private');

        $media = VerificationMedia::create([
            'user_id' => $user->id,
            'rental_application_id' => $application?->id,
            'kind' => $kind,
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 200, ''),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'metadata' => [
                'client_extension' => $file->getClientOriginalExtension(),
                'uploaded_at' => now()->toIso8601String(),
            ],
            'state' => MediaState::Uploaded,
            'retention_until' => $this->retentionUntil($kind),
        ]);

        AuditLogger::log(
            action: 'media.uploaded',
            resourceType: 'VerificationMedia',
            resourceId: $media->id,
            context: [
                'kind' => $kind,
                'size_bytes' => $media->size_bytes,
                'disk' => $disk,
                'retention_until' => $media->retention_until?->toDateString(),
            ],
        );

        return $media;
    }

    /**
     * VID-04. Expiring signed URL.
     *
     * A signed URL is a bearer token: anyone holding it can fetch the file
     * until it expires. That is why the controller behind this route ALSO
     * checks ownership -- the signature proves the link was issued by us, not
     * that the person clicking it is entitled to the content.
     */
    public function temporaryUrl(VerificationMedia $media, ?int $minutes = null): string
    {
        $minutes ??= (int) config('verification.media.signed_url_minutes', 10);

        return URL::temporarySignedRoute(
            'verification.media.show',
            now()->addMinutes($minutes),
            ['media' => $media->id],
        );
    }

    /** VID-05. Only checksum/size for now; transcoding needs a dependency we have not added. */
    public function markProcessed(VerificationMedia $media): VerificationMedia
    {
        $media->update(['state' => MediaState::Ready]);

        return $media->refresh();
    }

    /**
     * VID-06. Deletes the file, keeps the row.
     *
     * A `null` retention for a kind means the owner has not set a policy, and
     * such media is SKIPPED, never deleted on a guess (TODO(business) B11).
     */
    public function purgeExpired(): int
    {
        $purged = 0;

        VerificationMedia::whereNotNull('retention_until')
            ->where('retention_until', '<=', now()->toDateString())
            ->where('state', '!=', MediaState::Purged->value)
            ->chunkById(100, function ($rows) use (&$purged) {
                foreach ($rows as $media) {
                    $this->purge($media);
                    $purged++;
                }
            });

        return $purged;
    }

    public function purge(VerificationMedia $media): void
    {
        if ($media->path) {
            Storage::disk($media->disk)->delete($media->path);
        }

        $media->update([
            'state' => MediaState::Purged,
            'purged_at' => now(),
            // The row survives: the audit trail of the file having existed
            // must outlive the file itself.
            'path' => null,
        ]);

        AuditLogger::log(
            action: 'media.purged',
            resourceType: 'VerificationMedia',
            resourceId: $media->id,
            context: ['kind' => $media->kind, 'retention_until' => $media->retention_until?->toDateString()],
        );
    }

    private function retentionUntil(string $kind): ?Carbon
    {
        $days = config('verification.media.retention_days.'.$kind);

        return $days ? now()->addDays((int) $days) : null;
    }

    private function assertAllowed(UploadedFile $file, string $kind): void
    {
        $allowedMimes = config('verification.media.allowed_mimes.'.$kind);
        $maxKb = config('verification.media.max_size_kb.'.$kind);

        if (! $allowedMimes || ! $maxKb) {
            throw new \InvalidArgumentException('نوع فایل درخواستی پشتیبانی نمی‌شود.');
        }

        // getMimeType() sniffs the file contents, not the client-supplied
        // Content-Type header, so a renamed .php cannot pass as a video.
        if (! in_array((string) $file->getMimeType(), $allowedMimes, true)) {
            throw new \InvalidArgumentException('فرمت فایل ارسالی مجاز نیست.');
        }

        if ($file->getSize() > $maxKb * 1024) {
            throw new \InvalidArgumentException('حجم فایل ارسالی بیش از حد مجاز است.');
        }
    }
}
