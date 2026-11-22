<?php

namespace App\Plugins;

/**
 * Extension points that running add-ons fill in. Core screens read them:
 * the sidebar (config "navigation"), the roles editor and seeder
 * (PermissionCatalog), the Settings tabs and the sign-in page.
 */
class PluginRegistry
{
    /** @var array<string, array{label: string, permissions: array<string, string>, plugin: string}> */
    public array $permissionGroups = [];

    /** @var list<array{route: string, label: string, icon: string, can: string, plugin: string}> */
    public array $settingsTabs = [];

    /** @var list<array{view: string, plugin: string}> */
    public array $loginButtons = [];

    /** @var list<array{view: string, plugin: string}> */
    public array $dashboardWidgets = [];

    /**
     * Add a sidebar item to a section (matched by its label, e.g. "Catalog",
     * "Business", "Administration"); a new section is created if needed.
     *
     * @param  array<string, mixed>  $item  same keys as config/navigation.php items
     */
    public function navigation(string $section, array $item, ?string $after = null): void
    {
        $nav = (array) config('navigation', []);
        $index = null;
        foreach ($nav as $i => $group) {
            if (($group['label'] ?? null) === $section) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $nav[] = ['label' => $section, 'items' => []];
            $index = array_key_last($nav);
        }

        $items = (array) ($nav[$index]['items'] ?? []);
        $position = count($items);
        if ($after !== null) {
            foreach ($items as $i => $existing) {
                if (($existing['route'] ?? null) === $after) {
                    $position = $i + 1;
                    break;
                }
            }
        }
        array_splice($items, $position, 0, [$item]);
        $nav[$index]['items'] = $items;

        config(['navigation' => $nav]);
    }

    /**
     * @param  array<string, string>  $permissions  name => label
     */
    public function permissions(string $key, string $label, array $permissions, string $plugin): void
    {
        $this->permissionGroups[$key] = ['label' => $label, 'permissions' => $permissions, 'plugin' => $plugin];
    }

    public function settingsTab(string $route, string $label, string $icon, string $can, string $plugin): void
    {
        $this->settingsTabs[] = compact('route', 'label', 'icon', 'can', 'plugin');
    }

    public function loginButton(string $view, string $plugin): void
    {
        $this->loginButtons[] = compact('view', 'plugin');
    }

    public function dashboardWidget(string $view, string $plugin): void
    {
        $this->dashboardWidgets[] = compact('view', 'plugin');
    }

    /** @return list<string> */
    public function permissionNames(?string $plugin = null): array
    {
        return collect($this->permissionGroups)
            ->filter(fn ($g) => $plugin === null || $g['plugin'] === $plugin)
            ->flatMap(fn ($g) => array_keys($g['permissions']))->values()->all();
    }
}
