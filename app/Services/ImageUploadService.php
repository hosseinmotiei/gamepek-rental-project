<?php

namespace App\Services;

use App\Exceptions\ImageUploadFailedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Single, shared "store and verify" primitive for every image upload in the
 * app (products, banners, categories, blog posts, home sections, quick
 * categories, trust badges, settings images). Enforces one invariant
 * everywhere, uniformly:
 *
 *   UPLOAD SUCCESS =
 *     1. store() did not return false/null/empty
 *     2. the returned relative path physically exists on the disk
 *        immediately afterward
 *   Anything else -> ImageUploadFailedException, and the caller MUST NOT
 *   write that path (or any path) to the database.
 *
 * store()'s own return value alone is not trusted -- on this host a write
 * can report success (a non-false path) without the file being reliably
 * visible immediately after, so existence is independently re-checked via
 * Storage::exists() before this is considered done.
 */
class ImageUploadService
{
    public function storeAndVerify(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $path = $file->store($directory, $disk);

        if (!$path || !Storage::disk($disk)->exists($path)) {
            Log::error('Image upload failed: file not verified on disk after store().', [
                'disk' => $disk,
                'directory' => $directory,
                'returned_path' => $path ?: '(store() returned false/empty)',
                'original_name' => $file->getClientOriginalName(),
                'client_mime' => $file->getClientMimeType(),
                'client_size' => $file->getSize(),
            ]);

            throw ImageUploadFailedException::diskWriteFailed();
        }

        return $path;
    }
}
