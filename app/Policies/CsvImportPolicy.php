<?php

namespace App\Policies;

use App\Models\CsvImport;
use App\Models\User;

class CsvImportPolicy
{
    /**
     * Only users with a completed profile (seller context) may import.
     */
    public function create(User $user): bool
    {
        return $user->profile !== null;
    }

    /**
     * An import is only visible to the user who started it.
     */
    public function view(User $user, CsvImport $import): bool
    {
        return (int) $import->user_id === (int) $user->id;
    }

    /**
     * Only the owner may cancel an import.
     */
    public function cancel(User $user, CsvImport $import): bool
    {
        return (int) $import->user_id === (int) $user->id;
    }
}
