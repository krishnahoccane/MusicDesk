<?php

namespace App\Livewire;

use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\License\LicenseService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Topbar search (Ctrl+K / "/"): artists, releases, tracks, contributors and
 * users — only what the signed-in user may see and the license includes.
 */
class GlobalSearch extends Component
{
    public string $query = '';

    /**
     * @return list<array{label: string, icon: string, items: list<array{title: string, meta: string, url: string}>}>
     */
    protected function results(): array
    {
        $term = trim($this->query);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $user = auth()->user();
        $license = app(LicenseService::class);
        $groups = [];

        if ($license->canUse('artists') && $user->can('artists.view')) {
            $groups[] = ['label' => __('Artists'), 'icon' => 'mic-vocal', 'items' => Artist::query()->search($term)->orderBy('name')->limit(5)->get()
                ->map(fn (Artist $a) => ['title' => $a->name, 'meta' => trim($a->type->label().($a->primary_genre ? ' · '.$a->primary_genre : '')), 'url' => route('artists.show', $a)])->all()];
        }

        if ($license->canUse('releases') && $user->can('releases.view')) {
            $groups[] = ['label' => __('Releases'), 'icon' => 'disc-3', 'items' => Release::query()->with('artists:artists.id,artists.name')->search($term)->latest('id')->limit(5)->get()
                ->map(fn (Release $r) => ['title' => $r->displayTitle(), 'meta' => trim($r->artistLine().' · '.$r->status->label(), ' ·'), 'url' => route('releases.show', $r)])->all()];
        }

        if ($license->canUse('tracks') && $user->can('tracks.view')) {
            $groups[] = ['label' => __('Tracks'), 'icon' => 'audio-lines', 'items' => Track::query()->with(['artists:artists.id,artists.name', 'release:id,title,version,deleted_at'])
                ->whereHas('release', fn ($q) => $q->whereNull('releases.deleted_at'))
                ->search($term)->limit(5)->get()
                ->map(fn (Track $t) => ['title' => $t->displayTitle(), 'meta' => trim(($t->formattedIsrc() ? $t->formattedIsrc().' · ' : '').$t->artistLine()), 'url' => route('tracks.show', $t)])->all()];
        }

        if ($license->canUse('releases') && $user->can('contributors.view')) {
            $groups[] = ['label' => __('Contributors'), 'icon' => 'users-round', 'items' => Contributor::query()->search($term)->orderBy('name')->limit(4)->get()
                ->map(fn (Contributor $c) => ['title' => $c->name, 'meta' => $c->ipi_number ? 'IPI '.$c->ipi_number : '', 'url' => route('contributors.index', ['search' => $c->name])])->all()];
        }

        if ($user->can('users.view')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $groups[] = ['label' => __('Users'), 'icon' => 'user-cog', 'items' => User::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))->limit(4)->get()
                ->map(fn (User $u) => ['title' => $u->name, 'meta' => $u->email, 'url' => $user->can('update', $u) ? route('users.edit', $u) : route('users.index', ['search' => $u->email])])->all()];
        }

        return array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
    }

    public function render(): View
    {
        return view('livewire.global-search', [
            'groups' => $this->results(),
            'searched' => mb_strlen(trim($this->query)) >= 2,
        ]);
    }
}
