<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    public function index()
    {
        $plans = SubscriptionPlan::active()->ordered()->get();

        return view('modules.dashboard.index', compact('plans'));
    }

    public function profile()
    {
        $user = Auth::user();

        return view('modules.profile.form', compact('user'));
    }

    public function updateProfile(ProfileUpdateRequest $request)
    {
        try {
            DB::beginTransaction();
            $user = $request->user();

            $data = $request->validated();

            if ($request->hasFile('avatar')) {
                $data['avatar'] = $request->file('avatar')->store('avatars', 'public');
            }

            $user->update($data);
            DB::commit();

            return back()->with('success', 'Profile updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Profile update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
