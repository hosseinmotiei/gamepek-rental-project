<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AuditEventController as AdminAuditEventController;
use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Admin\HomeSectionController as AdminHomeSectionController;
use App\Http\Controllers\Admin\MenuController as AdminMenuController;
use App\Http\Controllers\Admin\MessageController as AdminMessageController;
use App\Http\Controllers\Admin\OperationController as AdminOperationController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ProductOptionController as AdminProductOptionController;
use App\Http\Controllers\Admin\QuickCategoryController as AdminQuickCategoryController;
use App\Http\Controllers\Admin\RentalApplicationController as AdminRentalApplicationController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\ShippingMethodController as AdminShippingMethodController;
use App\Http\Controllers\Admin\TrustBadgeController as AdminTrustBadgeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VerificationController as AdminVerificationController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MockPaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OwnerDeviceController;
use App\Http\Controllers\OwnerOperationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalApplicationController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

// ──────────────────────────────────────────────────────────────────────────────
// F3 fix: fallback static-file server for /storage/*. Only ever reached for
// a file Apache's own rewrite has already decided doesn't exist -- see
// MediaController's docblock. Every file Apache correctly serves statically
// never reaches this route at all, so this changes nothing for those.
// ──────────────────────────────────────────────────────────────────────────────
Route::get('/storage/{path}', [MediaController::class, 'show'])->where('path', '.*');

// ──────────────────────────────────────────────────────────────────────────────
// Public Routes
// ──────────────────────────────────────────────────────────────────────────────

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::view('/contact', 'contact')->name('contact');
Route::view('/terms', 'terms')->name('terms');
Route::view('/about', 'about')->name('about');

// Catalog. `products.*` route names are kept deliberately: renaming the
// catalog entity to a rental-specific one is a domain decision that has not
// been made yet, and churning route names now would only have to be redone.
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/search', [ProductController::class, 'searchPage'])->name('products.search');

// Cart (guest + authenticated)
Route::prefix('cart')->name('cart.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('index');
    Route::post('/add', [CartController::class, 'add'])->name('add');
    Route::patch('/items/{item}', [CartController::class, 'update'])->name('update');
    Route::delete('/items/{item}', [CartController::class, 'remove'])->name('remove');
    Route::post('/coupon', [CartController::class, 'applyCoupon'])->name('coupon.apply');
    Route::delete('/coupon', [CartController::class, 'removeCoupon'])->name('coupon.remove');
    Route::get('/count', [CartController::class, 'count'])->name('count');
    Route::get('/mini', [CartController::class, 'mini'])->name('mini');
});

// ──────────────────────────────────────────────────────────────────────────────
// Authentication Routes
// ──────────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->name('auth.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
    // Per-IP abuse throttle (BUG-038): independent of OtpService's own
    // per-mobile RateLimiter key, so it does not double-hit that limiter.
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])->name('send-otp')->middleware('throttle:10,5');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('verify-otp');
    Route::get('/complete-profile', [AuthController::class, 'showCompleteProfile'])->name('complete-profile')->middleware('auth');
    Route::post('/complete-profile', [AuthController::class, 'completeProfile'])->name('complete-profile.store')->middleware('auth');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');
});

// ──────────────────────────────────────────────────────────────────────────────
// Authenticated User Routes
// ──────────────────────────────────────────────────────────────────────────────

