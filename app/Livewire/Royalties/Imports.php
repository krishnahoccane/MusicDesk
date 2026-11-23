<?php

namespace App\Livewire\Royalties;

use App\Enums\RoyaltyImportStatus;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\RoyaltyImport;
use App\Models\RoyaltyImportProfile;
use App\Models\RoyaltyPeriod;
use App\Services\Royalties\RoyaltyImportService;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Statements of a period: upload → map columns → process; revert.
 */
class Imports extends Component
{
    use EnforcesLicense, WithFileUploads;

    #[Locked]
    public RoyaltyPeriod $period;

    public bool $uploading = false;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $source_name = '';

    public string $currency = '';

    public string $exchange_rate = '1';

    public string $profile_id = '';

    /** Import being mapped. */
    #[Locked]
    public ?int $mappingId = null;

    /** @var array<string, int|string> */
    public array $map = [];

    public string $decimal_separator = '.';

    public string $save_profile = '';

    public function mount(RoyaltyPeriod $period): void
    {
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('royalties.view'), 403);
        $this->period = $period;
        $this->currency = (string) config('labeldesk.currency');
    }

    protected function ensureImport(): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('royalties.import'), 403);
    }

    public function startUpload(): void
    {
        $this->ensureImport();
        $this->reset('file', 'source_name', 'exchange_rate', 'profile_id');
        $this->currency = (string) config('labeldesk.currency');
        $this->uploading = true;
        $this->mappingId = null;
    }

    public function updatedProfileId(): void
    {
        $profile = ctype_digit($this->profile_id) ? RoyaltyImportProfile::query()->find((int) $this->profile_id) : null;
        if ($profile) {
            $this->source_name = $this->source_name !== '' ? $this->source_name : $profile->name;
            $this->currency = $profile->currency ?? $this->currency;
        }
    }

    public function importFile(RoyaltyImportService $imports): void
    {
        $this->ensureImport();

        $base = (string) config('labeldesk.currency');
        $this->exchange_rate = str_replace(',', '.', trim($this->exchange_rate));
        $this->validate([
            'file' => ['required', 'file', 'max:'.(int) config('labeldesk.uploads.document_max_kb', 20480), 'extensions:csv,txt,tsv,xlsx'],
            'source_name' => ['required', 'string', 'max:100'],
            'currency' => ['required', Rule::in((array) config('labeldesk.installer.currencies'))],
            'exchange_rate' => [Rule::requiredIf($this->currency !== $base), 'nullable', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'not_in:0,0.0'],
            'profile_id' => ['nullable', Rule::exists('royalty_import_profiles', 'id')],
        ], [
            'file.extensions' => __('Upload a CSV or XLSX file.'),
            'exchange_rate.regex' => __('Enter the rate as a number, e.g. 83.25.'),
        ], ['source_name' => __('source'), 'exchange_rate' => __('exchange rate')]);

        $rate = $this->currency === $base ? '1' : $this->exchange_rate;

        try {
            $import = $imports->upload(auth()->user(), $this->period, $this->file, $this->source_name, $this->currency, $rate);
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        if (ctype_digit($this->profile_id)) {
            $import->forceFill(['royalty_import_profile_id' => (int) $this->profile_id])->save();
        }

        $this->reset('file', 'uploading');
        $this->openMapping($import->id, $imports);
    }

    public function openMapping(int $id, RoyaltyImportService $imports): void
    {
        $this->ensureImport();
        $import = $this->find($id);

        try {
            $headers = $imports->reader($import)->headers();
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $map = $import->column_map
            ?: ($import->profile ? RoyaltyImportService::mapFromProfile($import->profile, $headers) : [])
            ?: RoyaltyImportService::guessMap($headers);

        $this->mappingId = $import->id;
        $this->map = collect(array_keys(RoyaltyImportService::fields()))->mapWithKeys(fn ($f) => [$f => isset($map[$f]) ? (string) $map[$f] : ''])->all();
        $this->decimal_separator = $import->profile?->decimal_separator ?? $import->decimal_separator ?? '.';
        $this->save_profile = $import->profile ? '' : $import->source_name;
        $this->resetValidation();
    }

    public function cancelMapping(): void
    {
        $this->reset('mappingId', 'map', 'save_profile');
    }

    public function confirmMapping(RoyaltyImportService $imports): void
    {
        $this->ensureImport();
        $import = $this->find((int) $this->mappingId);

        $this->validate([
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'save_profile' => ['nullable', 'string', 'max:100'],
            'map.*' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $used = array_filter($this->map, fn ($v) => $v !== '' && $v !== null);
        if (count($used) !== count(array_unique($used))) {
            $this->addError('map', __('Each column can only be used for one field.'));

            return;
        }

        try {
            $imports->configure(auth()->user(), $import, $this->map, $this->decimal_separator, $this->save_profile !== '' ? $this->save_profile : null);
        } catch (RuntimeException $e) {
            $this->addError('map', $e->getMessage());

            return;
        }

        $this->reset('mappingId', 'map', 'save_profile');
        $import->refresh();
        $this->dispatch('toast', message: $import->status === RoyaltyImportStatus::Completed
            ? __('Imported :rows sales rows.', ['rows' => number_format($import->total_rows)])
            : __('The statement is queued and will be processed within a minute.'), type: $import->status === RoyaltyImportStatus::Failed ? 'danger' : 'success');
        $this->dispatch('period-updated');
    }

    /**
     * For hosts without a running scheduler: process a queued import now.
     */
    public function processNow(int $id, RoyaltyImportService $imports): void
    {
        $this->ensureImport();
        $import = $this->find($id);
        if ($import->status !== RoyaltyImportStatus::Queued) {
            return;
        }

        @set_time_limit(600);
        $imports->process($import);
        $import->refresh();

        $this->dispatch('toast', message: $import->status === RoyaltyImportStatus::Completed
            ? __('Imported :rows sales rows.', ['rows' => number_format($import->total_rows)])
            : ($import->error_message ?? __('Processing failed.')), type: $import->status === RoyaltyImportStatus::Completed ? 'success' : 'danger');
        $this->dispatch('period-updated');
    }

    public function revert(int $id, RoyaltyImportService $imports): void
    {
        $this->ensureImport();

        try {
            $imports->revert(auth()->user(), $this->find($id));
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', message: __('Statement reverted. Its sales were removed from this period.'), type: 'success');
        $this->dispatch('period-updated');
    }

    protected function find(int $id): RoyaltyImport
    {
        return RoyaltyImport::query()->with(['period', 'profile', 'creator'])->where('royalty_period_id', $this->period->id)->find($id) ?? abort(404);
    }

    public function render(RoyaltyImportService $service): View
    {
        $imports = $this->period->imports()->with('creator:id,name,deleted_at')->latest('id')->get();

        $mapping = null;
        if ($this->mappingId) {
            $import = $this->find($this->mappingId);
            $reader = $service->reader($import);
            $preview = $reader->preview(5);
            $amountIndex = $this->map['amount'] ?? '';
            $mapping = [
                'import' => $import,
                'headers' => $reader->headers(),
                'preview' => $preview,
                'parsed' => $amountIndex === '' ? [] : array_map(fn ($row) => Decimal::parse($row[(int) $amountIndex] ?? null, $this->decimal_separator), $preview),
            ];
        }

        return view('livewire.royalties.imports', [
            'imports' => $imports,
            'busy' => $imports->contains(fn ($i) => $i->status->isBusy()),
            'mapping' => $mapping,
            'fields' => RoyaltyImportService::fields(),
            'profiles' => RoyaltyImportProfile::query()->orderBy('name')->pluck('name', 'id'),
            'currencies' => (array) config('labeldesk.installer.currencies'),
            'baseCurrency' => (string) config('labeldesk.currency'),
            'canImport' => auth()->user()->can('royalties.import') && ! $this->period->isLocked(),
            'maxMb' => (int) round(config('labeldesk.uploads.document_max_kb', 20480) / 1024),
        ]);
    }
}
