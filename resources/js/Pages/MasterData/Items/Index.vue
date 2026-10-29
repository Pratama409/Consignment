<script setup>
import { computed, ref } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import SummaryCard from "../../../Components/SummaryCard.vue";
import StatusBadge from "../../../Components/StatusBadge.vue";
import OwnershipBadge from "../../../Components/OwnershipBadge.vue";
import Pagination from "../../../Components/Pagination.vue";
import BaseDialog from "../../../Components/BaseDialog.vue";
import { formatQuantity } from "../../../Support/references";
import {
    useListFilters,
    withCurrentQuery,
} from "../../../Composables/useListFilters";
defineOptions({ layout: AppLayout });
const props = defineProps({
    items: Object,
    filters: Object,
    summary: Object,
    can: Object,
    create_url: String,
    export_url: String,
    options: Object,
});
const page = usePage();
const { search, filtering, apply, schedule, reset, sort } = useListFilters(
    props,
    () => page.props.routes.itemsIndex,
    {
        sort: "item_code",
        ownership: "all",
        category: "all",
        vendor: "",
        location: "",
    },
);
const hasFilters = computed(
    () =>
        Boolean(search.q.trim()) ||
        search.status !== "all" ||
        search.ownership !== "all" ||
        search.category !== "all" ||
        Boolean(search.vendor) ||
        Boolean(search.location),
);
const target = ref(null);
const statusForm = useForm({ is_active: false });
function openStatus(item) {
    target.value = item;
    statusForm.clearErrors();
    statusForm.is_active = !item.is_active;
}
function saveStatus() {
    if (!statusForm.processing)
        statusForm.patch(withCurrentQuery(target.value.status_url), {
            preserveScroll: true,
            onSuccess: () => {
                target.value = null;
            },
        });
}
</script>
<template>
    <Head title="Master Barang" />
    <div class="units-page">
        <PageBanner
            title="Master Barang"
            description="Satu katalog untuk barang internal dan barang titipan Vendor, dengan identitas yang tetap."
            icon="box"
        />
        <section
            class="summary-grid"
            aria-label="Ringkasan seluruh Master Barang"
        >
            <SummaryCard
                label="Total Barang"
                :value="summary.total"
                description="Seluruh barang terdaftar"
                icon="box"
            /><SummaryCard
                label="Barang Aktif"
                :value="summary.active"
                description="Tersedia untuk pemilihan baru"
                icon="checkCircle"
                tone="green"
            /><SummaryCard
                label="Barang Nonaktif"
                :value="summary.inactive"
                description="Identitas tetap dipertahankan"
                icon="pause"
                tone="slate"
            />
        </section>
        <section class="surface-card" aria-labelledby="items-heading">
            <div class="table-card-heading">
                <div class="table-card-heading__title">
                    <span class="table-card-heading__icon"
                        ><AppIcon name="box"
                    /></span>
                    <div>
                        <h2 id="items-heading">
                            Daftar barang
                            <span class="count-badge">{{ items.total }}</span>
                        </h2>
                        <p>
                            Telusuri barang, kategori, Vendor, dan lokasi
                            aktifnya.
                        </p>
                    </div>
                </div>
                <a :href="export_url" class="button button--secondary"
                    ><AppIcon name="arrowDown" /> Export Excel</a
                >
            </div>
            <div class="table-toolbar">
                <div class="search-input">
                    <AppIcon name="search" /><label
                        for="item-search"
                        class="sr-only"
                        >Cari barang</label
                    ><input
                        id="item-search"
                        v-model="search.q"
                        type="search"
                        placeholder="Cari kode, PN, nama, kategori, Vendor, atau lokasi…"
                        maxlength="100"
                        @input="schedule"
                        @keydown.enter.prevent="apply"
                    /><span
                        v-if="filtering"
                        class="spinner"
                        aria-label="Memuat barang"
                    />
                </div>
                <div class="toolbar-actions item-toolbar-actions">
                    <Link
                        v-if="can.create"
                        :href="create_url"
                        class="button button--primary"
                        ><AppIcon name="plus" /> Tambah Barang</Link
                    >
                </div>
            </div>
            <div class="item-filters">
                <div class="filter-control">
                    <label for="category-filter">Filter kategori</label
                    ><select
                        id="category-filter"
                        v-model="search.category"
                        class="form-select"
                        @change="apply"
                    >
                        <option value="all">Semua kategori</option>
                        <option value="uncategorized">
                            Belum dikategorikan
                        </option>
                        <option
                            v-for="category in options.categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.code }} — {{ category.name
                            }}{{ !category.is_active ? " (nonaktif)" : "" }}
                        </option>
                    </select>
                </div>
                <div class="filter-control">
                    <label for="vendor-filter">Filter Vendor aktif</label
                    ><select
                        id="vendor-filter"
                        v-model="search.vendor"
                        class="form-select"
                        @change="apply"
                    >
                        <option value="">Semua Vendor</option>
                        <option
                            v-for="vendor in options.vendors"
                            :key="vendor.id"
                            :value="vendor.id"
                        >
                            {{ vendor.vendor_code }} — {{ vendor.vendor_name
                            }}{{ !vendor.is_active ? " (nonaktif)" : "" }}
                        </option>
                    </select>
                </div>
                <div class="filter-control">
                    <label for="location-filter">Filter lokasi aktif</label
                    ><select
                        id="location-filter"
                        v-model="search.location"
                        class="form-select"
                        @change="apply"
                    >
                        <option value="">Semua lokasi</option>
                        <option
                            v-for="location in options.locations"
                            :key="location.id"
                            :value="location.id"
                        >
                            {{ location.location_code
                            }}{{ !location.is_active ? " (nonaktif)" : "" }}
                        </option>
                    </select>
                </div>
                <div class="filter-control">
                    <label for="ownership-filter">Filter kepemilikan</label
                    ><select
                        id="ownership-filter"
                        v-model="search.ownership"
                        class="form-select"
                        @change="apply"
                    >
                        <option value="all">Semua jenis</option>
                        <option value="STOCK">Stock internal</option>
                        <option value="CONSIGNMENT">Consignment</option>
                    </select>
                </div>
                <div class="filter-control">
                    <label for="item-status">Filter status barang</label
                    ><select
                        id="item-status"
                        v-model="search.status"
                        class="form-select"
                        @change="apply"
                    >
                        <option value="all">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="table-meta">
                <span
                    >{{
                        hasFilters
                            ? "Hasil pencarian"
                            : "Seluruh katalog barang"
                    }}<button
                        v-if="hasFilters"
                        class="text-button"
                        type="button"
                        @click="reset"
                    >
                        Reset filter
                    </button></span
                ><label class="per-page-label" for="item-per-page"
                    >Tampilkan<select
                        id="item-per-page"
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
                aria-label="Tabel barang, dapat digeser horizontal"
            >
                <table class="data-table items-table">
                    <caption class="sr-only">
                        Katalog barang
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="column in [
                                    { key: 'item_code', label: 'Kode' },
                                    {
                                        key: 'part_number',
                                        label: 'Part Number',
                                    },
                                    {
                                        key: 'item_name',
                                        label: 'Nama / Deskripsi',
                                    },
                                ]"
                                :key="column.key"
                                scope="col"
                            >
                                <button
                                    class="table-sort"
                                    type="button"
                                    @click="sort(column.key)"
                                >
                                    {{ column.label
                                    }}<AppIcon
                                        v-if="search.sort === column.key"
                                        :name="
                                            search.direction === 'asc'
                                                ? 'arrowUp'
                                                : 'arrowDown'
                                        "
                                    />
                                </button>
                            </th>
                            <th scope="col">Jenis</th>
                            <th scope="col">Kategori</th>
                            <th scope="col">Satuan</th>
                            <th scope="col" class="numeric-cell">
                                <button
                                    type="button"
                                    class="table-sort"
                                    @click="sort('minimum_stock')"
                                >
                                    Target refill
                                </button>
                            </th>
                            <th scope="col">Vendor / lokasi aktif</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="actions-column">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items.data" :key="item.id">
                            <td>
                                <Link
                                    :href="item.show_url"
                                    class="item-code-link"
                                    >{{ item.item_code }}</Link
                                >
                            </td>
                            <td class="no-wrap">{{ item.part_number }}</td>
                            <td class="item-description">
                                {{ item.item_name }}
                            </td>
                            <td>
                                <OwnershipBadge :type="item.ownership_type" />
                            </td>
                            <td>
                                <span
                                    v-if="item.category"
                                    class="category-label"
                                    >{{ item.category.name }}</span
                                ><span v-else class="muted-text"
                                    >Belum dikategorikan</span
                                >
                            </td>
                            <td>
                                <span class="unit-code">{{
                                    item.unit_code
                                }}</span>
                            </td>
                            <td class="numeric-cell">
                                {{ formatQuantity(item.minimum_stock) }}
                            </td>
                            <td class="placement-cell">
                                <strong>{{
                                    item.ownership_type === "CONSIGNMENT"
                                        ? `${item.vendor_count} Vendor`
                                        : "Internal"
                                }}</strong>
                                <small
                                    v-for="(
                                        placement, index
                                    ) in item.placements.slice(0, 2)"
                                    :key="index"
                                    class="usage-note"
                                    >{{
                                        placement.vendor_code
                                            ? `${placement.vendor_code} · `
                                            : ""
                                    }}{{ placement.location_code }}</small
                                >
                                <Link
                                    v-if="item.placements.length > 2"
                                    :href="item.show_url"
                                    class="text-button placement-more"
                                    >+{{ item.placements.length - 2 }} relasi
                                    lainnya</Link
                                >
                                <small
                                    v-if="!item.placements.length"
                                    class="usage-note"
                                    >Belum ada penempatan</small
                                >
                            </td>
                            <td><StatusBadge :active="item.is_active" /></td>
                            <td>
                                <div class="row-actions">
                                    <Link
                                        :href="item.show_url"
                                        class="row-action"
                                        :aria-label="`Detail barang ${item.item_code}`"
                                        ><AppIcon name="eye" /> Detail</Link
                                    ><Link
                                        v-if="item.can_update"
                                        :href="item.edit_url"
                                        class="row-action"
                                        :aria-label="`Edit barang ${item.item_code}`"
                                        ><AppIcon name="pencil" /></Link
                                    ><button
                                        v-if="item.can_update"
                                        type="button"
                                        class="row-action"
                                        :aria-label="`${item.is_active ? 'Nonaktifkan' : 'Aktifkan'} barang ${item.item_code}`"
                                        @click="openStatus(item)"
                                    >
                                        <AppIcon name="power" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!items.data.length" class="empty-state">
                <span class="empty-state__icon"
                    ><AppIcon :name="hasFilters ? 'search' : 'box'"
                /></span>
                <h3>
                    {{
                        hasFilters
                            ? "Barang tidak ditemukan"
                            : "Belum ada data barang"
                    }}
                </h3>
                <p>
                    {{
                        hasFilters
                            ? "Coba kata kunci lain atau ubah filter."
                            : "Tambahkan barang secara manual atau mulai dari preview Master PN."
                    }}
                </p>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="button button--secondary"
                    @click="reset"
                >
                    Reset filter</button
                ><Link
                    v-else-if="can.create"
                    :href="create_url"
                    class="button button--primary"
                    ><AppIcon name="plus" /> Tambah Barang</Link
                >
            </div>
            <Pagination :paginator="items" noun="barang" />
        </section>
        <p class="page-note">
            <AppIcon name="info" /><span
                >Part number boleh sama. Gunakan kode internal dan jenis
                kepemilikan untuk memastikan barang yang dipilih.</span
            >
        </p>
    </div>
    <BaseDialog
        :open="Boolean(target)"
        :title="target?.is_active ? 'Nonaktifkan Barang?' : 'Aktifkan Barang?'"
        :busy="statusForm.processing"
        @close="!statusForm.processing && (target = null)"
        ><form v-if="target" @submit.prevent="saveStatus">
            <div class="dialog__body">
                <div class="confirmation-record">
                    <span class="unit-code">{{ target.item_code }}</span
                    ><strong>{{ target.item_name }}</strong>
                </div>
                <p class="confirmation-description">
                    Status berubah tanpa mengganti identitas, kepemilikan, atau
                    menghapus hubungan sebelumnya.
                </p>
                <p
                    v-for="(error, key) in statusForm.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
            </div>
            <footer class="dialog__footer">
                <button
                    type="button"
                    class="button button--secondary"
                    :disabled="statusForm.processing"
                    data-autofocus
                    @click="target = null"
                >
                    Batal</button
                ><button
                    type="submit"
                    class="button"
                    :class="
                        target.is_active ? 'button--danger' : 'button--primary'
                    "
                    :disabled="statusForm.processing"
                >
                    {{
                        statusForm.processing
                            ? "Menyimpan…"
                            : target.is_active
                              ? "Nonaktifkan Barang"
                              : "Aktifkan Barang"
                    }}
                </button>
            </footer>
        </form></BaseDialog
    >
</template>
