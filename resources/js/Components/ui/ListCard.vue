<script setup>
import AppIcon from "../AppIcon.vue";
import ProgressBar from "./ProgressBar.vue";

defineProps({
    title: { type: String, required: true },
    description: String,
    icon: String,
    badge: { type: [Number, String], default: null },
    badgeTone: { type: String, default: "warn" },
    tone: { type: String, default: "warn" },
    rows: { type: Array, default: () => [] },
    emptyText: { type: String, default: "Aman. Tidak ada item." },
});
const number = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 });
</script>

<template>
    <section class="panel" :aria-label="title">
        <div class="panel__head">
            <div>
                <h2>{{ title }}</h2>
                <p v-if="description">{{ description }}</p>
            </div>
            <span v-if="badge !== null" class="pill" :class="`pill--${badgeTone}`">{{ badge }}</span>
        </div>
        <ul v-if="rows.length" class="rows">
            <li v-for="row in rows" :key="row.code ?? row.label">
                <div class="row-item__top">
                    <div>
                        <span class="row-item__code">{{ row.code ?? row.label }}</span>
                        <span v-if="row.name" class="row-item__name">{{ row.name }}</span>
                    </div>
                    <span class="row-item__value">
                        <template v-if="row.minimum !== undefined">Stok {{ number.format(row.quantity) }} / Min {{ number.format(row.minimum) }}</template>
                        <template v-else>{{ row.total }}</template>
                    </span>
                </div>
                <ProgressBar :percent="row.percent ?? 0" :tone="tone" :label="`${row.code ?? row.label}: ${row.percent ?? 0}%`" />
            </li>
        </ul>
        <p v-else class="empty-hint"><AppIcon name="checkCircle" />{{ emptyText }}</p>
    </section>
</template>
