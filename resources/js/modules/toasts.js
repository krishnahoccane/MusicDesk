/**
 * Lightweight toast notifications. Server flash messages are rendered into
 * [data-toast] elements by the layout; Livewire components dispatch 'toast'.
 */
const ICONS = {
    success: '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    danger: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    warning: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
};

function stack() {
    let el = document.querySelector('.ld-toast-stack');
    if (!el) {
        el = document.createElement('div');
        el.className = 'ld-toast-stack';
        el.setAttribute('aria-live', 'polite');
        document.body.appendChild(el);
    }
    return el;
}

export function toast(message, type = 'success', timeout = 4500) {
    if (!message) return;
    const kind = ICONS[type] ? type : 'info';

    const item = document.createElement('div');
    item.className = `ld-toast ld-toast--${kind}`;
    item.setAttribute('role', kind === 'danger' ? 'alert' : 'status');

    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('class', 'ld-icon');
    icon.setAttribute('width', '18');
    icon.setAttribute('height', '18');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '2');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.innerHTML = ICONS[kind];

    const text = document.createElement('div');
    text.className = 'flex-grow-1';
    text.textContent = message; // textContent: never interpret messages as HTML

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-sm ms-2';
    close.setAttribute('aria-label', 'Dismiss');

    item.append(icon, text, close);
    stack().appendChild(item);

    const dismiss = () => {
        item.classList.add('is-leaving');
        setTimeout(() => item.remove(), 200);
    };
    close.addEventListener('click', dismiss);
    if (timeout > 0) setTimeout(dismiss, timeout);
}

export function initToasts() {
    document.querySelectorAll('[data-toast]').forEach((el) => {
        toast(el.dataset.toastMessage, el.dataset.toastType);
        el.remove();
    });
}
