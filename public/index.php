<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Incomplete upload (e.g. the source code instead of the release ZIP): explain
// instead of showing a PHP fatal error. No server paths are printed.
$missing = array_values(array_filter([
    is_file(__DIR__.'/../vendor/autoload.php') ? null : 'vendor/',
    is_file(__DIR__.'/build/manifest.json') ? null : 'public/build/',
]));
if ($missing !== []) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $list = implode('', array_map(fn ($m) => '<li><code>'.$m.'</code></li>', $missing));
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>LabelDesk · Upload incomplete</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:#07070b;color:#d2d2e0;font:15px/1.6 system-ui,-apple-system,'Segoe UI',sans-serif}
main{max-width:560px;background:#0b0b12;border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:32px}
h1{margin:0 0 8px;font-size:21px;color:#fff;letter-spacing:-.02em}.e{font:11px ui-monospace,monospace;letter-spacing:.2em;text-transform:uppercase;color:#9b84ff}
code{background:rgba(255,255,255,.06);padding:1px 6px;border-radius:5px;color:#ececf4}ol li,ul li{margin:4px 0}a{color:#b9a8ff}</style></head>
<body><main><div class="e">LabelDesk setup</div><h1>The upload is incomplete</h1>
<p>These folders are missing on the server:</p><ul>{$list}</ul>
<p>They are included in the <strong>release ZIP</strong> you download from your MusicDesk.tech account (not in the source code). To fix:</p>
<ol><li>Upload <code>labeldesk-&lt;version&gt;.zip</code> to your hosting account and extract it.</li>
<li>Make sure the domain's document root points to the <code>public</code> folder.</li>
<li>Reload this page; the installation wizard starts.</li></ol>
<p>Developers with SSH can instead run <code>composer install --no-dev</code> and <code>npm ci &amp;&amp; npm run build</code>.</p></main></body></html>
HTML;
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
