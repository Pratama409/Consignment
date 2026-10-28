<script setup>
import { computed, ref } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import SummaryCard from "../../../Components/SummaryCard.vue";
import BaseDialog from "../../../Components/BaseDialog.vue";
import FormField from "../../../Components/FormField.vue";
import ImportSteps from "../../../Components/ImportSteps.vue";
import ImportStateBadge from "../../../Components/ImportStateBadge.vue";
import OwnershipBadge from "../../../Components/OwnershipBadge.vue";
import Pagination from "../../../Components/Pagination.vue";
import {
    useListFilters,
    withCurrentQuery,
} from "../../../Composables/useListFilters";
import { formatQuantity } from "../../../Support/references";
defineOptions({ layout: AppLayout });
const props = defineProps({
    batch: Object,
    groups: Object,
    filters: Object,
    summary: Object,
    references: Object,
    options: Object,
    source_units: Array,
    units: Array,
    options_url: String,
    confirm_url: String,
});
const page = usePage();
const { search, filtering, apply, schedule } = useListFilters(
    props,
    () => props.batch.show_url,
);
const optionForm = useForm({
    create_missing: props.options.create_missing,
    minimum_stock: props.options.minimum_stock,
    unit_map: Object.fromEntries(
        props.source_units.map((code) => [
            code,
            props.options.unit_map?.[code] ?? "",
        ]),
    ),
});
const groupTarget = ref(null);
const rowTarget = ref(null);
const confirmOpen = ref(false);
const decisionForm = useForm({
    action: "review",
    target_code: "",
    minimum_stock: "",
});
const rowForm = useForm({
    skip: false,
    ITEM_CODE: "",
    VENDOR: "",
    PART_NUMBER: "",
    DESCRIPTION: "",
    LOCATION: "",
    UOM: "",
    MATERIAL_TYPE: "STOCK",
    MINIMUM_STOCK: "",
    IS_ACTIVE: "",
    TRACKING_TYPE: "",
    RELATION_ACTIVE: "",
});
const confirmForm = useForm({ acknowledge_skipped: false });
const canConfirm = computed(
    () => props.summary.blocking_groups === 0 && props.summary.ready_groups > 0,
);
const sourceCodeLocked = computed(() =>
    groupTarget.value?.rows.some((row) => row.item_code),
);
function reviewGroup(group) {
    groupTarget.value = group;
    decisionForm.clearErrors();
    decisionForm.action = group.action;
    decisionForm.target_code = group.target_code || "";
    decisionForm.minimum_stock = group.data.minimum_stock;
}
function saveDecision() {
    if (decisionForm.processing) return;
    decisionForm.transform((data) => ({
        ...data,
        minimum_stock:
            data.action === "skip" ||
            data.minimum_stock === "" ||
            data.minimum_stock == null
                ? null
                : data.minimum_stock,
    }));
    decisionForm.put(withCurrentQuery(groupTarget.value.decision_url), {
        preserveScroll: true,
        onSuccess: () => {
            groupTarget.value = null;
        },
    });
}
function reviewRow(row) {
    rowTarget.value = row;
    rowForm.clearErrors();
    Object.keys(rowForm.data()).forEach((key) => {
        rowForm[key] = key === "skip" ? row.skip : (row.values[key] ?? "");
    });
}
function saveRow() {
    if (!rowForm.processing)
        rowForm.put(withCurrentQuery(rowTarget.value.correction_url), {
            preserveScroll: true,
            onSuccess: () => {
                rowTarget.value = null;
                groupTarget.value = null;
            },
        });
}
function confirm() {
    if (!confirmForm.processing)
        confirmForm.post(props.confirm_url, { preserveScroll: true });
}
function expiry() {
    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: "Asia/Makassar",
    }).format(new Date(props.batch.expires_at));
}
</script>
<template>
    <Head title="Preview Master PN" />
    <div class="units-page">
        <PageBanner
            title="Preview Master PN"
            description="Pastikan identitas barang, referensi, dan baris yang akan disimpan sudah sesuai."
            icon="upload"
        /><ImportSteps :step="confirmOpen ? 3 : 2" />
        <div class="page-actions">
            <Link :href="page.props.routes.importsIndex" class="back-link"
                ><AppIcon name="chevronLeft" /> Unggah file lain</Link
            ><span class="file-caption"
                >{{ batch.file_name }} · {{ summary.source_rows }} baris
                sumber</span
            >
        </div>
        <section class="summary-grid" aria-label="Ringkasan preview import">
            <SummaryCard
                label="Kelompok Siap"
                :value="summary.ready_groups"
                description="Sudah memiliki penanganan yang valid"
                icon="checkCircle"
                tone="green"
            /><SummaryCard
                label="Perlu Diselesaikan"
                :value="summary.blocking_groups"
                description="Tinjau, perbaiki, atau lewati"
                icon="info"
            /><SummaryCard
                label="Baris Dilewati"
                :value="summary.skipped_rows"
                description="Termasuk duplikat persis"
                icon="pause"
                tone="slate"
            />
        </section>
        <details
            class="surface-card import-options"
            :open="summary.blocking_groups > 0"
        >
            <summary>
                <AppIcon name="layers" /><strong
                    >Pengaturan referensi dan satuan</strong
                ><AppIcon name="chevronDown" />
            </summary>
            <form
                class="form-card__body form-stack"
                @submit.prevent="
                    optionForm.put(options_url, { preserveScroll: true })
                "
            >
                <div class="form-grid">
                    <FormField
                        id="preview-minimum"
                        label="Target refill default untuk barang baru"
                        :error="optionForm.errors.minimum_stock"
                        ><input
                            id="preview-minimum"
                            v-model="optionForm.minimum_stock"
                            class="form-input"
                            type="number"
                            min="0"
                            step="0.0001"
                            :aria-invalid="
                                Boolean(optionForm.errors.minimum_stock)
                            " /></FormField
                    ><label class="checkbox-setting"
                        ><input
                            v-model="optionForm.create_missing"
                            type="checkbox"
                        /><span
                            ><strong
                                >Buat master Vendor dan lokasi baru saat
                                konfirmasi</strong
                            ><small
                                >Preview tidak langsung menambah atau mengubah
                                master.</small
                            ></span
                        ></label
                    >
                </div>
                <div class="unit-mapping-grid">
                    <FormField
                        v-for="code in source_units"
                        :id="`map-${code}`"
                        :key="code"
                        :label="`Satuan sumber: ${code || '(kosong)'}`"
                        :error="optionForm.errors[`unit_map.${code}`]"
                        ><select
                            :id="`map-${code}`"
                            v-model="optionForm.unit_map[code]"
                            class="form-input"
                        >
                            <option value="">Cocokkan kode yang sama</option>
                            <option
                                v-for="unit in units"
                                :key="unit.id"
                                :value="unit.id"
                            >
                                {{ unit.code }} — {{ unit.name }}
                            </option>
                        </select></FormField
                    >
                </div>
                <div class="form-actions">
                    <Link
                        :href="page.props.routes.unitsIndex"
                        target="_blank"
                        rel="noopener"
                        class="text-button"
                        >Kelola Master Satuan</Link
                    ><button
                        type="submit"
                        class="button button--secondary"
                        :disabled="optionForm.processing"
                    >
                        {{
                            optionForm.processing
                                ? "Memperbarui…"
                                : "Perbarui Preview"
                        }}
                    </button>
                </div>
                <p class="field-hint">
                    Pastikan arti satuan benar sebelum melakukan pemetaan. Kode
                    yang berbeda tidak otomatis dianggap satuan yang sama.
                </p>
            </form>
        </details>
        <section class="surface-card">
            <div class="table-card-heading">
                <div>
                    <h2>Tinjauan kelompok barang</h2>
                    <p>
                        Baris dengan identitas serupa ditampilkan bersama untuk
                        ditinjau, bukan digabung otomatis.
                    </p>
                </div>
            </div>
            <div class="table-toolbar">
                <div class="search-input">
                    <AppIcon name="search" /><label
                        for="preview-search"
                        class="sr-only"
                        >Cari PN, nama, atau nomor baris</label
                    ><input
                        id="preview-search"
                        v-model="search.q"
                        type="search"
                        placeholder="Cari PN, nama, atau nomor baris…"
                        @input="schedule"
                        @keydown.enter.prevent="apply"
                    /><span v-if="filtering" class="spinner" />
                </div>
                <label class="sr-only" for="preview-state"
                    >Filter hasil validasi</label
                ><select
                    id="preview-state"
                    v-model="search.state"
                    class="form-select"
                    @change="apply"
                >
                    <option value="all">Semua hasil</option>
                    <option value="valid">Siap diimport</option>
                    <option value="review">Perlu ditinjau</option>
                    <option value="invalid">Belum valid</option>
                    <option value="skipped">Dilewati</option>
                </select>
            </div>
            <div
                class="table-scroll"
                tabindex="0"
                role="region"
                aria-label="Tinjauan kelompok import"
            >
                <table class="data-table preview-table">
                    <thead>
                        <tr>
                            <th scope="col">Baris Excel</th>
                            <th scope="col">Barang</th>
                            <th scope="col">Jenis / satuan</th>
                            <th scope="col">Penanganan</th>
                            <th scope="col">Hasil validasi</th>
                            <th scope="col">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="group in groups.data" :key="group.id">
                            <td class="source-rows">
                                {{
                                    group.rows
                                        .slice(0, 4)
                                        .map((row) => row.number)
                                        .join(", ")
                                }}{{
                                    group.rows.length > 4
                                        ? ` +${group.rows.length - 4}`
                                        : ""
                                }}<small
                                    v-if="group.duplicate_count"
                                    class="usage-note"
                                    >{{ group.duplicate_count }} duplikat
                                    persis</small
                                >
                            </td>
                            <td class="item-description">
                                <strong>{{
                                    group.data.part_number || "PN belum diisi"
                                }}</strong
                                ><small class="usage-note">{{
                                    group.data.item_name || "Nama belum diisi"
                                }}</small>
                            </td>
                            <td>
                                <OwnershipBadge
                                    :type="group.data.ownership_type"
                                /><small class="usage-note"
                                    >{{
                                        group.data.unit_code ||
                                        "Satuan belum valid"
                                    }}
                                    · Target
                                    {{
                                        formatQuantity(group.data.minimum_stock)
                                    }}</small
                                >
                            </td>
                            <td>
                                {{
                                    {
                                        create: "Barang baru",
                                        split: "Pisah per baris",
                                        match: "Perbarui barang",
                                        skip: "Lewati",
                                        review: "Belum ditentukan",
                                    }[group.action]
                                }}<small
                                    v-if="group.target_code"
                                    class="usage-note"
                                    >{{ group.target_code }}</small
                                >
                            </td>
                            <td>
                                <ImportStateBadge :state="group.state" /><small
                                    v-if="group.errors.length"
                                    class="preview-error-text"
                                    >{{ group.errors[0]
                                    }}{{
                                        group.errors.length > 1
                                            ? ` (+${group.errors.length - 1} masalah)`
                                            : ""
                                    }}</small
                                >
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="row-action"
                                    :aria-label="`Tinjau kelompok ${group.data.part_number || group.rows[0].number}`"
                                    @click="reviewGroup(group)"
                                >
                                    <AppIcon name="pencil" /> Tinjau
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!groups.data.length" class="empty-state">
                <AppIcon name="search" />
                <h3>Tidak ada kelompok pada filter ini</h3>
                <p>Pilih hasil validasi lain atau ubah pencarian.</p>
            </div>
            <Pagination :paginator="groups" noun="kelompok" />
        </section>
        <section class="surface-card import-confirm-bar">
            <div>
                <h2>
                    {{ summary.new_items }} barang baru ·
                    {{ summary.updated_items }} diperbarui
                </h2>
                <p>
                    {{ summary.new_vendors }} Vendor dan
                    {{ summary.new_locations }} lokasi baru akan dibuat setelah
                    konfirmasi.
                </p>
                <small>Preview berlaku sampai {{ expiry() }} WITA.</small>
                <p v-if="summary.blocking_groups" class="field-error">
                    Selesaikan {{ summary.blocking_groups }} kelompok sebelum
                    melanjutkan.
                </p>
            </div>
            <button
                type="button"
                class="button button--primary"
                :disabled="!canConfirm"
                @click="
                    confirmForm.clearErrors();
                    confirmOpen = true;
                "
            >
                Lanjut Konfirmasi <AppIcon name="arrowRight" />
            </button>
        </section>
        <p class="page-note">
            <AppIcon name="info" /><span
                >Relasi lama yang tidak dicantumkan di file tetap dipertahankan.
                Untuk menonaktifkan relasi melalui import, isi RELATION_ACTIVE
                dengan 0.</span
            >
        </p>
    </div>
    <BaseDialog
        :open="Boolean(groupTarget)"
        title="Tinjau Kelompok Barang"
        variant="wide"
        :busy="decisionForm.processing"
        @close="!decisionForm.processing && (groupTarget = null)"
        ><form v-if="groupTarget" @submit.prevent="saveDecision">
            <div class="dialog__body form-stack">
                <div class="confirmation-record">
                    <strong>{{ groupTarget.data.part_number }}</strong
                    ><span>{{ groupTarget.data.item_name }}</span>
                </div>
                <div
                    v-if="groupTarget.errors.length"
                    class="validation-list"
                    role="alert"
                >
                    <p v-for="error in groupTarget.errors" :key="error">
                        {{ error }}
                    </p>
                </div>
                <div class="form-grid">
                    <FormField
                        id="group-action"
                        label="Penanganan kelompok"
                        :error="decisionForm.errors.action"
                        required
                        ><select
                            id="group-action"
                            v-model="decisionForm.action"
                            class="form-input"
                            data-autofocus
                        >
                            <option value="review" disabled>
                                Pilih penanganan
                            </option>
                            <option value="create" :disabled="sourceCodeLocked">
                                Buat satu barang baru untuk kelompok ini
                            </option>
                            <option value="split" :disabled="sourceCodeLocked">
                                Buat barang terpisah untuk tiap baris unik
                            </option>
                            <option value="match">
                                Perbarui barang terdaftar
                            </option>
                            <option value="skip">
                                Lewati seluruh kelompok
                            </option>
                        </select></FormField
                    ><FormField
                        v-if="decisionForm.action !== 'skip'"
                        id="group-minimum"
                        label="Target refill"
                        :error="decisionForm.errors.minimum_stock"
                        ><input
                            id="group-minimum"
                            v-model="decisionForm.minimum_stock"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="form-input"
                    /></FormField>
                </div>
                <FormField
                    v-if="decisionForm.action === 'match'"
                    id="group-target"
                    label="Kode internal barang tujuan"
                    :error="decisionForm.errors.target_code"
                    hint="Pilih dengan cermat. PN atau nama yang sama belum tentu merupakan barang yang sama."
                    required
                    ><input
                        id="group-target"
                        v-model="decisionForm.target_code"
                        class="form-input code-input"
                        placeholder="ITM-000001"
                        :readonly="sourceCodeLocked"
                        list="import-candidates"
                    /><datalist id="import-candidates">
                        <option
                            v-for="candidate in groupTarget.candidates"
                            :key="candidate.item_code"
                            :value="candidate.item_code"
                        >
                            {{ candidate.item_name }}
                        </option>
                    </datalist></FormField
                >
                <p
                    v-if="
                        groupTarget.rows.length > 1 &&
                        decisionForm.action === 'create'
                    "
                    class="field-hint"
                >
                    Anda memilih satu barang untuk seluruh baris unik dalam
                    kelompok ini. Pastikan barangnya benar-benar sama.
                </p>
                <div
                    class="table-scroll"
                    tabindex="0"
                    role="region"
                    aria-label="Baris sumber kelompok"
                >
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Baris</th>
                                <th scope="col">Vendor</th>
                                <th scope="col">Lokasi</th>
                                <th scope="col">Status baris</th>
                                <th scope="col">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in groupTarget.rows"
                                :key="row.number"
                            >
                                <td>{{ row.number }}</td>
                                <td>{{ row.values.VENDOR || "—" }}</td>
                                <td>
                                    {{ row.values.LOCATION || "UNASSIGNED" }}
                                </td>
                                <td>
                                    <ImportStateBadge
                                        v-if="row.skip || row.duplicate_of"
                                        :state="
                                            row.skip ? 'skipped' : 'duplicate'
                                        "
                                    /><span v-else>{{
                                        Object.keys(row.errors).length
                                            ? "Perlu diperbaiki"
                                            : "Terbaca"
                                    }}</span>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="row-action"
                                        :aria-label="`Perbaiki baris ${row.number}`"
                                        @click="reviewRow(row)"
                                    >
                                        Perbaiki
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <footer class="dialog__footer">
                <button
                    type="button"
                    class="button button--secondary"
                    :disabled="decisionForm.processing"
                    @click="groupTarget = null"
                >
                    Batal</button
                ><button
                    type="submit"
                    class="button button--primary"
                    :disabled="
                        decisionForm.processing ||
                        decisionForm.action === 'review'
                    "
                >
                    {{
                        decisionForm.processing
                            ? "Menyimpan…"
                            : "Simpan Penanganan"
                    }}
                </button>
            </footer>
        </form></BaseDialog
    >
    <BaseDialog
        :open="Boolean(rowTarget)"
        :title="`Perbaiki Baris ${rowTarget?.number ?? ''}`"
        variant="wide"
        :busy="rowForm.processing"
        @close="!rowForm.processing && (rowTarget = null)"
        ><form v-if="rowTarget" @submit.prevent="saveRow">
            <div class="dialog__body form-stack">
                <p class="field-hint">
                    Perubahan hanya diterapkan pada preview ini; file Excel asli
                    tetap sama.
                </p>
                <div
                    v-if="Object.keys(rowTarget.errors).length"
                    class="validation-list"
                    role="alert"
                >
                    <p v-for="(error, field) in rowTarget.errors" :key="field">
                        {{ error }}
                    </p>
                </div>
                <label class="checkbox-setting"
                    ><input v-model="rowForm.skip" type="checkbox" /><span
                        ><strong>Lewati baris ini</strong
                        ><small
                            >Baris tidak disimpan pada Master Data saat
                            import.</small
                        ></span
                    ></label
                >
                <div class="form-grid">
                    <FormField
                        v-for="field in [
                            {
                                key: 'ITEM_CODE',
                                label: 'Kode internal (opsional)',
                            },
                            { key: 'PART_NUMBER', label: 'Part number' },
                            { key: 'DESCRIPTION', label: 'Nama / deskripsi' },
                            { key: 'VENDOR', label: 'Vendor sumber' },
                            { key: 'LOCATION', label: 'Kode lokasi' },
                            { key: 'UOM', label: 'Kode satuan' },
                            {
                                key: 'TRACKING_TYPE',
                                label: 'Cara pelacakan (QUANTITY)',
                            },
                            {
                                key: 'MINIMUM_STOCK',
                                label: 'Target refill (opsional)',
                            },
                        ]"
                        :id="`row-${field.key}`"
                        :key="field.key"
                        :label="field.label"
                        :error="rowForm.errors[field.key]"
                        ><input
                            :id="`row-${field.key}`"
                            v-model="rowForm[field.key]"
                            type="text"
                            class="form-input"
                            :disabled="rowForm.skip"
                            maxlength="200"
                            :aria-invalid="
                                Boolean(rowForm.errors[field.key])
                            " /></FormField
                    ><FormField
                        id="row-ownership"
                        label="Jenis kepemilikan"
                        :error="rowForm.errors.MATERIAL_TYPE"
                        ><select
                            id="row-ownership"
                            v-model="rowForm.MATERIAL_TYPE"
                            class="form-input"
                            :disabled="rowForm.skip"
                        >
                            <option value="STOCK">STOCK</option>
                            <option value="CONSIGNMENT">CONSIGNMENT</option>
                            <option
                                v-if="rowForm.MATERIAL_TYPE === 'CONSIGMENT'"
                                value="CONSIGMENT"
                            >
                                CONSIGMENT → CONSIGNMENT
                            </option>
                        </select></FormField
                    ><FormField
                        v-for="field in [
                            { key: 'IS_ACTIVE', label: 'Status barang' },
                            {
                                key: 'RELATION_ACTIVE',
                                label: 'Status hubungan / penempatan',
                            },
                        ]"
                        :id="`row-${field.key}`"
                        :key="field.key"
                        :label="field.label"
                        ><select
                            :id="`row-${field.key}`"
                            v-model="rowForm[field.key]"
                            class="form-input"
                            :disabled="rowForm.skip"
                        >
                            <option value="">Ikuti nilai default</option>
                            <option value="1">Aktif</option>
                            <option value="0">Nonaktif</option>
                        </select></FormField
                    >
                </div>
            </div>
            <footer class="dialog__footer">
                <button
                    type="button"
                    class="button button--secondary"
                    :disabled="rowForm.processing"
                    @click="rowTarget = null"
                >
                    Batal</button
                ><button
                    type="submit"
                    class="button button--primary"
                    :disabled="rowForm.processing"
                >
                    {{ rowForm.processing ? "Menyimpan…" : "Simpan Perbaikan" }}
                </button>
            </footer>
        </form></BaseDialog
    >
    <BaseDialog
        :open="confirmOpen"
        title="Konfirmasi Import"
        :busy="confirmForm.processing"
        @close="!confirmForm.processing && (confirmOpen = false)"
        ><form @submit.prevent="confirm">
            <div class="dialog__body form-stack">
                <p class="confirmation-description">
                    Anda akan membuat
                    <strong>{{ summary.new_items }}</strong> barang dan
                    memperbarui
                    <strong>{{ summary.updated_items }}</strong> barang
                    berdasarkan preview ini.
                </p>
                <p class="confirmation-description">
                    {{ summary.new_vendors }} Vendor serta
                    {{ summary.new_locations }} lokasi baru akan dibuat. Semua
                    perubahan disimpan bersama setelah data divalidasi ulang.
                </p>
                <label v-if="summary.skipped_rows > 0" class="checkbox-setting"
                    ><input
                        v-model="confirmForm.acknowledge_skipped"
                        type="checkbox"
                    /><span
                        >Saya memahami bahwa
                        <strong>{{ summary.skipped_rows }} baris sumber</strong
                        >, termasuk duplikat persis, akan dilewati.</span
                    ></label
                >
                <p
                    v-if="confirmForm.errors.confirmation"
                    class="field-error"
                    role="alert"
                >
                    {{ confirmForm.errors.confirmation }}
                </p>
            </div>
            <footer class="dialog__footer">
                <button
                    type="button"
                    class="button button--secondary"
                    :disabled="confirmForm.processing"
                    data-autofocus
                    @click="confirmOpen = false"
                >
                    Batal</button
                ><button
                    type="submit"
                    class="button button--primary"
                    :disabled="
                        confirmForm.processing ||
                        (summary.skipped_rows > 0 &&
                            !confirmForm.acknowledge_skipped)
                    "
                >
                    <span
                        v-if="confirmForm.processing"
                        class="spinner"
                        aria-hidden="true"
                    />{{
                        confirmForm.processing
                            ? "Mengimport…"
                            : "Import Sekarang"
                    }}
                </button>
            </footer>
        </form></BaseDialog
    >
</template>
