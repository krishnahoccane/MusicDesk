<?php

namespace App\Livewire\Users;

use App\Exceptions\UserManagementException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Throwable;

/**
 * Create and edit a user account.
 */
class Form extends Component
{
    use EnforcesLicense;

    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $is_active = true;

    /** On create: email a "set your password" link instead of choosing one. */
    public bool $send_link = true;

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);
            $this->user = $user;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = (string) $user->getRoleNames()->first();
            $this->is_active = $user->is_active;
            $this->send_link = false;
        } else {
            $this->authorize('create', User::class);
            $this->user = null;
        }
    }

    protected function rules(): array
    {
        $editing = $this->user !== null;
        $needsPassword = ! $editing && ! $this->send_link;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Deleted accounts still own their email address (they can be restored).
                Rule::unique('users', 'email')->ignore($this->user?->id),
            ],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'password' => [$needsPassword ? 'required' : 'nullable', 'string', 'max:255', 'confirmed', Password::defaults()],
            'is_active' => ['boolean'],
            'send_link' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['email' => __('email address')];
    }

    public function messages(): array
    {
        return ['email.unique' => __('This email address is already used by another account (it may be a deleted account you can restore).')];
    }

    public function save(UserService $users)
    {
        $this->ensureLicenseAllowsWrites();
        $this->user
            ? $this->authorize('update', $this->user)
            : $this->authorize('create', User::class);

        $data = $this->validate();
        $actor = auth()->user();

        try {
            if ($this->user) {
                $users->update($actor, $this->user, $data, $this->role);
                $message = __('Changes to :name have been saved.', ['name' => $this->user->name]);
            } else {
                $user = $users->create($actor, [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $this->send_link ? null : $data['password'],
                    'is_active' => $this->is_active,
                ], $this->role, $this->send_link && $this->is_active);

                $message = $this->send_link && $this->is_active
                    ? __(':name has been added and a link to set their password was emailed to :email.', ['name' => $user->name, 'email' => $user->email])
                    : __(':name has been added.', ['name' => $user->name]);
            }
        } catch (UserManagementException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        } catch (Throwable $e) {
            report($e);
            // The account was created but the email failed.
            session()->flash('warning', __('The user was saved, but the email could not be sent. Check Settings → Email, then use "Send password link".'));

            return $this->redirectRoute('users.index');
        }

        $this->reset('password', 'password_confirmation');
        session()->flash('success', $message);

        return $this->redirectRoute('users.index');
    }

    public function render(UserService $users): View
    {
        $editing = $this->user !== null;
        $title = $editing ? __('Edit :name', ['name' => $this->user->name]) : __('Add user');

        return view('livewire.users.form', [
            'editing' => $editing,
            'roles' => $users->assignableRoles(auth()->user()),
            'isSelf' => $editing && $this->user->is(auth()->user()),
            'seatsAvailable' => $users->seatsAvailable(),
            'seatLimit' => $users->seatLimit(),
            'activity' => $editing
                ? ActivityLog::query()->where(fn ($q) => $q
                    ->where('user_id', $this->user->id)
                    ->orWhere(fn ($s) => $s->where('subject_type', $this->user->getMorphClass())->where('subject_id', $this->user->id)))
                    ->latest('id')->limit(8)->get()
                : collect(),
        ])->title($title)->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')],
            ['label' => __('Users'), 'url' => route('users.index')],
            ['label' => $editing ? $this->user->name : __('Add user')],
        ]]);
    }
}
