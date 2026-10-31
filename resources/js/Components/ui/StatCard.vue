<script setup>
import { computed } from "vue";
import AppIcon from "../AppIcon.vue";
import { useCountUp } from "../../Composables/useCountUp";

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    icon: { type: String, default: "box" },
    tone: { type: String, default: "neutral" },
    hint: String,
    decimals: { type: Number, default: 0 },
});
const animated = useCountUp(props.value, { decimals: props.decimals });
const formatter = computed(() => new Intl.NumberFormat("id-ID", { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals }));
const shown = computed(() => formatter.value.format(animated.value));
</script>

<template>
    <article class="stat" :class="`stat--${tone}`">
        <div class="stat__top">
            <p class="stat__label">{{ label }}</p>
            <span class="stat__icon" aria-hidden="true"><AppIcon :name="icon" /></span>
        </div>
        <p class="stat__value" :aria-label="`${label}: ${formatter.format(Number(value))}`">{{ shown }}</p>
        <p v-if="hint" class="stat__hint">{{ hint }}</p>
    </article>
</template>
