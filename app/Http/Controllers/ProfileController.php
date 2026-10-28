<?php

namespace App\Http\Controllers;

use App\Http\Requests\BasicInformationRequest;
use App\Http\Requests\CoverPhotoRequest;
use App\Http\Requests\CvResumeRequest;
use App\Http\Requests\HeaderInformationRequest;
use App\Http\Requests\ProfilePhotoRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\BasicInfoResource;
use App\Http\Resources\CvResumeResource;
use App\Http\Resources\HeaderInfoResource;
use App\Http\Resources\ProfileResource;
use App\Manager\ProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProfileController extends Controller
{
    protected ProfileManager $profileManager;

    public function __construct(ProfileManager $profileManager)
    {
        $this->profileManager = $profileManager;
    }

    final public function show(): JsonResponse
    {
        try {
            $this->data = ProfileResource::make($this->profileManager->show());
            $this->status_message = 'Profile retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Profile retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function basicInformation(): JsonResponse
    {
        try {
            $this->data = BasicInfoResource::make($this->profileManager->basicInformation());
            $this->status_message = 'Basic information retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Basic information retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function updateBasicInformation(BasicInformationRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BasicInfoResource::make($this->profileManager->updateBasicInformation($request->validated()));
            DB::commit();

            $this->status_message = 'Basic information updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Basic information update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function headerInformation(): JsonResponse
    {
        try {
            $this->data = HeaderInfoResource::make($this->profileManager->headerInformation());
            $this->status_message = 'Header information retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Header information retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function updateHeaderInformation(HeaderInformationRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = HeaderInfoResource::make($this->profileManager->updateHeaderInformation($request->validated()));
            DB::commit();

            $this->status_message = 'Header information updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Header information update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function cvResume(): JsonResponse
    {
        try {
            $this->data = CvResumeResource::make($this->profileManager->cvResume());
            $this->status_message = 'CV resume retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('CV resume retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function updateCvResume(CvResumeRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = CvResumeResource::make($this->profileManager->updateCvResume($request->validated()));
            DB::commit();

            $this->status_message = 'CV resume uploaded successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CV resume upload error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function coverPhoto(CoverPhotoRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ProfileResource::make($this->profileManager->updateCoverPhoto($request->validated()));
            DB::commit();

            $this->status_message = 'Cover photo updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Cover photo update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function profilePhoto(ProfilePhotoRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ProfileResource::make($this->profileManager->updateProfilePhoto($request->validated()));
            DB::commit();

            $this->status_message = 'Profile photo updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Profile photo update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Update the user's profile information.
     */
    final public function update(ProfileUpdateRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ProfileResource::make($this->profileManager->update($request->validated()));
            DB::commit();

            $this->status_message = 'Profile updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Profile update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Delete the user's account.
     */
    final public function destroy(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->profileManager->destroy();
            DB::commit();

            $this->data = [];
            $this->status_message = 'Account deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Account deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
