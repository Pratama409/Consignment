<script setup>
import { Link } from "@inertiajs/vue3";
import AppIcon from "../AppIcon.vue";

defineProps({ label: String, icon: String, url: String });
</script>

<template>
    <Link :href="url" class="quick-card">
        <span class="quick-card__icon" aria-hidden="true"><AppIcon :name="icon" /></span>
        <span>{{ label }}</span>
        <AppIcon name="arrowRight" class="quick-card__arrow" />
    </Link>
</template>
