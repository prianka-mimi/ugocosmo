<?php

namespace App\Manager;

use App\Models\Qualification;
use Illuminate\Support\Facades\Auth;

class QualificationManager
{
    public function index(): array
    {
        $qualifications = Qualification::forUser($this->userId())->ordered()->get();

        return $qualifications->map(fn (Qualification $qualification) => $this->format($qualification))->all();
    }

    public function store(array $data): array
    {
        $data['user_id'] = $this->userId();

        $qualification = Qualification::create($data);

        return $this->format($qualification);
    }

    public function update(Qualification $qualification, array $data): array
    {
        $this->ensureOwned($qualification);

        $qualification->update($data);

        return $this->format($qualification->fresh());
    }

    public function destroy(Qualification $qualification): void
    {
        $this->ensureOwned($qualification);

        $qualification->delete();
    }

    protected function format(Qualification $qualification): array
    {
        return [
            'id' => $qualification->id,
            'institute_name' => $qualification->institute_name,
            'degree' => $qualification->degree,
            'field_of_study' => $qualification->field_of_study,
            'start_date' => $qualification->start_date?->toDateString(),
            'end_date' => $qualification->end_date?->toDateString(),
        ];
    }

    protected function ensureOwned(Qualification $qualification): void
    {
        if ((int) $qualification->user_id !== $this->userId()) {
            abort(404);
        }
    }

    protected function userId(): int
    {
        return (int) Auth::guard('jwt')->id();
    }
}
