<script setup>
import { computed } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import AppIcon from "../../Components/AppIcon.vue";
import FormField from "../../Components/FormField.vue";
import PageBanner from "../../Components/PageBanner.vue";
import EmptyState from "../../Components/ui/EmptyState.vue";
import StatusPill from "../../Components/ui/StatusPill.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ items: Array, history: Array, store_url: String, idempotency_key: String, can_post: Boolean });
const localNow = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const blankLine = () => ({ item_id: "", bucket: "", quantity: 1 });
const form = useForm({ reason: "", vendor_do_number: "", receiver_name: "", received_at: localNow(), idempotency_key: props.idempotency_key, lines: [blankLine()] });
const add = () => form.lines.push(blankLine());
const remove = (index) => form.lines.length > 1 && form.lines.splice(index, 1);
const selected = (line) => props.items.find((item) => item.id === line.item_id);
const submit = () =>
    form
        .transform((data) => ({
            ...data,
            lines: data.lines.map((line) => {
                const [vendor_item_id, location_id] = line.bucket.split("|");
                return { item_id: line.item_id, vendor_item_id: vendor_item_id || null, location_id, quantity: line.quantity };
            }),
        }))
        .post(props.store_url, {
            onSuccess: () => {
                form.idempotency_key = crypto.randomUUID();
                form.lines = [blankLine()];
            },
        });
const hasItems = computed(() => props.items.length > 0);
</script>

<template>
    <Head title="Manual Data IN" />
    <div class="units-page">
        <PageBanner title="Manual Data IN" description="Catat kedatangan urgent tanpa Vendor Refill. Posting ini langsung menambah stok." icon="arrowDown" />
        <div v-if="Object.keys(form.errors).length" class="field-error" role="alert">
            <strong>Data IN belum berhasil.</strong>
            <ul><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul>
        </div>

        <section v-if="can_post" class="surface-card form-card">
            <div class="form-card__heading"><div><h2>Catat kedatangan</h2><p>Gunakan hanya untuk barang urgent yang datang tanpa permintaan refill.</p></div></div>
            <form class="form-card__body form-stack" @submit.prevent="submit">
                <div class="form-grid">
                    <FormField id="din-do" label="Nomor DO (opsional)"><input id="din-do" v-model="form.vendor_do_number" class="form-input" /></FormField>
                    <FormField id="din-at" label="Waktu diterima" required><input id="din-at" v-model="form.received_at" type="datetime-local" class="form-input" required /></FormField>
                </div>
                <FormField id="din-receiver" label="Nama penerima" required><input id="din-receiver" v-model="form.receiver_name" class="form-input" required /></FormField>
                <FormField id="din-reason" label="Alasan urgent" required><textarea id="din-reason" v-model="form.reason" class="form-input" rows="3" required /></FormField>
                <fieldset>
                    <legend>Barang diterima</legend>
                    <div v-for="(line, index) in form.lines" :key="index" class="line-grid">
                        <select v-model="line.item_id" class="form-input" aria-label="Barang" required @change="line.bucket = ''">
                            <option value="">Pilih barang</option>
                            <option v-for="item in items" :key="item.id" :value="item.id">{{ item.code }} · {{ item.name }} ({{ item.ownership }})</option>
                        </select>
                        <select v-model="line.bucket" class="form-input" aria-label="Lokasi" required>
                            <option value="">Pilih {{ selected(line)?.ownership === "CONSIGNMENT" ? "Vendor · lokasi" : "lokasi" }}</option>
                            <option v-for="bucket in selected(line)?.buckets || []" :key="`${bucket.vendor_item_id}|${bucket.location_id}`" :value="`${bucket.vendor_item_id || ''}|${bucket.location_id}`">{{ bucket.label }}</option>
                        </select>
                        <div class="table-actions">
                            <input v-model="line.quantity" type="number" min="1" step="1" class="form-input" aria-label="Quantity" required />
                            <button v-if="form.lines.length > 1" type="button" class="text-button" :aria-label="`Hapus baris ${index + 1}`" @click="remove(index)">Hapus</button>
                        </div>
                    </div>
                    <div style="margin-top: 12px"><button type="button" class="button button--secondary" :disabled="!hasItems" @click="add"><AppIcon name="plus" /> Tambah barang</button></div>
                </fieldset>
                <div class="form-actions"><button class="button button--primary" :disabled="form.processing || !hasItems">Posting Data IN</button></div>
            </form>
        </section>
        <section v-else class="surface-card"><div class="card-body"><p class="panel-note"><AppIcon name="info" /><span>Hanya Warehouse yang dapat memposting. Admin dapat melihat riwayat.</span></p></div></section>

        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Riwayat terbaru</h2><p>20 penerimaan manual terakhir.</p></div></div>
            <div v-if="history.length" class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Nomor</th><th>Waktu</th><th>Penerima</th><th>DO</th><th class="numeric">Baris</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="receipt in history" :key="receipt.number">
                            <td><strong>{{ receipt.number }}</strong></td>
                            <td>{{ receipt.received_at }}</td>
                            <td>{{ receipt.receiver }}<br /><small>{{ receipt.posted_by }}</small></td>
                            <td>{{ receipt.do_number || "—" }}</td>
                            <td class="numeric">{{ receipt.line_count }}</td>
                            <td><StatusPill :status="receipt.status" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="arrowDown" title="Belum ada Manual Data IN" hint="Penerimaan manual yang diposting akan tercatat di sini." />
        </section>
    </div>
</template>
