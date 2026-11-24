<?php

use App\Http\Controllers\Admin\ThemePreferenceController;
use App\Http\Controllers\Artists\ArtistFileController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\Catalog\CatalogFileController;
use App\Http\Controllers\License\LicenseStatusController;
use App\Http\Controllers\LoginPreviewController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\Royalties\StatementController;
use App\Livewire\Activity\Index as ActivityIndex;
use App\Livewire\Artists\Form as ArtistForm;
use App\Livewire\Artists\Index as ArtistIndex;
use App\Livewire\Artists\Show as ArtistShow;
use App\Livewire\Backups\Index as BackupIndex;
use App\Livewire\Contracts\Index as ContractIndex;
use App\Livewire\Contributors\Index as ContributorIndex;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Distribution\Dsps as DspIndex;
use App\Livewire\Distribution\Index as DistributionIndex;
use App\Livewire\Plugins\Index;
use App\Livewire\Profile\Edit as ProfileEdit;
use App\Livewire\Releases\Form as ReleaseForm;
use App\Livewire\Releases\Index as ReleaseIndex;
use App\Livewire\Releases\Show as ReleaseShow;
use App\Livewire\Reports\Index as ReportIndex;
use App\Livewire\Reports\SalesImports as SalesReportImports;
use App\Livewire\Reports\Show as ReportShow;
use App\Livewire\Roles\Form as RoleForm;
use App\Livewire\Roles\Index as RoleIndex;
use App\Livewire\Royalties\Balances as RoyaltyBalances;
use App\Livewire\Royalties\Index as RoyaltyIndex;
use App\Livewire\Royalties\PeriodShow as RoyaltyPeriodShow;
use App\Livewire\Settings\Appearance as AppearanceSettings;
use App\Livewire\Settings\Catalog as CatalogSettings;
use App\Livewire\Settings\General as GeneralSettings;
use App\Livewire\Settings\Mail as MailSettings;
use App\Livewire\System\Info as SystemInfo;
use App\Livewire\Tracks\Form as TrackForm;
use App\Livewire\Tracks\Index as TrackIndex;
use App\Livewire\Users\Form as UserForm;
use App\Livewire\Users\Index as UserIndex;
use App\Support\Appearance;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authenticated application routes
|--------------------------------------------------------------------------
|
| Every route here requires an authenticated, active user and passes through
| the license policy (read-only when the license is not usable). Module
| routes add their own permission middleware and policies on top.
|
*/

