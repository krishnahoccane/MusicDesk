{{-- Converts session flash messages into client-side toasts (rendered as text, never HTML). --}}
@php
    $toasts = [];
    if (is_array(session('toast'))) {
        $toasts[] = session('toast');
    }
    foreach (['success', 'info', 'warning'] as $key) {
        if (is_string(session($key))) {
            $toasts[] = ['type' => $key, 'message' => session($key)];
        }
    }
    if (is_string(session('error'))) {
        $toasts[] = ['type' => 'danger', 'message' => session('error')];
    }
@endphp
@foreach ($toasts as $toast)
    <template data-toast data-toast-type="{{ $toast['type'] ?? 'info' }}" data-toast-message="{{ $toast['message'] ?? '' }}"></template>
@endforeach
