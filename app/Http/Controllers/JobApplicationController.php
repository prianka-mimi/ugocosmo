<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobApplicationStoreRequest;
use App\Http\Resources\JobApplicationResource;
use App\Manager\JobApplicationManager;
use App\Models\JobApplication;
use App\Models\JobListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JobApplicationController extends Controller
{
    protected JobApplicationManager $applicationManager;

    public function __construct(JobApplicationManager $applicationManager)
    {
        $this->applicationManager = $applicationManager;
    }

    /**
     * Submit a new job application.
     */
    public function store(JobApplicationStoreRequest $request, JobListing $job): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = JobApplicationResource::make(
                $this->applicationManager->store($job, $request->validated())
            );
            DB::commit();

            $this->status_message = 'Application submitted successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Job application submission error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * View applications for a job listing (Employer view).
     */
    public function index(JobListing $job): JsonResponse
    {
        try {
            $this->data = JobApplicationResource::collection(
                $this->applicationManager->index($job)
            )->response()->getData();
            $this->status_message = 'Job applications retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Job applications retrieval error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * View own submitted applications (Candidate view).
     */
    public function mine(Request $request): JsonResponse
    {
        try {
            $this->data = JobApplicationResource::collection(
                $this->applicationManager->mine($request->only(['search', 'status']))
            )->response()->getData();
            $this->status_message = 'Your job applications retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My job applications retrieval error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * View details of a specific job application.
     */
    public function show(JobApplication $application): JsonResponse
    {
        try {
            $this->data = JobApplicationResource::make(
                $this->applicationManager->show($application)
            );
            $this->status_message = 'Job application details retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Job application details retrieval error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Update application status (Employer view).
     */
    public function updateStatus(Request $request, JobApplication $application): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        try {
            DB::beginTransaction();
            $this->data = JobApplicationResource::make(
                $this->applicationManager->updateStatus($application, $request->input('status'))
            );
            DB::commit();

            $this->status_message = 'Job application status updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Job application status update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
