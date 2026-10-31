<script setup>
import { computed } from "vue";
import { describeStatus, toneClass } from "../../Support/status";

const props = defineProps({ status: { type: String, default: "" } });
const info = computed(() => describeStatus(props.status));
</script>

<template>
    <span class="status-badge" :class="`status-badge--${toneClass[info.tone]}`">
        <span class="status-badge__dot" aria-hidden="true" />{{ info.label }}
    </span>
</template>
