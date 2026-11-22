<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-12 col-xxl-9">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="building-2" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Label') }}</h2>
                    </div>
                    <div class="card-body">
                        <label for="app_name" class="form-label">{{ __('Label name') }}</label>
                        <input id="app_name" type="text" wire:model="app_name" maxlength="80" style="max-width: 420px"
                               @class(['form-control', 'is-invalid' => $errors->has('app_name')]) required>
                        <div class="form-text">{{ __('Shown in the browser title, emails and reports.') }}</div>
                        @error('app_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="globe" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Regional') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="timezone" class="form-label">{{ __('Display timezone') }}</label>
                                <select id="timezone" wire:model="timezone" @class(['form-select', 'is-invalid' => $errors->has('timezone')])>
                                    @foreach ($timezones as $tz)
                                        <option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('Dates are stored in UTC and shown in this timezone.') }}</div>
                                @error('timezone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="currency" class="form-label">{{ __('Default currency') }}</label>
                                <select id="currency" wire:model="currency" @class(['form-select', 'is-invalid' => $errors->has('currency')])>
                                    @foreach ($currencies as $code)
                                        <option value="{{ $code }}">{{ $code }}</option>
                                    @endforeach
                                </select>
                                @error('currency') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="date_format" class="form-label">{{ __('Date format') }}</label>
                                <select id="date_format" wire:model="date_format" class="form-select">
                                    @foreach ($dateFormats as $format => $example)
                                        <option value="{{ $format }}">{{ $example }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="time_format" class="form-label">{{ __('Time format') }}</label>
                                <select id="time_format" wire:model="time_format" class="form-select">
                                    @foreach ($timeFormats as $format => $label)
                                        <option value="{{ $format }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="locale" class="form-label">{{ __('Language') }}</label>
                                <select id="locale" wire:model="locale" class="form-select">
                                    @foreach ($locales as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <x-icon name="history" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Activity log') }}</h2>
                    </div>
                    <div class="card-body">
                        <label for="retention_days" class="form-label">{{ __('Keep entries for') }}</label>
                        <div class="input-group" style="max-width: 220px">
                            <input id="retention_days" type="number" min="0" max="3650" wire:model="retention_days"
                                   @class(['form-control', 'is-invalid' => $errors->has('retention_days')])>
                            <span class="input-group-text">{{ __('days') }}</span>
                        </div>
                        <div class="form-text">{{ __('Older entries are removed automatically each night. Use 0 to keep them forever.') }}</div>
                        @error('retention_days') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ __('Save settings') }}
            </button>
        </div>
    </form>
</div>
