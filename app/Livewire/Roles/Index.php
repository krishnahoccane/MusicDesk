<?php

namespace App\Livewire\Roles;

use App\Enums\SystemRole;
use App\Exceptions\UserManagementException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\User;
use App\Services\RoleService;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class Index extends Component
{
    use EnforcesLicense;

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    public function delete(int $id, RoleService $roles): void
    {
        $this->ensureLicenseAllowsWrites();
        $role = Role::query()->findOrFail($id);
        $this->authorize('delete', $role);

        try {
            $roles->delete(auth()->user(), $role);
            $this->dispatch('toast', message: __('The :name role has been deleted.', ['name' => $role->name]), type: 'success');
        } catch (UserManagementException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        }
    }

    public function render(RoleService $roles): View
    {
        $list = Role::query()->withCount('permissions')->orderBy('name')->get();
        $userCounts = User::query()->toBase()
            ->join(config('permission.table_names.model_has_roles'), 'users.id', '=', 'model_id')
            ->where('model_type', (new User)->getMorphClass())
            ->whereNull('users.deleted_at')
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as aggregate')
            ->pluck('aggregate', 'role_id');

        // Built-in roles first, in their defined order; then custom roles.
        $order = collect(SystemRole::cases())->pluck('value')->flip();
        $list = $list->sortBy(fn (Role $r) => [$order[$r->name] ?? 99, $r->name])->values();

        return view('livewire.roles.index', [
            'roles' => $list,
            'userCounts' => $userCounts,
            'totalPermissions' => count(PermissionCatalog::all()),
            'customRolesEnabled' => $roles->customRolesEnabled(),
        ])->title(__('Roles'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')], ['label' => __('Roles')],
        ]]);
    }
}
