<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Modules\Communication\Http\Controllers\CallController;
use Modules\Communication\Http\Controllers\CallHistoryController;
use Modules\Communication\Http\Controllers\CallWebhookController;
use Modules\Communication\Http\Controllers\ConversationController;
use Modules\Communication\Http\Controllers\GiftController;
use Modules\Communication\Http\Controllers\LivestreamController;
use Modules\Communication\Http\Controllers\MessageController;

$broadcastDriver = (string) env('COMMUNICATION_BROADCAST_DRIVER', 'reverb');
$broadcastAuthMiddleware = (string) env('COMMUNICATION_BROADCAST_AUTH_MIDDLEWARE', 'auth:api');

try {
    if (in_array($broadcastDriver, ['reverb', 'pusher'], true)) {
        Broadcast::routes(['middleware' => [$broadcastAuthMiddleware], 'prefix' => 'api/communication']);
        require __DIR__.'/channels.php';
    }
} catch (Throwable) {
    // Skip broadcast registration when broadcasting credentials are not configured.
}

$authMiddleware = (string) env('COMMUNICATION_API_AUTH_MIDDLEWARE', 'auth:api');

// Public Webhook Endpoints
Route::prefix('api/communication')->controller(CallWebhookController::class)->group(function (): void {
    Route::post('/webhooks/livekit', 'handleLiveKitWebhook');
    Route::post('/webhooks/agora', 'handleAgoraWebhook');
});

Route::middleware([$authMiddleware])->prefix('api/communication')->group(function (): void {

    Route::controller(ConversationController::class)->group(function (): void {
        // Settings APIs (Group + General Chat)
        Route::get('/conversations/{id}/settings', 'groupSettings');
        Route::patch('/conversations/{id}/settings', 'updateGroupSettings');

        // Backward-compatible Group Settings aliases
        Route::get('/conversations/{id}/group/settings', 'groupSettings');
        Route::patch('/conversations/{id}/group/settings', 'updateGroupSettings');

        // Group Chat APIs
        Route::post('/conversations/{id}/group/members', 'addGroupMembers');
        Route::post('/conversations/{id}/group/members/admin', 'promoteGroupMembers');
        Route::delete('/conversations/{id}/group/members', 'removeGroupMembers');
        Route::post('/conversations/{id}/group/leave', 'leaveGroup');
        Route::delete('/conversations/{id}/group', 'deleteGroup'); // this delete conversation for all, only for group chat , entirely , even from db

        // Single Chat + Shared Conversation APIs
        Route::post('/conversations', 'start');
        Route::get('/conversations', 'index');
        Route::delete('/conversations/{id}', 'deleteConversationFromList');
        Route::delete('/conversations/{id}/history', 'clearConversationHistory');
        Route::get('/conversations/{id}/messages', 'messages');
        Route::get('/conversations/{id}/messages/search', 'searchMessages');
        Route::post('/conversations/{id}/messages', 'sendMessage');
        Route::post('/conversations/{id}/messages/gift', 'sendGift');
    });

    Route::controller(MessageController::class)->group(function (): void {
        // Message APIs
        Route::get('/messages/{id}/info', 'info');
        Route::post('/messages/{id}/reaction', 'react');
    });

    // Gift APIs
    Route::get('/gifts', [GiftController::class, 'index']);

    // call related apis
    Route::controller(CallController::class)->group(function (): void {
        Route::post('/calls/initiate', 'initiate');
        // Route::get('/calls/history', [CallHistoryController::class, 'index']);
        Route::get('/calls/{id}', 'show');
        Route::post('/calls/{id}/join', 'join');
        // Route::post('/calls/{id}/leave', 'leave');
        // Route::post('/calls/{id}/kick', 'kick');
        // Route::post('/calls/{id}/mute-participant', 'muteParticipant');
        Route::post('/calls/{id}/end', 'end');
        Route::get('/conversations/{id}/active-call', 'checkActive');
    });

    // livestream apis
    Route::controller(LivestreamController::class)->group(function (): void {
        Route::post('/livestreams/start', 'start');
        Route::get('/livestreams/amount-presets', 'amountPresets');
        Route::get('/livestreams/gift/price-configuration', 'giftPriceConfiguration');
        Route::get('/livestreams/price-configuration', 'giftPriceConfiguration');
        Route::get('/livestreams/active', 'active');
        Route::post('/livestreams/{id}/join', 'join');
        Route::post('/livestreams/{id}/leave', 'leave');
        Route::post('/livestreams/{id}/end', 'end');

        Route::post('/livestreams/{id}/invite-host', 'inviteHost');
        Route::post('/livestreams/{id}/accept-host', 'acceptHost');
        Route::post('/livestreams/{id}/reject-host', 'rejectHost');
        Route::post('/livestreams/{id}/revoke-host', 'revokeHost');

        Route::put('/livestreams/{id}', 'update');
        Route::get('/livestreams/{id}', 'show');

        Route::get('/livestreams/questions/generate', 'generateQuestions');
        Route::get('/livestreams/{id}/questions', 'getQuestions');
        Route::post('/livestreams/{id}/game-bet', 'placeGameBet');
        Route::post('/livestreams/{id}/game/bet', 'placeGameBet');

        Route::post('/livestreams/{id}/gifts', 'storeGift');
        Route::get('/livestreams/{id}/gifts', 'indexGifts');
        Route::get('/livestreams/{id}/top-gifters', 'topGifters');
        Route::get('/livestreams/{id}/host-items', 'hostItems');
        Route::get('/livestreams/{id}/featured-items', 'getFeaturedItems');
        Route::post('/livestreams/{id}/featured-items', 'featureItem');

        // Comments & Likes APIs
        Route::get('/livestreams/{id}/comments', 'indexComments');
        Route::post('/livestreams/{id}/comments', 'storeComment');
        Route::post('/livestreams/{id}/like', 'toggleStreamLike');
        Route::post('/livestreams/comments/{commentId}/like', 'toggleCommentLike');
        Route::delete('/livestreams/comments/{commentId}', 'deleteComment');

        // Polls APIs
        Route::post('/livestreams/{id}/polls', 'storePoll');
        Route::get('/livestreams/{id}/polls/active', 'showPoll');
        Route::post('/livestreams/polls/{pollId}/vote', 'votePoll');
        Route::post('/livestreams/polls/{pollId}/end', 'endPoll');

        Route::post('/livestreams/gifts/confirm', 'confirmGift');
    });

    // new user who i havn't chatted with before, list
    Route::get('/conversations/new-users', [ConversationController::class, 'newUsers']);
});
