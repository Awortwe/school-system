<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'role', 'status']);

        $staff = User::with(['roles', 'department', 'designation'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->role($role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_no' => $user->employee_no,
                'phone' => $user->phone,
                'role' => $user->displayRole(),
                'roles' => $user->getRoleNames()->values(),
                'department' => $user->department?->name,
                'designation' => $user->designation?->name,
                'employment_status' => $user->employment_status,
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toDateTimeString(),
            ]);

        return Inertia::render('Staff/Index', [
            'staff' => $staff,
            'filters' => $filters,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
                'pending_password' => User::where('must_change_password', true)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateStaff($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'employee_no' => $data['employee_no'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'designation_id' => $data['designation_id'] ?? null,
            'employment_status' => $data['employment_status'] ?? 'active',
            'employment_date' => $data['employment_date'] ?? now()->toDateString(),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'must_change_password' => (bool) ($data['must_change_password'] ?? true),
        ]);

        $this->syncRole($user, $data['role'], $request);

        Audit::record('staff_created', $user, [], ['name' => $user->name, 'email' => $user->email, 'role' => $data['role']], null, 'Staff account created');

        return redirect()->route('staff.index')->with('success', 'Staff account created.');
    }

    public function edit(User $staff): Response
    {
        abort_if($staff->isSuperAdmin() && ! request()->user()->isSuperAdmin(), 403);
        return Inertia::render('Staff/Edit', array_merge($this->formOptions(), [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'employee_no' => $staff->employee_no,
                'phone' => $staff->phone,
                'address' => $staff->address,
                'department_id' => $staff->department_id,
                'designation_id' => $staff->designation_id,
                'employment_status' => $staff->employment_status,
                'employment_date' => $staff->employment_date?->toDateString(),
                'is_active' => $staff->is_active,
                'must_change_password' => $staff->must_change_password,
                'role' => $staff->getRoleNames()->first(),
                'permissions' => $staff->getDirectPermissions()->pluck('name'),
            ],
        ]));
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $data = $this->validateStaff($request, $staff);

        $staff->update([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'employee_no' => $data['employee_no'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'designation_id' => $data['designation_id'] ?? null,
            'employment_status' => $data['employment_status'] ?? 'active',
            'employment_date' => $data['employment_date'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'must_change_password' => (bool) ($data['must_change_password'] ?? $staff->must_change_password),
        ]);

        if (! empty($data['password'])) {
            $staff->forceFill([
                'password' => Hash::make($data['password']),
                'must_change_password' => true,
            ])->save();
        }

        $this->syncRole($staff, $data['role'], $request);

        Audit::record('staff_updated', $staff, [], ['name' => $staff->name, 'email' => $staff->email, 'role' => $data['role']], null, 'Staff account updated');

        return redirect()->route('staff.index')->with('success', 'Staff account updated.');
    }

    public function toggle(Request $request, User $staff): RedirectResponse
    {
        abort_if($staff->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403);
        if ($staff->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($staff->isSuperAdmin() && User::role('super_admin')->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'The last active Super Administrator cannot be deactivated.');
        }

        $staff->update(['is_active' => ! $staff->is_active]);

        Audit::record('staff_status_changed', $staff, ['is_active' => ! $staff->is_active], ['is_active' => $staff->is_active], null, 'Staff account status changed');

        return back()->with('success', $staff->is_active ? 'Staff account activated.' : 'Staff account deactivated.');
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:departments,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        Department::create($data);

        return back()->with('success', 'Department created.');
    }

    public function storeDesignation(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:designations,name']]);

        Designation::create($data);

        return back()->with('success', 'Designation created.');
    }

    private function validateStaff(Request $request, ?User $staff = null): array
    {
        abort_if(($staff?->isSuperAdmin() || $request->input('role') === 'super_admin') && ! $request->user()->isSuperAdmin(), 403);
        $role = Role::where('name', $request->input('role'))->first();
        if (! $request->user()->isSuperAdmin() && $role) {
            abort_if($role->permissions->contains(fn ($permission) => ! $request->user()->can($permission->name)), 403, 'Cannot grant permissions you do not hold.');
        }
        if ($staff?->isSuperAdmin() && ($request->input('role') !== 'super_admin' || ! $request->boolean('is_active'))) {
            abort_if(User::role('super_admin')->where('is_active', true)->count() <= 1, 422, 'Retain at least one active Super Administrator.');
        }
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff?->id)],
            'employee_no' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_no')->ignore($staff?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'designation_id' => ['nullable', 'exists:designations,id'],
            'employment_status' => ['nullable', Rule::in(['active', 'probation', 'on_leave', 'resigned', 'retired'])],
            'employment_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'must_change_password' => ['boolean'],
            'role' => ['required', 'exists:roles,name'],
            'password' => [$staff ? 'nullable' : 'required', 'string', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);
    }

    private function syncRole(User $user, string $role, Request $request): void
    {
        if ($role === 'super_admin' && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a Super Administrator can assign the Super Administrator role.');
        }

        $user->syncRoles([$role]);
    }

    private function formOptions(): array
    {
        return [
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'permissions' => request()->user()->isSuperAdmin() ? \Spatie\Permission\Models\Permission::orderBy('name')->pluck('name') : [],
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'designations' => Designation::orderBy('name')->get(['id', 'name']),
        ];
    }

    public function permissions(Request $request, User $staff): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*.name' => ['required', 'exists:permissions,name']]);
        $old = $staff->getDirectPermissions()->pluck('name')->all();
        $staff->syncPermissions(collect($data['permissions'])->pluck('name')->all());
        Audit::record('staff_permissions_changed', $staff, ['permissions' => $old], ['permissions' => $staff->getDirectPermissions()->pluck('name')->all()], null, 'Staff permission overrides changed');
        return back()->with('success', 'Staff permissions updated.');
    }
}
