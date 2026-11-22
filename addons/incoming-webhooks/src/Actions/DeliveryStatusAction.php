<?php

namespace LabelDesk\Addons\IncomingWebhooks\Actions;

use App\Enums\DeliveryStatus;
use App\Exceptions\CatalogException;
use App\Models\Dsp;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use App\Models\Track;
use App\Models\User;
use App\Services\Distribution\DistributionService;
use App\Services\License\LicenseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;

/**
 * Distributor / store status reports: finds the release (UPC, else ISRC)
 * and the store, then moves that release's delivery to the reported status
 * through core DistributionService transitions (so the usual rules, the
 * delivery history and the activity log all apply), acting as the
 * endpoint's "acts as" user.
 *
 * Default payload (field paths can be changed per endpoint):
 *
 *   {"id": "evt_123", "upc": "0123456789012", "store": "Spotify", "status": "live",
 *    "date": "2026-11-22", "store_release_id": "4uLU6hMCjMI75M1A2tKUQC",
 *    "store_url": "https://open.spotify.com/album/…", "reason": "…"}
 */
class DeliveryStatusAction implements WebhookAction
{
    /** field => default path in the payload (dot notation) */
    public const FIELDS = [
        'upc' => 'upc',
        'isrc' => 'isrc',
        'store' => 'store',
        'status' => 'status',
        'date' => 'date',
        'store_id' => 'store_release_id',
        'store_url' => 'store_url',
        'reason' => 'reason',
    ];

    /** Accepted status words besides the LabelDesk status values. */
    public const STATUS_ALIASES = [
        'sent' => 'delivered',
        'ingested' => 'delivered',
        'processing' => 'delivered',
        'published' => 'live',
        'available' => 'live',
        'online' => 'live',
        'approved' => 'live',
        'declined' => 'rejected',
        'error' => 'rejected',
        'failed' => 'rejected',
        'takedown_pending' => 'takedown_requested',
        'takedown' => 'taken_down',
        'removed' => 'taken_down',
        'offline' => 'taken_down',
    ];

    /** Delivery status graph used to find the steps to the reported status (each step is checked again by core). */
    protected const GRAPH = [
        'pending' => ['deliver' => 'delivered', 'reject' => 'rejected'],
        'delivered' => ['go_live' => 'live', 'reject' => 'rejected', 'request_takedown' => 'takedown_requested'],
        'live' => ['request_takedown' => 'takedown_requested'],
        'rejected' => ['deliver' => 'delivered'],
        'takedown_requested' => ['confirm_takedown' => 'taken_down', 'cancel_takedown' => 'live'],
        'taken_down' => ['deliver' => 'delivered'],
    ];

    public function __construct(protected DistributionService $distribution, protected LicenseService $license) {}

    public function label(): string
    {
        return __('Store delivery status');
    }

    public function description(): string
    {
        return __('Update a release’s status at a store (delivered, live, rejected, taken down) from your distributor’s or store’s status reports. The release is matched by UPC or ISRC and the store by name.');
    }

    public function writes(): bool
    {
        return true;
    }

    public static function path(WebhookEndpoint $endpoint, string $field): string
    {
        $path = trim((string) $endpoint->setting('fields.'.$field, ''));

        return $path !== '' ? $path : self::FIELDS[$field];
    }

    public function handle(WebhookEndpoint $endpoint, array $payload, WebhookDelivery $delivery): array
    {
        $value = fn (string $field): ?string => $this->scalar(data_get($payload, self::path($endpoint, $field)));

        if (! $this->license->canUse('distribution')) {
            throw new WebhookActionException(__('Distribution is not included in your license plan.'));
        }

        $actor = $this->actor($endpoint);
        $target = $this->status($value('status'));
        $dsp = $this->store($endpoint, $value('store'));
        $release = $this->release($value('upc'), $value('isrc'), $dsp);

        $releaseDelivery = ReleaseDelivery::query()->with(['dsp', 'release'])
            ->where('release_id', $release->id)->where('dsp_id', $dsp->id)->first();
        if (! $releaseDelivery) {
            throw new WebhookActionException(__('":release" has not been added to :store in Distribution.', ['release' => $release->displayTitle(), 'store' => $dsp->name]));
        }

        $date = $this->date($value('date'));
        $reason = $value('reason');
        $comment = $reason !== null
            ? Str::limit($reason, 900).' '.__('(webhook ":name")', ['name' => $endpoint->name])
            : __('Reported by webhook ":name".', ['name' => $endpoint->name]);
        $storeId = $value('store_id');
        $details = array_filter(['store_id' => $storeId !== null ? mb_substr($storeId, 0, 100) : null, 'store_url' => $this->url($value('store_url'))]);

        $from = $releaseDelivery->status;
        $steps = $this->steps($from->value, $target->value);
        if ($steps === null) {
            throw new WebhookActionException(__('Cannot change :store from ":from" to ":to".', ['store' => $dsp->name, 'from' => $from->label(), 'to' => $target->label()]));
        }

        $result = ['release_id' => $release->id, 'release' => $release->displayTitle(), 'store' => $dsp->name, 'from' => $from->value, 'to' => $target->value];

        if ($steps === []) {
            $this->updateDetails($actor, $releaseDelivery, $details);

            return $result + ['outcome' => 'unchanged'];
        }

        try {
            foreach ($steps as $action) {
                $this->distribution->transition(
                    $actor,
                    $releaseDelivery,
                    $action,
                    $comment,
                    $this->distribution->setsDate($action) ? $date : null,
                    $details,
                );
                $releaseDelivery->refresh();
            }
        } catch (CatalogException $e) {
            throw new WebhookActionException($e->getMessage(), 0, $e);
        }

        return $result + ['outcome' => 'updated', 'steps' => $steps];
    }

