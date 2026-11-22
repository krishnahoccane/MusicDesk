<?php

namespace LabelDesk\Addons\RestApi\Http\Controllers;

use App\Enums\ContributorRole;
use App\Models\Release;
use App\Models\Track;
use App\Models\TrackContributor;
use App\Services\Catalog\TrackService;
use App\Support\Identifiers;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\RestApi\Support\Presenter;

/**
 * /api/v1/tracks and /api/v1/releases/{id}/tracks — same rules as the track
 * form (TrackPolicy, TrackService: editable releases only, ISRC checks,
 * credit shares, artist-account scope).
 */
class TrackController extends Controller
{
    protected const RELATIONS = ['artists', 'credits.contributor', 'language'];

    public function __construct(protected TrackService $tracks) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Track::class);

        $params = $this->listParams($request, [
            'search' => ['sometimes', 'string', 'max:100'],
            'release_id' => ['sometimes', 'integer', 'min:1'],
            'artist_id' => ['sometimes', 'integer', 'min:1'],
            'isrc' => ['sometimes', 'string', 'max:20'],
        ], ['title', 'created_at', 'updated_at']);

        // Like Catalog → Tracks: tracks of deleted releases are not listed.
        $query = Track::query()->with(self::RELATIONS)
            ->whereHas('release', fn (Builder $r) => $r->whereNull('releases.deleted_at'))
            ->when(isset($params['search']), fn ($q) => $q->search((string) $params['search']))
            ->when(isset($params['release_id']), fn ($q) => $q->where('release_id', (int) $params['release_id']))
            ->when(isset($params['isrc']), fn ($q) => $q->where('isrc', Identifiers::normalizeIsrc((string) $params['isrc'])))
            ->when(isset($params['artist_id']), fn ($q) => $q->whereHas('artists', fn ($a) => $a->where('artists.id', (int) $params['artist_id'])));

        return $this->paginated($this->applyCommon($query, $params, '-created_at'), $params, fn (Track $t) => Presenter::track($t));
    }

    /** GET /releases/{id}/tracks — in disc and track order. */
    public function forRelease(Request $request, string $id): JsonResponse
    {
        $release = Release::withTrashed()->findOrFail($this->id($id));
        $this->authorize('view', $release);
        $this->authorize('viewAny', Track::class);

        $params = $this->listParams($request, [], []);
        $query = $release->tracks()->with(self::RELATIONS)->getQuery();

        return $this->paginated($query, $params, fn (Track $t) => Presenter::track($t));
    }

    public function show(string $id): JsonResponse
    {
        $track = $this->find($id);
        $this->authorize('view', $track);

        return $this->item(Presenter::track($track));
    }

    /** POST /releases/{id}/tracks */
    public function store(Request $request, string $id): JsonResponse
    {
        $release = Release::withTrashed()->findOrFail($this->id($id));
        $this->authorize('create', [Track::class, $release]);
        $data = $request->validate($this->rules(false), $this->messages());

        // Like the track form: a new track is credited to the release's artists unless others are given.
        $artists = isset($data['artists'])
            ? ReleaseController::artistRows($data['artists'])
            : $release->artists->map(fn ($a) => ['artist_id' => $a->id, 'role' => $a->pivot->role])->values()->all();

        $track = $this->tracks->save($this->user(), $release, null, $this->fields($data, null), $artists, $this->credits($data['credits'] ?? []));

        return $this->item(Presenter::track($this->find((string) $track->id)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $track = $this->find($id);
        $this->authorize('update', $track);
        $data = $request->validate($this->rules(true), $this->messages());

        // Artists and credits are optional on update: keep the current ones when not sent.
        $artists = isset($data['artists'])
            ? ReleaseController::artistRows($data['artists'])
            : $track->artists()->withoutGlobalScope('artist-account')->get()
                ->map(fn ($a) => ['artist_id' => $a->id, 'role' => $a->pivot->role])->values()->all();
        $credits = array_key_exists('credits', $data)
            ? $this->credits($data['credits'] ?? [])
            : $track->credits->map(fn (TrackContributor $c) => [
                'contributor_id' => $c->contributor_id,
                'role' => $c->role->value,
                'share' => $c->share !== null ? (string) $c->share : null,
            ])->values()->all();

        $this->tracks->save($this->user(), $track->release, $track, $this->fields($data, $track), $artists, $credits);

        return $this->item(Presenter::track($this->find($id)));
    }

    public function destroy(string $id): JsonResponse
    {
        $track = $this->find($id);
        $this->authorize('delete', $track);

        $this->tracks->delete($this->user(), $track);

        return $this->noContent();
    }

    protected function find(string $id): Track
    {
        return Track::query()->with(self::RELATIONS)->findOrFail($this->id($id));
    }

    /**
     * Full field set for TrackService::save(); on update, fields not sent keep their value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fields(array $data, ?Track $track): array
    {
        $fields = [
            'title' => $data['title'] ?? $track?->title,
            'version' => array_key_exists('version', $data) ? $data['version'] : $track?->version,
            'isrc' => array_key_exists('isrc', $data) ? $data['isrc'] : $track?->isrc,
            'explicit' => array_key_exists('explicit', $data) ? (bool) $data['explicit'] : (bool) $track?->explicit,
            'language_id' => array_key_exists('language_id', $data) ? $data['language_id'] : $track?->language_id,
            'disc_number' => (int) ($data['disc_number'] ?? $track?->disc_number ?? 1),
        ];

        // Duration is only changed when sent (and ignored by the service once audio is uploaded).
        if (array_key_exists('duration_seconds', $data)) {
            $fields['duration_seconds'] = $data['duration_seconds'];
        }

        return $fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{contributor_id: int, role: string, share: ?string}>
     */
    protected function credits(array $rows): array
    {
        return array_values(array_map(fn ($r) => [
            'contributor_id' => (int) $r['contributor_id'],
            'role' => (string) $r['role'],
            'share' => isset($r['share']) && $r['share'] !== '' ? (string) $r['share'] : null,
        ], $rows));
    }

    /** @return array<string, mixed> the track form rules (without the audio upload; duration in seconds) */
    protected function rules(bool $partial): array
    {
        return [
            'title' => [$partial ? 'sometimes' : 'required', 'required', 'string', 'max:200'],
            'version' => ['nullable', 'string', 'max:100'],
            'isrc' => ['nullable', 'string', 'max:20', function (string $a, mixed $v, Closure $fail) {
                $n = Identifiers::normalizeIsrc((string) $v);
                if ($n !== null && ! Identifiers::isValidIsrc($n)) {
                    $fail(__('The ISRC must look like CC-XXX-YY-NNNNN (12 characters).'));
                }
            }],
            'disc_number' => ['sometimes', 'integer', 'between:1,20'],
            'duration_seconds' => ['nullable', 'integer', 'between:1,59999'],
            'explicit' => ['sometimes', 'boolean'],
            'language_id' => ['nullable', 'integer', Rule::exists('languages', 'id')],
            'credits' => ['sometimes', 'nullable', 'array', 'max:50'],
            'credits.*' => ['array:contributor_id,role,share'],
            'credits.*.contributor_id' => ['required', 'integer', Rule::exists('contributors', 'id')],
            'credits.*.role' => ['required', Rule::enum(ContributorRole::class)],
            'credits.*.share' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            ...ReleaseController::artistRules(true),
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            ...ReleaseController::artistMessages(),
            'credits.*.share.regex' => __('Enter a percentage such as 50 or 33.33.'),
        ];
    }
}
