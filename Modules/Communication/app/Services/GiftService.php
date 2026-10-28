<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Models\Gift;

class GiftService
{
    public function getActiveGifts(): Collection
    {
        return Gift::query()
            ->active()
            ->orderBy('coin_cost', 'asc')
            ->get();

    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function attachGiftMetadata(int $giftId, array $metadata = []): array
    {
        $gift = Gift::query()->active()->find($giftId);

        if ($gift === null) {
            throw ValidationException::withMessages([
                'gift_id' => ['The selected gift is invalid or inactive.'],
            ]);
        }

        $metadata['gift'] = [
            'gift_id' => (int) $gift->id,
            'name' => (string) $gift->title,
            'icon_url' => (string) $gift->icon_url,
            'coins' => (int) $gift->coin_cost,
        ];

        return $metadata;
    }
}
