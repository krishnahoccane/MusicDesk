/**
 * LabelDesk front-end runtime.
 *
 * Deliberately framework-free: Livewire handles server interactivity and this
 * file only provides theme switching, the sidebar, toasts and chart helpers.
 */
import '@fontsource-variable/inter';
import * as bootstrap from 'bootstrap';
import { initTheme } from './modules/theme';
import { initSidebar } from './modules/sidebar';
import { initToasts, toast } from './modules/toasts';
import { renderCharts } from './modules/charts';

window.bootstrap = bootstrap;
window.LabelDesk = { toast };

function initTooltips(root = document) {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initSidebar();
    initToasts();
    initTooltips();
    renderCharts();
});

// Livewire integration: re-initialise UI helpers after DOM updates and
// listen for server-dispatched toast events.
document.addEventListener('livewire:init', () => {
    window.Livewire.on('toast', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        toast(data?.message ?? '', data?.type ?? 'success');
    });

    window.Livewire.hook('morph.updated', ({ el }) => {
        initTooltips(el);
    });
});

document.addEventListener('livewire:navigated', () => {
    initTooltips();
    renderCharts();
});
