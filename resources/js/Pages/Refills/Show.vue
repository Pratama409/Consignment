<script setup>
import { computed } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import AppIcon from "../../Components/AppIcon.vue";
import FormField from "../../Components/FormField.vue";
import PageBanner from "../../Components/PageBanner.vue";
import StatusPill from "../../Components/ui/StatusPill.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ refill: Object, role: String });
const localNow = () => {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const revision = useForm({ line_id: "", quantity: "", reason: "" });
const shipment = useForm({
    document_no: "",
    expedition: "",
    shipped_at: localNow(),
    lines: props.refill.lines.filter((line) => Number(line.outstanding) > 0).map((line) => ({ enabled: true, refill_line_id: line.id, quantity: line.outstanding })),
});
const closing = useForm({ reason: "" });
const followUp = useForm({ note: "" });
const terminal = computed(() => ["COMPLETED", "CLOSED_PARTIAL", "CANCELLED"].includes(props.refill.status));
const totals = computed(() => props.refill.lines.reduce((sum, line) => ({ requested: sum.requested + line.quantity, shipped: sum.shipped + line.shipped, received: sum.received + line.received, outstanding: sum.outstanding + line.outstanding }), { requested: 0, shipped: 0, received: 0, outstanding: 0 }));
const anyErrors = computed(() => Object.keys(revision.errors).length || Object.keys(shipment.errors).length || Object.keys(closing.errors).length);
const lineName = (id) => props.refill.lines.find((line) => line.id === id)?.item;
const submitShipment = () => shipment.transform((data) => ({ ...data, lines: data.lines.filter((line) => line.enabled).map(({ refill_line_id, quantity }) => ({ refill_line_id, quantity })) })).post(props.refill.shipment_url);
const review = (item, approve) => useForm({ approve, note: approve ? "Disetujui" : "Ditolak" }).post(item.review_url);
const requestFollowUp = () => followUp.post(props.refill.follow_up.request_url, { preserveScroll: true, onSuccess: () => followUp.reset() });
const acknowledge = () => useForm({}).post(props.refill.follow_up.acknowledge_url, { preserveScroll: true });
</script>

<template>
    <Head :title="refill.number" />
    <div class="units-page">
        <PageBanner :title="refill.number" :description="`${refill.vendor}`" icon="truck">
            <template #chips><span class="chip">Status: {{ refill.status }}</span></template>
        </PageBanner>
        <div v-if="anyErrors" class="field-error" role="alert">Tindakan belum berhasil. Periksa status, quantity, dan data wajib.</div>

        <section class="summary-strip" aria-label="Ringkasan refill">
            <div class="summary-strip__item"><span class="summary-strip__label">Status</span><StatusPill :status="refill.status" /></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Diminta</span><span class="summary-strip__value">{{ totals.requested }}</span></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Dikirim</span><span class="summary-strip__value">{{ totals.shipped }}</span></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Diterima</span><span class="summary-strip__value">{{ totals.received }}</span></div>
            <div class="summary-strip__item"><span class="summary-strip__label">Outstanding</span><span class="summary-strip__value">{{ totals.outstanding }}</span></div>
        </section>

        <section v-if="refill.follow_up.open || refill.follow_up.can_request" class="surface-card form-card">
            <div class="form-card__body form-stack">
                <h2>Tindak lanjut</h2>
                <p v-if="refill.follow_up.open" class="panel-note"><AppIcon name="alert" /><span><strong>Perlu ditindaklanjuti.</strong> {{ refill.follow_up.note || "Sisa quantity masih harus dikirim." }}</span></p>
                <div v-if="refill.follow_up.can_acknowledge"><button type="button" class="button button--secondary" @click="acknowledge">Tandai sudah dibaca</button></div>
                <form v-if="refill.follow_up.can_request" class="form-stack" @submit.prevent="requestFollowUp">
                    <FormField id="follow-up-note" label="Catatan untuk Vendor (opsional)"><textarea id="follow-up-note" v-model="followUp.note" class="form-input" rows="2" /></FormField>
                    <div><button class="button button--primary" :disabled="followUp.processing">Follow Up Vendor</button></div>
                </form>
            </div>
        </section>

        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Barang refill</h2><p>Diminta, dikirim, diterima, dan sisa per barang.</p></div></div>
            <div class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Barang</th><th class="numeric">Diminta</th><th class="numeric">Dikirim</th><th class="numeric">Diterima</th><th class="numeric">Outstanding</th><th>Revisi</th></tr></thead>
                    <tbody>
                        <tr v-for="line in refill.lines" :key="line.id">
                            <td><strong>{{ line.item }}</strong></td>
                            <td class="numeric">{{ line.quantity }}</td>
                            <td class="numeric">{{ line.shipped }}</td>
                            <td class="numeric">{{ line.received }}</td>
                            <td class="numeric">{{ line.outstanding }}</td>
                            <td>
                                <div v-for="item in line.revisions" :key="item.id" class="table-actions">
                                    <span>{{ item.quantity }}</span><StatusPill :status="item.status" /><span>{{ item.reason }}</span>
                                    <template v-if="role === 'admin' && item.status === 'PENDING' && !terminal">
                                        <button type="button" class="text-button" @click="review(item, true)">Setujui</button>
                                        <button type="button" class="text-button" @click="review(item, false)">Tolak</button>
                                    </template>
                                </div>
                                <span v-if="!line.revisions.length">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Riwayat proses</h2><p>Pengiriman Vendor dan penerimaan Warehouse.</p></div></div>
            <div class="card-body"><p>Pengiriman: <strong>{{ refill.shipments.length }}</strong> · Penerimaan: <strong>{{ refill.receipts.length }}</strong></p></div>
        </section>

        <div v-if="role === 'vendor' && !terminal" class="dash__grid-even">
            <section class="surface-card form-card">
                <form class="form-card__body form-stack" @submit.prevent="revision.post(refill.revision_url)">
                    <h2>Usulkan revisi</h2>
                    <p>Ajukan quantity yang mampu dikirim bila stok Anda kurang.</p>
                    <FormField id="revision-line" label="Barang"><select id="revision-line" v-model="revision.line_id" class="form-input" required><option value="">Pilih barang</option><option v-for="line in refill.lines" :key="line.id" :value="line.id">{{ line.item }}</option></select></FormField>
                    <FormField id="revision-quantity" label="Quantity mampu dikirim"><input id="revision-quantity" v-model="revision.quantity" class="form-input" type="number" min="1" step="1" required /></FormField>
                    <FormField id="revision-reason" label="Alasan"><textarea id="revision-reason" v-model="revision.reason" class="form-input" rows="3" required /></FormField>
                    <div><button class="button button--secondary">Kirim revisi</button></div>
                </form>
            </section>
            <section class="surface-card form-card">
                <form class="form-card__body form-stack" @submit.prevent="submitShipment">
                    <h2>Tandai dikirim</h2>
                    <FormField id="shipment-doc" label="Nomor DO / surat jalan"><input id="shipment-doc" v-model="shipment.document_no" class="form-input" required /></FormField>
                    <FormField id="shipment-expedition" label="Ekspedisi"><input id="shipment-expedition" v-model="shipment.expedition" class="form-input" /></FormField>
                    <FormField id="shipment-at" label="Waktu kirim"><input id="shipment-at" v-model="shipment.shipped_at" class="form-input" type="datetime-local" required /></FormField>
                    <div>
                        <div v-for="row in shipment.lines" :key="row.refill_line_id" class="line-grid line-grid--refill" :class="{ 'is-off': !row.enabled }">
                            <label><input v-model="row.enabled" type="checkbox" /><span class="line-grid__title">{{ lineName(row.refill_line_id) }}</span></label>
                            <input v-model="row.quantity" class="form-input" type="number" min="1" step="1" :disabled="!row.enabled" aria-label="Quantity dikirim" required />
                        </div>
                    </div>
                    <div><button class="button button--primary" :disabled="!shipment.lines.some((row) => row.enabled)">Simpan pengiriman</button></div>
                </form>
            </section>
        </div>

        <section v-if="role === 'admin' && !terminal" class="surface-card form-card">
            <form class="form-card__body form-stack" @submit.prevent="closing.post(refill.close_url)">
                <h2>Tutup sisa refill</h2>
                <p>Gunakan bila sisa quantity tidak akan dikirim. Alasan wajib dan tercatat di audit.</p>
                <FormField id="close-reason" label="Alasan"><textarea id="close-reason" v-model="closing.reason" class="form-input" rows="2" required /></FormField>
                <div><button class="button button--danger">Tutup Sisa</button></div>
            </form>
        </section>
    </div>
</template>
