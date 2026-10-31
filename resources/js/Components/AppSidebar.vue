<script setup>
import { computed, reactive } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import AppIcon from "./AppIcon.vue";

const emit = defineEmits(["navigate"]);
const page = usePage();
const user = computed(() => page.props.auth.user);
const internal = computed(() => user.value?.role !== "vendor");
const admin = computed(() => user.value?.role === "admin");
const initials = computed(() =>
    user.value.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase(),
);
const routes = computed(() => page.props.routes);
const refillBadge = computed(() => page.props.navigation.badges?.refills || 0);

const groups = computed(() => [
    { label: "Utama", icon: "dashboard", items: [{ key: "home", label: "Dashboard", icon: "warehouse", url: routes.value.home }] },
    ...(internal.value
        ? [{
              label: "Transaksi",
              icon: "arrowDown",
              items: [
                  { key: "manual-data-in", label: "Data IN", icon: "arrowDown", url: routes.value.manualDataInIndex },
                  { key: "material-requests", label: "Data OUT / Material Request", icon: "box", url: routes.value.materialRequestsIndex },
              ],
          }]
        : []),
    { label: "Pengadaan", icon: "layers", items: [{ key: "procurement", label: "PR, PO & Dokumen", icon: "layers", url: routes.value.procurementIndex }] },
    {
        label: "Monitoring",
        icon: "chart",
        items: [
            ...(internal.value
                ? [
                      { key: "monitoring-transactions", label: "Rekap Transaksi", icon: "layers", url: routes.value.monitoringTransactionsIndex },
                      { key: "monitoring-shifts", label: "Rekap Shift", icon: "layers", url: routes.value.monitoringShiftsIndex },
                  ]
                : []),
            { key: "monitoring-reconciliations", label: "BA Rekonsiliasi", icon: "checkCircle", url: routes.value.monitoringReconciliationsIndex },
            { key: "monitoring-stock", label: "Stock Sistem", icon: "warehouse", url: routes.value.monitoringSystemStockIndex },
        ],
    },
    {
        label: "Vendor & Refill",
        icon: "truck",
        items: [
            { key: "refills", label: "Vendor Refill", icon: "truck", url: routes.value.refillsIndex, badge: refillBadge.value },
            ...(internal.value ? [{ key: "incoming-refills", label: "Incoming Refill", icon: "arrowDown", url: routes.value.incomingRefillsIndex }] : []),
        ],
    },
    ...(internal.value
        ? [{
              label: "Master & Tools",
              icon: "pin",
              items: [
                  { key: "items", label: "Master Part", icon: "box", url: routes.value.itemsIndex },
                  { key: "categories", label: "Kategori", icon: "layers", url: routes.value.categoriesIndex },
                  { key: "departments", label: "Departemen", icon: "layers", url: routes.value.departmentsIndex },
                  { key: "vendors", label: "Vendor", icon: "truck", url: routes.value.vendorsIndex },
                  { key: "units", label: "Satuan", icon: "ruler", url: routes.value.unitsIndex },
                  { key: "locations", label: "Lokasi", icon: "pin", url: routes.value.locationsIndex },
                  ...(page.props.navigation.can_import ? [{ key: "imports", label: "Import Master PN", icon: "upload", url: routes.value.importsIndex }] : []),
                  ...(admin.value ? [{ key: "users", label: "User Management", icon: "shield", url: routes.value.usersIndex }] : []),
                  { key: "opening-balances", label: "Saldo Awal", icon: "upload", url: routes.value.openingBalancesIndex },
                  { key: "stock-corrections", label: "Koreksi Stok", icon: "shield", url: routes.value.stockCorrectionsIndex },
                  { key: "stock-opname", label: "Stock Opname", icon: "checkCircle", url: routes.value.stockOpnameIndex },
                  { key: "shifts", label: "Pengaturan Shift", icon: "layers", url: routes.value.shiftsIndex },
              ],
          }]
        : []),
]);

const isActive = (item) => page.props.navigation.current === (item.active || item.key);
const holdsCurrent = (group) => group.items.some(isActive);
const groupBadge = (group) => group.items.reduce((sum, item) => sum + (item.badge || 0), 0);

// A group is open while it holds the current page; the user can open or close the others.
const manual = reactive({});
const isOpen = (group) => manual[group.label] ?? holdsCurrent(group);
const toggle = (group) => {
    manual[group.label] = !isOpen(group);
};
</script>

<template>
    <div class="sidebar-content">
        <div class="sidebar-brand">
            <span class="sidebar-brand__mark"><AppIcon name="warehouse" /></span>
            <div>
                <span class="sidebar-brand__name">WAREHOUSE</span
                ><span class="sidebar-brand__sub">SYSTEM</span>
            </div>
        </div>
        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <p class="sidebar-section-label">AREA KERJA</p>
            <div v-for="group in groups" :key="group.label" class="nav-group">
                <button
                    type="button"
                    class="nav-group-toggle"
                    :class="{ 'nav-group-toggle--current': holdsCurrent(group) }"
                    :aria-expanded="isOpen(group)"
                    :aria-controls="`nav-${group.label}`"
                    @click="toggle(group)"
                >
                    <span class="nav-group-toggle__icon"><AppIcon :name="group.icon" /></span>
                    <span class="nav-group-toggle__label">{{ group.label }}</span>
                    <span v-if="groupBadge(group) && !isOpen(group)" class="nav-badge" :aria-label="`${groupBadge(group)} perlu ditindaklanjuti`">{{ groupBadge(group) }}</span>
                    <AppIcon name="chevronDown" class="nav-group-toggle__chevron" />
                </button>
                <div :id="`nav-${group.label}`" class="nav-collapse" :data-open="isOpen(group)" :inert="!isOpen(group)">
                    <div class="nav-collapse__inner nav-children">
                        <Link
                            v-for="item in group.items"
                            :key="item.key"
                            :href="item.url"
                            class="nav-item"
                            :class="{ 'nav-item--active': isActive(item) }"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            @click="emit('navigate')"
                        >
                            <span class="nav-item__icon"><AppIcon :name="item.icon" /></span>
                            <span>{{ item.label }}</span>
                            <span v-if="item.badge" class="nav-badge" :aria-label="`${item.badge} perlu ditindaklanjuti`">{{ item.badge }}</span>
                            <span v-if="isActive(item)" class="nav-item__active-dot" />
                        </Link>
                    </div>
                </div>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-access">
                <AppIcon name="shield" /><span>{{ user.role === "vendor" ? "Akses vendor" : "Akses internal" }}</span>
            </div>
            <div class="sidebar-user">
                <span class="avatar">{{ initials }}</span>
                <div class="sidebar-user__identity">
                    <span>{{ user.name }}</span
                    ><small>{{ user.role_label }}</small>
                </div>
            </div>
            <Link :href="routes.logout" method="post" as="button" class="sidebar-logout"><AppIcon name="logout" /><span>Keluar</span></Link>
        </div>
    </div>
</template>
