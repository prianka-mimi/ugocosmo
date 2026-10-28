<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\BuyerBankAccount;
use App\Models\SellerBankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminBankAccountController extends Controller
{
    /**
     * Display a listing of payment and payout accounts for moderation.
     */
    public function index(Request $request)
    {
        $tab = $request->input('tab', 'payment');
        $status = $request->input('status', 'pending');
        $search = $request->input('search');

        if ($tab === 'payout') {
            $query = SellerBankAccount::with('seller.user');
            if ($status) {
                $query->where('status', $status);
            }
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%");
                });
            }
            $accounts = $query->latest()->paginate(20)->withQueryString();
        } else {
            $query = BuyerBankAccount::with('buyer.user');
            if ($status) {
                $query->where('status', $status);
            }
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%");
                });
            }
            $accounts = $query->latest()->paginate(20)->withQueryString();
        }

        return view('modules.admin.bank-accounts.index', compact('accounts', 'tab', 'status', 'search'));
    }

    /**
     * Approve a payment/payout bank account.
     */
    public function approve(string $type, $id)
    {
        try {
            if ($type === 'payout') {
                $account = SellerBankAccount::findOrFail($id);
                $account->update([
                    'status' => 'approved',
                    'is_verified' => true,
                ]);
                $entityId = $account->id;
                $entityName = $account->account_name;
            } else {
                $account = BuyerBankAccount::findOrFail($id);
                $account->update([
                    'status' => 'approved',
                ]);
                $entityId = $account->id;
                $entityName = $account->account_name;
            }

            $this->logAudit('approve', 'bank_account_'.$type, $entityId, ['account_name' => $entityName]);

            return back()->with('success', ucfirst($type).' account approved successfully.');
        } catch (Throwable $e) {
            Log::error('Admin bank account approval error: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reject a payment/payout bank account.
     */
    public function reject(string $type, $id)
    {
        try {
            if ($type === 'payout') {
                $account = SellerBankAccount::findOrFail($id);
                $account->update([
                    'status' => 'rejected',
                    'is_verified' => false,
                ]);
                $entityId = $account->id;
                $entityName = $account->account_name;
            } else {
                $account = BuyerBankAccount::findOrFail($id);
                $account->update([
                    'status' => 'rejected',
                ]);
                $entityId = $account->id;
                $entityName = $account->account_name;
            }

            $this->logAudit('reject', 'bank_account_'.$type, $entityId, ['account_name' => $entityName]);

            return back()->with('success', ucfirst($type).' account rejected.');
        } catch (Throwable $e) {
            Log::error('Admin bank account rejection error: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    protected function logAudit(string $action, string $entityType, ?int $entityId, array $metadata): void
    {
        try {
            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'new_value' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            logger()->error('Failed to log admin bank account audit: '.$e->getMessage());
        }
    }
}
