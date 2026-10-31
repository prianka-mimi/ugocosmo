<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartConversationRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'integer', 'exists:communication_conversations,id'],
            'type' => ['nullable', 'string', Rule::in(['private', 'group']), 'required_without:conversation_id'],
            'participant_ids' => ['nullable', 'array', 'min:1', 'required_without:conversation_id'],
            'participant_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            // 'name' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
            'message_type' => ['nullable', 'string', Rule::in(['wave', 'text', 'image', 'file'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $conversationId = (int) $this->input('conversation_id', 0);

            if ($conversationId > 0) {
                return;
            }

            $type = (string) $this->input('type', 'private');
            $authUserId = (int) ($this->user()?->id ?? 0);

            $participantIds = collect($this->input('participant_ids', []))
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($participantIds->contains($authUserId)) {
                $validator->errors()->add('participant_ids', 'Do not include your own user id in participant_ids.');
            }

            if ($type === 'private' && $participantIds->count() !== 1) {
                $validator->errors()->add('participant_ids', 'Private chat requires exactly one participant id.');
            }

            // if ($type === 'group' && $participantIds->count() < 2) {
            //   $validator->errors()->add('participant_ids', 'Group chat requires at least two participant ids.');
            // }
        });
    }
}
