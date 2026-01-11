<?php

// src/Services/Implementations/RestEmployeeApiService.php
namespace LaravelAdRbac\Services\Implementations;

use LaravelAdRbac\Services\EmployeeApiService;
use Illuminate\Support\Facades\Log;

class RestEmployeeApiService extends EmployeeApiService
{
    /**
     * Fetch employee from REST API
     */
    protected $config;
    protected $makeRequest;

    public function __construct()
    {
        $this->config = config('ad-rbac.employee_api');
        parent::__construct();
    }
    protected function fetchEmployeeFromApi(string $username): ?array
    {
        // Get endpoint from config, default to '/employee/{username}'
        $endpointTemplate = $this->config['endpoints']['employee_by_username'] ?? '/employee/{username}';
        $endpoint = str_replace('{username}', $username, $endpointTemplate);

        Log::debug('Fetching employee from API', [
            'username' => $username,
            'endpoint' => $endpoint,
        ]);

        $response = $this->makeRequest('get', $endpoint);

        if (!$response) {
            Log::warning('No response received from employee API', ['username' => $username]);
            return null;
        }

        // Log::debug('Employee API response received', [
        //     'username' => $username,
        //     'response_keys' => $response['data'],
        // ]);

        return $this->normalizeEmployeeData($response);
    }

