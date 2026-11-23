<div>
    @if ($canManage)
        <form wire:submit="storeDocument" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="upload" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Upload a document') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="doc-file" class="form-label">{{ __('File') }}</label>
                        <input id="doc-file" type="file" wire:model="file" accept="{{ $accept }}"
                               @class(['form-control', 'is-invalid' => $errors->has('file')])>
                        <div class="form-text">{{ __('PDF, Word, Excel, OpenDocument, CSV, text or images, up to :mb MB. Stored privately.', ['mb' => $maxMb]) }}</div>
                        <div wire:loading wire:target="file" class="small text-muted">{{ __('Uploading…') }}</div>
                        @error('file') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="doc-title" class="form-label">{{ __('Title') }}</label>
                        <input id="doc-title" type="text" wire:model="title" maxlength="200"
                               @class(['form-control', 'is-invalid' => $errors->has('title')])>
                        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="doc-category" class="form-label">{{ __('Category') }}</label>
                        <select id="doc-category" wire:model="category" class="form-select">
                            @foreach ($categories as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label for="doc-contract" class="form-label">{{ __('Related contract') }}</label>
                        <select id="doc-contract" wire:model="contract_id" @class(['form-select', 'is-invalid' => $errors->has('contract_id')])>
                            <option value="">{{ __('None') }}</option>
                            @foreach ($contracts as $id => $contractTitle)
                                <option value="{{ $id }}">{{ $contractTitle }}</option>
                            @endforeach
                        </select>
                        @error('contract_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="storeDocument,file">
                    <span wire:loading wire:target="storeDocument" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <x-icon name="upload" size="16" /> {{ __('Upload') }}
                </button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="card-header">
            <x-icon name="paperclip" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Documents') }}</h2>
            <select class="form-select form-select-sm ms-auto" style="max-width: 200px" wire:model.live="filter" aria-label="{{ __('Filter by category') }}">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @if ($documents->isEmpty())
            <x-empty-state icon="paperclip" :title="__('No documents')" :description="__('Signed contracts, tax forms and other files for this artist appear here.')" />
        @else
            <ul class="list-unstyled mb-0">
                @foreach ($documents as $document)
                    <li class="d-flex align-items-center gap-3 px-4 py-3 border-bottom" wire:key="doc-{{ $document->id }}">
                        <x-icon :name="$document->icon()" size="22" class="text-muted" />
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('artists.documents.download', [$artist, $document]) }}" class="fw-semibold text-break">{{ $document->title }}</a>
                            <div class="small text-muted text-break">
                                {{ $document->category->label() }}
                                @if ($document->contract) &middot; {{ $document->contract->title }} @endif
                                &middot; {{ $document->original_name }} &middot; {{ $document->humanSize() }}
                                &middot; {{ App\Support\Format::date($document->created_at) }}@if ($document->uploader), {{ $document->uploader->name }}@endif
                            </div>
                        </div>
                        <a href="{{ route('artists.documents.download', [$artist, $document]) }}" class="btn btn-sm btn-light" aria-label="{{ __('Download :title', ['title' => $document->title]) }}"><x-icon name="download" size="14" /></a>
                        @if ($canManage)
                            <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $document->id }})"
                                    wire:confirm="{{ __('Delete ":title"? The file is removed permanently.', ['title' => $document->title]) }}"
                                    aria-label="{{ __('Delete :title', ['title' => $document->title]) }}"><x-icon name="trash-2" size="14" /></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
