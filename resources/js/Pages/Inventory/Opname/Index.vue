<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import Pagination from "../../../Components/Pagination.vue";
defineOptions({ layout: AppLayout });
defineProps({ sessions: Object, can: Object, create_url: String });
const statusLabel = { COUNTING: "Sedang dihitung", COUNTED: "Menunggu persetujuan", RESOLVING: "Menyelesaikan selisih", COMPLETED: "Selesai", CANCELLED: "Dibatalkan" };
const statusClass = { COUNTING: "info", COUNTED: "warning", RESOLVING: "warning", COMPLETED: "active", CANCELLED: "inactive" };
const scopeLabel = { FULL: "Full", PARTIAL: "Parsial", CYCLE: "Cycle count" };
</script>

<template>
    <Head title="Stock Opname" />
    <div class="units-page">
        <PageBanner title="Stock Opname" description="Hitung fisik dibandingkan dengan stok sistem. Selisih membekukan barang sampai diselesaikan." icon="checkCircle" />
        <div v-if="can.create" class="form-actions"><Link :href="create_url" class="button button--primary"><AppIcon name="plus" /> Buat Sesi</Link></div>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Sesi Stock Opname</h2><p>Admin membuat dan menyetujui sesi; Warehouse menghitung.</p></div></div>
            <div class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Nomor</th><th>Jenis</th><th>Baris</th><th>Dibuat oleh</th><th>Dibuat</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <tr v-for="session in sessions.data" :key="session.id">
                            <td><strong>{{ session.number }}</strong></td>
                            <td>{{ scopeLabel[session.scope] }}<small v-if="session.cycle_month"> · {{ session.cycle_month }}</small></td>
                            <td>{{ session.lines }}</td>
                            <td>{{ session.creator }}</td>
                            <td>{{ session.created_at }}</td>
                            <td><span class="status-badge" :class="`status-badge--${statusClass[session.status]}`"><span class="status-badge__dot" aria-hidden="true" />{{ statusLabel[session.status] }}</span></td>
                            <td><Link :href="session.show_url" class="text-button">Buka</Link></td>
                        </tr>
                        <tr v-if="!sessions.data.length"><td colspan="7">Belum ada sesi Stock Opname.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="sessions" noun="sesi" />
        </section>
    </div>
</template>
