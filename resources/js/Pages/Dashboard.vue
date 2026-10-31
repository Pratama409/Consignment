<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../Layouts/AppLayout.vue";
import PageBanner from "../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
defineProps({ metrics: Object, recent_transactions: Array });
const page = usePage();
</script>

<template>
    <Head title="Dashboard" />
    <div class="units-page">
        <PageBanner
            title="Dashboard Operasional"
            description="Ringkasan kondisi persediaan dan pekerjaan yang masih perlu ditindaklanjuti."
            icon="warehouse"
        />
        <section class="summary-grid" aria-label="Ringkasan operasional">
            <article class="summary-card">
                <span>Bucket stok aktif</span
                ><strong>{{ metrics.stock_buckets }}</strong>
            </article>
            <article class="summary-card">
                <span>Refill berjalan</span
                ><strong>{{ metrics.open_refills }}</strong>
            </article>
            <article
                v-if="metrics.active_requests !== null"
                class="summary-card"
            >
                <span>Material Request aktif</span
                ><strong>{{ metrics.active_requests }}</strong>
            </article>
            <article class="summary-card">
                <span>Pengadaan outstanding</span
                ><strong>{{ metrics.procurement_outstanding }}</strong>
            </article>
        </section>
        <section class="surface-card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">AKTIVITAS TERBARU</p>
                    <h2>Transaksi stok</h2>
                </div>
                <Link
                    v-if="page.props.auth.user.role !== 'vendor'"
                    :href="page.props.routes.monitoringTransactionsIndex"
                    class="button button--secondary"
                    >Lihat monitoring</Link
                >
            </div>
            <div v-if="recent_transactions.length" class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nomor</th>
                            <th>Jenis</th>
                            <th>Waktu posting</th>
                            <th>Baris</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in recent_transactions" :key="row.id">
                            <td>{{ row.number }}</td>
                            <td>{{ row.type }}</td>
                            <td>{{ row.posted_at }}</td>
                            <td>{{ row.lines }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="page-note">
                Belum ada transaksi stok yang dapat ditampilkan.
            </p>
        </section>
    </div>
</template>
