{{-- Applies the theme before first paint to avoid a flash of the wrong theme. --}}
<script>
    (function () {
        var root = document.documentElement;
        var pref = root.dataset.themePreference || 'system';
        try {
            if (!root.dataset.themeEndpoint && !root.dataset.themeLocked) {
                var stored = localStorage.getItem('labeldesk.theme');
                if (stored === 'light' || stored === 'dark' || stored === 'system') pref = stored;
            }
            var sidebar = localStorage.getItem('labeldesk.sidebar');
            if (sidebar === 'collapsed' || sidebar === 'expanded') root.dataset.sidebar = sidebar;
        } catch (e) {}
        var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.dataset.themePreference = pref;
        root.dataset.bsTheme = dark ? 'dark' : 'light';
    })();
</script>
