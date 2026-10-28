<?php

namespace App\Policies;

use App\Models\AliExpressAccount;
use App\Models\User;

class AliExpressAccountPolicy
{
    /**
     * Determine if the user can view the account.
     */
    public function view(User $user, AliExpressAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can browse/import products from the account.
     */
    public function import(User $user, AliExpressAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id
            && $account->status === AliExpressAccount::STATUS_ACTIVE;
    }

    /**
     * Determine if the user can disconnect/disable the account.
     */
    public function disconnect(User $user, AliExpressAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can refresh the account's token.
     */
    public function refreshToken(User $user, AliExpressAccount $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }
}
