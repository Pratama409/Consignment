<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import AppIcon from "../Components/AppIcon.vue";
import AppSidebar from "../Components/AppSidebar.vue";
import BaseDialog from "../Components/BaseDialog.vue";
import PageSkeleton from "../Components/ui/PageSkeleton.vue";
import ThemeToggle from "../Components/ui/ThemeToggle.vue";
import { useSidebar } from "../Composables/useSidebar";

const page = usePage();
const navigationOpen = ref(false);
const flashDismissed = ref(false);
const loadError = ref("");
const loading = ref(false);
const { collapsed, toggle } = useSidebar();
const animating = ref(false);
let loadingTimer;
let animationTimer;
// Animate the width change only for the user's own toggle, so a window resize never lags behind its media query.
function toggleSidebar() {
    animating.value = true;
    clearTimeout(animationTimer);
    animationTimer = setTimeout(() => (animating.value = false), 350);
    toggle();
}
const removeListeners = [];
let desktopQuery;

function closeOnDesktop(event) {
    if (event.matches) navigationOpen.value = false;
}

watch(
    () => page.props.flash,
    () => {
        flashDismissed.value = false;
    },
);
onMounted(() => {
    removeListeners.push(
        // A page-to-page visit shows a skeleton after a short delay; form posts and in-page searches keep their content.
        router.on("start", (event) => {
            const visit = event.detail.visit;
            const navigates = visit.method === "get" && !visit.preserveState && !(visit.only?.length > 0);
            if (navigates) loadingTimer = setTimeout(() => (loading.value = true), 150);
        }),
        router.on("finish", () => {
            clearTimeout(loadingTimer);
            loading.value = false;
        }),
        router.on("networkError", (event) => {
            event.preventDefault();
            loadError.value =
                "Koneksi terputus. Data terakhir tetap ditampilkan. Muat ulang sebelum mengulangi penyimpanan.";
        }),
        router.on("httpException", (event) => {
            if (event.detail.response.status >= 500) {
                event.preventDefault();
                loadError.value =
                    "Data belum bisa dimuat. Coba muat ulang beberapa saat lagi.";
            }
        }),
        router.on("success", () => {
            loadError.value = "";
        }),
    );
    desktopQuery = window.matchMedia("(min-width: 1280px)");
    desktopQuery.addEventListener("change", closeOnDesktop);
});
onBeforeUnmount(() => {
    clearTimeout(loadingTimer);
    clearTimeout(animationTimer);
    desktopQuery?.removeEventListener("change", closeOnDesktop);
    removeListeners.forEach((remove) => remove());
});
</script>

<template>
    <div class="app-shell" :class="{ 'is-animating': animating }" :data-sidebar="collapsed ? 'collapsed' : 'expanded'">
        <a href="#main-content" class="skip-link">Lewati ke konten utama</a>
        <aside class="desktop-sidebar"><AppSidebar :compact="collapsed" /></aside>
        <BaseDialog
            :open="navigationOpen"
            title="Navigasi"
            variant="drawer"
            @close="navigationOpen = false"
            ><AppSidebar @navigate="navigationOpen = false"
        /></BaseDialog>
        <div class="workspace">
            <header class="workspace-bar">
                <div class="workspace-bar__left">
                    <button
                        type="button"
                        class="icon-button mobile-menu-button"
                        aria-label="Buka navigasi"
                        :aria-expanded="navigationOpen"
                        @click="navigationOpen = true"
                    >
                        <AppIcon name="menu" />
                    </button>
                    <button
                        type="button"
                        class="icon-button sidebar-toggle"
                        :aria-pressed="collapsed"
                        :aria-label="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
                        :title="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
                        @click="toggleSidebar"
                    >
                        <AppIcon name="menu" />
                    </button>
                    <div class="breadcrumb">
                        <AppIcon name="layers" /><span>Area Kerja</span
                        ><AppIcon name="chevronRight" /><strong>{{
                            page.props.navigation?.label ?? "Master Data"
                        }}</strong>
                    </div>
                </div>
                <div class="workspace-bar__right">
                    <span class="workspace-label"
                        ><AppIcon name="warehouse" /> Consignment Inventory</span
                    >
                    <ThemeToggle />
                </div>
            </header>
            <main id="main-content" class="workspace-main" tabindex="-1">
                <div
                    v-if="page.props.flash?.success && !flashDismissed"
                    class="flash-message"
                    role="status"
                    aria-live="polite"
                >
                    <AppIcon name="checkCircle" /><span>{{
                        page.props.flash.success
                    }}</span
                    ><button
                        type="button"
                        class="icon-button"
                        aria-label="Tutup pesan berhasil"
                        @click="flashDismissed = true"
                    >
                        <AppIcon name="close" />
                    </button>
                </div>
                <div v-if="loadError" class="load-error" role="alert">
                    <AppIcon name="info" /><span>{{ loadError }}</span>
                    <button
                        type="button"
                        class="button button--secondary"
                        @click="router.reload({ preserveScroll: true })"
                    >
                        Muat ulang
                    </button>
                </div>
                <PageSkeleton v-if="loading" />
                <div v-show="!loading"><slot /></div>
            </main>
            <footer class="workspace-footer">
                Warehouse System <span aria-hidden="true">·</span> Consignment
                Control Center
            </footer>
        </div>
    </div>
</template>