Route::middleware('auth')->group(function () {

    // Profile
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Orders
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/{orderNumber}', [OrderController::class, 'show'])->name('show');
        Route::post('/{order:order_number}/cancel', [OrderController::class, 'cancel'])->name('cancel');
    });

    // Messages (customer support)
    Route::prefix('messages')->name('messages.')->group(function () {
        Route::get('/', [MessageController::class, 'index'])->name('index');
        Route::get('/create', [MessageController::class, 'create'])->name('create');
        Route::post('/', [MessageController::class, 'store'])->name('store');
        Route::get('/{conversation}', [MessageController::class, 'show'])->name('show');
        Route::post('/{conversation}/reply', [MessageController::class, 'reply'])->name('reply');
    });

    // Addresses
    Route::prefix('addresses')->name('addresses.')->group(function () {
        Route::get('/', [AddressController::class, 'index'])->name('index');
        Route::post('/', [AddressController::class, 'store'])->name('store');
        Route::patch('/{address}', [AddressController::class, 'update'])->name('update');
        Route::delete('/{address}', [AddressController::class, 'destroy'])->name('destroy');
        Route::post('/{address}/default', [AddressController::class, 'setDefault'])->name('set-default');
    });

    // Checkout
    Route::prefix('checkout')->name('checkout.')->group(function () {
        Route::get('/shipping', [CheckoutController::class, 'shipping'])->name('shipping');
        Route::post('/place-order', [CheckoutController::class, 'placeOrder'])->name('place-order');
        Route::get('/payment/{order}', [CheckoutController::class, 'payment'])->name('payment');
        Route::post('/payment/{order}/start', [CheckoutController::class, 'startPayment'])->name('start-payment');
    });

    // ──────────────────────────────────────────────────────────────────────
    // Development gateway's stand-in bank page. Inside the auth group and
    // ownership-checked in the controller, which also 404s outside
    // local/testing. It exists so the mock payment outcome is recorded
    // SERVER-SIDE instead of being read out of the callback query string.
    // ──────────────────────────────────────────────────────────────────────
    Route::get('/payment/mock/{authority}', [MockPaymentController::class, 'show'])->name('payment.mock.confirm');
    Route::post('/payment/mock/{authority}', [MockPaymentController::class, 'confirm'])->name('payment.mock.confirm.post');

    // ──────────────────────────────────────────────────────────────────────
    // KYC level 2 and bank ownership (rental chain, stages 1-2).
    //
    // The throttles here are per-IP and independent of OtpService's own
    // per-mobile RateLimiter and of the provider-side rate limit enforced in
    // ProviderCallLogger -- three different abuse surfaces, three separate
    // budgets.
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('verification')->name('verification.')->group(function () {
        Route::get('/', [VerificationController::class, 'show'])->name('index');
        Route::post('/identity', [VerificationController::class, 'storeIdentity'])->middleware('throttle:verification-identity')->name('identity.store');
        Route::post('/identity/run', [VerificationController::class, 'runIdentityCheck'])->middleware('throttle:verification-identity')->name('identity.run');
        Route::post('/media', [VerificationController::class, 'storeMedia'])->middleware('throttle:verification-media')->name('media.store');

        // VID-04. `signed` proves we issued the link; the controller
        // additionally checks ownership, because a signed URL is a bearer
        // token that can be forwarded.
        Route::get('/media/{media}', [VerificationController::class, 'showMedia'])
            ->middleware('signed')
            ->name('media.show');

        Route::post('/bank-accounts', [BankAccountController::class, 'store'])->middleware('throttle:verification-bank')->name('bank.store');
        Route::post('/bank-accounts/{account}/verify', [BankAccountController::class, 'verify'])->middleware('throttle:verification-bank')->name('bank.verify');
    });

    // ──────────────────────────────────────────────────────────────────────
    // The rental chain itself.
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('rental/applications')->name('rental.applications.')->group(function () {
        Route::post('/', [RentalApplicationController::class, 'store'])->name('store');
        Route::get('/{application}', [RentalApplicationController::class, 'show'])->name('show');
        Route::post('/{application}/reserve', [RentalApplicationController::class, 'reserve'])->name('reserve');
        Route::post('/{application}/pay', [RentalApplicationController::class, 'pay'])->name('pay');
        Route::post('/{application}/guarantee', [RentalApplicationController::class, 'storeGuarantee'])->middleware('throttle:rental-guarantee')->name('guarantee');
        Route::get('/{application}/contract', [RentalApplicationController::class, 'contract'])->name('contract');
        Route::post('/{application}/contract/accept', [RentalApplicationController::class, 'acceptContract'])->name('contract.accept');

        // Its own throttle bucket, deliberately NOT the login OTP's: signing a
        // contract must not be able to exhaust the customer's login OTP
        // allowance and lock them out mid-signature.
        Route::post('/{application}/contract/sign/otp', [RentalApplicationController::class, 'requestSignatureOtp'])
            ->middleware('throttle:rental-signature-otp')
            ->name('contract.sign.otp');
        Route::post('/{application}/contract/sign', [RentalApplicationController::class, 'signContract'])
            ->middleware('throttle:rental-signature')
            ->name('contract.sign');
    });

    // ──────────────────────────────────────────────────────────────────────
    // Owner side of the fleet: a user's own devices.
    //
    // Authenticated by the same `auth` group as everything else -- there is no
    // second login. Owner capability is the presence of an Owner profile, and
    // every action is authorised server-side by DevicePolicy/OwnerPolicy, not
    // by which links the UI happens to render.
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('owner')->name('owner.')->group(function () {
        Route::get('/', [OwnerDeviceController::class, 'dashboard'])->name('dashboard');
        Route::post('/', [OwnerDeviceController::class, 'store'])->name('store');
        Route::get('/devices/create', [OwnerDeviceController::class, 'create'])->name('devices.create');
        Route::post('/devices', [OwnerDeviceController::class, 'storeDevice'])->name('devices.store');
        Route::get('/devices/{device}', [OwnerDeviceController::class, 'show'])->name('devices.show');
        Route::post('/devices/{device}/disable', [OwnerDeviceController::class, 'disable'])->name('devices.disable');

        // Pickups of this owner's own devices. Visibility plus one
        // confirmation; the operation itself is driven from the admin side.
        Route::get('/operations', [OwnerOperationController::class, 'index'])->name('operations.index');
        Route::get('/operations/{operation}', [OwnerOperationController::class, 'show'])->name('operations.show');
        Route::post('/operations/{operation}/acknowledge', [OwnerOperationController::class, 'acknowledgeCustody'])
            ->name('operations.acknowledge');
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// Payment Callback (No CSRF — whitelisted in bootstrap/app.php)
// ──────────────────────────────────────────────────────────────────────────────

Route::match(['GET', 'POST'], '/payment/callback', [CheckoutController::class, 'paymentCallback'])
    ->name('payment.callback');

// ──────────────────────────────────────────────────────────────────────────────
// Admin Routes
// ──────────────────────────────────────────────────────────────────────────────

Route::prefix('admin')->name('admin.')->group(function () {

    // Login. Enforced in AdminLoginController::showLogin() only: redirects
    // to the dashboard when the authenticated user already holds an admin
    // role. Deliberately not the generic 'guest' middleware -- since admin
    // and public users share the same auth guard, 'guest' would redirect
    // ANY authenticated user (not just admins) away from this page.
    Route::get('/login', [AdminLoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminLoginController::class, 'login'])->middleware('throttle:5,1')->name('login.post');

    // Protected admin area
    Route::middleware('admin')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/logout', [AdminLoginController::class, 'logout'])->name('logout');

        // ── Products ──────────────────────────────────────────────────────────
        Route::prefix('products')->name('products.')->group(function () {
            Route::get('/', [AdminProductController::class, 'index'])->name('index');
            Route::get('/create', [AdminProductController::class, 'create'])->name('create');
            Route::post('/', [AdminProductController::class, 'store'])->name('store');
            Route::get('/{product}', [AdminProductController::class, 'show'])->name('show');
            Route::get('/{product}/edit', [AdminProductController::class, 'edit'])->name('edit');
            Route::put('/{product}', [AdminProductController::class, 'update'])->name('update');
            Route::delete('/{product}', [AdminProductController::class, 'destroy'])->name('destroy');
            Route::patch('/{product}/toggle', [AdminProductController::class, 'toggle'])->name('toggle');
            Route::delete('/{product}/gallery-image', [AdminProductController::class, 'removeGalleryImage'])->name('gallery.remove');

            Route::post('/{product}/options', [AdminProductOptionController::class, 'storeGroup'])->name('options.groups.store');
            Route::delete('/{product}/options/{group}', [AdminProductOptionController::class, 'destroyGroup'])->name('options.groups.destroy');
            Route::post('/{product}/options/{group}/values', [AdminProductOptionController::class, 'storeValue'])->name('options.values.store');
            Route::delete('/{product}/options/{group}/values/{value}', [AdminProductOptionController::class, 'destroyValue'])->name('options.values.destroy');
        });

        // ── Categories ────────────────────────────────────────────────────────
        Route::prefix('categories')->name('categories.')->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index'])->name('index');
            Route::get('/create', [AdminCategoryController::class, 'create'])->name('create');
            Route::post('/', [AdminCategoryController::class, 'store'])->name('store');
            Route::get('/{category}/edit', [AdminCategoryController::class, 'edit'])->name('edit');
            Route::put('/{category}', [AdminCategoryController::class, 'update'])->name('update');
            Route::delete('/{category}', [AdminCategoryController::class, 'destroy'])->name('destroy');
            Route::patch('/{category}/toggle', [AdminCategoryController::class, 'toggle'])->name('toggle');
        });

        // ── Orders ────────────────────────────────────────────────────────────
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index'])->name('index');
            Route::get('/{order}', [AdminOrderController::class, 'show'])->name('show');
            Route::patch('/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('status');
            Route::post('/{order}/note', [AdminOrderController::class, 'saveNote'])->name('note');
        });

        // ── Messages ──────────────────────────────────────────────────────────
        Route::prefix('messages')->name('messages.')->group(function () {
            Route::get('/', [AdminMessageController::class, 'index'])->name('index');
            Route::get('/{conversation}', [AdminMessageController::class, 'show'])->name('show');
            Route::post('/{conversation}/reply', [AdminMessageController::class, 'reply'])->name('reply');
            Route::post('/{conversation}/close', [AdminMessageController::class, 'close'])->name('close');
            Route::post('/{conversation}/reopen', [AdminMessageController::class, 'reopen'])->name('reopen');
        });

        // ── Payments ──────────────────────────────────────────────────────────
        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('/', [AdminPaymentController::class, 'index'])->name('index');
            Route::get('/{transaction}', [AdminPaymentController::class, 'show'])->name('show');
            Route::post('/{transaction}/approve', [AdminPaymentController::class, 'approve'])->name('approve');
            Route::post('/{transaction}/reject', [AdminPaymentController::class, 'reject'])->name('reject');
        });

        // ── Wallet ────────────────────────────────────────────────────────────
        Route::prefix('wallet')->name('wallet.')->group(function () {
            Route::get('/', [AdminWalletController::class, 'index'])->name('index');
        });

        // ── Users ─────────────────────────────────────────────────────────────
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])->name('index');
            Route::get('/{user}', [AdminUserController::class, 'show'])->name('show');
            Route::patch('/{user}/toggle-block', [AdminUserController::class, 'toggleBlock'])->name('toggle-block');
            Route::patch('/{user}/role', [AdminUserController::class, 'updateRole'])->name('update-role');
            Route::patch('/{user}/credentials', [AdminUserController::class, 'updateCredentials'])->name('update-credentials');
        });

        // ── Settings ──────────────────────────────────────────────────────────
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [AdminSettingsController::class, 'index'])->name('index');
            Route::post('/clear-cache', [AdminSettingsController::class, 'clearCache'])->name('clear-cache');
            Route::get('/{group}', [AdminSettingsController::class, 'show'])->name('show');
            Route::put('/{group}', [AdminSettingsController::class, 'update'])->name('update');
        });

        // ── Banners ───────────────────────────────────────────────────────────
        Route::prefix('banners')->name('banners.')->group(function () {
            Route::get('/', [AdminBannerController::class, 'index'])->name('index');
            Route::get('/create', [AdminBannerController::class, 'create'])->name('create');
            Route::post('/', [AdminBannerController::class, 'store'])->name('store');
            Route::get('/{banner}/edit', [AdminBannerController::class, 'edit'])->name('edit');
            Route::put('/{banner}', [AdminBannerController::class, 'update'])->name('update');
            Route::delete('/{banner}', [AdminBannerController::class, 'destroy'])->name('destroy');
            Route::patch('/{banner}/toggle', [AdminBannerController::class, 'toggle'])->name('toggle');
        });

        // ── Home Sections ─────────────────────────────────────────────────────
        Route::prefix('home-sections')->name('home-sections.')->group(function () {
            Route::get('/', [AdminHomeSectionController::class, 'index'])->name('index');
            Route::get('/create', [AdminHomeSectionController::class, 'create'])->name('create');
            Route::post('/', [AdminHomeSectionController::class, 'store'])->name('store');
            Route::get('/{homeSection}/edit', [AdminHomeSectionController::class, 'edit'])->name('edit');
            Route::put('/{homeSection}', [AdminHomeSectionController::class, 'update'])->name('update');
            Route::delete('/{homeSection}', [AdminHomeSectionController::class, 'destroy'])->name('destroy');
            Route::patch('/{homeSection}/toggle', [AdminHomeSectionController::class, 'toggle'])->name('toggle');
        });

        // ── Quick Categories ──────────────────────────────────────────────────
        Route::prefix('quick-categories')->name('quick-categories.')->group(function () {
            Route::get('/', [AdminQuickCategoryController::class, 'index'])->name('index');
            Route::get('/create', [AdminQuickCategoryController::class, 'create'])->name('create');
            Route::post('/', [AdminQuickCategoryController::class, 'store'])->name('store');
            Route::get('/{quickCategory}/edit', [AdminQuickCategoryController::class, 'edit'])->name('edit');
            Route::put('/{quickCategory}', [AdminQuickCategoryController::class, 'update'])->name('update');
            Route::delete('/{quickCategory}', [AdminQuickCategoryController::class, 'destroy'])->name('destroy');
            Route::patch('/{quickCategory}/toggle', [AdminQuickCategoryController::class, 'toggle'])->name('toggle');
        });

        // ── Trust Badges ──────────────────────────────────────────────────────
        Route::prefix('trust-badges')->name('trust-badges.')->group(function () {
            Route::get('/', [AdminTrustBadgeController::class, 'index'])->name('index');
            Route::get('/create', [AdminTrustBadgeController::class, 'create'])->name('create');
            Route::post('/', [AdminTrustBadgeController::class, 'store'])->name('store');
            Route::get('/{trustBadge}/edit', [AdminTrustBadgeController::class, 'edit'])->name('edit');
            Route::put('/{trustBadge}', [AdminTrustBadgeController::class, 'update'])->name('update');
            Route::delete('/{trustBadge}', [AdminTrustBadgeController::class, 'destroy'])->name('destroy');
            Route::patch('/{trustBadge}/toggle', [AdminTrustBadgeController::class, 'toggle'])->name('toggle');
        });

        // ── Menus ─────────────────────────────────────────────────────────────
        Route::prefix('menus')->name('menus.')->group(function () {
            Route::get('/', [AdminMenuController::class, 'index'])->name('index');
            Route::get('/create', [AdminMenuController::class, 'create'])->name('create');
            Route::post('/', [AdminMenuController::class, 'store'])->name('store');
            Route::get('/{menu}/edit', [AdminMenuController::class, 'edit'])->name('edit');
            Route::put('/{menu}', [AdminMenuController::class, 'update'])->name('update');
            Route::delete('/{menu}', [AdminMenuController::class, 'destroy'])->name('destroy');
            Route::patch('/{menu}/toggle', [AdminMenuController::class, 'toggle'])->name('toggle');
        });

        // ── Shipping Methods ──────────────────────────────────────────────────
        Route::prefix('shipping-methods')->name('shipping-methods.')->group(function () {
            Route::get('/', [AdminShippingMethodController::class, 'index'])->name('index');
            Route::get('/create', [AdminShippingMethodController::class, 'create'])->name('create');
            Route::post('/', [AdminShippingMethodController::class, 'store'])->name('store');
            Route::get('/{shippingMethod}/edit', [AdminShippingMethodController::class, 'edit'])->name('edit');
            Route::put('/{shippingMethod}', [AdminShippingMethodController::class, 'update'])->name('update');
            Route::delete('/{shippingMethod}', [AdminShippingMethodController::class, 'destroy'])->name('destroy');
        });

        // ── Coupons ───────────────────────────────────────────────────────────
        Route::prefix('coupons')->name('coupons.')->group(function () {
            Route::get('/', [AdminCouponController::class, 'index'])->name('index');
            Route::get('/create', [AdminCouponController::class, 'create'])->name('create');
            Route::post('/', [AdminCouponController::class, 'store'])->name('store');
            Route::get('/{coupon}/edit', [AdminCouponController::class, 'edit'])->name('edit');
            Route::put('/{coupon}', [AdminCouponController::class, 'update'])->name('update');
            Route::delete('/{coupon}', [AdminCouponController::class, 'destroy'])->name('destroy');
        });

        // ── Reports ───────────────────────────────────────────────────────────
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [AdminReportController::class, 'dashboard'])->name('index');
            Route::get('/sales', [AdminReportController::class, 'sales'])->name('sales');
            Route::get('/sales/export', [AdminReportController::class, 'salesExport'])->name('sales.export');
            Route::get('/orders', [AdminReportController::class, 'orders'])->name('orders');
            Route::get('/orders/export', [AdminReportController::class, 'ordersExport'])->name('orders.export');
            Route::get('/products', [AdminReportController::class, 'products'])->name('products');
            Route::get('/products/export', [AdminReportController::class, 'productsExport'])->name('products.export');
            Route::get('/categories', [AdminReportController::class, 'categories'])->name('categories');
            Route::get('/users', [AdminReportController::class, 'users'])->name('users');
            Route::get('/users/export', [AdminReportController::class, 'usersExport'])->name('users.export');
            Route::get('/low-stock', [AdminReportController::class, 'lowStock'])->name('low-stock');
            Route::get('/low-stock/export', [AdminReportController::class, 'lowStockExport'])->name('low-stock.export');
        });

        // ── Rental chain (stages 1-3) ─────────────────────────────────────────
        Route::prefix('verifications')->name('verifications.')->group(function () {
            Route::get('/', [AdminVerificationController::class, 'index'])->name('index');
            Route::get('/{verification}', [AdminVerificationController::class, 'show'])->name('show');
            Route::post('/{verification}/approve', [AdminVerificationController::class, 'approve'])->name('approve');
            Route::post('/{verification}/reject', [AdminVerificationController::class, 'reject'])->name('reject');
        });

        Route::prefix('rental-applications')->name('rental-applications.')->group(function () {
            Route::get('/', [AdminRentalApplicationController::class, 'index'])->name('index');
            Route::get('/{rentalApplication}', [AdminRentalApplicationController::class, 'show'])->name('show');
            Route::post('/{rentalApplication}/refresh', [AdminRentalApplicationController::class, 'refresh'])->name('refresh');
            Route::post('/{rentalApplication}/approve', [AdminRentalApplicationController::class, 'approve'])->name('approve');
            Route::post('/{rentalApplication}/reject', [AdminRentalApplicationController::class, 'reject'])->name('reject');
            Route::post('/{rentalApplication}/guarantee/verify', [AdminRentalApplicationController::class, 'verifyGuarantee'])->name('guarantee.verify');
            Route::post('/{rentalApplication}/guarantee/reject', [AdminRentalApplicationController::class, 'rejectGuarantee'])->name('guarantee.reject');
            Route::post('/{rentalApplication}/contract/void', [AdminRentalApplicationController::class, 'voidContract'])->name('contract.void');
        });

        Route::get('/audit-events', [AdminAuditEventController::class, 'index'])->name('audit-events.index');

        // ──────────────────────────────────────────────────────────────────
        // Physical fleet: owners and devices.
        //
        // Review and inspection only. Pickup, inspection, delivery, return and
        // settlement screens belong to the operations phase.
        // ──────────────────────────────────────────────────────────────────
        Route::prefix('owners')->name('owners.')->group(function () {
            Route::get('/', [AdminDeviceController::class, 'owners'])->name('index');
            Route::get('/{owner}', [AdminDeviceController::class, 'showOwner'])->name('show');
        });

        Route::prefix('devices')->name('devices.')->group(function () {
            Route::get('/', [AdminDeviceController::class, 'index'])->name('index');
            // GamePek's own stock: no owner account is involved.
            Route::post('/gamepek', [AdminDeviceController::class, 'storeGamePekDevice'])->name('gamepek.store');
            Route::get('/{device}', [AdminDeviceController::class, 'show'])->name('show');
            Route::post('/{device}/approve', [AdminDeviceController::class, 'approve'])->name('approve');
            Route::post('/{device}/reject', [AdminDeviceController::class, 'reject'])->name('reject');
            Route::get('/{device}/custody', [AdminOperationController::class, 'custodyHistory'])->name('custody');
        });

        // ──────────────────────────────────────────────────────────────────
        // Physical operations: owner device pickup and custody.
        //
        // Pickup only. Inspection, delivery, customer return, owner return
        // and settlement belong to later phases and have no routes here.
        // ──────────────────────────────────────────────────────────────────
        Route::prefix('operations')->name('operations.')->group(function () {
            Route::get('/', [AdminOperationController::class, 'index'])->name('index');
            Route::get('/{operation}', [AdminOperationController::class, 'show'])->name('show');
            Route::post('/{operation}/device', [AdminOperationController::class, 'attachDevice'])->name('device');
            Route::post('/{operation}/schedule', [AdminOperationController::class, 'schedule'])->name('schedule');
            Route::post('/{operation}/start', [AdminOperationController::class, 'start'])->name('start');
            Route::post('/{operation}/custody', [AdminOperationController::class, 'recordCustody'])->name('custody');
            Route::post('/{operation}/fail', [AdminOperationController::class, 'fail'])->name('fail');
        });

        // ── Activity Logs ─────────────────────────────────────────────────────
        Route::prefix('activity-logs')->name('activity-logs.')->group(function () {
            Route::get('/', [AdminActivityLogController::class, 'index'])->name('index');
            Route::get('/{activityLog}', [AdminActivityLogController::class, 'show'])->name('show');
        });
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// Fallback
// ──────────────────────────────────────────────────────────────────────────────

Route::fallback(function () {
    abort(404);
});
