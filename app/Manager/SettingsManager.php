<?php

namespace App\Manager;

use App\Models\NotificationPreference as NotificationPreferenceModel;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Auth;

class SettingsManager
{
    protected PrivacySettingManager $privacySettingManager;

    protected ConnectionManager $connectionManager;

    public function __construct(
        PrivacySettingManager $privacySettingManager,
        ConnectionManager $connectionManager,
    ) {
        $this->privacySettingManager = $privacySettingManager;
        $this->connectionManager = $connectionManager;
    }

    /**
     * Get all settings for the current user.
     */
    public function all(): array
    {
        $user = Auth::guard('jwt')->user();

        return [
            'privacy' => $this->privacySettingManager->show(),
            'blocked_users' => $this->connectionManager->blockedUsers(),
            'subscription' => $this->subscriptionInfo(),
            'notification_preferences' => $this->notificationPreferences(),
            'account' => $this->accountInfo($user),
        ];
    }

    /**
     * Get the user's current subscription information.
     */
    protected function subscriptionInfo(): array
    {
        $activeSubscription = UserSubscription::forUser($this->userId())
            ->active()
            ->with('subscriptionPlan')
            ->latest('id')
            ->first();

        if (! $activeSubscription) {
            return [
                'has_subscription' => false,
                'subscription' => null,
            ];
        }

        return [
            'has_subscription' => true,
            'subscription' => [
                'id' => $activeSubscription->id,
                'plan_name' => $activeSubscription->subscriptionPlan?->name,
                'plan_slug' => $activeSubscription->subscriptionPlan?->slug,
                'started_at' => $activeSubscription->started_at?->toIso8601String(),
                'expires_at' => $activeSubscription->expires_at?->toIso8601String(),
                'is_active' => (bool) $activeSubscription->is_active,
            ],
        ];
    }

    /**
     * Get notification preferences summary.
     */
    protected function notificationPreferences(): array
    {
        $prefs = NotificationPreferenceModel::forUser($this->profileId());

        return $prefs->preferences ?? NotificationPreferenceModel::DEFAULTS;
    }

    /**
     * Get account information for settings.
     */
    protected function accountInfo($user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'avatar' => ImageManager::fileUrl($user->avatar),
            'onboarding_completed' => (bool) $user->onboarding_completed,
            'is_verified' => (bool) $user->is_verified,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }

    protected function userId(): int
    {
        return (int) Auth::guard('jwt')->id();
    }
}
