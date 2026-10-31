<script setup>
import { computed, ref } from "vue";

const props = defineProps({
    series: { type: Array, required: true },
    inLabel: { type: String, default: "Masuk" },
    outLabel: { type: String, default: "Keluar" },
});

const W = 720;
const H = 250;
const M = { left: 46, right: 8, top: 10, bottom: 30 };
const plotW = W - M.left - M.right;
const plotH = H - M.top - M.bottom;
const hover = ref(null);
const fmt = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 });

const top = computed(() => {
    const max = Math.max(0, ...props.series.flatMap((day) => [day.in, day.out]));
    if (max === 0) return 4;
    const power = 10 ** Math.floor(Math.log10(max));
    const unit = [1, 2, 2.5, 5, 10].map((m) => m * power).find((candidate) => candidate >= max) ?? max;
    return unit;
});
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => ({ value: (top.value / 4) * i, y: M.top + plotH - (plotH / 4) * i })));
const slot = computed(() => plotW / Math.max(1, props.series.length));
const barW = computed(() => Math.max(3, Math.min(14, slot.value * 0.34)));
const height = (value) => (value <= 0 ? 0 : Math.max(2, (value / top.value) * plotH));
const label = (date) => `${date.slice(8, 10)}/${date.slice(5, 7)}`;
const showLabel = (index) => index % 5 === 0 || index === props.series.length - 1;
const centerX = (index) => M.left + slot.value * index + slot.value / 2;
const tooltip = computed(() => {
    if (hover.value === null) return null;
    const day = props.series[hover.value];
    const barTop = M.top + plotH - Math.max(height(day.in), height(day.out));
    return { day, left: `${(centerX(hover.value) / W) * 100}%`, top: `${Math.max(0, ((barTop - 8) / H) * 100)}%` };
});
</script>

<template>
    <div class="chart" @mouseleave="hover = null">
        <div class="chart__scroll"><div class="chart__canvas">
        <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="`Grafik ${inLabel} dan ${outLabel} 30 hari terakhir`">
            <g class="chart__grid">
                <line v-for="tick in ticks" :key="tick.y" :x1="M.left" :x2="W - M.right" :y1="tick.y" :y2="tick.y" />
            </g>
            <g class="chart__axis">
                <text v-for="tick in ticks" :key="`t${tick.y}`" :x="M.left - 8" :y="tick.y + 4" text-anchor="end">{{ fmt.format(tick.value) }}</text>
                <template v-for="(day, index) in series" :key="`l${day.date}`">
                    <text v-if="showLabel(index)" :x="centerX(index)" :y="H - 8" text-anchor="middle">{{ label(day.date) }}</text>
                </template>
            </g>
            <g
                v-for="(day, index) in series"
                :key="day.date"
                class="chart__group"
                tabindex="0"
                :aria-label="`${label(day.date)}: ${inLabel} ${fmt.format(day.in)}, ${outLabel} ${fmt.format(day.out)}`"
                @mouseenter="hover = index"
                @focus="hover = index"
                @blur="hover = null"
            >
                <rect class="chart__hit" :x="M.left + slot * index" :y="M.top" :width="slot" :height="plotH" />
                <rect
                    class="chart__bar chart__bar--in"
                    rx="3"
                    :x="centerX(index) - barW - 1"
                    :y="M.top + plotH - height(day.in)"
                    :width="barW"
                    :height="height(day.in)"
                    :style="{ animationDelay: `${index * 16}ms` }"
                />
                <rect
                    class="chart__bar chart__bar--out"
                    rx="3"
                    :x="centerX(index) + 1"
                    :y="M.top + plotH - height(day.out)"
                    :width="barW"
                    :height="height(day.out)"
                    :style="{ animationDelay: `${index * 16 + 40}ms` }"
                />
            </g>
        </svg>
        <div v-if="tooltip" class="chart__tooltip" :style="{ left: tooltip.left, top: tooltip.top }" role="status">
            <strong>{{ tooltip.day.date.split("-").reverse().join("/") }}</strong>
            <div>{{ inLabel }}: {{ fmt.format(tooltip.day.in) }}</div>
            <div>{{ outLabel }}: {{ fmt.format(tooltip.day.out) }}</div>
        </div>
        </div></div>
        <ul class="legend" aria-hidden="true">
            <li><span class="legend__swatch" style="background: var(--series-2)" />{{ inLabel }}</li>
            <li><span class="legend__swatch" style="background: var(--series-4)" />{{ outLabel }}</li>
        </ul>
        <table class="sr-only">
            <caption>Pergerakan stok 30 hari</caption>
            <thead><tr><th>Tanggal</th><th>{{ inLabel }}</th><th>{{ outLabel }}</th></tr></thead>
            <tbody><tr v-for="day in series" :key="day.date"><td>{{ day.date }}</td><td>{{ day.in }}</td><td>{{ day.out }}</td></tr></tbody>
        </table>
    </div>
</template>
