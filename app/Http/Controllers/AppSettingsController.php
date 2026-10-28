<?php

namespace App\Http\Controllers;

use App\Manager\ImageManager;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AppSettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'tab_name' => AppSetting::get('tab_name', config('app.name', 'UgoCosmo')),
            'primary_color' => AppSetting::get('primary_color', '#E87450'),
            'logo' => AppSetting::get('logo', null),
            'favicon' => AppSetting::get('favicon', null),
        ];

        return view('modules.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'tab_name' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:7|regex:/^#[0-9a-fA-F]{6}$/',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120',
            'favicon' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,ico|max:1024',
        ]);

        if ($request->filled('tab_name')) {
            AppSetting::set('tab_name', $request->tab_name);
        }

        if ($request->filled('primary_color')) {
            AppSetting::set('primary_color', $request->primary_color);
        }

        if ($request->hasFile('logo')) {
            $oldLogo = AppSetting::get('logo');
            if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
                Storage::disk('public')->delete($oldLogo);
            }
            $path = $request->file('logo')->store('settings/logo', 'public');
            AppSetting::set('logo', $path);
        }

        if ($request->hasFile('favicon')) {
            $oldFavicon = AppSetting::get('favicon');
            if ($oldFavicon && Storage::disk('public')->exists($oldFavicon)) {
                Storage::disk('public')->delete($oldFavicon);
            }
            $path = $request->file('favicon')->store('settings/favicon', 'public');
            AppSetting::set('favicon', $path);
            AppSetting::set('favicon_version', AppSetting::get('favicon_version', 1) + 1);
        }

        return redirect()->route('settings.index')->with('success', 'Settings updated successfully. Refresh to see changes.');
    }

    public function bankIndex()
    {
        $settings = [
            'bank_account_name' => AppSetting::get('bank_account_name', 'Castilo Limited'),
            'bank_account_number' => AppSetting::get('bank_account_number', '12345678'),
            'bank_sort_code' => AppSetting::get('bank_sort_code', '00-00-00'),
        ];

        return view('modules.settings.bank', compact('settings'));
    }

    public function bankUpdate(Request $request)
    {
        $request->validate([
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_sort_code' => 'nullable|string|max:20',
        ]);

        if ($request->has('bank_account_name')) {
            AppSetting::set('bank_account_name', $request->bank_account_name ?? '');
        }

        if ($request->has('bank_account_number')) {
            AppSetting::set('bank_account_number', $request->bank_account_number ?? '');
        }

        if ($request->has('bank_sort_code')) {
            AppSetting::set('bank_sort_code', $request->bank_sort_code ?? '');
        }

        return redirect()->route('settings.bank.index')->with('success', 'Bank settings updated successfully.');
    }

    public function apiIndex(): JsonResponse
    {
        try {
            $this->data = [
                'tab_name' => AppSetting::get('tab_name', config('app.name', 'UgoCosmo')),
                'primary_color' => AppSetting::get('primary_color', '#E87450'),
                'logo' => ImageManager::fileUrl(AppSetting::get('logo')),
                'favicon' => ImageManager::fileUrl(AppSetting::get('favicon')),
                'bank_account_name' => AppSetting::get('bank_account_name', 'Castilo Limited'),
                'bank_account_number' => AppSetting::get('bank_account_number', '12345678'),
                'bank_sort_code' => AppSetting::get('bank_sort_code', '00-00-00'),
            ];
            $this->status_message = 'App settings retrieved successfully';
            $this->status_code = 200;
        } catch (\Throwable $e) {
            Log::error('App settings api index error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
