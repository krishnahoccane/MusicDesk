<?php

namespace App\Services\Navigation;

use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the sidebar navigation for the current user from config/navigation.php.
 *
 * Items are omitted when their route is not registered or the user lacks the
 * permission. This is presentation only; authorization is enforced on routes.
 */
class NavigationBuilder
{
    /** @var array<string, callable(string): bool> */
    protected array $featureResolvers = [];

    public function __construct(protected Router $router) {}

    /**
     * Register a resolver that decides whether a licensed feature is enabled.
     * The licensing module registers itself here in Milestone 3.
     */
    public function resolveFeaturesUsing(callable $resolver): void
    {
        $this->featureResolvers['default'] = $resolver;
    }

    /**
     * @return list<array{label: ?string, items: list<array<string, mixed>>}>
     */
    public function for(?User $user): array
    {
        $groups = [];

        foreach ((array) config('navigation', []) as $group) {
            $items = [];

            foreach ($group['items'] ?? [] as $item) {
                if (! $this->router->has($item['route'])) {
                    continue;
                }

                if (! empty($item['permission']) && ! $user?->can($item['permission'])) {
                    continue;
                }

                if (! empty($item['gate']) && ! ($user && Gate::forUser($user)->allows($item['gate']))) {
                    continue;
                }

                $items[] = $item + [
                    'url' => route($item['route']),
                    'is_active' => request()->routeIs($item['active'] ?? $item['route']),
                    'locked' => ! empty($item['feature']) && ! $this->featureEnabled($item['feature']),
                ];
            }

            if ($items !== []) {
                $groups[] = ['label' => $group['label'] ?? null, 'items' => $items];
            }
        }

        return $groups;
    }

    protected function featureEnabled(string $feature): bool
    {
        $resolver = $this->featureResolvers['default'] ?? null;

        return $resolver ? (bool) $resolver($feature) : true;
    }
}
