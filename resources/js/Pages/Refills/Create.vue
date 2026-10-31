<script setup>
import { computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import AppIcon from "../../Components/AppIcon.vue";
import FormField from "../../Components/FormField.vue";
import PageBanner from "../../Components/PageBanner.vue";
import EmptyState from "../../Components/ui/EmptyState.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ vendors: Array, store_url: String, index_url: String });
const form = useForm({ vendor_id: "", notes: "", lines: [] });
const vendor = computed(() => props.vendors.find((entry) => entry.id === form.vendor_id));
const itemOf = (row) => vendor.value?.items.find((entry) => entry.id === row.item_id);
const select = () => {
    form.lines = (vendor.value?.items || []).filter((entry) => Number(entry.suggestion) > 0).map((entry) => ({ enabled: true, item_id: entry.id, quantity: entry.suggestion }));
};
const submit = () => form.transform((data) => ({ ...data, lines: data.lines.filter((row) => row.enabled).map(({ item_id, quantity }) => ({ item_id, quantity })) })).post(props.store_url);
</script>

<template>
    <Head title="Buat Refill" />
    <div class="units-page">
        <PageBanner title="Buat Vendor Refill" description="Saran sudah mengurangi stok tersedia dan refill yang masih berjalan." icon="truck" />
        <section class="surface-card form-card">
            <form class="form-card__body form-stack" @submit.prevent="submit">
                <div v-if="Object.keys(form.errors).length" class="field-error" role="alert">
                    <strong>Refill belum dapat dibuat.</strong>
                    <ul><li v-for="(message, key) in form.errors" :key="key">{{ message }}</li></ul>
                </div>
                <FormField id="refill-vendor" label="Vendor" required>
                    <select id="refill-vendor" v-model="form.vendor_id" class="form-input" required @change="select">
                        <option value="">Pilih Vendor</option>
                        <option v-for="entry in vendors" :key="entry.id" :value="entry.id">{{ entry.code }} · {{ entry.name }}</option>
                    </select>
                </FormField>
                <div v-if="form.lines.length">
                    <h2>Barang yang disarankan</h2>
                    <p>Hilangkan centang pada barang yang tidak ikut diminta, atau ubah quantity-nya.</p>
                    <div class="section-stack" style="margin-top: 12px">
                        <div v-for="row in form.lines" :key="row.item_id" class="line-grid line-grid--refill" :class="{ 'is-off': !row.enabled }">
                            <label><input v-model="row.enabled" type="checkbox" /><span class="line-grid__title">{{ itemOf(row)?.code }}<small>{{ itemOf(row)?.name }}</small></span></label>
                            <input v-model="row.quantity" class="form-input" type="number" min="1" step="1" :disabled="!row.enabled" aria-label="Quantity" required />
                        </div>
                    </div>
                </div>
                <EmptyState v-else-if="vendor" icon="checkCircle" title="Tidak ada barang di bawah minimum" hint="Vendor ini tidak punya barang consignment yang perlu diisi ulang saat ini." />
                <FormField id="refill-notes" label="Catatan (opsional)"><textarea id="refill-notes" v-model="form.notes" class="form-input" rows="3" /></FormField>
                <div class="form-actions">
                    <Link :href="index_url" class="button button--secondary">Batal</Link>
                    <button class="button button--primary" :disabled="form.processing || !form.lines.some((row) => row.enabled)"><AppIcon name="truck" /> Kirim Refill</button>
                </div>
            </form>
        </section>
    </div>
</template>
