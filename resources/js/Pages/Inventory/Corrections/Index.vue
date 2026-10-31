<script setup>
import { computed, ref } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import Pagination from "../../../Components/Pagination.vue";
import EmptyState from "../../../Components/ui/EmptyState.vue";
import StatusPill from "../../../Components/ui/StatusPill.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ adjustments: Object, can_manage: Boolean, create_url: String, recent_transactions: Object, transaction_search: String });
const page = usePage();
const errors = computed(() => page.props.errors || {});
const search = ref(props.transaction_search || "");
const runSearch = () => router.get(page.url.split("?")[0], { q: search.value }, { preserveState: true, replace: true });
const review = (record, approve) => { const note = prompt(approve ? "Catatan persetujuan" : "Alasan penolakan"); if (note) router.post(record.review_url, { approve, note }); };
const post = (record) => { if (confirm("Posting penyesuaian ini ke ledger?")) router.post(record.post_url); };
const reverse = (transaction) => { const impact = transaction.impact.map((line) => `${line.item} · ${line.location} · ${line.quantity}`).join("\n"); const reason = prompt(`Dampak reversal:\n${impact}\n\nMasukkan alasan:`); if (reason) router.post(transaction.reverse_url, { reason }); };
</script>

<template>
    <Head title="Koreksi Stok" />
    <div class="units-page">
        <PageBanner title="Koreksi & Reversal Stok" description="Perubahan stok selalu melalui persetujuan dan ledger baru; histori lama tidak diedit." icon="shield" />
        <div v-if="Object.keys(errors).length" class="field-error" role="alert" tabindex="-1"><strong>Tindakan belum dapat diproses.</strong><ul><li v-for="(message, field) in errors" :key="field">{{ message }}</li></ul></div>
        <div class="form-actions"><Link :href="create_url" class="button button--primary"><AppIcon name="plus" /> Ajukan Koreksi</Link></div>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Pengajuan penyesuaian</h2><p>Warehouse membuat draft; Admin mereview dan memposting.</p></div></div>
            <div class="table-scroll" tabindex="0"><table class="data-table"><thead><tr><th>Barang & lokasi</th><th>Arah</th><th>Quantity</th><th>Alasan</th><th>Status</th><th>Aksi</th></tr></thead><tbody><tr v-for="record in adjustments.data" :key="record.id"><td><strong>{{ record.item }}</strong><br /><small>{{ record.location }}<template v-if="record.vendor"> · {{ record.vendor }}</template></small></td><td><span class="status-badge" :class="record.direction === 'IN' ? 'status-badge--active' : 'status-badge--danger'">{{ record.direction }}</span></td><td class="numeric">{{ record.quantity }}</td><td>{{ record.reason }}</td><td><StatusPill :status="record.status" /></td><td><div v-if="can_manage" class="table-actions"><button v-if="record.status === 'DRAFT'" class="row-action row-action--success" @click="review(record, true)">Setujui</button><button v-if="record.status === 'DRAFT'" class="row-action row-action--danger" @click="review(record, false)">Tolak</button><button v-if="record.status === 'APPROVED'" class="row-action" @click="post(record)">Posting</button></div><span v-else>—</span></td></tr></tbody></table></div>
            <EmptyState v-if="!adjustments.data.length" icon="shield" title="Belum ada pengajuan" hint="Draft koreksi stok dari Warehouse atau Stock Opname akan muncul di sini." />
            <Pagination :paginator="adjustments" noun="pengajuan" />
        </section>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Transaksi stok</h2><p>Cari nomor transaksi dan tinjau dampaknya sebelum reversal.</p></div><form class="table-search" @submit.prevent="runSearch"><input v-model="search" class="form-input" placeholder="Cari nomor transaksi" aria-label="Cari nomor transaksi" /><button class="button button--secondary" type="submit">Cari</button></form></div>
            <div class="table-scroll" tabindex="0"><table class="data-table"><thead><tr><th>Nomor</th><th>Jenis & dampak</th><th>Posted</th><th>Status</th><th>Aksi</th></tr></thead><tbody><tr v-for="transaction in recent_transactions.data" :key="transaction.id"><td><strong>{{ transaction.number }}</strong></td><td>{{ transaction.type }}<small v-for="line in transaction.impact" :key="`${transaction.id}-${line.item}-${line.location}`"> · {{ line.item }} · {{ line.location }} · {{ line.quantity }}</small></td><td>{{ transaction.posted_at }}</td><td><StatusPill :status="transaction.reversed ? 'REVERSED' : 'POSTED'" /></td><td><button v-if="can_manage && !transaction.reversed" class="row-action row-action--danger" @click="reverse(transaction)">Reverse</button><span v-else>—</span></td></tr></tbody></table></div>
            <EmptyState v-if="!recent_transactions.data.length" icon="layers" title="Belum ada transaksi stok" hint="Transaksi yang diposting akan muncul di sini." />
            <Pagination :paginator="recent_transactions" noun="transaksi" />
        </section>
    </div>
</template>
