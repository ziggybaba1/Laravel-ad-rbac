<?php
// src/Contracts/EmployeeApiInterface.php
namespace LaravelAdRbac\Contracts;

interface EmployeeApiInterface
{
    /**
     * Fetch employee data from external API
     */
    public function fetchEmployeeData(string $username): ?array;

    /**
     * Fetch multiple employees by criteria
     */
    public function fetchEmployees(array $criteria = [], int $page = 1, int $perPage = 50): array;

    /**
     * Search employees by various fields
     */
    public function searchEmployees(string $query, array $fields = []): array;

    /**
     * Get employee by employee ID
     */
    public function getEmployeeById(string $employeeId): ?array;

    /**
     * Get department structure
     */
    public function getDepartments(): array;

    /**
     * Get positions/job titles
     */
    public function getPositions(): array;

    /**
     * Validate API connection
     */
    public function validateConnection(): bool;

    /**
     * Get API status/health
     */
    public function getApiStatus(): array;
}