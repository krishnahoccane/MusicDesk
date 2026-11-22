/**
 * Theme handling. The resolved theme is applied before first paint by an inline
 * script in the document head (see partials/theme-script); this module wires
 * the theme menu and persists the preference per user on the server.
 */
const VALID = ['light', 'dark', 'system'];
const media = window.matchMedia('(prefers-color-scheme: dark)');

export function resolveTheme(preference) {
    if (preference === 'system') {
        return media.matches ? 'dark' : 'light';
    }
    return preference === 'dark' ? 'dark' : 'light';
}

function applyTheme(preference) {
    const root = document.documentElement;
    root.dataset.themePreference = preference;
    root.dataset.bsTheme = resolveTheme(preference);

    document.querySelectorAll('[data-theme-option]').forEach((el) => {
        const active = el.dataset.themeOption === preference;
        el.classList.toggle('active', active);
        el.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    document.dispatchEvent(new CustomEvent('labeldesk:theme-changed', {
        detail: { theme: root.dataset.bsTheme, preference },
    }));
}

async function persist(preference) {
    try {
        localStorage.setItem('labeldesk.theme', preference);
    } catch (e) {
        // Storage may be unavailable (private mode); the server copy still applies.
    }

    const endpoint = document.documentElement.dataset.themeEndpoint;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!endpoint || !token) {
        return;
    }

    try {
        await fetch(endpoint, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ theme: preference }),
            credentials: 'same-origin',
        });
    } catch (e) {
        // A failed save is not fatal; the preference still applies locally.
    }
}

export function initTheme() {
    document.addEventListener('click', (event) => {
        const option = event.target.closest('[data-theme-option]');
        if (!option) return;

        const preference = option.dataset.themeOption;
        if (!VALID.includes(preference)) return;

        event.preventDefault();
        applyTheme(preference);
        persist(preference);
    });

    media.addEventListener('change', () => {
        if (document.documentElement.dataset.themePreference === 'system') {
            applyTheme('system');
        }
    });

    applyTheme(document.documentElement.dataset.themePreference || 'system');
    requestAnimationFrame(() => document.documentElement.classList.remove('ld-no-transition'));
}
