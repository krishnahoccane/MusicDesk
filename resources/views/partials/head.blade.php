<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>{{ $title ? $title.' · ' : '' }}{{ config('app.name', 'LabelDesk') }}</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
@include('partials.theme-script')
@vite(['resources/scss/app.scss', 'resources/js/app.js'])
