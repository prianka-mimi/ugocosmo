<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscribePushRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Request body (x-www-form-urlencoded):
     *
     * ─── Fields ───────────────────────────────────────────────────────
     * endpoint  : The Push API endpoint URL from the browser's PushSubscription.
     *             This is a unique URL per browser/device (required, URL format).
     *
     * p256dh    : The client's public ECDH key, base64-encoded.
     *             Obtained from pushSubscription.getKey('p256dh') (required, string).
     *
     * auth      : The client's auth secret, base64-encoded.
     *             Obtained from pushSubscription.getKey('auth') (required, string).
     *
     * ─── Front-end JS example ──────────────────────────────────────────
     * registration.pushManager.subscribe({
     *     userVisibleOnly: true,
     *     applicationServerKey: vapidPublicKey
     * }).then(sub => {
     *     fetch('/api/push-subscriptions', {
     *         method: 'POST',
     *         headers: { 'Content-Type': 'application/json' },
     *         body: JSON.stringify(sub.toJSON())
     *     });
     * });
     */
    public function rules(): array
    {
        return [
            'endpoint' => 'required|string|url|max:500',
            'p256dh' => 'required|string|max:500',
            'auth' => 'required|string|max:500',
        ];
    }
}
