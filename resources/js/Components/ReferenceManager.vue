<script setup>
import { computed, nextTick, ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import {
    useListFilters,
    withCurrentQuery,
} from "../Composables/useListFilters";
import AppIcon from "./AppIcon.vue";
import BaseDialog from "./BaseDialog.vue";
import FormField from "./FormField.vue";
import PageBanner from "./PageBanner.vue";
import Pagination from "./Pagination.vue";
import StatusBadge from "./StatusBadge.vue";
import SummaryCard from "./SummaryCard.vue";

const props = defineProps({
    definition: Object,
    records: Object,
    filters: Object,
    summary: Object,
    can: Object,
    storeUrl: String,
});
const page = usePage();
const { search, filtering, apply, schedule, reset, sort } = useListFilters(
    props,
    () => page.props.routes[props.definition.routeKey],
);
const formOpen = ref(false);
const editing = ref(null);
const statusTarget = ref(null);
const form = useForm({ code: "", name: "", pic_name: "", is_active: true });
const statusForm = useForm({ is_active: false });
const usage = (record) =>
    record?.usage_count ?? record?.active_items_count ?? 0;
const locked = computed(
    () =>
        editing.value?.is_system ||
        (editing.value?.is_active && usage(editing.value) > 0),
);
const hasFilters = computed(
    () => Boolean(search.q.trim()) || search.status !== "all",
);
const number = new Intl.NumberFormat("id-ID");

function openForm(record = null) {
    editing.value = record;
    form.clearErrors();
    form.defaults({
        code: record?.code ?? "",
        name: record?.name ?? "",
        pic_name: record?.pic_name ?? "",
        is_active: record?.is_active ?? true,
    });
    form.reset();
    formOpen.value = true;
}
function closeForm() {
    if (!form.processing) {
        formOpen.value = false;
        form.clearErrors();
    }
}
function save() {
    if (form.processing) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
        onError: async () => {
            await nextTick();
            document
                .querySelector('dialog[open] [aria-invalid="true"]')
                ?.focus();
        },
    };
    form.transform((data) => ({
        ...data,
        code: data.code.trim().toUpperCase(),
        name: data.name.trim(),
    }));
    if (editing.value)
        form.put(withCurrentQuery(editing.value.update_url), options);
    else form.post(withCurrentQuery(props.storeUrl), options);
}
function openStatus(record) {
    statusForm.clearErrors();
    statusForm.is_active = !record.is_active;
    statusTarget.value = record;
}
function changeStatus() {
    if (statusForm.processing) return;
    statusForm.patch(withCurrentQuery(statusTarget.value.status_url), {
        preserveScroll: true,
        onSuccess: () => {
            statusTarget.value = null;
        },
    });
}
</script>

