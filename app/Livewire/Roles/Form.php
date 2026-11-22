<?php

namespace App\Livewire\Roles;

use App\Enums\SystemRole;
use App\Exceptions\UserManagementException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Services\RoleService;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Create, edit or (without roles.manage) view a role's permissions.
 */
class Form extends Component
{
    use EnforcesLicense;

    public ?Role $role = null;

    public string $name = '';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            $this->authorize('view', $role);
            $this->role = $role;
            $this->name = $role->name;
            $this->permissions = $role->permissions()->pluck('name')->all();
        } else {
            $this->authorize('create', Role::class);
            abort_unless(app(RoleService::class)->customRolesEnabled(), 403, __('Custom roles are not included in your license plan.'));
            $this->role = null;
        }
    }

    public function toggleGroup(string $group): void
    {
        $names = PermissionCatalog::names()[$group] ?? [];
        $allSelected = array_diff($names, $this->permissions) === [];

        $this->permissions = $allSelected
            ? array_values(array_diff($this->permissions, $names))
            : array_values(array_unique([...$this->permissions, ...$names]));
    }

    public function save(RoleService $roles)
    {
        $this->ensureLicenseAllowsWrites();
        $this->role ? $this->authorize('update', $this->role) : $this->authorize('create', Role::class);

        $this->validate([
            'name' => [
                'required', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN \-&\/\.]*$/u',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($this->role?->id),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ], [
            'name.regex' => __('Use letters, numbers, spaces and - & / . only.'),
        ]);

        try {
            $role = $this->role
                ? $roles->update(auth()->user(), $this->role, $this->name, $this->permissions)
                : $roles->create(auth()->user(), $this->name, $this->permissions);
        } catch (UserManagementException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        }

        session()->flash('success', __('The :name role has been saved.', ['name' => $role->name]));

        return $this->redirectRoute('roles.index');
    }

    public function render(RoleService $roles): View
    {
        $editing = $this->role !== null;
        $superAdmin = $editing && RoleService::isSuperAdmin($this->role);
        $canEdit = ! $superAdmin && auth()->user()->can($editing ? 'update' : 'create', $editing ? $this->role : Role::class);
        $title = $editing ? $this->role->name : __('New role');

        $actor = auth()->user();
        $held = $actor->hasRole(SystemRole::SuperAdmin->value) ? null : $actor->getAllPermissions()->pluck('name')->all();

        return view('livewire.roles.form', [
            'editing' => $editing,
            'system' => $editing && RoleService::isSystem($this->role),
            'superAdmin' => $superAdmin,
            'canEdit' => $canEdit,
            'canRename' => $canEdit && (! $editing || ! RoleService::isSystem($this->role)) && $roles->customRolesEnabled(),
            'groups' => PermissionCatalog::groups(),
            'held' => $held,
        ])->title($title)->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')],
            ['label' => __('Roles'), 'url' => route('roles.index')],
            ['label' => $title],
        ]]);
    }
}
