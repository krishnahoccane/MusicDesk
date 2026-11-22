<?php

namespace LabelDesk\Addons\RestApi\Http\Controllers;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Models\Artist;
use App\Services\ArtistService;
use App\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\RestApi\Support\Presenter;

/**
 * /api/v1/artists — same rules as Catalog → Artists (ArtistPolicy, ArtistService,
 * artist-account scope through the model's global scope).
 */
class ArtistController extends Controller
{
    public function __construct(protected ArtistService $artists) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Artist::class);

        $params = $this->listParams($request, [
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(ArtistStatus::class)],
            'type' => ['sometimes', Rule::enum(ArtistType::class)],
            'country' => ['sometimes', 'string', 'size:2'],
            'trashed' => ['sometimes', Rule::in(['with', 'only'])],
        ], ['name', 'created_at', 'updated_at']);

        $query = match ($params['trashed'] ?? null) {
            'with' => Artist::withTrashed(),
            'only' => Artist::onlyTrashed(),
            default => Artist::query(),
        };

        $query
            ->when(isset($params['search']), fn ($q) => $q->search((string) $params['search']))
            ->when(isset($params['status']), fn ($q) => $q->where('status', $params['status']))
            ->when(isset($params['type']), fn ($q) => $q->where('type', $params['type']))
            ->when(isset($params['country']), fn ($q) => $q->where('country', strtoupper((string) $params['country'])));

        return $this->paginated($this->applyCommon($query, $params, 'name'), $params, fn (Artist $a) => Presenter::artist($a));
    }

    public function show(string $id): JsonResponse
    {
        $artist = Artist::withTrashed()->findOrFail($this->id($id));
        $this->authorize('view', $artist);

        return $this->item(Presenter::artist($artist));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Artist::class);
        $data = $request->validate($this->rules(false), $this->messages());

        $artist = $this->artists->create($this->user(), $data);

        return $this->item(Presenter::artist($artist->fresh()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $artist = Artist::withTrashed()->findOrFail($this->id($id));
        $this->authorize('update', $artist);
        $data = $request->validate($this->rules(true), $this->messages());

        $artist = $this->artists->update($this->user(), $artist, $data);

        return $this->item(Presenter::artist($artist->fresh()));
    }

    public function destroy(string $id): JsonResponse
    {
        $artist = Artist::withTrashed()->findOrFail($this->id($id));
        $this->authorize('delete', $artist);

        $this->artists->delete($this->user(), $artist);

        return $this->noContent();
    }

    public function restore(string $id): JsonResponse
    {
        $artist = Artist::withTrashed()->findOrFail($this->id($id));
        $this->authorize('restore', $artist);

        $this->artists->restore($this->user(), $artist);

        return $this->item(Presenter::artist($artist->fresh()));
    }

    /** @return array<string, mixed> the Catalog → Artists form rules (without the photo upload) */
    protected function rules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'type' => [...$required, Rule::enum(ArtistType::class)],
            'status' => [...$required, Rule::enum(ArtistStatus::class)],
            'country' => ['nullable', Rule::in(Countries::codes())],
            'primary_genre' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s\/]*$/'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return ['phone.regex' => __('Use digits, spaces and + ( ) - . / only.')];
    }
}
