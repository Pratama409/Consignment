<script setup>
defineProps({
    id: String,
    label: String,
    error: String,
    hint: String,
    required: Boolean,
});
</script>

<template>
    <div class="form-field">
        <label :for="id"
            >{{ label }}
            <span v-if="required" class="required-mark" aria-hidden="true"
                >*</span
            ></label
        ><slot />
        <p v-if="error" :id="`${id}-error`" class="field-error" role="alert">
            {{ error }}
        </p>
        <p v-else-if="hint" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
    </div>
</template>
