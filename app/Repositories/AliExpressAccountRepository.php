<?php

namespace App\Repositories;

use App\Models\AliExpressAccount;
use App\Models\AliExpressApiLog;
use App\Models\AliExpressImport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;

class AliExpressAccountRepository
{
    /**
     * Find an account by ID with ownership validation.
     */
    public function findById(int $id, int $userId): ?AliExpressAccount
    {
        return AliExpressAccount::where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Find an account by ID without ownership validation (internal use).
     */
    public function findByIdRaw(int $id): ?AliExpressAccount
    {
        return AliExpressAccount::whereNull('deleted_at')->find($id);
    }

    /**
     * All accounts connected by a user.
     */
    public function getByUser(int $userId): Collection
    {
        return AliExpressAccount::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * All active accounts across all sellers (for scheduled syncs).
     */
    public function getAllActive(): Collection
    {
        return AliExpressAccount::active()->get();
    }

    // ─── Admin panel ──────────────────────────────────────────────

    /**
     * Paginated accounts including disabled (soft-deleted) ones, with
     * seller info and per-account import/product counts.
     *
     * @param  array{status?: string, region?: string, search?: string}  $filters
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = AliExpressAccount::withTrashed()
            ->withCount(['imports', 'products'])
            ->with(['profile:id,full_name,username,email', 'user:id,name,email']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['region'])) {
            $query->where('region', $filters['region']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('account_name', 'like', "%{$search}%")
                    ->orWhere('aliexpress_member_id', 'like', "%{$search}%")
                    ->orWhereHas('profile', fn ($p) => $p
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Find an account including disabled (soft-deleted) ones.
     */
    public function findWithTrashed(int $id): ?AliExpressAccount
    {
        return AliExpressAccount::withTrashed()->find($id);
    }

    /**
     * Summary numbers for the admin panel header cards.
     */
    public function overviewStats(): array
    {
        $since = now()->subDays(7);

        return [
            'total' => AliExpressAccount::withTrashed()->count(),
            'active' => AliExpressAccount::active()->count(),
            'disabled' => AliExpressAccount::withTrashed()
                ->where('status', AliExpressAccount::STATUS_DISABLED)
                ->count(),
            'expiring_soon' => AliExpressAccount::withExpiringToken(72)->count(),
            'failed_imports_7d' => AliExpressImport::whereIn('status', ['failed', 'partial'])
                ->where('created_at', '>=', $since)
                ->count(),
            'api_errors_7d' => AliExpressApiLog::where('success', false)
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * Create a new connection, encrypting tokens at rest.
     */
    public function create(array $data): AliExpressAccount
    {
        return AliExpressAccount::create($this->encryptTokens($data));
    }

    /**
     * Update a connection, re-encrypting any provided tokens.
     */
    public function update(AliExpressAccount $account, array $data): AliExpressAccount
    {
        $account->update($this->encryptTokens($data));

        return $account->fresh();
    }

    /**
     * Disable a connection without deleting imported products.
     */
    public function disable(AliExpressAccount $account): AliExpressAccount
    {
        $account->update([
            'status' => AliExpressAccount::STATUS_DISABLED,
            'deleted_at' => now(),
        ]);

        return $account->fresh();
    }

    /**
     * Reactivate a previously disabled connection.
     */
    public function activate(AliExpressAccount $account): AliExpressAccount
    {
        $account->update([
            'status' => AliExpressAccount::STATUS_ACTIVE,
            'deleted_at' => null,
        ]);

        return $account->fresh();
    }

    /**
     * Decrypt the access token for API calls.
     */
    public function decryptAccessToken(AliExpressAccount $account): string
    {
        return $this->decrypt($account->access_token);
    }

    /**
     * Decrypt the refresh token for token refresh calls.
     */
    public function decryptRefreshToken(AliExpressAccount $account): ?string
    {
        if (empty($account->refresh_token)) {
            return null;
        }

        return $this->decrypt($account->refresh_token);
    }

    protected function decrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value; // fallback for legacy/plaintext rows
        }
    }

    protected function encryptTokens(array $data): array
    {
        if (isset($data['access_token']) && ! empty($data['access_token'])) {
            $data['access_token'] = Crypt::encryptString($data['access_token']);
        }
        if (isset($data['refresh_token']) && ! empty($data['refresh_token'])) {
            $data['refresh_token'] = Crypt::encryptString($data['refresh_token']);
        }

        return $data;
    }
}
