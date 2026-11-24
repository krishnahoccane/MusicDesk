<?php

namespace LabelDesk\Addons\MediaStorage\Drivers;

/**
 * OneDrive for Business (Microsoft 365 work or school accounts) through
 * Microsoft Graph. Same API as OneDrive, signed in to your organisation's
 * tenant (tenant ID or domain, or "organizations" for any work account).
 */
class OneDriveBusiness extends OneDrive
{
    public static function type(): string
    {
        return 'onedrive_business';
    }

    public static function label(): string
    {
        return 'OneDrive for Business';
    }

    public static function fields(): array
    {
        return [
            'client_id' => ['label' => __('Application (client) ID'), 'type' => 'text', 'required' => true],
            'client_secret' => ['label' => __('Client secret'), 'type' => 'password', 'required' => true, 'secret' => true],
            'tenant' => ['label' => __('Tenant ID or domain'), 'type' => 'text', 'required' => true, 'default' => 'organizations', 'help' => __('Your Microsoft 365 tenant, e.g. yourlabel.onmicrosoft.com, or "organizations" for any work account.')],
        ];
    }

    protected function defaultTenant(): string
    {
        return 'organizations';
    }
}