    /**
     * Fetch multiple employees from REST API
     */
    protected function fetchEmployeesFromApi(array $criteria, int $page, int $perPage): array
    {
        $endpoint = $this->config['endpoints']['employees'] ?? '/employees';

        $params = array_merge($criteria, [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $response = $this->makeRequest('get', $endpoint, $params);

        if (!$response) {
            return ['data' => [], 'meta' => []];
        }

        // Normalize data
        $data = $response['data'] ?? $response;
        $normalized = array_map([$this, 'normalizeEmployeeData'], $data);

        return [
            'data' => $normalized,
            'meta' => $response['meta'] ?? [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => count($normalized),
            ]
        ];
    }

    /**
     * Search employees in REST API
     */
    protected function searchEmployeesFromApi(string $query, array $fields): array
    {
        $endpoint = $this->config['endpoints']['search'] ?? '/employees/search';

        $params = ['q' => $query];
        if (!empty($fields)) {
            $params['fields'] = implode(',', $fields);
        }

        $response = $this->makeRequest('get', $endpoint, $params);

        if (!$response) {
            return [];
        }

        $data = $response['data'] ?? $response;
        return array_map([$this, 'normalizeEmployeeData'], $data);
    }

    /**
     * Get employee by ID from REST API
     */
    protected function getEmployeeByIdFromApi(string $employeeId): ?array
    {
        $endpoint = $this->config['endpoints']['employee_by_id'] ?? '/employees/{id}';
        $endpoint = str_replace('{id}', $employeeId, $endpoint);

        $response = $this->makeRequest('get', $endpoint);

        if (!$response) {
            return null;
        }

        return $this->normalizeEmployeeData($response);
    }

    /**
     * Get departments from REST API
     */
    protected function getDepartmentsFromApi(): array
    {
        $endpoint = $this->config['endpoints']['departments'] ?? '/departments';

        $response = $this->makeRequest('get', $endpoint);

        if (!$response) {
            return [];
        }

        return $response['data'] ?? $response;
    }

    /**
     * Get zones from REST API
     */
    protected function getZonesFromApi(): array
    {
        $endpoint = $this->config['endpoints']['zones'] ?? '/zones';

        $response = $this->makeRequest('get', $endpoint);

        if (!$response) {
            return [];
        }

        return $response['data'] ?? $response;
    }

    /**
     * Get positions from REST API
     */
    protected function getPositionsFromApi(): array
    {
        $endpoint = $this->config['endpoints']['positions'] ?? '/positions';

        $response = $this->makeRequest('get', $endpoint);

        if (!$response) {
            return [];
        }

        return $response['data'] ?? $response;
    }

    /**
     * Normalize employee data from API to our format
     */
    protected function normalizeEmployeeData(array $apiData): array
    {
        // Extract data from response (handles both direct data and nested 'data' key)
        $data = $apiData['data'] ?? $apiData;

        // Extract nested objects
        $adUser = $data['ad_user'] ?? [];
        $department = $data['department'] ?? [];
        $position = $data['position'] ?? [];
        $reportsTo = $data['reports_to'] ?? null;

        // Build full name safely
        $firstName = $data['first_name'] ?? '';
        $middleName = $data['middle_name'] ?? '';
        $lastName = $data['last_name'] ?? '';

        $fullName = trim(implode(' ', array_filter([
            $firstName,
            $middleName,
            $lastName
        ])));

        // Build manager name if exists
        $managerName = null;
        if ($reportsTo) {
            $managerFirstName = $reportsTo['first_name'] ?? '';
            $managerLastName = $reportsTo['last_name'] ?? '';
            $managerFullName = trim($managerFirstName . ' ' . $managerLastName);
            $managerName = !empty($managerFullName) ? $managerFullName : null;
        }

        // Get job level and cadre from position
        $jobLevel = $position['job_level'] ?? [];
        $cadre = $position['cadre'] ?? [];

        return [
            // Basic employee info
            'id' => $data['id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'username' => $data['username'] ?? ($adUser['sam_account_name'] ?? null),

            // Name fields
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'display_name' => $adUser['display_name'] ?? $fullName,

            // Contact info
            'email' => $data['personal_email'] ?? ($adUser['email'] ?? null),
            'personal_email' => $data['personal_email'] ?? null,
            'work_email' => $adUser['email'] ?? null,
            'phone' => $data['personal_phone'] ?? ($adUser['telephone_number'] ?? null),
            'mobile' => $adUser['mobile'] ?? null,

            // AD/LDAP info
            'ad_username' => $adUser['sam_account_name'] ?? null,
            // 'user_principal_name' => $adUser['user_principal_name'] ?? null,
            // 'distinguished_name' => $adUser['distinguished_name'] ?? null,
            'employee_number' => $adUser['employee_id'] ?? null,

            // Department info
            'department' => $department['name'] ?? null,
            'department_code' => $department['code'] ?? null,
            'department_id' => $department['id'] ?? null,

            // Position/Job info
            'position' => $position['title'] ?? null,
            'position_code' => $position['code'] ?? null,
            'position_id' => $position['id'] ?? null,
            'job_title' => $position['title'] ?? null,
            'job_level' => $jobLevel['name'] ?? null,
            'job_level_code' => $jobLevel['code'] ?? null,
            'cadre' => $cadre['name'] ?? null,
            'cadre_code' => $cadre['code'] ?? null,

            // Manager/Reporting info
            'manager_id' => $reportsTo ? ($reportsTo['id'] ?? null) : null,
            'manager_name' => $managerName,
            'reports_to' => $reportsTo,

            // Employment details
            // 'employment_status' => $data['employment_status'] ?? null,
            // 'hire_date' => $data['hire_date'] ?? null,
            // 'termination_date' => $data['termination_date'] ?? null,
            // 'is_active' => $data['employment_status'] === 'active',

            // Personal details
            'gender' => $data['gender'] ?? null,
            'signature' => $data['signature'] ?? null,

            // Location/Address
            // 'location' => null, // Not in provided data
            // 'street_address' => $adUser['street_address'] ?? null,
            // 'city' => $adUser['city'] ?? null,
            // 'state' => $adUser['state'] ?? null,
            // 'postal_code' => $adUser['postal_code'] ?? null,
            // 'country' => $adUser['country'] ?? null,

            // Company info
            // 'company' => $adUser['company'] ?? null,
            // 'cost_center' => null, // Not in provided data
            // 'division' => null, // Not in provided data

            // AD Account status
            // 'account_enabled' => $adUser['account_enabled'] ?? true,
            // 'password_never_expires' => $adUser['password_never_expires'] ?? false,
            // 'cannot_change_password' => $adUser['cannot_change_password'] ?? false,
            // 'last_logon' => $adUser['last_logon'] ?? null,
            // 'lockout_time' => $adUser['lockout_time'] ?? null,

            // Additional metadata
            // 'member_of' => $adUser['member_of'] ?? null,
            // 'description' => $adUser['description'] ?? null,
            // 'direct_reports' => $data['direct_reports'] ?? [],

            // For reference
            'raw_data' => $data,
        ];
    }
}