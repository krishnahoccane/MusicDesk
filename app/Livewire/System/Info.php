<?php

namespace App\Livewire\System;

use App\Services\Backup\BackupService;
use App\Services\Installation\RequirementsChecker;
use App\Services\System\AutomaticTasks;
use App\Services\System\UpgradeService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Throwable;

/**
 * Administration → System: versions, server, database, storage, background
 * jobs, and the database upgrade after new files are uploaded.
 */
class Info extends Component
{
    public function upgrade(UpgradeService $upgrade): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        try {
            $result = $upgrade->run(auth()->user());
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'error', message: __('The upgrade did not finish: :reason. Your data was not deleted; restore your backup if needed and contact support.', ['reason' => $e->getMessage()]));

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Upgraded to :version (:count database changes).', ['version' => $result['to'], 'count' => count($result['migrations'])]));
    }

    /**
     * @return list<array{label: string, value: string, state?: string}>
     */
    protected function section(array $items): array
    {
        return array_values(array_filter($items));
    }

    public function render(UpgradeService $upgrade, BackupService $backups): View
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $dbVersion = rescue(fn () => $driver === 'sqlite' ? $connection->selectOne('select sqlite_version() as v')->v : $connection->selectOne('select version() as v')->v, '—', false);
        $dbSize = $driver === 'sqlite' ? null : rescue(fn () => (int) $connection->selectOne(
            'select coalesce(sum(data_length + index_length), 0) as s from information_schema.tables where table_schema = database()'
        )->s, null, false);

        $lastAuto = Cache::get(AutomaticTasks::LAST_RUN_KEY);
        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $latestBackup = $backups->list()[0] ?? null;
        $free = @disk_free_space(storage_path());
        $missing = array_values(array_filter(RequirementsChecker::REQUIRED_EXTENSIONS, fn ($ext) => ! extension_loaded($ext)));
        $production = app()->environment('production');

        $sections = [
            ['title' => __('Application'), 'icon' => 'package', 'items' => $this->section([
                ['label' => config('labeldesk.product.name'), 'value' => (string) config('labeldesk.product.version')],
                ['label' => __('Database version'), 'value' => $upgrade->installedVersion() ?? '—'],
                ['label' => 'Laravel', 'value' => Application::VERSION],
                ['label' => __('Environment'), 'value' => app()->environment(), 'state' => $production ? 'ok' : 'warning'],
                ['label' => __('Debug mode'), 'value' => config('app.debug') ? __('On') : __('Off'), 'state' => config('app.debug') && $production ? 'danger' : 'ok'],
                ['label' => __('Application URL'), 'value' => (string) config('app.url')],
                ['label' => __('Timezone'), 'value' => (string) config('labeldesk.display_timezone')],
            ])],
            ['title' => __('Server'), 'icon' => 'server', 'items' => $this->section([
                ['label' => 'PHP', 'value' => PHP_VERSION, 'state' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'danger'],
                ['label' => __('Memory limit'), 'value' => (string) ini_get('memory_limit')],
                ['label' => __('Max execution time'), 'value' => ini_get('max_execution_time').' s'],
                ['label' => __('Upload limit'), 'value' => ini_get('upload_max_filesize').' / '.ini_get('post_max_size')],
                ['label' => __('Required extensions'), 'value' => $missing === [] ? __('All present') : __('Missing: :list', ['list' => implode(', ', $missing)]), 'state' => $missing === [] ? 'ok' : 'danger'],
                ['label' => __('Operating system'), 'value' => PHP_OS_FAMILY],
            ])],
            ['title' => __('Database'), 'icon' => 'database', 'items' => $this->section([
                ['label' => __('Driver'), 'value' => $driver],
                ['label' => __('Server version'), 'value' => (string) $dbVersion],
                $dbSize !== null ? ['label' => __('Size'), 'value' => Format::bytes($dbSize)] : null,
                ['label' => __('Pending database changes'), 'value' => (string) count($upgrade->pendingMigrations()), 'state' => $upgrade->pendingMigrations() === [] ? 'ok' : 'warning'],
            ])],
            ['title' => __('Storage & backups'), 'icon' => 'hard-drive', 'items' => $this->section([
                ['label' => 'storage/app/private', 'value' => is_writable(storage_path('app/private')) ? __('Writable') : __('Not writable'), 'state' => is_writable(storage_path('app/private')) ? 'ok' : 'danger'],
                ['label' => 'bootstrap/cache', 'value' => is_writable(base_path('bootstrap/cache')) ? __('Writable') : __('Not writable'), 'state' => is_writable(base_path('bootstrap/cache')) ? 'ok' : 'danger'],
                $free !== false ? ['label' => __('Free disk space'), 'value' => Format::bytes((int) $free), 'state' => $free < 1024 ** 3 ? 'warning' : 'ok'] : null,
                ['label' => __('Latest backup'), 'value' => $latestBackup ? Format::datetime($latestBackup['created_at']) : __('None yet'),
                    'state' => $latestBackup && $latestBackup['created_at']->gt(now()->subDays(8)) ? 'ok' : 'warning'],
            ])],
            ['title' => __('Background tasks'), 'icon' => 'cpu', 'items' => $this->section([
                ['label' => __('Automatic tasks'), 'value' => $lastAuto ? __('Last ran :time', ['time' => CarbonImmutable::createFromTimestamp((int) $lastAuto)->diffForHumans()]) : __('Not run yet'), 'state' => $lastAuto ? 'ok' : 'warning'],
                ['label' => __('Queued jobs'), 'value' => (string) $pendingJobs, 'state' => $pendingJobs > 20 ? 'warning' : 'ok'],
                ['label' => __('Failed jobs'), 'value' => (string) $failedJobs, 'state' => $failedJobs > 0 ? 'warning' : 'ok'],
                ['label' => __('Email'), 'value' => config('mail.default') === 'log' ? __('Log only (not sent)') : (string) config('mail.default'), 'state' => config('mail.default') === 'log' ? 'warning' : 'ok'],
                ['label' => __('Nightly backup'), 'value' => $backups->scheduled() ? __('On') : __('Off')],
            ])],
        ];

        return view('livewire.system.info', [
            'sections' => $sections,
            'upgradeNeeded' => $upgrade->needed(),
            'upgradeFailure' => $upgrade->failure(),
            'pending' => $upgrade->pendingMigrations(),
            'installedVersion' => $upgrade->installedVersion(),
            'tasks' => collect(AutomaticTasks::all())->except('license')->map(fn ($minutes, $task) => [
                'every' => $minutes,
                'last' => AutomaticTasks::lastRun($task),
            ])->all(),
        ])->title(__('System'))->layoutData(['breadcrumbs' => [['label' => __('Administration')], ['label' => __('System')]]]);
    }
}
