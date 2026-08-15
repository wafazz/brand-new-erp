<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessAdministrator;
use App\Domain\Access\AccessChangeRefused;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly AccessAdministrator $access,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CompanyUser::class);

        $term = trim((string) $request->query('q', ''));

        $members = CompanyUser::query()
            ->visibleTo($request->user(), 'users.view')
            ->when($term !== '', fn ($query) => $query->whereHas('user', fn ($q) => $q
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")))
            ->with(['user:id,name,email', 'branch:id,name'])
            ->orderBy('role')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CompanyUser $member): array => [
                'id' => $member->getKey(),
                'user_id' => $member->user_id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'role' => $member->role?->value,
                'branch' => $member->branch?->name,
                'employee_no' => $member->employee_no,
                'is_active' => $member->is_active,
                'is_self' => $member->user_id === $request->user()->getKey(),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'members' => $members,
            'filters' => ['q' => $term],
            'roles' => $this->assignableRoles($request->user()),
            'can' => [
                'create' => $request->user()->can('create', CompanyUser::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', CompanyUser::class);

        return Inertia::render('Admin/Users/Create', $this->references($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CompanyUser::class);

        $data = $this->validated($request, null, true);

        try {
            $member = DB::transaction(function () use ($data, $request): CompanyUser {
                $subject = User::query()->where('email', $data['email'])->first()
                    ?? User::create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => $data['password'],
                    ]);

                if (CompanyUser::query()->where('user_id', $subject->getKey())->exists()) {
                    throw new AccessChangeRefused('That person is already a member of this company.');
                }

                $member = $this->access->addMember($request->user(), $subject, CompanyRole::from($data['role']), [
                    'branch_id' => $data['branch_id'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                    'employee_no' => $data['employee_no'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'joined_at' => now(),
                ]);

                if ($member->branch_id !== null) {
                    $subject->branches()->syncWithoutDetaching([
                        $member->branch_id => ['id' => (string) str()->ulid(), 'company_id' => app(CompanyContext::class)->idOrFail(self::class)],
                    ]);
                }

                $this->recorder->record('created', 'users', $member, $request->user());

                return $member;
            });
        } catch (AccessChangeRefused $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect('/admin/users')->with('success', "{$member->user?->name} added as {$data['role']}.");
    }

    public function edit(Request $request, CompanyUser $member): Response
    {
        $this->authorize('update', $member);

        $member->loadMissing('user:id,name,email');

        return Inertia::render('Admin/Users/Edit', [
            ...$this->references($request),
            'member' => [
                'id' => $member->getKey(),
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'role' => $member->role?->value,
                'branch_id' => $member->branch_id,
                'department_id' => $member->department_id,
                'employee_no' => $member->employee_no,
                'is_active' => $member->is_active,
                'is_self' => $member->user_id === $request->user()->getKey(),
            ],
        ]);
    }

    public function update(Request $request, CompanyUser $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $data = $this->validated($request, $member, false);

        try {
            $this->access->updateMember($request->user(), $member, CompanyRole::from($data['role']), [
                'branch_id' => $data['branch_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'employee_no' => $data['employee_no'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        } catch (AccessChangeRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('updated', 'users', $member->refresh(), $request->user());

        return redirect('/admin/users')->with('success', 'Access updated.');
    }

    /** @return array<int, array{value: string, label: string, grantable: bool, reason: ?string}> */
    private function assignableRoles(User $actor): array
    {
        return array_map(function (CompanyRole $role) use ($actor): array {
            try {
                $this->access->assertMayAssignRole($actor, $role);

                return ['value' => $role->value, 'label' => $role->label(), 'grantable' => true, 'reason' => null];
            } catch (AccessChangeRefused $exception) {
                return ['value' => $role->value, 'label' => $role->label(), 'grantable' => false, 'reason' => $exception->getMessage()];
            }
        }, CompanyRole::cases());
    }

    /** @return array<string, mixed> */
    private function references(Request $request): array
    {
        return [
            'roles' => $this->assignableRoles($request->user()),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Branch $b): array => ['value' => $b->getKey(), 'label' => $b->name])->all(),
            'departments' => Department::query()->orderBy('name')->get()
                ->map(fn (Department $d): array => ['value' => $d->getKey(), 'label' => $d->name])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CompanyUser $member, bool $creating): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $rules = [
            'role' => ['required', Rule::in(array_column(CompanyRole::cases(), 'value'))],
            'branch_id' => ['nullable', 'string', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'department_id' => ['nullable', 'string', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'employee_no' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($creating) {
            $rules['name'] = ['required', 'string', 'max:160'];
            $rules['email'] = ['required', 'email', 'max:160'];
            $rules['password'] = ['required', 'string', 'min:12'];
        }

        return $request->validate($rules);
    }
}
