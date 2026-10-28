<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AliExpress\OAuthException;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AliExpressAccount;
use App\Models\AliExpressApiLog;
use App\Models\AliExpressImport;
use App\Models\AliExpressSyncLog;
use App\Repositories\AliExpressAccountRepository;
use App\Services\AliExpressTokenManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin panel for the AliExpress supplier integration.
 *
 * Admins can audit connected sellers, disable/re-enable connections,
 * force a token refresh, and review import logs, sync logs, failed
 * requests and API errors. Tokens are never exposed.
 */
class AdminAliExpressController extends Controller
{
    public function __construct(
        protected AliExpressAccountRepository $accounts,
        protected AliExpressTokenManager $tokens,
    ) {}

    /**
     * Connected sellers overview.
     */
    public function index(Request $request)
    {
        $accounts = $this->accounts->paginateForAdmin(
            $request->only(['status', 'region', 'search']),
            20
        );
        $stats = $this->accounts->overviewStats();
        $statuses = [AliExpressAccount::STATUS_ACTIVE, AliExpressAccount::STATUS_DISABLED];
        $regions = ['sg' => 'Southeast Asia', 'global' => 'Global'];

        return view('modules.admin.aliexpress.index', compact('accounts', 'stats', 'statuses', 'regions'));
    }

    /**
     * Single connection detail: seller, token health, imports, syncs, API logs.
     */
    public function show(int $account)
    {
        $account = $this->accounts->findWithTrashed($account);
        abort_unless($account, 404);

        $account->load(['profile:id,full_name,username,email', 'user:id,name,email']);

        $imports = AliExpressImport::where('aliexpress_account_id', $account->id)
            ->latest()
            ->limit(15)
            ->get();

        $apiLogs = AliExpressApiLog::where('aliexpress_account_id', $account->id)
            ->latest()
            ->limit(15)
            ->get();

        $syncLogs = AliExpressSyncLog::where('aliexpress_account_id', $account->id)
            ->latest()
            ->limit(15)
            ->get();

        return view('modules.admin.aliexpress.show', compact('account', 'imports', 'apiLogs', 'syncLogs'));
    }

    /**
     * Import runs across all sellers.
     */
    public function imports(Request $request)
    {
        $query = AliExpressImport::query()->with([
            'account' => fn ($q) => $q->withTrashed()->with('profile:id,full_name,username'),
        ]);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($accountId = $request->input('account_id')) {
            $query->where('aliexpress_account_id', (int) $accountId);
        }

        if ($request->boolean('failed')) {
            $query->whereIn('status', ['failed', 'partial']);
        }

        $imports = $query->latest()->paginate(20)->withQueryString();

        $statuses = ['pending', 'queued', 'processing', 'completed', 'partial', 'failed', 'cancelled'];
        $accounts = AliExpressAccount::withTrashed()
            ->orderBy('account_name')
            ->get(['id', 'account_name']);

        return view('modules.admin.aliexpress.imports', compact('imports', 'statuses', 'accounts'));
    }

    /**
     * A single import run with its per-product logs.
     */
    public function importShow(int $import)
    {
        $import = AliExpressImport::with([
            'account' => fn ($q) => $q->withTrashed()->with('profile:id,full_name,username'),
            'logs' => fn ($q) => $q->latest()->limit(500),
        ])->findOrFail($import);

        return view('modules.admin.aliexpress.import_show', compact('import'));
    }

    /**
     * Background sync runs (category sync, token refresh).
     */
    public function syncLogs(Request $request)
    {
        $query = AliExpressSyncLog::query()->with([
            'account' => fn ($q) => $q->withTrashed()->with('profile:id,full_name,username'),
        ]);

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($accountId = $request->input('account_id')) {
            $query->where('aliexpress_account_id', (int) $accountId);
        }

        $logs = $query->latest()->paginate(20)->withQueryString();

        $types = [
            AliExpressSyncLog::TYPE_CATEGORY_SYNC,
            AliExpressSyncLog::TYPE_TOKEN_REFRESH,
            AliExpressSyncLog::TYPE_PRODUCT_SYNC,
        ];
        $statuses = [AliExpressSyncLog::STATUS_STARTED, AliExpressSyncLog::STATUS_COMPLETED, AliExpressSyncLog::STATUS_FAILED];
        $accounts = AliExpressAccount::withTrashed()
            ->orderBy('account_name')
            ->get(['id', 'account_name']);

        return view('modules.admin.aliexpress.sync_logs', compact('logs', 'types', 'statuses', 'accounts'));
    }

