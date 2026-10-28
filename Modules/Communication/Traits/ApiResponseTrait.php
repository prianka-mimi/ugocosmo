<?php

declare(strict_types=1);

namespace Modules\Communication\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    public function sendResponse(mixed $data, string $message = 'Success', int $code = 200): JsonResponse
    {
        $response = [
            'status' => true,
            'message' => $message,
            'code' => $code,
        ];

        if ($data instanceof LengthAwarePaginator) {
            $response['data'] = $data->items();
            $response['meta'] = [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ];
        } else {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    public function sendError(string $message, int $code = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
        ], $code);
    }
}
