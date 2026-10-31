<script setup>
import { computed, ref } from "vue";

const props = defineProps({
    slices: { type: Array, required: true },
    centerLabel: { type: String, default: "Total" },
});

const R = 80;
const C = 2 * Math.PI * R;
const hover = ref(null);
const fmt = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 });
const total = computed(() => props.slices.reduce((sum, slice) => sum + slice.value, 0));
const arcs = computed(() => {
    let offset = 0;
    return props.slices.map((slice, index) => {
        const length = total.value ? (slice.value / total.value) * C : 0;
        const arc = { ...slice, length, offset, color: `var(--series-${(index % 6) + 1})`, percent: total.value ? Math.round((slice.value / total.value) * 100) : 0 };
        offset += length;
        return arc;
    });
});
</script>

<template>
    <div class="donut">
        <div class="donut__figure">
            <svg viewBox="0 0 200 200" role="img" :aria-label="`${centerLabel}: ${fmt.format(total)}`">
                <circle cx="100" cy="100" :r="R" fill="none" stroke="var(--track)" stroke-width="22" />
                <circle
                    v-for="(arc, index) in arcs"
                    :key="arc.label"
                    class="donut__slice"
                    cx="100"
                    cy="100"
                    :r="R"
                    :stroke="arc.color"
                    :stroke-dasharray="`${Math.max(0, arc.length - (arcs.length > 1 ? 2 : 0))} ${C}`"
                    :stroke-dashoffset="-arc.offset"
                    :style="{ '--circ': C, animationDelay: `${index * 90}ms`, opacity: hover === null || hover === index ? 1 : 0.35 }"
                />
            </svg>
            <div class="donut__center"><strong>{{ fmt.format(total) }}</strong><span>{{ centerLabel }}</span></div>
        </div>
        <ul class="legend">
            <li v-for="(arc, index) in arcs" :key="arc.label" tabindex="0" @mouseenter="hover = index" @mouseleave="hover = null" @focus="hover = index" @blur="hover = null">
                <span class="legend__label"><span class="legend__swatch" :style="{ background: arc.color }" />{{ arc.label }}</span>
                <span class="tabular">{{ fmt.format(arc.value) }} · {{ arc.percent }}%</span>
            </li>
        </ul>
    </div>
</template>
