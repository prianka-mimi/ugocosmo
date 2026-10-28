<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobIndexRequest;
use App\Http\Requests\JobStoreRequest;
use App\Http\Requests\JobUpdateRequest;
use App\Http\Resources\JobResource;
use App\Manager\JobManager;
use App\Models\JobListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JobController extends Controller
{
    protected JobManager $jobManager;

    public function __construct(JobManager $jobManager)
    {
        $this->jobManager = $jobManager;
    }

    public function index(JobIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = JobResource::collection($this->jobManager->index($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Job listings retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Job listings retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function mine(Request $request): JsonResponse
    {
        try {
            $this->data = $this->jobManager->mine($request->only('search'));
            $this->status_message = 'Your job listings retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My job listings retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(JobStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = JobResource::make($this->jobManager->store($request->validated()));
            DB::commit();

            $this->status_message = 'Job listing created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Job listing creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(JobListing $job): JsonResponse
    {
        try {
            $this->data = JobResource::make($this->jobManager->show($job));
            $this->status_message = 'Job listing retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Job listing retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(JobUpdateRequest $request, JobListing $job): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = JobResource::make($this->jobManager->update($job, $request->validated()));
            DB::commit();

            $this->status_message = 'Job listing updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Job listing update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(JobListing $job): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->jobManager->destroy($job);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Job listing deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Job listing deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
