<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import AppIcon from "./AppIcon.vue";
const props = defineProps({
    paginator: { type: Object, required: true },
    noun: { type: String, default: "satuan" },
});
const pages = computed(() => props.paginator.links.slice(1, -1));
const number = new Intl.NumberFormat("id-ID");
</script>

<template>
    <div class="table-pagination">
        <p>
            Menampilkan
            <strong
                >{{ number.format(paginator.from ?? 0) }}–{{
                    number.format(paginator.to ?? 0)
                }}</strong
            >
            dari <strong>{{ number.format(paginator.total) }}</strong>
            {{ noun }}
        </p>
        <nav
            v-if="paginator.last_page > 1"
            class="pagination"
            :aria-label="`Halaman daftar ${noun}`"
        >
            <component
                :is="paginator.prev_page_url ? Link : 'button'"
                :href="paginator.prev_page_url || undefined"
                :disabled="!paginator.prev_page_url"
                class="pagination__button"
                aria-label="Halaman sebelumnya"
                preserve-scroll
                ><AppIcon name="chevronLeft" /></component
            ><template v-for="(link, index) in pages" :key="index"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="pagination__button"
                    :class="{ 'pagination__button--active': link.active }"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-label="`Halaman ${link.label}`"
                    preserve-scroll
                    >{{ link.label }}</Link
                ><span v-else class="pagination__ellipsis" aria-hidden="true"
                    >…</span
                ></template
            ><component
                :is="paginator.next_page_url ? Link : 'button'"
                :href="paginator.next_page_url || undefined"
                :disabled="!paginator.next_page_url"
                class="pagination__button"
                aria-label="Halaman berikutnya"
                preserve-scroll
                ><AppIcon name="chevronRight"
            /></component>
        </nav>
    </div>
</template>
