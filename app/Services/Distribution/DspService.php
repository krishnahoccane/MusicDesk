<?php

namespace App\Services\Distribution;

use App\Exceptions\CatalogException;
use App\Models\Dsp;
use App\Models\User;
use App\Services\Activity\ActivityLogger;

class DspService
{
    public function __construct(protected ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?Dsp $dsp, array $data): Dsp
    {
        $creating = $dsp === null;
        $dsp ??= new Dsp;

        $dsp->fill([
            'name' => trim($data['name']),
            'website' => filled($data['website'] ?? null) ? trim($data['website']) : null,
            'lead_time_days' => filled($data['lead_time_days'] ?? null) ? (int) $data['lead_time_days'] : null,
            'delivery_notes' => filled($data['delivery_notes'] ?? null) ? trim($data['delivery_notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        $this->activity->log($creating ? 'dsp.created' : 'dsp.updated', ($creating ? __('Added store :name', ['name' => $dsp->name]) : __('Updated store :name', ['name' => $dsp->name])), $dsp);

        return $dsp;
    }

    public function delete(User $actor, Dsp $dsp): void
    {
        if ($dsp->deliveries()->exists()) {
            throw new CatalogException(__(':name has deliveries and cannot be deleted. Deactivate it instead.', ['name' => $dsp->name]));
        }

        $dsp->delete();
        $this->activity->log('dsp.deleted', __('Deleted store :name', ['name' => $dsp->name]), null);
    }
}
