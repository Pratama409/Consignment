<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import OwnershipBadge from "../../../Components/OwnershipBadge.vue";
import StatusBadge from "../../../Components/StatusBadge.vue";
import { formatQuantity } from "../../../Support/references";
defineOptions({ layout: AppLayout });
defineProps({ item: Object, can_update: Boolean, edit_url: String });
const page = usePage();
</script>
<template>
    <Head :title="item.item_code" />
    <div class="units-page">
        <PageBanner
            title="Detail Barang"
            description="Identitas, target refill, dan referensi penempatan barang."
            icon="box"
        />
        <div class="page-actions">
            <Link :href="page.props.routes.itemsIndex" class="back-link"
                ><AppIcon name="chevronLeft" /> Kembali ke daftar barang</Link
            ><Link
                v-if="can_update"
                :href="edit_url"
                class="button button--primary"
                ><AppIcon name="pencil" /> Edit Barang</Link
            >
        </div>
        <section class="surface-card form-card">
            <header class="form-card__heading detail-heading">
                <div>
                    <p class="section-eyebrow">{{ item.item_code }}</p>
                    <h2>{{ item.item_name }}</h2>
                </div>
                <div class="detail-badges">
                    <OwnershipBadge :type="item.ownership_type" /><StatusBadge
                        :active="item.is_active"
                    />
                </div>
            </header>
            <dl class="detail-grid">
                <div>
                    <dt>Part number</dt>
                    <dd class="no-wrap">{{ item.part_number }}</dd>
                </div>
                <div>
                    <dt>Satuan</dt>
                    <dd>
                        {{ item.unit.code }} — {{ item.unit.name
                        }}<small v-if="!item.unit.is_active"> (nonaktif)</small>
                    </dd>
                </div>
                <div>
                    <dt>Target refill</dt>
                    <dd>
                        {{ formatQuantity(item.minimum_stock) }}
                        {{ item.unit.code }}
                    </dd>
                </div>
                <div>
                    <dt>Cara pelacakan</dt>
                    <dd>Kuantitas</dd>
                </div>
                <div>
                    <dt>Kategori barang</dt>
                    <dd>
                        {{
                            item.category
                                ? `${item.category.code} — ${item.category.name}`
                                : "Belum dikategorikan"
                        }}<small
                            v-if="item.category && !item.category.is_active"
                        >
                            (nonaktif)</small
                        >
                    </dd>
                </div>
            </dl>
        </section>
        <section class="surface-card form-card">
            <header class="form-card__heading">
                <span class="table-card-heading__icon"
                    ><AppIcon
                        :name="
                            item.ownership_type === 'CONSIGNMENT'
                                ? 'truck'
                                : 'pin'
                        "
                /></span>
                <div>
                    <h2>
                        {{
                            item.ownership_type === "CONSIGNMENT"
                                ? "Hubungan Vendor"
                                : "Penempatan internal"
                        }}
                    </h2>
                    <p>Referensi aktif dan nonaktif tetap dapat dibaca.</p>
                </div>
            </header>
            <div
                class="table-scroll"
                tabindex="0"
                role="region"
                aria-label="Referensi penempatan barang"
            >
                <table
                    v-if="item.ownership_type === 'CONSIGNMENT'"
                    class="data-table"
                >
                    <thead>
                        <tr>
                            <th scope="col">Vendor</th>
                            <th scope="col">PIC</th>
                            <th scope="col">Lokasi utama</th>
                            <th scope="col">Status hubungan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="relation in item.vendor_items"
                            :key="relation.id"
                        >
                            <td>
                                <strong>{{
                                    relation.vendor.vendor_code
                                }}</strong
                                ><small class="usage-note"
                                    >{{ relation.vendor.vendor_name
                                    }}{{
                                        !relation.vendor.is_active
                                            ? " · Vendor nonaktif"
                                            : ""
                                    }}</small
                                >
                            </td>
                            <td>
                                {{ relation.vendor.pic_name || "Belum diatur" }}
                            </td>
                            <td>
                                {{ relation.location.location_code
                                }}<small
                                    v-if="relation.location.location_name"
                                    class="usage-note"
                                    >{{
                                        relation.location.location_name
                                    }}</small
                                >
                            </td>
                            <td>
                                <StatusBadge :active="relation.is_active" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Kode lokasi</th>
                            <th scope="col">Keterangan</th>
                            <th scope="col">Status penempatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="relation in item.stock_locations"
                            :key="relation.id"
                        >
                            <td>
                                <span class="unit-code">{{
                                    relation.location.location_code
                                }}</span>
                            </td>
                            <td>
                                {{ relation.location.location_name || "—" }}
                            </td>
                            <td>
                                <StatusBadge :active="relation.is_active" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="
                    item.ownership_type === 'STOCK' &&
                    !item.stock_locations.length
                "
                class="empty-state"
            >
                <AppIcon name="pin" />
                <p>Belum ada penempatan internal.</p>
            </div>
        </section>
        <p class="page-note">
            <AppIcon name="info" /><span>{{
                item.ownership_type === "CONSIGNMENT"
                    ? "Target refill berlaku sama untuk setiap Vendor barang ini."
                    : "STOCK adalah barang milik internal. WAREHOUSE tidak diperlakukan sebagai Vendor."
            }}</span>
        </p>
    </div>
</template>
