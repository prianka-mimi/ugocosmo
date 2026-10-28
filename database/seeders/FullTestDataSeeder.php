<?php

namespace Database\Seeders;

use App\Enums\ShipmentStatus;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\GiftWallet;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Livestream;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Profile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Shipment;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Full test data seeder for the primary test account (p@gmail.com).
 *
 * Creates a complete, realistic dataset so every API endpoint can be
 * tested without hitting 404s or empty responses.
 *
 * Run: php artisan db:seed --class=FullTestDataSeeder
 */
class FullTestDataSeeder extends Seeder
{
    protected Profile $me;

    protected Profile $seller2;

    protected Profile $buyer2;

    public function run(): void
    {
        $this->createUser();
        $this->createOtherProfiles();
        $this->createProfileData();
        $this->createWorkHistoryAndQualifications();
        $this->createProviderAvailability();
        $this->createProducts();
        $this->createServices();
        $this->createJobs();
        $this->createEvents();
        $this->createPosts();
        $this->createStories();
        $this->createConnections();
        $this->createConversations();
        $this->createOrdersAsBuyer();
        $this->createOrdersAsSeller();
        $this->createShipEngineOrders();
        $this->createBookings();
        $this->createWallet();
        $this->createSavedItems();
        $this->createCart();
        $this->createNotifications();
        $this->createDeviceTokens();
        $this->createReviews();
        $this->createEscrow();
        $this->createJobApplications();
        $this->createEventTickets();
        $this->createLivestreams();
        $this->createBankAccounts();
        $this->createFollowers();

        $this->command?->info('✅ Full test data seeded for: p@gmail.com | password: 12345678');
    }

    // ─── 1. USER ─────────────────────────────────────────────────

