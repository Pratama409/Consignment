<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import Pagination from "../../../Components/Pagination.vue";
defineOptions({ layout: AppLayout });
defineProps({ shifts: Object });
</script>
<template>
    <Head title="Rekap Shift" />
    <div class="units-page">
        <PageBanner
            title="Rekap Shift"
            description="Ringkasan transaksi per periode kerja 12 jam yang dapat dibekukan untuk audit."
            icon="layers"
        />
        <section class="surface-card">
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in shifts.data" :key="row.id">
                            <td>{{ row.operational_date }} · {{ row.code }}</td>
                            <td>{{ row.starts_at }}</td>
                            <td>{{ row.ends_at }}</td>
                            <td>{{ row.status }}</td>
                            <td>
                                <Link
                                    :href="`/monitoring/shifts/${row.id}`"
                                    class="text-button"
                                    >Lihat rekap</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!shifts.data.length" class="page-note">
                Belum ada shift operasional.
            </p>
            <Pagination :paginator="shifts" noun="shift" />
        </section>
    </div>
</template>
