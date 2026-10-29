<?php

namespace App\Manager;

use App\Models\SavedAddress;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileManager
{
    public function show(): User
    {
        return Auth::guard('jwt')->user()->load(['socialAccounts']);
    }

    public function basicInformation(): User
    {
        return Auth::guard('jwt')->user();
    }

    public function updateBasicInformation(array $data): User
    {
        $user = Auth::guard('jwt')->user();

        if (! empty($data['remove_image']) && filter_var($data['remove_image'], FILTER_VALIDATE_BOOLEAN)) {
            app(ImageManager::class)->delete($user->avatar);
            $user->avatar = null;
        } elseif (isset($data['profile_image'])) {
            app(ImageManager::class)->delete($user->avatar);
            $user->avatar = app(ImageManager::class)->upload($data['profile_image'], 'avatars', 400, 400);
        }

        foreach (['occupation', 'dob', 'marital_status'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->$field = $data[$field];
            }
        }

        $user->save();

        return $user;
    }

    public function headerInformation(): User
    {
        return Auth::guard('jwt')->user();
    }

    public function updateHeaderInformation(array $data): User
    {
        $user = Auth::guard('jwt')->user();

        foreach (['bio', 'skills', 'interests'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->$field = $data[$field];
            }
        }

        $user->save();

        return $user;
    }

    public function cvResume(): User
    {
        return Auth::guard('jwt')->user();
    }

    public function updateCvResume(array $data): User
    {
        $user = Auth::guard('jwt')->user();

        if (array_key_exists('cv', $data)) {
            $this->updateCvResumeFile($user, $data['cv']);
        }

        $user->save();

        return $user;
    }

    public function update(array $data): User
    {
        $user = Auth::guard('jwt')->user();

        $fillable = [
            'account_type',
            'name',
            'username',
            'email',
            'mobile_number',
            'address_line_1',
            'address_line_2',
            'city',
            'postal_code',
            'country',
            'occupation',
            'bio',
        ];

        // Map alternative names back to our user fields
        if (array_key_exists('address_line1', $data) && ! array_key_exists('address_line_1', $data)) {
            $data['address_line_1'] = $data['address_line1'];
        }
        if (array_key_exists('city_locality', $data) && ! array_key_exists('city', $data)) {
            $data['city'] = $data['city_locality'];
        }
        if (array_key_exists('country_code', $data) && ! array_key_exists('country', $data)) {
            $data['country'] = $data['country_code'];
        }
        if (array_key_exists('phone', $data) && ! array_key_exists('mobile_number', $data)) {
            $data['mobile_number'] = $data['phone'];
        }

        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->is_verified = false;
            $user->verified_at = null;
        }

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $user->$field = $data[$field];
            }
        }

        $user->save();

        // Keep linked Profile in sync with User core identity fields
        if ($user->profile) {
            $profileUpdates = [];
            if (array_key_exists('name', $data)) {
                $profileUpdates['full_name'] = $data['name'];
            }
            if (array_key_exists('email', $data)) {
                $profileUpdates['email'] = $data['email'];
            }
            if (array_key_exists('username', $data)) {
                $profileUpdates['username'] = $data['username'];
            }
            if (! empty($profileUpdates)) {
                $user->profile->update($profileUpdates);
            }
        }

        // Sync with default SavedAddress
        $addressFields = ['name', 'mobile_number', 'phone', 'address_line_1', 'address_line1', 'address_line_2', 'city', 'city_locality', 'postal_code', 'country', 'country_code', 'state_province', 'label'];
        $hasAddressUpdate = array_intersect_key($data, array_flip($addressFields));

        if (! empty($hasAddressUpdate) && $user->profile) {
            $defaultAddress = SavedAddress::where('profile_id', $user->profile->id)
                ->where('is_default', true)
                ->first();

            // If no strict default, fallback to the latest address
            if (! $defaultAddress) {
                $defaultAddress = SavedAddress::where('profile_id', $user->profile->id)->latest()->first();
            }

            $addressData = [
                'name' => $data['name'] ?? $user->name ?: 'Customer',
                'address_line1' => $data['address_line1'] ?? $data['address_line_1'] ?? $user->address_line_1 ?? '',
                'address_line2' => $data['address_line2'] ?? $data['address_line_2'] ?? $user->address_line_2,
                'city_locality' => $data['city_locality'] ?? $data['city'] ?? $user->city ?? '',
                'state_province' => $data['state_province'] ?? $defaultAddress?->state_province ?? '',
                'postal_code' => $data['postal_code'] ?? $user->postal_code ?? '',
                'country_code' => $data['country_code'] ?? $data['country'] ?? $user->country ?? 'US',
                'phone' => $data['phone'] ?? $data['mobile_number'] ?? $user->mobile_number,
                'label' => $data['label'] ?? $defaultAddress?->label ?? 'Home',
                'is_default' => true,
            ];

            // Ensure all other addresses are not default
            SavedAddress::where('profile_id', $user->profile->id)->update(['is_default' => false]);

            if ($defaultAddress) {
                $defaultAddress->update($addressData);
            } else {
                if (! empty($addressData['address_line1']) || ! empty($addressData['city_locality']) || ! empty($addressData['postal_code'])) {
                    SavedAddress::create(array_merge($addressData, [
                        'profile_id' => $user->profile->id,
                    ]));
                }
            }
        }

        return $user;
    }

    public function updateCoverPhoto(array $data): User
    {
        $user = Auth::guard('jwt')->user();

        if (! empty($data['remove_image']) && filter_var($data['remove_image'], FILTER_VALIDATE_BOOLEAN)) {
            app(ImageManager::class)->delete($user->cover_photo);
            $user->cover_photo = null;
        } elseif (isset($data['cover_photo'])) {
            app(ImageManager::class)->delete($user->cover_photo);
            $user->cover_photo = app(ImageManager::class)->upload($data['cover_photo'], 'covers', 1200, 400);
        }

        $user->save();

        return $user;
    }

    public function updateProfilePhoto(array $data): User
    {
        $user = Auth::guard('jwt')->user();

        if (! empty($data['remove_image']) && filter_var($data['remove_image'], FILTER_VALIDATE_BOOLEAN)) {
            app(ImageManager::class)->delete($user->avatar);
            $user->avatar = null;
        } elseif (isset($data['profile_photo'])) {
            app(ImageManager::class)->delete($user->avatar);
            $user->avatar = app(ImageManager::class)->upload($data['profile_photo'], 'avatars', 400, 400);
        }

        $user->save();

        return $user;
    }

    protected function updateCvResumeFile(User $user, ?UploadedFile $cv): void
    {
        if (! $cv) {
            return;
        }

        if ($user->cv_resume && Storage::disk('public')->exists($user->cv_resume)) {
            Storage::disk('public')->delete($user->cv_resume);
        }

        $user->cv_resume = $cv->store('resumes', 'public');
    }

    public function destroy(): User
    {
        $user = Auth::guard('jwt')->user();

        Auth::guard('jwt')->logout();

        $user->delete();

        return $user;
    }
}
