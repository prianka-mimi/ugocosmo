<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Stateless, immutable API response formatter.
 *
 * Replaces the mutable stateful JsonResponseFormat trait pattern: each
 * response is built from static factory methods and carries no instance
 * state, making it trivially testable and safe under concurrency.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'status_code' => $code,
            'status_message' => $message,
            'data' => self::transform($data),
        ], $code);
    }

    /**
     * @param  int|string  $code  HTTP status; exception codes are accepted
     *                            because PDO/query exceptions expose SQLSTATE
     *                            strings (e.g. '23000') — those are not HTTP
     *                            statuses and fall back to 500.
     */
    public static function error(string $message, int|string $code = 400, mixed $errors = null): JsonResponse
    {
        $code = self::normalizeStatusCode($code);

        return response()->json([
            'status' => false,
            'status_code' => $code,
            'status_message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Normalize a status code: ints in the HTTP range pass through, numeric
     * strings coerce to int, anything else (e.g. an exception's SQLSTATE
     * string like '23000' or 'HY000') is not a valid HTTP status and
     * becomes 500.
     */
    private static function normalizeStatusCode(int|string $code): int
    {
        if (is_string($code) && ! ctype_digit($code)) {
            return 500;
        }

        $code = (int) $code;

        return $code >= 100 && $code <= 599 ? $code : 500;
    }

    public static function paginated(LengthAwarePaginator $paginator, mixed $data, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'status_code' => $code,
            'status_message' => $message,
            'data' => self::transform($data),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], $code);
    }

    private static function transform(mixed $data): mixed
    {
        $transformed = match (true) {
            $data instanceof LengthAwarePaginator => $data->toArray(),
            $data instanceof ResourceCollection => $data->response()->getData(true),
            $data instanceof JsonResource => $data->response()->getData(true),
            default => $data,
        };

        if (is_array($transformed) && array_key_exists('data', $transformed)) {
            // Check if there are other top-level keys like 'meta' or 'links' from pagination
            // If it's a paginated resource collection, we just want the items.
            return $transformed['data'];
        }

        return $transformed;
    }
}
