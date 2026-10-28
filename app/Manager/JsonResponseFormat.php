<?php

namespace App\Manager;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

trait JsonResponseFormat
{
    protected mixed $data = [];

    protected ?string $status_message = null;

    protected mixed $status_code = 200;

    protected ?array $pagination = null;

    protected ?array $additional = null;

    protected function JsonResponseFormat(): JsonResponse
    {
        // ── Ensure status_code is an integer ──────────────────────────
        $this->status_code = (int) $this->status_code;

        // ── Validate HTTP status code (must be 100–599) ───────────────
        if ($this->status_code < 100 || $this->status_code > 599) {
            $this->status_code = 500;
        }

        $response = [
            'status' => $this->status_code >= 200 && $this->status_code < 300,
            'status_code' => $this->status_code,
            'status_message' => $this->status_message
                ?? ($this->status_code >= 200 && $this->status_code < 300
                    ? 'Successfully Data Retrieved'
                    : 'Data Retrieval Failed'),
        ];

        // ── Auto-detect profile completion errors and add redirect URL ──
        if ($this->status_code >= 400 && $this->status_message) {
            $msg = mb_strtolower($this->status_message);
            if (str_contains($msg, 'complete your profile') || str_contains($msg, 'profile not found')) {
                $response['redirect_to'] = url('api/profile/basic-information');
            }
        }

        if ($this->data instanceof LengthAwarePaginator) {
            $response = array_merge($response, $this->data->toArray());
        } elseif ($this->data instanceof ResourceCollection) {
            $response += $this->data->response()->getData(true);
        } elseif ($this->data instanceof JsonResource) {
            $resourceData = $this->data->response()->getData(true);
            $response['data'] = (is_array($resourceData) && array_key_exists('data', $resourceData))
                ? $resourceData['data']
                : $resourceData;
        } else {
            $response['data'] = $this->data;
        }

        // Merge pagination metadata if set
        if ($this->pagination !== null) {
            $response = array_merge($response, [
                'pagination' => $this->pagination,
            ]);
        }

        // Merge additional metadata if set
        if ($this->additional !== null) {
            $response = array_merge($response, $this->additional);
        }

        return response()->json($response, $this->status_code);
    }
}
