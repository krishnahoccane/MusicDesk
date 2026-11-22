<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <form wire:submit="save" novalidate
          x-data="{ theme: @js($theme), sidebar: @js($sidebar) }"
          x-effect="document.documentElement.dataset.ldTheme = theme; document.documentElement.dataset.ldSidebar = sidebar">
        @if ($previewCss)
            <style>html[data-ld-theme] { {{ $previewCss }} }</style>
        @endif

        @include('livewire.settings._appearance-fields', ['prefix' => '', 'files' => $files, 'previewRoute' => fn ($d) => route('settings.appearance.login-preview', $d)])

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ __('Save appearance') }}
            </button>
            <a href="{{ route('settings.appearance') }}" class="btn btn-light">{{ __('Discard changes') }}</a>
        </div>
    </form>
</div>
