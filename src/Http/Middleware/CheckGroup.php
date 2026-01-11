<?php

// src/Http/Middleware/CheckGroup.php
namespace LaravelAdRbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckGroup
{
    public function handle(Request $request, Closure $next, string $group, string $guard = null)
    {
        $guard = $guard ?: config('ad-rbac.auth.default_guard', 'ad');

        $user = $request->user($guard);

        if (!$user) {
            return $this->unauthorized($request);
        }

        if (!$user->inGroup($group)) {
            return $this->unauthorized($request, "Required group membership: {$group}");
        }

        return $next($request);
    }

    protected function unauthorized(Request $request, string $message = null)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message ?? 'Unauthorized.',
                'required_group' => request()->route()->parameter('group')
            ], 403);
        }

        session()->flash('error', $message ?? 'You are not authorized to access this page.');
        return redirect()->route('ad-rbac.dashboard');
    }
}