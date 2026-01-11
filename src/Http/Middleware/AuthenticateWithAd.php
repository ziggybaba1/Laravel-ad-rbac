<?php

// src/Http/Middleware/AuthenticateWithAd.php
namespace LaravelAdRbac\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateWithAd
{
    public function handle(Request $request, Closure $next, string $guard = null)
    {
        $guard = $guard ?: config('ad-rbac.auth.default_guard', 'ad');

        if (Auth::guard($guard)->check()) {
            return $next($request);
        }

        // Store intended URL for redirect after login
        if ($request->isMethod('GET') && !$request->isJson()) {
            session()->put('url.intended', $request->fullUrl());
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Unauthenticated.'], 401)
            : redirect()->route('ad-rbac.login');
    }
}