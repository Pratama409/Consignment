<script setup>
import AppIcon from "./AppIcon.vue";
defineProps({
    label: String,
    value: Number,
    description: String,
    icon: String,
    tone: { type: String, default: "blue" },
});
const number = new Intl.NumberFormat("id-ID");
</script>

<template>
    <article class="summary-card" :class="`summary-card--${tone}`">
        <div class="summary-card__body">
            <div>
                <p class="summary-card__label">{{ label }}</p>
                <p class="summary-card__value">{{ number.format(value) }}</p>
            </div>
            <span class="summary-card__icon"><AppIcon :name="icon" /></span>
        </div>
        <p class="summary-card__description">{{ description }}</p>
    </article>
</template>
