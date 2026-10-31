<script setup>
import { Head } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import Pagination from "../../../Components/Pagination.vue";
defineOptions({ layout: AppLayout });
defineProps({ rows: Object, summary: Object, filters: Object });
</script>
<template>
    <Head title="Rekap Transaksi" />
    <div class="units-page">
        <PageBanner
            title="Rekap Transaksi"
            description="Ledger Data IN, Data OUT, adjustment, dan reversal berdasarkan waktu posting."
            icon="layers"
        />
        <section class="summary-grid" aria-label="Ringkasan pergerakan stok">
            <article class="summary-card">
                <span>Total Data IN</span
                ><strong>{{
                    Number(summary?.in ?? 0).toLocaleString("id-ID")
                }}</strong>
            </article>
            <article class="summary-card">
                <span>Total Data OUT</span
                ><strong>{{
                    Number(summary?.out ?? 0).toLocaleString("id-ID")
                }}</strong>
            </article>
            <article class="summary-card">
                <span>Baris ditemukan</span
                ><strong>{{ rows.total ?? rows.data.length }}</strong>
            </article>
        </section>
        <section class="surface-card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">RIWAYAT POSTING</p>
                    <h2>Daftar transaksi stok</h2>
                </div>
                <a
                    href="/monitoring/transactions/export"
                    class="button button--secondary"
                    >Export Excel</a
                >
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transaksi</th>
                            <th>Waktu/Shift</th>
                            <th>Barang</th>
                            <th>Vendor/Lokasi</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows.data" :key="row.id">
                            <td>
                                {{ row.transaction_no }}<br />{{ row.type }}
                            </td>
                            <td>
                                {{ row.posted_at }} · {{ row.shift }}<br /><span
                                    v-if="row.late"
                                    >Posting terlambat</span
                                >
                            </td>
                            <td>{{ row.item }}<br />{{ row.ownership }}</td>
                            <td>{{ row.vendor }}<br />{{ row.location }}</td>
                            <td class="numeric-cell">
                                <strong>{{
                                    Number(row.quantity).toLocaleString("id-ID")
                                }}</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.data.length" class="page-note">
                Belum ada transaksi pada periode ini.
            </p>
            <Pagination :paginator="rows" noun="baris transaksi" />
        </section>
    </div>
</template>
