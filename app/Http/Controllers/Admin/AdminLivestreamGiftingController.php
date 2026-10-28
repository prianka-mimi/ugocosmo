<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Communication\Models\Gift;
use Modules\Communication\Models\LivestreamAmountPreset;
use Modules\Communication\Models\LivestreamGamificationPreset;
use Modules\Communication\Traits\FileManagementTrait;
use Throwable;

class AdminLivestreamGiftingController extends Controller
{
    use FileManagementTrait;

    /**
     * Display a listing of gifts or presets.
     */
    public function index(Request $request)
    {
        $tab = $request->input('tab', 'gifts');

        if ($tab === 'gamification') {
            $gamificationPresets = LivestreamGamificationPreset::orderBy('amount', 'asc')->get();

            return view('modules.admin.livestream_presets.index', compact('gamificationPresets', 'tab'));
        }

        if ($tab === 'presets') {
            $presets = LivestreamAmountPreset::orderBy('amount', 'asc')->get();

            return view('modules.admin.livestream_presets.index', compact('presets', 'tab'));
        }

        $gifts = Gift::orderBy('coin_cost', 'asc')->get();

        return view('modules.admin.livestream_presets.index', compact('gifts', 'tab'));
    }

    /**
     * Show creation form.
     */
    public function create(Request $request)
    {
        $tab = $request->input('tab', 'gifts');

        return view('modules.admin.livestream_presets.form', [
            'tab' => $tab,
            'gift' => null,
            'preset' => null,
            'gamificationPreset' => null,
        ]);
    }

    /**
     * Store new record in database.
     */
    public function store(Request $request)
    {
        $tab = $request->input('tab', 'gifts');

        try {
            DB::beginTransaction();

            if ($tab === 'gamification') {
                $request->validate([
                    'amount' => ['required', 'numeric', 'min:0.01'],
                ]);

                LivestreamGamificationPreset::create([
                    'amount' => $request->input('amount'),
                    'is_active' => $request->has('is_active'),
                ]);

                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gamification'])
                    ->with('success', 'Gamification price preset created successfully.');
            }

            if ($tab === 'presets') {
                $request->validate([
                    'amount' => ['required', 'numeric', 'min:0.01'],
                ]);

                LivestreamAmountPreset::create([
                    'amount' => $request->input('amount'),
                    'is_active' => $request->has('is_active'),
                ]);

                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'presets'])
                    ->with('success', 'Amount preset created successfully.');
            }

