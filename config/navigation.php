<?php

/*
|--------------------------------------------------------------------------
| Sidebar navigation
|--------------------------------------------------------------------------
|
| Each item names a route and (optionally) the permission required to see it.
| Items whose route is not registered are skipped automatically, so modules
| appear in the sidebar as soon as their routes exist. Hiding an item is a
| convenience only — every route is independently protected by middleware,
| policies and permission checks.
|
| 'active' is a route-name pattern used to highlight the current section.
|
*/

return [

    [
        'label' => null,
        'items' => [
            ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'active' => 'dashboard'],
        ],
    ],

    [
        'label' => 'Catalog',
        'items' => [
            ['label' => 'Artists', 'icon' => 'mic-vocal', 'route' => 'artists.index', 'active' => 'artists.*', 'permission' => 'artists.view', 'feature' => 'artists'],
            ['label' => 'Releases', 'icon' => 'disc-3', 'route' => 'releases.index', 'active' => 'releases.*', 'permission' => 'releases.view', 'feature' => 'releases'],
            ['label' => 'Tracks', 'icon' => 'audio-lines', 'route' => 'tracks.index', 'active' => 'tracks.*', 'permission' => 'tracks.view', 'feature' => 'tracks'],
            ['label' => 'Labels', 'icon' => 'tags', 'route' => 'labels.index', 'active' => 'labels.*', 'permission' => 'labels.view', 'feature' => 'releases'],
            ['label' => 'Contributors', 'icon' => 'users-round', 'route' => 'contributors.index', 'active' => 'contributors.*', 'permission' => 'contributors.view', 'feature' => 'releases'],
        ],
    ],

    [
        'label' => 'Business',
        'items' => [
            ['label' => 'Distribution', 'icon' => 'send', 'route' => 'distribution.index', 'active' => 'distribution.*', 'permission' => 'distribution.view', 'feature' => 'distribution'],
            ['label' => 'Royalties', 'icon' => 'wallet', 'route' => 'royalties.index', 'active' => 'royalties.*', 'permission' => 'royalties.view', 'feature' => 'royalties'],
            ['label' => 'Contracts', 'icon' => 'file-signature', 'route' => 'contracts.index', 'active' => 'contracts.*', 'permission' => 'contracts.view', 'feature' => 'documents'],
            ['label' => 'Reports', 'icon' => 'chart-column', 'route' => 'reports.index', 'active' => 'reports.*', 'permission' => 'reports.view', 'feature' => 'reports'],
        ],
    ],

    [
        'label' => 'Administration',
        'items' => [
            ['label' => 'Users', 'icon' => 'user-cog', 'route' => 'users.index', 'active' => 'users.*', 'permission' => 'users.view'],
            ['label' => 'Roles', 'icon' => 'shield-check', 'route' => 'roles.index', 'active' => 'roles.*', 'permission' => 'roles.view'],
            ['label' => 'Activity Log', 'icon' => 'history', 'route' => 'activity.index', 'active' => 'activity.*', 'permission' => 'activity.view'],
            ['label' => 'Backups', 'icon' => 'database-backup', 'route' => 'backups.index', 'active' => 'backups.*', 'permission' => 'backups.manage'],
            ['label' => 'Add-ons', 'icon' => 'puzzle', 'route' => 'plugins.index', 'active' => 'plugins.*', 'permission' => 'plugins.manage'],
            ['label' => 'Updates', 'icon' => 'refresh-cw', 'route' => 'updates.index', 'active' => 'updates.*', 'permission' => 'updates.manage', 'badge' => 'updates'],
            ['label' => 'System', 'icon' => 'server', 'route' => 'system.index', 'active' => 'system.*', 'permission' => 'settings.manage'],
            ['label' => 'Settings', 'icon' => 'settings', 'route' => 'settings.index', 'active' => 'settings.*', 'permission' => 'settings.manage'],
            ['label' => 'License', 'icon' => 'key-round', 'route' => 'license.show', 'active' => 'license.*', 'permission' => 'license.view'],
        ],
    ],

];
