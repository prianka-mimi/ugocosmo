<?php

namespace App\Repositories;

use App\Models\EtsyImport;
use Illuminate\Pagination\LengthAwarePaginator;

class EtsyImportRepository
{
    public function create(array $data): EtsyImport
    {
        return EtsyImport::create($data);
    }

    public function findById(int $id): ?EtsyImport
    {
        return EtsyImport::find($id);
    }

    /**
     * Find an import with profile + ownership validation.
     */
    public function findForUser(int $id, int $profileId, int $userId): ?EtsyImport
    {
        return EtsyImport::where('id', $id)
            ->where('profile_id', $profileId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Import history for a profile with logs eager-loaded.
     */
    public function getByProfile(int $profileId, int $perPage = 20): LengthAwarePaginator
    {
        return EtsyImport::where('profile_id', $profileId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * A single import with its recent logs.
     */
    public function findWithLogs(int $id, int $profileId): ?EtsyImport
    {
        return EtsyImport::where('id', $id)
            ->where('profile_id', $profileId)
            ->with(['logs' => fn ($q) => $q->latest()->limit(500)])
            ->first();
    }
}
