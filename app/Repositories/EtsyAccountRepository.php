<?php

namespace App\Repositories;

use App\Models\EtsyAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;

class EtsyAccountRepository
{
    /**
     * Find an account by ID with ownership validation.
     */
    public function findById(int $id, int $userId): ?EtsyAccount
    {
        return EtsyAccount::where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Find an account by ID without ownership validation (internal use).
     */
    public function findByIdRaw(int $id): ?EtsyAccount
    {
        return EtsyAccount::whereNull('deleted_at')->find($id);
    }

    /**
     * All accounts connected by a user.
     */
    public function getByUser(int $userId): Collection
    {
        return EtsyAccount::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * All active accounts across all sellers (for scheduled syncs).
     */
    public function getAllActive(): Collection
    {
        return EtsyAccount::active()->get();
    }

    /**
     * Create a new connection, encrypting tokens at rest.
     */
    public function create(array $data): EtsyAccount
    {
        return EtsyAccount::create($this->encryptTokens($data));
    }

    /**
     * Update a connection, re-encrypting any provided tokens.
     */
    public function update(EtsyAccount $account, array $data): EtsyAccount
    {
        $account->update($this->encryptTokens($data));

        return $account->fresh();
    }

    /**
     * Disable a connection without deleting imported products.
     */
    public function disable(EtsyAccount $account): EtsyAccount
    {
        $account->update([
            'status' => EtsyAccount::STATUS_DISABLED,
            'deleted_at' => now(),
        ]);

        return $account->fresh();
    }

    /**
     * Reactivate a previously disabled connection.
     */
    public function activate(EtsyAccount $account): EtsyAccount
    {
        $account->update([
            'status' => EtsyAccount::STATUS_ACTIVE,
            'deleted_at' => null,
        ]);

        return $account->fresh();
    }

    /**
     * Decrypt the access token for API calls.
     */
    public function decryptAccessToken(EtsyAccount $account): string
    {
        return $this->decrypt($account->access_token);
    }

    /**
     * Decrypt the refresh token for token refresh calls.
     */
    public function decryptRefreshToken(EtsyAccount $account): ?string
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
