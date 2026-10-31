<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Validation\Validator;

class UpdateGroupSettingsRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        $receiveAudioCall = $this->input(
            'receive_audio_call',
            $this->input('recive_audio_call', $this->input('receive_voice_call'))
        );

        if ($receiveAudioCall !== null) {
            $this->merge(['receive_audio_call' => $receiveAudioCall]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', 'image'], // Max 2MB
            'notification' => ['nullable', 'boolean'],
            'receive_audio_call' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasAny(['name', 'avatar', 'notification', 'receive_audio_call'])) {
                return;
            }

            $validator->errors()->add('settings', 'Provide at least one setting: name, avatar, notification, or receive_audio_call.');
        });
    }
}
