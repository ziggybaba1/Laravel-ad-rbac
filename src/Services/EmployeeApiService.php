<?php

// src/Services/EmployeeApiService.php
namespace LaravelAdRbac\Services;

use LaravelAdRbac\Contracts\EmployeeApiInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

abstract class EmployeeApiService implements EmployeeApiInterface
{
    protected $config;
    protected $httpClient;
    protected $cacheEnabled = true;
    protected $cacheTtl = 3600; // 1 hour in seconds

    public function __construct()
    {
        $this->config = config('ad-rbac.employee_api', []);
        $this->httpClient = $this->createHttpClient();
        $this->cacheTtl = $this->config['cache_ttl'] ?? 3600;
        $this->cacheEnabled = $this->config['cache_enabled'] ?? true;
    }

    /**
     * Create HTTP client with common configuration
     */
    protected function createHttpClient()
    {
        $baseUrl = $this->config['base_url'] ?? '';

        if (empty($baseUrl)) {
            Log::error('Employee API base URL is not configured');
            throw new \Exception('Employee API base URL is not configured');
        }

        // Ensure base URL ends with slash for proper endpoint concatenation
        if (!str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }

        return Http::withOptions([
            'base_uri' => $baseUrl,
            'timeout' => $this->config['timeout'] ?? 30,
            'connect_timeout' => $this->config['connect_timeout'] ?? 10,
            'verify' => $this->config['verify_ssl'] ?? true,
        ])->withHeaders($this->getDefaultHeaders());
    }
    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . ($this->config['api_token'] ?? ''),
            'X-API-Key' => $this->config['api_key'] ?? '',
            'X-Requested-With' => 'Laravel AD RBAC',
        ];
    }
    /**
     * Fetch employee data from external API
     */
    public function fetchEmployeeData(string $username): ?array
    {
        $cacheKey = $this->getCacheKey("employee.{$username}");

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $employeeData = $this->fetchEmployeeFromApi($username);

            if ($employeeData && $this->cacheEnabled) {
                Cache::put($cacheKey, $employeeData, $this->cacheTtl);
            }

            return $employeeData;
        } catch (\Exception $e) {
            $this->logError('fetchEmployeeData', $e, ['username' => $username]);

            // Return cached data if available (even if expired)
            if ($this->config['use_stale_cache'] ?? false) {
                return Cache::get($cacheKey);
            }

            return null;
        }
    }

    /**
     * Actual API call to fetch employee - must be implemented by concrete class
     */
    abstract protected function fetchEmployeeFromApi(string $username): ?array;

    /**
     * Fetch multiple employees by criteria
     */
    public function fetchEmployees(array $criteria = [], int $page = 1, int $perPage = 50): array
    {
        $cacheKey = $this->getCacheKey('employees.' . md5(serialize($criteria) . $page . $perPage));

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = $this->fetchEmployeesFromApi($criteria, $page, $perPage);

            if ($this->cacheEnabled) {
                Cache::put($cacheKey, $response, $this->cacheTtl);
            }

            return $response;
        } catch (\Exception $e) {
            $this->logError('fetchEmployees', $e, compact('criteria', 'page', 'perPage'));
            return [];
        }
    }

    /**
     * Actual API call for multiple employees
     */
    abstract protected function fetchEmployeesFromApi(array $criteria, int $page, int $perPage): array;

    /**
     * Search employees by various fields
     */
    public function searchEmployees(string $query, array $fields = []): array
    {
        $cacheKey = $this->getCacheKey('search.' . md5($query . serialize($fields)));

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $results = $this->searchEmployeesFromApi($query, $fields);

            if ($this->cacheEnabled) {
                Cache::put($cacheKey, $results, 300); // 5 minutes for search results
            }

            return $results;
        } catch (\Exception $e) {
            $this->logError('searchEmployees', $e, compact('query', 'fields'));
            return [];
        }
    }

    /**
     * Actual API call for search
     */
    abstract protected function searchEmployeesFromApi(string $query, array $fields): array;

    /**
     * Get employee by employee ID
     */
    public function getEmployeeById(string $employeeId): ?array
    {
        $cacheKey = $this->getCacheKey("employee.id.{$employeeId}");

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $employeeData = $this->getEmployeeByIdFromApi($employeeId);

            if ($employeeData && $this->cacheEnabled) {
                Cache::put($cacheKey, $employeeData, $this->cacheTtl);
            }

            return $employeeData;
        } catch (\Exception $e) {
            $this->logError('getEmployeeById', $e, ['employeeId' => $employeeId]);
            return null;
        }
    }

    /**
     * Actual API call for employee by ID
     */
    abstract protected function getEmployeeByIdFromApi(string $employeeId): ?array;

    /**
     * Get department structure
     */
    public function getDepartments(): array
    {
        $cacheKey = $this->getCacheKey('departments');

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $departments = $this->getDepartmentsFromApi();

            if ($this->cacheEnabled) {
                Cache::put($cacheKey, $departments, 86400); // 24 hours
            }

            return $departments;
        } catch (\Exception $e) {
            $this->logError('getDepartments', $e);

            // Return cached data if available
            if ($this->config['use_stale_cache'] ?? false) {
                return Cache::get($cacheKey) ?? [];
            }

            return [];
        }
    }

    /**
     * Actual API call for departments
     */
    abstract protected function getDepartmentsFromApi(): array;

    /**
     * Get department structure
     */
    public function getZones(): array
    {
        $cacheKey = $this->getCacheKey('zones');

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $zones = $this->getZonesFromApi();

            if ($this->cacheEnabled) {
                Cache::put($cacheKey, $zones, 86400); // 24 hours
            }

            return $zones;
        } catch (\Exception $e) {
            $this->logError('getZones', $e);

            // Return cached data if available
            if ($this->config['use_stale_cache'] ?? false) {
                return Cache::get($cacheKey) ?? [];
            }

            return [];
        }
    }

    /**
     * Actual API call for zones
     */
    abstract protected function getZonesFromApi(): array;

    /**
     * Get positions/job titles
     */
    public function getPositions(): array
    {
        $cacheKey = $this->getCacheKey('positions');

        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $positions = $this->getPositionsFromApi();

            if ($this->cacheEnabled) {
                Cache::put($cacheKey, $positions, 86400); // 24 hours
            }

            return $positions;
        } catch (\Exception $e) {
            $this->logError('getPositions', $e);

            // Return cached data if available
            if ($this->config['use_stale_cache'] ?? false) {
                return Cache::get($cacheKey) ?? [];
            }

            return [];
        }
    }

    /**
     * Actual API call for positions
     */
    abstract protected function getPositionsFromApi(): array;

    /**
     * Validate API connection
     */
    public function validateConnection(): bool
    {
        try {
            $response = $this->httpClient->get($this->config['health_endpoint'] ?? '/health');
            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Employee API connection failed', [
                'error' => $e->getMessage(),
                'endpoint' => $this->config['base_url']
            ]);
            return false;
        }
    }

    /**
     * Get API status/health
     */
    public function getApiStatus(): array
    {
        try {
            $response = $this->httpClient->get($this->config['status_endpoint'] ?? '/status');

            if ($response->successful()) {
                return array_merge(
                    $response->json(),
                    ['connected' => true, 'last_checked' => now()->toISOString()]
                );
            }
        } catch (\Exception $e) {
            // Continue to return default status
        }

        return [
            'connected' => false,
            'message' => 'Unable to connect to employee API',
            'last_checked' => now()->toISOString(),
            'config' => [
                'base_url' => $this->config['base_url'],
                'timeout' => $this->config['timeout'] ?? 30,
            ]
        ];
    }

    /**
     * Clear cache for specific employee
     */
    public function clearEmployeeCache(string $username): bool
    {
        $cacheKey = $this->getCacheKey("employee.{$username}");
        return Cache::forget($cacheKey);
    }

    /**
     * Clear all API cache
     */
    public function clearAllCache(): bool
    {
        $pattern = $this->getCacheKey('*');

        // Implementation depends on cache driver
        if (config('cache.default') === 'redis') {
            $keys = Cache::getRedis()->keys($pattern);
            foreach ($keys as $key) {
                Cache::forget(str_replace(config('cache.prefix'), '', $key));
            }
            return true;
        }

        // For file/database cache, we can't easily clear by pattern
        Log::warning('Cannot clear all API cache with current cache driver');
        return false;
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $key): string
    {
        return "ad_rbac:api:" . $key;
    }

    /**
     * Log API errors
     */
    protected function logError(string $method, \Exception $e, array $context = []): void
    {
        Log::error("Employee API Error in {$method}", [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'context' => $context,
            'config' => [
                'base_url' => $this->config['base_url'],
                'timeout' => $this->config['timeout'],
            ]
        ]);
    }

    /**
     * Make authenticated API request
     */
    protected function makeRequest(string $method, string $endpoint, array $data = []): ?array
    {
        // Ensure httpClient is initialized
        if (!$this->httpClient) {
            Log::error('HTTP client not initialized');
            throw new \Exception('HTTP client not initialized');
        }

        try {
            // Log the request for debugging
            Log::debug('Making API request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data,
            ]);

            $response = $this->httpClient->{$method}($endpoint, $data);

            Log::debug('API response status', [
                'status' => $response->status(),
                'endpoint' => $endpoint,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            $this->handleErrorResponse($response);
            return null;

        } catch (RequestException $e) {
            $this->logError('makeRequest', $e, [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data
            ]);

            // Re-throw or return null based on your error handling strategy
            throw $e;
        } catch (\Exception $e) {
            $this->logError('makeRequest', $e, [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Handle error responses
     */
    protected function handleErrorResponse($response): void
    {
        $status = $response->status();
        $body = $response->body();

        Log::warning("Employee API Error Response", [
            'status' => $status,
            'body' => $body,
            'headers' => $response->headers()
        ]);

        if ($status === 401) {
            throw new \Exception(
                'Invalid API credentials'
            );
        }

        if ($status === 403) {
            throw new \Exception(
                'API access forbidden'
            );
        }

        if ($status === 404) {
            throw new \Exception(
                'Resource not found'
            );
        }

        if ($status >= 500) {
            throw new \Exception(
                'Employee API server error'
            );
        }

        throw new \Exception(
            "API request failed with status: {$status}"
        );
    }
}