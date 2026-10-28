<?php

use App\Http\Controllers\Admin\AdminAliExpressController;
use App\Http\Controllers\Admin\AdminBankAccountController;
use App\Http\Controllers\Admin\AdminConversationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDisputeController;
use App\Http\Controllers\Admin\AdminLivestreamGiftingController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminVerificationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ContentModerationController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\AliExpressConnectController;
use App\Http\Controllers\AppSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EtsyConnectController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ShopifyFlutterController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\UserController;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

/*

| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome', ['page' => 'welcome']);
});

/*
|--------------------------------------------------------------------------
| Shopify OAuth Callback (Browser Redirect)
|--------------------------------------------------------------------------
| Shopify redirects the merchant's browser here after OAuth authorization.
| This must be a web route (not API) because Shopify performs a browser
| redirect, not an AJAX/API call.
|
| The callback completes the OAuth flow, saves the access token, and
| returns a Flutter-friendly JSON response or redirects to a deep link.
|--------------------------------------------------------------------------
*/

Route::get('/auth/shopify/callback', [ShopifyFlutterController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| AliExpress OAuth Callback (Browser Redirect)
|--------------------------------------------------------------------------
| AliExpress redirects the seller's browser here after authorization.
| The callback exchanges the code for tokens and returns a Flutter-friendly
| JSON response (same pattern as the Shopify callback).
|--------------------------------------------------------------------------
*/

Route::get('/auth/aliexpress/callback', [AliExpressConnectController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| Etsy OAuth Callback (Browser Redirect)
|--------------------------------------------------------------------------
| Etsy redirects the seller's browser here after OAuth authorization
| (Authorization Code + PKCE). The callback exchanges the code for tokens
| using the verifier bound inside the signed state, and returns a
| Flutter-friendly HTML success/error page with an app deep link.
| This URL must be registered EXACTLY in the Etsy developer console.
|--------------------------------------------------------------------------
*/

Route::get('/auth/etsy/callback', [EtsyConnectController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| Google Merchant Center product landing pages
|--------------------------------------------------------------------------
| These routes serve product pages that Google can crawl for Merchant Center
| free listings. They display product info and redirect to the app via deep
| link. The URL format matches what is sent in the product 'link' field.
*/
Route::get('/product/{product}', function (Product $product) {
    if (! $product->is_active) {
        abort(404, 'Product not available.');
    }

    $appUrl = "castilo://product/{$product->id}";
    $webUrl = "https://admin.castilo.co.uk/product/{$product->id}";

    // Return a crawlable HTML page that also provides the deep link
    return response()->view('product-landing', compact('product', 'appUrl', 'webUrl'))
        ->header('Content-Type', 'text/html')
        ->header('Cache-Control', 'public, max-age=3600');
})->name('product.landing');

/*

| Authenticated Web Panel (any logged-in user)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
    Route::post('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

/*

| Admin / Supervisor Panel (web-admin middleware with permission checks)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'web-admin'])->group(function () {

    // ─── Admin Dashboard (Analytics) ────────────────────────────────
    Route::middleware('web-admin:dashboard.view')->group(function () {
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    });

    // ─── Livestream Gifting CRUD ─────────────────────────────────────
    Route::prefix('admin/livestream-gifting')->group(function () {
        Route::get('/', [AdminLivestreamGiftingController::class, 'index'])->name('admin.livestream-gifting.index');
        Route::get('/create', [AdminLivestreamGiftingController::class, 'create'])->name('admin.livestream-gifting.create');
        Route::post('/', [AdminLivestreamGiftingController::class, 'store'])->name('admin.livestream-gifting.store');
        Route::get('/{id}/edit', [AdminLivestreamGiftingController::class, 'edit'])->name('admin.livestream-gifting.edit');
        Route::put('/{id}', [AdminLivestreamGiftingController::class, 'update'])->name('admin.livestream-gifting.update');
        Route::delete('/{id}', [AdminLivestreamGiftingController::class, 'destroy'])->name('admin.livestream-gifting.destroy');
        Route::post('/{id}/toggle', [AdminLivestreamGiftingController::class, 'toggleStatus'])->name('admin.livestream-gifting.toggle');
    });

    // ─── User Management (web) ──────────────────────────────────────
    Route::middleware('web-admin:users.view')->prefix('admin/users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('admin.users');
        Route::get('/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
    });

    Route::middleware('web-admin:users.suspend')->group(function () {
        Route::post('/admin/users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('admin.users.suspend');
        Route::post('/admin/users/{user}/activate', [AdminUserController::class, 'activate'])->name('admin.users.activate');
    });

    Route::middleware('web-admin:users.verify')->group(function () {
        Route::post('/admin/users/{user}/verify', [AdminUserController::class, 'verify'])->name('admin.users.verify');
    });

    Route::middleware('web-admin:users.delete')->group(function () {
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
    });

    // ─── Content Moderation ────────────────────────────────────────

    // Posts
    Route::middleware('web-admin:posts.view')->group(function () {
        Route::get('/admin/moderation/posts', [ContentModerationController::class, 'posts'])->name('admin.moderation.posts');
        Route::get('/admin/moderation/posts/{post}', [ContentModerationController::class, 'showPost'])->name('admin.moderation.posts.show');
    });
    Route::middleware('web-admin:posts.moderate')->group(function () {
        Route::post('/admin/moderation/posts/{post}/deactivate', [ContentModerationController::class, 'deactivatePost'])->name('admin.moderation.posts.deactivate');
        Route::post('/admin/moderation/posts/{post}/activate', [ContentModerationController::class, 'activatePost'])->name('admin.moderation.posts.activate');
    });

    // Products
    Route::middleware('web-admin:products.view')->group(function () {
        Route::get('/admin/moderation/products', [ContentModerationController::class, 'products'])->name('admin.moderation.products');
        Route::get('/admin/moderation/products/{product}', [ContentModerationController::class, 'showProduct'])->name('admin.moderation.products.show');
    });
    Route::middleware('web-admin:products.moderate')->group(function () {
        Route::post('/admin/moderation/products/{product}/deactivate', [ContentModerationController::class, 'deactivateProduct'])->name('admin.moderation.products.deactivate');
        Route::post('/admin/moderation/products/{product}/activate', [ContentModerationController::class, 'activateProduct'])->name('admin.moderation.products.activate');
    });

    // Services
    Route::middleware('web-admin:services.view')->group(function () {
        Route::get('/admin/moderation/services', [ContentModerationController::class, 'services'])->name('admin.moderation.services');
        Route::get('/admin/moderation/services/{service}', [ContentModerationController::class, 'showService'])->name('admin.moderation.services.show');
    });
    Route::middleware('web-admin:services.moderate')->group(function () {
        Route::post('/admin/moderation/services/{service}/deactivate', [ContentModerationController::class, 'deactivateService'])->name('admin.moderation.services.deactivate');
        Route::post('/admin/moderation/services/{service}/activate', [ContentModerationController::class, 'activateService'])->name('admin.moderation.services.activate');
    });

    // Events
    Route::middleware('web-admin:events.view')->group(function () {
        Route::get('/admin/moderation/events', [ContentModerationController::class, 'events'])->name('admin.moderation.events');
        Route::get('/admin/moderation/events/{event}', [ContentModerationController::class, 'showEvent'])->name('admin.moderation.events.show');
    });
    Route::middleware('web-admin:events.moderate')->group(function () {
        Route::post('/admin/moderation/events/{event}/deactivate', [ContentModerationController::class, 'deactivateEvent'])->name('admin.moderation.events.deactivate');
        Route::post('/admin/moderation/events/{event}/activate', [ContentModerationController::class, 'activateEvent'])->name('admin.moderation.events.activate');
    });

    // Jobs
    Route::middleware('web-admin:jobs.view')->group(function () {
        Route::get('/admin/moderation/jobs', [ContentModerationController::class, 'jobs'])->name('admin.moderation.jobs');
        Route::get('/admin/moderation/jobs/{job}', [ContentModerationController::class, 'showJob'])->name('admin.moderation.jobs.show');
    });
    Route::middleware('web-admin:jobs.moderate')->group(function () {
        Route::post('/admin/moderation/jobs/{job}/deactivate', [ContentModerationController::class, 'deactivateJob'])->name('admin.moderation.jobs.deactivate');
        Route::post('/admin/moderation/jobs/{job}/activate', [ContentModerationController::class, 'activateJob'])->name('admin.moderation.jobs.activate');
    });

    // Bookings
    Route::middleware('web-admin:bookings.view')->group(function () {
        Route::get('/admin/moderation/bookings', [ContentModerationController::class, 'bookings'])->name('admin.moderation.bookings');
        Route::get('/admin/moderation/bookings/{booking}', [ContentModerationController::class, 'showBooking'])->name('admin.moderation.bookings.show');
    });
    Route::middleware('web-admin:bookings.moderate')->group(function () {
        Route::post('/admin/moderation/bookings/{booking}/deactivate', [ContentModerationController::class, 'deactivateBooking'])->name('admin.moderation.bookings.deactivate');
        Route::post('/admin/moderation/bookings/{booking}/activate', [ContentModerationController::class, 'activateBooking'])->name('admin.moderation.bookings.activate');
    });

    // Job Applications
    Route::middleware('web-admin:job-applications.view')->group(function () {
        Route::get('/admin/moderation/job-applications', [ContentModerationController::class, 'jobApplications'])->name('admin.moderation.job-applications');
        Route::get('/admin/moderation/job-applications/{application}', [ContentModerationController::class, 'showJobApplication'])->name('admin.moderation.job-applications.show');
    });
    Route::middleware('web-admin:job-applications.moderate')->group(function () {
        Route::post('/admin/moderation/job-applications/{application}/deactivate', [ContentModerationController::class, 'deactivateJobApplication'])->name('admin.moderation.job-applications.deactivate');
        Route::post('/admin/moderation/job-applications/{application}/activate', [ContentModerationController::class, 'activateJobApplication'])->name('admin.moderation.job-applications.activate');
    });

    // Event Tickets
    Route::middleware('web-admin:tickets.view')->group(function () {
        Route::get('/admin/moderation/tickets', [ContentModerationController::class, 'tickets'])->name('admin.moderation.tickets');
        Route::get('/admin/moderation/tickets/{ticket}', [ContentModerationController::class, 'showTicket'])->name('admin.moderation.tickets.show');
    });
    Route::middleware('web-admin:tickets.moderate')->group(function () {
        Route::post('/admin/moderation/tickets/{ticket}/deactivate', [ContentModerationController::class, 'deactivateTicket'])->name('admin.moderation.tickets.deactivate');
        Route::post('/admin/moderation/tickets/{ticket}/activate', [ContentModerationController::class, 'activateTicket'])->name('admin.moderation.tickets.activate');
    });

    // ─── Order Management ──────────────────────────────────────────
    Route::middleware('web-admin:orders.view')->prefix('admin/orders')->name('admin.orders.')->group(function () {
        Route::get('/', [AdminOrderController::class, 'index'])->name('index');
        Route::get('/{order}', [AdminOrderController::class, 'show'])->name('show');
    });
    Route::middleware('web-admin:orders.manage')->group(function () {
        Route::post('/admin/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('admin.orders.update-status');
        Route::post('/admin/orders/{order}/refund', [AdminOrderController::class, 'refund'])->name('admin.orders.refund');
    });

    // ─── Dispute Management ────────────────────────────────────────
    Route::middleware('web-admin:disputes.view')->prefix('admin/disputes')->name('admin.disputes.')->group(function () {
        Route::get('/', [AdminDisputeController::class, 'index'])->name('index');
        Route::get('/{dispute}', [AdminDisputeController::class, 'show'])->name('show');
    });
    Route::middleware('web-admin:disputes.manage')->group(function () {
        Route::post('/admin/disputes/{dispute}/status', [AdminDisputeController::class, 'updateStatus'])->name('admin.disputes.update-status');
    });

    // ─── AliExpress Admin Panel ─────────────────────────────────────
    Route::middleware('web-admin:aliexpress.view')->prefix('admin/aliexpress')->name('admin.aliexpress.')->group(function () {
        Route::get('/', [AdminAliExpressController::class, 'index'])->name('index');
        Route::get('/imports', [AdminAliExpressController::class, 'imports'])->name('imports');
        Route::get('/imports/{import}', [AdminAliExpressController::class, 'importShow'])->name('imports.show')
            ->whereNumber('import');
        Route::get('/sync-logs', [AdminAliExpressController::class, 'syncLogs'])->name('sync-logs');
        Route::get('/api-logs', [AdminAliExpressController::class, 'apiLogs'])->name('api-logs');
        Route::get('/{account}', [AdminAliExpressController::class, 'show'])->name('show')->whereNumber('account');
    });

    Route::middleware('web-admin:aliexpress.manage')->group(function () {
        Route::post('/admin/aliexpress/{account}/disable', [AdminAliExpressController::class, 'disable'])
            ->name('admin.aliexpress.disable')->whereNumber('account');
        Route::post('/admin/aliexpress/{account}/reactivate', [AdminAliExpressController::class, 'reactivate'])
            ->name('admin.aliexpress.reactivate')->whereNumber('account');
        Route::post('/admin/aliexpress/{account}/refresh-token', [AdminAliExpressController::class, 'refreshToken'])
            ->name('admin.aliexpress.refresh-token')->whereNumber('account');
    });

    // ─── Conversations / Contact Messages ──────────────────────────
    Route::middleware('web-admin:conversations.view')->prefix('admin/conversations')->name('admin.conversations.')->group(function () {
        Route::get('/', [AdminConversationController::class, 'index'])->name('index');
        Route::get('/{conversation}', [AdminConversationController::class, 'show'])->name('show');
    });
    Route::middleware('web-admin:conversations.reply')->group(function () {
        Route::post('/admin/conversations/{conversation}/reply', [AdminConversationController::class, 'reply'])->name('admin.conversations.reply');
    });
    Route::middleware('web-admin:conversations.assign')->group(function () {
        Route::post('/admin/conversations/{conversation}/assign', [AdminConversationController::class, 'assignSupervisor'])->name('admin.conversations.assign');
    });

    // ─── Role & Permission Management ──────────────────────────────
    Route::prefix('admin/roles')->name('admin.roles.')->group(function () {
        Route::middleware('web-admin:roles.view|permissions.manage')->get('/', [RolePermissionController::class, 'index'])->name('index');

        Route::middleware('web-admin:permissions.manage')->group(function () {
            Route::get('/create', [RolePermissionController::class, 'create'])->name('create');
            Route::post('/', [RolePermissionController::class, 'store'])->name('store');
            Route::post('/regenerate', [RolePermissionController::class, 'regenerate'])->name('regenerate');
            Route::put('/sync-matrix', [RolePermissionController::class, 'syncMatrix'])->name('syncMatrix');
            Route::get('/{role}/edit', [RolePermissionController::class, 'edit'])->name('edit');
            Route::put('/{role}', [RolePermissionController::class, 'update'])->name('update');
            Route::get('/{role}/edit-name', [RolePermissionController::class, 'editName'])->name('edit-name');
            Route::put('/{role}/name', [RolePermissionController::class, 'updateName'])->name('update-name');
            Route::patch('/{role}/status', [RolePermissionController::class, 'updateStatus'])->name('update-status');
            Route::delete('/{role}', [RolePermissionController::class, 'destroy'])->name('destroy');
        });
    });

    Route::middleware('web-admin:roles.assign')->group(function () {
        Route::post('/admin/users/{user}/role', [RolePermissionController::class, 'assignRole'])->name('admin.roles.assign');
    });

    // ─── Audit Logs ───────────────────────────────────────────────
    Route::middleware('web-admin:audit.view')->group(function () {
        Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('admin.audit');
    });

    // ─── Users CRUD ──────────────────────────────────────────────
    Route::middleware('web-admin:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    });
    Route::middleware('web-admin:users.create')->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
    });
    Route::middleware('web-admin:users.edit')->group(function () {
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
    Route::middleware('web-admin:users.delete')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
    Route::middleware('web-admin:users.edit')->group(function () {
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');
        Route::post('/users/{user}/reject', [UserController::class, 'reject'])->name('users.reject');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
    });

    // ─── FAQ CRUD ─────────────────────────────────────────────────
    Route::middleware('web-admin:faq.manage')->group(function () {
        Route::get('/faq', [FaqController::class, 'index'])->name('faq.index');
        Route::get('/faq/create', [FaqController::class, 'create'])->name('faq.create');
        Route::post('/faq', [FaqController::class, 'store'])->name('faq.store');
        Route::get('/faq/{faq}', [FaqController::class, 'show'])->name('faq.show');
        Route::get('/faq/{faq}/edit', [FaqController::class, 'edit'])->name('faq.edit');
        Route::match(['put', 'patch'], '/faq/{faq}', [FaqController::class, 'update'])->name('faq.update');
        Route::delete('/faq/{faq}', [FaqController::class, 'destroy'])->name('faq.destroy');
        Route::patch('/faq/{faq}/toggle-status', [FaqController::class, 'toggleStatus'])->name('faq.toggleStatus');
    });

    // ─── Service Categories CRUD ───────────────────────────────────
    Route::middleware('web-admin:settings.manage')->group(function () {
        Route::get('/service-categories', [ServiceCategoryController::class, 'index'])->name('service-categories.index');
        Route::get('/service-categories/create', [ServiceCategoryController::class, 'create'])->name('service-categories.create');
        Route::post('/service-categories', [ServiceCategoryController::class, 'store'])->name('service-categories.store');
        Route::get('/service-categories/{category}', [ServiceCategoryController::class, 'show'])->name('service-categories.show');
        Route::get('/service-categories/{category}/edit', [ServiceCategoryController::class, 'edit'])->name('service-categories.edit');
        Route::match(['put', 'patch'], '/service-categories/{category}', [ServiceCategoryController::class, 'update'])->name('service-categories.update');
        Route::delete('/service-categories/{category}', [ServiceCategoryController::class, 'destroy'])->name('service-categories.destroy');
        Route::patch('/service-categories/{category}/toggle-status', [ServiceCategoryController::class, 'toggleStatus'])->name('service-categories.toggleStatus');
    });

    // ─── Product Categories CRUD ───────────────────────────────────
    Route::middleware('web-admin:settings.manage')->group(function () {
        Route::get('/product-categories', [ProductCategoryController::class, 'index'])->name('product-categories.index');
        Route::get('/product-categories/create', [ProductCategoryController::class, 'create'])->name('product-categories.create');
        Route::post('/product-categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
        Route::get('/product-categories/{category}', [ProductCategoryController::class, 'show'])->name('product-categories.show');
        Route::get('/product-categories/{category}/edit', [ProductCategoryController::class, 'edit'])->name('product-categories.edit');
        Route::match(['put', 'patch'], '/product-categories/{category}', [ProductCategoryController::class, 'update'])->name('product-categories.update');
        Route::delete('/product-categories/{category}', [ProductCategoryController::class, 'destroy'])->name('product-categories.destroy');
        Route::patch('/product-categories/{category}/toggle-status', [ProductCategoryController::class, 'toggleStatus'])->name('product-categories.toggleStatus');
    });

    // ─── Pages CRUD ───────────────────────────────────────────────
    Route::middleware('web-admin:pages.manage')->group(function () {
        Route::get('/page', [PageController::class, 'index'])->name('page.index');
        Route::get('/page/create', [PageController::class, 'create'])->name('page.create');
        Route::post('/page', [PageController::class, 'store'])->name('page.store');
        Route::get('/page/{page}', [PageController::class, 'show'])->name('page.show');
        Route::get('/page/{page}/edit', [PageController::class, 'edit'])->name('page.edit');
        Route::match(['put', 'patch'], '/page/{page}', [PageController::class, 'update'])->name('page.update');
        Route::delete('/page/{page}', [PageController::class, 'destroy'])->name('page.destroy');
        Route::patch('/page/{page}/toggle-status', [PageController::class, 'toggleStatus'])->name('page.toggleStatus');
    });

    // ─── Subscriptions CRUD ───────────────────────────────────────
    Route::middleware('web-admin:subscriptions.manage')->group(function () {
        Route::get('/subscription', [SubscriptionPlanController::class, 'index'])->name('subscription.index');
        Route::get('/subscription/create', [SubscriptionPlanController::class, 'create'])->name('subscription.create');
        Route::post('/subscription', [SubscriptionPlanController::class, 'store'])->name('subscription.store');
        Route::get('/subscription/{subscription}', [SubscriptionPlanController::class, 'show'])->name('subscription.show');
        Route::get('/subscription/{subscription}/edit', [SubscriptionPlanController::class, 'edit'])->name('subscription.edit');
        Route::match(['put', 'patch'], '/subscription/{subscription}', [SubscriptionPlanController::class, 'update'])->name('subscription.update');
        Route::delete('/subscription/{subscription}', [SubscriptionPlanController::class, 'destroy'])->name('subscription.destroy');
        Route::patch('/subscription/{subscription}/toggle-status', [SubscriptionPlanController::class, 'toggleStatus'])->name('subscription.toggleStatus');
    });

    // ─── App Settings ───────────────────────────────────────────
    Route::middleware('web-admin:settings.manage')->group(function () {
        Route::get('/settings', [AppSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [AppSettingsController::class, 'update'])->name('settings.update');
        Route::get('/settings/bank', [AppSettingsController::class, 'bankIndex'])->name('settings.bank.index');
        Route::post('/settings/bank', [AppSettingsController::class, 'bankUpdate'])->name('settings.bank.update');
    });

    // ─── Verification Management (Admin/Supervisor) ────────────────
    Route::prefix('admin/verifications')->name('admin.verifications.')->group(function () {
        Route::middleware('web-admin:verifications.view')->group(function () {
            Route::get('/', [AdminVerificationController::class, 'index'])->name('index');
            Route::get('/{submission}', [AdminVerificationController::class, 'show'])->name('show');
        });
        Route::middleware('web-admin:verifications.manage')->group(function () {
            Route::post('/{submission}/approve', [AdminVerificationController::class, 'approve'])->name('approve');
            Route::post('/{submission}/reject', [AdminVerificationController::class, 'reject'])->name('reject');
            Route::post('/{submission}/generate-payment', [AdminVerificationController::class, 'generatePaymentLink'])->name('generate-payment');
        });
    });

    // ─── Bank Accounts Moderation (Admin) ───────────────────────────
    Route::middleware('web-admin:verifications.view')->prefix('admin/bank-accounts')->name('admin.bank-accounts.')->group(function () {
        Route::get('/', [AdminBankAccountController::class, 'index'])->name('index');
        Route::post('/{type}/{id}/approve', [AdminBankAccountController::class, 'approve'])->name('approve');
        Route::post('/{type}/{id}/reject', [AdminBankAccountController::class, 'reject'])->name('reject');
    });
});

require __DIR__.'/auth.php';
