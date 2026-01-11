<?php

// src/Http/Controllers/Api/DocumentationController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Http\Request;

class DocumentationController extends BaseApiController
{
    public function index()
    {
        return response()->json([
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('ad-rbac.api.swagger.title'),
                'version' => config('ad-rbac.api.swagger.version'),
                'description' => config('ad-rbac.api.swagger.description'),
            ],
            'servers' => [
                ['url' => url('/api/ad-rbac'), 'description' => 'API Server'],
            ],
            'paths' => $this->getPaths(),
            'components' => [
                'schemas' => $this->getSchemas(),
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
            'security' => [['bearerAuth' => []]],
        ]);
    }

    protected function getPaths(): array
    {
        return [
            '/auth/login' => [
                'post' => [
                    'tags' => ['Authentication'],
                    'summary' => 'Login with AD credentials',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['username', 'password'],
                                    'properties' => [
                                        'username' => ['type' => 'string', 'example' => 'jdoe'],
                                        'password' => ['type' => 'string', 'example' => 'secret123'],
                                        'remember' => ['type' => 'boolean', 'example' => false],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Login successful',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SuccessResponse'],
                                ],
                            ],
                        ],
                        '401' => ['description' => 'Invalid credentials'],
                    ],
                ],
            ],
            // ... other paths
        ];
    }

    protected function getSchemas(): array
    {
        return [
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Success'],
                    'data' => ['type' => 'object'],
                    'meta' => ['$ref' => '#/components/schemas/Meta'],
                ],
            ],
            'Employee' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'ad_username' => ['type' => 'string', 'example' => 'jdoe'],
                    'email' => ['type' => 'string', 'example' => 'john.doe@example.com'],
                    'first_name' => ['type' => 'string', 'example' => 'John'],
                    'last_name' => ['type' => 'string', 'example' => 'Doe'],
                    'full_name' => ['type' => 'string', 'example' => 'John Doe'],
                    'department' => ['type' => 'string', 'example' => 'IT'],
                    'position' => ['type' => 'string', 'example' => 'Developer'],
                    'is_active' => ['type' => 'boolean', 'example' => true],
                    'last_login_at' => ['type' => 'string', 'format' => 'date-time'],
                    'ad_sync_at' => ['type' => 'string', 'format' => 'date-time'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'Role' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Administrator'],
                    'slug' => ['type' => 'string', 'example' => 'admin'],
                    'description' => ['type' => 'string', 'example' => 'System administrator'],
                    'is_system' => ['type' => 'boolean', 'example' => false],
                ],
            ],
            'Pagination' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer', 'example' => 100],
                    'count' => ['type' => 'integer', 'example' => 20],
                    'per_page' => ['type' => 'integer', 'example' => 20],
                    'current_page' => ['type' => 'integer', 'example' => 1],
                    'total_pages' => ['type' => 'integer', 'example' => 5],
                    'links' => [
                        'type' => 'object',
                        'properties' => [
                            'next' => ['type' => 'string', 'nullable' => true],
                            'previous' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                ],
            ],
            'Meta' => [
                'type' => 'object',
                'properties' => [
                    'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                    'version' => ['type' => 'string', 'example' => '1.0.0'],
                    'authenticated' => ['type' => 'boolean'],
                    'user_id' => ['type' => 'integer', 'nullable' => true],
                ],
            ],
        ];
    }
}