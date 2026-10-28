<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrivacySettingUpdateRequest;
use App\Manager\PrivacySettingManager;
use App\Manager\SettingsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class SettingsController extends Controller
{
    protected SettingsManager $settingsManager;

    protected PrivacySettingManager $privacySettingManager;

    public function __construct(SettingsManager $settingsManager, PrivacySettingManager $privacySettingManager)
    {
        $this->settingsManager = $settingsManager;
        $this->privacySettingManager = $privacySettingManager;
    }

    /**
     * Get all settings (privacy, blocked users, subscription, notification prefs, account).
     */
    public function index(): JsonResponse
    {
        try {
            $this->data = $this->settingsManager->all();
            $this->status_message = 'Settings retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Settings retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get privacy settings.
     */
    public function privacy(): JsonResponse
    {
        try {
            $this->data = $this->privacySettingManager->show();
            $this->status_message = 'Privacy settings retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Privacy settings retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Update privacy settings.
     */
    public function updatePrivacy(PrivacySettingUpdateRequest $request): JsonResponse
    {
        try {
            $this->data = $this->privacySettingManager->update($request->validated());
            $this->status_message = 'Privacy settings updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Privacy settings update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
