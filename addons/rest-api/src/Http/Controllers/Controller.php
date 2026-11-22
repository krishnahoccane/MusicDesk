<?php

namespace LabelDesk\Addons\RestApi\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LabelDesk\Addons\RestApi\Support\ApiException;
use Throwable;

/**
 * Shared helpers: the signed-in token owner, policy checks, list parameters
 * (page, per_page, sort, updated_since) and response envelopes.
 */
abstract class Controller
{
    protected function user(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }

    /** Same policy / permission checks as the app (Super Admin passes through Gate::before). */
    protected function authorize(string $ability, mixed $arguments = []): void
    {
        if (! Gate::forUser($this->user())->allows($ability, $arguments)) {
            throw ApiException::forbidden('forbidden', __('Your account is not allowed to do this.'));
        }
    }

    protected function requirePermission(string $permission): void
    {
        if (! $this->user()->can($permission)) {
            throw ApiException::forbidden('forbidden', __('Your account is not allowed to do this.'), ['permission' => $permission]);
        }
    }

    /**
     * Validates list parameters shared by all collections.
     *
     * @param  array<string, mixed>  $rules  extra filter rules
     * @param  list<string>  $sorts  allowed sort fields (prefix "-" for descending)
     * @return array<string, mixed>
     */
    protected function listParams(Request $request, array $rules, array $sorts): array
    {
        $max = (int) config('rest-api.max_per_page', 100);
        $allowed = array_merge($sorts, array_map(fn ($s) => '-'.$s, $sorts));

        return $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$max],
            'sort' => ['sometimes', 'string', Rule::in($allowed)],
            'updated_since' => ['sometimes', 'date'],
            ...$rules,
        ]);
    }

    /** @param  array<string, mixed>  $params */
    protected function applyCommon(Builder $query, array $params, string $defaultSort): Builder
    {
        if (isset($params['updated_since'])) {
            try {
                $since = CarbonImmutable::parse((string) $params['updated_since'])->utc();
            } catch (Throwable) {
                throw ValidationException::withMessages(['updated_since' => __('Use an ISO 8601 date or date-time.')]);
            }
            $query->where($query->getModel()->qualifyColumn('updated_at'), '>=', $since);
        }

        $sort = (string) ($params['sort'] ?? $defaultSort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($query->getModel()->qualifyColumn($column), $direction)
            ->orderBy($query->getModel()->qualifyColumn('id'), $direction);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  Closure(mixed): array<string, mixed>  $present
     */
    protected function paginated(Builder $query, array $params, Closure $present): JsonResponse
    {
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate(
            perPage: (int) ($params['per_page'] ?? config('rest-api.per_page', 25)),
            page: (int) ($params['page'] ?? 1),
        );

        $page->appends(collect($params)->except('page')->all());

        return new JsonResponse([
            'data' => collect($page->items())->map($present)->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'links' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function item(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    protected function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /** Route ids are digits only (enforced on the routes); anything else is a 404. */
    protected function id(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw ApiException::notFound();
        }

        return (int) $value;
    }
}
