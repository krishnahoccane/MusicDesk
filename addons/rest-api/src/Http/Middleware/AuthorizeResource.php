<?php

namespace LabelDesk\Addons\RestApi\Http\Middleware;

use App\Services\License\LicenseService;
use Closure;
use Illuminate\Http\Request;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Support\Abilities;
use LabelDesk\Addons\RestApi\Support\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "AuthorizeResource:artists":
 *  - the token needs "artists:read" (GET/HEAD) or "artists:write" (anything else);
 *  - the license plan must include the resource's feature;
 *  - writes are refused while the license is read-only, like in the app.
 * The owner's permissions and policies are checked again in the controller.
 */
class AuthorizeResource
{
    public function __construct(protected LicenseService $license) {}

    public function handle(Request $request, Closure $next, string $resource, ?string $extraFeature = null): Response
    {
        $definition = Abilities::RESOURCES[$resource] ?? throw new \InvalidArgumentException("Unknown API resource [{$resource}].");
        $write = ! $request->isMethodSafe();
        $ability = $resource.($write ? ':write' : ':read');

        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');
        if (! $token instanceof ApiToken || ! $token->allows($ability)) {
            throw ApiException::forbidden('insufficient_scope', __('This token does not have the ":ability" ability.', ['ability' => $ability]), ['required_ability' => $ability]);
        }

        foreach (array_filter([$definition['feature'], $extraFeature]) as $feature) {
            if (! $this->license->canUse($feature)) {
                throw ApiException::forbidden('feature_unavailable', __('This feature is not included in your license plan.'), ['feature' => $feature]);
            }
        }

        if ($write && ! $this->license->allowsWrites()) {
            throw ApiException::forbidden('license_read_only', __('Changes are disabled until the license is verified. Reading still works.'));
        }

        return $next($request);
    }
}
