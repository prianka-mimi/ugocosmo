<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkHistoryRequest;
use App\Manager\WorkHistoryManager;
use App\Models\WorkHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkHistoryController extends Controller
{
    protected WorkHistoryManager $workHistoryManager;

    public function __construct(WorkHistoryManager $workHistoryManager)
    {
        $this->workHistoryManager = $workHistoryManager;
    }

    public function index(): JsonResponse
    {
        try {
            $this->data = $this->workHistoryManager->index();
            $this->status_message = 'Work history retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Work history retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(WorkHistoryRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->workHistoryManager->store($request->validated());
            DB::commit();

            $this->status_message = 'Work history added successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Work history add error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(WorkHistoryRequest $request, WorkHistory $workHistory): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->workHistoryManager->update($workHistory, $request->validated());
            DB::commit();

            $this->status_message = 'Work history updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Work history update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(WorkHistory $workHistory): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->workHistoryManager->destroy($workHistory);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Work history deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Work history delete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
