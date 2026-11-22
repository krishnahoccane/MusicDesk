<?php

namespace LabelDesk\Addons\RestApi\Livewire;

use Illuminate\Contracts\View\View;
use LabelDesk\Addons\RestApi\Support\Abilities;
use Livewire\Component;

/** Administration → API tokens → API reference: a short guide to /api/v1. */
class Reference extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('api.tokens'), 403);
    }

    public function render(): View
    {
        return view('rest-api::reference', [
            'baseUrl' => rtrim(url('/api/v1'), '/'),
            'abilities' => Abilities::all(),
            'rateLimit' => (int) config('rest-api.rate_limit', 120),
            'perPage' => (int) config('rest-api.per_page', 25),
            'maxPerPage' => (int) config('rest-api.max_per_page', 100),
            'endpoints' => self::endpoints(),
        ])->title(__('API reference'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')],
            ['label' => __('API tokens'), 'url' => route('rest-api.tokens')],
            ['label' => __('API reference')],
        ]]);
    }

    /** @return array<string, list<array{0: string, 1: string, 2: string, 3: string}>> group => [method, path, ability, description] */
    public static function endpoints(): array
    {
        return [
            __('Account') => [
                ['GET', '/me', '—', __('The token owner, roles and the token’s abilities.')],
                ['GET', '/reference', '—', __('Genres, languages and accepted values (types, statuses, roles, workflow actions).')],
            ],
            __('Artists') => [
                ['GET', '/artists', 'artists:read', __('List. Filters: search, status, type, country, trashed=with|only, updated_since. Sort: name, created_at, updated_at.')],
                ['GET', '/artists/{id}', 'artists:read', __('One artist.')],
                ['POST', '/artists', 'artists:write', __('Create: name, type, status (required); legal_name, country, primary_genre, email, phone, bio, notes.')],
                ['PATCH', '/artists/{id}', 'artists:write', __('Change the fields you send.')],
                ['DELETE', '/artists/{id}', 'artists:write', __('Delete (restorable). Credited artists cannot be deleted.')],
                ['POST', '/artists/{id}/restore', 'artists:write', __('Restore a deleted artist.')],
            ],
            __('Releases') => [
                ['GET', '/releases', 'releases:read', __('List. Filters: search, status, type, artist_id, upc, release_date_from, release_date_to, trashed, updated_since. Sort: title, release_date, created_at, updated_at.')],
                ['GET', '/releases/{id}', 'releases:read', __('One release with its credited artists and the workflow actions you can take.')],
                ['POST', '/releases', 'releases:write', __('Create: title, type, artists [{artist_id, role}] (required); version, upc, catalog_number, label_name, c_line, p_line, release_date, original_release_date, genre_id, language_id, notes.')],
                ['PATCH', '/releases/{id}', 'releases:write', __('Change the fields you send (draft or changes-requested releases only).')],
                ['DELETE', '/releases/{id}', 'releases:write', __('Delete (restorable; editable releases only).')],
                ['POST', '/releases/{id}/restore', 'releases:write', __('Restore a deleted release.')],
                ['POST', '/releases/{id}/workflow', 'releases:write', __('Workflow step: action (submit, withdraw, start_review, approve, reject, mark_ready, reopen) and comment.')],
            ],
            __('Tracks') => [
                ['GET', '/tracks', 'tracks:read', __('List. Filters: search, release_id, artist_id, isrc, updated_since. Sort: title, created_at, updated_at.')],
                ['GET', '/releases/{id}/tracks', 'tracks:read', __('Tracks of a release in disc and track order.')],
                ['GET', '/tracks/{id}', 'tracks:read', __('One track with artists and contributor credits.')],
                ['POST', '/releases/{id}/tracks', 'tracks:write', __('Add: title (required); version, isrc, explicit, language_id, disc_number, duration_seconds, artists, credits [{contributor_id, role, share}].')],
                ['PATCH', '/tracks/{id}', 'tracks:write', __('Change the fields you send. Sending artists or credits replaces them.')],
                ['DELETE', '/tracks/{id}', 'tracks:write', __('Delete a track (editable releases only).')],
            ],
            __('Contributors') => [
                ['GET', '/contributors', 'contributors:read', __('List. Filters: search, ipi_number, updated_since. Sort: name, created_at, updated_at.')],
                ['GET', '/contributors/{id}', 'contributors:read', __('One contributor.')],
                ['POST', '/contributors', 'contributors:write', __('Create: name (required); legal_name, ipi_number, pro, email, notes.')],
                ['PATCH', '/contributors/{id}', 'contributors:write', __('Change the fields you send.')],
                ['DELETE', '/contributors/{id}', 'contributors:write', __('Delete (only when not credited on any track).')],
            ],
        ];
    }
}
