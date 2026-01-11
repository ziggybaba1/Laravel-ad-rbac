RbacService usage

use LaravelAdRbac\Services\RbacService;

class YourController extends Controller
{
    protected $rbacService;

    public function __construct(RbacService $rbacService)
    {
        $this->rbacService = $rbacService;
    }

      public function assignPermissionsToRole()
    {
        // Single assignment
        $this->rbacService->assignPermissionToRole(1, 10);

        // Mass assignment
        $this->rbacService->massAssignPermissionsToRole(1, [10, 11, 12, 13]);

        // Multiple roles assignment
        $this->rbacService->assignPermissionsToRoles([
            1 => [10, 11, 12], // Role 1 gets permissions 10, 11, 12
            2 => [10, 13],     // Role 2 gets permissions 10, 13
            3 => 14,           // Role 3 gets permission 14
        ]);
    }

    public function assignRolesToGroup()
    {
        // Single assignment
        $this->rbacService->assignRoleToGroup(1, 5);

        // Mass assignment
        $this->rbacService->massAssignRolesToGroup(1, [5, 6, 7, 8]);

        // Multiple groups assignment
        $this->rbacService->assignRolesToGroups([
            1 => [5, 6, 7], // Group 1 gets roles 5, 6, 7
            2 => [5, 8],    // Group 2 gets roles 5, 8
            3 => 9,         // Group 3 gets role 9
        ]);
    }

    public function bulkOperations()
    {
        // Bulk assign both permissions and roles
        $result = $this->rbacService->bulkAssign([
            'permission_assignments' => [
                1 => [10, 11, 12],
                2 => [10, 13],
            ],
            'role_assignments' => [
                1 => [5, 6],
                2 => [7, 8],
            ]
        ]);

        // Bulk removal
        $result = $this->rbacService->bulkRemove([
            'permission_removals' => [
                1 => [10, 11],
                2 => [13],
            ],
            'role_removals' => [5, 6, 7],
        ]);
    }

    public function getAssignments()
    {
        // Get all permissions for a role
        $permissions = $this->rbacService->getRolePermissions(1);

        // Get all roles in a group
        $roles = $this->rbacService->getGroupRoles(1);

        // Get roles that have a specific permission
        $rolesWithPermission = $this->rbacService->getRolesWithPermission(10);

        // Get statistics
        $stats = $this->rbacService->getAssignmentStats();
    }

    public function syncOperations()
    {
        // Sync all permissions for a role (replace existing)
        $this->rbacService->syncPermissionsForRole(1, [10, 11, 12, 13]);

        // Sync all roles for a group (replace existing)
        $this->rbacService->syncRolesForGroup(1, [5, 6, 7, 8]);
    }

    public function validateAndAssign()
    {
        $data = [
            'permission_assignments' => [
                1 => [10, 11, 12],
                2 => [10, 13],
            ],
            'role_assignments' => [
                1 => [5, 6],
                2 => [7, 8],
            ]
        ];

        // Validate before assignment
        $errors = $this->rbacService->validateBulkAssignment($data);

        if (empty($errors)) {
            $result = $this->rbacService->bulkAssign($data);
            return response()->json(['success' => true, 'result' => $result]);
        } else {
            return response()->json(['success' => false, 'errors' => $errors]);
        }
    }

    public function getPermissions()
    {
        // Get all permissions, paginated, excluding timestamps
        $permissions = $this->rbacService->getAllPermissions(
            ['created_at', 'updated_at', 'deleted_at'], // exclude columns
            15 // per page
        );

        // Get permissions by category
        $userPermissions = $this->rbacService->getPermissionsByCategory('users');

        // Create a permission
        $permission = $this->rbacService->createPermission([
            'name' => 'Create Users',
            'action' => 'create',
            'module' => 'App\Models\User',
            'category' => 'users'
        ]);
    }

    public function getRoles()
    {
        // Get all roles with their groups and permissions, paginated
        $roles = $this->rbacService->getAllRoles([], 15);

        // Create a role with permissions
        $role = $this->rbacService->createRole(
            [
                'name' => 'Administrator',
                'description' => 'Full system access'
            ],
            [1, 2, 3, 4] // permission IDs
        );
    }

    public function getGroups()
    {
        // Get hierarchical group tree
        $groups = $this->rbacService->getGroupTree(['deleted_at']);

        // Get paginated flat list
        $groupsPaginated = $this->rbacService->getAllGroups([], 20);

        // Create a group
        $group = $this->rbacService->createGroup([
            'name' => 'Administration',
            'description' => 'Administrative groups'
        ]);
    }
}



namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAdRbac\Traits\HasAuditLog;

class User extends Model
{
    use HasAuditLog;
    
    protected $fillable = ['name', 'email', 'password'];
}


use LaravelAdRbac\Services\AuditLogService;

class AuditController extends Controller
{
    protected $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['event', 'model_type', 'date_from', 'date_to', 'search']);
        
        $logs = $this->auditLogService->getAllLogs(
            filters: $filters,
            perPage: 25,
            sort: ['created_at', 'desc']
        );
        
        $statistics = $this->auditLogService->getStatistics($filters);
        
        return view('audit.index', compact('logs', 'statistics'));
    }

    public function show($id)
    {
        $log = AuditLog::with(['auditable', 'causer'])->findOrFail($id);
        
        return view('audit.show', compact('log'));
    }

    public function export(Request $request)
    {
        $filters = $request->only(['event', 'model_type', 'date_from', 'date_to']);
        
        $logs = $this->auditLogService->exportLogs($filters);
        
        return response()->json($logs);
    }

    public function fieldHistory(Request $request)
    {
        $history = $this->auditLogService->getFieldHistory(
            modelType: $request->model_type,
            modelId: $request->model_id,
            fieldName: $request->field_name,
            limit: 10
        );
        
        return response()->json($history);
    }
}


// Log custom event on a model
$user->logCustomEvent('approved', [
    'approved_by' => auth()->id(),
    'approval_date' => now(),
], 'User account approved');

// Manual logging
$auditLogService->logManualEvent(
    'import',
    'Users imported from CSV',
    null, // no specific model
    auth()->user(),
    ['file' => 'users.csv', 'count' => 150]
);