            $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'coin_cost' => ['required', 'integer', 'min:0'],
                'icon_file' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            ]);

            $iconUrl = '';
            if ($request->hasFile('icon_file')) {
                $uploadedUrl = $this->uploadFile($request->file('icon_file'), 'communication/gifts', 'public');
                $appUrl = (string) config('app.url');
                if (Str::contains($uploadedUrl, $appUrl)) {
                    $iconUrl = parse_url($uploadedUrl, PHP_URL_PATH);
                } else {
                    $iconUrl = $uploadedUrl;
                }
            }

            Gift::create([
                'title' => $request->input('title'),
                'icon_url' => $iconUrl,
                'coin_cost' => (int) $request->input('coin_cost'),
                'is_active' => $request->has('is_active'),
            ]);

            DB::commit();

            return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gifts'])
                ->with('success', 'Gift item created successfully.');

        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream gifting item creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show edit form.
     */
    public function edit(Request $request, $id)
    {
        $tab = $request->input('tab', 'gifts');

        if ($tab === 'gamification') {
            $gamificationPreset = LivestreamGamificationPreset::findOrFail($id);

            return view('modules.admin.livestream_presets.form', [
                'tab' => $tab,
                'gamificationPreset' => $gamificationPreset,
                'preset' => null,
                'gift' => null,
            ]);
        }

        if ($tab === 'presets') {
            $preset = LivestreamAmountPreset::findOrFail($id);

            return view('modules.admin.livestream_presets.form', [
                'tab' => $tab,
                'preset' => $preset,
                'gift' => null,
                'gamificationPreset' => null,
            ]);
        }

        $gift = Gift::findOrFail($id);

        return view('modules.admin.livestream_presets.form', [
            'tab' => $tab,
            'gift' => $gift,
            'preset' => null,
            'gamificationPreset' => null,
        ]);
    }

    /**
     * Update existing record.
     */
    public function update(Request $request, $id)
    {
        $tab = $request->input('tab', 'gifts');

        try {
            DB::beginTransaction();

            if ($tab === 'gamification') {
                $gamificationPreset = LivestreamGamificationPreset::findOrFail($id);
                $request->validate([
                    'amount' => ['required', 'numeric', 'min:0.01'],
                ]);

                $gamificationPreset->update([
                    'amount' => $request->input('amount'),
                    'is_active' => $request->has('is_active'),
                ]);

                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gamification'])
                    ->with('success', 'Gamification price preset updated successfully.');
            }

            if ($tab === 'presets') {
                $preset = LivestreamAmountPreset::findOrFail($id);
                $request->validate([
                    'amount' => ['required', 'numeric', 'min:0.01'],
                ]);

                $preset->update([
                    'amount' => $request->input('amount'),
                    'is_active' => $request->has('is_active'),
                ]);

                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'presets'])
                    ->with('success', 'Amount preset updated successfully.');
            }

            $gift = Gift::findOrFail($id);
            $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'coin_cost' => ['required', 'integer', 'min:0'],
                'icon_file' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            ]);

            $iconUrl = $gift->getRawOriginal('icon_url');
            if ($request->hasFile('icon_file')) {
                // Delete old icon
                if (! empty($iconUrl)) {
                    $this->deleteFile($iconUrl, 'public');
                }
                $uploadedUrl = $this->uploadFile($request->file('icon_file'), 'communication/gifts', 'public');
                $appUrl = (string) config('app.url');
                if (Str::contains($uploadedUrl, $appUrl)) {
                    $iconUrl = parse_url($uploadedUrl, PHP_URL_PATH);
                } else {
                    $iconUrl = $uploadedUrl;
                }
            }

            $gift->update([
                'title' => $request->input('title'),
                'icon_url' => $iconUrl,
                'coin_cost' => (int) $request->input('coin_cost'),
                'is_active' => $request->has('is_active'),
            ]);

            DB::commit();

            return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gifts'])
                ->with('success', 'Gift item updated successfully.');

        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream gifting item update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Delete existing record.
     */
    public function destroy(Request $request, $id)
    {
        $tab = $request->input('tab', 'gifts');

        try {
            DB::beginTransaction();

            if ($tab === 'gamification') {
                $gamificationPreset = LivestreamGamificationPreset::findOrFail($id);
                $gamificationPreset->delete();
                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gamification'])
                    ->with('success', 'Gamification price preset deleted successfully.');
            }

            if ($tab === 'presets') {
                $preset = LivestreamAmountPreset::findOrFail($id);
                $preset->delete();
                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'presets'])
                    ->with('success', 'Amount preset deleted successfully.');
            }

            $gift = Gift::findOrFail($id);
            $iconUrl = $gift->getRawOriginal('icon_url');
            if (! empty($iconUrl)) {
                $this->deleteFile($iconUrl, 'public');
            }
            $gift->delete();

            DB::commit();

            return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gifts'])
                ->with('success', 'Gift item deleted successfully.');

        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream gifting item deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Toggle status.
     */
    public function toggleStatus(Request $request, $id)
    {
        $tab = $request->input('tab', 'gifts');

        try {
            DB::beginTransaction();

            if ($tab === 'gamification') {
                $gamificationPreset = LivestreamGamificationPreset::findOrFail($id);
                $gamificationPreset->is_active = ! $gamificationPreset->is_active;
                $gamificationPreset->save();
                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gamification'])
                    ->with('success', 'Gamification price preset status updated successfully.');
            }

            if ($tab === 'presets') {
                $preset = LivestreamAmountPreset::findOrFail($id);
                $preset->is_active = ! $preset->is_active;
                $preset->save();
                DB::commit();

                return redirect()->route('admin.livestream-gifting.index', ['tab' => 'presets'])
                    ->with('success', 'Amount preset status updated successfully.');
            }

            $gift = Gift::findOrFail($id);
            $gift->is_active = ! $gift->is_active;
            $gift->save();
            DB::commit();

            return redirect()->route('admin.livestream-gifting.index', ['tab' => 'gifts'])
                ->with('success', 'Gift item status updated successfully.');

        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream status toggle failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