Route::middleware(['auth', 'auth.session', 'active', 'license'])->group(function () {
    Route::get('dashboard', Overview::class)->name('dashboard');

    Route::get('profile', ProfileEdit::class)->name('profile');

    // Catalog → Artists (license feature "artists")
    Route::middleware('feature:artists')->group(function () {
        Route::get('artists', ArtistIndex::class)->middleware('permission:artists.view')->name('artists.index');
        Route::get('artists/create', ArtistForm::class)->middleware('permission:artists.create')->name('artists.create');
        Route::get('artists/{artist}', ArtistShow::class)->middleware('permission:artists.view')->withTrashed()->name('artists.show');
        Route::get('artists/{artist}/edit', ArtistForm::class)->middleware('permission:artists.update')->name('artists.edit');
        Route::get('artists/{artist}/photo', [ArtistFileController::class, 'photo'])->middleware('permission:artists.view')->withTrashed()->name('artists.photo');

        // Contracts & documents (license feature "documents")
        Route::get('artists/{artist}/documents/{document}/download', [ArtistFileController::class, 'document'])
            ->middleware(['feature:documents', 'permission:documents.view', 'throttle:60,1'])
            ->withTrashed()
            ->name('artists.documents.download');
        Route::get('contracts', ContractIndex::class)->middleware(['feature:documents', 'permission:contracts.view'])->name('contracts.index');
    });

    // Catalog → Releases & contributors (license feature "releases")
    Route::middleware('feature:releases')->group(function () {
        Route::get('releases', ReleaseIndex::class)->middleware('permission:releases.view')->name('releases.index');
        Route::get('releases/create', ReleaseForm::class)->middleware('permission:releases.create')->name('releases.create');
        Route::get('releases/{release}', ReleaseShow::class)->middleware('permission:releases.view')->withTrashed()->name('releases.show');
        Route::get('releases/{release}/edit', ReleaseForm::class)->middleware('permission:releases.update')->name('releases.edit');
        Route::get('releases/{release}/artwork', [CatalogFileController::class, 'artwork'])->middleware('permission:releases.view')->withTrashed()->name('releases.artwork');
        Route::get('contributors', ContributorIndex::class)->middleware('permission:contributors.view')->name('contributors.index');
        Route::get('labels', App\Livewire\Labels\Index::class)->middleware('permission:labels.view')->name('labels.index');

        // Tracks (license feature "tracks")
        Route::middleware('feature:tracks')->group(function () {
            Route::get('tracks', TrackIndex::class)->middleware('permission:tracks.view')->name('tracks.index');
            Route::get('releases/{release}/tracks/create', TrackForm::class)->middleware('permission:tracks.create')->name('releases.tracks.create');
            Route::get('tracks/{track}', TrackForm::class)->middleware('permission:tracks.view')->name('tracks.show');
            Route::get('tracks/{track}/edit', TrackForm::class)->middleware('permission:tracks.update')->name('tracks.edit');
            Route::get('tracks/{track}/audio', [CatalogFileController::class, 'audio'])->middleware('permission:tracks.download_master')->name('tracks.audio');
            Route::get('tracks/{track}/download', [CatalogFileController::class, 'download'])
                ->middleware(['permission:tracks.download_master', 'throttle:30,1'])->name('tracks.download');
        });
    });

    // Business → Distribution (license feature "distribution")
    Route::middleware('feature:distribution')->group(function () {
        Route::get('distribution', DistributionIndex::class)->middleware('permission:distribution.view')->name('distribution.index');
        Route::get('distribution/stores', DspIndex::class)->middleware('permission:dsps.manage')->name('distribution.dsps');
    });

    // Royalty statements: royalty staff, and artist accounts (payments.view) for their own artists.
    Route::middleware(['feature:royalties', 'permission:royalties.view|payments.view'])->group(function () {
        Route::get('royalties/periods/{period}/statements/{artist}', [StatementController::class, 'show'])->withTrashed()->name('royalties.statements.show');
        Route::get('royalties/periods/{period}/statements/{artist}/export/{format}', [StatementController::class, 'export'])
            ->withTrashed()->middleware('throttle:30,1')->whereIn('format', ['csv', 'xlsx'])->name('royalties.statements.export');
    });

    // Business → Royalties (license feature "royalties")
    Route::middleware(['feature:royalties', 'permission:royalties.view'])->group(function () {
        Route::get('royalties', RoyaltyIndex::class)->name('royalties.index');
        Route::get('royalties/periods/{period}', RoyaltyPeriodShow::class)->name('royalties.periods.show');
        Route::get('royalties/balances', RoyaltyBalances::class)->middleware('permission:payments.view')->name('royalties.balances');
    });

    // Reports → Import sales reports (registered before reports/{report})
    Route::get('reports/import', SalesReportImports::class)
        ->middleware(['feature:royalties', 'permission:royalties.import'])->name('reports.import');

    // Business → Reports (license feature "reports"; each report adds its own features and permissions)
    Route::middleware(['feature:reports', 'permission:reports.view'])->group(function () {
        Route::get('reports', ReportIndex::class)->name('reports.index');
        Route::get('reports/{report}', ReportShow::class)->where('report', '[a-z\-]+')->name('reports.show');
        Route::get('reports/{report}/export/{format}', ReportExportController::class)
            ->where('report', '[a-z\-]+')->whereIn('format', ['csv', 'xlsx'])
            ->middleware(['permission:reports.export', 'throttle:30,1'])->name('reports.export');
    });

    // Administration
    Route::get('users', UserIndex::class)->middleware('permission:users.view')->name('users.index');
    Route::get('users/create', UserForm::class)->middleware('permission:users.manage')->name('users.create');
    Route::get('users/{user}/edit', UserForm::class)->middleware('permission:users.manage')->name('users.edit');

    Route::get('roles', RoleIndex::class)->middleware('permission:roles.view')->name('roles.index');
    Route::get('roles/create', RoleForm::class)->middleware('permission:roles.manage')->name('roles.create');
    Route::get('roles/{role}', RoleForm::class)->middleware('permission:roles.view')->name('roles.edit');

    Route::get('activity', ActivityIndex::class)->middleware('permission:activity.view')->name('activity.index');

    // Administration → Backups (license feature "backups")
    Route::middleware(['feature:backups', 'permission:backups.manage'])->group(function () {
        Route::get('backups', BackupIndex::class)->name('backups.index');
        Route::get('backups/{backup}/download', BackupDownloadController::class)
            ->where('backup', 'labeldesk-backup-[0-9\-]+(-db)?\.zip')->middleware('throttle:20,1')->name('backups.download');
    });

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('settings', GeneralSettings::class)->name('settings.index');
        Route::get('settings/mail', MailSettings::class)->name('settings.mail');
        Route::get('settings/appearance', AppearanceSettings::class)->name('settings.appearance');
        Route::get('settings/catalog', CatalogSettings::class)->name('settings.catalog');
        Route::get('settings/appearance/login-preview/{design}', LoginPreviewController::class)
            ->whereIn('design', array_keys(Appearance::LOGIN_LAYOUTS))->name('settings.appearance.login-preview');
        Route::get('system', SystemInfo::class)->name('system.index');
    });

    // Administration → Updates (over-the-air updates from MusicDesk.tech)
    Route::middleware('permission:updates.manage')->group(function () {
        Route::get('updates', \App\Livewire\System\Updates::class)->name('updates.index');
        Route::get('updates/progress', fn () => response()->json(app(\App\Services\Updates\UpdateService::class)->progress() ?? ['state' => 'idle'])
            ->header('Cache-Control', 'no-store'))->middleware('throttle:120,1')->name('updates.progress');
    });

    // Administration → Add-ons
    Route::middleware('permission:plugins.manage')->group(function () {
        Route::get('plugins', Index::class)->name('plugins.index');
    });

    Route::patch('preferences/theme', [ThemePreferenceController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('preferences.theme');

    // Settings → License Information
    Route::prefix('settings/license')->name('license.')->group(function () {
        Route::get('/', [LicenseStatusController::class, 'show'])->middleware('permission:license.view')->name('show');

        Route::middleware(['permission:license.manage', 'throttle:license-activation'])->group(function () {
            Route::post('verify', [LicenseStatusController::class, 'verify'])->name('verify');
            Route::post('activate', [LicenseStatusController::class, 'activate'])->name('activate');
            Route::post('change-key', [LicenseStatusController::class, 'changeKey'])->name('change-key');
            Route::post('deactivate/request', [LicenseStatusController::class, 'deactivateRequest'])->name('deactivate.request');
            Route::post('deactivate', [LicenseStatusController::class, 'deactivate'])->name('deactivate');
            Route::post('deactivate/cancel', [LicenseStatusController::class, 'cancelDeactivate'])->name('deactivate.cancel');
            Route::post('transfer', [LicenseStatusController::class, 'transfer'])->name('transfer');
            Route::post('offline', [LicenseStatusController::class, 'offline'])->name('offline');
            Route::get('request-file', [LicenseStatusController::class, 'requestFile'])->name('request-file');
        });
    });
});
