<script setup>
import { reactive } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";
import EmptyState from "../../Components/ui/EmptyState.vue";
import StatusPill from "../../Components/ui/StatusPill.vue";
defineOptions({ layout: AppLayout });
defineProps({ shipments: Array, locations: Array, can_receive: Boolean });
const page = usePage();
const localNow = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const forms = reactive({});
const form = (shipment) =>
    (forms[shipment.id] ??= {
        idempotency_key: crypto.randomUUID(),
        vendor_do_number: shipment.document_no,
        received_at: localNow(),
        lines: shipment.lines.map((line) => ({ enabled: true, refill_line_id: line.refill_line_id, location_id: "", quantity: line.quantity })),
    });
const lineOf = (shipment, row) => shipment.lines.find((line) => line.refill_line_id === row.refill_line_id);
const submit = (shipment) => {
    const data = form(shipment);
    router.post(shipment.receipt_url, { ...data, lines: data.lines.filter((row) => row.enabled).map(({ refill_line_id, location_id, quantity }) => ({ refill_line_id, location_id, quantity })) });
};
</script>

<template>
    <Head title="Incoming Refill" />
    <div class="units-page">
        <PageBanner title="Incoming Refill" description="Masukkan quantity fisik dalam satuan bulat; hanya posting ini yang menambah stok Consignment." icon="arrowDown" />
        <div v-if="Object.keys(page.props.errors).length" class="field-error" role="alert">
            <strong>Penerimaan belum berhasil.</strong>
            <ul><li v-for="(message, key) in page.props.errors" :key="key">{{ message }}</li></ul>
        </div>
        <section v-for="shipment in shipments" :key="shipment.id" class="surface-card form-card">
            <div class="form-card__heading">
                <div>
                    <h2>{{ shipment.refill }} · {{ shipment.vendor }}</h2>
                    <p>Surat jalan {{ shipment.document_no }}</p>
                </div>
                <StatusPill :status="shipment.status" />
            </div>
            <form v-if="can_receive" class="form-card__body form-stack" @submit.prevent="submit(shipment)">
                <div class="form-grid">
                    <label>Nomor DO Vendor<input v-model="form(shipment).vendor_do_number" class="form-input" required /></label>
                    <label>Waktu diterima<input v-model="form(shipment).received_at" class="form-input" type="datetime-local" required /></label>
                </div>
                <div>
                    <h2>Barang diterima</h2>
                    <p>Ubah quantity bila yang datang tidak sesuai; sisanya tetap terbuka sebagai parsial.</p>
                    <div class="section-stack" style="margin-top: 12px">
                        <div v-for="row in form(shipment).lines" :key="row.refill_line_id" class="line-grid line-grid--receipt" :class="{ 'is-off': !row.enabled }">
                            <label><input v-model="row.enabled" type="checkbox" /><span class="line-grid__title">{{ lineOf(shipment, row)?.item }}</span></label>
                            <input v-model="row.quantity" class="form-input" type="number" min="1" step="1" :disabled="!row.enabled" aria-label="Quantity diterima" required />
                            <select v-model="row.location_id" class="form-input" :disabled="!row.enabled" aria-label="Lokasi" required>
                                <option value="">Pilih lokasi</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.location_code }} · {{ location.location_name }}</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-actions"><button class="button button--primary" :disabled="!form(shipment).lines.some((row) => row.enabled)">Posting penerimaan</button></div>
            </form>
            <div v-else class="form-card__body"><p>Hanya Warehouse yang dapat memposting penerimaan.</p></div>
        </section>
        <section v-if="!shipments.length" class="surface-card">
            <EmptyState icon="truck" title="Tidak ada kiriman yang menunggu" hint="Kiriman Vendor yang sudah berangkat akan muncul di sini untuk diterima Warehouse." />
        </section>
    </div>
</template>
