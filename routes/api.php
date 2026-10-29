<?php

use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\AliExpressConnectController;
use App\Http\Controllers\AliExpressFulfillmentController;
use App\Http\Controllers\AliExpressImportController;
use App\Http\Controllers\AliExpressProductBrowseController;
use App\Http\Controllers\AppSettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BuyerBankAccountController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\EscrowController;
use App\Http\Controllers\EtsyConnectController;
use App\Http\Controllers\EtsyImportController;
use App\Http\Controllers\EtsyProductBrowseController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\HomepageController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ImportOptionsController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\LivestreamController;
use App\Http\Controllers\LivestreamInteractionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderFulfillmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductTextImprovementController;
use App\Http\Controllers\ProductUgcVideoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SavedAddressController;
use App\Http\Controllers\SavedItemController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShipEngineWebhookController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShopifyFlutterController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TermsGuidelinesController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WorkHistoryController;
use Illuminate\Support\Facades\Route;

// ═════════════════════════════════════════════════════════════════════
//  UNAUTHENTICATED & PUBLIC ENDPOINTS
// ═════════════════════════════════════════════════════════════════════

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login']);

Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::post('/forget-password', [AuthController::class, 'forgetPassword'])->middleware('throttle:5,1');
Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:5,1');
Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:5,1');
Route::post('/social-login', [AuthController::class, 'socialLogin'])->middleware('throttle:5,1');

Route::get('/countries', [CountryController::class, 'index']);
Route::get('/service-categories/active', [ServiceCategoryController::class, 'getActive']);
Route::get('/product-categories/active', [ProductCategoryController::class, 'getActive']);
Route::get('/faqs', [FaqController::class, 'apiIndex']);
Route::get('/app-settings', [AppSettingsController::class, 'apiIndex']);

// ── Webhooks ─────────────────────────────────────────────────────
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

//  ── SHOPIFY ─────────────────────────────────────────────────────
Route::post('/webhooks/shopify/{topic}', [ShopifyWebhookController::class, 'handle']);
Route::post('/webhooks/shopify/app/uninstalled', [ShopifyWebhookController::class, 'handleAppUninstalled']);
Route::post('/webhooks/shopify/inventory/update', [ShopifyWebhookController::class, 'handleInventoryUpdate']);

// ShipEngine tracking webhook (public; authenticated via shared-secret header
// when SHIPENGINE_WEBHOOK_SECRET is set — mirrors the Stripe webhook pattern).
Route::post('/webhooks/shipengine/tracking', [ShipEngineWebhookController::class, 'tracking']);

// Label download/preview — public so browser and Flutter can open URL directly without Bearer token
Route::get('/shipping/download-label', [ShipmentController::class, 'downloadLabel'])->name('shipping.shipments.label')->middleware('throttle:60,1'); // ?label_id=&download=1 or &preview=1

// ═════════════════════════════════════════════════════════════════════
//  AUTHENTICATED ENDPOINTS
// ═════════════════════════════════════════════════════════════════════

