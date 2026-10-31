<script setup>
import { ref } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";
import StatusBadge from "../../Components/StatusBadge.vue";
import Pagination from "../../Components/Pagination.vue";
import BaseDialog from "../../Components/BaseDialog.vue";
import AppIcon from "../../Components/AppIcon.vue";
import { useListFilters, withCurrentQuery } from "../../Composables/useListFilters";
defineOptions({ layout: AppLayout });
const props = defineProps({ users: Object, filters: Object, options: Object, create_url: String });
const page = usePage();
const { search, filtering, schedule, apply, reset, sort } = useListFilters(props, () => page.props.routes.usersIndex, { sort: "name", role: "all", vendor: "" });
const target = ref(null);
const statusForm = useForm({ is_active: false });
function openStatus(user) {
    target.value = user;
    statusForm.clearErrors();
    statusForm.is_active = !user.is_active;
}
function saveStatus() {
    if (statusForm.processing) return;
    statusForm.patch(withCurrentQuery(target.value.status_url), { preserveScroll: true, onSuccess: () => target.value = null });
}
</script>

<template>
    <Head title="Manajemen Pengguna" />
    <div class="units-page">
        <PageBanner title="Manajemen Pengguna" description="Atur akses Admin, Warehouse, dan Vendor serta pertahankan riwayat akun." icon="shield" />
        <section class="surface-card" aria-labelledby="users-heading">
            <div class="table-card-heading"><div><h2 id="users-heading">Daftar pengguna <span class="count-badge">{{ users.total }}</span></h2><p>Kelola identitas dan status akses pengguna.</p></div><Link :href="create_url" class="button button--primary"><AppIcon name="plus" />Tambah Pengguna</Link></div>
            <div class="table-toolbar"><div class="search-input"><AppIcon name="search" /><label for="user-search" class="sr-only">Cari pengguna</label><input id="user-search" v-model="search.q" type="search" placeholder="Cari nama, email, atau nomor karyawan…" maxlength="100" @input="schedule" @keydown.enter.prevent="apply" /><span v-if="filtering" class="spinner" aria-label="Memuat pengguna" /></div></div>
            <div class="item-filters">
                <div class="filter-control"><label for="role-filter">Filter role pengguna</label><select id="role-filter" v-model="search.role" class="form-select" @change="apply"><option value="all">Semua role</option><option v-for="role in options.roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></div>
                <div class="filter-control"><label for="status-filter">Filter status pengguna</label><select id="status-filter" v-model="search.status" class="form-select" @change="apply"><option value="all">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
                <div class="filter-control"><label for="vendor-filter">Filter Vendor pengguna</label><select id="vendor-filter" v-model="search.vendor" class="form-select" @change="apply"><option value="">Semua Vendor</option><option v-for="vendor in options.vendors" :key="vendor.id" :value="vendor.id">{{ vendor.vendor_code }} — {{ vendor.vendor_name }}</option></select></div>
            </div>
            <div class="table-meta"><button type="button" class="text-button" @click="reset">Reset filter</button><label class="per-page-label" for="user-per-page">Tampilkan<select id="user-per-page" v-model.number="search.per_page" @change="apply"><option :value="10">10</option><option :value="25">25</option><option :value="50">50</option></select><span>per halaman</span></label></div>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Tabel pengguna, dapat digeser horizontal" :aria-busy="filtering">
                <table class="data-table"><caption class="sr-only">Pengguna aplikasi</caption><thead><tr><th scope="col"><button class="table-sort" type="button" @click="sort('name')">Nama</button></th><th scope="col"><button class="table-sort" type="button" @click="sort('email')">Email</button></th><th scope="col">Role</th><th scope="col">Vendor</th><th scope="col">Nomor karyawan / jabatan</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead><tbody>
                    <tr v-for="user in users.data" :key="user.id"><td>{{ user.name }}</td><td>{{ user.email }}</td><td>{{ user.role_label }}</td><td>{{ user.vendor_name || '—' }}</td><td>{{ user.employee_no || '—' }}<small class="usage-note">{{ user.position }}</small></td><td><StatusBadge :active="user.is_active" /></td><td><div class="row-actions"><Link :href="user.edit_url" class="row-action" :aria-label="`Edit pengguna ${user.name}`"><AppIcon name="pencil" />Edit</Link><button type="button" class="row-action" :aria-label="`${user.is_active ? 'Nonaktifkan' : 'Aktifkan'} pengguna ${user.name}`" @click="openStatus(user)"><AppIcon name="power" />{{ user.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></div></td></tr>
                </tbody></table>
            </div>
            <div v-if="!users.data.length" class="empty-state"><h3>{{ search.q || search.role !== 'all' || search.status !== 'all' || search.vendor ? 'Pengguna tidak ditemukan' : 'Belum ada pengguna' }}</h3><p>Coba kata kunci lain atau ubah filter.</p><button type="button" class="button button--secondary" @click="reset">Reset filter</button></div>
            <Pagination :paginator="users" noun="pengguna" />
        </section>
    </div>
    <BaseDialog :open="Boolean(target)" :title="target?.is_active ? 'Nonaktifkan Pengguna?' : 'Aktifkan Pengguna?'" :busy="statusForm.processing" @close="!statusForm.processing && (target = null)">
        <form v-if="target" @submit.prevent="saveStatus"><div class="dialog__body"><div class="confirmation-record"><strong>{{ target.name }}</strong><span>{{ target.email }}</span></div><p class="confirmation-description">{{ target.is_active ? 'Akun akan dinonaktifkan. Identitas dan riwayat pengguna tetap disimpan.' : 'Akun akan diaktifkan kembali dengan role dan Vendor yang tersimpan.' }}</p><p v-for="(error, key) in statusForm.errors" :key="key" class="field-error" role="alert">{{ error }}</p></div><footer class="dialog__footer"><button type="button" class="button button--secondary" data-autofocus :disabled="statusForm.processing" @click="target = null">Batal</button><button type="submit" class="button" :class="target.is_active ? 'button--danger' : 'button--primary'" :disabled="statusForm.processing">{{ statusForm.processing ? 'Menyimpan…' : target.is_active ? 'Nonaktifkan Pengguna' : 'Aktifkan Pengguna' }}</button></footer></form>
    </BaseDialog>
</template>
