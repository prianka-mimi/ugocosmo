<?php

namespace App\Manager;

use App\Models\WorkHistory;
use Illuminate\Support\Facades\Auth;

class WorkHistoryManager
{
    public function index(): array
    {
        $histories = WorkHistory::forUser($this->userId())->ordered()->get();

        return $histories->map(fn (WorkHistory $history) => $this->format($history))->all();
    }

    public function store(array $data): array
    {
        $data['user_id'] = $this->userId();

        $history = WorkHistory::create($this->normalize($data));

        return $this->format($history);
    }

    public function update(WorkHistory $history, array $data): array
    {
        $this->ensureOwned($history);

        $history->update($this->normalize($data));

        return $this->format($history->fresh());
    }

    public function destroy(WorkHistory $history): void
    {
        $this->ensureOwned($history);

        $history->delete();
    }

    protected function normalize(array $data): array
    {
        if (array_key_exists('is_currently_working', $data) && $data['is_currently_working']) {
            $data['end_date'] = null;
        }

        return $data;
    }

    protected function format(WorkHistory $history): array
    {
        return [
            'id' => $history->id,
            'company_name' => $history->company_name,
            'position_title' => $history->position_title,
            'start_date' => $history->start_date?->toDateString(),
            'end_date' => $history->end_date?->toDateString(),
            'job_description' => $history->job_description,
            'is_currently_working' => (bool) $history->is_currently_working,
        ];
    }

    protected function ensureOwned(WorkHistory $history): void
    {
        if ((int) $history->user_id !== $this->userId()) {
            abort(404);
        }
    }

    protected function userId(): int
    {
        return (int) Auth::guard('jwt')->id();
    }
}
