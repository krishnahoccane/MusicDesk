<?php

namespace App\Http\Middleware;

use App\Plugins\PluginManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Add-on routes answer 404 unless the add-on is running (active and on the license). */
class EnsurePluginRunning
{
    public function __construct(protected PluginManager $plugins) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        abort_unless($this->plugins->isRunning($slug), 404);

        return $next($request);
    }
}
