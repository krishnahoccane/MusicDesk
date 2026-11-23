<?php

namespace App\Livewire\Artists;

use App\Enums\DocumentCategory;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Models\ArtistDocument;
use App\Services\DocumentService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

class Documents extends Component
{
    use EnforcesLicense, WithFileUploads;

    #[Locked]
    public Artist $artist;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $title = '';

    public string $category = 'contract';

    public string $contract_id = '';

    #[Url(as: 'doc_category', except: '')]
    public string $filter = '';

    public function mount(Artist $artist): void
    {
        $this->ensureFeature('documents');
        abort_unless(auth()->user()->can('documents.view'), 403);
        $this->artist = $artist;
    }

    public function updatedFile(): void
    {
        $this->validateOnly('file', $this->uploadRules());

        if ($this->title === '' && $this->file) {
            $this->title = (string) str(pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME))
                ->replace(['_', '-'], ' ')->squish()->limit(200, '');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadRules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.DocumentService::maxKilobytes(), 'extensions:'.DocumentService::extensions()],
        ];
    }

    public function storeDocument(DocumentService $documents): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('documents');
        $this->authorize('manageDocuments', $this->artist);

        $this->validate([
            ...$this->uploadRules(),
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'contract_id' => ['nullable', Rule::exists('artist_contracts', 'id')->where('artist_id', $this->artist->id)->whereNull('deleted_at')],
        ], [], ['contract_id' => __('contract')]);

        try {
            $documents->store(auth()->user(), $this->artist, $this->file, $this->title, $this->category, $this->contract_id !== '' ? (int) $this->contract_id : null);
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->reset('file', 'title', 'category', 'contract_id');
        $this->dispatch('toast', message: __('Document uploaded.'), type: 'success');
        $this->dispatch('artist-updated');
    }

    public function delete(int $id, DocumentService $documents): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('documents');
        $this->authorize('manageDocuments', $this->artist);

        $document = ArtistDocument::query()->where('artist_id', $this->artist->id)->find($id) ?? abort(404);
        $documents->delete(auth()->user(), $this->artist, $document);
        $this->dispatch('toast', message: __('Document deleted.'), type: 'success');
        $this->dispatch('artist-updated');
    }

    public function render(): View
    {
        return view('livewire.artists.documents', [
            'documents' => ArtistDocument::query()
                ->where('artist_id', $this->artist->id)
                ->with(['contract:id,title,deleted_at', 'uploader:id,name,deleted_at'])
                ->when(in_array($this->filter, DocumentCategory::values(), true), fn ($q) => $q->where('category', $this->filter))
                ->latest('id')
                ->get(),
            'categories' => DocumentCategory::options(),
            'contracts' => $this->artist->contracts()->orderBy('title')->pluck('title', 'id'),
            'canManage' => auth()->user()->can('manageDocuments', $this->artist),
            'maxMb' => (int) round(DocumentService::maxKilobytes() / 1024),
            'accept' => '.'.str_replace(',', ',.', DocumentService::extensions()),
        ]);
    }
}
