<?php

namespace App\Http\Controllers;

use App\Http\Requests\BankAccountRequest;
use App\Http\Requests\CreateDisputeRequest;
use App\Http\Requests\EscrowIndexRequest;
use App\Http\Requests\ResolveDisputeRequest;
use App\Http\Resources\BankAccountResource;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\EscrowResource;
use App\Manager\EscrowManager;
use App\Models\Dispute;
use App\Models\EscrowPayment;
use App\Models\Order;
use App\Models\SellerBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * EscrowController - Escrow, disputes, payouts, and bank accounts.
 *
 * Escrow holds payment until order is fulfilled.
 * Disputes allow buyers to challenge transactions.
 * Payouts send funds to seller bank accounts.
 */
class EscrowController extends Controller
{
    protected EscrowManager $escrowManager;

    public function __construct(EscrowManager $escrowManager)
    {
        $this->escrowManager = $escrowManager;
    }

    // ─── Escrow ──────────────────────────────────────────────────

    /**
     * Create escrow from a completed order.
     */
    public function create(Order $order): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = EscrowResource::make(
                $this->escrowManager->createFromOrder($order)
            );
            DB::commit();

            $this->status_message = 'Escrow payment created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Escrow creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Release escrow funds to the seller.
     */
    public function release(EscrowPayment $escrow): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = EscrowResource::make(
                $this->escrowManager->release($escrow)
            );
            DB::commit();

            $this->status_message = 'Escrow released successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Escrow release error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * List escrow payments for the authenticated user.
     */
    public function myEscrows(EscrowIndexRequest $request): JsonResponse
    {
        try {
            $role = $request->input('role', 'buyer');

            if (! in_array($role, ['buyer', 'seller'])) {
                $role = 'buyer';
            }

            $perPage = (int) $request->input('per_page', 20);

            $this->data = EscrowResource::collection(
                $this->escrowManager->myEscrows($role, $perPage)
            )->response()->getData();

            $this->status_message = 'Escrows retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Escrows list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get a single escrow payment detail.
     */
    public function showEscrow(EscrowPayment $escrow): JsonResponse
    {
        try {
            $this->data = EscrowResource::make(
                $this->escrowManager->show($escrow)
            );
            $this->status_message = 'Escrow retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Escrow detail error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Disputes ─────────────────────────────────────────────────

    /**
     * Raise a dispute on an escrow payment.
     */
    public function createDispute(CreateDisputeRequest $request, EscrowPayment $escrow): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = DisputeResource::make(
                $this->escrowManager->createDispute($escrow, $request->validated())
            );
            DB::commit();

            $this->status_message = 'Dispute raised successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Dispute creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Resolve a dispute (admin only).
     */
    public function resolveDispute(ResolveDisputeRequest $request, Dispute $dispute): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = DisputeResource::make(
                $this->escrowManager->resolveDispute($dispute, $request->validated())
            );
            DB::commit();

            $this->status_message = 'Dispute resolved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Dispute resolution error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get disputes for the authenticated user.
     */
    public function myDisputes(EscrowIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);

            $this->data = DisputeResource::collection(
                $this->escrowManager->myDisputes($perPage)
            )->response()->getData();

            $this->status_message = 'Disputes retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Disputes list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get a single dispute detail.
     */
    public function showDispute(Dispute $dispute): JsonResponse
    {
        try {
            $this->data = DisputeResource::make(
                $this->escrowManager->showDispute($dispute)
            );
            $this->status_message = 'Dispute retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Dispute detail error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Payouts ──────────────────────────────────────────────────

    /**
     * Get payouts for the authenticated seller.
     */
    public function myPayouts(EscrowIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);

            $this->data = $this->escrowManager->myPayouts($perPage);
            $this->status_message = 'Payouts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Payouts list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Bank Accounts ────────────────────────────────────────────

    /**
     * List seller bank accounts.
     */
    public function bankAccounts(): JsonResponse
    {
        try {
            $profileId = $this->profileId();

            $accounts = SellerBankAccount::bySeller($profileId)
                ->orderBy('is_primary', 'desc')
                ->get();

            $this->data = BankAccountResource::collection($accounts);
            $this->status_message = 'Bank accounts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Bank accounts list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Add a new bank account.
     */
    public function addBankAccount(BankAccountRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $profileId = $this->profileId();

            $data = $request->validated();
            $data['seller_id'] = $profileId;

            // Handle bank statement upload
            if ($request->hasFile('bank_statement')) {
                $data['statement_path'] = $request->file('bank_statement')->store('payout-accounts/statements', 'public');
            }

            // If this is the first account, auto-set as primary
            $existingCount = SellerBankAccount::bySeller($profileId)->count();
            if ($existingCount === 0) {
                $data['is_primary'] = true;
            }

            $account = SellerBankAccount::create($data);
            DB::commit();

            $this->data = BankAccountResource::make($account);
            $this->status_message = 'Bank account added successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Add bank account error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Update a bank account.
     */
    public function updateBankAccount(BankAccountRequest $request, SellerBankAccount $account): JsonResponse
    {
        try {
            $this->ensureBankOwned($account);

            DB::beginTransaction();
            $data = $request->validated();

            // Handle bank statement upload
            if ($request->hasFile('bank_statement')) {
                if ($account->statement_path && Storage::disk('public')->exists($account->statement_path)) {
                    Storage::disk('public')->delete($account->statement_path);
                }
                $data['statement_path'] = $request->file('bank_statement')->store('payout-accounts/statements', 'public');
            }

            $account->update($data);
            DB::commit();

            $this->data = BankAccountResource::make($account->fresh());
            $this->status_message = 'Bank account updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Update bank account error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Delete a bank account.
     */
    public function deleteBankAccount(SellerBankAccount $account): JsonResponse
    {
        try {
            $this->ensureBankOwned($account);

            DB::beginTransaction();
            // Delete storage file if exists
            if ($account->statement_path && Storage::disk('public')->exists($account->statement_path)) {
                Storage::disk('public')->delete($account->statement_path);
            }

            $account->delete();
            DB::commit();

            $this->data = [];
            $this->status_message = 'Bank account deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Delete bank account error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Helpers ─────────────────────────────────────────────────

    protected function ensureBankOwned(SellerBankAccount $account): void
    {
        if ((int) $account->seller_id !== $this->profileId()) {
            abort(404);
        }
    }

    protected function profileId(): int
    {
        $user = auth('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
