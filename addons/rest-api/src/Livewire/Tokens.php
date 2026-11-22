<?php

namespace LabelDesk\Addons\RestApi\Livewire;

use App\Exceptions\CatalogException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Services\ApiTokenService;
use LabelDesk\Addons\RestApi\Support\Abilities;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Administration → API tokens: create personal access tokens (shown once),
 * see when they were last used and revoke them. Users with
 * "api.tokens.manage" can also see and revoke everyone's tokens.
 */
class Tokens extends Component
{
    use EnforcesLicense, WithPagination;

    #[Url(except: 'mine')]
    public string $scope = 'mine';

    public bool $showForm = false;

    public string $name = '';

    public string $expiry = '';

    /** @var array<string, string> resource => none|read|write */
    public array $access = [];

    /** The new token, shown once and cleared on the next action. */
    #[Locked]
    public ?string $plainToken = null;

    #[Locked]
    public ?string $plainTokenName = null;

    public function mount(): void
    {
        abort_unless($this->user()->can('api.tokens'), 403);
        if ($this->scope !== 'all' || ! $this->canManage()) {
            $this->scope = 'mine';
        }
        $this->resetForm();
    }

    public function updatedScope(): void
    {
        if (! $this->canManage()) {
            $this->scope = 'mine';
        }
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless($this->user()->can('api.tokens'), 403);
        $this->dismissToken();
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(ApiTokenService $tokens): void
    {
        $this->ensureLicenseAllowsWrites();
        $user = $this->user();
        abort_unless($user->can('api.tokens'), 403);
        $this->dismissToken();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expiry' => ['required', Rule::in($this->expiryKeys())],
            'access' => ['array'],
            'access.*' => [Rule::in(['none', 'read', 'write'])],
        ], [], ['name' => __('token name'), 'expiry' => __('expiry')]);

        $abilities = [];
        foreach (Abilities::RESOURCES as $resource => $definition) {
            $level = $this->access[$resource] ?? 'none';
            if ($level === 'none') {
                continue;
            }
            // A token never gets more than its owner's role allows.
            if (! Abilities::userCan($user, $resource, $level)) {
                $this->addError('access.'.$resource, __('Your role does not allow this for :resource.', ['resource' => __($definition['label'])]));

                return;
            }
            $abilities[] = $resource.':'.$level;
        }

        if ($abilities === []) {
            $this->addError('access', __('Choose at least one ability.'));

            return;
        }

        $expiresAt = $this->expiry === 'never' ? null : now()->addDays((int) $this->expiry)->endOfDay();

        try {
            [$token, $plain] = $tokens->create($user, $this->name, $abilities, $expiresAt);
        } catch (CatalogException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->plainToken = $plain;
        $this->plainTokenName = $token->name;
        $this->scope = 'mine';
        $this->resetForm();
        $this->resetPage();
    }

    public function dismissToken(): void
    {
        $this->plainToken = null;
        $this->plainTokenName = null;
    }

    /** Revoking removes access, so it stays possible while the license is read-only. */
    public function revoke(int $id, ApiTokenService $tokens): void
    {
        $user = $this->user();
        abort_unless($user->can('api.tokens') || $this->canManage(), 403);
        $this->dismissToken();

        $token = ApiToken::query()->findOrFail($id);
        abort_unless($token->user_id === $user->id || $this->canManage(), 403);

        $tokens->revoke($user, $token);
        $this->dispatch('toast', message: __('The token ":name" has been revoked.', ['name' => $token->name]), type: 'success');
    }

    public function render(): View
    {
        $user = $this->user();
        $all = $this->scope === 'all' && $this->canManage();

        $tokens = ApiToken::query()
            ->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->when($all, fn ($q) => $q->with('user:id,name,email'))
            ->orderByRaw('case when revoked_at is null then 0 else 1 end')
            ->latest('id')
            ->paginate(25);

        $resources = [];
        foreach (Abilities::RESOURCES as $resource => $definition) {
            $resources[$resource] = [
                'label' => __($definition['label']),
                'read' => Abilities::userCan($user, $resource, 'read'),
                'write' => Abilities::userCan($user, $resource, 'write'),
            ];
        }

        return view('rest-api::tokens', [
            'tokens' => $tokens,
            'showingAll' => $all,
            'canManage' => $this->canManage(),
            'resources' => $resources,
            'expiryOptions' => $this->expiryOptions(),
        ])->title(__('API tokens'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')], ['label' => __('API tokens')],
        ]]);
    }

    protected function resetForm(): void
    {
        $this->showForm = false;
        $this->name = '';
        $default = config('rest-api.default_expiry', 90);
        $this->expiry = in_array((string) ($default ?? 'never'), $this->expiryKeys(), true) ? (string) ($default ?? 'never') : $this->expiryKeys()[0];

        $user = $this->user();
        $this->access = [];
        foreach (array_keys(Abilities::RESOURCES) as $resource) {
            $this->access[$resource] = Abilities::userCan($user, $resource, 'read') ? 'read' : 'none';
        }
        $this->resetValidation();
    }

    /** @return array<string, string> key => label */
    protected function expiryOptions(): array
    {
        $options = [];
        foreach ((array) config('rest-api.expiry_options', [30, 90, 365]) as $days) {
            $options[$days === null ? 'never' : (string) (int) $days] = $days === null
                ? __('No expiry')
                : trans_choice('{1} :count day|[2,*] :count days', (int) $days, ['count' => (int) $days]);
        }

        return $options;
    }

    /** @return list<string> */
    protected function expiryKeys(): array
    {
        // Numeric keys come back as integers from array_keys().
        return array_map('strval', array_keys($this->expiryOptions()));
    }

    protected function canManage(): bool
    {
        return $this->user()->can('api.tokens.manage');
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
