<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";
import Pagination from "../../Components/Pagination.vue";
import EmptyState from "../../Components/ui/EmptyState.vue";
import StatusPill from "../../Components/ui/StatusPill.vue";
defineOptions({ layout: AppLayout });
defineProps({ requests: Object });
</script>

<template>
    <Head title="Material Request" />
    <div class="units-page">
        <PageBanner title="Material Request" description="Antrean permintaan, persiapan, approval, dan serah terima barang." icon="box" />
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Antrean permintaan</h2><p>Permintaan terbaru dari pemohon di semua departemen.</p></div></div>
            <div v-if="requests.data.length" class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Nomor</th><th>Pemohon</th><th>Departemen</th><th>Dibutuhkan</th><th>Status</th><th><span class="sr-only">Aksi</span></th></tr></thead>
                    <tbody>
                        <tr v-for="request in requests.data" :key="request.id">
                            <td><strong>{{ request.number }}</strong></td>
                            <td>{{ request.applicant }}</td>
                            <td>{{ request.department }}</td>
                            <td>{{ request.needed_at }}</td>
                            <td><StatusPill :status="request.status" /></td>
                            <td class="numeric"><Link :href="request.show_url" class="row-action">Buka</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="box" title="Belum ada Material Request" hint="Permintaan yang dikirim lewat formulir publik akan muncul di sini." />
            <Pagination :paginator="requests" noun="material request" />
        </section>
    </div>
</template>
