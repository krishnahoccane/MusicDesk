<?php

namespace LabelDesk\Addons\IncomingWebhooks\Services;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\License\LicenseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LabelDesk\Addons\IncomingWebhooks\Actions\ActionRegistry;
use LabelDesk\Addons\IncomingWebhooks\Actions\WebhookActionException;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use Throwable;

/**
 * Runs an accepted delivery through its endpoint's action. Each delivery is
 * claimed atomically (queued → processing), so it is processed once even if
 * the after-response job and the stale sweep meet.
 */
class WebhookProcessor
{
    /** Queued deliveries older than this are picked up by the sweep. */
    public const STALE_MINUTES = 2;

    /** Deliveries stuck in "processing" this long are retried (the process died). */
    public const STUCK_MINUTES = 10;

    public const MAX_ATTEMPTS = 3;

    public function __construct(
        protected ActionRegistry $actions,
        protected LicenseService $license,
        protected ActivityLogger $activity,
    ) {}

    /**
     * @param  string|null  $body  the full body when the logged payload is truncated
     */
    public function process(int $deliveryId, ?string $body = null): ?WebhookDelivery
    {
        $claimed = WebhookDelivery::query()->whereKey($deliveryId)->where('status', WebhookDelivery::QUEUED)
            ->update(['status' => WebhookDelivery::PROCESSING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return null;
        }

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($deliveryId);

        try {
            $endpoint = $delivery->endpoint;
            if ($delivery->payload_truncated && $body === null) {
                throw new WebhookActionException(__('The payload was too large to keep and can no longer be processed. Ask the sender to send it again.'));
            }
            $payload = json_decode($body ?? (string) $delivery->payload, true);
            if (! is_array($payload)) {
                throw new WebhookActionException(__('The body must be a JSON object.'));
            }

            $action = $this->actions->get($endpoint->action);
            if ($action->writes() && ! $this->license->allowsWrites()) {
                throw new WebhookActionException(__('LabelDesk is read-only until its license is verified. Replay this delivery afterwards.'));
            }

            $result = $action->handle($endpoint, $payload, $delivery);
            $delivery->forceFill(['status' => WebhookDelivery::PROCESSED, 'result' => $result, 'error' => null, 'processed_at' => now()])->save();
        } catch (WebhookActionException $e) {
            $delivery->forceFill(['status' => WebhookDelivery::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000), 'processed_at' => now()])->save();
        } catch (Throwable $e) {
            Log::error('Incoming webhook processing failed', ['delivery' => $delivery->id, 'endpoint' => $delivery->webhook_endpoint_id, 'exception' => $e::class]);
            report($e);
            $delivery->forceFill([
                'status' => WebhookDelivery::FAILED,
                'error' => __('Unexpected error while processing (:type). Details are in the application log.', ['type' => class_basename($e)]),
                'processed_at' => now(),
            ])->save();
        }

        return $delivery;
    }

    /** Process deliveries that were accepted but never processed. */
    public function processStale(int $limit = 20): int
    {
        $stuck = fn () => WebhookDelivery::query()->where('status', WebhookDelivery::PROCESSING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES));
        $stuck()->where('attempts', '<', self::MAX_ATTEMPTS)->update(['status' => WebhookDelivery::QUEUED]);
        $stuck()->update([
            'status' => WebhookDelivery::FAILED,
            'error' => __('Processing did not finish after :count attempts.', ['count' => self::MAX_ATTEMPTS]),
            'processed_at' => now(),
        ]);

        $ids = WebhookDelivery::query()->where('status', WebhookDelivery::QUEUED)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $this->process((int) $id);
        }

        return $ids->count();
    }

    /**
     * Process a logged delivery again, as a new log entry, on an admin's
     * request. Idempotency is bypassed on purpose; the action itself makes
     * no change when the data is already up to date.
     */
    public function replay(WebhookDelivery $original, User $by): WebhookDelivery
    {
        if (! $original->canReplay()) {
            throw new WebhookActionException(__('Only verified deliveries with a complete payload can be replayed.'));
        }

        $copy = $original->endpoint->deliveries()->create([
            'event_id' => $original->event_id,
            'status' => WebhookDelivery::QUEUED,
            'signature_valid' => true,
            'headers' => $original->headers,
            'payload' => $original->payload,
            'payload_size' => $original->payload_size,
            'payload_truncated' => false,
            'replay_of_id' => $original->id,
            'replayed_by' => $by->id,
        ]);

        $this->activity->log('webhooks.replayed', __('Replayed webhook delivery #:id for ":endpoint"', [
            'id' => $original->id,
            'endpoint' => $original->endpoint->name,
        ]), null, ['delivery' => $original->id, 'replay' => $copy->id]);

        return $this->process($copy->id) ?? $copy->refresh();
    }
}
