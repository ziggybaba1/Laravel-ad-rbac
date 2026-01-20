<?php

namespace LaravelAdRbac\Services;

use LaravelAdRbac\Contracts\AdAuthInterface;
use LaravelAdRbac\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdAuthService implements AdAuthInterface
{
    protected $ldapConnection = null;
    protected $config;
    protected $lastError = null;
    protected $enabled = true;

    public function __construct()
    {
        $this->config = config('ad-rbac.ad', []);
        $this->enabled = $this->config['enabled'] ?? true;
    }

    public function authenticate(string $username, string $password): bool
    {
        // 1. Validate against AD
        $adValid = $this->validateAdCredentials($username, $password);

        if (!$adValid) {
            return false;
        }

        // 2. Sync employee data from external API
        $employeeData = $this->syncEmployeeData($username);

        if (!$employeeData) {
            return false;
        }

        // 3. Update or create employee record
        $employee = $this->updateOrCreateEmployee($username, $employeeData);

        return $employee !== null;
    }

    protected function validateAdCredentials(string $username, string $password): bool
    {
        // LDAP implementation
        $config = config('ad-rbac.ad');

        try {
            $connection = $this->connectToLdap();

            if (!$connection) {
                $this->lastError = 'Cannot connect to LDAP server';
                return false;
            }

            // Format username for AD binding
            $username = $this->formatUsername($username);

            // Attempt to bind with user credentials
            $bind = @ldap_bind($connection, $username, $password);

            if (!$bind) {
                $this->lastError = ldap_error($connection);
                Log::warning('AD bind failed', [
                    'username' => $username,
                    'error' => $this->lastError,
                    'code' => ldap_errno($connection),
                ]);

                ldap_unbind($connection);
                return false;
            }

            ldap_unbind($connection);
            return true;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('AD Authentication failed', ['error' => $this->lastError]);
            return false;
        }
    }

    // protected function validateAdCredentials(string $username, string $password): bool
    // {
    //     $config = [
    //         'server' => '127.0.0.1',
    //         'port' => 389,
    //         'base_dn' => 'DC=corporate,DC=local',
    //         'domain' => 'corporate.local',
    //     ];

    //     // Now it will connect to your mock LDAP server
    //     return parent::validateAdCredentials($username, $password);
    // }

    public function syncEmployeeData(string $username): ?array
    {
        $cacheKey = "employee_data_{$username}";

        return Cache::remember($cacheKey, config('ad-rbac.employee_api.cache_ttl'), function () use ($username) {
            $apiService = app(EmployeeApiService::class);
            return $apiService->fetchEmployeeData($username);
        });
    }

    public function updateOrCreateEmployee(string $username, array $data): ?Employee
    {
        $employeeModel = config('ad-rbac.models.employee');
        $employee = $employeeModel::where('ad_username', $username)->first();
        if ($employee) {
            $employee->update([
                'employee_id' => $data['id'] ?? null,
                'email' => $data['email'] ?? null,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'department' => $data['department'] ?? null,
                'position' => $data['position'] ?? null,
                'ad_sync_at' => now(),
            ]);
            return $employee;
        }
        return null;
    }

    // Interface Methods Implementation

    public function validateConfiguration(): array
    {
        $errors = [];
        $config = $this->config;

        // Check required configuration
        if (empty($config['server'])) {
            $errors[] = 'AD server is not configured';
        }

        if (empty($config['base_dn']) && empty($config['domain'])) {
            $errors[] = 'Either base_dn or domain must be configured';
        }

        // Test connection if server is configured
        if (!empty($config['server'])) {
            if (!$this->testConnection()) {
                $errors[] = 'Cannot connect to AD server: ' . ($this->lastError ?? 'Unknown error');
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'config' => [
                'server' => $config['server'] ?? '',
                'port' => $config['port'] ?? 389,
                'use_ssl' => $config['use_ssl'] ?? false,
                'use_tls' => $config['use_tls'] ?? false,
                'base_dn' => $config['base_dn'] ?? null,
                'domain' => $config['domain'] ?? null,
                'enabled' => $config['enabled'] ?? true,
                'timeout' => $config['timeout'] ?? 5,
            ],
        ];
    }

    public function searchUser(string $username, array $attributes = []): ?array
    {
        try {
            $connection = $this->connectToLdap();
            if (!$connection) {
                return null;
            }

            // Build search filter
            $searchFilter = $this->buildUserSearchFilter($username);

            // Default attributes if none specified
            if (empty($attributes)) {
                $attributes = ['*', 'memberOf'];
            }

            $search = ldap_search(
                $connection,
                $this->config['base_dn'],
                $searchFilter,
                $attributes
            );

            if (!$search) {
                $this->lastError = ldap_error($connection);
                ldap_unbind($connection);
                return null;
            }

            $entries = ldap_get_entries($connection, $search);
            ldap_unbind($connection);

            if ($entries['count'] > 0) {
                return $this->normalizeLdapEntry($entries[0]);
            }

            return null;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('AD search failed', ['username' => $username, 'error' => $this->lastError]);
            return null;
        }
    }

    public function getUserGroups(string $username): array
    {
        $cacheKey = "ad_user_groups_{$username}";

        return Cache::remember($cacheKey, 3600, function () use ($username) {
            try {
                $userData = $this->searchUser($username, ['memberOf']);

                if (!$userData || !isset($userData['memberof'])) {
                    return [];
                }

                $groups = is_array($userData['memberof'])
                    ? $userData['memberof']
                    : [$userData['memberof']];

                return $groups;

            } catch (\Exception $e) {
                Log::error('Failed to get user groups', ['username' => $username]);
                return [];
            }
        });
    }

    public function userInGroups(string $username, array $groupDNs): bool
    {
        if (empty($groupDNs)) {
            return true; // No group restrictions
        }

        $userGroups = $this->getUserGroups($username);

        foreach ($groupDNs as $groupDN) {
            if (in_array($groupDN, $userGroups)) {
                return true;
            }
        }

        return false;
    }

    public function getUserDetails(string $username): array
    {
        $cacheKey = "ad_user_details_{$username}";

        return Cache::remember($cacheKey, 1800, function () use ($username) {
            try {
                $attributes = [
                    'cn',
                    'givenName',
                    'sn',
                    'displayName',
                    'mail',
                    'sAMAccountName',
                    'userPrincipalName',
                    'department',
                    'title',
                    'telephoneNumber',
                    'mobile',
                    'streetAddress',
                    'l',
                    'st',
                    'postalCode',
                    'co',
                    'userAccountControl',
                    'lastLogonTimestamp',
                    'whenCreated',
                    'whenChanged',
                    'manager',
                    'memberOf',
                    'distinguishedName'
                ];

                $userData = $this->searchUser($username, $attributes);

                if (!$userData) {
                    return ['error' => 'User not found in AD'];
                }

                // Parse userAccountControl
                $userAccountControl = $userData['useraccountcontrol'] ?? 0;
                $isDisabled = ($userAccountControl & 2) == 2;
                $isLocked = ($userAccountControl & 16) == 16;

                // Parse last logon timestamp
                $lastLogon = null;
                if (isset($userData['lastlogontimestamp'])) {
                    $lastLogon = $this->convertAdTimestamp($userData['lastlogontimestamp']);
                }

                return [
                    'username' => $userData['samaccountname'] ?? $username,
                    'email' => $userData['mail'] ?? null,
                    'first_name' => $userData['givenname'] ?? null,
                    'last_name' => $userData['sn'] ?? null,
                    'full_name' => $userData['displayname'] ?? $userData['cn'] ?? null,
                    'department' => $userData['department'] ?? null,
                    'title' => $userData['title'] ?? null,
                    'phone' => $userData['telephonenumber'] ?? null,
                    'mobile' => $userData['mobile'] ?? null,
                    'address' => [
                        'street' => $userData['streetaddress'] ?? null,
                        'city' => $userData['l'] ?? null,
                        'state' => $userData['st'] ?? null,
                        'postal_code' => $userData['postalcode'] ?? null,
                        'country' => $userData['co'] ?? null,
                    ],
                    'account_status' => [
                        'disabled' => $isDisabled,
                        'locked' => $isLocked,
                        'last_logon' => $lastLogon,
                        'created' => $userData['whencreated'] ?? null,
                        'modified' => $userData['whenchanged'] ?? null,
                    ],
                    'manager' => $userData['manager'] ?? null,
                    'distinguished_name' => $userData['distinguishedname'] ?? null,
                    'groups' => $this->getUserGroups($username),
                    'raw_data' => $userData,
                ];

            } catch (\Exception $e) {
                Log::error('Failed to get user details', ['username' => $username]);
                return ['error' => $e->getMessage()];
            }
        });
    }

    public function changePassword(string $username, string $oldPassword, string $newPassword): bool
    {
        try {
            $connection = $this->connectToLdap();
            if (!$connection) {
                return false;
            }

            // First, bind with user's current credentials
            $userDn = $this->getUserDN($username);
            if (!$userDn) {
                $this->lastError = 'User not found';
                return false;
            }

            // Bind with old password
            if (!@ldap_bind($connection, $userDn, $oldPassword)) {
                $this->lastError = 'Current password is incorrect';
                ldap_unbind($connection);
                return false;
            }

            // Prepare password change
            $encodedPassword = $this->encodePassword($newPassword);
            $entry = ['unicodePwd' => $encodedPassword];

            // Attempt to modify password
            if (!ldap_mod_replace($connection, $userDn, $entry)) {
                $this->lastError = ldap_error($connection);
                ldap_unbind($connection);
                return false;
            }

            ldap_unbind($connection);

            // Clear user cache
            $this->clearUserCache($username);

            return true;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('Password change failed', ['username' => $username, 'error' => $this->lastError]);
            return false;
        }
    }

    public function unlockAccount(string $username): bool
    {
        try {
            $connection = $this->connectToLdap();
            if (!$connection) {
                return false;
            }

            // Bind with admin credentials
            if (!$this->bindWithAdmin($connection)) {
                $this->lastError = 'Cannot bind with admin credentials';
                ldap_unbind($connection);
                return false;
            }

            $userDn = $this->getUserDN($username);
            if (!$userDn) {
                $this->lastError = 'User not found';
                ldap_unbind($connection);
                return false;
            }

            // Set lockoutTime to 0 to unlock account
            $entry = ['lockoutTime' => 0];

            if (!ldap_mod_replace($connection, $userDn, $entry)) {
                $this->lastError = ldap_error($connection);
                ldap_unbind($connection);
                return false;
            }

            ldap_unbind($connection);

            // Clear user cache
            $this->clearUserCache($username);

            return true;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('Account unlock failed', ['username' => $username, 'error' => $this->lastError]);
            return false;
        }
    }

    public function isAccountLocked(string $username): bool
    {
        try {
            $userDetails = $this->getUserDetails($username);

            if (isset($userDetails['account_status']['locked'])) {
                return $userDetails['account_status']['locked'];
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Failed to check account lock status', ['username' => $username]);
            return false;
        }
    }

    public function isAccountDisabled(string $username): bool
    {
        try {
            $userDetails = $this->getUserDetails($username);

            if (isset($userDetails['account_status']['disabled'])) {
                return $userDetails['account_status']['disabled'];
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Failed to check account disable status', ['username' => $username]);
            return false;
        }
    }

    public function searchUsers(array $filters = [], array $attributes = [], int $limit = 100): array
    {
        try {
            $connection = $this->connectToLdap();
            if (!$connection) {
                return [];
            }

            // Build search filter
            $searchFilter = '(&(objectClass=user)(objectCategory=person)';

            foreach ($filters as $key => $value) {
                if (!empty($value)) {
                    $searchFilter .= "({$key}={$value})";
                }
            }

            $searchFilter .= ')';

            // Default attributes if none specified
            if (empty($attributes)) {
                $attributes = ['cn', 'sAMAccountName', 'mail', 'department', 'title'];
            }

            $search = ldap_search(
                $connection,
                $this->config['base_dn'],
                $searchFilter,
                $attributes
            );

            if (!$search) {
                $this->lastError = ldap_error($connection);
                ldap_unbind($connection);
                return [];
            }

            $entries = ldap_get_entries($connection, $search);
            ldap_unbind($connection);

            $users = [];
            $count = min($entries['count'], $limit);

            for ($i = 0; $i < $count; $i++) {
                $users[] = $this->normalizeLdapEntry($entries[$i]);
            }

            return $users;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('User search failed', ['error' => $this->lastError]);
            return [];
        }
    }

    public function getGroups(string $searchTerm = '', int $limit = 100): array
    {
        try {
            $connection = $this->connectToLdap();
            if (!$connection) {
                return [];
            }

            // Build search filter
            $searchFilter = '(objectClass=group)';
            if (!empty($searchTerm)) {
                $searchFilter = "(&(objectClass=group)(|(cn=*{$searchTerm}*)(name=*{$searchTerm}*)))";
            }

            $attributes = ['cn', 'name', 'description', 'distinguishedName', 'member'];

            $search = ldap_search(
                $connection,
                $this->config['base_dn'],
                $searchFilter,
                $attributes
            );

            if (!$search) {
                $this->lastError = ldap_error($connection);
                ldap_unbind($connection);
                return [];
            }

            $entries = ldap_get_entries($connection, $search);
            ldap_unbind($connection);

            $groups = [];
            $count = min($entries['count'], $limit);

            for ($i = 0; $i < $count; $i++) {
                $group = $this->normalizeLdapEntry($entries[$i]);

                // Get member count
                $memberCount = 0;
                if (isset($group['member']) && is_array($group['member'])) {
                    $memberCount = count($group['member']);
                } elseif (isset($group['member'])) {
                    $memberCount = 1;
                }

                $groups[] = [
                    'name' => $group['cn'] ?? $group['name'] ?? '',
                    'description' => $group['description'] ?? '',
                    'distinguished_name' => $group['distinguishedname'] ?? '',
                    'member_count' => $memberCount,
                    'raw_data' => $group,
                ];
            }

            return $groups;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('Group search failed', ['error' => $this->lastError]);
            return [];
        }
    }

    public function verifyCredentials(string $username, string $password): bool
    {
        // This is a lightweight version of authenticate that doesn't sync data
        return $this->validateAdCredentials($username, $password);
    }

    public function getUserDN(string $username): ?string
    {
        try {
            $userData = $this->searchUser($username, ['distinguishedName']);
            return $userData['distinguishedname'] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to get user DN', ['username' => $username]);
            return null;
        }
    }

    public function testConnection(): bool
    {
        try {
            $connection = $this->connectToLdap();

            if (!$connection) {
                return false;
            }

            // Test bind with anonymous or admin credentials
            if (!empty($this->config['admin_username']) && !empty($this->config['admin_password'])) {
                $adminDn = $this->formatUsername($this->config['admin_username']);
                if (!@ldap_bind($connection, $adminDn, $this->config['admin_password'])) {
                    $this->lastError = 'Admin bind failed: ' . ldap_error($connection);
                    return false;
                }
            } else {
                // Anonymous bind
                if (!@ldap_bind($connection)) {
                    $this->lastError = 'Anonymous bind failed: ' . ldap_error($connection);
                    return false;
                }
            }

            ldap_unbind($connection);
            return true;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function getServerStatus(): array
    {
        $startTime = microtime(true);

        $connectionTest = $this->testConnection();
        $configTest = $this->validateConfiguration();

        $responseTime = round((microtime(true) - $startTime) * 1000, 2); // ms

        return [
            'connected' => $connectionTest,
            'configuration_valid' => $configTest['valid'],
            'response_time_ms' => $responseTime,
            'last_error' => $this->lastError,
            'enabled' => $this->isEnabled(),
            'server' => $this->config['server'] ?? null,
            'port' => $this->config['port'] ?? 389,
            'timestamp' => now()->toISOString(),
        ];
    }

    public function clearUserCache(string $username): bool
    {
        try {
            $cacheKeys = [
                "employee_data_{$username}",
                "ad_user_groups_{$username}",
                "ad_user_details_{$username}",
            ];

            foreach ($cacheKeys as $key) {
                Cache::forget($key);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to clear user cache', ['username' => $username]);
            return false;
        }
    }

    public function clearAllCache(): bool
    {
        try {
            // Note: This implementation depends on the cache driver
            // For Redis, we can use pattern matching
            if (config('cache.default') === 'redis') {
                $redis = Cache::getRedis();
                $keys = $redis->keys('*employee_data_*');
                $keys = array_merge($keys, $redis->keys('*ad_user_*'));

                foreach ($keys as $key) {
                    $redis->del($key);
                }

                return true;
            }

            // For other cache drivers, we can't easily clear by pattern
            // Users should implement their own cache clearing strategy
            Log::warning('Cannot clear all AD cache with current cache driver');
            return false;

        } catch (\Exception $e) {
            Log::error('Failed to clear all cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;

        // Update config if needed
        if ($this->config['enabled'] !== $enabled) {
            $this->config['enabled'] = $enabled;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && ($this->config['enabled'] ?? true);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // Helper Methods

    protected function connectToLdap()
    {
        try {
            $server = $this->config['server'];
            $port = $this->config['port'] ?? 389;

            // Check if using SSL
            // if ($this->config['use_ssl'] ?? false) {
            //     $server = "ldaps://{$server}";
            // }

            $connection = ldap_connect($server, $port);
            if (!$connection) {
                $this->lastError = "Cannot connect to LDAP server: {$server}:{$port}";
                return null;
            }

            // Set LDAP options
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $this->config['timeout'] ?? 5);
            ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $this->config['timeout'] ?? 5);
            ldap_set_option($connection, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);

            // Start TLS if required
            if ($this->config['use_tls'] ?? false) {

                // if (!ldap_start_tls($connection)) {
                //     $this->lastError = 'Cannot start TLS connection';
                //     return null;
                // }
            }
            return $connection;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    protected function formatUsername(string $username): string
    {
        // If username already contains @, assume it's UPN
        if (Str::contains($username, '@')) {
            return $username;
        }

        // If domain is specified
        if (!empty($this->config['domain'])) {
            return "{$username}@{$this->config['domain']}";
        }

        // If base DN is specified, try to find DN
        if (!empty($this->config['base_dn'])) {
            $userData = $this->searchUser($username, ['distinguishedName']);
            return $userData['distinguishedname'] ?? "CN={$username},{$this->config['base_dn']}";
        }

        return $username;
    }

    protected function buildUserSearchFilter(string $username): string
    {
        if (Str::contains($username, '@')) {
            // User Principal Name (UPN)
            return "(userPrincipalName={$username})";
        } elseif (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            // Email address
            return "(mail={$username})";
        } else {
            // sAMAccountName
            return "(sAMAccountName={$username})";
        }
    }

    protected function normalizeLdapEntry(array $entry): array
    {
        $result = [];

        foreach ($entry as $key => $value) {
            if (is_int($key) || $key === 'count') {
                continue;
            }

            if (is_array($value) && isset($value['count'])) {
                if ($value['count'] == 1) {
                    $result[$key] = $value[0];
                } else {
                    $result[$key] = array_slice($value, 0, $value['count']);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    protected function convertAdTimestamp(string $adTimestamp): ?string
    {
        // AD timestamp is the number of 100-nanosecond intervals since January 1, 1601 (UTC)
        try {
            $timestamp = (int) $adTimestamp;
            $seconds = $timestamp / 10000000;
            $unixTimestamp = $seconds - 11644473600; // Seconds from 1601 to 1970

            return date('Y-m-d H:i:s', $unixTimestamp);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function encodePassword(string $password): string
    {
        // AD requires password to be encoded in UTF-16LE and surrounded by quotes
        $password = "\"{$password}\"";
        return mb_convert_encoding($password, 'UTF-16LE', 'UTF-8');
    }

    protected function bindWithAdmin($connection): bool
    {
        if (empty($this->config['admin_username']) || empty($this->config['admin_password'])) {
            return false;
        }

        $adminDn = $this->formatUsername($this->config['admin_username']);
        return @ldap_bind($connection, $adminDn, $this->config['admin_password']);
    }
}