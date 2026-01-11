<?php

// src/Http/Middleware/CheckAnyPermission.php
namespace LaravelAdRbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelAdRbac\Services\PermissionChecker;

class CheckAnyPermission
{
    protected $permissionChecker;

    public function __construct(PermissionChecker $permissionChecker)
    {
        $this->permissionChecker = $permissionChecker;
    }

    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $guard = config('ad-rbac.auth.default_guard', 'ad');
        $user = $request->user($guard);

        if (!$user) {
            return $this->unauthorized($request);
        }

        foreach ($permissions as $permission) {
            if ($this->permissionChecker->hasPermission($user, $permission)) {
                return $next($request);
            }
        }

        return $this->unauthorized($request, "Requires one of: " . implode(', ', $permissions));
    }

    protected function unauthorized(Request $request, string $message = null)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message ?? 'Unauthorized.'
            ], 403);
        }

        session()->flash('error', $message ?? 'You are not authorized to access this page.');
        return redirect()->route('ad-rbac.dashboard');
    }
}