    protected function createUser(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'p@gmail.com'],
            [
                'name' => 'Prianka Mimi',
                'username' => 'prianka_mimi',
                'password' => Hash::make('12345678'),
                'role' => 'user',
                'status' => 'active',
                'mobile_number' => '+1234567890',
            ]
        );

        $this->me = Profile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email' => 'p@gmail.com',
                'full_name' => 'Prianka Mimi',
                'username' => 'prianka_mimi',
                'bio' => 'Full-stack developer & e-commerce entrepreneur. Building the future of digital commerce.',
                'location' => 'New York, US',
                'website' => 'https://prianka.dev',
                'is_seller' => true,
                'subscription_tier' => 'premium',
            ]
        );

        DB::table('privacy_settings')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'age_visibility' => 'public',
                'employment_history_visibility' => 'public',
                'qualifications_visibility' => 'public',
                'profile_visibility' => 'public',
                'show_online_status' => true,
                'message_permission' => 'everyone',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    // ─── 2. OTHER PROFILES ──────────────────────────────────────

    protected function createOtherProfiles(): void
    {
        // Seller 2 (alice) — will sell to us
        $u1 = User::firstOrCreate(['email' => 'alice@example.com'], [
            'name' => 'Alice Johnson', 'username' => 'alice',
            'password' => Hash::make('12345678'), 'role' => 'user', 'status' => 'active',
        ]);
        $this->seller2 = Profile::firstOrCreate(['user_id' => $u1->id], [
            'email' => 'alice@example.com', 'full_name' => 'Alice Johnson',
            'username' => 'alice', 'bio' => 'Freelance designer & creator.',
            'location' => 'Los Angeles, US', 'is_seller' => true,
        ]);

        // Buyer 2 (bob) — will buy from us
        $u2 = User::firstOrCreate(['email' => 'bob@example.com'], [
            'name' => 'Bob Smith', 'username' => 'bob',
            'password' => Hash::make('12345678'), 'role' => 'user', 'status' => 'active',
        ]);
        $this->buyer2 = Profile::firstOrCreate(['user_id' => $u2->id], [
            'email' => 'bob@example.com', 'full_name' => 'Bob Smith',
            'username' => 'bob', 'bio' => 'Tech enthusiast & buyer.',
            'location' => 'London, UK', 'is_seller' => false,
        ]);

        // Extra profiles for richer data
        foreach ([
            ['carol@example.com', 'Carol Williams', 'carol', 'Event planner.', 'Toronto, CA', true],
            ['david@example.com', 'David Brown', 'david', 'Marketing specialist.', 'Sydney, AU', false],
            ['eva@example.com', 'Eva Martinez', 'eva', 'Photographer.', 'Berlin, DE', true],
        ] as [$email, $name, $username, $bio, $loc, $seller]) {
            $u = User::firstOrCreate(['email' => $email], [
                'name' => $name, 'username' => $username,
                'password' => Hash::make('12345678'), 'role' => 'user', 'status' => 'active',
            ]);
            Profile::firstOrCreate(['user_id' => $u->id], [
                'email' => $email, 'full_name' => $name, 'username' => $username,
                'bio' => $bio, 'location' => $loc, 'is_seller' => $seller,
            ]);
        }
    }

    // ─── 3. PROFILE DATA ────────────────────────────────────────

    protected function createProfileData(): void
    {
        // Basic info is stored on users table
        User::where('id', $this->me->user_id)->update([
            'address_line_1' => '123 Main Street',
            'city' => 'New York',
            'country' => 'US',
            'postal_code' => '10001',
        ]);
    }

    // ─── 4. WORK HISTORY & QUALIFICATIONS ────────────────────────

    protected function createWorkHistoryAndQualifications(): void
    {
        $userId = $this->me->user_id;
        DB::table('work_histories')->updateOrInsert(
            ['user_id' => $userId, 'company_name' => 'Google'],
            ['position_title' => 'Senior Software Engineer', 'start_date' => '2020-01-01',
                'end_date' => '2023-12-31', 'job_description' => 'Built scalable microservices.', 'is_currently_working' => false, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('work_histories')->updateOrInsert(
            ['user_id' => $userId, 'company_name' => 'Meta'],
            ['position_title' => 'Frontend Developer', 'start_date' => '2018-06-01',
                'end_date' => '2019-12-31', 'job_description' => 'React & React Native apps.', 'is_currently_working' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('qualifications')->updateOrInsert(
            ['user_id' => $userId, 'institute_name' => 'MIT'],
            ['degree' => 'BSc Computer Science', 'field_of_study' => 'Computer Science',
                'start_date' => '2014-09-01', 'end_date' => '2018-05-01', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    // ─── 5. PROVIDER AVAILABILITY ────────────────────────────────

    protected function createProviderAvailability(): void
    {
        foreach ([1, 2, 3, 4, 5] as $day) {
            DB::table('provider_availability')->updateOrInsert(
                ['provider_id' => $this->me->id, 'day_of_week' => $day],
                ['start_time' => '09:00', 'end_time' => '17:00', 'is_available' => true,
                    'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    // ─── 6. PRODUCTS ─────────────────────────────────────────────

    protected function createProducts(): void
    {
        $physical = ProductCategory::where('product_type', 'physical')->first();
        $digital = ProductCategory::where('product_type', 'digital')->first();

        $products = [
            ['title' => 'Wireless Bluetooth Headphones', 'price' => 89.99, 'product_type' => 'physical', 'weight' => 8.0, 'stock_quantity' => 100,
                'package_dimensions' => ['length' => 7, 'width' => 6, 'height' => 3, 'unit' => 'inch'], 'description' => 'Premium ANC headphones with 30hr battery.'],
            ['title' => 'Mechanical Keyboard RGB', 'price' => 129.99, 'product_type' => 'physical', 'weight' => 24.0, 'stock_quantity' => 50,
                'package_dimensions' => ['length' => 17, 'width' => 5, 'height' => 2, 'unit' => 'inch'], 'description' => 'Cherry MX switches, per-key RGB.'],
            ['title' => 'USB-C Hub 7-in-1', 'price' => 49.99, 'product_type' => 'physical', 'weight' => 4.0, 'stock_quantity' => 200,
                'package_dimensions' => ['length' => 5, 'width' => 2, 'height' => 1, 'unit' => 'inch'], 'description' => 'HDMI, USB-A, SD card, PD charging.'],
            ['title' => 'Laravel Mastery Course', 'price' => 59.99, 'product_type' => 'digital', 'weight' => null, 'stock_quantity' => null,
                'package_dimensions' => null, 'description' => '40+ hours of advanced Laravel content.'],
            ['title' => 'UI Kit - Dashboard Templates', 'price' => 34.99, 'product_type' => 'digital', 'weight' => null, 'stock_quantity' => null,
                'package_dimensions' => null, 'description' => '50+ Figma dashboard components.'],
            ['title' => 'Webcam HD 1080p', 'price' => 69.99, 'product_type' => 'physical', 'weight' => 6.0, 'stock_quantity' => 75,
                'package_dimensions' => ['length' => 5, 'width' => 3, 'height' => 3, 'unit' => 'inch'], 'description' => 'Auto-focus, noise-cancelling mic.'],
        ];

        foreach ($products as $p) {
            $cat = $p['product_type'] === 'physical' ? $physical : $digital;
            Product::firstOrCreate(
                ['title' => $p['title'], 'seller_id' => $this->me->id],
                [
                    'seller_id' => $this->me->id,
                    'title' => $p['title'],
                    'price' => $p['price'],
                    'category_id' => $cat?->id,
                    'currency' => 'USD',
                    'product_type' => $p['product_type'],
                    'condition' => 'new',
                    'weight' => $p['weight'],
                    'stock_quantity' => $p['stock_quantity'],
                    'package_dimensions' => $p['package_dimensions'],
                    'is_active' => true,
                    'description' => $p['description'],
                ]
            );
        }
    }

    // ─── 7. SERVICES ─────────────────────────────────────────────

    protected function createServices(): void
    {
        $cat = ServiceCategory::first();

        $services = [
            ['title' => 'Full-Stack Web Development', 'price' => 75.00, 'price_type' => 'hourly',
                'service_type' => 'online', 'description' => 'Laravel, React, Vue, Node.js. 10+ years experience.'],
            ['title' => 'Mobile App Development', 'price' => 100.00, 'price_type' => 'hourly',
                'service_type' => 'online', 'description' => 'Flutter & React Native apps for iOS and Android.'],
            ['title' => 'Logo & Brand Design', 'price' => 299.00, 'price_type' => 'fixed',
                'service_type' => 'online', 'description' => 'Professional logo with 3 concepts and revisions.'],
        ];

        foreach ($services as $s) {
            Service::firstOrCreate(
                ['title' => $s['title'], 'provider_id' => $this->me->id],
                [
                    'title' => $s['title'],
                    'provider_id' => $this->me->id,
                    'price' => $s['price'],
                    'price_type' => $s['price_type'],
                    'service_type' => $s['service_type'],
                    'description' => $s['description'],
                    'category_id' => $cat?->id,
                    'currency' => 'USD',
                    'estimated_delivery_time' => '3-5 days',
                    'is_active' => true,
                ]
            );
        }

        // Alice also needs a service so booking-as-customer tests work
        Service::firstOrCreate(
            ['title' => 'UI/UX Design Consultation', 'provider_id' => $this->seller2->id],
            [
                'title' => 'UI/UX Design Consultation',
                'provider_id' => $this->seller2->id,
                'price' => 65.00,
                'price_type' => 'hourly',
                'service_type' => 'online',
                'description' => 'User interface and experience consulting for web and mobile.',
                'category_id' => $cat?->id,
                'currency' => 'USD',
                'estimated_delivery_time' => '1-2 days',
                'is_active' => true,
            ]
        );
    }

    // ─── 8. JOBS ─────────────────────────────────────────────────

    protected function createJobs(): void
    {
        $jobs = [
            ['title' => 'Senior Laravel Developer', 'company' => 'UgoCosmo', 'location' => 'Remote',
                'salary_min' => 90000, 'salary_max' => 130000, 'job_type' => 'full-time', 'is_remote' => true,
                'description' => 'We need a senior Laravel dev for our marketplace platform.'],
            ['title' => 'Flutter Developer', 'company' => 'UgoCosmo', 'location' => 'New York, US',
                'salary_min' => 80000, 'salary_max' => 120000, 'job_type' => 'full-time', 'is_remote' => true,
                'description' => 'Build our mobile app with Flutter & Dart.'],
        ];

        foreach ($jobs as $j) {
            JobListing::firstOrCreate(
                ['title' => $j['title'], 'poster_id' => $this->me->id],
                array_merge($j, ['poster_id' => $this->me->id, 'is_active' => true])
            );
        }
    }

    // ─── 9. EVENTS ───────────────────────────────────────────────

    protected function createEvents(): void
    {
        $events = [
            ['title' => 'UgoCosmo Tech Meetup 2026', 'location' => 'Convention Center, New York',
                'city' => 'New York', 'country' => 'US', 'event_date' => now()->addMonths(2),
                'price' => 0.00, 'max_attendees' => 200, 'description' => 'Free community meetup.'],
            ['title' => 'Advanced Laravel Workshop', 'location' => 'Online (Zoom)',
                'city' => 'Online', 'country' => 'US', 'event_date' => now()->addMonth(),
                'price' => 49.99, 'max_attendees' => 50, 'description' => 'Deep dive into Laravel 12 features.'],
        ];

        foreach ($events as $e) {
            Event::firstOrCreate(
                ['title' => $e['title'], 'organizer_id' => $this->me->id],
                array_merge($e, ['organizer_id' => $this->me->id, 'is_active' => true])
            );
        }
    }

    // ─── 10. POSTS ───────────────────────────────────────────────

    protected function createPosts(): void
    {
        $posts = [
            'Just shipped a new feature on UgoCosmo! 🚀 The marketplace is growing fast.',
            'Looking for Flutter developers to join our team. DM me! 💼',
            'Best practices for Laravel API development — thread 🧵👇',
            'Happy to announce our first Tech Meetup is happening next month! 🎉',
        ];

        foreach ($posts as $content) {
            $post = Post::firstOrCreate(
                ['content' => $content, 'author_id' => $this->me->id],
                ['content' => $content, 'author_id' => $this->me->id, 'is_active' => true]
            );

            // Add comments from other users (post_comments uses author_id)
            if ($post->wasRecentlyCreated) {
                PostComment::create([
                    'post_id' => $post->id, 'author_id' => $this->seller2->id,
                    'content' => 'Great work! Keep it up! 🔥',
                ]);
                PostComment::create([
                    'post_id' => $post->id, 'author_id' => $this->buyer2->id,
                    'content' => 'Awesome, love the platform!',
                ]);

                PostLike::firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $this->seller2->id],
                    ['post_id' => $post->id, 'user_id' => $this->seller2->id]
                );
            }
        }
    }

    // ─── 11. STORIES ─────────────────────────────────────────────

    protected function createStories(): void
    {
        Story::firstOrCreate(
            ['creator_id' => $this->me->id, 'media_type' => 'image'],
            ['creator_id' => $this->me->id, 'media_type' => 'image', 'media_url' => '/storage/stories/test1.jpg',
                'caption' => 'Working on something cool! 💻', 'expires_at' => now()->addDay()]
        );
    }

    // ─── 12. CONNECTIONS ─────────────────────────────────────────

    protected function createConnections(): void
    {
        foreach ([$this->seller2, $this->buyer2] as $profile) {
            DB::table('user_connections')->updateOrInsert(
                ['requester_id' => $this->me->id, 'recipient_id' => $profile->id],
                ['requester_id' => $this->me->id, 'recipient_id' => $profile->id, 'status' => 'accepted', 'connection_type' => 'professional', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    // ─── 13. CONVERSATIONS ───────────────────────────────────────

    protected function createConversations(): void
    {
        $conv = Conversation::firstOrCreate(
            ['participant_1' => $this->me->id, 'participant_2' => $this->seller2->id],
            ['participant_1' => $this->me->id, 'participant_2' => $this->seller2->id]
        );

        Message::firstOrCreate(
            ['conversation_id' => $conv->id, 'sender_id' => $this->me->id, 'content' => 'Hey Alice! Love your designs.'],
            ['conversation_id' => $conv->id, 'sender_id' => $this->me->id, 'content' => 'Hey Alice! Love your designs.', 'is_read' => true]
        );
        Message::firstOrCreate(
            ['conversation_id' => $conv->id, 'sender_id' => $this->seller2->id, 'content' => 'Thanks Prianka! Let me know if you need anything.'],
            ['conversation_id' => $conv->id, 'sender_id' => $this->seller2->id, 'content' => 'Thanks Prianka! Let me know if you need anything.', 'is_read' => false]
        );
    }

    // ─── 14. ORDERS (AS BUYER) ──────────────────────────────────

    protected function createOrdersAsBuyer(): void
    {
        $product = Product::where('seller_id', $this->seller2->id)->first();
        if (! $product) {
            // Create a product for alice to sell
            $product = Product::firstOrCreate(
                ['title' => 'Logo Design Package', 'seller_id' => $this->seller2->id],
                ['seller_id' => $this->seller2->id, 'title' => 'Logo Design Package', 'price' => 49.99,
                    'currency' => 'USD', 'product_type' => 'digital', 'is_active' => true, 'description' => 'Professional logo design.']
            );
        }

        $shippingAddr = ['line1' => '123 Main Street', 'line2' => 'Apt 4B', 'city' => 'New York',
            'state' => 'NY', 'postal_code' => '10001', 'country' => 'US'];

        // Order 1: completed
        $o1 = Order::firstOrCreate(
            ['buyer_id' => $this->me->id, 'seller_id' => $this->seller2->id, 'status' => 'completed'],
            ['buyer_id' => $this->me->id, 'seller_id' => $this->seller2->id, 'status' => 'completed',
                'subtotal' => $product->price, 'total' => $product->price, 'shipping_address' => $shippingAddr]
        );
        OrderItem::firstOrCreate(['order_id' => $o1->id, 'product_id' => $product->id], [
            'order_id' => $o1->id, 'product_id' => $product->id, 'title' => $product->title,
            'quantity' => 1, 'unit_price' => $product->price, 'total_price' => $product->price,
        ]);

        // Order 2: pending
        $o2 = Order::firstOrCreate(
            ['buyer_id' => $this->me->id, 'seller_id' => $this->seller2->id, 'status' => 'pending'],
            ['buyer_id' => $this->me->id, 'seller_id' => $this->seller2->id, 'status' => 'pending',
                'subtotal' => $product->price, 'total' => $product->price, 'shipping_address' => $shippingAddr]
        );
        OrderItem::firstOrCreate(['order_id' => $o2->id, 'product_id' => $product->id], [
            'order_id' => $o2->id, 'product_id' => $product->id, 'title' => $product->title,
            'quantity' => 1, 'unit_price' => $product->price, 'total_price' => $product->price,
        ]);
    }

    // ─── 15. ORDERS (AS SELLER) ──────────────────────────────────

    protected function createOrdersAsSeller(): void
    {
        $product = Product::where('seller_id', $this->me->id)->first();
        if (! $product) {
            return;
        }

        $shippingAddr = ['line1' => '45 Broadway', 'city' => 'New York', 'state' => 'NY',
            'postal_code' => '10006', 'country' => 'US'];

        // Order from bob → me (seller), status: confirmed
        Order::firstOrCreate(
            ['buyer_id' => $this->buyer2->id, 'seller_id' => $this->me->id, 'status' => 'confirmed'],
            ['buyer_id' => $this->buyer2->id, 'seller_id' => $this->me->id, 'status' => 'confirmed',
                'subtotal' => $product->price, 'total' => $product->price, 'shipping_address' => $shippingAddr]
        );

        // Order from bob → me, status: ready_to_ship (for shipping tests)
        $rtsOrder = Order::firstOrCreate(
            ['buyer_id' => $this->buyer2->id, 'seller_id' => $this->me->id, 'status' => 'ready_to_ship'],
            ['buyer_id' => $this->buyer2->id, 'seller_id' => $this->me->id, 'status' => 'ready_to_ship',
                'subtotal' => $product->price, 'total' => $product->price, 'shipping_address' => $shippingAddr,
                'purchased_at' => now()->subDays(2), 'stock_received_at' => now()->subDay()]
        );
        OrderItem::firstOrCreate(['order_id' => $rtsOrder->id, 'product_id' => $product->id], [
            'order_id' => $rtsOrder->id, 'product_id' => $product->id, 'title' => $product->title,
            'quantity' => 1, 'unit_price' => $product->price, 'total_price' => $product->price,
        ]);

        // Create shipment for ready_to_ship order
        Shipment::firstOrCreate(
            ['order_id' => $rtsOrder->id],
            ['order_id' => $rtsOrder->id, 'status' => ShipmentStatus::Created->value]
        );

        // Order from carol → me, status: shipped
        $shippedOrder = Order::firstOrCreate(
            ['buyer_id' => Profile::where('username', 'carol')->first()?->id, 'seller_id' => $this->me->id, 'status' => 'shipped'],
            ['buyer_id' => Profile::where('username', 'carol')->first()?->id, 'seller_id' => $this->me->id, 'status' => 'shipped',
                'subtotal' => $product->price, 'total' => $product->price, 'tracking_number' => 'TRACK999888777',
                'shipping_address' => $shippingAddr, 'shipped_at' => now()->subDay()]
        );
        OrderItem::firstOrCreate(['order_id' => $shippedOrder->id, 'product_id' => $product->id], [
            'order_id' => $shippedOrder->id, 'product_id' => $product->id, 'title' => $product->title,
            'quantity' => 1, 'unit_price' => $product->price, 'total_price' => $product->price,
        ]);
    }

    // ─── 16. SHIPENGINE ORDERS ───────────────────────────────────

    protected function createShipEngineOrders(): void
    {
        // Already handled in createOrdersAsSeller — the ready_to_ship order
        // with shipment record is the ShipEngine test order.
    }

    // ─── 17. BOOKINGS ────────────────────────────────────────────

    protected function createBookings(): void
    {
        $service = Service::where('provider_id', $this->me->id)->first();
        if (! $service) {
            return;
        }

        // Booking from bob to me (provider)
        Booking::firstOrCreate(
            ['customer_id' => $this->buyer2->id, 'service_id' => $service->id],
            ['customer_id' => $this->buyer2->id, 'service_id' => $service->id,
                'provider_id' => $this->me->id, 'booking_date' => now()->addWeek(),
                'booking_time' => '10:00', 'status' => 'pending', 'notes' => 'Need help with API integration.']
        );

        // Booking from me to alice (as customer)
        $aliceService = Service::where('provider_id', $this->seller2->id)->first();
        if ($aliceService) {
            Booking::firstOrCreate(
                ['customer_id' => $this->me->id, 'service_id' => $aliceService->id],
                ['customer_id' => $this->me->id, 'service_id' => $aliceService->id,
                    'provider_id' => $this->seller2->id, 'booking_date' => now()->addWeeks(2),
                    'booking_time' => '14:00', 'status' => 'confirmed', 'notes' => 'Design consultation.']
            );
        }
    }

    // ─── 18. WALLET ──────────────────────────────────────────────

    protected function createWallet(): void
    {
        $wallet = GiftWallet::firstOrCreate(['user_id' => $this->me->id], [
            'user_id' => $this->me->id, 'balance' => 250.00, 'total_deposited' => 250.00,
            'total_withdrawn' => 0.00, 'total_gifted' => 0.00, 'total_received' => 0.00,
        ]);

        $exists = DB::table('gift_wallet_transactions')->where('wallet_id', $wallet->id)->exists();
        if (! $exists) {
            DB::table('gift_wallet_transactions')->insert([
                ['wallet_id' => $wallet->id, 'user_id' => $this->me->id, 'type' => 'top_up',
                    'amount' => 150.00, 'balance_after' => 150.00, 'status' => 'completed',
                    'payment_method' => 'card', 'description' => 'Initial top-up',
                    'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)],
                ['wallet_id' => $wallet->id, 'user_id' => $this->me->id, 'type' => 'top_up',
                    'amount' => 100.00, 'balance_after' => 250.00, 'status' => 'completed',
                    'payment_method' => 'card', 'description' => 'Second top-up',
                    'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)],
            ]);
        }
    }

    // ─── 19. SAVED ITEMS ─────────────────────────────────────────

    protected function createSavedItems(): void
    {
        $product = Product::where('seller_id', $this->seller2->id)->first();
        if ($product) {
            DB::table('product_saves')->updateOrInsert(
                ['user_id' => $this->me->id, 'product_id' => $product->id],
                ['user_id' => $this->me->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    // ─── 20. CART ────────────────────────────────────────────────

    protected function createCart(): void
    {
        $cartProduct = Product::where('seller_id', $this->seller2->id)->where('product_type', 'physical')->first();
        if (! $cartProduct) {
            $cartProduct = Product::where('seller_id', $this->me->id)->where('product_type', 'physical')->first();
        }
        if ($cartProduct) {
            // carts is a header table (just user_id); items go in cart_items
            DB::table('carts')->updateOrInsert(
                ['user_id' => $this->me->id],
                ['user_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]
            );
            $cart = DB::table('carts')->where('user_id', $this->me->id)->first();

            DB::table('cart_items')->updateOrInsert(
                ['cart_id' => $cart->id, 'product_id' => $cartProduct->id],
                [
                    'cart_id' => $cart->id,
                    'product_id' => $cartProduct->id,
                    'item_type' => 'product',
                    'title' => $cartProduct->title,
                    'quantity' => 2,
                    'unit_price' => $cartProduct->price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    // ─── 21. NOTIFICATIONS ───────────────────────────────────────

    protected function createNotifications(): void
    {
        $types = ['order', 'message', 'review', 'connection', 'system'];
        foreach ($types as $i => $type) {
            Notification::firstOrCreate(
                ['user_id' => $this->me->id, 'type' => $type, 'title' => ucfirst($type).' Notification'],
                ['user_id' => $this->me->id, 'type' => $type, 'title' => ucfirst($type).' Notification',
                    'message' => "You have a new {$type} notification.", 'is_read' => $i > 2]
            );
        }
    }

    // ─── 22. DEVICE TOKENS ───────────────────────────────────────

    protected function createDeviceTokens(): void
    {
        DB::table('device_tokens')->updateOrInsert(
            ['user_id' => $this->me->id, 'fcm_token' => 'test_fcm_token_prianka_001'],
            ['user_id' => $this->me->id, 'fcm_token' => 'test_fcm_token_prianka_001',
                'device_type' => 'android', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    // ─── 23. REVIEWS ─────────────────────────────────────────────

    protected function createReviews(): void
    {
        $reviewProduct = Product::where('seller_id', $this->me->id)->first();
        if ($reviewProduct) {
            DB::table('product_reviews')->updateOrInsert(
                ['product_id' => $reviewProduct->id, 'reviewer_id' => $this->buyer2->id],
                ['product_id' => $reviewProduct->id, 'reviewer_id' => $this->buyer2->id,
                    'rating' => 5, 'review_text' => 'Amazing product! Highly recommend.', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    // ─── 24. ESCROW ──────────────────────────────────────────────

    protected function createEscrow(): void
    {
        // escrow_payments is the table (not 'escrows')
        $order = Order::where('seller_id', $this->me->id)->where('status', 'completed')->first();
        if ($order) {
            DB::table('escrow_payments')->updateOrInsert(
                ['order_id' => $order->id],
                [
                    'order_id' => $order->id,
                    'amount' => $order->total,
                    'payment_reference' => 'pi_test_'.$order->id,
                    'status' => 'released',
                    'buyer_id' => $order->buyer_id,
                    'seller_id' => $this->me->id,
                    'payment_received_at' => now()->subDays(10),
                    'escrow_released_at' => now()->subDays(5),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    // ─── 25. JOB APPLICATIONS ────────────────────────────────────

    protected function createJobApplications(): void
    {
        $job = JobListing::where('poster_id', $this->me->id)->first();
        if ($job) {
            JobApplication::firstOrCreate(
                ['job_id' => $job->id, 'applicant_id' => $this->buyer2->id],
                ['job_id' => $job->id, 'applicant_id' => $this->buyer2->id,
                    'cover_letter' => 'I am interested in this position. I have 5+ years of experience.',
                    'status' => 'pending']
            );
        }
    }

    // ─── 26. EVENT TICKETS ───────────────────────────────────────

    protected function createEventTickets(): void
    {
        $event = Event::where('organizer_id', $this->me->id)->first();
        if ($event) {
            EventTicket::firstOrCreate(
                ['event_id' => $event->id, 'customer_id' => $this->buyer2->id],
                ['event_id' => $event->id, 'customer_id' => $this->buyer2->id, 'customer_name' => 'Bob Smith',
                    'ticket_number' => 'TKT-'.strtoupper(uniqid()), 'status' => 'confirmed',
                    'purchased_at' => now()->subDays(2)]
            );
        }
    }

    // ─── 27. LIVESTREAMS ─────────────────────────────────────────

    protected function createLivestreams(): void
    {
        Livestream::firstOrCreate(
            ['host_id' => $this->me->id, 'title' => 'Live Coding: Building a Laravel API'],
            ['host_id' => $this->me->id, 'title' => 'Live Coding: Building a Laravel API',
                'description' => 'Join me as I build a real-time API from scratch.',
                'is_live' => false, 'started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)->addHours(2)]
        );
    }

    // ─── 28. BANK ACCOUNTS ───────────────────────────────────────

    protected function createBankAccounts(): void
    {
        // Seller bank account (for payout tests)
        DB::table('seller_bank_accounts')->updateOrInsert(
            ['seller_id' => $this->me->id, 'account_number' => '1234567890'],
            [
                'seller_id' => $this->me->id,
                'bank_name' => 'Chase Bank',
                'account_name' => 'Prianka Mimi',
                'account_number' => '1234567890',
                'sort_code' => '021000021',
                'is_primary' => true,
                'is_verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Buyer bank account (for payment tests)
        DB::table('buyer_bank_accounts')->updateOrInsert(
            ['buyer_id' => $this->me->id, 'account_number_last4' => '7890'],
            [
                'buyer_id' => $this->me->id,
                'bank_name' => 'Chase Bank',
                'account_name' => 'Prianka Mimi',
                'account_number_last4' => '7890',
                'is_own_account' => true,
                'declaration_signed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    // ─── 29. FOLLOWERS (for social graph) ─────────────────────────

    protected function createFollowers(): void
    {
        // Make some users follow us (so followers/following endpoints work)
        foreach ([$this->seller2, $this->buyer2] as $profile) {
            DB::table('user_connections')->updateOrInsert(
                ['requester_id' => $profile->id, 'recipient_id' => $this->me->id],
                ['requester_id' => $profile->id, 'recipient_id' => $this->me->id, 'status' => 'accepted', 'connection_type' => 'follow', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
