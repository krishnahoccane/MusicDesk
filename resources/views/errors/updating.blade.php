<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="5">
    <meta name="robots" content="noindex">
    <title>{{ __('Updating') }} · {{ config('app.name') }}</title>
    {{-- Self-contained: shown while files and caches are being replaced, so no built assets. --}}
    <style>
        :root { color-scheme: light dark; --bg: #f5f6f8; --card: #fff; --text: #1b1d22; --muted: #616774; --accent: #6d4aff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f1115; --card: #171a21; --text: #eceef3; --muted: #9aa1ae; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text);
               font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--card); border-radius: 14px; padding: 32px; text-align: center;
               box-shadow: 0 10px 30px rgba(0, 0, 0, .08); }
        h1 { font-size: 20px; margin: 18px 0 8px; }
        p { margin: 0; color: var(--muted); }
        .spinner { width: 36px; height: 36px; margin: 0 auto; border-radius: 50%; border: 3px solid color-mix(in srgb, var(--accent) 25%, transparent);
                   border-top-color: var(--accent); animation: spin .9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 3s; } }
    </style>
</head>
<body>
<main role="status" aria-live="polite">
    <div class="spinner" aria-hidden="true"></div>
    <h1>{{ __(':name is being updated', ['name' => config('app.name')]) }}</h1>
    <p>{{ __('This usually takes less than a minute. The page reloads on its own.') }}</p>
</main>
</body>
</html>
