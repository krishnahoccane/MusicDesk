<div class="card">
    <div class="card-header">
        <x-icon name="link" size="18" class="text-muted" />
        <h2 class="card-title">{{ __('Streaming and social links') }}</h2>
    </div>

    @if ($canEdit)
        <form wire:submit="save" class="card-body border-bottom" novalidate>
            <div class="row g-2 align-items-start">
                <div class="col-md-3">
                    <label for="link-platform" class="visually-hidden">{{ __('Platform') }}</label>
                    <select id="link-platform" wire:model="platform" class="form-select">
                        @foreach ($platforms as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="link-url" class="visually-hidden">{{ __('Web address') }}</label>
                    <input id="link-url" type="url" wire:model="url" maxlength="500" placeholder="https://"
                           @class(['form-control', 'is-invalid' => $errors->has('url')])>
                    @error('url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1" wire:loading.attr="disabled" wire:target="save">
                        {{ $editingId ? __('Save link') : __('Add link') }}
                    </button>
                    @if ($editingId)
                        <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                    @endif
                </div>
            </div>
        </form>
    @endif

    @if ($links->isEmpty())
        <x-empty-state icon="link" :title="__('No links yet')" :description="__('Add the artist\'s profiles on streaming services and social media.')" />
    @else
        <ul class="list-unstyled mb-0">
            @foreach ($links as $link)
                <li class="d-flex align-items-center gap-3 px-4 py-3 border-bottom" wire:key="link-{{ $link->id }}">
                    <x-icon :name="$link->platform->icon()" size="18" class="text-muted" />
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">{{ $link->platform->label() }}</div>
                        <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer nofollow" class="small text-break">{{ $link->url }}</a>
                    </div>
                    @if ($canEdit)
                        <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $link->id }})" aria-label="{{ __('Edit link') }}"><x-icon name="pencil" size="14" /></button>
                        <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $link->id }})"
                                wire:confirm="{{ __('Remove this link?') }}" aria-label="{{ __('Remove link') }}"><x-icon name="trash-2" size="14" /></button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
