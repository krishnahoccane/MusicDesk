@php
    // Show LabelDesk's own explanations (e.g. "not included in your license plan"); hide framework defaults.
    $reason = isset($exception) ? (string) $exception->getMessage() : '';
    $generic = ['', 'This action is unauthorized.', 'Forbidden', 'User does not have the right permissions.', 'User does not have the right roles.'];
@endphp
@include('errors.layout', [
    'code' => 403,
    'title' => __('You do not have access'),
    'message' => in_array($reason, $generic, true) ? __('Your role does not include this page. Ask an administrator if you need access.') : $reason,
])
