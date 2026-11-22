<div>
    <x-page-header :title="__('API tokens')" :subtitle="__('Personal access tokens let other systems use the LabelDesk REST API with your permissions.')">
        <x-slot:actions>
            <a href="{{ route('rest-api.reference') }}" class="btn btn-light"><x-icon name="book-open" size="16" /> {{ __('API reference') }}</a>
            @if (! $showForm)
                <button type="button" class="btn btn-primary" wire:click="create"><x-icon name="plus" size="16" /> {{ __('Create token') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($plainToken)
        <x-alert variant="success" class="mb-4" :title="__('Token created: :name', ['name' => $plainTokenName])">
            <p class="mb-2">{{ __('Copy it now and store it somewhere safe. For your security it will not be shown again.') }}</p>
            <div class="input-group mb-2" x-data="{ copied: false }">
                <input type="text" class="form-control font-monospace" value="{{ $plainToken }}" readonly aria-label="{{ __('New API token') }}" x-ref="token" x-on:focus="$el.select()">
                <button type="button" class="btn btn-outline-secondary"
                        x-on:click="navigator.clipboard.writeText($refs.token.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <x-icon name="copy" size="16" /> <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                </button>
            </div>
            <button type="button" class="btn btn-sm btn-light" wire:click="dismissToken">{{ __('I have stored it') }}</button>
        </x-alert>
    @endif

    @if ($showForm)
        <form wire:submit="save" class="card mb-4" novalidate>
            <div class="card-header">
                <x-icon name="key-round" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Create token') }}</h2>
            </div>
            <div class="card-body">
                @error('form') <x-alert variant="danger" class="mb-3">{{ $message }}</x-alert> @enderror
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="t-name" class="form-label">{{ __('Name') }}</label>
                        <input id="t-name" type="text" wire:model="name" maxlength="100" placeholder="{{ __('e.g. Website sync') }}" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-5">
                        <label for="t-expiry" class="form-label">{{ __('Expires after') }}</label>
                        <select id="t-expiry" wire:model="expiry" @class(['form-select', 'is-invalid' => $errors->has('expiry')])>
                            @foreach ($expiryOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('expiry') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <span class="form-label d-block">{{ __('Abilities') }}</span>
                        <p class="small text-muted mb-2">{{ __('The token can never do more than your role allows. Write includes read.') }}</p>
                        @error('access') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    @foreach ($resources as $resource => $info)
                                        <tr wire:key="access-{{ $resource }}">
                                            <th scope="row" class="fw-semibold" style="width: 35%">{{ $info['label'] }}</th>
                                            <td>
                                                <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="{{ $info['label'] }}">
                                                    @foreach (['none' => __('No access'), 'read' => __('Read'), 'write' => __('Read & write')] as $level => $label)
                                                        @php($allowed = $level === 'none' || $info[$level])
                                                        <div class="form-check mb-0">
                                                            <input class="form-check-input" type="radio" id="access-{{ $resource }}-{{ $level }}" value="{{ $level }}"
                                                                   wire:model="access.{{ $resource }}" @disabled(! $allowed)>
                                                            <label @class(['form-check-label', 'text-muted' => ! $allowed]) for="access-{{ $resource }}-{{ $level }}">{{ $label }}</label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @error('access.'.$resource) <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ __('Create token') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        @if ($canManage)
            <div class="card-body border-bottom py-3">
                <select class="form-select" style="max-width: 260px" wire:model.live="scope" aria-label="{{ __('Show tokens of') }}">
                    <option value="mine">{{ __('My tokens') }}</option>
                    <option value="all">{{ __('All users') }}</option>
                </select>
            </div>
        @endif

        @if ($tokens->isEmpty())
            <x-empty-state icon="braces" :title="__('No API tokens yet')"
                           :description="__('Create a token to connect a website, a distributor tool or a script to LabelDesk.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            @if ($showingAll) <th>{{ __('User') }}</th> @endif
                            <th class="d-none d-lg-table-cell">{{ __('Abilities') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Last used') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Expires') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            @php($status = $token->status())
                            <tr wire:key="token-{{ $token->id }}" @class(['text-muted' => $status !== 'active'])>
                                <td>
                                    <div class="fw-semibold">{{ $token->name }}</div>
                                    <div class="small text-muted font-monospace">{{ $token->token_prefix }}…</div>
                                </td>
                                @if ($showingAll)
                                    <td>
                                        <div>{{ $token->user?->name ?? '—' }}</div>
                                        <div class="small text-muted">{{ $token->user?->email }}</div>
                                    </td>
                                @endif
                                <td class="d-none d-lg-table-cell small">
                                    @foreach (array_keys(LabelDesk\Addons\RestApi\Support\Abilities::RESOURCES) as $resource)
                                        @if ($token->allows($resource.':write'))
                                            <x-badge variant="accent">{{ LabelDesk\Addons\RestApi\Support\Abilities::label($resource.':write') }}</x-badge>
                                        @elseif ($token->allows($resource.':read'))
                                            <x-badge>{{ LabelDesk\Addons\RestApi\Support\Abilities::label($resource.':read') }}</x-badge>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="d-none d-md-table-cell small">
                                    {{ App\Support\Format::datetime($token->last_used_at, __('Never')) }}
                                    @if ($token->last_used_ip) <div class="text-muted">{{ $token->last_used_ip }}</div> @endif
                                </td>
                                <td class="d-none d-md-table-cell small">{{ App\Support\Format::date($token->expires_at, __('Never')) }}</td>
                                <td>
                                    @if ($status === 'active')
                                        <x-badge variant="success">{{ __('Active') }}</x-badge>
                                    @elseif ($status === 'expired')
                                        <x-badge variant="warning">{{ __('Expired') }}</x-badge>
                                    @else
                                        <x-badge variant="danger">{{ __('Revoked') }}</x-badge>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if (! $token->isRevoked())
                                        <button type="button" class="btn btn-sm btn-light text-danger" wire:click="revoke({{ $token->id }})"
                                                wire:confirm="{{ __('Revoke :name? Systems using it stop working immediately.', ['name' => $token->name]) }}"
                                                aria-label="{{ __('Revoke :name', ['name' => $token->name]) }}"><x-icon name="ban" size="14" /> {{ __('Revoke') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($tokens->hasPages())
                <div class="card-body border-top py-2">{{ $tokens->links() }}</div>
            @endif
        @endif
    </div>
</div>
