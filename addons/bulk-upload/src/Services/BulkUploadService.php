<?php

namespace LabelDesk\Addons\BulkUpload\Services;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use LabelDesk\Addons\BulkUpload\Models\BulkUpload;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who may bulk upload what, templates, and running an upload.
 *
 * Administrators (settings.manage) always may. Other staff need
 * "catalog.bulk_upload" and the "staff" switch (Settings → Bulk upload);
 * artist accounts need the "artists" switch and can only upload releases
 * for their own artists.
 */
class BulkUploadService
{
    public const TYPES = ['artists', 'releases'];

    public function __construct(
        protected SettingsService $settings,
        protected ArtistBulkImporter $artistImporter,
        protected ReleaseBulkImporter $releaseImporter,
        protected ActivityLogger $activity,
    ) {}

    public function enabledFor(string $audience): bool
    {
        return (bool) $this->settings->get('bulk_upload.'.$audience, $audience === 'staff');
    }

    public function allows(User $user, string $type): bool
    {
        if (! in_array($type, self::TYPES, true)) {
            return false;
        }

        $creates = $type === 'artists' ? 'artists.create' : 'releases.create';

        // The Artist role has a fixed permission set (synced by core on every
        // upgrade), so for artist accounts the "artists" switch is the control.
        if ($user->isArtistAccount()) {
            return $type === 'releases' && $this->enabledFor('artists') && $user->can($creates);
        }

        if ($user->can('settings.manage')) {
            return $user->can($creates);
        }

        return $this->enabledFor('staff') && $user->can('catalog.bulk_upload') && $user->can($creates);
    }

    /**
     * @return list<string>
     */
    public function typesFor(User $user): array
    {
        return array_values(array_filter(self::TYPES, fn ($t) => $this->allows($user, $t)));
    }

    public function columns(string $type): array
    {
        return $type === 'artists' ? ArtistBulkImporter::columns() : ReleaseBulkImporter::columns();
    }

    public function help(string $type): array
    {
        return $type === 'artists' ? ArtistBulkImporter::help() : ReleaseBulkImporter::help();
    }

    public function analyse(string $type, string $path, string $extension, bool $createMissing = false): array
    {
        $rows = BulkSheet::read($path, $extension, $this->columns($type));
        $analysis = $type === 'artists'
            ? $this->artistImporter->analyse($rows)
            : $this->releaseImporter->analyse($rows, $createMissing);

        return $analysis + ['total' => count($rows), 'missing' => $analysis['missing'] ?? []];
    }

    public function import(User $user, string $type, string $path, string $extension, string $originalName, bool $createMissing = false): BulkUpload
    {
        $analysis = $this->analyse($type, $path, $extension, $createMissing && ! $user->isArtistAccount());
        $result = $type === 'artists'
            ? $this->artistImporter->import($user, $analysis)
            : $this->releaseImporter->import($user, $analysis);

        $errors = $analysis['errors'];
        foreach ($result['failed'] as $row => $message) {
            $errors[$row][] = $message;
        }
        foreach ($analysis['skipped'] as $row => $message) {
            $errors[$row][] = $message;
        }
        ksort($errors);

        $upload = BulkUpload::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'original_name' => mb_substr($originalName, 0, 255),
            'total_rows' => $analysis['total'],
            'created_count' => $result['created'],
            'skipped_count' => count($errors), // rows not imported (invalid, duplicate or failed)
            'errors' => array_slice($errors, 0, 500, true),
        ]);

        $this->activity->log('bulk_upload.completed', trans_choice(
            '{1} Bulk upload: 1 :type created from :file|[2,*] Bulk upload: :count :type created from :file',
            $result['created'],
            ['count' => $result['created'], 'type' => $type === 'artists' ? __('artists') : __('releases'), 'file' => $upload->original_name],
        ), null, ['type' => $type, 'rows' => $analysis['total'], 'created' => $result['created']], $user);

        return $upload;
    }

    public function template(string $type, string $format): StreamedResponse
    {
        $columns = $this->columns($type);
        $filename = "labeldesk-{$type}-template.{$format}";

        return response()->streamDownload(function () use ($columns, $format) {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_map(fn ($c) => $c[0].($c[1] ? ' *' : ''), array_values($columns)), (new Style)->setFontBold()));
            $writer->addRow(Row::fromValues(array_map(fn ($c) => $c[2], array_values($columns))));
            $writer->close();
        }, $filename, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
