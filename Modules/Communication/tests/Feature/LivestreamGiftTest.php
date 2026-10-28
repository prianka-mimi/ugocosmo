<?php

declare(strict_types=1);

namespace Modules\Communication\Tests\Feature;

use App\Models\GiftWallet;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Communication\Events\LivestreamGiftSent;
use Modules\Communication\Models\CallLog;
use Modules\Communication\Models\Conversation;
use Modules\Communication\Models\ConversationParticipant;
use Modules\Communication\Models\Gift;
use Modules\Communication\Models\LivestreamAmountPreset;
use Tests\TestCase;

class LivestreamGiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_amount_presets_can_be_retrieved(): void
    {
        $sender = User::factory()->create();

        LivestreamAmountPreset::create(['amount' => 5.00, 'sort_order' => 1, 'is_active' => true]);
        LivestreamAmountPreset::create(['amount' => 10.00, 'sort_order' => 2, 'is_active' => true]);
        LivestreamAmountPreset::create(['amount' => 50.00, 'sort_order' => 3, 'is_active' => false]); // inactive

        $response = $this->actingAs($sender, 'api')
            ->getJson('/api/communication/livestreams/amount-presets');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.presets')
            ->assertJsonFragment(['amount' => 5.00])
            ->assertJsonFragment(['amount' => 10.00]);
    }

    public function test_user_can_send_gift_to_livestream_host_using_preset(): void
    {
        Event::fake([LivestreamGiftSent::class]);

        // Create sender and host users with profiles
        $sender = User::factory()->create();
        $senderProfile = Profile::factory()->create(['user_id' => $sender->id]);

        $host = User::factory()->create();
        $hostProfile = Profile::factory()->create(['user_id' => $host->id]);

        // Setup wallets
        $senderWallet = GiftWallet::create([
            'user_id' => $senderProfile->id,
            'balance' => 100.00,
        ]);
        $hostWallet = GiftWallet::create([
            'user_id' => $hostProfile->id,
            'balance' => 10.00,
        ]);

        // Create call log representing livestream
        $conversation = Conversation::create(['is_group' => true, 'name' => 'Live Chat']);

        // Add participants
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $host->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        $callLog = CallLog::create([
            'conversation_id' => $conversation->id,
            'initiator_id' => $host->id,
            'call_type' => 'stream',
            'status' => 'ongoing',
            'is_live' => true,
            'started_at' => now(),
        ]);

        // Create an amount preset
        LivestreamAmountPreset::create(['amount' => 5.00, 'sort_order' => 1, 'is_active' => true]);

        // Send gift via API
        $response = $this->actingAs($sender, 'api')
            ->postJson("/api/communication/livestreams/{$callLog->id}/gifts", [
                'amount' => 5.00,
                'message' => 'Awesome live!',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // Verify wallets updated
        $this->assertEquals(95.00, $senderWallet->fresh()->balance);
        $this->assertEquals(15.00, $hostWallet->fresh()->balance);

        // Verify database records
        $this->assertDatabaseHas('communication_livestream_gifts', [
            'call_log_id' => $callLog->id,
            'sender_id' => $sender->id,
            'amount' => 5.00,
            'message' => 'Awesome live!',
        ]);

        $this->assertDatabaseHas('gift_wallet_transactions', [
            'user_id' => $senderProfile->id,
            'type' => 'gift_sent',
            'amount' => -5.00,
        ]);

        $this->assertDatabaseHas('gift_wallet_transactions', [
            'user_id' => $hostProfile->id,
            'type' => 'gift_received',
            'amount' => 5.00,
        ]);

        // Verify event was dispatched
        Event::assertDispatched(LivestreamGiftSent::class, function ($event) use ($callLog, $sender) {
            return $event->streamId === $callLog->id && $event->payload['sender']['id'] === $sender->id;
        });
    }

    public function test_user_can_send_predefined_gift(): void
    {
        Event::fake([LivestreamGiftSent::class]);

        $sender = User::factory()->create();
        $senderProfile = Profile::factory()->create(['user_id' => $sender->id]);

        $host = User::factory()->create();
        $hostProfile = Profile::factory()->create(['user_id' => $host->id]);

        GiftWallet::create(['user_id' => $senderProfile->id, 'balance' => 50.00]);
        GiftWallet::create(['user_id' => $hostProfile->id, 'balance' => 0.00]);

        $conversation = Conversation::create(['is_group' => true, 'name' => 'Live Chat']);

        $callLog = CallLog::create([
            'conversation_id' => $conversation->id,
            'initiator_id' => $host->id,
            'call_type' => 'stream',
            'status' => 'ongoing',
            'is_live' => true,
            'started_at' => now(),
        ]);

        // Predefined gift in gifts table
        $gift = Gift::create([
            'title' => 'Rose',
            'icon_url' => 'https://example.com/rose.png',
            'coin_cost' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($sender, 'api')
            ->postJson("/api/communication/livestreams/{$callLog->id}/gifts", [
                'gift_id' => $gift->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.gift.type', 'Rose')
            ->assertJsonPath('data.gift.amount', 10.00);

        $this->assertDatabaseHas('communication_livestream_gifts', [
            'call_log_id' => $callLog->id,
            'sender_id' => $sender->id,
            'gift_id' => $gift->id,
            'amount' => 10.00,
        ]);
    }
}
