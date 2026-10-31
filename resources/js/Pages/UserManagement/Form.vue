<script setup>
import { nextTick } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";
import FormField from "../../Components/FormField.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ user: Object, options: Object, submit_url: String });
const page = usePage();
const form = useForm({
    name: props.user?.name ?? "",
    email: props.user?.email ?? "",
    password: "",
    role: props.user?.role ?? "warehouse",
    vendor_id: props.user?.vendor_id ?? "",
    employee_no: props.user?.employee_no ?? "",
    position: props.user?.position ?? "",
    is_active: props.user?.is_active ?? true,
});
function changeRole() {
    if (form.role !== "vendor") form.vendor_id = "";
    form.clearErrors("vendor_id");
}
function save() {
    if (form.processing) return;
    const visit = {
        preserveScroll: true,
        onFinish: async () => {
            if (!form.hasErrors) return;
            await nextTick();
            document.querySelector('[aria-invalid="true"]')?.focus();
        },
        onSuccess: () => form.reset("password"),
    };
    if (props.user) form.put(props.submit_url, visit);
    else form.post(props.submit_url, visit);
}
</script>

<template>
    <Head :title="user ? 'Edit Pengguna' : 'Tambah Pengguna'" />
    <div class="units-page">
        <PageBanner :title="user ? 'Edit Pengguna' : 'Tambah Pengguna'" description="Kelola identitas, hak akses, dan status akun pengguna." icon="shield" />
        <div class="page-actions"><Link :href="page.props.routes.usersIndex" class="back-link">Kembali ke daftar pengguna</Link></div>
        <form novalidate @submit.prevent="save">
            <fieldset class="item-form-fieldset" :disabled="form.processing">
                <section class="surface-card form-card">
                    <div class="table-card-heading"><div><h2>Identitas dan akses</h2><p>Akun dinonaktifkan tanpa menghapus riwayat pengguna.</p></div></div>
                    <div class="form-grid form-card__body">
                        <FormField id="user-name" label="Nama pengguna" :error="form.errors.name" required>
                            <input id="user-name" v-model="form.name" class="form-input" autocomplete="name" maxlength="255" :aria-invalid="Boolean(form.errors.name)" :aria-describedby="form.errors.name ? 'user-name-error' : undefined" />
                        </FormField>
                        <FormField id="user-email" label="Email pengguna" :error="form.errors.email" required>
                            <input id="user-email" v-model="form.email" type="email" class="form-input" autocomplete="off" maxlength="255" :aria-invalid="Boolean(form.errors.email)" :aria-describedby="form.errors.email ? 'user-email-error' : undefined" />
                        </FormField>
                        <FormField id="user-password" label="Kata sandi pengguna" :error="form.errors.password" :required="!user" :hint="user ? 'Kosongkan untuk mempertahankan kata sandi saat ini.' : 'Gunakan minimal 8 karakter.'">
                            <input id="user-password" v-model="form.password" type="password" class="form-input" autocomplete="new-password" :aria-invalid="Boolean(form.errors.password)" :aria-describedby="form.errors.password ? 'user-password-error' : 'user-password-hint'" />
                        </FormField>
                        <FormField id="user-role" label="Role pengguna" :error="form.errors.role" required>
                            <select id="user-role" v-model="form.role" class="form-select" :aria-invalid="Boolean(form.errors.role)" :aria-describedby="form.errors.role ? 'user-role-error' : undefined" @change="changeRole">
                                <option v-for="role in options.roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                            </select>
                        </FormField>
                        <FormField v-if="form.role === 'vendor'" id="vendor-id" label="Vendor pengguna" :error="form.errors.vendor_id" required hint="Akun hanya dapat membaca barang dan relasi Vendor ini.">
                            <select id="vendor-id" v-model="form.vendor_id" class="form-select" :aria-invalid="Boolean(form.errors.vendor_id)" :aria-describedby="form.errors.vendor_id ? 'vendor-id-error' : 'vendor-id-hint'">
                                <option value="">Pilih Vendor</option>
                                <option v-for="vendor in options.vendors" :key="vendor.id" :value="vendor.id">{{ vendor.vendor_code }} — {{ vendor.vendor_name }}{{ vendor.is_active ? '' : ' (nonaktif)' }}</option>
                            </select>
                        </FormField>
                        <FormField id="employee-no" label="Nomor karyawan" :error="form.errors.employee_no">
                            <input id="employee-no" v-model="form.employee_no" class="form-input" maxlength="50" :aria-invalid="Boolean(form.errors.employee_no)" :aria-describedby="form.errors.employee_no ? 'employee-no-error' : undefined" />
                        </FormField>
                        <FormField id="user-position" label="Jabatan" :error="form.errors.position">
                            <input id="user-position" v-model="form.position" class="form-input" maxlength="150" :aria-invalid="Boolean(form.errors.position)" :aria-describedby="form.errors.position ? 'user-position-error' : undefined" />
                        </FormField>
                        <FormField id="user-status" label="Status pengguna" :error="form.errors.is_active" required>
                            <select id="user-status" v-model="form.is_active" class="form-select" :aria-invalid="Boolean(form.errors.is_active)" :aria-describedby="form.errors.is_active ? 'user-status-error' : undefined"><option :value="true">Aktif</option><option :value="false">Nonaktif</option></select>
                        </FormField>
                    </div>
                </section>
            </fieldset>
            <footer class="page-form-footer"><Link :href="page.props.routes.usersIndex" class="button button--secondary">Batal</Link><button type="submit" class="button button--primary" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan Pengguna' }}</button></footer>
        </form>
    </div>
</template>
