<script setup>
import { computed } from "vue";
import AppIcon from "../AppIcon.vue";
import { useTheme } from "../../Composables/useTheme";

const { theme, toggle } = useTheme();
const dark = computed(() => theme.value === "dark");
</script>

<template>
    <button
        type="button"
        class="theme-toggle"
        :aria-pressed="dark"
        :aria-label="dark ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'"
        :title="dark ? 'Tema terang' : 'Tema gelap'"
        @click="toggle"
    >
        <AppIcon :name="dark ? 'sun' : 'moon'" />
    </button>
</template>
