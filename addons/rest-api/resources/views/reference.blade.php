<div>
    <x-page-header :title="__('API reference')" :subtitle="__('Version 1 of the LabelDesk REST API. JSON in, JSON out.')">
        <x-slot:actions>
            <a href="{{ route('rest-api.tokens') }}" class="btn btn-light"><x-icon name="key-round" size="16" /> {{ __('API tokens') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <x-icon name="key-round" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Authentication') }}</h2>
                </div>
                <div class="card-body">
                    <p>{{ __('Base URL') }}: <code>{{ $baseUrl }}</code></p>
                    <p>{{ __('Send a personal access token with every request. Tokens act as the user who created them: the same permissions, artist-account limits and license rules apply as in the app.') }}</p>
<pre class="bg-body-tertiary rounded p-3 small mb-3"><code>curl {{ $baseUrl }}/releases?status=draft \
  -H "Authorization: Bearer ldk_…" \
  -H "Accept: application/json"</code></pre>
                    <p class="mb-2">{{ __('Abilities a token can have (write includes read):') }}</p>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach ($abilities as $ability)
                            <code class="me-2">{{ $ability }}</code>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <x-icon name="braces" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Responses') }}</h2>
                </div>
                <div class="card-body">
                    <p class="mb-2">{{ __('Single items are wrapped in "data". Lists add "meta" and "links":') }}</p>
<pre class="bg-body-tertiary rounded p-3 small mb-3"><code>{ "data": [ … ],
  "meta": { "current_page": 1, "per_page": {{ $perPage }}, "total": 42, "last_page": 2 },
  "links": { "next": "…?page=2", "prev": null } }</code></pre>
                    <p class="mb-2">{{ __('Use page and per_page (at most :max). Sort with sort=name or sort=-updated_at.', ['max' => $maxPerPage]) }}</p>
                    <p class="mb-2">{{ __('Errors always look like this:') }}</p>
<pre class="bg-body-tertiary rounded p-3 small mb-0"><code>{ "error": { "code": "validation_failed",
             "message": "The given data was invalid.",
             "details": { "fields": { "title": ["…"] } } } }</code></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <x-icon name="triangle-alert" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Status codes and limits') }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <tr><td class="text-nowrap"><code>401 unauthenticated</code></td><td>{{ __('Missing, invalid, expired or revoked token, or the owner was deactivated.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>403 insufficient_scope</code></td><td>{{ __('The token lacks the ability for this endpoint.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>403 forbidden</code></td><td>{{ __('The owner’s role does not allow this action.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>403 license_read_only</code></td><td>{{ __('The license is not verified: reading works, changes are refused.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>403 feature_unavailable</code></td><td>{{ __('The license plan does not include this part of the catalog.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>404 not_found</code></td><td>{{ __('Unknown id, or a record outside an artist account’s own artists.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>409 transition_not_allowed</code></td><td>{{ __('The workflow step is not possible from the release’s current status.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>422 validation_failed</code></td><td>{{ __('Invalid fields; see details.fields.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>422 rule_violation</code></td><td>{{ __('A catalog rule was broken, e.g. the release is locked or an ISRC is already used.') }}</td></tr>
                    <tr><td class="text-nowrap"><code>429 rate_limited</code></td><td>{{ __(':count requests per minute per token. Wait for the Retry-After header.', ['count' => $rateLimit]) }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($endpoints as $group => $rows)
        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title">{{ $group }}</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 6rem">{{ __('Method') }}</th>
                            <th>{{ __('Path') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Ability') }}</th>
                            <th>{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as [$method, $path, $ability, $description])
                            <tr>
                                <td><x-badge :variant="$method === 'GET' ? 'info' : ($method === 'DELETE' ? 'danger' : 'accent')">{{ $method }}</x-badge></td>
                                <td class="text-nowrap"><code>{{ $path }}</code></td>
                                <td class="d-none d-md-table-cell text-nowrap"><code>{{ $ability }}</code></td>
                                <td class="small">{{ $description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
