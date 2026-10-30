<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import AppIcon from "./AppIcon.vue";
const emit = defineEmits(["navigate"]);
const page = usePage();
const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    user.value.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase(),
);
const modules = computed(() => [
    ...(!user.value || user.value.role === "vendor"
        ? []
        : [{ key: "material-requests", label: "Material Request", icon: "box", url: page.props.routes.materialRequestsIndex }]),
    {
        key: "refills",
        label: "Vendor Refill",
        icon: "truck",
        url: page.props.routes.refillsIndex,
    },
    ...(!user.value || user.value.role === "vendor"
        ? []
        : [
              {
                  key: "incoming-refills",
                  label: "Incoming Refill",
                  icon: "arrowDown",
                  url: page.props.routes.incomingRefillsIndex,
              },
          ]),
    {
        key: "items",
        label: "Barang",
        icon: "box",
        url: page.props.routes.itemsIndex,
    },
    {
        key: "categories",
        label: "Kategori",
        icon: "layers",
        url: page.props.routes.categoriesIndex,
    },
    {
        key: "vendors",
        label: "Vendor",
        icon: "truck",
        url: page.props.routes.vendorsIndex,
    },
    {
        key: "units",
        label: "Satuan",
        icon: "ruler",
        url: page.props.routes.unitsIndex,
    },
    {
        key: "locations",
        label: "Lokasi",
        icon: "pin",
        url: page.props.routes.locationsIndex,
    },
    ...(page.props.navigation.can_import
        ? [
              {
                  key: "imports",
                  label: "Import Master PN",
                  icon: "upload",
                  url: page.props.routes.importsIndex,
              },
          ]
        : []),
    ...(page.props.auth.abilities.manage_users
        ? [{ key: "users", label: "Manajemen Pengguna", icon: "shield", url: page.props.routes.usersIndex }]
        : []),
    ...(page.props.auth.abilities.view_reference_masters
        ? [
              { key: "opening-balances", label: "Saldo Awal", icon: "upload", url: page.props.routes.openingBalancesIndex },
              { key: "stock-corrections", label: "Koreksi Stok", icon: "shield", url: page.props.routes.stockCorrectionsIndex },
              { key: "shifts", label: "Pengaturan Shift", icon: "layers", url: page.props.routes.shiftsIndex },
          ]
        : []),
].filter((module) => page.props.auth.abilities.view_reference_masters || !["categories", "units", "locations"].includes(module.key)));
</script>
<template>
    <div class="sidebar-content">
        <div class="sidebar-brand">
            <span class="sidebar-brand__mark"
                ><AppIcon name="warehouse"
            /></span>
            <div>
                <span class="sidebar-brand__name">WAREHOUSE</span
                ><span class="sidebar-brand__sub">SYSTEM</span>
            </div>
        </div>
        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <p class="sidebar-section-label">AREA KERJA</p>
            <div class="nav-group-label">
                <AppIcon name="layers" /><span>Master Data</span
                ><AppIcon name="chevronDown" />
            </div>
            <div class="nav-children">
                <Link
                    v-for="module in modules"
                    :key="module.key"
                    :href="module.url"
                    class="nav-item"
                    :class="{
                        'nav-item--active':
                            page.props.navigation.current === module.key,
                    }"
                    :aria-current="
                        page.props.navigation.current === module.key
                            ? 'page'
                            : undefined
                    "
                    @click="emit('navigate')"
                    ><span class="nav-item__icon"
                        ><AppIcon :name="module.icon" /></span
                    ><span>{{ module.label }}</span
                    ><span
                        v-if="page.props.navigation.current === module.key"
                        class="nav-item__active-dot"
                /></Link>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-access">
                <AppIcon name="shield" /><span>Akses internal</span>
            </div>
            <div class="sidebar-user">
                <span class="avatar">{{ initials }}</span>
                <div class="sidebar-user__identity">
                    <span :title="user.name">{{ user.name }}</span
                    ><small>{{ user.role_label }}</small>
                </div>
            </div>
            <Link
                :href="page.props.routes.logout"
                method="post"
                as="button"
                class="sidebar-logout"
                ><AppIcon name="logout" /><span>Keluar</span></Link
            >
        </div>
    </div>
</template>
