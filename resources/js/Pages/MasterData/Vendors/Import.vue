<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ batch: Object, urls: Object });
const upload = useForm({ file: null });
const confirm = useForm({});
</script>

<template>
    <Head title="Import Vendor & PIC" />
    <div class="units-page">
        <PageBanner
            title="Import Vendor & PIC"
            description="Preview data Excel sebelum membuat Vendor baru atau memperbarui PIC."
            icon="upload"
        />
        <section v-if="!batch" class="surface-card form-stack">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">LANGKAH 1</p>
                    <h2>Unggah file Excel</h2>
                </div>
                <span class="status-pill">.xlsx maksimal 5 MB</span>
            </div>
            <p class="page-note">
                Gunakan dua kolom dengan header <strong>NAMA VENDOR</strong> dan
                <strong>PIC</strong>. Isi “-” bila PIC belum tersedia.
            </p>
            <label class="inline-field"
                ><span>File Vendor</span
                ><input
                    type="file"
                    accept=".xlsx"
                    @change="upload.file = $event.target.files[0]"
            /></label>
            <p v-if="upload.errors.file" class="field-error" role="alert">
                {{ upload.errors.file }}
            </p>
            <div class="form-actions">
                <Link :href="urls.index" class="button button--secondary"
                    >Kembali</Link
                ><button
                    class="button button--primary"
                    :disabled="!upload.file || upload.processing"
                    @click="upload.post(urls.store, { forceFormData: true })"
                >
                    Preview data
                </button>
            </div>
        </section>
        <section v-else class="surface-card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">LANGKAH 2</p>
                    <h2>Periksa perubahan</h2>
                </div>
                <span class="status-pill"
                    >{{ batch.data.rows.length }} baris</span
                >
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Baris</th>
                            <th>Nama Vendor</th>
                            <th>PIC</th>
                            <th>Aksi</th>
                            <th>Kode</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in batch.data.rows" :key="row.row">
                            <td>{{ row.row }}</td>
                            <td>
                                <strong>{{ row.vendor_name }}</strong>
                            </td>
                            <td>{{ row.pic_name || "Belum diatur" }}</td>
                            <td>
                                <span class="status-pill">{{
                                    row.action
                                }}</span>
                            </td>
                            <td>{{ row.proposed_code }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="confirm.errors.file" class="field-error">
                {{ confirm.errors.file }}
            </p>
            <div class="form-actions">
                <Link :href="urls.index" class="button button--secondary"
                    >Batal</Link
                ><button
                    class="button button--primary"
                    :disabled="
                        batch.status !== 'vendor_preview' || confirm.processing
                    "
                    @click="
                        confirm.post(
                            `/master-data/vendor/import/${batch.id}/confirm`,
                        )
                    "
                >
                    Konfirmasi import
                </button>
            </div>
        </section>
    </div>
</template>
