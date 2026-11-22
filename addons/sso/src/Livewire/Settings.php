<?php

namespace LabelDesk\Addons\Sso\Livewire;

use App\Enums\SystemRole;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\Sso\Models\SsoProvider;
use LabelDesk\Addons\Sso\Oidc\OidcClient;
use LabelDesk\Addons\Sso\Oidc\SsoException;
use LabelDesk\Addons\Sso\SsoPolicy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Settings → Single sign-on: identity providers and the "require single
 * sign-on" policy. Client secrets are stored encrypted and never sent back
 * to the browser.
 */
class Settings extends Component
{
    use EnforcesLicense;

    public bool $require_sso = false;

    public bool $require_sso_artists = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public bool $hasSecret = false;

    public string $preset = 'entra';

    public string $name = '';

    public string $tenant = '';

    public string $issuer = '';

    public string $client_id = '';

    /** Write-only: blank keeps the saved secret. */
    public string $client_secret = '';

    public string $scopes = 'openid email profile';

    public string $allowed_domains = '';

    public bool $enabled = true;

    public bool $jit_enabled = false;

    public string $jit_role = '';

    public function mount(SsoPolicy $policy): void
    {
        $this->authorizeManage();
        $this->require_sso = $policy->required();
        $this->require_sso_artists = $policy->includesArtists();
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('settings.manage'), 403);
    }

    /** @return Collection<int, SsoProvider> */
    #[Computed]
    public function providers(): Collection
    {
        return SsoProvider::query()->withCount('identities')->orderBy('name')->get();
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->whereNotIn('name', [SystemRole::SuperAdmin->value, SystemRole::Artist->value])->orderBy('name')->pluck('name');
    }

    /** The redirect URI the provider will use (the slug is fixed when the provider is first saved). */
    #[Computed]
    public function redirectUri(): string
    {
        $slug = $this->editingId
            ? (string) SsoProvider::query()->whereKey($this->editingId)->value('slug')
            : SsoProvider::uniqueSlug(trim($this->name));

        return route('sso.callback', ['provider' => $slug]);
    }

    // ------------------------------------------------------------ providers

    public function create(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $provider = SsoProvider::query()->findOrFail($id);
        $this->resetForm();

        $this->editingId = $provider->id;
        $this->preset = $provider->preset;
        $this->name = $provider->name;
        if ($provider->preset === 'entra') {
            $this->tenant = (string) Str::of($provider->issuer)->after('login.microsoftonline.com/')->before('/');
        } elseif ($provider->preset !== 'google') {
            $this->issuer = $provider->issuer;
        }
        $this->client_id = $provider->client_id;
        $this->hasSecret = filled($provider->getRawOriginal('client_secret'));
        $this->scopes = (string) $provider->scopes;
        $this->allowed_domains = implode(', ', $provider->domains());
        $this->enabled = $provider->enabled;
        $this->jit_enabled = $provider->jit_enabled;
        $this->jit_role = (string) $provider->jit_role;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'hasSecret', 'preset', 'name', 'tenant', 'issuer', 'client_id', 'client_secret', 'scopes', 'allowed_domains', 'enabled', 'jit_enabled', 'jit_role');
        $this->resetValidation();
    }

    public function save(OidcClient $client, ActivityLogger $activity): void
    {
        $this->authorizeManage();
        $this->ensureLicenseAllowsWrites();

        $provider = $this->editingId ? SsoProvider::query()->findOrFail($this->editingId) : new SsoProvider;
        $this->validate([
            'preset' => ['required', Rule::in(array_keys(SsoProvider::PRESETS))],
            'name' => ['required', 'string', 'max:80'],
            'tenant' => [Rule::requiredIf($this->preset === 'entra'), 'nullable', 'uuid'],
            'issuer' => [Rule::requiredIf(in_array($this->preset, ['okta', 'oidc'], true)), 'nullable', 'string', 'max:240', 'url:https'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [Rule::requiredIf(! $this->hasSecret), 'nullable', 'string', 'max:1000'],
            'scopes' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7E]+( [\x21-\x7E]+)*$/'],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],
            'jit_role' => [Rule::requiredIf($this->jit_enabled), 'nullable', Rule::in($this->roles->all())],
        ], [
            'tenant.uuid' => __('Enter the Directory (tenant) ID, e.g. 72f988bf-86f1-41af-91ab-2d7cd011db47.'),
            'issuer.url' => __('Enter the issuer URL starting with https://.'),
            'scopes.regex' => __('Separate scopes with single spaces.'),
        ], ['tenant' => __('tenant ID'), 'client_id' => __('client ID'), 'client_secret' => __('client secret'), 'jit_role' => __('role')]);

        $domains = $this->parseDomains();
        if ($domains === null) {
            $this->addError('allowed_domains', __('Enter domain names such as example.com, separated by commas.'));

            return;
        }
        if ($this->jit_enabled && $domains === []) {
            $this->addError('allowed_domains', __('Creating accounts automatically needs at least one allowed email domain.'));

            return;
        }

        $provider->fill([
            'name' => trim($this->name),
            'preset' => $this->preset,
            'issuer' => $this->issuerUrl(),
            'client_id' => trim($this->client_id),
            'scopes' => trim($this->scopes),
            'allowed_domains' => $domains,
            'enabled' => $this->enabled,
            'jit_enabled' => $this->jit_enabled,
            'jit_role' => $this->jit_enabled ? $this->jit_role : null,
        ]);
        if ($this->client_secret !== '') {
            $provider->client_secret = $this->client_secret;
        }

        // Only enable a provider whose configuration can actually be loaded.
        if ($provider->enabled) {
            try {
                $client->discover($provider, fresh: true);
            } catch (SsoException $e) {
                $this->addError($this->preset === 'entra' ? 'tenant' : ($this->preset === 'google' ? 'preset' : 'issuer'), $e->getMessage());

                return;
            }
        }

        $created = ! $provider->exists;
        if ($created) {
            $provider->slug = SsoProvider::uniqueSlug($provider->name);
        }
        $provider->save();

        $activity->log($created ? 'sso.provider_created' : 'sso.provider_updated', ($created
            ? __('Added single sign-on provider :name', ['name' => $provider->name])
            : __('Updated single sign-on provider :name', ['name' => $provider->name])), null, [
                'provider' => $provider->name, 'preset' => $provider->preset, 'enabled' => $provider->enabled,
                'secret' => $this->client_secret !== '' ? 'changed' : 'unchanged',
            ]);

        $this->resetForm();
        unset($this->providers);
        $this->dispatch('toast', message: __('Single sign-on provider saved.'), type: 'success');
    }

    /** @return list<string>|null */
    protected function parseDomains(): ?array
    {
        $domains = array_values(array_unique(array_filter(array_map(
            fn ($d) => Str::lower(trim(ltrim(trim($d), '@'))),
            preg_split('/[\s,;]+/', $this->allowed_domains) ?: []
        ))));
        foreach ($domains as $domain) {
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                return null;
            }
        }

        return $domains;
    }

    protected function issuerUrl(): string
    {
        return match ($this->preset) {
            'entra' => 'https://login.microsoftonline.com/'.Str::lower(trim($this->tenant)).'/v2.0',
            'google' => SsoProvider::GOOGLE_ISSUER,
            default => rtrim((string) Str::of(trim($this->issuer))->before('?')->before('#')->replaceEnd('/.well-known/openid-configuration', ''), '/'),
        };
    }

    public function toggle(int $id, OidcClient $client, ActivityLogger $activity): void
    {
        $this->authorizeManage();
        $this->ensureLicenseAllowsWrites();
        $provider = SsoProvider::query()->findOrFail($id);

        if (! $provider->enabled) {
            try {
                $client->discover($provider, fresh: true);
            } catch (SsoException $e) {
                $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

                return;
            }
        } elseif ($this->require_sso && SsoProvider::query()->enabled()->count() === 1) {
            $this->dispatch('toast', message: __('Turn off "Require single sign-on" before disabling the last provider.'), type: 'warning');

            return;
        }

        $provider->forceFill(['enabled' => ! $provider->enabled])->save();
        $activity->log('sso.provider_updated', ($provider->enabled
            ? __('Enabled single sign-on provider :name', ['name' => $provider->name])
            : __('Disabled single sign-on provider :name', ['name' => $provider->name])), null, ['provider' => $provider->name, 'enabled' => $provider->enabled]);
        unset($this->providers);
    }

    public function test(int $id, OidcClient $client): void
    {
        $this->authorizeManage();
        $provider = SsoProvider::query()->findOrFail($id);
        try {
            $document = $client->discover($provider, fresh: true);
            $this->dispatch('toast', message: __('Connected to :issuer.', ['issuer' => $document['issuer']]), type: 'success');
        } catch (SsoException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        }
    }

    public function delete(int $id, ActivityLogger $activity): void
    {
        $this->authorizeManage();
        $this->ensureLicenseAllowsWrites();
        $provider = SsoProvider::query()->findOrFail($id);

        if ($this->require_sso && $provider->enabled && SsoProvider::query()->enabled()->count() === 1) {
            $this->dispatch('toast', message: __('Turn off "Require single sign-on" before removing the last provider.'), type: 'warning');

            return;
        }

        $provider->delete();
        $activity->log('sso.provider_deleted', __('Removed single sign-on provider :name', ['name' => $provider->name]), null, ['provider' => $provider->name]);
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        unset($this->providers);
        $this->dispatch('toast', message: __('Single sign-on provider removed.'), type: 'success');
    }

    // ------------------------------------------------------------ policy

    public function savePolicy(SettingsService $settings, SsoPolicy $policy, ActivityLogger $activity): void
    {
        $this->authorizeManage();
        $this->ensureLicenseAllowsWrites();

        if ($this->require_sso && ! SsoProvider::query()->enabled()->exists()) {
            $this->addError('require_sso', __('Enable at least one provider first.'));

            return;
        }

        $wasRequired = $policy->required();
        $settings->set(SsoPolicy::REQUIRE, $this->require_sso, 'bool');
        $settings->set(SsoPolicy::INCLUDE_ARTISTS, $this->require_sso_artists, 'bool');

        // "Keep me signed in" cookies of affected users stop working, so they sign in with SSO next time.
        if ($this->require_sso) {
            User::query()->where('is_active', true)->whereNotNull('remember_token')->each(function (User $user) use ($policy) {
                if ($policy->passwordBlockedFor($user)) {
                    $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
                }
            });
        }

        $activity->log('settings.updated', __('Updated single sign-on policy'), null, [
            'require_sso' => $this->require_sso, 'include_artists' => $this->require_sso_artists, 'was_required' => $wasRequired,
        ]);
        $this->dispatch('toast', message: __('Single sign-on policy saved.'), type: 'success');
    }

    public function render(): View
    {
        return view('sso::livewire.settings', [
            'presets' => SsoProvider::PRESETS,
        ])->title(__('Single sign-on'))
            ->layoutData(['breadcrumbs' => [['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Single sign-on')]]]);
    }
}
