<script setup>
import { reactive } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";
import Pagination from "../../Components/Pagination.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ requirements: Object, role: String, urls: Object });
const upload = useForm({ stage: "PR", file: null });
const forms = reactive({});
const validate = (row, decision) =>
    router.post(
        `/procurement/${row.id}/validate`,
        { decision, note: forms[row.id]?.note || "" },
        { preserveScroll: true },
    );
const documentForm = (row) =>
    (forms[row.id] ??= {
        note: "",
        type: "MR",
        vendor_visible: false,
        file: null,
    });
const storeDocument = (row) =>
    router.post(`/procurement/${row.id}/documents`, documentForm(row), {
        forceFormData: true,
        preserveScroll: true,
    });
</script>
<template>
    <Head title="Pengadaan" />
    <div class="units-page">
        <PageBanner
            title="Pengadaan & Dokumen SAP"
            description="Reservasi, PR, validasi, PO, GRGI, dan dokumen setelah Data Out Consignment."
            icon="layers"
        />
        <section v-if="role !== 'vendor'" class="surface-card form-stack">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">UPDATE DOKUMEN</p>
                    <h2>Import referensi pengadaan</h2>
                </div>
                <span class="status-pill">Excel .xlsx</span>
            </div>
            <p class="page-note">
                Unduh template, isi nomor dan quantity berdasarkan ROW_KEY, lalu
                import sesuai tahap.
            </p>
            <div class="operation-toolbar">
                <a :href="urls.export" class="button button--secondary"
                    >Unduh template</a
                ><label class="inline-field"
                    ><span>Tahap proses</span
                    ><select v-model="upload.stage" class="form-input">
                        <option
                            v-for="stage in ['RESERVATION', 'PR', 'PO', 'GRGI']"
                            :key="stage"
                        >
                            {{ stage }}
                        </option>
                    </select></label
                ><label class="inline-field"
                    ><span>File referensi</span
                    ><input
                        type="file"
                        accept=".xlsx"
                        @change="upload.file = $event.target.files[0]" /></label
                ><button
                    class="button button--primary"
                    :disabled="!upload.file || upload.processing"
                    @click="upload.post(urls.import, { forceFormData: true })"
                >
                    Import
                </button>
            </div>
            <div
                v-if="Object.keys(upload.errors).length"
                class="field-error"
                role="alert"
            >
                <p v-for="(error, key) in upload.errors" :key="key">
                    {{ error }}
                </p>
            </div>
        </section>
        <section class="surface-card">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">ANTRIAN PENGADAAN</p>
                    <h2>Kebutuhan Data OUT Consignment</h2>
                </div>
                <span class="status-pill"
                    >{{
                        requirements.total ?? requirements.data.length
                    }}
                    baris</span
                >
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Vendor / Barang</th>
                            <th>Qty</th>
                            <th>Status</th>
                            <th>Referensi aktif</th>
                            <th>Dokumen</th>
                            <th v-if="role !== 'vendor'">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in requirements.data" :key="row.id">
                            <td>
                                <strong>{{ row.vendor }}</strong
                                ><br />{{ row.item }}
                            </td>
                            <td class="numeric-cell">
                                <strong>{{
                                    Number(row.quantity).toLocaleString("id-ID")
                                }}</strong>
                            </td>
                            <td>
                                <span class="status-pill">{{
                                    row.status
                                }}</span>
                            </td>
                            <td>
                                <div
                                    v-for="reference in row.references"
                                    :key="reference.id"
                                >
                                    {{ reference.stage }} ·
                                    {{ reference.document_no }} ·
                                    {{ Number(reference.covered_quantity).toLocaleString('id-ID') }}
                                </div>
                                <span v-if="!row.references.length">—</span>
                            </td>
                            <td>
                                <div
                                    v-for="document in row.documents"
                                    :key="document.id"
                                >
                                    <a :href="document.download_url"
                                        >{{ document.type }} v{{
                                            document.version
                                        }}
                                        · {{ document.name }}</a
                                    >
                                </div>
                                <template v-if="role !== 'vendor'"
                                    ><input
                                        type="file"
                                        @change="
                                            documentForm(row).file =
                                                $event.target.files[0]
                                        "
                                    /><input
                                        v-model="documentForm(row).type"
                                        class="form-input"
                                        placeholder="Jenis dokumen"
                                    /><label
                                        ><input
                                            v-model="
                                                documentForm(row).vendor_visible
                                            "
                                            type="checkbox"
                                        />
                                        Bagikan ke Vendor</label
                                    ><button
                                        class="text-button"
                                        @click="storeDocument(row)"
                                    >
                                        Upload
                                    </button></template
                                >
                            </td>
                            <td v-if="role !== 'vendor'">
                                <template v-if="role === 'admin'">
                                    <textarea
                                        v-model="documentForm(row).note"
                                        class="form-input"
                                        placeholder="Catatan validasi"
                                    /><button
                                        class="text-button"
                                        @click="validate(row, 'VALID')"
                                    >
                                        Valid</button
                                    ><button
                                        class="text-button"
                                        @click="validate(row, 'REVISE')"
                                    >
                                        Revisi</button
                                    ><button
                                        class="text-button"
                                        @click="validate(row, 'REJECT')"
                                    >
                                        Reject
                                    </button></template
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!requirements.data.length" class="page-note">
                Belum ada Data Out Consignment yang memerlukan dokumen.
            </p>
            <Pagination :paginator="requirements" noun="kebutuhan pengadaan" />
        </section>
    </div>
</template>
