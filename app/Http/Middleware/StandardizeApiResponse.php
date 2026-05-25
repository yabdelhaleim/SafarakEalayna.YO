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
            
            // Skip if it's already formatted or it's a Livewire/Filament request
            if (isset($data['success']) && array_key_exists('errors', $data) && array_key_exists('message', $data) && array_key_exists('data', $data)) {
                // If it already matches the exact contract, leave it
                return $response;
            }

            if ($request->hasHeader('X-Livewire')) {
                return $response; // Don't format Livewire requests
            }

            // Determine success
            $isSuccess = $response->isSuccessful();
            if (isset($data['success'])) {
                $isSuccess = (bool) $data['success'];
            } elseif (isset($data['status'])) {
                if (is_bool($data['status'])) {
                    $isSuccess = $data['status'];
                } elseif ($data['status'] === 'success' || $data['status'] === 'error' || $data['status'] === 'SUCCESS') {
                    $isSuccess = ($data['status'] === 'success' || $data['status'] === 'SUCCESS');
                }
            }

            // Extract actual data
            $actualData = $data['data'] ?? $data;
            
            // Exception for pagination
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
