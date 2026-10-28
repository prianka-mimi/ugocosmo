<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceIndexRequest;
use App\Http\Requests\ServiceStoreRequest;
use App\Http\Requests\ServiceUpdateRequest;
use App\Http\Resources\ServiceResource;
use App\Manager\ServiceManager;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServiceController extends Controller
{
    protected ServiceManager $serviceManager;

    public function __construct(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    public function index(ServiceIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = ServiceResource::collection($this->serviceManager->index($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Services retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Services retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function mine(Request $request): JsonResponse
    {
        try {
            $this->data = $this->serviceManager->mine($request->only('search'));
            $this->status_message = 'Your services retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My services retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(ServiceStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ServiceResource::make($this->serviceManager->store($request->validated()));
            DB::commit();

            $this->status_message = 'Service created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Service $service): JsonResponse
    {
        try {
            $this->data = ServiceResource::make($this->serviceManager->show($service));
            $this->status_message = 'Service retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Service retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(ServiceUpdateRequest $request, Service $service): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ServiceResource::make($this->serviceManager->update($service, $request->validated()));
            DB::commit();

            $this->status_message = 'Service updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(Service $service): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->serviceManager->destroy($service);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Service deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