    /**
     * Raw API request/response log — failed requests & API errors live here.
     *
     * Filter: ?result=all|failed|errors&method=&account_id=
     */
    public function apiLogs(Request $request)
    {
        $query = AliExpressApiLog::query()->with([
            'account' => fn ($q) => $q->withTrashed()->with('profile:id,full_name,username'),
        ]);

        $result = $request->input('result', 'all');
        if ($result === 'failed') {
            $query->where('success', false);
        } elseif ($result === 'errors') {
            $query->where('success', false)->whereNotNull('error_code');
        }

        if ($method = $request->input('method')) {
            $query->where('method', $method);
        }

        if ($accountId = $request->input('account_id')) {
            $query->where('aliexpress_account_id', (int) $accountId);
        }

        $logs = $query->latest()->paginate(20)->withQueryString();

        $methods = AliExpressApiLog::query()
            ->distinct()
            ->orderBy('method')
            ->pluck('method');
        $accounts = AliExpressAccount::withTrashed()
            ->orderBy('account_name')
            ->get(['id', 'account_name']);

        return view('modules.admin.aliexpress.api_logs', compact('logs', 'methods', 'accounts', 'result'));
    }

    /**
     * Disable a seller connection (imported products are preserved).
     */
    public function disable(int $account)
    {
        $account = $this->accounts->findWithTrashed($account);
        abort_unless($account, 404);

        if ($account->status !== AliExpressAccount::STATUS_ACTIVE) {
            return back()->with('error', 'This connection is not active.');
        }

        try {
            DB::beginTransaction();

            $this->accounts->disable($account);

            $this->logAudit('aliexpress_account_disabled', $account->id, [
                'account_name' => $account->account_name,
                'profile_id' => $account->profile_id,
            ]);

            DB::commit();

            return back()->with('success', 'AliExpress connection disabled. Imported products are preserved.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Admin: AliExpress disable failed: '.$e->getMessage());

            return back()->with('error', 'Failed to disable connection: '.$e->getMessage());
        }
    }

    /**
     * Re-enable a previously disabled connection.
     */
    public function reactivate(int $account)
    {
        $account = $this->accounts->findWithTrashed($account);
        abort_unless($account, 404);

        if ($account->status !== AliExpressAccount::STATUS_DISABLED) {
            return back()->with('error', 'Only disabled connections can be re-enabled.');
        }

        try {
            DB::beginTransaction();

            $this->accounts->activate($account);

            $this->logAudit('aliexpress_account_reactivated', $account->id, [
                'account_name' => $account->account_name,
                'profile_id' => $account->profile_id,
            ]);

            DB::commit();

            return back()->with('success', 'AliExpress connection re-enabled.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Admin: AliExpress reactivate failed: '.$e->getMessage());

            return back()->with('error', 'Failed to re-enable connection: '.$e->getMessage());
        }
    }

    /**
     * Force a token refresh — the "reconnect" action. If the refresh token
     * is still valid this re-authenticates the connection automatically;
     * otherwise the seller must re-authorize from their own app.
     */
    public function refreshToken(int $account)
    {
        $account = $this->accounts->findWithTrashed($account);
        abort_unless($account, 404);

        try {
            $this->tokens->refresh($account);

            $this->logAudit('aliexpress_token_refreshed', $account->id, [
                'account_name' => $account->account_name,
                'profile_id' => $account->profile_id,
            ]);

            return back()->with('success', 'AliExpress access token refreshed successfully.');
        } catch (OAuthException $e) {
            return back()->with('error', 'Token refresh failed: '.$e->getMessage().' The seller must reconnect from their own app.');
        } catch (Throwable $e) {
            Log::error('Admin: AliExpress token refresh failed: '.$e->getMessage());

            return back()->with('error', 'Token refresh failed: '.$e->getMessage());
        }
    }

    /**
     * Record an admin action for the audit trail.
     */
    protected function logAudit(string $action, int $entityId, array $metadata = []): void
    {
        try {
            $adminId = auth()->user()?->profile?->id;

            if (! $adminId) {
                return;
            }

            AdminAuditLog::create([
                'admin_id' => $adminId,
                'action' => $action,
                'entity_type' => 'aliexpress_account',
                'entity_id' => $entityId,
                'new_value' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (Throwable $e) {
            // Audit logging must never break the admin action.
            Log::warning('Admin: failed to log AliExpress audit: '.$e->getMessage());
        }
    }
}
