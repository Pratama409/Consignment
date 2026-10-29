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
const form = useForm({ file: null, cutoff_at: new Date().toISOString().slice(0, 10) });
const submit = () => form.post(props.upload_url, { forceFormData: true });
</script>

<template>
    <Head title="Saldo Awal" />
    <div class="units-page">
        <PageBanner title="Saldo Awal Persediaan" description="Unggah, tinjau, lalu aktifkan stok awal. Preview tidak mengubah stok." icon="upload" />
        <div class="import-upload-layout">
            <section class="surface-card form-card">
                <header class="form-card__heading"><span class="table-card-heading__icon"><AppIcon name="upload" /></span><div><h2>Import opening balance</h2><p>Gunakan ITEM_CODE, LOCATION_CODE, VENDOR_CODE, dan QUANTITY.</p></div></header>
                <form v-if="can_manage" class="form-card__body form-stack" @submit.prevent="submit">
                    <FormField id="opening-cutoff" label="Tanggal cut-off (WITA)" :error="form.errors.cutoff_at"><input id="opening-cutoff" v-model="form.cutoff_at" class="form-input" type="date" required /></FormField>
                    <div class="upload-dropzone" :class="{ 'upload-dropzone--error': form.errors.file }">
                        <input id="opening-file" ref="fileInput" class="sr-only" type="file" accept=".xlsx" @change="form.file = $event.target.files[0]" />
                        <label for="opening-file"><span class="upload-dropzone__icon"><AppIcon name="upload" /></span><strong>{{ form.file?.name || "Pilih file opening balance" }}</strong><span>XLSX maksimal 5 MB</span></label>
                    </div>
                    <p v-if="form.errors.file" class="field-error" role="alert">{{ form.errors.file }}</p>
                    <div class="form-actions"><button class="button button--primary" type="submit" :disabled="form.processing || !form.file">{{ form.processing ? "Memvalidasi…" : "Validasi & Preview" }}</button></div>
                </form>
                <div v-else class="form-card__body"><p class="page-note"><AppIcon name="shield" /> Warehouse dapat membaca preview. Hanya Admin yang dapat mengunggah dan mengaktifkan saldo awal.</p></div>
            </section>
            <aside class="surface-card form-card import-guidance"><h2>Aturan penting</h2><ol><li>Barang STOCK tidak mengisi Vendor.</li><li>Barang CONSIGNMENT wajib mengisi Vendor yang sesuai.</li><li>Satu bucket barang–Vendor–lokasi hanya boleh muncul sekali.</li><li>Stok baru berubah setelah Admin menekan Aktivasi.</li></ol></aside>
        </div>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Riwayat preview</h2><p>Preview dan aktivasi opening balance.</p></div></div>
            <div class="table-scroll" role="region" aria-label="Riwayat saldo awal" tabindex="0"><table class="data-table"><thead><tr><th>File</th><th>Cut-off</th><th>Baris</th><th>Status</th><th>Aksi</th></tr></thead><tbody><tr v-for="batch in batches" :key="batch.id"><td>{{ batch.source }}</td><td>{{ batch.cutoff_at }}</td><td>{{ batch.line_count }}</td><td>{{ batch.status }}</td><td><Link class="row-action" :href="batch.show_url">Lihat <AppIcon name="chevronRight" /></Link></td></tr><tr v-if="!batches.length"><td colspan="5">Belum ada preview saldo awal.</td></tr></tbody></table></div>
        </section>
    </div>
</template>
