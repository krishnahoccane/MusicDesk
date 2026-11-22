/**
 * Collapsible sidebar (desktop) and off-canvas sidebar (mobile).
 * The collapsed state is a per-browser convenience stored in localStorage.
 */
const KEY = 'labeldesk.sidebar';
const MOBILE = window.matchMedia('(max-width: 991.98px)');

export function initSidebar() {
    const root = document.documentElement;

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-sidebar-toggle]')) {
            event.preventDefault();

            if (MOBILE.matches) {
                const open = root.dataset.sidebarMobile === 'open';
                root.dataset.sidebarMobile = open ? 'closed' : 'open';
                return;
            }

            const collapsed = root.dataset.sidebar === 'collapsed';
            root.dataset.sidebar = collapsed ? 'expanded' : 'collapsed';
            try {
                localStorage.setItem(KEY, root.dataset.sidebar);
            } catch (e) {
                // ignore storage failures
            }
            return;
        }

        if (event.target.closest('[data-sidebar-close]')) {
            root.dataset.sidebarMobile = 'closed';
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && root.dataset.sidebarMobile === 'open') {
            root.dataset.sidebarMobile = 'closed';
        }
    });
}
