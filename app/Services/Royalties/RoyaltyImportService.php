<?php

namespace App\Services\Royalties;

use App\Enums\ReleaseType;
use App\Enums\RoyaltyImportStatus;
use App\Exceptions\CatalogException;
use App\Jobs\ProcessRoyaltyImport;
use App\Models\Release;
use App\Models\RoyaltyImport;
use App\Models\RoyaltyImportProfile;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Models\Track;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Support\Decimal;
use App\Support\Identifiers;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sales statement imports: upload → map columns → process (queued) → match
 * lines to tracks (ISRC) or releases (UPC). Reverting removes the lines but
 * keeps the import record and file.
 */
class RoyaltyImportService
{
    public const DISK = 'local';

    /** Mappable fields: key => [label, required]. */
    public static function fields(): array
    {
        return [
            'isrc' => [__('ISRC'), false],
            'upc' => [__('UPC / EAN'), false],
            'amount' => [__('Net revenue'), true],
            'units' => [__('Units / streams'), false],
            'store' => [__('Store'), false],
            'territory' => [__('Territory / country'), false],
            'sale_date' => [__('Sale date'), false],
        ];
    }

    public function __construct(
        protected ActivityLogger $activity,
        protected RoyaltyCalculator $calculator,
    ) {}

    public function upload(User $actor, RoyaltyPeriod $period, UploadedFile $file, string $source, string $currency, string $rate): RoyaltyImport
    {
        $this->ensureOpen($period);

        $extension = Str::lower($file->getClientOriginalExtension());
        $hash = hash_file('sha256', $file->getRealPath());

        $duplicate = RoyaltyImport::query()->where('file_hash', $hash)
            ->whereNotIn('status', [RoyaltyImportStatus::Reverted->value, RoyaltyImportStatus::Failed->value])
            ->with('period:id,name')->first();
        if ($duplicate) {
            throw new CatalogException(__('This exact file was already imported into :period (":source"). Revert that import first if you want to load it again.', [
                'period' => $duplicate->period->name, 'source' => $duplicate->source_name,
            ]));
        }

        // Make sure it is readable before keeping it.
        StatementReader::for($file->getRealPath(), $extension)->headers();

        $path = $file->storeAs('royalties/imports', Str::uuid().'.'.$extension, ['disk' => self::DISK]);
        if ($path === false) {
            throw new CatalogException(__('The file could not be saved. Check that the storage folder is writable.'));
        }

        $import = new RoyaltyImport([
            'source_name' => trim($source),
            'currency' => strtoupper($currency),
            'exchange_rate' => $rate,
        ]);
        $import->forceFill([
            'royalty_period_id' => $period->id,
            'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
            'file_path' => $path,
            'file_hash' => $hash,
            'created_by' => $actor->id,
        ])->save();

        $this->activity->log('royalty.import_uploaded', __('Uploaded statement ":file" to :period', ['file' => $import->original_name, 'period' => $period->name]), $import);

        return $import;
    }

    public function reader(RoyaltyImport $import): StatementReader
    {
        return StatementReader::for(Storage::disk(self::DISK)->path($import->file_path), pathinfo($import->file_path, PATHINFO_EXTENSION));
    }

    /**
     * Saves the column mapping (optionally as a reusable profile) and queues processing.
     *
     * @param  array<string, int|null>  $map  field => column index
     */
    public function configure(User $actor, RoyaltyImport $import, array $map, string $decimalSeparator, ?string $profileName = null): RoyaltyImport
    {
        $this->ensureOpen($import->period);
        if (! in_array($import->status, [RoyaltyImportStatus::Uploaded, RoyaltyImportStatus::Failed], true)) {
            throw new CatalogException(__('This import has already been processed.'));
        }

        $map = array_filter(array_intersect_key($map, self::fields()), fn ($v) => $v !== null && $v !== '');
        $map = array_map('intval', $map);

        if (! isset($map['amount'])) {
            throw new CatalogException(__('Choose the column that holds the net revenue.'));
        }
        if (! isset($map['isrc']) && ! isset($map['upc'])) {
            throw new CatalogException(__('Choose the ISRC column, the UPC column, or both, so sales can be matched to your catalogue.'));
        }

        $profile = null;
        if (filled($profileName)) {
            $profile = RoyaltyImportProfile::query()->updateOrCreate(
                ['name' => trim($profileName)],
                ['column_map' => $this->mapByHeader($import, $map), 'decimal_separator' => $decimalSeparator, 'currency' => $import->currency],
            );
        }

        $import->forceFill([
            'column_map' => $map,
            'decimal_separator' => $decimalSeparator === ',' ? ',' : '.',
            'royalty_import_profile_id' => $profile?->id ?? $import->royalty_import_profile_id,
            'status' => RoyaltyImportStatus::Queued,
            'error_message' => null,
        ])->save();

        ProcessRoyaltyImport::dispatch($import->id);

        return $import->refresh();
    }

