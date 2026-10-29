<script setup>
import { computed, nextTick } from "vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import FormField from "../../../Components/FormField.vue";
import OwnershipBadge from "../../../Components/OwnershipBadge.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({
    item: Object,
    options: Object,
    submit_url: String,
});
const page = usePage();
const form = useForm(`ItemForm:${props.item?.id ?? "new"}`, {
    part_number: props.item?.part_number ?? "",
    item_name: props.item?.item_name ?? "",
    ownership_type: props.item?.ownership_type ?? "STOCK",
    unit_id: props.item?.unit_id ?? "",
    category_id: props.item?.category_id ?? "",
    minimum_stock: props.item?.minimum_stock ?? "0",
    tracking_type: "QUANTITY",
    is_active: props.item?.is_active ?? true,
    vendor_items:
        props.item?.vendor_items
            .filter((row) => row.is_active)
            .map((row) => ({
                vendor_id: row.vendor_id,
                location_id: row.location_id,
                is_active: true,
            })) ?? [],
    stock_locations: props.item
        ? props.item.stock_locations
              .filter((row) => row.is_active)
              .map((row) => ({ location_id: row.location_id, is_active: true }))
        : [{ location_id: props.options.unassigned_id, is_active: true }],
});
const availableVendors = computed(() =>
    props.options.vendors.some(
        (vendor) =>
            vendor.is_active &&
            !form.vendor_items.some((row) => row.vendor_id === vendor.id),
    ),
);
function ownershipChanged() {
    if (form.ownership_type === "CONSIGNMENT" && !form.vendor_items.length)
        addVendor();
    if (form.ownership_type === "STOCK" && !form.stock_locations.length)
        addLocation();
}
function addVendor() {
    form.vendor_items.push({
        vendor_id: "",
        location_id: props.options.unassigned_id,
        is_active: true,
    });
}
function addLocation() {
    form.stock_locations.push({
        location_id: props.options.unassigned_id,
        is_active: true,
    });
}
function save() {
    if (form.processing) return;
    form.transform((data) => ({
        ...data,
        vendor_items:
            data.ownership_type === "CONSIGNMENT" ? data.vendor_items : [],
        stock_locations:
            data.ownership_type === "STOCK" ? data.stock_locations : [],
    }));
    const visit = {
        preserveScroll: true,
        onFinish: async () => {
            if (!form.hasErrors) return;
            await nextTick();
            document.querySelector('[aria-invalid="true"]')?.focus();
        },
    };
    if (props.item) form.put(props.submit_url, visit);
    else form.post(props.submit_url, visit);
}
</script>
<template>
    <Head :title="item ? 'Edit Barang' : 'Tambah Barang'" />
    <div class="units-page">
        <PageBanner
            :title="item ? 'Edit Barang' : 'Tambah Barang'"
            description="Lengkapi identitas barang, target refill, dan penempatannya."
            icon="box"
        />
        <div class="page-actions">
            <Link :href="page.props.routes.itemsIndex" class="back-link"
                ><AppIcon name="chevronLeft" /> Kembali ke daftar barang</Link
            ><span v-if="item" class="unit-code">{{ item.item_code }}</span>
        </div>
        <form novalidate @submit.prevent="save">
            <fieldset class="item-form-fieldset" :disabled="form.processing">
                <div class="item-form-layout">
                    <section class="surface-card form-card">
                        <header class="form-card__heading">
                            <span class="table-card-heading__icon"
                                ><AppIcon name="box"
                            /></span>
                            <div>
                                <h2>Identitas barang</h2>
                                <p>
                                    Kode internal dibuat sistem dan tetap sama
                                    setelah barang disimpan.
                                </p>
                            </div>
                        </header>
                        <div class="form-card__body form-stack">
                            <div class="form-grid">
                                <FormField
                                    id="item-pn"
                                    label="Part number"
                                    :error="form.errors.part_number"
                                    required
                                    ><input
                                        id="item-pn"
                                        v-model="form.part_number"
                                        type="text"
                                        class="form-input"
                                        maxlength="100"
                                        placeholder="Part number barang"
                                        :aria-invalid="
                                            Boolean(form.errors.part_number)
                                        "
                                        :aria-describedby="
                                            form.errors.part_number
                                                ? 'item-pn-error'
                                                : undefined
                                        " /></FormField
                                ><FormField
                                    id="item-ownership"
                                    label="Jenis kepemilikan"
                                    :error="form.errors.ownership_type"
                                    :hint="
                                        item
                                            ? 'Kepemilikan dikunci setelah barang dibuat.'
                                            : 'STOCK milik internal; CONSIGNMENT milik Vendor.'
                                    "
                                    required
                                    ><select
                                        id="item-ownership"
                                        v-model="form.ownership_type"
                                        class="form-input"
                                        :disabled="Boolean(item)"
                                        :aria-invalid="
                                            Boolean(form.errors.ownership_type)
                                        "
                                        :aria-describedby="
                                            form.errors.ownership_type
                                                ? 'item-ownership-error'
                                                : 'item-ownership-hint'
                                        "
                                        @change="ownershipChanged"
                                    >
                                        <option value="STOCK">
                                            Stock internal
                                        </option>
                                        <option value="CONSIGNMENT">
                                            Consignment
                                        </option>
                                    </select></FormField
                                >
                            </div>
                            <FormField
                                id="item-name"
                                label="Nama / deskripsi barang"
                                :error="form.errors.item_name"
                                required
                            >
                                <textarea
                                    id="item-name"
                                    v-model="form.item_name"
                                    class="form-input form-textarea"
                                    maxlength="200"
                                    rows="3"
                                    placeholder="Nama yang membantu pengguna mengenali barang"
                                    :aria-invalid="
                                        Boolean(form.errors.item_name)
                                    "
                                    :aria-describedby="
                                        form.errors.item_name
                                            ? 'item-name-error'
                                            : undefined
                                    "
                                />
                            </FormField>
                            <FormField
                                id="item-category"
                                label="Kategori barang"
                                :error="form.errors.category_id"
                                hint="Opsional. Kelompok produk terpisah dari kepemilikan STOCK atau CONSIGNMENT."
                            >
                                <select
                                    id="item-category"
                                    v-model="form.category_id"
                                    class="form-input"
                                    :aria-invalid="
                                        Boolean(form.errors.category_id)
                                    "
                                    :aria-describedby="
                                        form.errors.category_id
                                            ? 'item-category-error'
                                            : 'item-category-hint'
                                    "
                                >
                                    <option value="">
                                        Belum dikategorikan
                                    </option>
                                    <option
                                        v-for="category in options.categories"
                                        :key="category.id"
                                        :value="category.id"
                                        :disabled="!category.is_active"
                                    >
                                        {{ category.code }} — {{ category.name
                                        }}{{
                                            !category.is_active
                                                ? " (nonaktif)"
                                                : ""
                                        }}
                                    </option>
                                </select>
                            </FormField>
                            <div class="form-grid">
                                <FormField
                                    id="item-unit"
                                    label="Satuan"
                                    :error="form.errors.unit_id"
                                    required
                                    ><select
                                        id="item-unit"
                                        v-model="form.unit_id"
                                        class="form-input"
                                        :aria-invalid="
                                            Boolean(form.errors.unit_id)
                                        "
                                        :aria-describedby="
                                            form.errors.unit_id
                                                ? 'item-unit-error'
                                                : undefined
                                        "
                                    >
                                        <option value="">Pilih satuan</option>
                                        <option
                                            v-for="unit in options.units"
                                            :key="unit.id"
                                            :value="unit.id"
                                            :disabled="!unit.is_active"
                                        >
                                            {{ unit.code }} — {{ unit.name
                                            }}{{
                                                !unit.is_active
                                                    ? " (nonaktif)"
                                                    : ""
                                            }}
                                        </option>
                                    </select></FormField
                                ><FormField
                                    id="item-minimum"
                                    label="Target refill"
                                    :error="form.errors.minimum_stock"
                                    hint="Nilai nonnegatif, maksimal empat desimal. Berlaku sama untuk semua Vendor barang ini."
                                    required
                                    ><input
                                        id="item-minimum"
                                        v-model="form.minimum_stock"
                                        class="form-input"
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        :aria-invalid="
                                            Boolean(form.errors.minimum_stock)
                                        "
                                        :aria-describedby="
                                            form.errors.minimum_stock
                                                ? 'item-minimum-error'
                                                : 'item-minimum-hint'
                                        "
                                /></FormField>
                            </div>
                            <div class="status-setting">
                                <div>
                                    <label for="item-active"
                                        >Barang
                                        {{
                                            form.is_active
                                                ? "aktif"
                                                : "nonaktif"
                                        }}</label
                                    >
                                    <p id="item-active-hint">
                                        Barang nonaktif tetap menyimpan
                                        identitas dan referensinya.
                                    </p>
                                </div>
                                <input
                                    id="item-active"
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="switch-input"
                                    role="switch"
                                    aria-describedby="item-active-hint"
                                />
                            </div>
                            <p
                                v-if="form.errors.is_active"
                                class="field-error"
                                role="alert"
                            >
                                {{ form.errors.is_active }}
                            </p>
                            <p class="page-note">
                                <AppIcon name="info" /><span
                                    >Pelacakan saat ini berdasarkan kuantitas.
                                    QR dan serial number disiapkan pada tahap
                                    berikutnya.</span
                                >
                            </p>
                        </div>
                    </section>
                    <aside class="surface-card form-card form-context">
                        <span class="form-context__icon"
                            ><AppIcon
                                :name="
                                    form.ownership_type === 'STOCK'
                                        ? 'warehouse'
                                        : 'truck'
                                " /></span
                        ><OwnershipBadge :type="form.ownership_type" />
                        <h2>
                            {{
                                form.ownership_type === "STOCK"
                                    ? "Milik internal"
                                    : "Titipan Vendor"
                            }}
                        </h2>
                        <p>
                            {{
                                form.ownership_type === "STOCK"
                                    ? "Barang dikelola warehouse. Penempatan internal dapat mencakup beberapa lokasi."
                                    : "Minimal satu Vendor aktif diperlukan. Setiap pasangan barang–Vendor mempunyai satu lokasi utama."
                            }}
                        </p>
                        <div class="reference-links">
                            <strong>Lengkapi data pilihan</strong
                            ><Link
                                :href="page.props.routes.unitsIndex"
                                target="_blank"
                                rel="noopener"
                                >Master Satuan
                                <AppIcon name="arrowRight" /></Link
                            ><Link
                                :href="page.props.routes.categoriesIndex"
                                target="_blank"
                                rel="noopener"
                                >Master Kategori
                                <AppIcon name="arrowRight" /></Link
                            ><Link
                                :href="page.props.routes.vendorsIndex"
                                target="_blank"
                                rel="noopener"
                                >Master Vendor
                                <AppIcon name="arrowRight" /></Link
                            ><Link
                                :href="page.props.routes.locationsIndex"
                                target="_blank"
                                rel="noopener"
                                >Master Lokasi
                                <AppIcon name="arrowRight" /></Link
                            ><button
                                type="button"
                                class="text-button"
                                @click="
                                    router.reload({
                                        only: ['options'],
                                        preserveState: true,
                                    })
                                "
                            >
                                Muat ulang pilihan
                            </button>
                        </div>
                    </aside>
                </div>
                <section class="surface-card form-card assignments-card">
                    <header class="form-card__heading">
                        <span class="table-card-heading__icon"
                            ><AppIcon
                                :name="
                                    form.ownership_type === 'CONSIGNMENT'
                                        ? 'truck'
                                        : 'pin'
                                "
                        /></span>
                        <div>
                            <h2>
                                {{
                                    form.ownership_type === "CONSIGNMENT"
                                        ? "Vendor dan lokasi utama"
                                        : "Penempatan internal"
                                }}
                            </h2>
                            <p>
                                Gunakan UNASSIGNED jika lokasi belum diketahui.
                                Perubahan tetap mempertahankan identitas relasi
                                sebelumnya.
                            </p>
                        </div>
                    </header>
                    <div class="form-card__body">
                        <template v-if="form.ownership_type === 'CONSIGNMENT'"
                            ><div
                                v-for="(row, index) in form.vendor_items"
                                :key="index"
                                class="assignment-row"
                            >
                                <span class="assignment-number">{{
                                    index + 1
                                }}</span
                                ><FormField
                                    :id="`vendor-${index}`"
                                    label="Vendor"
                                    :error="
                                        form.errors[
                                            `vendor_items.${index}.vendor_id`
                                        ]
                                    "
                                    required
                                    ><select
                                        :id="`vendor-${index}`"
                                        v-model="row.vendor_id"
                                        class="form-input"
                                        :aria-invalid="
                                            Boolean(
                                                form.errors[
                                                    `vendor_items.${index}.vendor_id`
                                                ],
                                            )
                                        "
                                        :aria-describedby="
                                            form.errors[
                                                `vendor_items.${index}.vendor_id`
                                            ]
                                                ? `vendor-${index}-error`
                                                : undefined
                                        "
                                    >
                                        <option value="">Pilih Vendor</option>
                                        <option
                                            v-for="vendor in options.vendors"
                                            :key="vendor.id"
                                            :value="vendor.id"
                                            :disabled="
                                                !vendor.is_active ||
                                                form.vendor_items.some(
                                                    (other, otherIndex) =>
                                                        otherIndex !== index &&
                                                        other.vendor_id ===
                                                            vendor.id,
                                                )
                                            "
                                        >
                                            {{ vendor.vendor_code }} —
                                            {{ vendor.vendor_name
                                            }}{{
                                                !vendor.is_active
                                                    ? " (nonaktif)"
                                                    : ""
                                            }}
                                        </option>
                                    </select></FormField
                                ><FormField
                                    :id="`vendor-location-${index}`"
                                    label="Lokasi utama"
                                    :error="
                                        form.errors[
                                            `vendor_items.${index}.location_id`
                                        ]
                                    "
                                    required
                                    ><select
                                        :id="`vendor-location-${index}`"
                                        v-model="row.location_id"
                                        class="form-input"
                                        :aria-invalid="
                                            Boolean(
                                                form.errors[
                                                    `vendor_items.${index}.location_id`
                                                ],
                                            )
                                        "
                                        :aria-describedby="
                                            form.errors[
                                                `vendor_items.${index}.location_id`
                                            ]
                                                ? `vendor-location-${index}-error`
                                                : undefined
                                        "
                                    >
                                        <option value="">Pilih lokasi</option>
                                        <option
                                            v-for="location in options.locations"
                                            :key="location.id"
                                            :value="location.id"
                                            :disabled="!location.is_active"
                                        >
                                            {{ location.location_code
                                            }}{{
                                                location.location_name
                                                    ? ` — ${location.location_name}`
                                                    : ""
                                            }}{{
                                                !location.is_active
                                                    ? " (nonaktif)"
                                                    : ""
                                            }}
                                        </option>
                                    </select></FormField
                                ><button
                                    type="button"
                                    class="icon-button assignment-remove"
                                    :aria-label="`Lepas Vendor baris ${index + 1}`"
                                    :disabled="form.vendor_items.length === 1"
                                    @click="form.vendor_items.splice(index, 1)"
                                >
                                    <AppIcon name="close" />
                                </button>
                            </div>
                            <p
                                v-if="form.errors.vendor_items"
                                class="field-error"
                                role="alert"
                            >
                                {{ form.errors.vendor_items }}
                            </p>
                            <button
                                type="button"
                                class="button button--secondary"
                                :disabled="!availableVendors"
                                @click="addVendor"
                            >
                                <AppIcon name="plus" /> Tambah Vendor
                            </button>
                            <p
                                v-if="
                                    !options.vendors.some(
                                        (vendor) => vendor.is_active,
                                    )
                                "
                                class="field-hint"
                            >
                                Tambahkan Vendor aktif terlebih dahulu melalui
                                Master Vendor.
                            </p></template
                        ><template v-else
                            ><div
                                v-for="(row, index) in form.stock_locations"
                                :key="index"
                                class="assignment-row assignment-row--stock"
                            >
                                <span class="assignment-number">{{
                                    index + 1
                                }}</span
                                ><FormField
                                    :id="`stock-location-${index}`"
                                    label="Lokasi internal"
                                    :error="
                                        form.errors[
                                            `stock_locations.${index}.location_id`
                                        ]
                                    "
                                    required
                                    ><select
                                        :id="`stock-location-${index}`"
                                        v-model="row.location_id"
                                        class="form-input"
                                        :aria-invalid="
                                            Boolean(
                                                form.errors[
                                                    `stock_locations.${index}.location_id`
                                                ],
                                            )
                                        "
                                        :aria-describedby="
                                            form.errors[
                                                `stock_locations.${index}.location_id`
                                            ]
                                                ? `stock-location-${index}-error`
                                                : undefined
                                        "
                                    >
                                        <option value="">Pilih lokasi</option>
                                        <option
                                            v-for="location in options.locations"
                                            :key="location.id"
                                            :value="location.id"
                                            :disabled="
                                                !location.is_active ||
                                                form.stock_locations.some(
                                                    (other, otherIndex) =>
                                                        otherIndex !== index &&
                                                        other.location_id ===
                                                            location.id,
                                                )
                                            "
                                        >
                                            {{ location.location_code
                                            }}{{
                                                location.location_name
                                                    ? ` — ${location.location_name}`
                                                    : ""
                                            }}
                                        </option>
                                    </select></FormField
                                ><button
                                    type="button"
                                    class="icon-button assignment-remove"
                                    :aria-label="`Lepas lokasi baris ${index + 1}`"
                                    @click="
                                        form.stock_locations.splice(index, 1)
                                    "
                                >
                                    <AppIcon name="close" />
                                </button>
                            </div>
                            <p
                                v-if="form.errors.stock_locations"
                                class="field-error"
                                role="alert"
                            >
                                {{ form.errors.stock_locations }}
                            </p>
                            <button
                                type="button"
                                class="button button--secondary"
                                @click="addLocation"
                            >
                                <AppIcon name="plus" /> Tambah Lokasi
                            </button></template
                        >
                    </div>
                </section>
            </fieldset>
            <footer class="page-form-footer">
                <Link
                    :href="
                        item
                            ? `${page.props.routes.itemsIndex}/${item.id}`
                            : page.props.routes.itemsIndex
                    "
                    class="button button--secondary"
                    >Batal</Link
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
                        form.processing ? "Menyimpan…" : "Simpan Barang"
                    }}
                </button>
            </footer>
        </form>
    </div>
</template>
