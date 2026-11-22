<?php

namespace LabelDesk\Addons\IncomingWebhooks\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use Throwable;

/**
 * Background upkeep without cron, run after a webhook response has been
 * sent: picks up deliveries that were never processed (e.g. the PHP
 * process was stopped) and, once an hour, removes log entries older than
 * the retention period.
 */
class Housekeeping
{
    protected bool $scheduled = false;

    public function __construct(protected WebhookProcessor $processor) {}

    public function scheduleAfterResponse(): void
    {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        app()->terminating(function () {
            $this->scheduled = false;
            try {
                $this->run();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public function run(): void
    {
        $this->processor->processStale();

        if (Cache::add('incoming-webhooks:pruned', true, 3600)) {
            $this->prune();
        }
    }

    public function prune(): int
    {
        $cutoff = now()->subDays(WebhookDelivery::RETENTION_DAYS);
        DB::table('webhook_events')->where('created_at', '<', $cutoff)->delete();

        $deleted = 0;
        do {
            $ids = WebhookDelivery::query()->where('created_at', '<', $cutoff)->limit(500)->pluck('id');
            if ($ids->isNotEmpty()) {
                WebhookDelivery::query()->whereIn('replay_of_id', $ids)->update(['replay_of_id' => null]);
                $deleted += WebhookDelivery::query()->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === 500);

        return $deleted;
    }
}
