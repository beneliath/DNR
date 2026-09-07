import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip, DoughnutController, ArcElement, BarController, BarElement } from 'chart.js';
import countries from '../data/stats-world.json';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip, DoughnutController, ArcElement, BarController, BarElement);

(() => {
    const payload = document.getElementById('short-link-stats-data');
    const report = document.querySelector('.short-link-report');
    if (!payload || !report) return;
    const data = JSON.parse(payload.textContent);
    const format = new Intl.NumberFormat(document.documentElement.lang || 'en');
    const regionNames = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
    const countryName = code => code === 'ZZ' ? 'Unknown' : /^[A-Z]{2}$/.test(code) ? regionNames?.of(code) || countries.find(country => country.code === code)?.name || code : code;
    const charts = [];
    const colors = ['#a8cef3', '#d6e8fa', '#7fb4e8', '#bdddf8', '#91c4ee', '#5b9bd5', '#e3f0fc'];
    const theme = () => {
        const style = getComputedStyle(report);
        const get = name => style.getPropertyValue(name).trim();
        return { text: get('--text-muted'), surface: get('--surface'), border: get('--border'), blue: get('--stats-blue'), rgb: get('--stats-rgb') };
    };
    const gradient = (context, horizontal = false) => {
        const { ctx, chartArea: area } = context.chart;
        const { rgb } = theme();
        if (!area) return `rgba(${rgb}, .45)`;
        const fill = horizontal ? ctx.createLinearGradient(area.left, 0, area.right, 0) : ctx.createLinearGradient(0, area.top, 0, area.bottom);
        fill.addColorStop(0, `rgba(${rgb}, ${horizontal ? '.12' : '.6'})`);
        fill.addColorStop(1, `rgba(${rgb}, ${horizontal ? '.65' : '.025'})`);
        return fill;
    };
    const baseOptions = () => {
        const palette = theme();
        return {
            responsive: true, maintainAspectRatio: false, animation: false,
            color: palette.text,
            font: { family: getComputedStyle(report).fontFamily, size: 12 },
            plugins: { tooltip: {
                backgroundColor: palette.surface, titleColor: palette.text, bodyColor: palette.blue,
                borderColor: palette.border, borderWidth: 1, cornerRadius: 4, padding: 12, displayColors: false,
                callbacks: { label: context => `Visits: ${format.format(context.raw)}` },
            } },
        };
    };
    const axes = horizontal => {
        const palette = theme();
        const counts = { beginAtZero: true, suggestedMax: 1, grid: { color: palette.border }, border: { color: palette.border }, ticks: { color: palette.text, precision: 0, maxTicksLimit: 6 } };
        const labels = { grid: { color: palette.border }, border: { color: palette.border }, ticks: { color: palette.text, maxRotation: 0, autoSkip: !horizontal, maxTicksLimit: 8 } };
        return horizontal ? { x: counts, y: labels } : { x: labels, y: counts };
    };
    function highlight(chart, index) {
        const element = chart.getDatasetMeta(0).data[index];
        if (!element) return;
        const elements = [{ datasetIndex: 0, index }];
        chart.setActiveElements(elements);
        chart.tooltip.setActiveElements(elements, element.tooltipPosition());
        chart.update('none');
    }
    function clearHighlight(chart) {
        chart.setActiveElements([]);
        chart.tooltip.setActiveElements([], { x: 0, y: 0 });
        chart.update('none');
    }
    function createChart(id, config) {
        const canvas = document.getElementById(id);
        document.getElementById(`${id}-wrap`).hidden = false;
        const chart = new Chart(canvas, config);
        charts.push(chart);
        let index = 0;
        canvas.addEventListener('focus', () => highlight(chart, index));
        canvas.addEventListener('blur', () => clearHighlight(chart));
        canvas.addEventListener('keydown', event => {
            const length = chart.data.labels.length;
            if (!length) return;
            if (event.key === 'Escape') { clearHighlight(chart); return; }
            if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = length - 1;
            else index = (index + (['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1) + length) % length;
            highlight(chart, index);
        });
        return chart;
    }
    function renderTimeline() {
        const label = row => {
            const date = new Date(`${row.label}${data.period === 'month' ? '-01' : ''}T00:00:00Z`);
            return date.toLocaleDateString('en', { timeZone: 'UTC', month: 'short', ...(data.period === 'month' ? { year: 'numeric' } : { day: 'numeric' }) });
        };
        createChart('stats-timeline', {
            type: 'line', data: { labels: data.timeline.map(label), datasets: [{
                data: data.timeline.map(row => row.total), label: 'Visits', fill: true,
                backgroundColor: context => gradient(context), borderColor: theme().blue,
                borderWidth: 1.75, cubicInterpolationMode: 'monotone',
                pointRadius: data.timeline.length === 1 ? 4 : 0, pointHoverRadius: 4,
                pointBackgroundColor: theme().surface, pointBorderColor: theme().blue,
            }] },
            options: { ...baseOptions(), interaction: { mode: 'index', intersect: false }, scales: axes(false),
                plugins: { ...baseOptions().plugins, tooltip: { ...baseOptions().plugins.tooltip, callbacks: {
                    title: contexts => data.timeline[contexts[0].dataIndex].label + ' (UTC)',
                    label: context => `Visits: ${format.format(context.raw)}`,
                } } },
            },
        });
    }
    function renderReferrers() {
        if (!data.referrer.length) return;
        const chart = createChart('stats-referrer', {
            type: 'doughnut', data: { labels: data.referrer.map(row => row.label), datasets: [{
                data: data.referrer.map(row => row.total), backgroundColor: colors,
                borderColor: theme().surface, borderWidth: 2, hoverBorderColor: theme().blue,
            }] }, options: { ...baseOptions(), cutout: '58%', plugins: { ...baseOptions().plugins, tooltip: { ...baseOptions().plugins.tooltip, callbacks: {
                label: context => `${format.format(context.raw)} visits · ${(context.raw / data.total * 100).toFixed(1)}%`,
            } } } },
        });
        const legend = document.getElementById('stats-referrer-legend');
        data.referrer.forEach((row, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('aria-label', `${row.label}: ${format.format(row.total)} visits, ${(row.total / data.total * 100).toFixed(1)}%`);
            const swatch = document.createElement('span');
            swatch.className = `stats-swatch stats-swatch-${index}`;
            swatch.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'stats-legend-label'; label.textContent = row.label;
            const count = document.createElement('span');
            count.className = 'stats-legend-count'; count.textContent = format.format(row.total);
            button.append(swatch, label, count); item.append(button); legend.append(item);
            for (const event of ['mouseenter', 'focus', 'click']) button.addEventListener(event, () => highlight(chart, index));
            for (const event of ['mouseleave', 'blur']) button.addEventListener(event, () => clearHighlight(chart));
        });
    }
    function renderBars(key) {
        if (!data[key].length) return;
        createChart(`stats-${key}`, {
            type: 'bar', data: { labels: data[key].map(row => row.label), datasets: [{
                data: data[key].map(row => row.total), label: 'Visits',
                backgroundColor: context => gradient(context, true), borderColor: theme().blue,
                borderWidth: 1, borderSkipped: false, maxBarThickness: 44, categoryPercentage: .85, barPercentage: .85,
            }] }, options: { ...baseOptions(), indexAxis: 'y', scales: axes(true) },
        });
    }
    function renderMap() {
        const wrapper = document.getElementById('stats-map');
        const svg = document.getElementById('stats-world');
        const tooltip = document.getElementById('stats-map-tooltip');
        const values = new Map(data.country.map(row => [row.label, row.total]));
        const largest = Math.max(1, ...data.country.filter(row => row.label !== 'ZZ').map(row => row.total));
        let active = null;
        function hide() { tooltip.hidden = true; active?.removeAttribute('data-active'); active = null; }
        countries.forEach(country => {
            const count = values.get(country.code) || 0;
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', country.path);
            path.dataset.country = country.code;
            path.dataset.level = count ? String(Math.max(1, Math.ceil(5 * count / largest))) : '0';
            path.setAttribute('aria-label', `${countryName(country.code)}: ${format.format(count)} visits`);
            // Countries with traffic participate in keyboard navigation; all countries support pointer inspection.
            path.setAttribute('tabindex', count ? '0' : '-1');
            function show(event) {
                hide(); active = path; path.setAttribute('data-active', 'true');
                tooltip.textContent = `${countryName(country.code)}: ${format.format(count)} visits`;
                tooltip.hidden = false;
                const bounds = wrapper.getBoundingClientRect();
                const shape = path.getBoundingClientRect();
                const x = event.clientX ?? shape.x + shape.width / 2;
                const y = event.clientY ?? shape.y + shape.height / 2;
                tooltip.style.left = `${Math.max(0, Math.min(bounds.width - tooltip.offsetWidth, x - bounds.left + 10))}px`;
                tooltip.style.top = `${Math.max(0, Math.min(bounds.height - tooltip.offsetHeight, y - bounds.top - tooltip.offsetHeight - 10))}px`;
            }
            for (const event of ['pointerenter', 'pointermove', 'focus', 'click']) path.addEventListener(event, show);
            for (const event of ['pointerleave', 'blur']) path.addEventListener(event, hide);
            path.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
            svg.append(path);
        });
        const mappedCodes = new Set(countries.map(country => country.code));
        const uncharted = data.country.filter(row => row.label !== 'ZZ' && !mappedCodes.has(row.label)).reduce((sum, row) => sum + row.total, 0);
        document.getElementById('stats-unmapped').textContent = `${format.format(values.get('ZZ') || 0)} visits with unknown location${uncharted ? ` · ${format.format(uncharted)} visits in countries or territories too small for this map; see country data` : ''}`;
        document.querySelectorAll('[data-country]').forEach(element => {
            if (element.tagName === 'TH') element.textContent = countryName(element.dataset.country);
        });
        wrapper.hidden = false;
    }
    try {
        renderTimeline(); renderReferrers(); renderBars('browser'); renderMap(); renderBars('os');
        new MutationObserver(() => {
            const palette = theme();
            for (const chart of charts) {
                chart.options.color = palette.text;
                Object.assign(chart.options.plugins.tooltip, { backgroundColor: palette.surface, titleColor: palette.text, bodyColor: palette.blue, borderColor: palette.border });
                const dataset = chart.data.datasets[0];
                dataset.borderColor = chart.config.type === 'doughnut' ? palette.surface : palette.blue;
                if (chart.config.type === 'line') { dataset.pointBackgroundColor = palette.surface; dataset.pointBorderColor = palette.blue; }
                if (chart.config.type !== 'doughnut') chart.options.scales = axes(chart.config.type === 'bar');
                chart.update('none');
            }
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    } catch (error) {
        document.getElementById('stats-chart-error').hidden = false;
        console.error('Unable to render short-link statistics', error);
    }
})();
