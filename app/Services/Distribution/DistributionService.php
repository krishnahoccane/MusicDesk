<?php

namespace App\Services\Distribution;

use App\Enums\DeliveryStatus;
use App\Enums\ReleaseStatus;
use App\Exceptions\CatalogException;
use App\Models\Dsp;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manual delivery tracking. Every status change is checked against the
 * allowed transitions inside a locked transaction and recorded in
 * delivery_events and the activity log.
 */
class DistributionService
{
    /**
     * action => [from, to, comment required, date field set on the delivery]
     *
     * @var array<string, array{from: list<DeliveryStatus>, to: DeliveryStatus, comment: bool, date: ?string}>
     */
    protected array $actions;

    public function __construct(protected ActivityLogger $activity)
    {
        $this->actions = [
            'deliver' => ['from' => [DeliveryStatus::Pending, DeliveryStatus::Rejected, DeliveryStatus::TakenDown], 'to' => DeliveryStatus::Delivered, 'comment' => false, 'date' => 'delivered_on'],
            'go_live' => ['from' => [DeliveryStatus::Delivered], 'to' => DeliveryStatus::Live, 'comment' => false, 'date' => 'live_on'],
            'reject' => ['from' => [DeliveryStatus::Pending, DeliveryStatus::Delivered], 'to' => DeliveryStatus::Rejected, 'comment' => true, 'date' => null],
            'request_takedown' => ['from' => [DeliveryStatus::Delivered, DeliveryStatus::Live], 'to' => DeliveryStatus::TakedownRequested, 'comment' => true, 'date' => null],
            'confirm_takedown' => ['from' => [DeliveryStatus::TakedownRequested], 'to' => DeliveryStatus::TakenDown, 'comment' => false, 'date' => 'taken_down_on'],
            'cancel_takedown' => ['from' => [DeliveryStatus::TakedownRequested], 'to' => DeliveryStatus::Live, 'comment' => false, 'date' => null],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'deliver' => __('Mark delivered'),
            'go_live' => __('Mark live'),
            'reject' => __('Store rejected'),
            'request_takedown' => __('Request takedown'),
            'confirm_takedown' => __('Confirm taken down'),
            'cancel_takedown' => __('Cancel takedown'),
        ];
    }

    /**
     * @return list<string>
     */
    public function available(ReleaseDelivery $delivery): array
    {
        return array_values(array_filter(array_keys($this->actions), fn ($a) => in_array($delivery->status, $this->actions[$a]['from'], true)));
    }

    public function requiresComment(string $action): bool
    {
        return $this->actions[$action]['comment'] ?? false;
    }

    public function setsDate(string $action): bool
    {
        return ($this->actions[$action]['date'] ?? null) !== null;
    }

    public function canDistribute(Release $release): bool
    {
        return ! $release->trashed() && $release->status === ReleaseStatus::Ready;
    }

    /**
     * Adds pending deliveries for the given stores (already-added stores are skipped).
     *
     * @param  list<int>  $dspIds
     */
    public function addDeliveries(User $actor, Release $release, array $dspIds): int
    {
        if (! $this->canDistribute($release)) {
            throw new CatalogException(__('Only releases that are ready for delivery can be sent to stores.'));
        }

        $dsps = Dsp::query()->where('is_active', true)->whereIn('id', $dspIds)->get();
        $existing = $release->deliveries()->pluck('dsp_id')->all();
        $added = [];

        DB::transaction(function () use ($actor, $release, $dsps, $existing, &$added) {
            foreach ($dsps as $dsp) {
                if (in_array($dsp->id, $existing, true)) {
                    continue;
                }
                $delivery = $release->deliveries()->make(['dsp_id' => $dsp->id]);
                $delivery->created_by = $actor->id;
                $delivery->save();
                $delivery->events()->create(['from_status' => null, 'to_status' => DeliveryStatus::Pending->value, 'user_id' => $actor->id]);
                $added[] = $dsp->name;
            }
        });

        if ($added) {
            $this->activity->log('distribution.added', __('Added :count stores for :release', ['count' => count($added), 'release' => $release->displayTitle()]), $release, ['stores' => $added]);
        }

        return count($added);
    }

