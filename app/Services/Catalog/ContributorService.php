<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Models\Contributor;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Str;

class ContributorService
{
    public function __construct(protected ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?Contributor $contributor, array $data): Contributor
    {
        $creating = $contributor === null;
        $contributor ??= new Contributor;

        $contributor->fill([
            'name' => trim($data['name']),
            'legal_name' => filled($data['legal_name'] ?? null) ? trim($data['legal_name']) : null,
            'ipi_number' => filled($data['ipi_number'] ?? null) ? preg_replace('/\D/', '', $data['ipi_number']) : null,
            'pro' => filled($data['pro'] ?? null) ? trim($data['pro']) : null,
            'email' => filled($data['email'] ?? null) ? Str::lower(trim($data['email'])) : null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ]);
        if ($creating) {
            $contributor->created_by = $actor->id;
        }
        $contributor->save();

        $this->activity->log(
            $creating ? 'contributor.created' : 'contributor.updated',
            $creating ? __('Added contributor :name', ['name' => $contributor->name]) : __('Updated contributor :name', ['name' => $contributor->name]),
            $contributor,
        );

        return $contributor;
    }

    public function delete(User $actor, Contributor $contributor): void
    {
        $credits = $contributor->credits()->count();
        if ($credits > 0) {
            throw new CatalogException(trans_choice(
                '{1} :name is credited on 1 track and cannot be deleted.|[2,*] :name is credited on :count tracks and cannot be deleted.',
                $credits,
                ['name' => $contributor->name, 'count' => $credits],
            ));
        }

        $contributor->delete();
        $this->activity->log('contributor.deleted', __('Deleted contributor :name', ['name' => $contributor->name]), null);
    }
}
