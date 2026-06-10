<?php

namespace App\Http\Controllers\Api\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * تسجيل مستخدم جديد
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('بيانات التسجيل غير صحيحة', $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'employee', // دور افتراضي
            'is_active' => false, // يجب تفعيله من قبل الإدارة
        ]);

        return ApiResponse::success('تم التسجيل بنجاح. يرجى انتظار تفعيل الحساب من قبل الإدارة.', [
            'user' => new UserResource($user),
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return ApiResponse::error('بيانات الاعتماد غير صحيحة', null, Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active) {
            return ApiResponse::error('الحساب غير نشط', null, Response::HTTP_UNAUTHORIZED);
        }

        // توكن مستقل لكل جهاز/جلسة — لا نحذف جلسات أخرى (نفس المستخدم أو مستخدمين مختلفين)
        $tokenName = 'auth-token-' . Str::uuid();
        $token = $user->createToken($tokenName)->plainTextToken;

        $expirationMinutes = config('sanctum.expiration');

        return ApiResponse::success('تم تسجيل الدخول بنجاح', [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_minutes' => $expirationMinutes,
            'user' => new UserResource($user->load('employee')),
        ]);
    }

    /**
     * تجديد توكن API دون إعادة إدخال كلمة المرور (يُستخدم قبل انتهاء الصلاحية).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        $tokenName = $current?->name ?? ('auth-token-' . Str::uuid());

        if ($current) {
            $user->tokens()->where('id', $current->id)->delete();
        }

        $token = $user->createToken($tokenName)->plainTextToken;
        $expirationMinutes = config('sanctum.expiration');

        return ApiResponse::success('تم تجديد الجلسة بنجاح', [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_minutes' => $expirationMinutes,
            'user' => new UserResource($user->load('employee')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        // Safely log out from the standard web guard and clear session to complete Single Sign-Out
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success('تم تسجيل الخروج بنجاح');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('', new UserResource($request->user()->load('employee')));
    }

    /**
     * تحديث بيانات المستخدم
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'current_password' => 'required_with:password|string',
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('بيانات التحديث غير صحيحة', $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // التحقق من كلمة المرور الحالية إذا أراد تغييرها
        if ($request->filled('password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return ApiResponse::error('كلمة المرور الحالية غير صحيحة', null, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->password = Hash::make($request->password);
        }

        $user->name = $request->name ?? $user->name;
        $user->email = $request->email ?? $user->email;
        $user->save();

        return ApiResponse::success('تم تحديث البيانات بنجاح', [
            'user' => new UserResource($user),
        ]);
    }
}