    protected function actor(WebhookEndpoint $endpoint): User
    {
        $user = $endpoint->acting_user_id ? User::query()->find($endpoint->acting_user_id) : null;
        if (! $user || ! $user->is_active) {
            throw new WebhookActionException(__('The endpoint’s "acts as" user is missing or inactive. Edit the endpoint and choose an active user.'));
        }
        if (! $user->can('distribution.manage')) {
            throw new WebhookActionException(__(':name may not manage deliveries. Choose an "acts as" user with the "Manage deliveries and takedowns" permission.', ['name' => $user->name]));
        }

        return $user;
    }

    protected function status(?string $value): DeliveryStatus
    {
        $key = Str::snake(Str::lower(trim((string) $value)));
        $key = str_replace('-', '_', $key);
        $status = DeliveryStatus::tryFrom(self::STATUS_ALIASES[$key] ?? $key);
        if (! $status || $status === DeliveryStatus::Pending) {
            throw new WebhookActionException($value === null
                ? __('The payload has no status.')
                : __('Unknown status ":status". Use delivered, live, rejected, takedown_requested or taken_down.', ['status' => Str::limit($value, 40)]));
        }

        return $status;
    }

    protected function store(WebhookEndpoint $endpoint, ?string $name): Dsp
    {
        if ($name !== null) {
            $wanted = $this->normalise($name);
            $dsp = Dsp::query()->get()->first(fn (Dsp $d) => $this->normalise($d->name) === $wanted);
            if (! $dsp) {
                throw new WebhookActionException(__('No store named ":store". Add it under Distribution → Stores or check the spelling.', ['store' => Str::limit($name, 60)]));
            }

            return $dsp;
        }

        $default = $endpoint->setting('default_dsp_id');
        $dsp = $default ? Dsp::query()->find($default) : null;
        if (! $dsp) {
            throw new WebhookActionException(__('The payload names no store and the endpoint has no default store.'));
        }

        return $dsp;
    }

    protected function release(?string $upc, ?string $isrc, Dsp $dsp): Release
    {
        if ($upc !== null) {
            $digits = preg_replace('/\D/', '', $upc);
            $candidates = array_unique(array_filter([$digits, strlen($digits) === 12 ? '0'.$digits : null, strlen($digits) === 13 && str_starts_with($digits, '0') ? substr($digits, 1) : null]));
            $release = $digits !== '' ? Release::query()->whereIn('upc', $candidates)->first() : null;
            if (! $release) {
                throw new WebhookActionException(__('No release with UPC :upc.', ['upc' => Str::limit($upc, 20)]));
            }

            return $release;
        }

        if ($isrc !== null) {
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $isrc));
            $ids = $code !== '' ? Track::query()->where('isrc', $code)->distinct()->pluck('release_id') : collect();
            $releases = Release::query()->whereIn('id', $ids)->get();
            if ($releases->count() > 1) {
                // The same recording on several releases: use the one that is set up for this store.
                $releases = $releases->filter(fn (Release $r) => $r->deliveries()->where('dsp_id', $dsp->id)->exists())->values();
            }
            if ($releases->isEmpty()) {
                throw new WebhookActionException(__('No release with a track with ISRC :isrc.', ['isrc' => Str::limit($isrc, 20)]));
            }
            if ($releases->count() > 1) {
                throw new WebhookActionException(__('ISRC :isrc is on several releases at :store. Send the UPC instead.', ['isrc' => $code, 'store' => $dsp->name]));
            }

            return $releases->first();
        }

        throw new WebhookActionException(__('The payload has neither a UPC nor an ISRC.'));
    }

    protected function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $day = substr($value, 0, 10);
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $day);
        } catch (\Throwable) {
            $parsed = false;
        }
        if (! $parsed || $parsed->format('Y-m-d') !== $day) {
            throw new WebhookActionException(__('The date ":date" is not in the format YYYY-MM-DD.', ['date' => Str::limit($value, 30)]));
        }

        return $day;
    }

    /** Longest chain of steps taken for one report (pending → live is deliver + go live). */
    public const MAX_STEPS = 2;

    /**
     * Shortest list of core actions from one status to another; [] when
     * already there, null when it cannot be reached in MAX_STEPS steps.
     *
     * @return list<string>|null
     */
    protected function steps(string $from, string $to): ?array
    {
        if ($from === $to) {
            return [];
        }
        $queue = [[$from, []]];
        $seen = [$from => true];
        while ($queue) {
            [$status, $path] = array_shift($queue);
            foreach (self::GRAPH[$status] ?? [] as $action => $next) {
                if (isset($seen[$next])) {
                    continue;
                }
                if ($next === $to) {
                    return [...$path, $action];
                }
                $seen[$next] = true;
                if (count($path) + 1 < self::MAX_STEPS) {
                    $queue[] = [$next, [...$path, $action]];
                }
            }
        }

        return null;
    }

    /** @param  array<string, string>  $details */
    protected function updateDetails(User $actor, ReleaseDelivery $delivery, array $details): void
    {
        $storeId = $details['store_id'] ?? $delivery->store_id;
        $storeUrl = $details['store_url'] ?? $delivery->store_url;
        if ($storeId !== $delivery->store_id || $storeUrl !== $delivery->store_url) {
            $this->distribution->updateDetails($actor, $delivery, $storeId, $storeUrl, $delivery->notes);
        }
    }

    protected function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    protected function url(?string $value): ?string
    {
        if ($value === null || ! preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return mb_substr($value, 0, 500);
    }

    protected function normalise(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($name)));
    }
}
