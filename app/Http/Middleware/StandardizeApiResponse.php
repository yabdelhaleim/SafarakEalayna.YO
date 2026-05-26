<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StandardizeApiResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if ($request->hasHeader('X-Livewire')) {
                return $response;
            }

            if (isset($data['success']) && !isset($data['status'])) {
                return $response;
            }

            $isSuccess = $response->isSuccessful();
            if (isset($data['status'])) {
                if (is_bool($data['status'])) {
                    $isSuccess = $data['status'];
                } elseif ($data['status'] === 'success' || $data['status'] === 'error') {
                    $isSuccess = $data['status'] === 'success';
                }
            } elseif (isset($data['success'])) {
                $isSuccess = $data['success'];
            }

            $actualData = $data['data'] ?? $data;

            if (isset($data['items']) && isset($data['pagination'])) {
                $actualData = [
                    'items' => $data['items'],
                    'pagination' => $data['pagination']
                ];
            }

            $formatted = [
                'success' => $isSuccess,
                'message' => $data['message'] ?? ($isSuccess ? 'Success' : 'Error'),
                'data' => $actualData,
                'errors' => $data['errors'] ?? null,
            ];

            $response->setData($formatted);
        }

        return $response;
    }
}
