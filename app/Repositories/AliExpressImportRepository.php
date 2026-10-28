<?php

namespace App\Repositories;

use App\Models\AliExpressImport;
use Illuminate\Pagination\LengthAwarePaginator;

class AliExpressImportRepository
{
    public function create(array $data): AliExpressImport
    {
        return AliExpressImport::create($data);
    }

    public function findById(int $id): ?AliExpressImport
    {
        return AliExpressImport::find($id);
    }

    /**
     * Find an import with account + ownership validation.
     */
    public function findForUser(int $id, int $accountId, int $userId): ?AliExpressImport
    {
        return AliExpressImport::where('id', $id)
            ->where('aliexpress_account_id', $accountId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Import history for an account with logs eager-loaded.
     */
    public function getByAccount(int $accountId, int $perPage = 20): LengthAwarePaginator
    {
        return AliExpressImport::where('aliexpress_account_id', $accountId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * A single import with its recent logs.
     */
    public function findWithLogs(int $id, int $accountId): ?AliExpressImport
    {
        return AliExpressImport::where('id', $id)
            ->where('aliexpress_account_id', $accountId)
            ->with(['logs' => fn ($q) => $q->latest()->limit(500)])
            ->first();
    }

    /**
     * Update progress counters on an import record.
     */
    public function updateProgress(int $importId, array $data): ?AliExpressImport
    {
        $import = AliExpressImport::find($importId);
        if ($import) {
            $import->update($data);
        }

        return $import;
    }

    public function getActive(int $accountId): ?AliExpressImport
    {
        return AliExpressImport::where('aliexpress_account_id', $accountId)
            ->whereIn('status', ['pending', 'queued', 'processing'])
            ->latest()
            ->first();
    }
}
