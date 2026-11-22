<?php

namespace App\Services\Catalog;

use App\Enums\ArtistRole;
use App\Enums\ContributorRole;
use App\Enums\ReleaseStatus;
use App\Exceptions\CatalogException;
use App\Models\Release;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Distribution\DistributionService;
use Illuminate\Support\Facades\DB;

/**
 * The release approval workflow. Every transition is permission-checked,
 * re-validated against the current status inside a locked transaction, and
 * recorded in release_status_histories and the activity log.
 */
class ReleaseWorkflow
{
    /**
     * action => [allowed from, to, permission, comment required, checklist required]
     *
     * @var array<string, array{from: list<ReleaseStatus>, to: ReleaseStatus, permission: string, comment: bool, checklist: bool}>
     */
    protected array $actions;

    public function __construct(protected ActivityLogger $activity)
    {
        $this->actions = [
            'submit' => ['from' => [ReleaseStatus::Draft, ReleaseStatus::Rejected], 'to' => ReleaseStatus::Submitted, 'permission' => 'releases.submit', 'comment' => false, 'checklist' => true],
            'withdraw' => ['from' => [ReleaseStatus::Submitted], 'to' => ReleaseStatus::Draft, 'permission' => 'releases.submit', 'comment' => false, 'checklist' => false],
            'start_review' => ['from' => [ReleaseStatus::Submitted], 'to' => ReleaseStatus::InReview, 'permission' => 'releases.review', 'comment' => false, 'checklist' => false],
            'approve' => ['from' => [ReleaseStatus::Submitted, ReleaseStatus::InReview], 'to' => ReleaseStatus::Approved, 'permission' => 'releases.review', 'comment' => false, 'checklist' => true],
            'reject' => ['from' => [ReleaseStatus::Submitted, ReleaseStatus::InReview], 'to' => ReleaseStatus::Rejected, 'permission' => 'releases.review', 'comment' => true, 'checklist' => false],
            'mark_ready' => ['from' => [ReleaseStatus::Approved], 'to' => ReleaseStatus::Ready, 'permission' => 'releases.review', 'comment' => false, 'checklist' => true],
            'reopen' => ['from' => [ReleaseStatus::Approved, ReleaseStatus::Ready], 'to' => ReleaseStatus::Draft, 'permission' => 'releases.review', 'comment' => true, 'checklist' => false],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'submit' => __('Submit for review'),
            'withdraw' => __('Withdraw submission'),
            'start_review' => __('Start review'),
            'approve' => __('Approve'),
            'reject' => __('Request changes'),
            'mark_ready' => __('Mark ready for delivery'),
            'reopen' => __('Reopen as draft'),
        ];
    }

    /**
     * Actions the user can take on the release right now.
     *
     * @return list<string>
     */
    public function available(User $user, Release $release): array
    {
        if ($release->trashed()) {
            return [];
        }

        $available = array_values(array_filter(array_keys($this->actions), function (string $action) use ($user, $release) {
            $def = $this->actions[$action];

            return in_array($release->status, $def['from'], true) && $user->can($def['permission']);
        }));

        // Reopening is impossible while the release is pending, delivered or live at a store.
        if (in_array('reopen', $available, true) && DistributionService::hasActiveDeliveries($release)) {
            $available = array_values(array_diff($available, ['reopen']));
        }

        return $available;
    }

    public function requiresComment(string $action): bool
    {
        return $this->actions[$action]['comment'] ?? false;
    }

    public function transition(User $user, Release $release, string $action, ?string $comment = null): Release
    {
        $def = $this->actions[$action] ?? throw new CatalogException(__('Unknown workflow action.'));

        if (! $user->can($def['permission'])) {
            throw new CatalogException(__('You do not have permission to do this.'));
        }

        $comment = $comment !== null ? trim($comment) : null;
        if ($def['comment'] && ($comment === null || $comment === '')) {
            throw new CatalogException(__('Please add a comment explaining why.'));
        }

        $from = DB::transaction(function () use ($release, $def, $user, $comment, $action) {
            /** @var Release $locked */
            $locked = Release::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $def['from'], true)) {
                throw new CatalogException(__('This release is now :status, so that step is no longer possible. Refresh the page.', ['status' => strtolower($locked->status->label())]));
            }

            if ($action === 'reopen' && DistributionService::hasActiveDeliveries($locked)) {
                throw new CatalogException(__('This release is pending, delivered or live at one or more stores. Remove pending stores and take it down everywhere before reopening it.'));
            }

            if ($def['checklist']) {
                $missing = collect($this->checklist($locked))->reject(fn ($item) => $item['ok'])->pluck('label');
                if ($missing->isNotEmpty()) {
                    throw new CatalogException(__('The release is not complete yet: :items.', ['items' => $missing->implode('; ')]));
                }
            }

            $from = $locked->status;
            $locked->status = $def['to'];
            if ($action === 'submit') {
                $locked->submitted_at = now();
            }
            if ($action === 'approve') {
                $locked->approved_at = now();
            }
            if ($def['to'] === ReleaseStatus::Draft) {
                $locked->approved_at = null;
            }
            $locked->save();

            $locked->history()->create([
                'from_status' => $from->value,
                'to_status' => $def['to']->value,
                'user_id' => $user->id,
                'comment' => $comment ?: null,
            ]);

            return $from;
        });

        $release->refresh();

        $this->activity->log('release.'.$action, __(':action: :title', ['action' => self::labels()[$action], 'title' => $release->displayTitle()]), $release, array_filter([
            'from' => $from->value,
            'to' => $release->status->value,
            'comment' => $comment ?: null,
        ]));

        return $release;
    }

    /**
     * Completeness checks before a release can be submitted or approved.
     *
     * @return list<array{label: string, ok: bool}>
     */
    public function checklist(Release $release): array
    {
        $tracks = $release->tracks()
            ->withCount([
                'artists as primary_artists_count' => fn ($q) => $q->where('track_artists.role', ArtistRole::Primary->value),
                'credits as writer_credits_count' => fn ($q) => $q->whereIn('role', [
                    ContributorRole::Composer->value, ContributorRole::Lyricist->value, ContributorRole::Songwriter->value,
                ]),
            ])
            ->get();

        return [
            ['label' => __('Primary artist'), 'ok' => $release->primaryArtists()->exists()],
            ['label' => __('Release date'), 'ok' => $release->release_date !== null],
            ['label' => __('Genre and language'), 'ok' => $release->genre_id !== null && $release->language_id !== null],
            ['label' => __('© and ℗ lines'), 'ok' => filled($release->c_line) && filled($release->p_line)],
            ['label' => __('Artwork'), 'ok' => $release->artwork_path !== null],
            ['label' => __('At least one track'), 'ok' => $tracks->isNotEmpty()],
            ['label' => __('Every track has a primary artist'), 'ok' => $tracks->isNotEmpty() && $tracks->every(fn ($t) => $t->primary_artists_count > 0)],
            ['label' => __('Every track has a master audio file'), 'ok' => $tracks->isNotEmpty() && $tracks->every(fn ($t) => $t->audio_path !== null)],
            ['label' => __('Every track has a songwriter credit'), 'ok' => $tracks->isNotEmpty() && $tracks->every(fn ($t) => $t->writer_credits_count > 0)],
        ];
    }
}
