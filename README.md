# Laravel AD RBAC
A comprehensive Laravel package for Active Directory (AD) integration with Role-Based Access Control (RBAC). This package provides seamless authentication with AD/LDAP servers and robust permission management for Laravel applications.

Features
- AD/LDAP Authentication - Authenticate users against Active Directory/LDAP servers

- Role-Based Access Control - Flexible role and permission management

- Employee Management - Sync and manage employee data from AD

- Permission Management - Granular control over system permissions

- Easy Integration - Drop-in solution for existing Laravel applications

- Real-time Sync - Automatic synchronization with AD

- Audit Logging - Comprehensive audit trails for security

## Installation
### Step 1: Add Package Repository
Add the following to your project's composer.json file:

```json
{
     "minimum-stability": "dev",
    "prefer-stable": true,
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "LaravelAdRbac\\": "src/"
        }
    },
   "repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/ziggybaba1/Laravel-ad-rbac.git"
    }
]
}
```

### Step 2: Require the Package
Run the following command to install the package:

```bash
composer require ziggybaba1/laravel-ad-rbac:@dev-main
```
### Step 3: Publish Configuration and Migrations
Run the following command to publish the configuration and migrations:

```bash
php artisan ad-rbac:install 
```
### Step 4: Run Migrations
Run the following command to create an admin user and grant all permissions:

```bash
php artisan ad-rbac:create-admin --grant-all-permissions
```
### Step 5: Configure AD/LDAP Settings
Open the .env file and configure the AD/LDAP settings.

```php
AD_AUTH_ENABLED=true
AD_SERVER= //Get value from Solution Architect
AD_PORT=389
AD_USE_SSL=false
AD_USE_TLS=true
AD_TIMEOUT=5

EMPLOYEE_API_URL=//Get value from Solution Architect
EMPLOYEE_API_SECRET=//Get value from Solution Architect
EMPLOYEE_API_TIMEOUT=30
EMPLOYEE_CACHE_TTL=3000
```
### Step 6: How to use the AD Service in your code (Sample)

```php
namespace App\Actions\Auth;

use Illuminate\Support\Facades\Validator;
use LaravelAdRbac\Services\AdAuthService;
use Illuminate\Support\Facades\Auth;
use LaravelAdRbac\Http\Resources\EmployeeResource;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;

class AdLoginAction
{
    protected $adAuthService;

    public function __construct()
    {
        $this->adAuthService = app(AdAuthService::class);
    }
    public function execute(array $input)
    {

        // Validation
        $validator = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'remember' => ['boolean'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Rate limiting
        $throttleKey = strtolower($input['email']) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        $authenticated = $this->adAuthService->authenticate(
            $input['email'],
            $input['password']
        );

        if (!$authenticated) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'email' => __('Invalid AD credentials'),
            ]);
        }

        // Find user/employee
    $user = config('ad-rbac.models.employee')::where('email', $input['email'])->first();


        if (!$user) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'email' => __('User not found'),
            ]);
        }

        if (isset($user->is_active) && !$user->is_active) {
            throw ValidationException::withMessages([
                'email' => __('Your account is not active'),
            ]);
        }

        // Attempt Laravel authentication with password
        if (
            !Auth::attempt([
                'email' => $input['email'],
                'password' => $input['password'],
            ], $input['remember'] ?? false)
        ) {

            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Clear rate limiter
        RateLimiter::clear($throttleKey);

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Regenerate session
        session()->regenerate();

        return $user;
    }

}
```

### More examples to come
Package is currently undergoing testing

Author: Oluwaseyi Adejugbagbe