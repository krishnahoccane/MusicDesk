<?php

namespace LabelDesk\Addons\Hello;

use App\Plugins\AddonServiceProvider;
use Illuminate\Support\Facades\DB;

class HelloServiceProvider extends AddonServiceProvider
{
    public function boot(): void
    {
        $this->views();
        $this->migrations();
        $this->webRoutes('routes/web.php');
        $this->apiRoutes('routes/api.php');
        $this->permissions('hello', 'Hello', ['hello.view' => 'View hello']);
        $this->navigation('Catalog', ['label' => 'Hello', 'icon' => 'puzzle', 'route' => 'hello.index', 'permission' => 'hello.view'], after: 'tracks.index');
    }

    public function activate(): void
    {
        DB::table('hello_notes')->insertOrIgnore(['id' => 1, 'note' => 'activated']);
    }
}
