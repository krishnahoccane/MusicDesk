<?php

namespace LabelDesk\Addons\IncomingWebhooks\Services;

use App\Services\License\LicenseService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use LabelDesk\Addons\IncomingWebhooks\Actions\ActionRegistry;
use LabelDesk\Addons\IncomingWebhooks\Jobs\ProcessWebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Accepts a request for /api/hooks/{token}: checks it, logs it and hands
 * accepted events to ProcessWebhookDelivery, which runs after the response
 * has been sent (no cron, the sender never waits for the work).
 *
 * Answers: 202 accepted · 200 duplicate · 400 bad JSON · 401 signature ·
 * 403 IP not allowed · 404 unknown/disabled · 413 too large · 429 too many ·
 * 503 license read-only (sender should retry later).
 */
class WebhookReceiver
{
    /** Largest accepted body. */
    public const MAX_BODY = 524288;

    /** Requests per minute per endpoint. */
    public const RATE_LIMIT = 120;

    public const EVENT_ID_HEADER = 'X-Webhook-Id';

    /** Header names (lowercase) containing any of these words are stored as "[redacted]". */
    protected const SENSITIVE = ['authorization', 'cookie', 'signature', 'secret', 'token', 'key', 'password', 'auth', 'session', 'csrf'];

    public function __construct(
        protected SignatureVerifier $verifier,
        protected ActionRegistry $actions,
        protected LicenseService $license,
    ) {}

    public function receive(Request $request, string $token): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->where('token', $token)->first();
        if (! $endpoint) {
            return $this->reply(404, 'not_found');
        }

        $limiter = 'incoming-webhooks:'.$endpoint->id;
        if (RateLimiter::tooManyAttempts($limiter, self::RATE_LIMIT)) {
            return $this->reply(429, 'too_many_requests')->header('Retry-After', (string) RateLimiter::availableIn($limiter));
        }
        RateLimiter::hit($limiter, 60);

        $body = (string) $request->getContent();
        $delivery = new WebhookDelivery([
            'webhook_endpoint_id' => $endpoint->id,
            'ip_address' => $request->ip(),
            'headers' => $this->headers($request),
            'payload' => mb_strcut($body, 0, WebhookDelivery::PAYLOAD_LIMIT, 'UTF-8'),
            'payload_size' => strlen($body),
            'payload_truncated' => strlen($body) > WebhookDelivery::PAYLOAD_LIMIT,
        ]);
        $endpoint->forceFill(['last_delivery_at' => now()])->saveQuietly();
        app(Housekeeping::class)->scheduleAfterResponse();

        if (! $endpoint->is_active) {
            return $this->reject($delivery, 404, 'not_found', __('The endpoint is disabled.'));
        }

        if (strlen($body) > self::MAX_BODY) {
            return $this->reject($delivery, 413, 'payload_too_large', __('The body is larger than :size KB.', ['size' => self::MAX_BODY / 1024]));
        }

        $allowed = array_values(array_filter((array) $endpoint->allowed_ips));
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return $this->reject($delivery, 403, 'forbidden', __('The sender’s IP address :ip is not on the allow-list.', ['ip' => $request->ip()]));
        }

        $check = $this->verifier->verify($request->header(SignatureVerifier::HEADER), $body, $endpoint->acceptedSecrets(), max(60, $endpoint->tolerance_seconds));
        if ($check !== SignatureVerifier::OK) {
            return $this->reject($delivery, 401, 'invalid_signature', SignatureVerifier::describe($check));
        }
        $delivery->signature_valid = true;

        $payload = json_decode($body, true);
        if (! is_array($payload) || array_is_list($payload) && $payload !== []) {
            return $this->reject($delivery, 400, 'invalid_json', __('The body must be a JSON object.'));
        }

        if ($this->actions->has($endpoint->action) && $this->actions->get($endpoint->action)->writes() && ! $this->license->allowsWrites()) {
            return $this->reject($delivery, 503, 'unavailable', __('LabelDesk is read-only until its license is verified. The sender should retry later.'));
        }

        $delivery->event_id = $this->eventId($request, $endpoint, $payload, $body);

        $accepted = $this->record($endpoint, $delivery);
        if (! $accepted) {
            return $this->reply(200, 'duplicate', ['delivery' => $delivery->id]);
        }

        ProcessWebhookDelivery::dispatchAfterResponse($delivery->id, $delivery->payload_truncated ? $body : null);

        return $this->reply(202, 'accepted', ['delivery' => $delivery->id]);
    }

    /**
     * Saves an accepted delivery and claims its event id. Returns false (and
     * logs the delivery as a duplicate) when the event was already accepted
     * and has not failed. A failed event may be sent again.
     */
    protected function record(WebhookEndpoint $endpoint, WebhookDelivery $delivery): bool
    {
        return DB::transaction(function () use ($endpoint, $delivery) {
            $delivery->fill(['status' => WebhookDelivery::QUEUED, 'http_status' => 202])->save();

            try {
                DB::transaction(fn () => DB::table('webhook_events')->insert([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_id' => $delivery->event_id,
                    'webhook_delivery_id' => $delivery->id,
                    'created_at' => now(),
                ]));

                return true;
            } catch (UniqueConstraintViolationException) {
                $event = DB::table('webhook_events')->where('webhook_endpoint_id', $endpoint->id)
                    ->where('event_id', $delivery->event_id)->lockForUpdate()->first();
                $previous = $event?->webhook_delivery_id ? WebhookDelivery::query()->find($event->webhook_delivery_id) : null;

                if ($event && (! $previous || $previous->status === WebhookDelivery::FAILED)) {
                    DB::table('webhook_events')->where('id', $event->id)->update(['webhook_delivery_id' => $delivery->id]);

                    return true;
                }

                $delivery->forceFill([
                    'status' => WebhookDelivery::DUPLICATE,
                    'http_status' => 200,
                    'result' => ['outcome' => 'duplicate', 'original_delivery' => $previous?->id],
                ])->save();

                return false;
            }
        });
    }

    /** @param  array<string, mixed>  $payload */
    protected function eventId(Request $request, WebhookEndpoint $endpoint, array $payload, string $body): string
    {
        $id = $request->header(self::EVENT_ID_HEADER);
        if (! is_string($id) || trim($id) === '') {
            $path = trim((string) $endpoint->setting('fields.event_id', '')) ?: 'id';
            $value = data_get($payload, $path);
            $id = is_scalar($value) ? (string) $value : null;
        }
        $id = $id !== null ? trim($id) : '';

        // No event id: identical bodies count as the same event.
        return $id !== '' ? mb_substr($id, 0, 191) : 'sha256:'.hash('sha256', $body);
    }

    protected function reject(WebhookDelivery $delivery, int $status, string $code, string $error): JsonResponse
    {
        $delivery->forceFill(['status' => WebhookDelivery::REJECTED, 'http_status' => $status, 'error' => mb_substr($error, 0, 1000)])->save();

        return $this->reply($status, $code, $status === 404 ? [] : ['message' => $error]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function reply(int $status, string $code, array $extra = []): JsonResponse
    {
        return response()->json(['status' => $code] + $extra, $status);
    }

    /**
     * Request headers for the log, secrets redacted.
     *
     * @return array<string, string>
     */
    protected function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            $sensitive = false;
            foreach (self::SENSITIVE as $word) {
                if (str_contains($name, $word)) {
                    $sensitive = true;
                    break;
                }
            }
            $headers[$name] = $sensitive ? '[redacted]' : mb_substr(implode(', ', (array) $values), 0, 500);
            if (count($headers) >= 50) {
                break;
            }
        }
        ksort($headers);

        return $headers;
    }
}
