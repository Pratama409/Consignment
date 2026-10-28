<script setup>
import AppIcon from "./AppIcon.vue";
defineProps({ step: { type: Number, default: 1 } });
const steps = ["Unggah", "Tinjau data", "Konfirmasi", "Hasil"];
</script>
<template>
    <ol class="import-steps" aria-label="Tahapan import">
        <li
            v-for="(label, index) in steps"
            :key="label"
            :class="{
                'import-step--active': step === index + 1,
                'import-step--done': step > index + 1,
            }"
            :aria-current="step === index + 1 ? 'step' : undefined"
        >
            <span class="import-step__number"
                ><AppIcon v-if="step > index + 1" name="check" /><template
                    v-else
                    >{{ index + 1 }}</template
                ></span
            ><span>{{ label }}</span>
        </li>
    </ol>
</template>
