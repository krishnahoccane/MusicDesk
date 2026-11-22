/**
 * ApexCharts integration. Charts are declared in Blade with a JSON config:
 *   <div data-chart='{"type":"area","series":[...],"categories":[...]}'></div>
 * Supported types: area, line, bar (optionally "horizontal": true), donut.
 * "format": "money" formats values with thousands separators.
 * ApexCharts is loaded lazily so pages without charts stay light.
 */
const instances = new WeakMap();

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

const money = (value) => (typeof value === 'number'
    ? value.toLocaleString(undefined, { maximumFractionDigits: 0 })
    : value);

function themeOptions() {
    const dark = document.documentElement.dataset.bsTheme === 'dark';
    return {
        chart: {
            background: 'transparent',
            foreColor: token('--ld-text-muted'),
            fontFamily: 'inherit',
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { easing: 'easeout', speed: 450 },
        },
        theme: { mode: dark ? 'dark' : 'light' },
        colors: [token('--ld-accent'), token('--ld-accent-2'), token('--ld-warning'), token('--ld-info'), token('--ld-danger'), token('--ld-success')],
        grid: { borderColor: token('--ld-border'), strokeDashArray: 4, padding: { left: 6, right: 6 } },
        tooltip: { theme: dark ? 'dark' : 'light' },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'right', fontWeight: 500, markers: { size: 5 } },
    };
}

function build(config) {
    const base = themeOptions();
    const type = config.type || 'area';
    const formatter = config.format === 'money'
        ? money
        : (config.format === 'integer' ? (v) => (typeof v === 'number' ? Math.round(v).toLocaleString() : v) : undefined);

    if (type === 'donut') {
        return {
            ...base,
            chart: { ...base.chart, type: 'donut', height: config.height || 300 },
            series: config.series || [],
            labels: config.labels || [],
            legend: { ...base.legend, position: 'bottom', horizontalAlign: 'center' },
            stroke: { width: 3, colors: [token('--ld-surface')] },
            plotOptions: {
                pie: {
                    donut: {
                        size: '68%',
                        labels: {
                            show: true,
                            total: { show: true, label: 'Total', color: token('--ld-text-muted'), fontWeight: 600 },
                            value: { color: token('--ld-heading'), fontWeight: 700, fontSize: '22px' },
                        },
                    },
                },
            },
        };
    }

    return {
        ...base,
        chart: { ...base.chart, type, height: config.height || 300 },
        series: config.series || [],
        xaxis: {
            categories: config.categories || [],
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: config.horizontal && formatter ? { formatter } : {},
            ...(config.xaxis || {}),
        },
        yaxis: { labels: formatter && ! config.horizontal ? { formatter } : {}, ...(config.yaxis || {}) },
        tooltip: { ...base.tooltip, y: formatter ? { formatter } : {} },
        stroke: { curve: 'smooth', width: type === 'bar' ? 0 : 2.5 },
        fill: type === 'area'
            ? { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.32, opacityTo: 0.02, stops: [0, 95] } }
            : { opacity: 1 },
        plotOptions: { bar: { borderRadius: 6, columnWidth: '45%', horizontal: !! config.horizontal, barHeight: '60%', distributed: !! config.horizontal } },
        legend: config.horizontal ? { show: false } : base.legend,
        markers: { size: 0, hover: { size: 5 } },
    };
}

export async function renderCharts(root = document) {
    const nodes = root.querySelectorAll('[data-chart]');
    if (!nodes.length) return;

    const { default: ApexCharts } = await import('apexcharts');

    nodes.forEach((el) => {
        let config;
        try {
            config = JSON.parse(el.dataset.chart);
        } catch (e) {
            return;
        }

        instances.get(el)?.destroy();

        const chart = new ApexCharts(el, build(config));
        chart.render();
        instances.set(el, chart);
    });
}

document.addEventListener('labeldesk:theme-changed', () => renderCharts());