    /**
     * Removes a delivery that was never sent.
     */
    public function remove(User $actor, ReleaseDelivery $delivery): void
    {
        if ($delivery->status !== DeliveryStatus::Pending) {
            throw new CatalogException(__('Only pending stores can be removed. Request a takedown instead.'));
        }

        $delivery->delete();
        $this->activity->log('distribution.removed', __('Removed :store from :release', ['store' => $delivery->dsp->name, 'release' => $delivery->release->displayTitle()]), $delivery->release);
    }

    public function transition(User $actor, ReleaseDelivery $delivery, string $action, ?string $comment = null, ?string $date = null, array $details = []): ReleaseDelivery
    {
        $def = $this->actions[$action] ?? throw new CatalogException(__('Unknown distribution action.'));
        $comment = $comment !== null ? trim($comment) : null;

        if ($def['comment'] && ($comment === null || $comment === '')) {
            throw new CatalogException(__('Please add a reason.'));
        }

        $day = null;
        if ($def['date']) {
            $tz = config('labeldesk.display_timezone');
            $day = $date ? Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay() : now()->setTimezone($tz)->startOfDay();
            if ($day->isAfter(now()->setTimezone(config('labeldesk.display_timezone'))->endOfDay())) {
                throw new CatalogException(__('The date cannot be in the future.'));
            }
        }

        $from = DB::transaction(function () use ($actor, $delivery, $def, $comment, $day, $details) {
            /** @var ReleaseDelivery $locked */
            $locked = ReleaseDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $def['from'], true)) {
                throw new CatalogException(__('This store is now ":status", so that step is no longer possible. Refresh the page.', ['status' => $locked->status->label()]));
            }

            $from = $locked->status;
            $locked->status = $def['to'];
            if ($def['date']) {
                $locked->{$def['date']} = $day->toDateString();
            }
            foreach (['store_id', 'store_url'] as $field) {
                if (array_key_exists($field, $details) && filled($details[$field])) {
                    $locked->{$field} = trim((string) $details[$field]);
                }
            }
            $locked->save();

            $locked->events()->create([
                'from_status' => $from->value,
                'to_status' => $def['to']->value,
                'user_id' => $actor->id,
                'comment' => $comment ?: null,
            ]);

            return $from;
        });

        $delivery->refresh();

        $this->activity->log('distribution.'.$action, __(':store: :release — :status', [
            'store' => $delivery->dsp->name,
            'release' => $delivery->release->displayTitle(),
            'status' => $delivery->status->label(),
        ]), $delivery->release, array_filter([
            'store' => $delivery->dsp->name,
            'from' => $from->value,
            'to' => $delivery->status->value,
            'comment' => $comment ?: null,
        ]));

        return $delivery;
    }

    /**
     * Applies one action to every delivery of a release where it is possible.
     */
    public function transitionAll(User $actor, Release $release, string $action, ?string $comment = null, ?string $date = null): int
    {
        $count = 0;
        foreach ($release->deliveries()->with(['dsp', 'release'])->get() as $delivery) {
            if (in_array($action, $this->available($delivery), true)) {
                $this->transition($actor, $delivery, $action, $comment, $date);
                $count++;
            }
        }

        return $count;
    }

    public function updateDetails(User $actor, ReleaseDelivery $delivery, ?string $storeId, ?string $storeUrl, ?string $notes): void
    {
        $delivery->fill([
            'store_id' => filled($storeId) ? trim($storeId) : null,
            'store_url' => filled($storeUrl) ? trim($storeUrl) : null,
            'notes' => filled($notes) ? trim($notes) : null,
        ]);

        if ($delivery->isDirty()) {
            $delivery->save();
            $this->activity->log('distribution.updated', __('Updated :store details for :release', ['store' => $delivery->dsp->name, 'release' => $delivery->release->displayTitle()]), $delivery->release);
        }
    }

    /**
     * Whether the release still has deliveries that are (or may be) at a
     * store; such releases cannot be reopened for editing.
     */
    public static function hasActiveDeliveries(Release $release): bool
    {
        return $release->deliveries()->whereIn('status', DeliveryStatus::activeValues())->exists();
    }
}
