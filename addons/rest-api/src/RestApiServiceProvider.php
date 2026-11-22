<?php

namespace LabelDesk\Addons\RestApi;

use App\Plugins\AddonServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use LabelDesk\Addons\RestApi\Livewire\Reference;
use LabelDesk\Addons\RestApi\Livewire\Tokens;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Support\ErrorRenderer;
use Livewire\Livewire;
use Throwable;

/**
 * REST API add-on: /api/v1 for artists, releases, tracks and contributors,
 * authenticated with personal access tokens that users create under
 * Administration → API tokens.
 */
class RestApiServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->path('config/rest-api.php'), 'rest-api');
    }

    public function boot(): void
    {
        $this->views();
        $this->migrations();

        $this->permissions('rest_api', 'REST API', [
            'api.tokens' => 'Create and use personal API tokens',
            'api.tokens.manage' => "View and revoke every user's API tokens",
        ]);

        Livewire::component('rest-api.tokens', Tokens::class);
        Livewire::component('rest-api.reference', Reference::class);

        $this->webRoutes('routes/web.php');
        $this->apiRoutes('routes/api.php');

        $this->navigation('Administration', [
            'label' => 'API tokens', 'icon' => 'braces', 'route' => 'rest-api.tokens',
            'active' => 'rest-api.*', 'permission' => 'api.tokens',
        ], after: 'activity.index');

        $this->errors();
    }

    /** Tokens stop working for good when the add-on is removed (rows are kept for the audit trail). */
    public function uninstall(): void
    {
        if (Schema::hasTable('api_tokens')) {
            ApiToken::query()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }

    /** Every error under /api/v1 uses one JSON format (see ErrorRenderer). */
    protected function errors(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (Throwable $e, Request $request) {
                return ErrorRenderer::applies($request) ? ErrorRenderer::render($e) : null;
            });
        }
    }
}
