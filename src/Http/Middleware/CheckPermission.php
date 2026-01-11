<?php

// src/Http/Middleware/CheckPermission.php
namespace LaravelAdRbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelAdRbac\Services\PermissionChecker;

class CheckPermission
{
    protected $permissionChecker;

    public function __construct(PermissionChecker $permissionChecker)
    {
        $this->permissionChecker = $permissionChecker;
    }

    public function handle(Request $request, Closure $next, string $permission, string $guard = null)
    {
        $guard = $guard ?: config('ad-rbac.auth.default_guard', 'ad');

        $user = $request->user($guard);

        if (!$user) {
            return $this->unauthorized($request);
        }

        if (!$this->permissionChecker->hasPermission($user, $permission)) {
            return $this->unauthorized($request, "You don't have permission to: {$permission}");
        }

        return $next($request);
    }

    protected function unauthorized(Request $request, string $message = null)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message ?? 'Unauthorized.',
                'required_permission' => request()->route()->parameter('permission')
            ], 403);
        }

        session()->flash('error', $message ?? 'You are not authorized to access this page.');
        return redirect()->route('ad-rbac.dashboard');
    }
}