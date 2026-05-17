<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(
        string $message,
        mixed $data = null,
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $statusCode);
    }

    public static function error(
        string $message,
        mixed $errors = null,
        int $statusCode = 422
    ): JsonResponse {
        if (!config('app.debug')) {
            $sensitiveKeywords = ['SQLSTATE', 'Call to undefined method', 'Call to a member function', 'Trying to get property', 'Connection refused', 'Stack trace:', 'file_put_contents', 'PDOException'];
            foreach ($sensitiveKeywords as $keyword) {
                if (stripos($message, $keyword) !== false) {
                    $message = 'حدث خطأ داخلي في الخادم. يرجى المحاولة لاحقاً.';
                    break;
                }
            }
        }

        return response()->json([
            'status' => false,
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $statusCode);
    }

    public static function paginated(
        string $message,
        mixed $resource,
        LengthAwarePaginator $paginator
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'success' => true,
            'message' => $message,
            'data' => [
                'items' => $resource,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
            'errors' => null,
        ]);
    }
}
