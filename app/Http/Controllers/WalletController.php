<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendGiftRequest;
use App\Http\Requests\TopUpRequest;
use App\Http\Requests\WalletTransactionIndexRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Manager\WalletManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WalletController - Virtual wallet endpoints.
 *
 * Users can top up their wallet, check balance, send gifts,
 * and view transaction history.
 */
class WalletController extends Controller
{
    protected WalletManager $walletManager;

    public function __construct(WalletManager $walletManager)
    {
        $this->walletManager = $walletManager;
    }

    /**
     * Get wallet details with balance.
     */
    public function show(): JsonResponse
    {
        try {
            $this->data = WalletResource::make($this->walletManager->show());
            $this->status_message = 'Wallet retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Wallet retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get current wallet balance only.
     */
    public function balance(): JsonResponse
    {
        try {
            $this->data = $this->walletManager->balance();
            $this->status_message = 'Balance retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Balance retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get paginated transaction history.
     */
    public function transactions(WalletTransactionIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);

            $this->data = TransactionResource::collection(
                $this->walletManager->transactions($perPage)
            )->response()->getData();

            $this->status_message = 'Transactions retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Transactions retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Top up wallet with funds.
     * Returns client_secret, payment_intent_id, and direct payment link.
     */
    public function topUp(TopUpRequest $request): JsonResponse
    {
        try {
            $this->data = $this->walletManager->initiateTopUp($request->validated());
            $this->status_message = 'Top-up initiated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Wallet top-up error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Confirm a top-up after client payment completion.
     */
    public function confirmTopUp(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'payment_intent_id' => 'required|string',
            ]);

            $this->data = $this->walletManager->confirmTopUp($request->input('payment_intent_id'));
            $this->status_message = 'Wallet topped up successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Wallet top-up confirm error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Send a gift to another user.
     */
    public function sendGift(SendGiftRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->walletManager->sendGift($request->validated());
            DB::commit();

            $this->status_message = 'Gift sent successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Send gift error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
