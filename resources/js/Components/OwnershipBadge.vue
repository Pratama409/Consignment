<script setup>
defineProps({ type: String });
</script>
<template>
    <span
        class="ownership-badge"
        :class="
            type === 'CONSIGNMENT'
                ? 'ownership-badge--consignment'
                : 'ownership-badge--stock'
        "
        >{{ type === "CONSIGNMENT" ? "Consignment" : "Stock internal" }}</span
    >
</template>
