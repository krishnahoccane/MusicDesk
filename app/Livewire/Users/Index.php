<?php

namespace App\Livewire\Users;

use App\Exceptions\UserManagementException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\User;
use App\Services\UserService;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;
use Throwable;

class Index extends Component
{
    use EnforcesLicense, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'role', 'status');
        $this->resetPage();
    }

    public function toggleActive(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);

        $this->act('update', $user, function () use ($users, $user) {
            $users->setActive(auth()->user(), $user, ! $user->is_active);

            return $user->is_active
                ? __(':name has been activated.', ['name' => $user->name])
                : __(':name has been deactivated and signed out.', ['name' => $user->name]);
        });
    }

    public function delete(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);

        $this->act('delete', $user, function () use ($users, $user) {
            $users->delete(auth()->user(), $user);

            return __(':name has been deleted. You can restore the account from the Deleted filter.', ['name' => $user->name]);
        });
    }

    public function restore(int $id, UserService $users): void
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $this->act('restore', $user, function () use ($users, $user) {
            $users->restore(auth()->user(), $user);

            return __(':name has been restored as a deactivated account.', ['name' => $user->name]);
        });
    }

    public function sendPasswordLink(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);

        $this->act('update', $user, function () use ($users, $user) {
            return $users->sendPasswordLink(auth()->user(), $user)
                ? __('A password link has been emailed to :email.', ['email' => $user->email])
                : throw new UserManagementException(__('The password link could not be sent.'));
        });
    }

    /**
     * Runs a write action with license, policy and error handling.
     *
     * @param  Closure(): string  $action
     */
    protected function act(string $ability, User $user, Closure $action): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorize($ability, $user);

        try {
            $message = $action();
            $this->dispatch('toast', message: $message, type: 'success');
        } catch (UserManagementException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('The email could not be sent. Check Settings → Email.'), type: 'danger');
        }
    }

    public function render(UserService $users): View
    {
        $query = $this->status === 'deleted' ? User::onlyTrashed() : User::query();

        $list = $query
            ->with(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($this->role !== '', fn ($q) => $q->role($this->role))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.users.index', [
            'users' => $list,
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'seatLimit' => $users->seatLimit(),
            'activeCount' => $users->activeCount(),
            'seatsAvailable' => $users->seatsAvailable(),
        ])->title(__('Users'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')], ['label' => __('Users')],
        ]]);
    }
}
