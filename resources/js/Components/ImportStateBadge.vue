<script setup>
defineProps({ state: String });
const labels = {
    valid: "Siap diimport",
    review: "Perlu ditinjau",
    invalid: "Belum valid",
    skipped: "Dilewati",
    completed: "Selesai",
    preview: "Preview",
    duplicate: "Duplikat",
};
</script>
<template>
    <span class="import-state-badge" :class="`import-state-badge--${state}`">{{
        labels[state] ?? state
    }}</span>
</template>
