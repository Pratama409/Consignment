<script setup>
import { watch } from "vue";
import { Head, router, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";

defineOptions({ layout: AppLayout });
const props = defineProps({ request: Object, role: String, locations: Array });
const page = usePage();
const localNow = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const approval = useForm({ approve: true, note: "", revision_no: props.request.current_revision_no });
const revision = useForm({ reason: "", lines: props.request.lines.map((line) => ({ enabled: false, line_id: line.id, quantity: line.quantity })) });
const issue = useForm({ receiver_name: "", issued_at: localNow(), idempotency_key: props.request.idempotency_key, lines: [] });

function issueLines() {
    return props.request.lines.filter((line) => ["READY", "PARTIALLY_ISSUED"].includes(line.status) && line.remaining > 0).map((line) => ({ enabled: true, line_id: line.id, quantity: line.remaining, bucket: line.buckets[0] ? `${line.buckets[0].vendor_item_id}|${line.buckets[0].location_id}` : "" }));
}
watch(() => props.request, () => { issue.lines = issueLines(); approval.revision_no = props.request.current_revision_no; }, { deep: true, immediate: true });

const ready = (line, status) => router.post(line.readiness_url, { status }, { preserveScroll: true });
const decide = (approve) => approval.transform((data) => ({ ...data, approve })).post(props.request.approve_url, { preserveScroll: true });
const revise = () => revision.transform((data) => ({ reason: data.reason, lines: data.lines.filter((line) => line.enabled).map(({ line_id, quantity }) => ({ line_id, quantity })) })).post(props.request.revision_url, { preserveScroll: true });
const postIssue = () => issue.transform((data) => ({ ...data, lines: data.lines.filter((line) => line.enabled).map((line) => { const [vendor_item_id, location_id] = line.bucket ? line.bucket.split("|") : [null, null]; return { line_id: line.line_id, quantity: line.quantity, vendor_item_id, location_id }; }) })).post(props.request.issue_url, { preserveScroll: true, onSuccess: () => { issue.idempotency_key = crypto.randomUUID(); } });
</script>

<template>
    <Head :title="request.number" />
    <div class="units-page">
        <PageBanner :title="request.number" :description="`${request.applicant} · ${request.department} · ${request.status}`" icon="box" />
        <div v-if="Object.keys(page.props.errors).length" class="field-error" role="alert"><strong>Tindakan belum berhasil.</strong><ul><li v-for="(error, key) in page.props.errors" :key="key">{{ error }}</li></ul></div>
        <section class="surface-card"><h2>Detail permintaan</h2><p>NRP {{ request.nrp }} · {{ request.phone }} · Dibutuhkan {{ request.needed_at }}</p><p>{{ request.purpose }}</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Barang</th><th>Jenis</th><th>Awal</th><th>Efektif</th><th>Diserahkan</th><th>Sisa</th><th>Status</th><th v-if="role === 'warehouse'">Persiapan</th></tr></thead><tbody><tr v-for="line in request.lines" :key="line.id"><td>{{ line.item }}</td><td>{{ line.ownership }}</td><td>{{ line.original_quantity }}</td><td>{{ line.quantity }}</td><td>{{ line.issued }}</td><td>{{ line.remaining }}</td><td>{{ line.status }}</td><td v-if="role === 'warehouse'"><button class="text-button" @click="ready(line, 'PREPARING')">Siapkan</button><button class="text-button" @click="ready(line, 'READY')">Ready</button></td></tr></tbody></table></div></section>
        <section v-if="role === 'admin'" class="surface-card form-card"><form class="form-card__body form-stack" @submit.prevent="revise"><h2>Revisi permintaan</h2><p>Baris yang diubah kembali ke Pending dan memerlukan approval ulang.</p><div v-for="line in revision.lines" :key="line.line_id" class="form-grid"><label><input v-model="line.enabled" type="checkbox" /> {{ request.lines.find((item) => item.id === line.line_id)?.item }}</label><input v-model="line.quantity" type="number" min="1" step="1" class="form-input" :disabled="!line.enabled" /></div><textarea v-model="revision.reason" class="form-input" placeholder="Alasan revisi" required /><button class="button button--secondary" :disabled="!revision.lines.some((line) => line.enabled)">Simpan revisi</button></form></section>
        <section v-if="role === 'admin'" class="surface-card form-stack"><h2>Approval Admin · Revisi {{ request.current_revision_no }}</h2><textarea v-model="approval.note" class="form-input" placeholder="Catatan keputusan" /><div class="form-actions"><button class="button button--primary" @click="decide(true)">Setujui</button><button class="button button--danger" @click="decide(false)">Tolak</button></div></section>
        <section v-if="role === 'warehouse'" class="surface-card form-stack"><h2>Posting Data Out</h2><p>STOCK dicatat tanpa mengubah saldo web. Untuk CONSIGNMENT, pilih bucket Vendor dan lokasi fisik.</p><input v-model="issue.receiver_name" class="form-input" placeholder="Nama penerima" required /><input v-model="issue.issued_at" type="datetime-local" class="form-input" required /><div v-for="line in issue.lines" :key="line.line_id" class="form-grid"><label><input v-model="line.enabled" type="checkbox" /> {{ request.lines.find((item) => item.id === line.line_id)?.item }}</label><div><input v-model="line.quantity" type="number" min="1" step="1" class="form-input" :disabled="!line.enabled" /><select v-if="request.lines.find((item) => item.id === line.line_id)?.ownership === 'CONSIGNMENT'" v-model="line.bucket" class="form-input" :disabled="!line.enabled" required><option value="">Pilih Vendor · lokasi</option><option v-for="bucket in request.lines.find((item) => item.id === line.line_id)?.buckets" :key="bucket.vendor_item_id + bucket.location_id" :value="`${bucket.vendor_item_id}|${bucket.location_id}`">{{ bucket.label }}</option></select></div></div><p v-if="!issue.lines.length" class="page-note">Belum ada barang Ready yang dapat diserahkan.</p><button class="button button--primary" :disabled="!issue.lines.some((line) => line.enabled)" @click="postIssue">Posting serah terima</button></section>
    </div>
</template>
