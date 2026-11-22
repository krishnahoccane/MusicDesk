<?php

namespace LabelDesk\Addons\RestApi\Http\Controllers;

use App\Enums\ArtistRole;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Models\Release;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\ReleaseWorkflow;
use App\Support\Identifiers;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LabelDesk\Addons\RestApi\Support\ApiException;
use LabelDesk\Addons\RestApi\Support\Presenter;

/**
 * /api/v1/releases — same rules as Catalog → Releases (ReleasePolicy,
 * ReleaseService, ReleaseWorkflow, artist-account scope).
 */
class ReleaseController extends Controller
{
    public function __construct(
        protected ReleaseService $releases,
        protected ReleaseWorkflow $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Release::class);

        $params = $this->listParams($request, [
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(ReleaseStatus::class)],
            'type' => ['sometimes', Rule::enum(ReleaseType::class)],
            'artist_id' => ['sometimes', 'integer', 'min:1'],
            'upc' => ['sometimes', 'string', 'max:20'],
            'release_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'release_date_to' => ['sometimes', 'date_format:Y-m-d'],
            'trashed' => ['sometimes', Rule::in(['with', 'only'])],
        ], ['title', 'release_date', 'created_at', 'updated_at']);

        $query = match ($params['trashed'] ?? null) {
            'with' => Release::withTrashed(),
            'only' => Release::onlyTrashed(),
            default => Release::query(),
        };

        $query->with(['artists', 'genre', 'language'])->withCount('tracks')
            ->when(isset($params['search']), fn ($q) => $q->search((string) $params['search']))
            ->when(isset($params['status']), fn ($q) => $q->where('status', $params['status']))
            ->when(isset($params['type']), fn ($q) => $q->where('type', $params['type']))
            ->when(isset($params['upc']), fn ($q) => $q->where('upc', Identifiers::normalizeUpc((string) $params['upc'])))
            ->when(isset($params['artist_id']), fn ($q) => $q->whereHas('artists', fn ($a) => $a->where('artists.id', (int) $params['artist_id'])))
            ->when(isset($params['release_date_from']), fn ($q) => $q->whereDate('release_date', '>=', $params['release_date_from']))
            ->when(isset($params['release_date_to']), fn ($q) => $q->whereDate('release_date', '<=', $params['release_date_to']));

        return $this->paginated($this->applyCommon($query, $params, '-created_at'), $params, fn (Release $r) => Presenter::release($r));
    }

    public function show(string $id): JsonResponse
    {
        $release = $this->find($id);
        $this->authorize('view', $release);

        return $this->item(Presenter::release($release, $this->workflow->available($this->user(), $release)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Release::class);
        $data = $this->validated($request, null);

        $release = $this->releases->create($this->user(), $data['fields'], $data['artists']);

        return $this->item($this->present($release), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $release = $this->find($id);
        $this->authorize('update', $release);
        $data = $this->validated($request, $release);

        // Artists are optional on update: keep the current credits when not sent.
        $artists = $data['artists'] ?? $release->artists()->withoutGlobalScope('artist-account')->get()
            ->map(fn ($a) => ['artist_id' => $a->id, 'role' => $a->pivot->role])->all();

        $release = $this->releases->update($this->user(), $release, $data['fields'], $artists);

        return $this->item($this->present($release));
    }

    public function destroy(string $id): JsonResponse
    {
        $release = $this->find($id);
        $this->authorize('delete', $release);

        $this->releases->delete($this->user(), $release);

        return $this->noContent();
    }

    public function restore(string $id): JsonResponse
    {
        $release = $this->find($id);
        $this->authorize('restore', $release);

        $this->releases->restore($this->user(), $release);

        return $this->item($this->present($release));
    }

    /** POST /releases/{id}/workflow {"action": "submit", "comment": "…"} — submit, withdraw, approve … */
    public function transition(Request $request, string $id): JsonResponse
    {
        $release = $this->find($id);
        $this->authorize('view', $release);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(array_keys(ReleaseWorkflow::labels()))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $available = $this->workflow->available($this->user(), $release);
        if (! in_array($data['action'], $available, true)) {
            throw new ApiException(409, 'transition_not_allowed', __('":action" is not possible for this release right now, or your account may not do it.', [
                'action' => $data['action'],
            ]), ['status' => $release->status->value, 'available_actions' => $available]);
        }

        $release = $this->workflow->transition($this->user(), $release, $data['action'], $data['comment'] ?? null);

        return $this->item($this->present($release));
    }

    protected function find(string $id): Release
    {
        return Release::withTrashed()->with(['artists', 'genre', 'language'])->withCount('tracks')->findOrFail($this->id($id));
    }

    /** @return array<string, mixed> */
    protected function present(Release $release): array
    {
        $fresh = $this->find((string) $release->id);

        return Presenter::release($fresh, $this->workflow->available($this->user(), $fresh));
    }

    /**
     * The Catalog → Releases form rules (without the artwork upload).
     *
     * @return array{fields: array<string, mixed>, artists: list<array{artist_id: int, role: string}>|null}
     */
    protected function validated(Request $request, ?Release $release): array
    {
        $partial = $release !== null;
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'title' => [...$required, 'string', 'max:200'],
            'version' => ['nullable', 'string', 'max:100'],
            'type' => [...$required, Rule::enum(ReleaseType::class)],
            'release_date' => ['nullable', 'date_format:Y-m-d'],
            'original_release_date' => ['nullable', 'date_format:Y-m-d'],
            'genre_id' => ['nullable', 'integer', Rule::exists('genres', 'id')],
            'language_id' => ['nullable', 'integer', Rule::exists('languages', 'id')],
            'upc' => ['nullable', 'string', 'max:20', function (string $a, mixed $v, Closure $fail) {
                $n = Identifiers::normalizeUpc((string) $v);
                if ($n !== null && ! Identifiers::isValidUpc($n)) {
                    $fail(__('Enter a valid 12- or 13-digit UPC/EAN (the check digit does not match).'));
                }
            }],
            'catalog_number' => ['nullable', 'string', 'max:50', Rule::unique('releases', 'catalog_number')->ignore($release?->id)],
            'label_name' => ['nullable', 'string', 'max:150'],
            'c_line' => ['nullable', 'string', 'max:200'],
            'p_line' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...self::artistRules($partial),
        ], self::artistMessages());

        // The original release date may not be after the release date (also when only one of them changes).
        $releaseDate = array_key_exists('release_date', $data) ? $data['release_date'] : $release?->release_date?->toDateString();
        $originalDate = array_key_exists('original_release_date', $data) ? $data['original_release_date'] : $release?->original_release_date?->toDateString();
        if ($releaseDate && $originalDate && $originalDate > $releaseDate) {
            throw ValidationException::withMessages(['original_release_date' => __('The original release date cannot be after the release date.')]);
        }

        if (array_key_exists('upc', $data)) {
            $data['upc'] = Identifiers::normalizeUpc($data['upc']);
            $duplicate = $data['upc'] !== null && Release::withTrashed()->withoutGlobalScope('artist-account')->where('upc', $data['upc'])
                ->when($release, fn ($q) => $q->whereKeyNot($release->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['upc' => __('This UPC is already used by another release.')]);
            }
        }

        $artists = isset($data['artists']) ? self::artistRows($data['artists']) : null;
        unset($data['artists']);

        return ['fields' => $data, 'artists' => $artists];
    }

    /** @return array<string, mixed> credited artists: [{"artist_id": 1, "role": "primary"}, …] */
    public static function artistRules(bool $partial): array
    {
        return [
            'artists' => [$partial ? 'sometimes' : 'required', 'array', 'min:1', 'max:20'],
            'artists.*' => ['array:artist_id,role'],
            'artists.*.artist_id' => ['required', 'integer', 'distinct', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            'artists.*.role' => ['required', Rule::enum(ArtistRole::class)],
        ];
    }

    /** @return array<string, string> */
    public static function artistMessages(): array
    {
        return [
            'artists.required' => __('Add at least one artist.'),
            'artists.*.artist_id.distinct' => __('This artist is listed twice.'),
            'artists.*.artist_id.exists' => __('Choose an existing artist.'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{artist_id: int, role: string}>
     */
    public static function artistRows(array $rows): array
    {
        return array_values(array_map(fn ($r) => ['artist_id' => (int) $r['artist_id'], 'role' => (string) $r['role']], $rows));
    }
}
