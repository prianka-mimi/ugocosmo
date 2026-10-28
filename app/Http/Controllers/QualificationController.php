<?php

namespace App\Http\Controllers;

use App\Http\Requests\QualificationRequest;
use App\Manager\QualificationManager;
use App\Models\Qualification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class QualificationController extends Controller
{
    protected QualificationManager $qualificationManager;

    public function __construct(QualificationManager $qualificationManager)
    {
        $this->qualificationManager = $qualificationManager;
    }

    public function index(): JsonResponse
    {
        try {
            $this->data = $this->qualificationManager->index();
            $this->status_message = 'Qualification retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Qualification retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(QualificationRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->qualificationManager->store($request->validated());
            DB::commit();

            $this->status_message = 'Qualification added successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Qualification add error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(QualificationRequest $request, Qualification $qualification): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->qualificationManager->update($qualification, $request->validated());
            DB::commit();

            $this->status_message = 'Qualification updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Qualification update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(Qualification $qualification): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->qualificationManager->destroy($qualification);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Qualification deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Qualification delete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
