<?php

namespace App\Manager;

use App\Models\Livestream;
use App\Models\LivestreamItem;
use Illuminate\Support\Facades\Auth;

class LivestreamManager
{
    public function live(array $params = [], int $perPage = 20)
    {
        return Livestream::live()
            ->with('host')
            ->latest('started_at')
            ->paginate($perPage);
    }

    public function mine(array $params = [], int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        return Livestream::where('host_id', $profileId)
            ->with('host')
            ->latest()
            ->paginate($perPage);
    }

    public function start(array $data): Livestream
    {
        $profileId = (int) $this->profileId();

        // End any existing active livestream
        Livestream::where('host_id', $profileId)->where('is_live', true)->update(['is_live' => false, 'ended_at' => now()]);

        $stream = Livestream::create([
            'host_id' => $profileId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_live' => true,
            'viewer_count' => 0,
            'started_at' => now(),
        ]);

        if (isset($data['thumbnail'])) {
            $stream->thumbnail_url = $data['thumbnail']->store('livestream-thumbnails', 'public');
            $stream->save();
        }

        return $stream->load('host');
    }

    public function stop(Livestream $livestream): Livestream
    {
        $this->ensureOwned($livestream);

        $livestream->update([
            'is_live' => false,
            'ended_at' => now(),
        ]);

        return $livestream->fresh()->load('host');
    }

    public function show(Livestream $livestream): Livestream
    {
        return $livestream->load(['host', 'items.product', 'items.service', 'items.job', 'items.event']);
    }

    public function addItem(Livestream $livestream, array $data): LivestreamItem
    {
        $this->ensureOwned($livestream);

        $item = LivestreamItem::create([
            'livestream_id' => $livestream->id,
            'product_id' => $data['product_id'] ?? null,
            'service_id' => $data['service_id'] ?? null,
            'job_id' => $data['job_id'] ?? null,
            'event_id' => $data['event_id'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
        ]);

        return $item;
    }

    public function removeItem(Livestream $livestream, LivestreamItem $item): void
    {
        $this->ensureOwned($livestream);

        if ((int) $item->livestream_id !== (int) $livestream->id) {
            abort(404);
        }

        $item->delete();
    }

    protected function ensureOwned(Livestream $livestream): void
    {
        if ((int) $livestream->host_id !== (int) $this->profileId()) {
            abort(404);
        }
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
}