    /**
     * Profiles store header names so they work even if columns move.
     *
     * @param  array<string, int>  $map
     * @return array<string, string>
     */
    protected function mapByHeader(RoyaltyImport $import, array $map): array
    {
        $headers = $this->reader($import)->headers();

        return array_map(fn (int $i) => $headers[$i] ?? '', $map);
    }

    /**
     * Applies a saved profile to this file's headers (case-insensitive).
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function mapFromProfile(RoyaltyImportProfile $profile, array $headers): array
    {
        $lower = array_map(fn ($h) => Str::lower(trim($h)), $headers);
        $map = [];
        foreach ((array) $profile->column_map as $field => $header) {
            $index = array_search(Str::lower(trim((string) $header)), $lower, true);
            if ($index !== false) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    /**
     * Guesses the mapping from common header names.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function guessMap(array $headers): array
    {
        $patterns = [
            'isrc' => '/\bisrc\b/i',
            'upc' => '/\b(upc|ean|barcode)\b/i',
            'amount' => '/(net|payable|royalt|earning|revenue|amount)/i',
            'territory' => '/\b(territory|country|region)\b/i',
            'units' => '/\b(units?|quantity|qty|streams|plays|count)\b/i',
            'store' => '/\b(store|dsp|service|platform|retailer|shop)\b/i',
            'sale_date' => '/(sale|sales|report|transaction|period).*date|^date$|month/i',
        ];

        $map = [];
        foreach ($patterns as $field => $pattern) {
            foreach ($headers as $i => $header) {
                if (! in_array($i, $map, true) && preg_match($pattern, $header)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Imports all rows. Called by the queued job (or directly with "Process now").
     */
    public function process(RoyaltyImport $import): void
    {
        $import->refresh();
        if (! in_array($import->status, [RoyaltyImportStatus::Queued, RoyaltyImportStatus::Processing], true)) {
            return;
        }

        $import->forceFill(['status' => RoyaltyImportStatus::Processing])->save();
        RoyaltyLine::query()->where('royalty_import_id', $import->id)->delete();

        $map = (array) $import->column_map;
        $separator = (string) $import->decimal_separator;
        $rate = (string) $import->exchange_rate;
        $defaultDate = $import->period->ends_on->toDateString();

        $trackCache = [];
        $releaseCache = [];
        $buffer = [];
        $total = 0;
        $matched = 0;
        $skipped = 0;
        $netTotal = '0';
        $netBase = '0';

        try {
            foreach ($this->reader($import)->rows() as [$rowNumber, $values]) {
                $cell = fn (string $field) => isset($map[$field]) ? ($values[$map[$field]] ?? null) : null;

                $amount = Decimal::parse($cell('amount'), $separator);
                $isrc = Identifiers::normalizeIsrc(is_scalar($cell('isrc')) ? (string) $cell('isrc') : null);
                $upc = Identifiers::normalizeUpc(is_scalar($cell('upc')) ? preg_replace('/\.0+$/', '', (string) $cell('upc')) : null);

                // Rows without an amount (sub-totals, notes) are skipped and counted.
                if ($amount === null || ($isrc === null && $upc === null)) {
                    $skipped++;

                    continue;
                }

                $total++;
                $trackId = null;
                $releaseId = null;

                if ($isrc !== null && Identifiers::isValidIsrc($isrc)) {
                    [$trackId, $releaseId] = $trackCache[$isrc] ??= $this->findTrack($isrc);
                } else {
                    $isrc = $isrc !== null ? substr($isrc, 0, 12) : null;
                }
                if ($trackId === null && $upc !== null) {
                    $releaseId = $releaseCache[$upc] ??= Release::withTrashed()->where('upc', $upc)->value('id');
                }

                $amount = Decimal::round($amount, 6);
                $base = Decimal::mul($amount, $rate);
                $netTotal = Decimal::add($netTotal, $amount);
                $netBase = Decimal::add($netBase, $base);
                $isMatched = $trackId !== null || $releaseId !== null;
                $matched += $isMatched ? 1 : 0;

                $buffer[] = [
                    'royalty_import_id' => $import->id,
                    'royalty_period_id' => $import->royalty_period_id,
                    'row_number' => $rowNumber,
                    'track_id' => $trackId,
                    'release_id' => $releaseId,
                    'isrc' => $isrc,
                    'upc' => $upc !== null ? substr($upc, 0, 14) : null,
                    'store' => $this->text($cell('store'), 100),
                    'territory' => $this->text($cell('territory'), 50),
                    'sale_date' => $this->date($cell('sale_date')) ?? $defaultDate,
                    'units' => (int) (Decimal::parse($cell('units')) ?? 0),
                    'net_amount' => $amount,
                    'net_amount_base' => $base,
                    'match_status' => $isMatched ? 'matched' : 'unmatched',
                ];

                if (count($buffer) >= 500) {
                    RoyaltyLine::query()->insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer) {
                RoyaltyLine::query()->insert($buffer);
            }

            if ($total === 0) {
                throw new CatalogException(__('No sales rows were found. Check the column mapping and the decimal separator.'));
            }

            $import->forceFill([
                'status' => RoyaltyImportStatus::Completed,
                'total_rows' => $total,
                'matched_rows' => $matched,
                'skipped_rows' => $skipped,
                'net_total' => $netTotal,
                'net_total_base' => $netBase,
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            RoyaltyLine::query()->where('royalty_import_id', $import->id)->delete();
            $import->forceFill([
                'status' => RoyaltyImportStatus::Failed,
                'error_message' => Str::limit($e instanceof CatalogException ? $e->getMessage() : __('Processing failed: :error', ['error' => $e->getMessage()]), 1000),
            ])->save();
            report($e);

            return;
        }

        $this->calculator->recalculate($import->period);

        $this->activity->log('royalty.import_processed', __('Imported :rows sales rows from ":file" (:matched matched)', [
            'rows' => $total, 'file' => $import->original_name, 'matched' => $matched,
        ]), $import, ['net_total_base' => $netBase], $import->creator);
    }

    /**
     * Track by ISRC. If several tracks share it (compilation re-use), the
     * original (non-compilation) release wins.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function findTrack(string $isrc): array
    {
        $track = Track::query()
            ->join('releases', 'releases.id', '=', 'tracks.release_id')
            ->where('tracks.isrc', $isrc)
            ->orderByRaw('case when releases.type = ? then 1 else 0 end', [ReleaseType::Compilation->value])
            ->orderBy('tracks.id')
            ->first(['tracks.id', 'tracks.release_id']);

        return $track ? [$track->id, $track->release_id] : [null, null];
    }

    public function revert(User $actor, RoyaltyImport $import): void
    {
        $this->ensureOpen($import->period);
        if ($import->status->isBusy()) {
            throw new CatalogException(__('Wait until processing has finished.'));
        }

        DB::transaction(function () use ($import) {
            RoyaltyLine::query()->where('royalty_import_id', $import->id)->delete();
            $import->forceFill(['status' => RoyaltyImportStatus::Reverted])->save();
        });

        $this->calculator->recalculate($import->period);
        $this->activity->log('royalty.import_reverted', __('Reverted statement ":file" in :period', ['file' => $import->original_name, 'period' => $import->period->name]), $import);
    }

    /**
     * Assigns all unmatched lines of a period with this ISRC or UPC to a track.
     */
    public function match(User $actor, RoyaltyPeriod $period, string $kind, string $key, Track $track): int
    {
        $this->ensureOpen($period);
        $column = $kind === 'upc' ? 'upc' : 'isrc';

        $count = RoyaltyLine::query()
            ->where('royalty_period_id', $period->id)
            ->where('match_status', 'unmatched')
            ->where($column, $key)
            ->when($column === 'upc', fn ($q) => $q->whereNull('isrc'))
            ->update(['track_id' => $track->id, 'release_id' => $track->release_id, 'match_status' => 'matched']);

        $this->refreshImportCounts($period);
        $this->calculator->recalculate($period);
        $this->activity->log('royalty.lines_matched', __('Matched :count lines (:key) to ":track"', ['count' => $count, 'key' => $key, 'track' => $track->displayTitle()]), $track);

        return $count;
    }

    public function ignore(User $actor, RoyaltyPeriod $period, string $kind, string $key): int
    {
        $this->ensureOpen($period);
        $column = $kind === 'upc' ? 'upc' : 'isrc';

        $count = RoyaltyLine::query()
            ->where('royalty_period_id', $period->id)
            ->where('match_status', 'unmatched')
            ->where($column, $key)
            ->when($column === 'upc', fn ($q) => $q->whereNull('isrc'))
            ->update(['match_status' => 'ignored']);

        $this->activity->log('royalty.lines_ignored', __('Marked :count lines (:key) in :period as not ours', ['count' => $count, 'key' => $key, 'period' => $period->name]), $period);

        return $count;
    }

    protected function refreshImportCounts(RoyaltyPeriod $period): void
    {
        foreach ($period->imports()->where('status', RoyaltyImportStatus::Completed->value)->get() as $import) {
            $import->forceFill(['matched_rows' => $import->lines()->where('match_status', 'matched')->count()])->save();
        }
    }

    public function ensureOpen(RoyaltyPeriod $period): void
    {
        if ($period->isLocked()) {
            throw new CatalogException(__('The period :name is locked and cannot be changed.', ['name' => $period->name]));
        }
    }

    protected function text(mixed $value, int $max): ?string
    {
        if ($value === null || is_object($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : Str::limit($text, $max, '');
    }

    protected function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $text = trim((string) $value);
        foreach (['Y-m-d', 'Y/m/d', 'd.m.Y', 'd-m-Y', 'Y-m', 'Ym', 'Y-m-d H:i:s'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);
                if ($date && $date->format($format) === $text) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        return null;
    }
}
