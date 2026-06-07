<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->select('id', 'name', 'email', 'role', 'is_active', 'permissions', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => (bool) $user->is_active,
                    'permissions' => UserPermissions::sanitize($user->permissions),
                    'effective_permissions' => UserPermissions::effectiveFor($user),
                    'created_at' => $user->created_at?->toDateTimeString(),
                ];
            });

        return ApiResponse::success('Users retrieved successfully', [
            'users' => $users,
            'available_permissions' => UserPermissions::definitions(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['admin', 'employee', 'owner'])],
            'is_active' => 'boolean',
            'permissions' => 'array',
            'permissions.*' => [Rule::in(UserPermissions::keys())],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['permissions'] = UserPermissions::sanitize($validated['permissions'] ?? []);

        $user = User::create($validated);

        return ApiResponse::success(
            'User created successfully',
            new UserResource($user),
            201
        );
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success('User retrieved successfully', new UserResource($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'owner' && $request->filled('role') && $request->input('role') !== 'owner') {
            return ApiResponse::error('لا يمكن تغيير دور حساب المالك.', null, 403);
        }

        if (auth()->id() === $user->id && $request->has('is_active') && ! $request->boolean('is_active')) {
            return ApiResponse::error('لا يمكن إيقاف حسابك الحالي.', null, 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'role' => ['sometimes', Rule::in(['admin', 'employee', 'owner'])],
            'is_active' => 'boolean',
            'permissions' => 'array',
            'permissions.*' => [Rule::in(UserPermissions::keys())],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (array_key_exists('permissions', $validated)) {
            $validated['permissions'] = UserPermissions::sanitize($validated['permissions']);
        }

        $user->update($validated);

        return ApiResponse::success('User updated successfully', new UserResource($user->fresh()));
    }

    public function destroy(User $user): JsonResponse
    {
        if (auth()->id() === $user->id) {
            return ApiResponse::error('لا يمكن حذف حسابك الحالي.', null, 403);
        }

        if ($user->role === 'owner') {
            return ApiResponse::error('لا يمكن حذف حساب المالك.', null, 403);
        }

        $user->delete();

        return ApiResponse::success('User deleted successfully');
    }
}
