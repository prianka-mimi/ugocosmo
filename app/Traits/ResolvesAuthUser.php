<?php

namespace App\Traits;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Shared helpers for controllers that need the JWT-authenticated user
 * and their associated profile. Throws typed exceptions so callers can
 * return consistent ApiResponse::error() responses.
 */
trait ResolvesAuthUser
{
    protected function user(): User
    {
        $user = Auth::guard('jwt')->user();
        if (! $user) {
            throw new \Exception('Authentication required.', 401);
        }

        return $user;
    }

    protected function profile(): Profile
    {
        $profile = $this->user()->profile;
        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return $profile;
    }
}
