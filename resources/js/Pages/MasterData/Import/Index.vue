<script setup>
import { ref } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import FormField from "../../../Components/FormField.vue";
import ImportSteps from "../../../Components/ImportSteps.vue";
import ImportStateBadge from "../../../Components/ImportStateBadge.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({
    recent: Array,
    upload_url: String,
    template_url: String,
});
const fileInput = ref(null);
const form = useForm({ file: null, create_missing: false, minimum_stock: "0" });
function choose(file) {
    form.clearErrors("file");
    form.file = file ?? null;
    if (file?.size > 5 * 1024 * 1024)
        form.setError("file", "Ukuran file maksimal 5 MB.");
}
function submit() {
    if (!form.processing)
        form.post(props.upload_url, {
            forceFormData: true,
            onError: () =>
                document.querySelector('[aria-invalid="true"]')?.focus(),
        });
}
</script>
<template>
    <Head title="Import Master PN" />
    <div class="units-page">
        <PageBanner
            title="Import Master PN"
            description="Mulai dari Excel, tinjau setiap keputusan, lalu simpan data yang sudah siap."
            icon="upload"
        /><ImportSteps :step="1" />
        <div class="import-upload-layout">
            <section class="surface-card form-card">
                <header class="form-card__heading">
                    <span class="table-card-heading__icon"
                        ><AppIcon name="upload"
                    /></span>
                    <div>
                        <h2>Unggah Master PN</h2>
                        <p>File yang dipilih belum mengubah Master Data.</p>
                    </div>
                </header>
                <form
                    class="form-card__body form-stack"
                    novalidate
                    @submit.prevent="submit"
                >
                    <div
                        class="upload-dropzone"
                        :class="{ 'upload-dropzone--error': form.errors.file }"
                        @dragover.prevent
                        @drop.prevent="choose($event.dataTransfer.files[0])"
                    >
                        <input
                            id="import-file"
                            ref="fileInput"
                            type="file"
                            accept=".xlsx"
                            class="sr-only"
                            :aria-invalid="Boolean(form.errors.file)"
                            :aria-describedby="
                                form.errors.file
                                    ? 'import-file-error'
                                    : 'import-file-hint'
                            "
                            @change="choose($event.target.files[0])"
                        /><label for="import-file"
                            ><span class="upload-dropzone__icon"
                                ><AppIcon name="upload" /></span
                            ><strong>{{
                                form.file?.name ||
                                "Pilih file atau letakkan di sini"
                            }}</strong
                            ><span id="import-file-hint"
                                >Excel XLSX · maksimal 5 MB · 5.000 baris
                                data</span
                            ></label
                        ><button
                            v-if="form.file"
                            type="button"
                            class="text-button"
                            :disabled="form.processing"
                            @click="
                                choose(null);
                                fileInput.value = '';
                            "
                        >
                            Ganti file
                        </button>
                    </div>
                    <p
                        v-if="form.errors.file"
                        id="import-file-error"
                        class="field-error"
                        role="alert"
                    >
                        {{ form.errors.file }}
                    </p>
                    <FormField
                        id="import-minimum"
                        label="Target refill untuk barang baru"
                        :error="form.errors.minimum_stock"
                        hint="Dipakai jika kolom MINIMUM_STOCK tidak diisi. Target barang lama tetap dipertahankan bila tidak dicantumkan."
                        ><input
                            id="import-minimum"
                            v-model="form.minimum_stock"
                            class="form-input"
                            type="number"
                            min="0"
                            step="0.0001"
                            :aria-invalid="Boolean(form.errors.minimum_stock)"
                            :aria-describedby="
                                form.errors.minimum_stock
                                    ? 'import-minimum-error'
                                    : 'import-minimum-hint'
                            " /></FormField
                    ><label class="checkbox-setting"
                        ><input
                            v-model="form.create_missing"
                            type="checkbox"
                        /><span
                            ><strong
                                >Buat Vendor dan lokasi yang belum
                                terdaftar</strong
                            ><small
                                >Daftar master baru ditinjau pada preview dan
                                baru dibuat setelah konfirmasi. Satuan tetap
                                harus dipilih dengan benar.</small
                            ></span
                        ></label
                    >
                    <div
                        v-if="form.progress"
                        class="upload-progress"
                        role="status"
                    >
                        <progress :value="form.progress.percentage" max="100" />
                        Mengunggah {{ form.progress.percentage }}%
                    </div>
                    <div class="form-actions">
                        <a :href="template_url" class="button button--secondary"
                            ><AppIcon name="arrowDown" /> Unduh Template</a
                        ><button
                            type="submit"
                            class="button button--primary"
                            :disabled="
                                form.processing ||
                                !form.file ||
                                Boolean(form.errors.file)
                            "
                        >
                            <span
                                v-if="form.processing"
                                class="spinner"
                                aria-hidden="true"
                            />{{
                                form.processing
                                    ? "Memvalidasi…"
                                    : "Validasi & Preview"
                            }}<AppIcon
                                v-if="!form.processing"
                                name="arrowRight"
                            />
                        </button>
                    </div>
                </form>
            </section>
            <aside class="surface-card form-card import-guidance">
                <h2>Sebelum memulai</h2>
                <ol>
                    <li>
                        Gunakan header dari template atau Master PN enam kolom.
                    </li>
                    <li>
                        Untuk memperbarui barang, gunakan
                        <strong>ITEM_CODE</strong> dari hasil export.
                    </li>
                    <li>
                        PN yang sama dapat mewakili barang berbeda. Tentukan
                        penggabungan atau pemetaannya saat preview.
                    </li>
                    <li>
                        Perbaiki lokasi gabungan, angka eksponensial, dan satuan
                        yang belum dikenali.
                    </li>
                    <li>
                        Kategori opsional menggunakan
                        <strong>CATEGORY_CODE</strong> dari Master Kategori.
                        Kategori tidak dibuat atau ditebak otomatis.
                    </li>
                </ol>
                <p class="page-note">
                    <AppIcon name="shield" /><span
                        >Hanya admin yang dapat mengonfirmasi import. Preview
                        dapat ditinjau kembali selama 24 jam.</span
                    >
                </p>
            </aside>
        </div>
        <section v-if="recent.length" class="surface-card">
            <div class="table-card-heading">
                <div>
                    <h2>Import Anda sebelumnya</h2>
                    <p>
                        Lanjutkan preview atau baca hasil import yang selesai.
                    </p>
                </div>
            </div>
            <div
                class="table-scroll"
                tabindex="0"
                role="region"
                aria-label="Riwayat import Anda"
            >
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">File</th>
                            <th scope="col">Baris sumber</th>
                            <th scope="col">Status</th>
                            <th scope="col">Diunggah (WITA)</th>
                            <th scope="col">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in recent" :key="record.id">
                            <td class="file-name">{{ record.file_name }}</td>
                            <td>{{ record.source_rows }}</td>
                            <td><ImportStateBadge :state="record.status" /></td>
                            <td class="no-wrap">{{ record.created_at }}</td>
                            <td>
                                <Link :href="record.show_url" class="row-action"
                                    >{{
                                        record.status === "completed"
                                            ? "Lihat hasil"
                                            : "Lanjutkan"
                                    }}<AppIcon name="chevronRight"
                                /></Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
