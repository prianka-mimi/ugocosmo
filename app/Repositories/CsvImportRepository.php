<?php

namespace App\Repositories;

use App\Models\CsvImport;
use App\Models\CsvImportFile;
use Illuminate\Pagination\LengthAwarePaginator;

class CsvImportRepository
{
    public function create(array $data): CsvImport
    {
        return CsvImport::create($data);
    }

    public function createFile(array $data): CsvImportFile
    {
        return CsvImportFile::create($data);
    }

    public function findById(int $id): ?CsvImport
    {
        return CsvImport::find($id);
    }

    /**
     * Find an import with user-ownership validation.
     */
    public function findForUser(int $id, int $userId): ?CsvImport
    {
        return CsvImport::where('id', $id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Import history for a user with per-file records eager-loaded.
     */
    public function getByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return CsvImport::where('user_id', $userId)
            ->with('files')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * A single import with its per-file records and recent logs.
     */
    public function findWithLogs(int $id, int $userId): ?CsvImport
    {
        return CsvImport::where('id', $id)
            ->where('user_id', $userId)
            ->with(['files', 'logs' => fn ($q) => $q->latest()->limit(500)])
            ->first();
    }

    /**
     * Does this user already have a running import?
     */
    public function hasActive(int $userId): bool
    {
        return CsvImport::where('user_id', $userId)
            ->whereIn('status', ['pending', 'queued', 'processing'])
            ->exists();
    }
}
