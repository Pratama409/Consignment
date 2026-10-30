<script setup>
import { ref } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import AppIcon from "../../Components/AppIcon.vue";
import FormField from "../../Components/FormField.vue";
import PageBanner from "../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ can_manage: Boolean, upload_url: String, batches: Array });
const fileInput = ref(null);
const mode = ref("file");
const localNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const form = useForm({ file: null, cutoff_at: localNow, rows: [{ row_number: 2, item_code: "", location_code: "", vendor_code: "", quantity: "" }] });
const addRow = () => form.rows.push({ row_number: form.rows.length + 2, item_code: "", location_code: "", vendor_code: "", quantity: "" });
const removeRow = (index) => { if (form.rows.length > 1) form.rows.splice(index, 1); };
const submit = () => form.transform((data) => mode.value === "file" ? { file: data.file, cutoff_at: data.cutoff_at } : { cutoff_at: data.cutoff_at, rows: data.rows }).post(props.upload_url, { forceFormData: mode.value === "file" });
</script>

<template>
    <Head title="Saldo Awal" />
    <div class="units-page">
        <PageBanner title="Saldo Awal Persediaan" description="Unggah atau isi manual, tinjau, lalu aktifkan stok awal. Preview tidak mengubah stok." icon="upload" />
        <div class="import-upload-layout">
            <section class="surface-card form-card">
                <header class="form-card__heading"><span class="table-card-heading__icon"><AppIcon name="upload" /></span><div><h2>Preview opening balance</h2><p>Gunakan ITEM_CODE, LOCATION_CODE, VENDOR_CODE, dan QUANTITY.</p></div></header>
                <form v-if="can_manage" class="form-card__body form-stack" @submit.prevent="submit">
                    <div v-if="Object.keys(form.errors).length" class="field-error" role="alert" tabindex="-1"><strong>Preview belum dapat dibuat.</strong><ul><li v-for="(message, field) in form.errors" :key="field">{{ field.startsWith('rows.') ? `Baris ${field.split('.')[1]}: ${message}` : message }}</li></ul></div>
                    <FormField id="opening-cutoff" label="Tanggal dan waktu cut-off (WITA)" :error="form.errors.cutoff_at"><input id="opening-cutoff" v-model="form.cutoff_at" class="form-input" type="datetime-local" required /></FormField>
                    <div class="form-actions"><button type="button" class="button" :class="mode === 'file' ? 'button--primary' : 'button--secondary'" @click="mode = 'file'">Upload XLSX</button><button type="button" class="button" :class="mode === 'manual' ? 'button--primary' : 'button--secondary'" @click="mode = 'manual'">Entri manual</button></div>
                    <template v-if="mode === 'file'"><div class="upload-dropzone" :class="{ 'upload-dropzone--error': form.errors.file }"><input id="opening-file" ref="fileInput" class="sr-only" type="file" accept=".xlsx" @change="form.file = $event.target.files[0]" /><label for="opening-file"><span class="upload-dropzone__icon"><AppIcon name="upload" /></span><strong>{{ form.file?.name || "Pilih file opening balance" }}</strong><span>XLSX maksimal 5 MB</span></label></div></template>
                    <div v-else class="table-scroll"><table class="data-table"><thead><tr><th>ITEM_CODE</th><th>LOCATION_CODE</th><th>VENDOR_CODE</th><th>QUANTITY</th><th>Aksi</th></tr></thead><tbody><tr v-for="(row, index) in form.rows" :key="index"><td><input v-model="row.item_code" :aria-label="`ITEM_CODE baris ${index + 2}`" class="form-input" required /></td><td><input v-model="row.location_code" :aria-label="`LOCATION_CODE baris ${index + 2}`" class="form-input" required /></td><td><input v-model="row.vendor_code" :aria-label="`VENDOR_CODE baris ${index + 2}`" class="form-input" /></td><td><input v-model="row.quantity" :aria-label="`QUANTITY baris ${index + 2}`" class="form-input" inputmode="decimal" required /></td><td><button type="button" class="text-button" :disabled="form.rows.length === 1" @click="removeRow(index)">Hapus</button></td></tr></tbody></table><button type="button" class="text-button" @click="addRow">+ Tambah baris</button></div>
                    <div class="form-actions"><button class="button button--primary" type="submit" :disabled="form.processing || (mode === 'file' && !form.file)">{{ form.processing ? "Memvalidasi…" : "Validasi & Preview" }}</button></div>
                </form>
                <div v-else class="form-card__body"><p class="page-note"><AppIcon name="shield" /> Warehouse dapat membaca preview. Hanya Admin yang dapat mengunggah dan mengaktifkan saldo awal.</p></div>
            </section>
            <aside class="surface-card form-card import-guidance"><h2>Aturan penting</h2><ol><li>Barang STOCK tidak mengisi Vendor.</li><li>Barang CONSIGNMENT wajib mengisi Vendor yang sesuai.</li><li>Satu bucket barang–Vendor–lokasi hanya boleh muncul sekali.</li><li>Preview dapat diganti sebelum aktivasi.</li><li>Stok baru berubah setelah Admin menekan Aktivasi.</li></ol></aside>
        </div>
        <section class="surface-card"><div class="table-card-heading"><div><h2>Riwayat preview</h2><p>Preview dan aktivasi opening balance.</p></div></div><div class="table-scroll" role="region" aria-label="Riwayat saldo awal" tabindex="0"><table class="data-table"><thead><tr><th>File</th><th>Cut-off</th><th>Baris</th><th>Status</th><th>Aksi</th></tr></thead><tbody><tr v-for="batch in batches" :key="batch.id"><td>{{ batch.source }}</td><td>{{ batch.cutoff_at }}</td><td>{{ batch.line_count }}</td><td>{{ batch.status }}</td><td><Link class="row-action" :href="batch.show_url">Lihat <AppIcon name="chevronRight" /></Link></td></tr><tr v-if="!batches.length"><td colspan="5">Belum ada preview saldo awal.</td></tr></tbody></table></div></section>
    </div>
</template>
