<script setup>
import AppIcon from "./AppIcon.vue";
defineProps({
    title: String,
    description: String,
    icon: { type: String, default: "ruler" },
});
</script>

<template>
    <section class="page-banner" aria-labelledby="page-title">
        <div class="page-banner__circle" aria-hidden="true" />
        <div class="page-banner__copy">
            <p class="page-banner__eyebrow">CONSIGNMENT CONTROL CENTER</p>
            <h1 id="page-title" tabindex="-1">{{ title }}</h1>
            <p class="page-banner__description">{{ description }}</p>
        </div>
        <div class="page-banner__emblem" aria-hidden="true">
            <AppIcon :name="icon" />
        </div>
    </section>
</template>
