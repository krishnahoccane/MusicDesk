@php
    // Show LabelDesk's own explanations (abort(404, '…')); hide framework texts such as
    // "The route … could not be found." or "No query results for model …".
    $reason = isset($exception) ? (string) $exception->getMessage() : '';
    $framework = $reason === '' || $reason === 'Not Found' || str_starts_with($reason, 'The route ') || str_starts_with($reason, 'No query results');
@endphp
@include('errors.layout', [
    'code' => 404,
    'title' => __('Page not found'),
    'message' => $framework ? __('This page does not exist, or it is not part of your catalogue.') : $reason,
])
