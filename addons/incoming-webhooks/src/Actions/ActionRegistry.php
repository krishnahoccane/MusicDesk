<?php

namespace LabelDesk\Addons\IncomingWebhooks\Actions;

/**
 * Endpoint actions offered in the endpoint form. New actions are added here.
 */
class ActionRegistry
{
    /** @var array<string, class-string<WebhookAction>> */
    public const ACTIONS = [
        'delivery_status' => DeliveryStatusAction::class,
        'log_only' => LogOnlyAction::class,
    ];

    public function has(string $key): bool
    {
        return isset(self::ACTIONS[$key]);
    }

    public function get(string $key): WebhookAction
    {
        $class = self::ACTIONS[$key] ?? throw new WebhookActionException(__('Unknown webhook action ":action".', ['action' => $key]));

        return app($class);
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        $options = [];
        foreach (array_keys(self::ACTIONS) as $key) {
            $options[$key] = $this->get($key)->label();
        }

        return $options;
    }
}
