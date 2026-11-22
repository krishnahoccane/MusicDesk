<?php

namespace App\Livewire\Dashboard;

use App\Enums\DeliveryStatus;
use App\Enums\LicenseState;
use App\Enums\ReleaseStatus;
use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\ArtistContract;
use App\Models\ArtistLedgerEntry;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Models\User;
use App\Services\License\LicenseService;
use App\Services\Royalties\RoyaltyPeriodService;
use App\Support\ArtistAccess;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Dashboard. Every section is chosen by the viewer's permissions and the
 * license plan, so a label manager, an accountant and an administrator each
 * see what matters to them; administrators also get system health.
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Overview extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        $license = app(LicenseService::class);
        $can = fn (string $permission, ?string $feature = null) => $user->can($permission) && ($feature === null || $license->canUse($feature));

        return view('livewire.dashboard.overview', [
            'greeting' => $this->greeting(),
            'firstName' => explode(' ', trim($user->name))[0] ?: $user->name,
            'today' => now()->setTimezone(config('labeldesk.display_timezone'))->translatedFormat('l, j F Y'),
            'actions' => $this->quickActions($can),
            'kpis' => $this->kpis($can),
            'charts' => $this->charts($can),
            'attention' => $this->attention($can, $license),
            'upcoming' => $can('releases.view', 'releases') ? $this->upcoming() : null,
            'activity' => $user->can('activity.view') ? ActivityLog::query()->with('user:id,name,deleted_at')->latest('id')->limit(8)->get() : null,
            'admin' => $this->admin($user, $license),
            'currency' => config('labeldesk.currency'),
        ]);
    }

    /**
     * @return list<array{label: string, icon: string, url: string}>
     */
    protected function quickActions(callable $can): array
    {
        return array_values(array_filter([
            $can('releases.create', 'releases') ? ['label' => __('New release'), 'icon' => 'disc-3', 'url' => route('releases.create')] : null,
            $can('artists.create', 'artists') ? ['label' => __('Add artist'), 'icon' => 'mic-vocal', 'url' => route('artists.create')] : null,
            $can('royalties.import', 'royalties') ? ['label' => __('Import statement'), 'icon' => 'file-spreadsheet', 'url' => route('royalties.index')] : null,
            $can('distribution.view', 'distribution') ? ['label' => __('Distribution'), 'icon' => 'send', 'url' => route('distribution.index')] : null,
            $can('users.manage') ? ['label' => __('Invite user'), 'icon' => 'user-plus', 'url' => route('users.create')] : null,
        ]));
    }

    /**
     * @return list<array{label: string, value: string, icon: string, variant: ?string, hint: ?string, url: ?string}>
     */
    protected function kpis(callable $can): array
    {
        $kpis = [];

        if ($can('artists.view', 'artists')) {
            $total = Artist::query()->count();
            $active = Artist::query()->where('status', 'active')->count();
            $kpis[] = ['label' => __('Artists'), 'value' => number_format($total), 'icon' => 'mic-vocal', 'variant' => null,
                'hint' => __(':count active on the roster', ['count' => number_format($active)]), 'url' => route('artists.index')];
        }

        if ($can('releases.view', 'releases')) {
            $counts = Release::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $ready = (int) ($counts[ReleaseStatus::Ready->value] ?? 0);
            $kpis[] = ['label' => __('Releases'), 'value' => number_format((int) $counts->sum()), 'icon' => 'disc-3', 'variant' => 'accent',
                'hint' => __(':count ready for delivery', ['count' => number_format($ready)]), 'url' => route('releases.index')];
        }

        if ($can('distribution.view', 'distribution')) {
            $live = ReleaseDelivery::query()->where('status', DeliveryStatus::Live->value)
                ->whereHas('release', fn ($q) => $q->whereNull('releases.deleted_at'))->count();
            $kpis[] = ['label' => __('Live in stores'), 'value' => number_format($live), 'icon' => 'radio', 'variant' => 'success',
                'hint' => __('release × store placements'), 'url' => route('distribution.index', ['status' => 'live'])];
        }

        if ($can('royalties.view', 'royalties')) {
            $period = RoyaltyPeriod::query()->orderByDesc('ends_on')->first();
            if ($period) {
                $totals = RoyaltyPeriodService::totals($period);
                $kpis[] = ['label' => __('Revenue · :period', ['period' => $period->name]), 'value' => Format::money($totals['revenue']),
                    'icon' => 'wallet', 'variant' => 'info', 'hint' => __(':amount to artists', ['amount' => Format::money($totals['artist'])]),
                    'url' => route('royalties.periods.show', $period)];
            }
        }

        if ($can('payments.view', 'royalties')) {
            $balances = [];
            foreach (ArtistLedgerEntry::query()->select(['artist_id', 'amount'])->cursor() as $entry) {
                $balances[$entry->artist_id] = Decimal::add($balances[$entry->artist_id] ?? '0', (string) $entry->amount);
            }
            $owed = Decimal::sum(array_filter($balances, fn ($b) => Decimal::compare($b, '0') > 0));
            if (ArtistAccess::restricted()) {
                // Artist accounts: their own balance, linking to their earnings.
                $own = Decimal::sum($balances);
                $first = ArtistAccess::ids()[0] ?? null;
                $kpis[] = ['label' => __('Your balance'), 'value' => Format::money($own), 'icon' => 'hand-coins', 'variant' => 'success',
                    'hint' => __('Earnings minus advances and payments'),
                    'url' => $first ? route('artists.show', ['artist' => $first, 'tab' => 'earnings']) : null];

                return array_slice($kpis, 0, 4);
            }
            $kpis[] = ['label' => __('Owed to artists'), 'value' => Format::money($owed), 'icon' => 'hand-coins', 'variant' => 'warning',
                'hint' => trans_choice('{0} no open balances|{1} 1 artist with a balance|[2,*] :count artists with a balance', count(array_filter($balances, fn ($b) => Decimal::compare($b, '0') > 0)), ['count' => count(array_filter($balances, fn ($b) => Decimal::compare($b, '0') > 0))]),
                'url' => route('royalties.balances', ['filter' => 'owed'])];
        }

        return array_slice($kpis, 0, 4);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function charts(callable $can): array
    {
        $charts = [];

        if ($can('royalties.view', 'royalties')) {
            $periods = RoyaltyPeriod::query()->orderByDesc('starts_on')->limit(8)->get()->reverse()->values();
            if ($periods->isNotEmpty()) {
                $revenue = [];
                $artist = [];
                foreach ($periods as $period) {
                    $t = RoyaltyPeriodService::totals($period);
                    $revenue[] = (float) Decimal::round($t['revenue'], 2);
                    $artist[] = (float) Decimal::round($t['artist'], 2);
                }
                $charts['revenue'] = [
                    'type' => $periods->count() > 1 ? 'area' : 'bar', 'height' => 280, 'format' => 'money',
                    'categories' => $periods->pluck('name')->all(),
                    'series' => [['name' => __('Revenue'), 'data' => $revenue], ['name' => __('To artists'), 'data' => $artist]],
                ];
            }
        }

        if ($can('releases.view', 'releases')) {
            $counts = Release::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            if ($counts->sum() > 0) {
                $labels = [];
                $series = [];
                foreach (ReleaseStatus::cases() as $status) {
                    if (($counts[$status->value] ?? 0) > 0) {
                        $labels[] = $status->label();
                        $series[] = (int) $counts[$status->value];
                    }
                }
                $charts['releases'] = ['type' => 'donut', 'height' => 280, 'labels' => $labels, 'series' => $series];
            }
        }

        if ($can('distribution.view', 'distribution')) {
            $byStore = ReleaseDelivery::query()
                ->join('dsps', 'dsps.id', '=', 'release_deliveries.dsp_id')
                ->whereIn('release_deliveries.status', [DeliveryStatus::Live->value, DeliveryStatus::Delivered->value])
                ->selectRaw('dsps.name as store, count(*) as total')
                ->groupBy('dsps.name')->orderByDesc('total')->limit(6)->pluck('total', 'store');
            if ($byStore->isNotEmpty()) {
                $charts['stores'] = [
                    'type' => 'bar', 'height' => 280, 'horizontal' => true, 'format' => 'integer',
                    'categories' => $byStore->keys()->all(),
                    'series' => [['name' => __('Releases'), 'data' => $byStore->values()->map(fn ($v) => (int) $v)->all()]],
                ];
            }
        }

        return $charts;
    }

    /**
     * Things someone should act on, with links.
     *
     * @return list<array{icon: string, variant: string, title: string, meta: string, url: string}>
     */
    protected function attention(callable $can, LicenseService $license): array
    {
        $items = [];

        if ($can('releases.review', 'releases')) {
            $n = Release::query()->whereIn('status', [ReleaseStatus::Submitted->value, ReleaseStatus::InReview->value])->count();
            if ($n) {
                $items[] = ['icon' => 'git-pull-request-arrow', 'variant' => 'info', 'title' => trans_choice('{1} 1 release waiting for review|[2,*] :count releases waiting for review', $n, ['count' => $n]),
                    'meta' => __('Approve or request changes'), 'url' => route('releases.index', ['status' => 'submitted'])];
            }
        }

        if ($can('releases.update', 'releases')) {
            $n = Release::query()->where('status', ReleaseStatus::Rejected->value)->count();
            if ($n) {
                $items[] = ['icon' => 'message-square-warning', 'variant' => 'warning', 'title' => trans_choice('{1} 1 release needs changes|[2,*] :count releases need changes', $n, ['count' => $n]),
                    'meta' => __('Reviewers requested changes'), 'url' => route('releases.index', ['status' => 'rejected'])];
            }
        }

        if ($can('distribution.manage', 'distribution')) {
            $n = ReleaseDelivery::query()->whereIn('status', [DeliveryStatus::Rejected->value, DeliveryStatus::TakedownRequested->value])->count();
            if ($n) {
                $items[] = ['icon' => 'triangle-alert', 'variant' => 'danger', 'title' => trans_choice('{1} 1 store rejection or takedown|[2,*] :count store rejections or takedowns', $n, ['count' => $n]),
                    'meta' => __('Follow up with the store'), 'url' => route('distribution.index', ['status' => 'rejected'])];
            }
            $ready = Release::query()->where('status', ReleaseStatus::Ready->value)->whereDoesntHave('deliveries')->count();
            if ($ready) {
                $items[] = ['icon' => 'package-check', 'variant' => 'info', 'title' => trans_choice('{1} 1 ready release not sent to stores|[2,*] :count ready releases not sent to stores', $ready, ['count' => $ready]),
                    'meta' => __('Add stores on the Distribution tab'), 'url' => route('distribution.index')];
            }
        }

        if ($can('royalties.manage', 'royalties')) {
            $n = RoyaltyLine::query()->where('match_status', 'unmatched')->count();
            if ($n) {
                $items[] = ['icon' => 'circle-help', 'variant' => 'warning', 'title' => trans_choice('{1} 1 unmatched sales line|[2,*] :count unmatched sales lines', $n, ['count' => number_format($n)]),
                    'meta' => __('Not paid to anyone until matched'), 'url' => route('royalties.index')];
            }
        }

        if ($can('contracts.view', 'documents')) {
            $n = ArtistContract::query()->whereHas('artist', fn ($q) => $q->whereNull('artists.deleted_at'))->expiringWithin(60)->count();
            if ($n) {
                $items[] = ['icon' => 'calendar-clock', 'variant' => 'warning', 'title' => trans_choice('{1} 1 contract ends within 60 days|[2,*] :count contracts end within 60 days', $n, ['count' => $n]),
                    'meta' => __('Renew or plan ahead'), 'url' => route('contracts.index', ['status' => 'expiring'])];
            }
        }

        if (auth()->user()->can('license.view')) {
            $snapshot = $license->snapshot();
            if ($snapshot->state !== LicenseState::Active) {
                $items[] = ['icon' => 'key-round', 'variant' => 'danger', 'title' => $snapshot->state->label(), 'meta' => (string) $snapshot->reason, 'url' => route('license.show')];
            }
        }

        return $items;
    }

    protected function upcoming()
    {
        $today = now()->setTimezone(config('labeldesk.display_timezone'))->toDateString();

        return Release::query()
            ->with('artists:artists.id,artists.name')
            ->whereNotNull('release_date')
            ->whereDate('release_date', '>=', $today)
            ->whereDate('release_date', '<=', now()->addDays(60)->toDateString())
            ->orderBy('release_date')
            ->limit(6)
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function admin(User $user, LicenseService $license): ?array
    {
        if (! $user->can('users.view') && ! $user->can('settings.manage') && ! $user->can('license.view')) {
            return null;
        }

        $snapshot = $license->snapshot();
        $activation = $snapshot->activation;
        $active = User::query()->where('is_active', true)->count();
        $limit = $snapshot->result?->userLimit;

        $health = [];
        $health[] = ['label' => 'PHP '.PHP_VERSION, 'state' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'danger'];
        $health[] = ['label' => __('Database: :driver', ['driver' => DB::connection()->getDriverName()]), 'state' => 'ok'];

        try {
            $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        } catch (Throwable) {
            $pending = $failed = 0;
        }
        $health[] = ['label' => __('Queue: :pending waiting, :failed failed', ['pending' => $pending, 'failed' => $failed]), 'state' => $failed > 0 ? 'warning' : ($pending > 20 ? 'warning' : 'ok')];
        $health[] = ['label' => is_writable(storage_path('app/private')) ? __('Storage is writable') : __('Storage is not writable'), 'state' => is_writable(storage_path('app/private')) ? 'ok' : 'danger'];
        $health[] = ['label' => config('mail.default') === 'log' ? __('Email: log only (not sent)') : __('Email: :mailer', ['mailer' => config('mail.default')]), 'state' => config('mail.default') === 'log' ? 'warning' : 'ok'];

        return [
            'license' => $user->can('license.view') ? [
                'state' => $snapshot->state,
                'plan' => $activation?->plan,
                'domain' => $activation?->domain,
                'checked' => $activation?->last_verified_at,
                'support' => $activation?->support_until,
                'messages' => (array) ($activation?->messages ?? []),
            ] : null,
            'users' => $user->can('users.view') ? ['active' => $active, 'limit' => $limit, 'total' => User::query()->count()] : null,
            'health' => $user->can('settings.manage') ? $health : null,
        ];
    }

    protected function greeting(): string
    {
        $hour = (int) now()->setTimezone(config('labeldesk.display_timezone'))->format('G');

        return match (true) {
            $hour < 12 => __('Good morning'),
            $hour < 18 => __('Good afternoon'),
            default => __('Good evening'),
        };
    }
}
