<script setup>
defineProps({ active: Boolean });
</script>

<template>
    <span
        class="status-badge"
        :class="active ? 'status-badge--active' : 'status-badge--inactive'"
        ><span class="status-badge__dot" aria-hidden="true" />{{
            active ? "Aktif" : "Nonaktif"
        }}</span
    >
</template>
