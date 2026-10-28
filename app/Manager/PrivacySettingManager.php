<?php

namespace App\Manager;

use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class PrivacySettingManager
{
    /**
     * Get the current user's privacy settings.
     */
    public function show(): array
    {
        $settings = PrivacySetting::forUser($this->userId());

        return [
            'age_visibility' => $settings->age_visibility ?? 'private',
            'employment_history_visibility' => $settings->employment_history_visibility ?? 'verified',
            'qualifications_visibility' => $settings->qualifications_visibility ?? 'verified',
            'profile_visibility' => $settings->profile_visibility ?? 'public',
            'show_online_status' => (bool) $settings->show_online_status,
            'message_permission' => $settings->message_permission ?? 'everyone',
        ];
    }

    /**
     * Update the current user's privacy settings.
     */
    public function update(array $data): array
    {
        $settings = PrivacySetting::forUser($this->userId());

        $fillable = [
            'age_visibility',
            'employment_history_visibility',
            'qualifications_visibility',
            'profile_visibility',
            'show_online_status',
            'message_permission',
        ];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $settings->$field = $data[$field];
            }
        }

        $settings->save();

        return $this->show();
    }

    /**
     * Check if a user's age is visible to the requesting user.
     */
    public function canViewAge(User $targetUser, ?User $requestingUser = null): bool
    {
        $settings = PrivacySetting::forUser((int) $targetUser->id);

        if ($settings->age_visibility === 'public') {
            return true;
        }

        if ($settings->age_visibility === 'verified' && $requestingUser) {
            return $this->isUserVerified($requestingUser);
        }

        // 'private' — only the user and admins
        if ($requestingUser && ((int) $requestingUser->id === (int) $targetUser->id || $requestingUser->role === 'admin')) {
            return true;
        }

        return false;
    }

    /**
     * Check if a user's employment history is visible to the requesting user.
     */
    public function canViewEmploymentHistory(User $targetUser, ?User $requestingUser = null): bool
    {
        $settings = PrivacySetting::forUser((int) $targetUser->id);

        if ($settings->employment_history_visibility === 'public') {
            return true;
        }

        if ($settings->employment_history_visibility === 'verified' && $requestingUser) {
            return $this->isUserVerified($requestingUser);
        }

        if ($requestingUser && ((int) $requestingUser->id === (int) $targetUser->id || $requestingUser->role === 'admin')) {
            return true;
        }

        return false;
    }

    /**
     * Check if a user's qualifications are visible to the requesting user.
     */
    public function canViewQualifications(User $targetUser, ?User $requestingUser = null): bool
    {
        $settings = PrivacySetting::forUser((int) $targetUser->id);

        if ($settings->qualifications_visibility === 'public') {
            return true;
        }

        if ($settings->qualifications_visibility === 'verified' && $requestingUser) {
            return $this->isUserVerified($requestingUser);
        }

        if ($requestingUser && ((int) $requestingUser->id === (int) $targetUser->id || $requestingUser->role === 'admin')) {
            return true;
        }

        return false;
    }

    /**
     * Check if the user is a verified user (has an active subscription with verification).
     */
    protected function isUserVerified(User $user): bool
    {
        return (bool) $user->is_verified;
    }

    protected function userId(): int
    {
        return (int) Auth::guard('jwt')->id();
    }
}
