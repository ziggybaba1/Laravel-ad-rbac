<?php

// src/Http/Middleware/CheckRole.php
namespace LaravelAdRbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string $role, string $guard = null)
    {
        $guard = $guard ?: config('ad-rbac.auth.default_guard', 'ad');

        $user = $request->user($guard);

        if (!$user) {
            return $this->unauthorized($request);
        }

        if (!$user->hasRole($role)) {
            return $this->unauthorized($request, "Required role: {$role}");
        }

        return $next($request);
    }

    protected function unauthorized(Request $request, string $message = null)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message ?? 'Unauthorized.',
                'required_role' => request()->route()->parameter('role')
            ], 403);
        }

        session()->flash('error', $message ?? 'You are not authorized to access this page.');
        return redirect()->route('ad-rbac.dashboard');
    }
}