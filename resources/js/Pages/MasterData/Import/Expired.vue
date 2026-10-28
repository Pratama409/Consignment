<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
defineProps({ batch: Object });
const page = usePage();
</script>
<template>
    <Head title="Preview Kedaluwarsa" />
    <div class="units-page">
        <PageBanner
            title="Preview Kedaluwarsa"
            description="Unggah file kembali untuk memeriksa data terhadap kondisi Master Data terbaru."
            icon="info"
        />
        <section class="surface-card empty-state">
            <span class="empty-state__icon"><AppIcon name="upload" /></span>
            <h2>{{ batch.file_name }}</h2>
            <p>
                Preview berlaku selama 24 jam. Belum ada perubahan Master Data
                yang disimpan dari preview ini.
            </p>
            <Link
                :href="page.props.routes.importsIndex"
                class="button button--primary"
                >Unggah Ulang <AppIcon name="arrowRight"
            /></Link>
        </section>
    </div>
</template>
