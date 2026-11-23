@php($ldNote = trim((string) ($ldTexts['note'] ?? '')))
<div @class(['ld-login__note', 'text-center' => $center ?? false])>
    @if ($ldNote !== '')
        <p class="mb-2">{{ $ldNote }}</p>
    @endif
    <span>&copy; {{ now()->year }} {{ config('app.name') }}</span>
</div>
