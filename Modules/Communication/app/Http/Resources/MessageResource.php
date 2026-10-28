<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sender = $this->relationLoaded('sender') ? $this->sender : null;

        $senderName = trim(
            (string) ($sender?->name ?? '')
        );

        if ($senderName === '') {
            $senderName = 'User '.(int) ($sender?->id ?? $this->sender_id);
        }

        $attachments = collect($this->attachments ?? [])
            ->map(fn ($item) => [
                'url' => isset($item['url']) ? asset($item['url']) : null,
                'type' => $item['type'] ?? null,
                'name' => $item['file_name'] ?? null,
                'size' => $item['file_size'] ?? null,
            ])
            ->filter(fn ($item) => ! empty($item['url']))
            ->values()
            ->all();

        if ($this->type === 'wave') {
            $attachments = [
                [
                    'url' => url('/uploads/communication/conversations/wave.gif'),
                    'type' => 'image',
                    'name' => 'wave.gif',
                    'size' => null,
                ],
            ];
        }

        return [
            'id' => (string) $this->id,
            'sender' => [
                'id' => (int) ($sender?->id ?? $this->sender_id),
                'name' => $senderName,
                'avatar' => $sender?->avatar ?? 'https://ui-avatars.com/api/?name='.urlencode($senderName).'&background=random&size=256',
            ],
            'type' => (string) $this->type,
            'content' => $this->content,
            'attachments' => $attachments,
            'metadata' => $this->metadata ?? [],
            'status' => (string) $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
