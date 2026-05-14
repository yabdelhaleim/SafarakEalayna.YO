<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Wallet\WalletTypeResource;
use App\Models\Wallet\WalletType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WalletType::query();

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $types = $query->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(
            'Wallet types retrieved successfully.',
            WalletTypeResource::collection($types)
        );
    }
}
