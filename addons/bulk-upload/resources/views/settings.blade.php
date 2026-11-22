<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <div class="card mb-3">
        <div class="card-header">
            <x-icon name="upload" size="18" />
            <h2 class="card-title">{{ __('Who may bulk upload') }}</h2>
            <a href="{{ route('bulk-upload.index') }}" class="small ms-auto">{{ __('Open bulk upload') }} &rarr;</a>
        </div>
        <form class="card-body" wire:submit="save" novalidate>
            <p class="small text-muted">{{ __('Administrators can always bulk upload artists and releases. Choose who else may:') }}</p>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="bulk_staff" wire:model="bulk_staff">
                <label class="form-check-label" for="bulk_staff">
                    {{ __('Label team') }}
                    <span class="d-block small text-muted">{{ __('Staff whose role includes "Bulk upload artists and releases" (Label Manager, Artist Manager by default).') }}</span>
                </label>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="bulk_artists" wire:model="bulk_artists">
                <label class="form-check-label" for="bulk_artists">
                    {{ __('Artist accounts') }}
                    <span class="d-block small text-muted">{{ __('Artists can bulk upload releases for their own artist profiles only. Releases arrive as drafts for your review.') }}</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </form>
    </div>
</div>
