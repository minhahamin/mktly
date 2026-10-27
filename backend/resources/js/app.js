import Chart from 'chart.js/auto';

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();

const withAlpha = (hex, alpha) => {
    const n = parseInt(hex.replace('#', ''), 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
};

// 색상은 CSS 토큰 이름('pink-500')으로 받아 실제 값으로 변환한다.
const buildConfig = ({ type, labels, datasets, suffix = '' }) => ({
    type,
    data: {
        labels,
        datasets: datasets.map(({ color, fill, ...rest }) => {
            const base = css(color);

            return {
                ...rest,
                borderColor: base,
                backgroundColor: type === 'line' ? withAlpha(base, fill ? 0.18 : 0.6) : withAlpha(base, 0.75),
                pointBackgroundColor: base,
                pointRadius: 0,
                pointHoverRadius: 5,
                borderWidth: type === 'line' ? 2.5 : 0,
                borderRadius: type === 'bar' ? 8 : 0,
                tension: 0.35,
                fill: Boolean(fill),
            };
        }),
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { usePointStyle: true, boxWidth: 8, color: css('ink-700'), padding: 16 },
            },
            tooltip: {
                backgroundColor: 'rgba(43, 34, 48, 0.92)',
                padding: 12,
                cornerRadius: 12,
                callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${ctx.formattedValue}${suffix}` },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: css('ink-500'), maxTicksLimit: 10 } },
            y: {
                beginAtZero: true,
                grid: { color: css('line') },
                border: { display: false },
                ticks: { color: css('ink-500'), callback: (v) => `${v}${suffix}` },
            },
        },
    },
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chart', (spec) => ({
        instance: null,
        init() {
            this.instance = new Chart(this.$refs.canvas, buildConfig(spec));
        },
        destroy() {
            this.instance?.destroy();
        },
    }));
});
