<?php

// src/Http/Controllers/Api/AuthController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LaravelAdRbac\Services\AdAuthService;
use LaravelAdRbac\Http\Resources\EmployeeResource;

class AuthController extends BaseApiController
{
    protected $adAuthService;

    public function __construct(AdAuthService $adAuthService)
    {
        $this->adAuthService = $adAuthService;
    }

    /**
     * @OA\Post(
     *     path="/api/ad-rbac/auth/login",
     *     summary="Login with AD credentials",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username","password"},
     *             @OA\Property(property="username", type="string", example="jdoe"),
     *             @OA\Property(property="password", type="string", example="secret123"),
     *             @OA\Property(property="remember", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials"
     *     )
     * )
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        // Attempt AD authentication
        $authenticated = $this->adAuthService->authenticate(
            $request->username,
            $request->password
        );

        if (!$authenticated) {
            return $this->error('Invalid AD credentials', null, 401);
        }

        // Get employee and log them in
        $employee = config('ad-rbac.models.employee')::where('ad_username', $request->username)->first();

        if (!$employee || !$employee->is_active) {
            return $this->error('Employee account not active', null, 403);
        }

        Auth::login($employee, $request->boolean('remember', false));

        // Update last login
        $employee->update(['last_login_at' => now()]);

        $token = null;
        if ($request->wantsJsonToken()) {
            $token = $employee->createToken('ad-rbac-api')->plainTextToken;
        }

        return $this->success([
            'employee' => new EmployeeResource($employee->load('roles', 'permissions')),
            'token' => $token,
            'permissions' => $employee->getAllPermissions()->pluck('slug'),
            'roles' => $employee->roles->pluck('slug'),
        ], 'Login successful');
    }

    /**
     * @OA\Post(
     *     path="/api/ad-rbac/auth/logout",
     *     summary="Logout current user",
     *     tags={"Authentication"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful"
     *     )
     * )
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            // Revoke API tokens if using Sanctum
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->success(null, 'Logout successful');
    }

    /**
     * @OA\Get(
     *     path="/api/ad-rbac/auth/user",
     *     summary="Get current authenticated user",
     *     tags={"Authentication"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User data",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Employee")
     *         )
     *     )
     * )
     */
    public function user(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Not authenticated', null, 401);
        }

        $user->load(['roles', 'permissions', 'groups']);

        return $this->success([
            'employee' => new EmployeeResource($user),
            'permissions' => $user->getAllPermissions()->pluck('slug'),
            'roles' => $user->roles->pluck('slug'),
            'groups' => $user->groups->pluck('slug'),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/ad-rbac/auth/permissions",
     *     summary="Get current user's permissions",
     *     tags={"Authentication"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User permissions"
     *     )
     * )
     */
    public function permissions(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Not authenticated', null, 401);
        }

        $permissions = $user->getAllPermissions()->groupBy('module');

        return $this->success([
            'permissions' => $permissions,
            'effective_permissions' => $user->getAllPermissions()->pluck('slug'),
            'module_permissions' => $permissions->map(function ($items) {
                return $items->pluck('action');
            }),
        ]);
    }
}