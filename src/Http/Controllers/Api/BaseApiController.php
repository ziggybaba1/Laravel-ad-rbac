<?php

// src/Http/Controllers/Api/BaseApiController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BaseApiController extends Controller
{
    /**
     * Success response
     */
    protected function success($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $this->getMetaData(),
        ], $code);
    }

    /**
     * Error response
     */
    protected function error(string $message = 'Error', $errors = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'meta' => $this->getMetaData(),
        ], $code);
    }

    /**
     * Get meta data for response
     */
    protected function getMetaData(): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'version' => config('ad-rbac.api.version', '1.0'),
            'authenticated' => Auth::check(),
            'user_id' => Auth::id(),
        ];
    }

    /**
     * Paginated response
     */
    protected function paginated($paginator, string $message = 'Success'): JsonResponse
    {
        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'count' => $paginator->count(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'total_pages' => $paginator->lastPage(),
                'links' => [
                    'next' => $paginator->nextPageUrl(),
                    'previous' => $paginator->previousPageUrl(),
                ],
            ],
        ], $message);
    }

    /**
     * Check if user has permission via API
     */
    protected function checkApiPermission(string $permission): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasPermission($permission) ||
            $user->hasRole('super-admin') ||
            $user->hasRole('admin');
    }

    /**
     * Authorize API request
     */
    protected function authorizeApi(string $permission): void
    {
        if (!$this->checkApiPermission($permission)) {
            abort(403, 'You do not have permission to access this resource.');
        }
    }
}