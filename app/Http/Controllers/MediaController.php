<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Fallback static-file server for the "public" disk.
 *
 * On this host, Apache's own static-file check (.htaccess's
 * `RewriteCond %{REQUEST_FILENAME} !-f`) has been observed to return a
 * false negative for files that physically exist -- confirmed via direct
 * comparison: files present since early deployment are served correctly
 * by Apache (never reach Laravel at all), while files created afterward
 * (including a plain re-copy of an already-working file placed at a new
 * path) fall through to this application and 404 despite existing on disk
 * with correct permissions. This points to a stale filesystem-existence
 * cache in front of Apache (commonly CageFS on CloudLinux+DirectAdmin
 * hosts) rather than anything wrong in this codebase.
 *
 * This route only ever receives a request when Apache's own rewrite has
 * ALREADY decided the file doesn't exist and forwarded to index.php -- for
 * every file Apache correctly recognizes as static, it serves it directly
 * and this controller is never invoked. So this changes nothing for the
 * files that already work; it's a safety net for whatever Apache's cache
 * hasn't caught up on, using PHP's own filesystem access (confirmed
 * reliable: same user, same disk, real-time, no caching layer involved).
 */
class MediaController extends Controller
{
    public function show(Request $request, string $path)
    {
        // Flysystem's local adapter already rejects path traversal, but
        // reject it explicitly here too before ever touching the disk.
        if (str_contains($path, '..')) {
            abort(404);
        }

        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
