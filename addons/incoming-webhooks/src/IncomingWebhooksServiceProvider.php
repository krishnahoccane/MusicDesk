<?php

namespace LabelDesk\Addons\IncomingWebhooks;

use App\Plugins\AddonServiceProvider;
use LabelDesk\Addons\IncomingWebhooks\Livewire\Deliveries;
use LabelDesk\Addons\IncomingWebhooks\Livewire\Endpoints;
use LabelDesk\Addons\IncomingWebhooks\Services\Housekeeping;
use Livewire\Livewire;

class IncomingWebhooksServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Housekeeping::class);
    }

    public function boot(): void
    {
        $this->views();
        $this->migrations();
        $this->webRoutes('routes/web.php');
        $this->apiRoutes('routes/api.php');
        $this->permissions('incoming_webhooks', 'Incoming webhooks', [
            'webhooks.manage' => 'Manage incoming webhooks and view their delivery log',
        ]);
        $this->navigation('Administration', [
            'label' => 'Webhooks',
            'icon' => 'webhook',
            'route' => 'incoming-webhooks.index',
            'active' => 'incoming-webhooks.*',
            'permission' => 'webhooks.manage',
        ], after: 'plugins.index');

        Livewire::component('incoming-webhooks.endpoints', Endpoints::class);
        Livewire::component('incoming-webhooks.deliveries', Deliveries::class);
    }

    /** Data is kept on uninstall; drop the webhook_* tables manually to remove it. */
    public function uninstall(): void {}
}
