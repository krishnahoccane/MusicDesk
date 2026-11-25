<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="{{ $robots ?? 'noindex, nofollow' }}">
@isset($description)<meta name="description" content="{{ $description }}">@endisset
<meta name="color-scheme" content="light dark">
<title>{{ $title ? $title.' · ' : '' }}{{ config('app.name', 'LabelDesk') }}</title>
@php($ldFavicon = App\Support\Branding::url('favicon'))
<link rel="icon" href="{{ $ldFavicon ?? asset('favicon.svg') }}">
@include('partials.theme-script')
@vite(['resources/scss/app.scss', 'resources/js/app.js'])
@if (! empty($appearance['css']))
    {{-- Custom brand colours (validated #rrggbb values only). --}}
    <style>{!! $appearance['css'] !!}</style>
@endif
