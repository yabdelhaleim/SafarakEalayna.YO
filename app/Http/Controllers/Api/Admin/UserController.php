<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $users = User::with('employee')->paginate(15);

        return UserResource::collection($users);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'role' => $request->role,
                'is_active' => true,
            ]);

            Employee::create([
                'user_id' => $user->id,
                'salary' => $request->salary ?? 0,
                'status' => $request->status ?? 'active',
            ]);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء المستخدم بنجاح',
            'data' => new UserResource($user->load('employee')),
        ], Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with('employee')->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود',
                'data' => null,
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => new UserResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود',
                'data' => null,
            ], Response::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($request, $user) {
            $user->update($request->only(['name', 'email', 'role', 'is_active']));

            if ($user->employee) {
                $employeeData = $request->only(['salary', 'status']);
                if (! empty($employeeData)) {
                    $user->employee->update($employeeData);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المستخدم بنجاح',
            'data' => new UserResource($user->load('employee')),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود',
                'data' => null,
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user->id === auth()->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك إلغاء تنشيط ح��ابك الخاص',
                'data' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        $user->update(['is_active' => false]);

        if ($user->employee) {
            $user->employee->update(['status' => 'inactive']);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تنشيط المستخدم بنجاح',
            'data' => null,
        ]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $user = User::with('employee')->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود',
                'data' => null,
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user->id === auth()->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تغيير حالة حسابك الخاص',
                'data' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        $newStatus = ! $user->is_active;
        $user->update(['is_active' => $newStatus]);

        if ($user->employee) {
            $user->employee->update(['status' => $newStatus ? 'active' : 'inactive']);
        }

        return response()->json([
            'success' => true,
            'message' => $newStatus ? 'تم تفعيل المستخدم بنجاح' : 'تم إلغاء تنشيط المستخدم بنجاح',
            'data' => new UserResource($user->load('employee')),
        ]);
    }
}
