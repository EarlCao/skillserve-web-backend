<?php

namespace App\Shared\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Single source of truth for API responses.
 *
 * Every API endpoint should return one of these envelopes:
 *
 * {
 *     "success": true|false,
 *     "message": "string",
 *     "data":    mixed,
 *     "errors":  array|null,
 *     "meta":    array
 * }
 *
 * Controllers should never build JSON responses manually — use the
 * ApiResponse trait (or this class directly) instead.
 */
final class ApiResponder
{
    /**
     * Successful response.
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request successful.',
        array $meta = [],
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return self::respond(true, $message, $data, null, $meta, $status, $headers);
    }

    /**
     * Error response.
     */
    public static function error(
        string $message = 'Something went wrong.',
        int $status = 500,
        mixed $data = null,
        array $errors = [],
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return self::respond(false, $message, $data, $errors, $meta, $status, $headers);
    }

    /**
     * Paginated success response with standard pagination metadata.
     *
     * @param  class-string|null  $resource  Optional resource class for items.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        ?string $resource = null,
        string $message = 'Request successful.',
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $data = $resource
            ? $resource::collection($paginator->items())
            : $paginator->items();

        $meta = array_merge($meta, [
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);

        return self::respond(true, $message, $data, null, $meta, 200, $headers);
    }

    /**
     * Empty 204 response.
     */
    public static function noContent(array $headers = []): JsonResponse
    {
        return response()->json(null, 204, $headers);
    }

    /**
     * Build the standard envelope.
     */
    private static function respond(
        bool $success,
        string $message,
        mixed $data,
        mixed $errors,
        array $meta,
        int $status,
        array $headers,
    ): JsonResponse {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data ?? (object) [],
            'errors' => $errors ?: null,
            'meta' => $meta,
        ], $status, $headers);
    }
}
