<?php

// src/Auth/AdSessionGuard.php
namespace LaravelAdRbac\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use LaravelAdRbac\Services\AdAuthService;
use LaravelAdRbac\Models\Employee;

class AdSessionGuard extends SessionGuard
{
    /**
     * The AD authentication service
     */
    protected AdAuthService $adAuthService;

    /**
     * Create a new authentication guard.
     */
    public function __construct(
        string $name,
        UserProvider $provider,
        Session $session,
        Request $request = null,
        AdAuthService $adAuthService = null
    ) {
        parent::__construct($name, $provider, $session, $request);

        $this->adAuthService = $adAuthService ?? app(AdAuthService::class);
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     */
    public function attempt(array $credentials = [], $remember = false): bool
    {
        $username = $credentials['username'] ?? $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!$username || !$password) {
            return false;
        }

        // Step 1: Validate against Active Directory
        $isAdValid = $this->adAuthService->authenticate($username, $password);

        if (!$isAdValid) {
            $this->fireFailedEvent($username, $credentials);
            return false;
        }

        // Step 2: Get or create employee from API
        $employee = $this->getEmployeeFromAdUsername($username);

        if (!$employee || !$employee->is_active) {
            $this->fireFailedEvent($username, $credentials);
            return false;
        }

        // Step 3: Log in the employee
        $this->login($employee, $remember);

        // Step 4: Update last login timestamp
        $employee->update(['last_login_at' => now()]);

        $this->fireAuthenticatedEvent($employee);

        return true;
    }

    /**
     * Get employee by AD username
     */
    protected function getEmployeeFromAdUsername(string $username): ?Employee
    {
        $employeeClass = config('ad-rbac.models.employee');

        return $employeeClass::where('ad_username', $username)->first();
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return $this->attempt($credentials, false);
    }

    /**
     * Log a user into the application without sessions or cookies.
     */
    public function once(array $credentials = []): bool
    {
        $username = $credentials['username'] ?? $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!$username || !$password) {
            return false;
        }

        // Validate against AD
        if (!$this->adAuthService->authenticate($username, $password)) {
            return false;
        }

        // Get employee
        $employee = $this->getEmployeeFromAdUsername($username);

        if (!$employee || !$employee->is_active) {
            return false;
        }

        $this->setUser($employee);

        return true;
    }

    /**
     * Log the user out of the application.
     */
    public function logout(): void
    {
        $user = $this->user();

        parent::logout();

        // Fire custom logout event
        if ($user instanceof Employee) {
            event(new \LaravelAdRbac\Events\EmployeeLoggedOut($user));
        }
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        if ($this->loggedOut) {
            return null;
        }

        // If we've already retrieved the user for the current request we can just
        // return it back immediately. We do not want to fetch the user data on
        // every call to this method because that would be tremendously slow.
        if (!is_null($this->user)) {
            return $this->user;
        }

        $id = $this->session->get($this->getName());

        // First we will try to load the user using the identifier in the session if
        // one exists. Otherwise we will check for a "remember me" cookie in this
        // request, and if one exists, attempt to retrieve the user using that.
        if (!is_null($id)) {
            if ($this->user = $this->provider->retrieveById($id)) {
                $this->fireAuthenticatedEvent($this->user);
            }
        }

        // If the user is null, but we decrypt a "recaller" cookie we can attempt to
        // pull the user data on that cookie which serves as a remember cookie on
        // the application. Once we have a user we can return it to the caller.
        $recaller = $this->recaller();

        if (is_null($this->user) && !is_null($recaller)) {
            $this->user = $this->userFromRecaller($recaller);

            if ($this->user) {
                $this->updateSession($this->user->getAuthIdentifier());

                $this->fireLoginEvent($this->user, true);
            }
        }

        return $this->user;
    }

    /**
     * Handle synchronization of AD data on login
     */
    protected function syncAdData(Employee $employee): void
    {
        // Check if AD data needs sync (older than configured interval)
        $syncInterval = config('ad-rbac.ad.sync_interval', 86400); // Default: 24 hours

        if (
            !$employee->ad_sync_at ||
            $employee->ad_sync_at->diffInSeconds(now()) > $syncInterval
        ) {

            // Trigger async sync job
            \LaravelAdRbac\Jobs\SyncEmployeeData::dispatch($employee);
        }
    }
}