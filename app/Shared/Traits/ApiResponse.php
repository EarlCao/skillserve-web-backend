<?php

namespace App\Shared\Traits;

use App\Shared\Services\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Convenience trait for controllers. Delegates to the ApiResponder service
 * so there is a single source of truth for API envelopes.
 */
trait ApiResponse
{
    protected function success(
        mixed $data = null,
        string $message = 'Request successful.',
        array $meta = [],
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return ApiResponder::success($data, $message, $meta, $status, $headers);
    }

    protected function error(
        string $message = 'Something went wrong.',
        int $status = 500,
        mixed $data = null,
        array $errors = [],
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return ApiResponder::error($message, $status, $data, $errors, $meta, $headers);
    }

    protected function paginated(
        LengthAwarePaginator $paginator,
        ?string $resource = null,
        string $message = 'Request successful.',
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return ApiResponder::paginated($paginator, $resource, $message, $meta, $headers);
    }

    protected function noContent(array $headers = []): JsonResponse
    {
        return ApiResponder::noContent($headers);
    }
}
