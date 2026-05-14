<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        // Only return users, avoiding password exposure
        $users = User::select('id', 'name', 'email', 'role', 'is_active', 'permissions', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();
            
        // ensure permissions is always an array
        $users->transform(function ($user) {
            $user->permissions = $user->permissions ?? [];
            return $user;
        });

        return ApiResponse::success('Users retrieved successfully', $users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['admin', 'employee', 'owner'])],
            'is_active' => 'boolean',
            'permissions' => 'array',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['permissions'] = $validated['permissions'] ?? [];

        $user = User::create($validated);

        return ApiResponse::success('User created successfully', clone $user->makeHidden('password'), 201);
    }

    public function show(User $user)
    {
        return ApiResponse::success('User retrieved successfully', $user);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'role' => ['sometimes', Rule::in(['admin', 'employee', 'owner'])],
            'is_active' => 'boolean',
            'permissions' => 'array',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return ApiResponse::success('User updated successfully', $user);
    }

    public function destroy(User $user)
    {
        // Prevent deleting oneself or the primary owner
        if (auth()->id() === $user->id) {
            return ApiResponse::error('You cannot delete your own account.', null, 403);
        }

        $user->delete();

        return ApiResponse::success('User deleted successfully');
    }
}
