<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }} · {{ config('app.name', 'LabelDesk') }}</title>
    {{-- Self-contained on purpose: error pages must render even when assets or the database are unavailable. --}}
    <style>
        :root { --bg: #f5f6fa; --card: #fff; --text: #475467; --heading: #101828; --muted: #98a2b3; --accent: #6d4aff; --accent-2: #1fc8a8; --border: #e4e7ec; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0d1016; --card: #151922; --text: #a9b1c1; --heading: #f2f4f7; --muted: #687083; --accent: #8f74ff; --accent-2: #39d7b8; --border: #262c38; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text);
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 460px; background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 40px 32px; text-align: center;
            box-shadow: 0 18px 48px -18px rgba(16, 24, 40, .25); }
        .wave { display: flex; gap: 4px; justify-content: center; align-items: flex-end; height: 34px; margin-bottom: 20px; }
        .wave span { width: 5px; border-radius: 3px; background: linear-gradient(var(--accent), var(--accent-2)); opacity: .85; }
        .code { font-size: 13px; font-weight: 700; letter-spacing: .12em; color: var(--accent); }
        h1 { margin: 6px 0 8px; font-size: 22px; color: var(--heading); }
        p { margin: 0 0 24px; }
        .actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        a { display: inline-block; padding: 9px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; }
        .primary { background: var(--accent); color: #fff; }
        .light { border: 1px solid var(--border); color: var(--heading); }
        small { display: block; margin-top: 28px; color: var(--muted); }
    </style>
</head>
<body>
    <main class="card">
        <div class="wave" aria-hidden="true">
            @foreach ([40, 70, 50, 90, 60, 100, 45, 75, 55] as $h)<span style="height: {{ $h }}%"></span>@endforeach
        </div>
        <div class="code">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            <a class="primary" href="{{ url('/') }}">{{ \App\Support\Text::t('Go to dashboard') }}</a>
            <a class="light" href="javascript:history.back()">{{ \App\Support\Text::t('Go back') }}</a>
        </div>
        <small>{{ config('app.name', 'LabelDesk') }}</small>
    </main>
</body>
</html>
