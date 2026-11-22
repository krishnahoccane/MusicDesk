<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One release at one store.
 */
class ReleaseDelivery extends Model
{
    /** @var list<string> */
    protected $fillable = ['dsp_id', 'store_id', 'store_url', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'delivered_on' => 'date',
            'live_on' => 'date',
            'taken_down_on' => 'date',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function dsp(): BelongsTo
    {
        return $this->belongsTo(Dsp::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class)->latest('id');
    }

    /**
     * Pending deliveries whose store lead time has already passed relative to
     * the release date (the label risks missing the release date there).
     */
    public function isLate(): bool
    {
        $releaseDate = $this->release?->release_date;
        $lead = $this->dsp?->lead_time_days;

        if ($this->status !== DeliveryStatus::Pending || ! $releaseDate || ! $lead) {
            return false;
        }

        $today = now()->setTimezone(config('labeldesk.display_timezone'))->startOfDay();

        return Carbon::parse($releaseDate)->subDays($lead)->lt($today);
    }
}