<template>
    <Head :title="definition.title" />
    <div class="units-page">
        <PageBanner
            :title="definition.title"
            :description="definition.description"
            :icon="definition.icon"
        />
        <section
            class="summary-grid"
            :aria-label="`Ringkasan seluruh data ${definition.lower}`"
        >
            <SummaryCard
                :label="`Total ${definition.noun}`"
                :value="summary.total"
                :description="`Seluruh ${definition.lower} terdaftar`"
                :icon="definition.icon"
            />
            <SummaryCard
                :label="`${definition.noun} Aktif`"
                :value="summary.active"
                description="Tersedia untuk pemilihan baru"
                icon="checkCircle"
                tone="green"
            />
            <SummaryCard
                :label="`${definition.noun} Nonaktif`"
                :value="summary.inactive"
                description="Tetap tersimpan dalam riwayat"
                icon="pause"
                tone="slate"
            />
        </section>
        <section class="surface-card" aria-labelledby="reference-heading">
            <div class="table-card-heading">
                <div class="table-card-heading__title">
                    <span class="table-card-heading__icon"
                        ><AppIcon :name="definition.icon"
                    /></span>
                    <div>
                        <h2 id="reference-heading">
                            Daftar {{ definition.lower }}
                            <span class="count-badge">{{
                                number.format(records.total)
                            }}</span>
                        </h2>
                        <p>
                            {{
                                hasFilters
                                    ? "Hasil sesuai pencarian dan filter Anda."
                                    : `Kelola kode, nama, dan status ${definition.lower}.`
                            }}
                        </p>
                    </div>
                </div>
                <span v-if="!can.create" class="read-only-label"
                    ><AppIcon name="lock" /> Akses baca</span
                >
            </div>
            <div class="table-toolbar">
                <div class="search-input">
                    <AppIcon name="search" /><label
                        for="reference-search"
                        class="sr-only"
                        >Cari kode atau nama {{ definition.lower }}</label
                    ><input
                        id="reference-search"
                        v-model="search.q"
                        type="search"
                        :placeholder="`Cari kode atau nama ${definition.lower}…`"
                        maxlength="100"
                        autocomplete="off"
                        @input="schedule"
                        @keydown.enter.prevent="apply"
                    /><span
                        v-if="filtering"
                        class="spinner"
                        aria-label="Memuat hasil"
                    />
                </div>
                <div class="toolbar-actions">
                    <div class="status-filter">
                        <label for="reference-status" class="sr-only"
                            >Filter status {{ definition.lower }}</label
                        ><select
                            id="reference-status"
                            v-model="search.status"
                            class="form-select"
                            @change="apply"
                        >
                            <option value="all">Semua status</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option></select
                        ><AppIcon name="chevronDown" />
                    </div>
                    <button
                        v-if="can.create"
                        type="button"
                        class="button button--primary"
                        @click="openForm()"
                    >
                        <AppIcon name="plus" /> Tambah {{ definition.noun }}
                    </button>
                </div>
            </div>
            <div class="table-meta">
                <span
                    >{{
                        hasFilters
                            ? "Hasil pencarian"
                            : `Semua ${definition.lower}`
                    }}<button
                        v-if="hasFilters"
                        type="button"
                        class="text-button"
                        @click="reset"
                    >
                        Reset filter
                    </button></span
                ><label class="per-page-label" for="reference-per-page"
                    >Tampilkan<select
                        id="reference-per-page"
                        v-model.number="search.per_page"
                        @change="apply"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option></select
                    ><span>per halaman</span></label
                >
            </div>
            <div
                class="table-scroll"
                :aria-busy="filtering"
                tabindex="0"
                role="region"
                :aria-label="`Tabel ${definition.lower}, geser horizontal pada layar sempit`"
            >
                <table class="data-table">
                    <caption class="sr-only">
                        Daftar
                        {{
                            definition.lower
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                class="code-column"
                                :aria-sort="
                                    search.sort === 'code'
                                        ? search.direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                            >
                                <button
                                    type="button"
                                    class="table-sort"
                                    @click="sort('code')"
                                >
                                    Kode<AppIcon
                                        v-if="search.sort === 'code'"
                                        :name="
                                            search.direction === 'asc'
                                                ? 'arrowUp'
                                                : 'arrowDown'
                                        "
                                    />
                                </button>
                            </th>
                            <th
                                scope="col"
                                :aria-sort="
                                    search.sort === 'name'
                                        ? search.direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                            >
                                <button
                                    type="button"
                                    class="table-sort"
                                    @click="sort('name')"
                                >
                                    {{ definition.nameLabel
                                    }}<AppIcon
                                        v-if="search.sort === 'name'"
                                        :name="
                                            search.direction === 'asc'
                                                ? 'arrowUp'
                                                : 'arrowDown'
                                        "
                                    />
                                </button>
                            </th>
                            <th scope="col" class="status-column">Status</th>
                            <th scope="col" class="actions-column">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in records.data" :key="record.id">
                            <td>
                                <span class="unit-code">{{ record.code }}</span
                                ><small
                                    v-if="record.is_system"
                                    class="usage-note"
                                    >Lokasi sistem</small
                                >
                            </td>
                            <td class="unit-name">
                                {{ record.name || "—"
                                }}<small
                                    v-if="definition.key === 'vendors'"
                                    class="usage-note"
                                    >{{
                                        record.pic_name
                                            ? `PIC: ${record.pic_name}`
                                            : "PIC belum diatur"
                                    }}</small
                                >
                            </td>
                            <td>
                                <StatusBadge :active="record.is_active" /><small
                                    v-if="usage(record) > 0"
                                    class="usage-note"
                                    >Dipakai {{ number.format(usage(record)) }}
                                    {{ definition.usage }}</small
                                >
                            </td>
                            <td>
                                <div
                                    v-if="record.can_update"
                                    class="row-actions"
                                >
                                    <button
                                        type="button"
                                        class="row-action"
                                        :aria-label="`Edit ${definition.lower} ${record.code}`"
                                        @click="openForm(record)"
                                    >
                                        <AppIcon name="pencil" /> Edit</button
                                    ><button
                                        type="button"
                                        class="row-action"
                                        :class="
                                            record.is_active
                                                ? 'row-action--danger'
                                                : 'row-action--success'
                                        "
                                        :disabled="
                                            record.is_system ||
                                            (record.is_active &&
                                                usage(record) > 0)
                                        "
                                        :aria-label="`${record.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${definition.lower} ${record.code}`"
                                        :title="
                                            record.is_system
                                                ? 'Lokasi sistem harus tetap aktif'
                                                : usage(record) > 0
                                                  ? 'Masih digunakan referensi aktif'
                                                  : undefined
                                        "
                                        @click="openStatus(record)"
                                    >
                                        <AppIcon name="power" />{{
                                            record.is_active
                                                ? "Nonaktifkan"
                                                : "Aktifkan"
                                        }}
                                    </button>
                                </div>
                                <span v-else class="muted-text"
                                    >Hanya admin</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!records.data.length" class="empty-state">
                <span class="empty-state__icon"
                    ><AppIcon :name="hasFilters ? 'search' : definition.icon"
                /></span>
                <h3>
                    {{
                        hasFilters
                            ? `${definition.noun} tidak ditemukan`
                            : `Belum ada data ${definition.lower}`
                    }}
                </h3>
                <p>
                    {{
                        hasFilters
                            ? "Coba kata kunci lain atau ubah filter status."
                            : `Tambahkan ${definition.lower} pertama untuk digunakan pada Master Barang.`
                    }}
                </p>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="button button--secondary"
                    @click="reset"
                >
                    Reset filter</button
                ><button
                    v-else-if="can.create"
                    type="button"
                    class="button button--primary"
                    @click="openForm()"
                >
                    <AppIcon name="plus" /> Tambah {{ definition.noun }}
                </button>
            </div>
            <Pagination :paginator="records" :noun="definition.plural" />
        </section>
        <p class="page-note">
            <AppIcon name="info" /><span>{{ definition.note }}</span>
        </p>
    </div>
    <BaseDialog
        :open="formOpen"
        :title="`${editing ? 'Edit' : 'Tambah'} ${definition.noun}`"
        :description="`Lengkapi informasi ${definition.lower} yang digunakan pada barang.`"
        :busy="form.processing"
        @close="closeForm"
        ><form novalidate @submit.prevent="save">
            <div class="dialog__body">
                <div class="unit-form-grid">
                    <FormField
                        id="reference-code"
                        :label="definition.codeLabel"
                        :error="form.errors.code"
                        :hint="
                            editing?.is_system
                                ? 'Kode lokasi sistem dikunci.'
                                : `Kode unik, maksimal ${definition.codeLength} karakter.`
                        "
                        required
                        ><input
                            id="reference-code"
                            v-model="form.code"
                            class="form-input code-input"
                            :class="{ 'form-input--invalid': form.errors.code }"
                            :readonly="editing?.is_system"
                            type="text"
                            :placeholder="definition.codePlaceholder"
                            :maxlength="definition.codeLength"
                            required
                            data-autofocus
                            :aria-invalid="Boolean(form.errors.code)"
                            :aria-describedby="
                                form.errors.code
                                    ? 'reference-code-error'
                                    : 'reference-code-hint'
                            " /></FormField
                    ><FormField
                        id="reference-name"
                        :label="definition.nameLabel"
                        :error="form.errors.name"
                        :required="definition.key !== 'locations'"
                        ><input
                            id="reference-name"
                            v-model="form.name"
                            class="form-input"
                            :class="{ 'form-input--invalid': form.errors.name }"
                            type="text"
                            :placeholder="definition.namePlaceholder"
                            :maxlength="definition.nameLength"
                            :aria-invalid="Boolean(form.errors.name)"
                            :aria-describedby="
                                form.errors.name
                                    ? 'reference-name-error'
                                    : undefined
                            "
                    /></FormField>
                </div>
                <FormField
                    v-if="definition.key === 'vendors'"
                    id="reference-pic"
                    label="Nama PIC"
                    :error="form.errors.pic_name"
                    hint="Opsional. Dapat diubah melalui pengaturan Vendor."
                    ><input
                        id="reference-pic"
                        v-model="form.pic_name"
                        class="form-input"
                        type="text"
                        placeholder="Nama PIC Vendor"
                        maxlength="150"
                        :aria-invalid="Boolean(form.errors.pic_name)"
                        :aria-describedby="
                            form.errors.pic_name
                                ? 'reference-pic-error'
                                : 'reference-pic-hint'
                        "
                /></FormField>
                <div class="status-setting">
                    <div>
                        <label for="reference-active"
                            >{{ definition.noun }}
                            {{ form.is_active ? "aktif" : "nonaktif" }}</label
                        >
                        <p id="reference-active-hint">
                            {{
                                editing?.is_system
                                    ? "Lokasi sistem harus tetap aktif."
                                    : locked
                                      ? "Status dipertahankan karena masih digunakan referensi aktif."
                                      : "Data aktif tersedia untuk pemilihan baru."
                            }}
                        </p>
                    </div>
                    <input
                        id="reference-active"
                        v-model="form.is_active"
                        type="checkbox"
                        class="switch-input"
                        role="switch"
                        :disabled="locked"
                        :aria-invalid="Boolean(form.errors.is_active)"
                        :aria-describedby="
                            form.errors.is_active
                                ? 'reference-active-error'
                                : 'reference-active-hint'
                        "
                    />
                </div>
                <p
                    v-if="form.errors.is_active"
                    id="reference-active-error"
                    class="field-error"
                    role="alert"
                >
                    {{ form.errors.is_active }}
                </p>
            </div>
            <footer class="dialog__footer">
                <button
                    type="button"
                    class="button button--secondary"
                    :disabled="form.processing"
                    @click="closeForm"
                >
                    Batal</button
                ><button
                    type="submit"
                    class="button button--primary"
                    :disabled="form.processing"
                >
                    <span
                        v-if="form.processing"
                        class="spinner"
                        aria-hidden="true"
                    /><AppIcon v-else name="check" />{{
                        form.processing
                            ? "Menyimpan…"
                            : `Simpan ${definition.noun}`
                    }}
                </button>
            </footer>
        </form></BaseDialog
    >
    <BaseDialog
        :open="Boolean(statusTarget)"
        :title="`${statusTarget?.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${definition.noun}?`"
        :busy="statusForm.processing"
        @close="!statusForm.processing && (statusTarget = null)"
        ><form v-if="statusTarget" @submit.prevent="changeStatus">
            <div class="dialog__body">
                <div class="confirmation-record">
                    <span class="unit-code">{{ statusTarget.code }}</span
                    ><strong>{{ statusTarget.name }}</strong>
                </div>
                <p class="confirmation-description">
                    {{
                        statusTarget.is_active
                            ? "Data ini tidak akan tersedia untuk pemilihan baru. Identitas dan referensi sebelumnya tetap tersimpan."
                            : "Data ini akan tersedia kembali untuk pemilihan baru."
                    }}
                </p>
                <p
                    v-if="statusForm.errors.is_active"
                    class="field-error"
                    role="alert"
                >
                    {{ statusForm.errors.is_active }}
                </p>
            </div>
            <footer class="dialog__footer">
                <button
                    type="button"
                    class="button button--secondary"
                    :disabled="statusForm.processing"
                    data-autofocus
                    @click="statusTarget = null"
                >
                    Batal</button
                ><button
                    type="submit"
                    class="button"
                    :class="
                        statusTarget.is_active
                            ? 'button--danger'
                            : 'button--primary'
                    "
                    :disabled="statusForm.processing"
                >
                    <span
                        v-if="statusForm.processing"
                        class="spinner"
                        aria-hidden="true"
                    /><AppIcon v-else name="power" />{{
                        statusForm.processing
                            ? "Menyimpan…"
                            : `${statusTarget.is_active ? "Nonaktifkan" : "Aktifkan"} ${definition.noun}`
                    }}
                </button>
            </footer>
        </form></BaseDialog
    >
</template>
