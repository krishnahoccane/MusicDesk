@php($ldNote = trim((string) ($ldTexts['note'] ?? '')))
<div @class(['ld-login__note', 'text-center' => $center ?? false])>
    @if ($ldNote !== '')
        <p class="mb-2">{{ $ldNote }}</p>
    @endif
    <span>{{ config('app.name') }} &middot; {{ __('Powered by :product', ['product' => config('labeldesk.product.name')]) }}</span>
</div>
