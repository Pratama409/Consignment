<script setup>
import { computed } from "vue";
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../Layouts/AppLayout.vue";
import AppIcon from "../Components/AppIcon.vue";
import PageBanner from "../Components/PageBanner.vue";
import BarChart from "../Components/ui/BarChart.vue";
import DonutChart from "../Components/ui/DonutChart.vue";
import ListCard from "../Components/ui/ListCard.vue";
import QuickLinkCard from "../Components/ui/QuickLinkCard.vue";
import StatCard from "../Components/ui/StatCard.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ metrics: Object, lists: Object, charts: Object, quick_links: Array, recent_transactions: Array, generated_at: String });
const page = usePage();
const user = computed(() => page.props.auth.user);
const internal = computed(() => user.value.role !== "vendor");
const followUps = computed(() => page.props.navigation?.badges?.refills ?? 0);
const typeLabels = { OPENING: "Saldo awal", MANUAL_RECEIPT: "Data IN", MATERIAL_ISSUE: "Data OUT", REFILL_RECEIPT: "Refill diterima", ADJUSTMENT: "Koreksi stok", REVERSAL: "Reversal" };
const movementTotal = computed(() => props.charts.movement.reduce((sum, day) => sum + day.in + day.out, 0));
const procurementRows = computed(() => (props.lists.procurement_by_vendor ?? []).map((row) => ({ label: row.vendor, total: row.total })));
</script>

<template>
    <Head title="Dashboard" />
    <div class="dash">
        <PageBanner title="Dashboard Analytics" description="Kondisi persediaan, pergerakan stok, dan pekerjaan yang masih perlu ditindaklanjuti." icon="warehouse" style="--i: 0">
            <template #chips>
                <span class="chip"><AppIcon name="shield" />{{ user.name }}</span>
                <span class="chip">{{ user.role_label }}</span>
                <span class="chip"><AppIcon name="info" />Diperbarui {{ generated_at }}</span>
            </template>
        </PageBanner>

        <nav class="quick-grid" aria-label="Pintasan" style="--i: 1">
            <QuickLinkCard v-for="link in quick_links" :key="link.label" v-bind="link" />
        </nav>

        <section class="stat-grid" aria-label="Ringkasan" style="--i: 2">
            <StatCard label="Total Barang" :value="metrics.total_items" icon="box" tone="neutral" hint="Barang aktif" />
            <StatCard label="Total Stok" :value="Number(metrics.total_stock)" icon="warehouse" tone="info" hint="Seluruh posisi stok" :decimals="Number.isInteger(Number(metrics.total_stock)) ? 0 : 2" />
            <StatCard label="Stok < Min" :value="metrics.low_stock" icon="alert" tone="warn" hint="Di bawah batas minimum" />
            <StatCard label="Stok 0 / Kritis" :value="metrics.critical_stock" icon="alert" tone="danger" hint="Habis tetapi punya minimum" />
            <StatCard v-if="internal" label="Vendor Aktif" :value="metrics.active_vendors" icon="truck" tone="neutral" />
            <StatCard label="Refill Berjalan" :value="metrics.open_refills" icon="truck" tone="info" :hint="!internal && followUps ? `${followUps} perlu ditindaklanjuti` : undefined" />
            <StatCard v-if="internal" label="Material Request Aktif" :value="metrics.active_requests" icon="layers" tone="neutral" />
            <StatCard label="Pengadaan Outstanding" :value="metrics.procurement_outstanding" icon="layers" tone="warn" />
            <StatCard v-if="internal" label="Stock Opname Terbuka" :value="metrics.open_stock_opname" icon="checkCircle" tone="neutral" />
        </section>

        <h2 class="dash__section-title" style="--i: 3"><AppIcon name="activity" />Operational Health</h2>
        <section class="dash__grid-3" style="--i: 3">
            <ListCard title="Stok < Min" description="Paling jauh dari batas minimum" :rows="lists.low_stock" :badge="metrics.low_stock" tone="warn" empty-text="Aman. Tidak ada barang di bawah minimum." />
            <ListCard title="Stok 0 / Kritis" description="Perlu refill atau penerimaan" :rows="lists.critical_stock" :badge="metrics.critical_stock" badge-tone="danger" tone="danger" empty-text="Aman. Tidak ada stok habis." />
            <ListCard title="Antrean Refill" description="Per tahap proses" :rows="lists.refill_queue" :badge="metrics.open_refills" badge-tone="warn" tone="info" empty-text="Tidak ada refill berjalan." />
        </section>

        <h2 class="dash__section-title" style="--i: 4"><AppIcon name="chart" />Analytics</h2>
        <section class="dash__grid-2" style="--i: 4">
            <section class="panel" aria-label="Pergerakan stok">
                <div class="panel__head"><div><h2>Pergerakan IN vs OUT 30 Hari</h2><p>Jumlah quantity yang diposting per hari, tanpa saldo awal.</p></div><span class="pill">30 hari</span></div>
                <BarChart v-if="movementTotal > 0" :series="charts.movement" in-label="IN" out-label="OUT" />
                <p v-else class="empty-hint"><AppIcon name="info" />Belum ada pergerakan stok dalam 30 hari terakhir.</p>
            </section>
            <section class="panel" aria-label="Komposisi stok">
                <div class="panel__head"><div><h2>{{ internal ? "Komposisi Stok Vendor" : "Stok per Lokasi" }}</h2><p>{{ internal ? "Saldo saat ini per pemilik" : "Saldo saat ini per lokasi" }}</p></div><span class="pill">Top 5</span></div>
                <DonutChart v-if="charts.composition.length" :slices="charts.composition" center-label="Total stok" />
                <p v-else class="empty-hint"><AppIcon name="info" />Belum ada saldo stok.</p>
            </section>
        </section>

        <section v-if="internal" class="dash__grid-even" style="--i: 5">
            <section class="panel" aria-label="Pengadaan per Vendor">
                <div class="panel__head"><div><h2>Pengadaan Outstanding per Vendor</h2><p>Reservasi, PR, validasi, dan PO yang belum selesai.</p></div><Link :href="quick_links.find((link) => link.label.startsWith('PR'))?.url" class="pill">Buka</Link></div>
                <table v-if="procurementRows.length" class="mini-table">
                    <thead><tr><th>Vendor</th><th>Total</th></tr></thead>
                    <tbody><tr v-for="row in procurementRows" :key="row.label"><td>{{ row.label }}</td><td>{{ row.total }}</td></tr></tbody>
                </table>
                <p v-else class="empty-hint"><AppIcon name="checkCircle" />Tidak ada pengadaan outstanding.</p>
            </section>
            <section class="panel" aria-label="Transaksi terbaru">
                <div class="panel__head"><div><h2>Transaksi Stok Terbaru</h2><p>Delapan transaksi terakhir yang diposting.</p></div><Link :href="quick_links.find((link) => link.label === 'Stock Sistem')?.url" class="pill">Monitoring</Link></div>
                <table v-if="recent_transactions.length" class="mini-table">
                    <thead><tr><th>Jenis</th><th>Waktu</th></tr></thead>
                    <tbody><tr v-for="transaction in recent_transactions" :key="transaction.id"><td>{{ typeLabels[transaction.type] ?? transaction.type }} · {{ transaction.lines }} baris</td><td>{{ transaction.posted_at }}</td></tr></tbody>
                </table>
                <p v-else class="empty-hint"><AppIcon name="info" />Belum ada transaksi.</p>
            </section>
        </section>
    </div>
</template>
