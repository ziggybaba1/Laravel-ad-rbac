<?php

// src/Contracts/AdAuthInterface.php
namespace LaravelAdRbac\Contracts;

use App\Models\Employee;

interface AdAuthInterface
{
    /**
     * Authenticate a user against Active Directory
     *
     * @param string $username The AD username (can be sAMAccountName, UPN, or email)
     * @param string $password The user's password
     * @return bool Returns true if authentication is successful
     */
    public function authenticate(string $username, string $password): bool;

    /**
     * Validate AD configuration and test connection
     *
     * @return array Returns validation results including:
     *               - 'valid': boolean indicating if configuration is valid
     *               - 'errors': array of error messages if any
     *               - 'config': array of current configuration
     */
    public function validateConfiguration(): array;

    /**
     * Search for a user in Active Directory
     *
     * @param string $username The username to search for
     * @param array $attributes Specific LDAP attributes to retrieve
     * @return array|null Returns user data if found, null otherwise
     */
    public function syncEmployeeData(string $username): ?array;

    /**
     * Get user's groups from Active Directory
     *
     * @param string $username The username to get groups for
     * @return array Returns array of group distinguished names (DNs)
     */
    // public function updateOrCreateEmployee(string $username, array $data);

    /**
     * Check if user is a member of specific AD groups
     *
     * @param string $username The username to check
     * @param array $groupDNs Array of group distinguished names to check against
     * @return bool Returns true if user is a member of any specified group
     */
    public function userInGroups(string $username, array $groupDNs): bool;

    /**
     * Get comprehensive user details from Active Directory
     *
     * @param string $username The username to get details for
     * @return array Returns user details including:
     *               - Basic info (name, email, department)
     *               - Account status (enabled, locked, etc.)
     *               - Group membership
     *               - Last logon timestamp
     */
    public function getUserDetails(string $username): array;

    /**
     * Change user password in Active Directory
     * Note: Requires special permissions and may need SSL/TLS
     *
     * @param string $username The username
     * @param string $oldPassword The current password
     * @param string $newPassword The new password
     * @return bool Returns true if password change was successful
     * @throws \LaravelAdRbac\Exceptions\AdOperationException If operation fails
     */
    public function changePassword(string $username, string $oldPassword, string $newPassword): bool;

    /**
     * Unlock a user account in Active Directory
     * Note: Requires account operator permissions
     *
     * @param string $username The username to unlock
     * @return bool Returns true if account was unlocked successfully
     */
    public function unlockAccount(string $username): bool;

    /**
     * Check if a user account is locked in Active Directory
     *
     * @param string $username The username to check
     * @return bool Returns true if account is locked
     */
    public function isAccountLocked(string $username): bool;

    /**
     * Check if a user account is disabled in Active Directory
     *
     * @param string $username The username to check
     * @return bool Returns true if account is disabled
     */
    public function isAccountDisabled(string $username): bool;

    /**
     * Search for users in Active Directory with filters
     *
     * @param array $filters LDAP search filters
     * @param array $attributes Attributes to retrieve
     * @param int $limit Maximum number of results
     * @return array Returns array of user data
     */
    public function searchUsers(array $filters = [], array $attributes = [], int $limit = 100): array;

    /**
     * Get all groups from Active Directory
     *
     * @param string $searchTerm Optional search term for group names
     * @param int $limit Maximum number of results
     * @return array Returns array of group data
     */
    public function getGroups(string $searchTerm = '', int $limit = 100): array;

    /**
     * Verify credentials without creating a session
     * This is a lightweight authentication check
     *
     * @param string $username The username
     * @param string $password The password
     * @return bool Returns true if credentials are valid
     */
    public function verifyCredentials(string $username, string $password): bool;

    /**
     * Get the user's distinguished name (DN)
     *
     * @param string $username The username
     * @return string|null Returns the distinguished name or null if not found
     */
    public function getUserDN(string $username): ?string;

    /**
     * Test connection to Active Directory server
     *
     * @return bool Returns true if connection is successful
     */
    public function testConnection(): bool;

    /**
     * Get AD server status and statistics
     *
     * @return array Returns server status information
     */
    public function getServerStatus(): array;

    /**
     * Clear cached AD data for a specific user
     *
     * @param string $username The username
     * @return bool Returns true if cache was cleared
     */
    public function clearUserCache(string $username): bool;

    /**
     * Clear all cached AD data
     *
     * @return bool Returns true if cache was cleared
     */
    public function clearAllCache(): bool;

    /**
     * Enable or disable AD authentication
     *
     * @param bool $enabled Set to true to enable, false to disable
     * @return void
     */
    public function setEnabled(bool $enabled): void;

    /**
     * Check if AD authentication is enabled
     *
     * @return bool Returns true if AD authentication is enabled
     */
    public function isEnabled(): bool;

    /**
     * Get last error message
     *
     * @return string|null Returns the last error message or null if no error
     */
    public function getLastError(): ?string;
}