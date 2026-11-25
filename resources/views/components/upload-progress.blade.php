@props(['for'])
{{--
    Upload percentage for a Livewire file input: <x-upload-progress for="audio" /> next to
    <input type="file" id="audio" wire:model="audio">. Livewire fires livewire-upload-start / -progress /
    -finish / -error / -cancel on the input; they bubble to the window, where this listens for that input.
--}}
<div x-data="{ uploading: false, progress: 0, mine(e) { return e.target && e.target.id === @js($for) } }"
     x-on:livewire-upload-start.window="if (mine($event)) { uploading = true; progress = 0 }"
     x-on:livewire-upload-progress.window="if (mine($event)) { progress = $event.detail.progress }"
     x-on:livewire-upload-finish.window="if (mine($event)) uploading = false"
     x-on:livewire-upload-error.window="if (mine($event)) uploading = false"
     x-on:livewire-upload-cancel.window="if (mine($event)) uploading = false"
     {{ $attributes->class(['mt-2']) }}>
    <div x-show="uploading" x-cloak role="status" aria-live="polite">
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span>{{ __('Uploading…') }}</span>
            <span class="font-mono" x-text="progress + '%'"></span>
        </div>
        <div class="progress" style="height: 6px" role="progressbar" aria-label="{{ __('Upload progress') }}" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress">
            <div class="progress-bar" x-bind:style="'width: ' + progress + '%'"></div>
        </div>
    </div>
</div>
