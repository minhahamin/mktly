import Chart from 'chart.js/auto';

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim();

const withAlpha = (hex, alpha) => {
    const n = parseInt(hex.replace('#', ''), 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
};

// 색상은 CSS 토큰 이름('pink-500')으로 받아 실제 값으로 변환한다.
const buildConfig = ({ type, labels, datasets, suffix = '', xy = false, legend = true }) => ({
    type,
    data: {
        labels,
        datasets: datasets.map(({ color, fill, dashed, ...rest }) => {
            const base = css(color);

            return {
                ...rest,
                borderColor: base,
                backgroundColor: type === 'line' ? withAlpha(base, fill ? 0.18 : 0.6) : withAlpha(base, 0.75),
                pointBackgroundColor: base,
                pointRadius: 0,
                pointHoverRadius: 5,
                borderWidth: dashed ? 1.5 : type === 'line' ? 2.5 : 0,
                borderDash: dashed ? [5, 4] : [],
                borderRadius: type === 'bar' ? 8 : 0,
                tension: xy ? 0 : 0.35,
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
                display: legend,
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
        scales: xy
            ? {
                  // 좌표형 곡선(ROC): 두 축 모두 0~1 범위의 선형 축을 쓴다.
                  x: { type: 'linear', min: 0, max: 1, grid: { display: false }, ticks: { color: css('ink-500'), stepSize: 0.5 } },
                  y: { type: 'linear', min: 0, max: 1, grid: { color: css('line') }, border: { display: false }, ticks: { color: css('ink-500'), stepSize: 0.5 } },
              }
            : {
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
