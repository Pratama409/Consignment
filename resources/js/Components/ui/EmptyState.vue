<script setup>
import AppIcon from "../AppIcon.vue";

defineProps({
    title: { type: String, required: true },
    hint: String,
    icon: { type: String, default: "info" },
});
</script>

<template>
    <div class="empty-state">
        <span class="empty-state__icon" aria-hidden="true"><AppIcon :name="icon" /></span>
        <h3 class="empty-state__title">{{ title }}</h3>
        <p v-if="hint" class="empty-state__hint">{{ hint }}</p>
        <slot />
    </div>
</template>
