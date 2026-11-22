<?php

namespace LabelDesk\Addons\RestApi\Http\Controllers;

use App\Models\Contributor;
use App\Services\Catalog\ContributorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LabelDesk\Addons\RestApi\Support\Presenter;

/**
 * /api/v1/contributors — same rules as Catalog → Contributors
 * (contributors.view / contributors.manage, ContributorService).
 */
class ContributorController extends Controller
{
    public function __construct(protected ContributorService $contributors) {}

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission('contributors.view');

        $params = $this->listParams($request, [
            'search' => ['sometimes', 'string', 'max:100'],
            'ipi_number' => ['sometimes', 'string', 'max:13'],
        ], ['name', 'created_at', 'updated_at']);

        $query = Contributor::query()->withCount('credits')
            ->when(isset($params['search']), fn ($q) => $q->search((string) $params['search']))
            ->when(isset($params['ipi_number']), fn ($q) => $q->where('ipi_number', preg_replace('/\D/', '', (string) $params['ipi_number'])));

        return $this->paginated($this->applyCommon($query, $params, 'name'), $params, fn (Contributor $c) => Presenter::contributor($c));
    }

    public function show(string $id): JsonResponse
    {
        $this->requirePermission('contributors.view');

        return $this->item(Presenter::contributor($this->find($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission('contributors.manage');
        $data = $request->validate($this->rules(false), $this->messages());

        $contributor = $this->contributors->save($this->user(), null, $data);

        return $this->item(Presenter::contributor($this->find((string) $contributor->id)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->requirePermission('contributors.manage');
        $contributor = $this->find($id);
        $data = $request->validate($this->rules(true), $this->messages());

        // ContributorService::save() replaces every field: keep the ones not sent.
        $data = array_merge($contributor->only(['name', 'legal_name', 'ipi_number', 'pro', 'email', 'notes']), $data);
        $this->contributors->save($this->user(), $contributor, $data);

        return $this->item(Presenter::contributor($this->find($id)));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->requirePermission('contributors.manage');

        $this->contributors->delete($this->user(), $this->find($id));

        return $this->noContent();
    }

    protected function find(string $id): Contributor
    {
        return Contributor::query()->withCount('credits')->findOrFail($this->id($id));
    }

    /** @return array<string, mixed> the Catalog → Contributors form rules */
    protected function rules(bool $partial): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'ipi_number' => ['nullable', 'regex:/^\s*\d{9,11}\s*$/'],
            'pro' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return ['ipi_number.regex' => __('An IPI name number has 9 to 11 digits.')];
    }
}
