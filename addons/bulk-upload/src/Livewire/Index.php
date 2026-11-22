<?php

namespace LabelDesk\Addons\BulkUpload\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use Illuminate\Contracts\View\View;
use LabelDesk\Addons\BulkUpload\Models\BulkUpload;
use LabelDesk\Addons\BulkUpload\Services\BulkUploadService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Catalog → Bulk upload: artists and releases from a CSV / XLSX template.
 * Step 1 checks the file and shows what will be created; step 2 imports
 * the valid rows (the file is checked again on the server).
 */
class Index extends Component
{
    use EnforcesLicense, WithFileUploads;

    #[Url(except: '')]
    public string $type = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public bool $createMissing = false;

    /** Result of the check, for display only. */
    public ?array $preview = null;

    public function mount(BulkUploadService $bulk): void
    {
        $types = $bulk->typesFor(auth()->user());
        abort_if($types === [], 403);

        if (! in_array($this->type, $types, true)) {
            $this->type = $types[0];
        }
    }

    public function updatedType(): void
    {
        abort_unless(app(BulkUploadService::class)->allows(auth()->user(), $this->type), 403);
        $this->reset('file', 'preview', 'createMissing');
        $this->resetErrorBag();
    }

    public function updatedFile(): void
    {
        $this->preview = null;
    }

    protected function guard(BulkUploadService $bulk): void
    {
        abort_unless($bulk->allows(auth()->user(), $this->type), 403);
        $this->ensureFeature($this->type === 'artists' ? 'artists' : 'releases');
        $this->ensureLicenseAllowsWrites();

        $this->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']],
            ['file.mimes' => __('Upload the template as a CSV or XLSX file.')],
            ['file' => __('file')],
        );
    }

    public function check(BulkUploadService $bulk): void
    {
        $this->guard($bulk);

        try {
            $analysis = $bulk->analyse($this->type, $this->file->getRealPath(), $this->extension(), $this->createMissing && ! auth()->user()->isArtistAccount());
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->preview = [
            'total' => $analysis['total'],
            'items' => array_slice(array_map(fn ($i) => ['row' => $i['row'], 'label' => $i['label']], $analysis['items']), 0, 100),
            'count' => count($analysis['items']),
            'errors' => array_slice($analysis['errors'], 0, 200, true),
            'errorCount' => count($analysis['errors']),
            'skipped' => array_slice($analysis['skipped'], 0, 200, true),
            'missing' => array_slice($analysis['missing'], 0, 50),
        ];
    }

    public function import(BulkUploadService $bulk): void
    {
        $this->guard($bulk);

        try {
            $upload = $bulk->import(auth()->user(), $this->type, $this->file->getRealPath(), $this->extension(), $this->file->getClientOriginalName(), $this->createMissing);
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->reset('file', 'preview', 'createMissing');
        $this->dispatch('toast', type: $upload->created_count > 0 ? 'success' : 'warning', message: trans_choice(
            '{0} Nothing was created. See the problems below.|{1} 1 :type created.|[2,*] :count :type created.',
            $upload->created_count,
            ['count' => $upload->created_count, 'type' => $this->type === 'artists' ? __('artists') : __('releases')],
        ));
    }

    protected function extension(): string
    {
        return strtolower($this->file->getClientOriginalExtension() ?: $this->file->guessExtension() ?: 'csv');
    }

    public function render(BulkUploadService $bulk): View
    {
        $user = auth()->user();

        return view('bulk-upload::index', [
            'types' => $bulk->typesFor($user),
            'columns' => $bulk->columns($this->type),
            'help' => $bulk->help($this->type),
            'artistAccount' => $user->isArtistAccount(),
            'history' => BulkUpload::query()->with('user:id,name')
                ->when(! $user->can('settings.manage'), fn ($q) => $q->where('user_id', $user->id))
                ->latest('id')->limit(10)->get(),
        ])->title(__('Bulk upload'))->layoutData(['breadcrumbs' => [['label' => __('Catalog')], ['label' => __('Bulk upload')]]]);
    }
}
