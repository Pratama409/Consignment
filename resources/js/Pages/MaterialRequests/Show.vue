<script setup>
import { watch } from "vue";
import { Head, router, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import FormField from "../../Components/FormField.vue";
import PageBanner from "../../Components/PageBanner.vue";
import EmptyState from "../../Components/ui/EmptyState.vue";
import StatusPill from "../../Components/ui/StatusPill.vue";

defineOptions({ layout: AppLayout });
const props = defineProps({ request: Object, role: String, locations: Array });
const page = usePage();
const localNow = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const approval = useForm({ approve: true, note: "", revision_no: props.request.current_revision_no });
const revision = useForm({ reason: "", lines: props.request.lines.map((line) => ({ enabled: false, line_id: line.id, quantity: line.quantity })) });
const issue = useForm({ receiver_name: "", work_order_no: "", issued_at: localNow(), idempotency_key: props.request.idempotency_key, lines: [] });
const lineOf = (id) => props.request.lines.find((item) => item.id === id);

function issueLines() {
    return props.request.lines
        .filter((line) => ["READY", "PARTIALLY_ISSUED"].includes(line.status) && line.remaining > 0)
        .map((line) => ({ enabled: true, line_id: line.id, quantity: line.remaining, is_backcharge: false, bucket: line.buckets[0] ? `${line.buckets[0].vendor_item_id}|${line.buckets[0].location_id}` : "" }));
}
watch(
    () => props.request,
    () => {
        issue.lines = issueLines();
        approval.revision_no = props.request.current_revision_no;
    },
    { deep: true, immediate: true },
);

const ready = (line, status) => router.post(line.readiness_url, { status }, { preserveScroll: true });
const decide = (approve) => approval.transform((data) => ({ ...data, approve })).post(props.request.approve_url, { preserveScroll: true });
const revise = () =>
    revision
        .transform((data) => ({ reason: data.reason, lines: data.lines.filter((line) => line.enabled).map(({ line_id, quantity }) => ({ line_id, quantity })) }))
        .post(props.request.revision_url, { preserveScroll: true });
const postIssue = () =>
    issue
        .transform((data) => ({
            ...data,
            lines: data.lines
                .filter((line) => line.enabled)
                .map((line) => {
                    const [vendor_item_id, location_id] = line.bucket ? line.bucket.split("|") : [null, null];
                    return { line_id: line.line_id, quantity: line.quantity, vendor_item_id, location_id, is_backcharge: line.is_backcharge };
                }),
        }))
        .post(props.request.issue_url, {
            preserveScroll: true,
            onSuccess: () => {
                issue.idempotency_key = crypto.randomUUID();
            },
        });
</script>

<template>
    <Head :title="request.number" />
    <div class="units-page">
        <PageBanner :title="request.number" :description="`${request.applicant} · ${request.department}`" icon="box">
            <template #chips><span class="chip">NRP {{ request.nrp }}</span><span class="chip">Dibutuhkan {{ request.needed_at }}</span></template>
        </PageBanner>
        <div v-if="Object.keys(page.props.errors).length" class="field-error" role="alert">
            <strong>Tindakan belum berhasil.</strong>
            <ul><li v-for="(error, key) in page.props.errors" :key="key">{{ error }}</li></ul>
        </div>

        <section class="summary-strip" aria-label="Ringkasan permintaan">
            <div class="summary-strip__item"><span class="summary-strip__label">Status</span><StatusPill :status="request.status" /></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Telepon</span><span class="summary-strip__value" style="font-size: 16px">{{ request.phone }}</span></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Revisi aktif</span><span class="summary-strip__value">{{ request.current_revision_no }}</span></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Keperluan</span><span style="color: var(--text-body); font-size: 14px">{{ request.purpose }}</span></div>
        </section>

        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Barang diminta</h2><p>Quantity awal, efektif setelah revisi, dan yang sudah diserahkan.</p></div></div>
            <div class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead>
                        <tr><th>Barang</th><th>Jenis</th><th class="numeric">Awal</th><th class="numeric">Efektif</th><th class="numeric">Diserahkan</th><th class="numeric">Sisa</th><th>Status</th><th v-if="role === 'warehouse'">Persiapan</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in request.lines" :key="line.id">
                            <td><strong>{{ line.item }}</strong></td>
                            <td>{{ line.ownership }}</td>
                            <td class="numeric">{{ line.original_quantity }}</td>
                            <td class="numeric">{{ line.quantity }}</td>
                            <td class="numeric">{{ line.issued }}</td>
                            <td class="numeric">{{ line.remaining }}</td>
                            <td><StatusPill :status="line.status" /></td>
                            <td v-if="role === 'warehouse'">
                                <div class="table-actions">
                                    <button type="button" class="row-action" @click="ready(line, 'PREPARING')">Siapkan</button>
                                    <button type="button" class="row-action row-action--success" @click="ready(line, 'READY')">Ready</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="role === 'admin'" class="dash__grid-even">
            <section class="surface-card form-card">
                <form class="form-card__body form-stack" @submit.prevent="revise">
                    <h2>Revisi permintaan</h2>
                    <p>Baris yang diubah kembali ke Pending dan memerlukan approval ulang.</p>
                    <div>
                        <div v-for="line in revision.lines" :key="line.line_id" class="line-grid line-grid--refill" :class="{ 'is-off': !line.enabled }">
                            <label><input v-model="line.enabled" type="checkbox" /><span class="line-grid__title">{{ lineOf(line.line_id)?.item }}</span></label>
                            <input v-model="line.quantity" type="number" min="1" step="1" class="form-input" :disabled="!line.enabled" aria-label="Quantity revisi" />
                        </div>
                    </div>
                    <FormField id="revision-reason" label="Alasan revisi"><textarea id="revision-reason" v-model="revision.reason" class="form-input" rows="3" required /></FormField>
                    <div><button class="button button--secondary" :disabled="!revision.lines.some((line) => line.enabled)">Simpan revisi</button></div>
                </form>
            </section>
            <section class="surface-card form-card">
                <div class="form-card__body form-stack">
                    <h2>Approval Admin · Revisi {{ request.current_revision_no }}</h2>
                    <FormField id="approval-note" label="Catatan keputusan"><textarea id="approval-note" v-model="approval.note" class="form-input" rows="4" /></FormField>
                    <div class="form-actions">
                        <button type="button" class="button button--primary" @click="decide(true)">Setujui</button>
                        <button type="button" class="button button--danger" @click="decide(false)">Tolak</button>
                    </div>
                </div>
            </section>
        </div>

        <section v-if="role === 'warehouse'" class="surface-card form-card">
            <div class="form-card__heading"><div><h2>Posting Data OUT</h2><p>STOCK dicatat tanpa mengubah saldo web. Untuk CONSIGNMENT, pilih bucket Vendor dan lokasi fisik.</p></div></div>
            <div class="form-card__body form-stack">
                <div class="form-grid">
                    <FormField id="issue-receiver" label="Nama penerima" required><input id="issue-receiver" v-model="issue.receiver_name" class="form-input" required /></FormField>
                    <FormField id="issue-wo" label="Nomor WO (opsional)" :error="issue.errors.work_order_no"><input id="issue-wo" v-model="issue.work_order_no" class="form-input" maxlength="50" aria-label="Nomor WO" /></FormField>
                </div>
                <FormField id="issue-at" label="Waktu serah terima" required><input id="issue-at" v-model="issue.issued_at" type="datetime-local" class="form-input" required /></FormField>
                <div v-if="issue.lines.length">
                    <div v-for="line in issue.lines" :key="line.line_id" class="line-grid line-grid--receipt" :class="{ 'is-off': !line.enabled }">
                        <label><input v-model="line.enabled" type="checkbox" /><span class="line-grid__title">{{ lineOf(line.line_id)?.item }}<small>Sisa {{ lineOf(line.line_id)?.remaining }}</small></span></label>
                        <input v-model="line.quantity" type="number" min="1" step="1" class="form-input" :disabled="!line.enabled" aria-label="Quantity diserahkan" />
                        <div class="form-stack" style="gap: 8px">
                            <select v-if="lineOf(line.line_id)?.ownership === 'CONSIGNMENT'" v-model="line.bucket" class="form-input" :disabled="!line.enabled" aria-label="Vendor dan lokasi" required>
                                <option value="">Pilih Vendor · lokasi</option>
                                <option v-for="bucket in lineOf(line.line_id)?.buckets" :key="bucket.vendor_item_id + bucket.location_id" :value="`${bucket.vendor_item_id}|${bucket.location_id}`">{{ bucket.label }}</option>
                            </select>
                            <label><input v-model="line.is_backcharge" type="checkbox" :disabled="!line.enabled" /> Backcharge</label>
                        </div>
                    </div>
                </div>
                <EmptyState v-else icon="box" title="Belum ada barang Ready" hint="Tandai barang sebagai Ready di tabel di atas sebelum diserahkan." />
                <div class="form-actions"><button type="button" class="button button--primary" :disabled="!issue.lines.some((line) => line.enabled)" @click="postIssue">Posting serah terima</button></div>
            </div>
        </section>

        <section v-if="role !== 'vendor' && request.issues.length" class="surface-card">
            <div class="table-card-heading"><div><h2>Riwayat serah terima</h2><p>Nomor WO dan flag Backcharge hanya terlihat oleh pengguna internal.</p></div></div>
            <div class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Nomor</th><th>Penerima</th><th>WO</th><th>Backcharge</th></tr></thead>
                    <tbody>
                        <tr v-for="posted in request.issues" :key="posted.id">
                            <td><strong>{{ posted.issue_no }}</strong></td>
                            <td>{{ posted.receiver_name }}</td>
                            <td>{{ posted.work_order_no || "—" }}</td>
                            <td>{{ posted.lines.filter((line) => line.is_backcharge).length }} baris</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
