<?php

namespace App\Livewire\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Administration → Activity log (read-only).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $user = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('activity.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'event', 'user', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'event', 'user', 'from', 'to');
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    /**
     * Interprets a Y-m-d filter date in the display timezone and returns UTC.
     */
    protected function boundary(string $date, bool $end): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $date, config('labeldesk.display_timezone'));
        } catch (Throwable) {
            return null;
        }

        return ($end ? $day->endOfDay() : $day->startOfDay())->utc();
    }

    public function render(): View
    {
        $from = $this->boundary($this->from, false);
        $to = $this->boundary($this->to, true);

        $entries = ActivityLog::query()
            ->with('user:id,name,email,deleted_at')
            ->when($this->search !== '', function ($q) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
                $q->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('ip_address', 'like', $term));
            })
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->user === 'system', fn ($q) => $q->whereNull('user_id'))
            ->when(ctype_digit($this->user), fn ($q) => $q->where('user_id', (int) $this->user))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->latest('id')
            ->paginate(25);

        return view('livewire.activity.index', [
            'entries' => $entries,
            'events' => ActivityLogger::events(),
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
        ])->title(__('Activity log'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')], ['label' => __('Activity log')],
        ]]);
    }
}
