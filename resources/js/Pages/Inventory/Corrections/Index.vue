<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
defineProps({ adjustments: Object, can_manage: Boolean, create_url: String, recent_transactions: Array });
const review = (record, approve) => { const note = prompt(approve ? "Catatan persetujuan" : "Alasan penolakan"); if (note) router.post(record.review_url, { approve, note }); };
const post = (record) => { if (confirm("Posting penyesuaian ini ke ledger?")) router.post(record.post_url); };
const reverse = (transaction) => { const reason = prompt("Alasan reversal wajib diisi"); if (reason) router.post(transaction.reverse_url, { reason }); };
</script>

<template>
    <Head title="Koreksi Stok" />
    <div class="units-page">
        <PageBanner title="Koreksi & Reversal Stok" description="Perubahan stok selalu melalui persetujuan dan ledger baru; histori lama tidak diedit." icon="shield" />
        <div class="form-actions"><Link :href="create_url" class="button button--primary"><AppIcon name="plus" /> Ajukan Koreksi</Link></div>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Pengajuan penyesuaian</h2><p>Warehouse membuat draft; Admin mereview dan memposting.</p></div></div>
            <div class="table-scroll" tabindex="0"><table class="data-table"><thead><tr><th>Barang</th><th>Arah</th><th>Quantity</th><th>Alasan</th><th>Status</th><th>Aksi</th></tr></thead><tbody><tr v-for="record in adjustments.data" :key="record.id"><td>{{ record.item }}<small v-if="record.vendor"> · {{ record.vendor }}</small></td><td>{{ record.direction }}</td><td>{{ record.quantity }}</td><td>{{ record.reason }}</td><td>{{ record.status }}</td><td><div v-if="can_manage" class="table-actions"><button v-if="record.status === 'DRAFT'" class="text-button" @click="review(record, true)">Setujui</button><button v-if="record.status === 'DRAFT'" class="text-button" @click="review(record, false)">Tolak</button><button v-if="record.status === 'APPROVED'" class="text-button" @click="post(record)">Posting</button></div><span v-else>—</span></td></tr><tr v-if="!adjustments.data.length"><td colspan="6">Belum ada pengajuan.</td></tr></tbody></table></div>
        </section>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Transaksi terbaru</h2><p>Reversal membuat transaksi lawan, bukan menghapus transaksi asli.</p></div></div>
            <div class="table-scroll" tabindex="0"><table class="data-table"><thead><tr><th>Nomor</th><th>Jenis</th><th>Posted</th><th>Status</th><th>Aksi</th></tr></thead><tbody><tr v-for="transaction in recent_transactions" :key="transaction.id"><td>{{ transaction.number }}</td><td>{{ transaction.type }}</td><td>{{ transaction.posted_at }}</td><td>{{ transaction.reversed ? 'REVERSED' : 'POSTED' }}</td><td><button v-if="can_manage && !transaction.reversed" class="text-button" @click="reverse(transaction)">Reverse</button><span v-else>—</span></td></tr><tr v-if="!recent_transactions.length"><td colspan="5">Belum ada transaksi stok.</td></tr></tbody></table></div>
        </section>
    </div>
</template>
