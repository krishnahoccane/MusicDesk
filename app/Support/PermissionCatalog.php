<?php

namespace App\Support;

/**
 * The single source of truth for LabelDesk permissions, grouped by module,
 * with the labels shown in the roles editor. Seeders and the UI read from here.
 */
class PermissionCatalog
{
    /**
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'artists' => ['label' => __('Artists'), 'permissions' => [
                'artists.view' => __('View artists'),
                'artists.create' => __('Create artists'),
                'artists.update' => __('Edit artists'),
                'artists.delete' => __('Delete artists'),
            ]],
            'releases' => ['label' => __('Releases'), 'permissions' => [
                'releases.view' => __('View releases'),
                'releases.create' => __('Create releases'),
                'releases.update' => __('Edit releases'),
                'releases.delete' => __('Delete releases'),
                'releases.submit' => __('Submit releases for review'),
                'releases.review' => __('Approve or reject releases'),
                'catalog.bulk_upload' => __('Bulk upload artists and releases'),
            ]],
            'tracks' => ['label' => __('Tracks'), 'permissions' => [
                'tracks.view' => __('View tracks'),
                'tracks.create' => __('Create tracks'),
                'tracks.update' => __('Edit tracks'),
                'tracks.delete' => __('Delete tracks'),
                'tracks.download_master' => __('Download master audio'),
            ]],
            'contributors' => ['label' => __('Contributors'), 'permissions' => [
                'contributors.view' => __('View contributors'),
                'contributors.manage' => __('Manage contributors'),
            ]],
            'distribution' => ['label' => __('Distribution'), 'permissions' => [
                'distribution.view' => __('View deliveries'),
                'distribution.manage' => __('Manage deliveries and takedowns'),
                'dsps.manage' => __('Manage DSP list'),
            ]],
            'royalties' => ['label' => __('Royalties'), 'permissions' => [
                'royalties.view' => __('View royalties'),
                'royalties.import' => __('Import royalty reports'),
                'royalties.manage' => __('Manage royalty periods and allocation'),
                'payments.view' => __('View payments'),
                'payments.manage' => __('Record payments'),
            ]],
            'contracts' => ['label' => __('Contracts & documents'), 'permissions' => [
                'contracts.view' => __('View contracts'),
                'contracts.manage' => __('Manage contracts'),
                'documents.view' => __('View documents'),
                'documents.manage' => __('Upload and delete documents'),
            ]],
            'reports' => ['label' => __('Reports'), 'permissions' => [
                'reports.view' => __('View reports'),
                'reports.export' => __('Export reports'),
                'reports.financial' => __('View financial reports'),
            ]],
            'administration' => ['label' => __('Administration'), 'permissions' => [
                'users.view' => __('View users'),
                'users.manage' => __('Create and edit users'),
                'roles.view' => __('View roles'),
                'roles.manage' => __('Create and edit roles'),
                'settings.manage' => __('Manage settings'),
                'activity.view' => __('View activity log'),
                'backups.manage' => __('Manage backups'),
                'license.view' => __('View license'),
                'license.manage' => __('Manage license'),
            ]],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function names(): array
    {
        return collect(self::groups())->map(fn (array $g) => array_keys($g['permissions']))->all();
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return collect(self::names())->flatten()->values()->all();
    }

    public static function label(string $permission): string
    {
        foreach (self::groups() as $group) {
            if (isset($group['permissions'][$permission])) {
                return $group['permissions'][$permission];
            }
        }

        return $permission;
    }
}
