<?php

namespace LabelDesk\Addons\PdlDistribution;

use App\Plugins\AddonServiceProvider;
use LabelDesk\Addons\PdlDistribution\Console\RunPdl;
use LabelDesk\Addons\PdlDistribution\Livewire\Index;
use LabelDesk\Addons\PdlDistribution\Livewire\ReleasePage;
use LabelDesk\Addons\PdlDistribution\Livewire\Settings;
use LabelDesk\Addons\PdlDistribution\Services\DeliveryService;
use Livewire\Livewire;

/**
 * PDL distribution: releases sent to Phonographic Digital Limited through the
 * COSMOS data ingestion API (metadata → artwork/audio uploads to presigned S3
 * URLs → verify → submit), then their review and release status followed.
 *
 * Deliveries are a small state machine in pdl_deliveries, advanced by the
 * "pdl" background task (every minute, no cron or queue worker needed) or by
 * `php artisan labeldesk:pdl:run`. A page never waits for uploads.
 */
class PdlDistributionServiceProvider extends AddonServiceProvider
{
    public const PERMISSIONS = [
        'pdl.view' => 'View PDL deliveries and their status',
        'pdl.deliver' => 'Send releases and updates to PDL, check status',
        'pdl.manage' => 'Manage the PDL connection and field mapping',
    ];

    public function register(): void
    {
        $this->app->singleton(DeliveryService::class);
    }

    public function boot(): void
    {
        $this->views();
        $this->migrations();
        $this->webRoutes('routes/web.php');

        Livewire::component('pdl-distribution.index', Index::class);
        Livewire::component('pdl-distribution.release', ReleasePage::class);
        Livewire::component('pdl-distribution.settings', Settings::class);

        $this->permissions('pdl_distribution', 'PDL distribution', self::PERMISSIONS);
        $this->navigation('Business', [
            'label' => 'PDL distribution', 'icon' => 'send', 'route' => 'pdl.index', 'active' => 'pdl.*',
            'permission' => 'pdl.view',
        ], after: 'distribution.index');
        $this->settingsTab('settings.pdl', 'PDL distribution', 'send', 'pdl.manage');

        if ($this->app->runningInConsole()) {
            $this->commands([RunPdl::class]);
        }

        // Metadata, uploads, verify and submit for queued deliveries; status sync of sent ones.
        $this->backgroundTask('pdl', 1, fn () => $this->app->make(DeliveryService::class)->run(), __('PDL deliveries and status sync'));
    }

    /** Deliveries, their history and the settings are kept: they record what was sent to PDL. */
    public function uninstall(): void {}
}
