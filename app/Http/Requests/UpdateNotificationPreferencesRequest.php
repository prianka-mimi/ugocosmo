<?php

namespace App\Http\Requests;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
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
     * ─── Structure ──────────────────────────────────────────────────────
     * Each key corresponds to a preference category, and the value is a
     * JSON-encoded object with boolean 'push', 'in_app', and 'email' fields.
     *
     * ─── Example (sent as form fields) ──────────────────────────────────
     *   booking[push]   = 0
     *   booking[in_app] = 1
     *   booking[email]  = 1
     *   message[push]   = 0
     *
     * ─── Categories ─────────────────────────────────────────────────────
     *   booking, ticket, message, livestream
     *
     * ─── Rules per setting ──────────────────────────────────────────────
     *   push   : boolean (optional) — enable/disable push (FCM + Web Push)
     *   in_app : boolean (optional) — enable/disable in-app (DB) notifications
     *   email  : boolean (optional) — enable/disable transactional emails
     *
     * All fields use `sometimes` — only include the fields you want to change.
     */
    public function rules(): array
    {
        $categories = NotificationPreference::CATEGORIES;

        $rules = [];

        foreach ($categories as $category) {
            $rules["{$category}.push"] = ['sometimes', 'required', 'boolean'];
            $rules["{$category}.in_app"] = ['sometimes', 'required', 'boolean'];
            $rules["{$category}.email"] = ['sometimes', 'required', 'boolean'];
        }

        return $rules;
    }
}
