<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuyerBankAccountRequest;
use App\Http\Resources\BuyerBankAccountResource;
use App\Models\BuyerBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuyerBankAccountController extends Controller
{
    /**
     * Get all payment accounts for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            if (! $profile) {
                throw new \Exception('Profile not found. Complete your profile first.', 400);
            }

            $accounts = BuyerBankAccount::byBuyer((int) $profile->id)->latest()->get();

            $this->data = BuyerBankAccountResource::collection($accounts);
            $this->status_message = 'Payment accounts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Buyer bank accounts index error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Register a new payment account.
     */
    public function store(BuyerBankAccountRequest $request): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            if (! $profile) {
                throw new \Exception('Profile not found. Complete your profile first.', 400);
            }

            $validated = $request->validated();

            // Store statement file
            $statementPath = null;
            if ($request->hasFile('bank_statement')) {
                $statementPath = $request->file('bank_statement')->store('payment-accounts/statements', 'public');
            }

            // Extract last 4 digits
            $accountNumber = $validated['account_number'];
            $last4 = substr($accountNumber, -4);

            $account = BuyerBankAccount::create([
                'buyer_id' => $profile->id,
                'bank_name' => $validated['bank_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $accountNumber,
                'account_number_last4' => $last4,
                'sort_code' => $validated['sort_code'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'swift_code' => $validated['swift_code'] ?? null,
                'statement_path' => $statementPath,
                'is_own_account' => (bool) $validated['is_own_account'],
                'status' => 'pending',
                'declaration_signed_at' => $validated['is_own_account'] ? now() : null,
            ]);

            $this->data = BuyerBankAccountResource::make($account);
            $this->status_message = 'Payment account registered successfully and is pending review';
            $this->status_code = 201;
        } catch (Throwable $e) {
            Log::error('Buyer bank account creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Update a payment account.
     */
    public function update(BuyerBankAccountRequest $request, BuyerBankAccount $paymentAccount): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            if (! $profile || (int) $paymentAccount->buyer_id !== (int) $profile->id) {
                throw new \Exception('Unauthorized', 403);
            }

            $validated = $request->validated();

            // Store new statement file if uploaded
            if ($request->hasFile('bank_statement')) {
                if ($paymentAccount->statement_path && Storage::disk('public')->exists($paymentAccount->statement_path)) {
                    Storage::disk('public')->delete($paymentAccount->statement_path);
                }
                $validated['statement_path'] = $request->file('bank_statement')->store('payment-accounts/statements', 'public');
            }

            // Update account number details if changed
            if (isset($validated['account_number'])) {
                $validated['account_number_last4'] = substr($validated['account_number'], -4);
            }

            // Keep status pending for review on any changes
            $validated['status'] = 'pending';

            if (isset($validated['is_own_account'])) {
                $validated['declaration_signed_at'] = $validated['is_own_account'] ? now() : null;
            }

            $paymentAccount->update($validated);

            $this->data = BuyerBankAccountResource::make($paymentAccount->fresh());
            $this->status_message = 'Payment account updated successfully and is pending review';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Buyer bank account update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Delete a payment account.
     */
    public function destroy(BuyerBankAccount $paymentAccount): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            if (! $profile || (int) $paymentAccount->buyer_id !== (int) $profile->id) {
                throw new \Exception('Unauthorized', 403);
            }

            // Delete storage file if exists
            if ($paymentAccount->statement_path && Storage::disk('public')->exists($paymentAccount->statement_path)) {
                Storage::disk('public')->delete($paymentAccount->statement_path);
            }

            $paymentAccount->delete();

            $this->data = [];
            $this->status_message = 'Payment account deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Buyer bank account deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
