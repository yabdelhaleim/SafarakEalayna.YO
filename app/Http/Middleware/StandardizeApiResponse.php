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

            // Extract message and errors safely before altering the keys
            $messageText = $data['message'] ?? ($isSuccess ? 'Success' : 'Error');
            $errorsData = $data['errors'] ?? null;

            // Extract actual data
            $actualData = $data['data'] ?? $data;
            if (isset($data['success']) || isset($data['message']) || isset($data['errors'])) {
                // If it is an error or validation payload, do not wrap the outer keys inside 'data'
                unset($data['success'], $data['message'], $data['errors']);
                $actualData = isset($data['data']) ? $data['data'] : (!empty($data) ? $data : null);
            }
            
            // Exception for pagination
            if (isset($data['items']) && isset($data['pagination'])) {
                $actualData = [
                    'items' => $data['items'],
                    'pagination' => $data['pagination']
                ];
            }

            $formatted = [
                'success' => $isSuccess,
                'message' => $messageText,
                'data' => $actualData,
                'errors' => $errorsData,
            ];

            $response->setData($formatted);
        }

        return $response;
    }
}