Route::middleware(['auth:jwt'])->group(function () {

    // ─── Import Options (modal data for Flutter) ───────────────────
    Route::get('/import/options', [ImportOptionsController::class, 'index']);

    // ─── Auth ───────────────────────────────────────────────────────
    Route::post('/password-change', [AuthController::class, 'passwordChange']);
    Route::post('/password-change/verify', [AuthController::class, 'passwordVerifyOtp']);
    Route::post('/password-change/update', [AuthController::class, 'passwordUpdate']);
    Route::post('/email-change', [AuthController::class, 'emailChange']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // ─── Homepage & Search ──────────────────────────────────────────
    Route::get('/homepage', [HomepageController::class, 'index']);
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/users', [UserController::class, 'apiIndex']);
    Route::get('/user/search', [UserController::class, 'search']);

    Route::get('/contact', [ContactController::class, 'index']);
    Route::post('/contact', [ContactController::class, 'send']);

    // ─── Settings / Privacy ─────────────────────────────────────────
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index']);                // All settings
        Route::get('/privacy', [SettingsController::class, 'privacy']);       // Privacy settings
        Route::put('/privacy', [SettingsController::class, 'updatePrivacy']); // Update privacy
    });

    // ─── Posts ───────────────────────────────────────────────────────
    Route::prefix('posts')->group(function () {
        Route::get('/', [PostController::class, 'index']);                       // ?page, per_page, sort, author_id
        Route::post('/', [PostController::class, 'store']);                      // content, image, video, media[], attached_item_type, attached_item_id, gif_url, emoji
        Route::get('/{post}', [PostController::class, 'show']);
        Route::get('/{post}/comments', [PostController::class, 'comments']);     // ?page, per_page
        Route::match(['put', 'patch'], '/{post}', [PostController::class, 'update']);
        Route::delete('/{post}', [PostController::class, 'destroy']);
        Route::post('/{post}/like', [PostController::class, 'like']);
        Route::post('/{post}/comment', [PostController::class, 'comment']);      // content
        Route::post('/{post}/comment/{comment}/reply', [PostController::class, 'reply']); // content
        Route::post('/{post}/comment/{comment}/react', [PostController::class, 'react']); // reaction (like, love, haha, wow, sad, angry)
        Route::match(['put', 'patch'], '/{post}/comment/{comment}', [PostController::class, 'updateComment']);
        Route::delete('/{post}/comment/{comment}', [PostController::class, 'deleteComment']);
        Route::post('/{post}/save', [PostController::class, 'save']);
        Route::post('/{post}/share', [PostController::class, 'share']);
        Route::post('/{post}/reshare', [PostController::class, 'reshare']);
        Route::post('/{post}/remix', [PostController::class, 'remix']);          // video, content, image
        Route::get('/{post}/remixes', [PostController::class, 'remixes']);        // ?page, per_page (List all remixes of this reel)
        Route::post('/{post}/report', [PostController::class, 'report']);
    });

    // ─── Stories ─────────────────────────────────────────────────────
    Route::prefix('stories')->group(function () {
        Route::get('/', [StoryController::class, 'index']);                      // ?page, per_page
        Route::get('/mine', [StoryController::class, 'mine']);
        Route::get('/{story}', [StoryController::class, 'show']);
        Route::post('/', [StoryController::class, 'store']);
        Route::delete('/{story}', [StoryController::class, 'destroy']);
        Route::get('/user/{profile}', [StoryController::class, 'userStories']);
        Route::get('/{story}/views', [StoryController::class, 'views']);
        Route::get('/{story}/reactions', [StoryController::class, 'reactions']);
        Route::match(['post', 'delete'], '/{story}/react', [StoryController::class, 'react']);
        Route::post('/{story}/view', [StoryController::class, 'markViewed']);
    });

    // ─── Livestreams ─────────────────────────────────────────────────
    Route::prefix('livestreams')->group(function () {
        Route::get('/live', [LivestreamController::class, 'live']);              // ?page, per_page
        Route::get('/mine', [LivestreamController::class, 'mine']);              // ?page, per_page
        Route::post('/start', [LivestreamController::class, 'start']);
        Route::get('/{livestream}', [LivestreamController::class, 'show']);
        Route::post('/{livestream}/stop', [LivestreamController::class, 'stop']);
        Route::post('/{livestream}/items', [LivestreamController::class, 'addItem']);
        Route::delete('/{livestream}/items/{item}', [LivestreamController::class, 'removeItem']);

        // ── Interactions ─────────────────────────────────────────
        Route::get('/{livestream}/comments', [LivestreamInteractionController::class, 'comments']);
        Route::post('/{livestream}/comments', [LivestreamInteractionController::class, 'sendComment']);
        Route::get('/{livestream}/gifts', [LivestreamInteractionController::class, 'gifts']);
        Route::post('/{livestream}/gifts', [LivestreamInteractionController::class, 'sendGift']);
        Route::get('/{livestream}/join-requests', [LivestreamInteractionController::class, 'joinRequests']);
        Route::post('/{livestream}/join', [LivestreamInteractionController::class, 'requestToJoin']);
        Route::post('/{livestream}/join-requests/{joinRequest}', [LivestreamInteractionController::class, 'respondToJoinRequest']);
        Route::post('/{livestream}/invite', [LivestreamInteractionController::class, 'invite']);
        Route::post('/{livestream}/invites/{invite}/respond', [LivestreamInteractionController::class, 'respondToInvite']);
        Route::get('/{livestream}/engagement', [LivestreamInteractionController::class, 'engagement']);
    });

    // ─── Product Categories ────────────────────────────────────────────
    Route::prefix('product-categories')->group(function () {
        Route::get('/', [ProductCategoryController::class, 'index']);
        Route::post('/', [ProductCategoryController::class, 'store']);
        Route::get('/{productCategory}', [ProductCategoryController::class, 'show']);
        Route::match(['put', 'patch'], '/{productCategory}', [ProductCategoryController::class, 'update']);
        Route::delete('/{productCategory}', [ProductCategoryController::class, 'destroy']);
    });

    // ─── Products (Storefront Catalog & CRUD) ────────────────────────
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);                   // ?page, per_page, category (or category_id), condition, min_price, max_price, min_rating, search, sort (newest, price_low_high, price_high_low, highest_rated, most_popular), tag, wholesale_only (or wholesale)
        Route::get('/mine', [ProductController::class, 'mine']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::get('/{product}/shipping-options', [ProductController::class, 'shippingOptions']); // Buyer: seller-enabled shipping options for this product
        Route::match(['put', 'patch'], '/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::patch('/{product}/status', [ProductController::class, 'toggleStatus']); // Toggle active/inactive
    });

    // ─── AI Product Image, Text & Video Improvers ─────────────────────
    Route::post('/product-image-improver', [ImageController::class, 'improveProductImage']);
    Route::post('/product-text-improver', [ProductTextImprovementController::class, 'improveProductText']);
    Route::post('/product-ugc-video', [ProductUgcVideoController::class, 'generateUgcVideo']);

    //  ── SHOPIFY — (OAuth + Import + Fulfillment) ─────────────────────────────────────────────────────
    Route::prefix('shopify')->group(function () {
        // ── OAuth ─────────────────────────────────────────────────────
        Route::post('/connect', [ShopifyFlutterController::class, 'connect']);          // Step 1: Get OAuth URL
        Route::post('/reconnect', [ShopifyFlutterController::class, 'reconnect']);      // Reconnect store
        Route::post('/disconnect', [ShopifyFlutterController::class, 'disconnect']);    // Disconnect store
        Route::get('/stores', [ShopifyFlutterController::class, 'stores']);             // List connected stores
        Route::get('/stores/check', [ShopifyFlutterController::class, 'checkStore']);   // Check if connected
        Route::get('/status', [ShopifyFlutterController::class, 'status']);             // Dashboard stats

        // ── Store-specific ────────────────────────────────────────────
        Route::prefix('stores/{store}')->group(function () {
            Route::get('/products', [ShopifyFlutterController::class, 'products']);             // Preview products
            Route::get('/health', [ShopifyFlutterController::class, 'health']);                 // Store health status
            Route::post('/refresh-token', [ShopifyFlutterController::class, 'refreshToken']);   // Refresh OAuth token
            Route::get('/webhook-metrics', [ShopifyFlutterController::class, 'webhookMetrics']); // Webhook metrics
        });

        // ── Import ────────────────────────────────────────────────────
        Route::post('/import', [ShopifyFlutterController::class, 'import']);                    // Import selected products
        Route::get('/import/history', [ShopifyFlutterController::class, 'importHistory']);      // Import history
        Route::get('/import/{importId}', [ShopifyFlutterController::class, 'importDetail'])     // Import details with logs
            ->whereNumber('importId');

        // ── Import Lifecycle (Cancel / Resume / Retry / Progress) ─────
        Route::post('/import/{importId}/cancel', [ShopifyFlutterController::class, 'cancel'])
            ->whereNumber('importId');                                                         // Cancel an import
        Route::post('/import/{importId}/resume', [ShopifyFlutterController::class, 'resume'])
            ->whereNumber('importId');                                                         // Resume interrupted import
        Route::post('/import/{importId}/retry', [ShopifyFlutterController::class, 'retry'])
            ->whereNumber('importId');                                                         // Retry failed/partial import
        Route::get('/import/{importId}/progress', [ShopifyFlutterController::class, 'importProgress'])
            ->whereNumber('importId');                                                         // Detailed progress

        // ── Store Settings ────────────────────────────────────────────
        Route::prefix('stores/{store}')->group(function () {
            Route::put('/settings', [ShopifyFlutterController::class, 'updateSettings']);       // Update sync policies
        });

        // ── Imported Products ─────────────────────────────────────────
        Route::get('/products', [ShopifyFlutterController::class, 'importedProducts']);          // All imported products
    });

    // ── ETSY — (API-key Product Import) ─────────────────────────────────────────────────────
    Route::prefix('etsy')->group(function () {
        // ── OAuth ─────────────────────────────────────────────────────
        Route::post('/connect', [EtsyConnectController::class, 'connect']);          // Step 1: Get OAuth URL (PKCE)
        Route::post('/reconnect', [EtsyConnectController::class, 'reconnect']);      // Reconnect account
        Route::post('/disconnect', [EtsyConnectController::class, 'disconnect']);    // Disconnect account
        Route::get('/account/status', [EtsyConnectController::class, 'check']);      // Check if connected
        Route::post('/refresh-token', [EtsyConnectController::class, 'refreshToken']);

        // ── Browse (Open API v3 listings, seller-connected) ─────
        Route::get('/products', [EtsyProductBrowseController::class, 'index']);              // ?keyword       = ?limit= ?offset=
        Route::get('/products/imported', [EtsyImportController::class, 'importedProducts']); // ?search        =&is_active=&per_page=
        Route::get('/products/{listingId}', [EtsyProductBrowseController::class, 'show'])->whereNumber('listingId');

        // ── Import ────────────────────────────────────────────────
        Route::post('/import', [EtsyImportController::class, 'import']);
        Route::get('/import/history', [EtsyImportController::class, 'history']);
        Route::get('/import/{importId}', [EtsyImportController::class, 'detail'])->whereNumber('importId');
        Route::post('/import/{importId}/cancel', [EtsyImportController::class, 'cancel'])->whereNumber('importId');
    });

    // ── CSV PRODUCT IMPORT ─────────────────────────────────────────────────────
    Route::prefix('csv')->group(function () {
        Route::post('/import', [CsvImportController::class, 'import']);                   // Upload CSV + start background import
        Route::get('/import/history', [CsvImportController::class, 'history']);           // Import history (?page, per_page)
        Route::get('/import/{importId}', [CsvImportController::class, 'detail'])->whereNumber('importId');
        Route::post('/import/{importId}/cancel', [CsvImportController::class, 'cancel'])->whereNumber('importId');
        Route::get('/products', [CsvImportController::class, 'importedProducts']);        // All imported CSV products
    });

    // ── ALIEXPRESS — (OAuth + Import + Fulfillment) ─────────────────────────────────────────────────────
    Route::prefix('aliexpress')->group(function () {
        // ── OAuth ─────────────────────────────────────────────────────
        Route::post('/connect', [AliExpressConnectController::class, 'connect']);
        Route::post('/reconnect', [AliExpressConnectController::class, 'reconnect']);
        Route::post('/disconnect', [AliExpressConnectController::class, 'disconnect']);
        Route::get('/account/status', [AliExpressConnectController::class, 'check']);
        Route::post('/refresh-token', [AliExpressConnectController::class, 'refreshToken']);

        // ── Products ──────────────────────────────────────────────────
        Route::get('/products', [AliExpressProductBrowseController::class, 'index']);              // ?keyword =&page_size=&page=&category=&sku=&product_id=&ship_to_country=
        Route::get('/products/imported', [AliExpressImportController::class, 'importedProducts']); // ?name    =&active=&inactive=&id=&search=&is_active=&per_page=
        Route::get('/products/{productId}', [AliExpressProductBrowseController::class, 'show']);   // single product detail
        Route::get('/products/{productId}/reviews', [AliExpressProductBrowseController::class, 'reviews']); // product ratings and reviews breakdown

        // ── Import ────────────────────────────────────────────────────
        Route::post('/import', [AliExpressImportController::class, 'import']);
        Route::get('/import/history', [AliExpressImportController::class, 'history']);
        Route::get('/import/{productId}', [AliExpressImportController::class, 'detail'])->where('productId', '[0-9]+');
        Route::match(['post', 'put', 'patch'], '/import/{productId}', [AliExpressImportController::class, 'updateStagedProduct'])->where('productId', '[0-9]+');
        Route::delete('/import/{productId}', [AliExpressImportController::class, 'removeSingleStagedProduct'])->where('productId', '[0-9]+');
        Route::post('/import/finalize', [AliExpressImportController::class, 'finalize']);
        Route::post('/import/cancel', [AliExpressImportController::class, 'cancel']);
    });

    // ─── Services ────────────────────────────────────────────────────
    Route::prefix('services')->group(function () {
        Route::get('/', [ServiceController::class, 'index']);                   // ?page, per_page, category (or category_id), service_type, price_type, min_price, max_price, min_rating, city, country, search, sort (newest, price_low_high, price_high_low, highest_rated, most_popular), tag, is_insured, is_certified
        Route::get('/mine', [ServiceController::class, 'mine']);
        Route::post('/', [ServiceController::class, 'store']);
        Route::get('/{service}', [ServiceController::class, 'show']);
        Route::match(['put', 'patch'], '/{service}', [ServiceController::class, 'update']);
        Route::delete('/{service}', [ServiceController::class, 'destroy']);
        Route::get('/{service}/availability', [AvailabilityController::class, 'checkSlots']);   // ?date, duration
        Route::get('/{service}/weekly-availability', [AvailabilityController::class, 'weeklyAvailability']);
    });

    // ─── Jobs ────────────────────────────────────────────────────────
    Route::prefix('jobs')->group(function () {
        Route::get('/', [JobController::class, 'index']);                       // ?page, per_page, job_type, is_remote, search, location, city, country, min_salary, max_salary, sort (newest, salary_low_high, salary_high_low, highest_rated, most_popular)
        Route::get('/mine', [JobController::class, 'mine']);
        Route::post('/', [JobController::class, 'store']);
        Route::get('/{job}', [JobController::class, 'show']);
        Route::match(['put', 'patch'], '/{job}', [JobController::class, 'update']);
        Route::delete('/{job}', [JobController::class, 'destroy']);

        // Applications
        Route::post('/{job}/apply', [JobApplicationController::class, 'store']);
        Route::get('/{job}/applications', [JobApplicationController::class, 'index']);
    });

    // ─── Job Applications ────────────────────────────────────────────
    Route::prefix('job-applications')->group(function () {
        Route::get('/my-applications', [JobApplicationController::class, 'mine']);
        Route::get('/{application}', [JobApplicationController::class, 'show']);
        Route::put('/{application}/status', [JobApplicationController::class, 'updateStatus']);
    });

    // ─── Events & Tickets ────────────────────────────────────────────
    Route::prefix('events')->group(function () {
        Route::get('/', [EventController::class, 'index']);                     // ?page, per_page, upcoming, search, location, city, country, min_price, max_price, min_rating, sort (newest, date_asc, date_desc, price_low_high, price_high_low, highest_rated, most_popular), tag
        Route::get('/mine', [EventController::class, 'mine']);
        Route::post('/', [EventController::class, 'store']);
        Route::get('/{event}', [EventController::class, 'show']);
        Route::match(['put', 'patch'], '/{event}', [EventController::class, 'update']);
        Route::delete('/{event}', [EventController::class, 'destroy']);

        // ── Tickets ──────────────────────────────────────────────
        Route::post('/{event}/tickets', [TicketController::class, 'purchase']);
        Route::get('/{event}/tickets', [TicketController::class, 'eventTickets']);
        Route::post('/{event}/tickets/{ticket}/use', [TicketController::class, 'use']);
    });

    Route::prefix('tickets')->group(function () {
        Route::get('/organizer', [TicketController::class, 'organizerTickets']);
        Route::get('/', [TicketController::class, 'myTickets']);                // ?page, per_page
        Route::get('/{ticket}', [TicketController::class, 'show']);
    });

    // ─── Bookings ────────────────────────────────────────────────────
    Route::prefix('bookings')->group(function () {
        Route::post('/', [BookingController::class, 'create']);
        Route::get('/', [BookingController::class, 'myBookings']);              // ?page, per_page, status
        Route::get('/{booking}', [BookingController::class, 'show']);
        Route::post('/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('/{booking}/confirm', [BookingController::class, 'confirm']);
        Route::post('/{booking}/reject', [BookingController::class, 'reject']);
        Route::post('/{booking}/deliver', [BookingController::class, 'deliver']);
        Route::post('/{booking}/reject-delivery', [BookingController::class, 'rejectDelivery']);
        Route::post('/{booking}/complete', [BookingController::class, 'complete']);
    });

    // ─── Provider Availability ───────────────────────────────────────
    Route::prefix('providers/availability')->group(function () {
        Route::post('/', [AvailabilityController::class, 'setSlots']);
        Route::get('/', [AvailabilityController::class, 'myAvailability']);     // ?page, per_page
        Route::match(['put', 'patch'], '/{slot}', [AvailabilityController::class, 'updateSlot']);
        Route::delete('/{slot}', [AvailabilityController::class, 'removeSlot']);

        // Day-specific availability
        Route::get('/day/{day_of_week}', [AvailabilityController::class, 'getDayAvailability']);
        Route::patch('/day/available/update/{day_of_week}', [AvailabilityController::class, 'toggleDayAvailability']);

        // Date-specific blocking
        Route::post('/block', [AvailabilityController::class, 'blockDate']);
        Route::get('/blocked', [AvailabilityController::class, 'getBlockedDates']);
        Route::delete('/block/{blockedDate}', [AvailabilityController::class, 'unblockDate']);
    });

    // ─── Saved Items ──────────────────────────────────────────────────
    Route::post('/saved-items/toggle', [SavedItemController::class, 'toggle']);
    Route::get('/saved-items', [SavedItemController::class, 'index']);

    // ─── Payment Accounts (Buyer Bank Accounts) ───────────────────────
    Route::prefix('payment-accounts')->group(function () {
        Route::get('/', [BuyerBankAccountController::class, 'index']);
        Route::post('/', [BuyerBankAccountController::class, 'store']);
        Route::match(['put', 'patch'], '/{paymentAccount}', [BuyerBankAccountController::class, 'update']);
        Route::delete('/{paymentAccount}', [BuyerBankAccountController::class, 'destroy']);
    });

    // ─── Payout Accounts (Seller Bank Accounts) ───────────────────────
    Route::prefix('payout-accounts')->group(function () {
        Route::get('/', [EscrowController::class, 'bankAccounts']);
        Route::post('/', [EscrowController::class, 'addBankAccount']);
        Route::match(['put', 'patch'], '/{account}', [EscrowController::class, 'updateBankAccount']);
        Route::delete('/{account}', [EscrowController::class, 'deleteBankAccount']);
    });

    // ─── Cart ────────────────────────────────────────────────────────
    Route::prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'show']);
        Route::post('/add', [CartController::class, 'add']);
        Route::match(['put', 'patch'], '/items/{cartItem}', [CartController::class, 'update']);
        Route::delete('/items/{cartItem}', [CartController::class, 'remove']);
        Route::post('/clear', [CartController::class, 'clear']);
        Route::get('/count', [CartController::class, 'count']);
        Route::post('/select-shipping', [CartController::class, 'selectShipping']);
    });

    // ─── Payments (Stripe PaymentIntent for Mobile / Web) ────────────
    Route::match(['get', 'post'], 'payments/{order}/pay', [PaymentController::class, 'getPaymentDetails']);

    // ─── Orders ──────────────────────────────────────────────────────
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);                         // ?page, per_page, status, source, search, type (sales|purchases), role (seller|buyer)
        Route::get('/checkout', [OrderController::class, 'checkoutSummary']);       // GET all products, options, shipping, address, price review before placing order
        Route::post('/checkout', [OrderController::class, 'checkout']);            // POST place order
        Route::get('/checkout/{productId}', [CheckoutController::class, 'shippingOptions'])->whereNumber('productId');                    // Buyer: seller-enabled shipping options for a product
        Route::get('/checkout/{productId}/{shippingMethodId}', [CheckoutController::class, 'carriersAndRates'])->whereNumber('productId')->where('shippingMethodId', '[a-zA-Z0-9_-]+'); // Buyer: carriers + rates for selected method
        Route::get('/purchases', [OrderController::class, 'purchases']);            // ?page, per_page, status
        Route::get('/sales', [OrderController::class, 'sales']);                    // ?page, per_page, status
        Route::get('/{order}/confirmation', [OrderController::class, 'confirmation']); // Flutter payment confirmation
        Route::post('/{order}/fulfill-aliexpress', [AliExpressFulfillmentController::class, 'fulfill']); // Push to AliExpress supplier and retrieve payment link
        Route::post('/{order}/fulfill', [OrderFulfillmentController::class, 'fulfill']); // Push custom fulfillment (ShipEngine/Manual)
        Route::patch('/{order}/shipping-address', [OrderController::class, 'updateShippingAddress']); // Buyer or seller
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::get('/{order}/timeline', [OrderController::class, 'timeline']);      // Status history
        Route::post('/{order}/status', [OrderController::class, 'updateStatus']);
        Route::post('/{order}/cancel', [OrderController::class, 'cancel']);         // Buyer or seller cancel + auto-refund
        Route::post('/{order}/refund', [OrderController::class, 'refund']);         // Seller manual partial/full refund
    });

    // ─── Shipping ────────────────────────────────────────────────────
    Route::get('/shipping-options', [ShipmentController::class, 'availableOptions']);    // Seller: GET /api/shipping-options
    Route::prefix('shipping')->group(function () {
        Route::post('/create-label', [ShipmentController::class, 'createLabel']);        // body: { order_id }
        Route::post('/void', [ShipmentController::class, 'voidLabel']);                  // body: { label_id }
        Route::post('/return-label', [ShipmentController::class, 'createReturnLabel']); // body: { label_id }
        Route::get('/tracking', [ShipmentController::class, 'tracking']);                // ?label_id=&tracking_number=
    });

    // ─── Saved Addresses (Buyer address book) ────────────────────────
    Route::prefix('user/addresses')->group(function () {
        Route::get('/', [SavedAddressController::class, 'index']);                   // List all saved addresses
        Route::post('/', [SavedAddressController::class, 'store']);                  // Save a new address
        Route::get('/{address}', [SavedAddressController::class, 'show']);           // Get one address
        Route::match(['put', 'patch'], '/{address}', [SavedAddressController::class, 'update']); // Update address
        Route::delete('/{address}', [SavedAddressController::class, 'destroy']);     // Delete address
        Route::patch('/{address}/set-default', [SavedAddressController::class, 'setDefault']); // Mark as default
    });

    // ─── Wallet ──────────────────────────────────────────────────────
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'show']);
        Route::get('/balance', [WalletController::class, 'balance']);
        Route::get('/transactions', [WalletController::class, 'transactions']);     // ?page, per_page
        Route::post('/top-up', [WalletController::class, 'topUp']);
        Route::post('/top-up/confirm', [WalletController::class, 'confirmTopUp']);
        Route::post('/send-gift', [WalletController::class, 'sendGift']);
    });

    // ─── Escrow ──────────────────────────────────────────────────────
    Route::prefix('escrow')->group(function () {
        Route::get('/', [EscrowController::class, 'myEscrows']);                    // ?page, per_page
        Route::post('/create/{order}', [EscrowController::class, 'create']);

        Route::get('/disputes', [EscrowController::class, 'myDisputes']);           // ?page, per_page
        Route::get('/disputes/{dispute}', [EscrowController::class, 'showDispute']);
        Route::post('/disputes/{dispute}/resolve', [EscrowController::class, 'resolveDispute']);

        Route::get('/payouts', [EscrowController::class, 'myPayouts']);             // ?page, per_page

        Route::post('/{escrow}/dispute', [EscrowController::class, 'createDispute']);
        Route::post('/{escrow}/release', [EscrowController::class, 'release']);
        Route::get('/{escrow}', [EscrowController::class, 'showEscrow']);
    });

    // ─── Device Tokens (FCM Push) ────────────────────────────────────
    Route::prefix('device-tokens')->group(function () {
        Route::get('/', [DeviceTokenController::class, 'index']);
        Route::post('/register', [DeviceTokenController::class, 'register']);
        Route::post('/unregister', [DeviceTokenController::class, 'unregister']);
        Route::delete('/{deviceToken}', [DeviceTokenController::class, 'destroy']);
    });

    // ─── Push Subscriptions (Web Push / VAPID) ───────────────────────
    Route::prefix('push-subscriptions')->group(function () {
        Route::get('/', [PushSubscriptionController::class, 'index']);
        Route::post('/', [PushSubscriptionController::class, 'subscribe']);
        Route::post('/unsubscribe', [PushSubscriptionController::class, 'unsubscribeByEndpoint']);
        Route::delete('/{pushSubscription}', [PushSubscriptionController::class, 'destroy']);
    });

    // ─── Notification Preferences ────────────────────────────────────
    Route::prefix('notification-preferences')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'show']);
        Route::match(['put', 'patch'], '/', [NotificationPreferenceController::class, 'update']);
    });

    // ─── Notifications ───────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);                 // ?page, per_page, type, is_read
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    });

    // ─── Reviews ─────────────────────────────────────────────────────
    Route::prefix('reviews')->group(function () {
        Route::prefix('products')->group(function () {
            Route::get('/{product}', [ReviewController::class, 'productIndex']);    // ?page, per_page
            Route::post('/{product}', [ReviewController::class, 'productStore']);
            Route::match(['put', 'patch'], '/{product}/review/{review}', [ReviewController::class, 'productUpdate']);
            Route::delete('/{product}/review/{review}', [ReviewController::class, 'productDestroy']);
        });

        Route::prefix('services')->group(function () {
            Route::get('/{service}', [ReviewController::class, 'serviceIndex']);    // ?page, per_page
            Route::post('/{service}', [ReviewController::class, 'serviceStore']);
            Route::match(['put', 'patch'], '/{service}/review/{review}', [ReviewController::class, 'serviceUpdate']);
            Route::delete('/{service}/review/{review}', [ReviewController::class, 'serviceDestroy']);
        });

        Route::prefix('events')->group(function () {
            Route::get('/{event}', [ReviewController::class, 'eventIndex']);        // ?page, per_page
            Route::post('/{event}', [ReviewController::class, 'eventStore']);
            Route::match(['put', 'patch'], '/{event}/review/{review}', [ReviewController::class, 'eventUpdate']);
            Route::delete('/{event}/review/{review}', [ReviewController::class, 'eventDestroy']);
        });
    });

    // ─── Conversations ───────────────────────────────────────────────
    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);                  // ?page, per_page
        Route::get('/unread-count', [ConversationController::class, 'unreadCount']);
        Route::post('/', [ConversationController::class, 'start']);
        Route::get('/{conversation}', [ConversationController::class, 'show']);
        Route::post('/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::post('/{conversation}/read', [ConversationController::class, 'markAsRead']);
    });

    // ─── Connections ────────────────────────────────────────────────
    Route::prefix('connections')->group(function () {
        Route::get('/', [ConnectionController::class, 'index']);                // ?page, per_page
        Route::get('/requests', [ConnectionController::class, 'pendingRequests']);
        Route::get('/sent', [ConnectionController::class, 'sentRequests']);
        Route::post('/request/{profile}', [ConnectionController::class, 'sendRequest']);
        Route::post('/accept/{connection}', [ConnectionController::class, 'acceptRequest']);
        Route::delete('/decline/{connection}', [ConnectionController::class, 'declineRequest']);
        Route::delete('/{connection}', [ConnectionController::class, 'removeConnection']);
        Route::post('/block/{profile}', [ConnectionController::class, 'block']);
        Route::post('/unblock/{profile}', [ConnectionController::class, 'unblock']);
        Route::get('/blocked', [ConnectionController::class, 'blockedUsers']);  // ?page, per_page
        Route::post('/follow/{profile}', [ConnectionController::class, 'follow']);
        Route::post('/unfollow/{profile}', [ConnectionController::class, 'unfollow']);
        Route::get('/followers', [ConnectionController::class, 'myFollowers']);
        Route::get('/following', [ConnectionController::class, 'myFollowing']);
    });

    // ─── Profiles ────────────────────────────────────────────────────
    Route::get('/profiles/{profile}', [ConnectionController::class, 'showProfile']);
    Route::get('/profiles/{profile}/about', [ConnectionController::class, 'aboutProfile']);
    Route::get('/profiles/{profile}/followers', [ConnectionController::class, 'userFollowers']);
    Route::get('/profiles/{profile}/following', [ConnectionController::class, 'userFollowing']);

    // ─── Profile (User) ──────────────────────────────────────────────
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::match(['put', 'patch'], '/', [ProfileController::class, 'update']);
        Route::delete('/', [ProfileController::class, 'destroy']);

        // ── Step 1: Basic Information ────────────────────────────
        Route::get('/basic-information', [ProfileController::class, 'basicInformation']);
        Route::match(['put', 'patch'], '/basic-information', [ProfileController::class, 'updateBasicInformation']);

        // ── Step 2: Header (Bio, Skills, Interests) ──────────────
        Route::get('/header-information', [ProfileController::class, 'headerInformation']);
        Route::match(['put', 'patch'], '/header-information', [ProfileController::class, 'updateHeaderInformation']);

        // ── Photo ─────────────────────────────────────────
        Route::match(['put', 'patch'], '/cover-photo', [ProfileController::class, 'coverPhoto']);
        Route::match(['put', 'patch'], '/profile-photo', [ProfileController::class, 'profilePhoto']);

        // ── Step 3: CV / Resume ──────────────────────────────────
        Route::get('/cv-resume', [ProfileController::class, 'cvResume']);
        Route::post('/cv-resume', [ProfileController::class, 'updateCvResume']);

        // ── Step 4: Work History ─────────────────────────────────
        Route::get('/work-history', [WorkHistoryController::class, 'index']);
        Route::post('/work-history', [WorkHistoryController::class, 'store']);
        Route::match(['put', 'patch'], '/work-history/{workHistory}', [WorkHistoryController::class, 'update']);
        Route::delete('/work-history/{workHistory}', [WorkHistoryController::class, 'destroy']);

        // ── Step 5: Qualification ────────────────────────────────
        Route::get('/qualification', [QualificationController::class, 'index']);
        Route::post('/qualification', [QualificationController::class, 'store']);
        Route::match(['put', 'patch'], '/qualification/{qualification}', [QualificationController::class, 'update']);
        Route::delete('/qualification/{qualification}', [QualificationController::class, 'destroy']);

        // ── Step 6: Subscription Plans ──────────────────────────
        Route::get('/subscription-plans', [SubscriptionController::class, 'index']);
        Route::post('/subscription-plans/choose/{subscriptionPlan}', [SubscriptionController::class, 'choose'])
            ->whereNumber('subscriptionPlan');

        // ── Step 6b: Verification & Document Upload ────────────
        Route::prefix('verification')->group(function () {
            Route::get('/', [VerificationController::class, 'index']);              // All submissions
            Route::get('/required-documents/{planId}', [VerificationController::class, 'requiredDocuments']);
            Route::post('/submit/{planId}', [VerificationController::class, 'submit']);       // Submit verification with docs
            Route::post('/choose-plan/{planId}', [VerificationController::class, 'choosePlan']); // Re-activate subscription (docs optional)
            Route::get('/{submission}', [VerificationController::class, 'show']);   // Single submission detail
            Route::post('/{submission}/pay', [VerificationController::class, 'confirmPayment']); // Confirm Stripe payment
        });

        // ── Step 7: Terms & Guidelines ──────────────────────────
        Route::get('/terms-guidelines', [TermsGuidelinesController::class, 'index']);
        Route::post('/terms-guidelines/accept', [OnboardingController::class, 'acceptTerms']);

        // ── Onboarding Progress ─────────────────────────────────
        Route::get('/completion-status', [OnboardingController::class, 'completionStatus']);
        Route::post('/complete-onboarding', [OnboardingController::class, 'complete']);
    });
});