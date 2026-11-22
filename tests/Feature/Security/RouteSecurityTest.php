<?php

namespace Tests\Feature\Security;

use App\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Milestone 10 security review: guards that hold for every route, so a new
 * page cannot be added without authentication by accident.
 */
class RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Public by design: installer (locked after installation), auth screens, Livewire assets, health check. */
    protected const PUBLIC_PREFIXES = ['install', 'login', 'forgot-password', 'reset-password', 'livewire/', 'up', 'branding/', '/'];

    protected function isPublic(Route $route): bool
    {
        $uri = $route->uri();
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if ($uri === $prefix || ($prefix !== '/' && str_starts_with($uri, $prefix))) {
                return true;
            }
        }

        return false;
    }

    public function test_every_application_route_requires_authentication(): void
    {
        $unprotected = collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $r) => $this->isPublic($r))
            ->filter(fn (Route $r) => ! in_array('auth', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => implode('|', $r->methods()).' '.$r->uri())
            ->values()->all();

        $this->assertSame([], $unprotected, 'Routes without the auth middleware');
    }

    public function test_guests_are_sent_to_login_from_every_page(): void
    {
        $pages = collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $r) => $this->isPublic($r))
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'));

        $this->assertGreaterThan(20, $pages->count());
        foreach ($pages as $route) {
            $this->get('/'.ltrim($route->uri(), '/'))->assertRedirect('/login');
        }
    }

    public function test_viewer_cannot_open_management_pages(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->activateLicense();
        $viewer = $this->makeUser(SystemRole::Viewer);

        foreach (['/users', '/users/create', '/roles/create', '/settings', '/settings/appearance', '/settings/catalog', '/settings/mail',
            '/backups', '/system', '/activity', '/royalties', '/reports/import', '/bulk-upload', '/artists/create', '/releases/create'] as $url) {
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options');
    }

    public function test_private_storage_is_not_served_by_url(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->get('/storage/backups/x.zip')->assertNotFound();
    }
}
