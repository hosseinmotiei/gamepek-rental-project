<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — GamePek
|--------------------------------------------------------------------------
| These routes are stateless JSON endpoints.
| Used by the frontend JavaScript for dynamic actions.
|
| Cart and waitlist actions are NOT here (BUG-098): they require a PHP
| session for guest-cart tracking, which this stateless "api" middleware
| group does not provide. The working, session-enabled equivalents are
| the /cart/* and /waitlist routes in routes/web.php, which is what the
| frontend actually calls.
*/

Route::middleware('throttle:60,1')->group(function () {

    // Product search autocomplete
    Route::get('/products/search', [ProductController::class, 'search']);
});

// Future Sanctum API (for mobile app integration)
// Route::middleware('auth:sanctum')->group(function () {
//     Route::apiResource('orders', OrderApiController::class)->only(['index', 'show']);
// });
