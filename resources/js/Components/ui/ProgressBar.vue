<script setup>
defineProps({
    percent: { type: Number, default: 0 },
    tone: { type: String, default: "info" },
    label: String,
});
</script>

<template>
    <div class="progress" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" :aria-label="label">
        <div class="progress__bar" :class="`progress__bar--${tone}`" :style="{ width: `${Math.max(2, percent)}%` }" />
    </div>
</template>
