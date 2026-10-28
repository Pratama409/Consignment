<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
import AppIcon from "./AppIcon.vue";

const props = defineProps({
    open: Boolean,
    title: { type: String, required: true },
    description: { type: String, default: "" },
    busy: Boolean,
    variant: { type: String, default: "default" },
});
const emit = defineEmits(["close"]);
const dialog = ref(null);
const titleId = useId();
const descriptionId = useId();
let previousFocus;

async function syncOpen() {
    await nextTick();
    if (!dialog.value) return;
    if (props.open && !dialog.value.open) {
        previousFocus = document.activeElement;
        dialog.value.showModal();
        await nextTick();
        dialog.value.querySelector("[data-autofocus]")?.focus();
    } else if (!props.open && dialog.value.open) {
        dialog.value.close();
    }
}

function restoreFocus() {
    const target = previousFocus?.isConnected
        ? previousFocus
        : document.querySelector("main h1");
    target?.focus({ preventScroll: true });
}

function requestClose() {
    if (!props.busy) emit("close");
}

function trapFocus(event) {
    if (event.key !== "Tab") return;
    const controls = [
        ...dialog.value.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ].filter((element) => element.getClientRects().length > 0);
    const first = controls[0];
    const last = controls.at(-1);
    const current = document.activeElement;
    if (!first) {
        event.preventDefault();
        dialog.value.focus();
    } else if (
        event.shiftKey &&
        (current === first || current === dialog.value)
    ) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && current === last) {
        event.preventDefault();
        first.focus();
    }
}

function onBackdrop(event) {
    if (event.target !== dialog.value) return;
    const rect = dialog.value.getBoundingClientRect();
    if (
        event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom
    )
        requestClose();
}

watch(() => props.open, syncOpen);
onMounted(syncOpen);
onBeforeUnmount(() => dialog.value?.close());
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="dialog"
            :class="`dialog--${variant}`"
            :aria-labelledby="titleId"
            :aria-describedby="description ? descriptionId : undefined"
            :aria-busy="busy"
            @cancel.prevent="requestClose"
            @click="onBackdrop"
            @close="restoreFocus"
            @keydown="trapFocus"
        >
            <header class="dialog__header">
                <div>
                    <h2 :id="titleId">{{ title }}</h2>
                    <p v-if="description" :id="descriptionId">
                        {{ description }}
                    </p>
                </div>
                <button
                    type="button"
                    class="icon-button"
                    aria-label="Tutup dialog"
                    :disabled="busy"
                    @click="requestClose"
                >
                    <AppIcon name="close" />
                </button>
            </header>
            <slot />
        </dialog>
    </Teleport>
</template>
