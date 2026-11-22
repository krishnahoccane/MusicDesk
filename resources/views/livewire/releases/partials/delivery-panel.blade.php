{{-- Inline form for a delivery action or store details. Needs $title, $service, $panelAction. --}}
<form wire:submit="submitPanel" class="border rounded p-3 my-2" style="border-color: var(--ld-border) !important; background: var(--ld-surface)" novalidate>
    <div class="fw-semibold mb-2">{{ $title }}</div>
    <div class="row g-2">
        @if ($panelAction !== 'details' && $service->setsDate($panelAction))
            <div class="col-sm-4 col-md-3">
                <label for="panel-date" class="form-label small">{{ __('Date') }}</label>
                <input id="panel-date" type="date" wire:model="date" @class(['form-control form-control-sm', 'is-invalid' => $errors->has('date')])>
                @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        @endif
        @if ($panelFor !== 'all' && in_array($panelAction, ['go_live', 'details'], true))
            <div class="col-sm-4 col-md-3">
                <label for="panel-store-id" class="form-label small">{{ __('Store ID') }}</label>
                <input id="panel-store-id" type="text" wire:model="store_id" maxlength="100" class="form-control form-control-sm font-monospace">
            </div>
            <div class="col-sm-8 col-md-6">
                <label for="panel-store-url" class="form-label small">{{ __('Store link') }}</label>
                <input id="panel-store-url" type="url" wire:model="store_url" maxlength="500" placeholder="https://"
                       @class(['form-control form-control-sm', 'is-invalid' => $errors->has('store_url')])>
                @error('store_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        @endif
        @if ($panelAction === 'details')
            <div class="col-12">
                <label for="panel-notes" class="form-label small">{{ __('Notes') }}</label>
                <textarea id="panel-notes" wire:model="notes" rows="2" maxlength="1000" class="form-control form-control-sm"></textarea>
            </div>
        @elseif ($service->requiresComment($panelAction))
            <div class="col-12">
                <label for="panel-comment" class="form-label small">{{ $panelAction === 'reject' ? __('Why did the store reject it?') : __('Reason for the takedown') }}</label>
                <textarea id="panel-comment" wire:model="comment" rows="2" maxlength="2000"
                          @class(['form-control form-control-sm', 'is-invalid' => $errors->has('comment')])></textarea>
                @error('comment') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        @endif
    </div>
    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="submitPanel">{{ __('Save') }}</button>
        <button type="button" class="btn btn-sm btn-light" wire:click="closePanel">{{ __('Cancel') }}</button>
    </div>
</form>
