<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
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

        return ApiResponse::success(
            'تم إنشاء المستخدم بنجاح',
            new UserResource($user->load('employee')),
            Response::HTTP_CREATED
        );
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with('employee')->find($id);

        if (! $user) {
            return ApiResponse::error(
                'المستخدم غير موجود',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        return ApiResponse::success(
            '',
            new UserResource($user)
        );
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return ApiResponse::error(
                'المستخدم غير موجود',
                null,
                Response::HTTP_NOT_FOUND
            );
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

        return ApiResponse::success(
            'تم تحديث المستخدم بنجاح',
            new UserResource($user->load('employee'))
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return ApiResponse::error(
                'المستخدم غير موجود',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        if ($user->id === auth()->user()->id) {
            return ApiResponse::error(
                'لا يمكنك إلغاء تنشيط حسابك الخاص',
                null,
                Response::HTTP_FORBIDDEN
            );
        }

        $user->update(['is_active' => false]);

        if ($user->employee) {
            $user->employee->update(['status' => 'inactive']);
        }

        return ApiResponse::success(
            'تم إلغاء تنشيط المستخدم بنجاح',
            null
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $user = User::with('employee')->find($id);

        if (! $user) {
            return ApiResponse::error(
                'المستخدم غير موجود',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        if ($user->id === auth()->user()->id) {
            return ApiResponse::error(
                'لا يمكنك تغيير حالة حسابك الخاص',
                null,
                Response::HTTP_FORBIDDEN
            );
        }

        $newStatus = ! $user->is_active;
        $user->update(['is_active' => $newStatus]);

        if ($user->employee) {
            $user->employee->update(['status' => $newStatus ? 'active' : 'inactive']);
        }

        return ApiResponse::success(
            $newStatus ? 'تم تفعيل المستخدم بنجاح' : 'تم إلغاء تنشيط المستخدم بنجاح',
            new UserResource($user->load('employee'))
        );
    }
},
            'message' => $newStatus ? 'تم تفعيل المستخدم بنجاح' : 'تم إلغاء تنشيط المستخدم بنجاح',
            'data' => new UserResource($user->load('employee')),
        ]);
    }
}
