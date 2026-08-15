<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessAdministrator;
use App\Domain\Access\AccessChangeRefused;
use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use App\Services\Access\ScopeResolver;
use App\Services\Audit\AuditRecorder;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(
        private readonly AccessAdministrator $access,
        private readonly ScopeResolver $scopes,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('roles.view'), 403);

        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get();
        $permissions = Permission::query()->orderBy('name')->get()->keyBy('name');

        $scopes = RolePermissionScope::query()
            ->get()
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->keyBy('permission_id'));

        $actor = $request->user();
        $mayEdit = $actor->can('roles.update');

        $groups = [];

        foreach (PermissionRegistry::all() as $name) {
            $split = PermissionRegistry::split($name);
            $groups[$split['group']][] = $split['ability'];
        }

        return Inertia::render('Admin/Roles/Index', [
            'groups' => array_map(
                static fn (string $group, array $abilities): array => ['group' => $group, 'abilities' => $abilities],
                array_keys($groups),
                $groups
            ),
            'roles' => $roles->map(function (Role $role) use ($permissions, $scopes, $actor): array {
                $held = $role->permissions->pluck('name')->all();

                return [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                    'is_system' => (bool) $role->is_system,
                    'members' => CompanyUser::query()->where('role', $role->name)->where('is_active', true)->count(),
                    'is_own_role' => $actor->hasRole($role->name),
                    'permissions' => collect(PermissionRegistry::all())
                        ->mapWithKeys(function (string $name) use ($held, $permissions, $scopes, $role): array {
                            $permission = $permissions->get($name);
                            $scope = $permission === null
                                ? null
                                : $scopes->get($role->getKey())?->get($permission->getKey())?->scope;

                            return [$name => [
                                'granted' => in_array($name, $held, true),
                                'scope' => $scope instanceof DataScope ? $scope->value : null,
                            ]];
                        })
                        ->all(),
                ];
            })->all(),
            'scopeOptions' => array_values(array_map(
                static fn (DataScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
                array_filter(DataScope::cases(), static fn (DataScope $s): bool => $s->isGrantableToCompanyRole())
            )),
            'myScopes' => collect(PermissionRegistry::all())
                ->mapWithKeys(fn (string $name): array => [$name => $this->scopes->for($actor, $name)?->value])
                ->all(),
            'can' => ['update' => $mayEdit],
        ]);
    }

    public function updateScope(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->can('roles.update'), 403);

        $data = $request->validate([
            'permission' => ['required', 'string', Rule::in(PermissionRegistry::all())],
            'scope' => ['required', Rule::in(array_column(DataScope::cases(), 'value'))],
        ]);

        $permission = Permission::query()->where('name', $data['permission'])->firstOrFail();

        try {
            $this->access->setScope($request->user(), $role, $permission, DataScope::from($data['scope']));
        } catch (AccessChangeRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record(
            'scope_changed',
            'roles',
            $role,
            $request->user(),
            "{$data['permission']} set to {$data['scope']}",
        );

        return back()->with('success', "{$role->name} · {$data['permission']} is now \"".DataScope::from($data['scope'])->label().'".');
    }
}
