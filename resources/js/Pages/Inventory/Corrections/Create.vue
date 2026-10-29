<script setup>
import { computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import FormField from "../../../Components/FormField.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ store_url: String, index_url: String, items: Array, locations: Array, vendor_items: Array });
const form = useForm({ item_id: "", vendor_item_id: "", location_id: "", direction: "IN", quantity: "", reason: "" });
const item = computed(() => props.items.find((value) => value.id === form.item_id));
const matchingVendors = computed(() => props.vendor_items.filter((value) => value.item_id === form.item_id && (!form.location_id || value.location_id === form.location_id)));
const submit = () => form.post(props.store_url);
</script>

<template>
    <Head title="Ajukan Koreksi Stok" />
    <div class="units-page">
        <PageBanner title="Ajukan Koreksi Stok" description="Masukkan selisih yang ditemukan. Draft tidak langsung mengubah stok." icon="shield" />
        <section class="surface-card form-card"><form class="form-card__body form-stack" @submit.prevent="submit">
            <FormField id="correction-item" label="Barang" :error="form.errors.item_id"><select id="correction-item" v-model="form.item_id" class="form-input" required><option value="">Pilih barang</option><option v-for="value in items" :key="value.id" :value="value.id">{{ value.item_code }} · {{ value.item_name }}</option></select></FormField>
            <FormField id="correction-location" label="Lokasi" :error="form.errors.location_id"><select id="correction-location" v-model="form.location_id" class="form-input" required><option value="">Pilih lokasi</option><option v-for="value in locations" :key="value.id" :value="value.id">{{ value.location_code }} · {{ value.location_name }}</option></select></FormField>
            <FormField v-if="item?.ownership_type === 'CONSIGNMENT'" id="correction-vendor" label="Vendor" :error="form.errors.vendor_item_id"><select id="correction-vendor" v-model="form.vendor_item_id" class="form-input" required><option value="">Pilih Vendor</option><option v-for="value in matchingVendors" :key="value.id" :value="value.id">{{ value.vendor.vendor_code }} · {{ value.vendor.vendor_name }}</option></select></FormField>
            <div class="form-grid"><FormField id="correction-direction" label="Arah koreksi"><select id="correction-direction" v-model="form.direction" class="form-input"><option value="IN">Tambah (IN)</option><option value="OUT">Kurang (OUT)</option></select></FormField><FormField id="correction-quantity" label="Quantity" :error="form.errors.quantity"><input id="correction-quantity" v-model="form.quantity" class="form-input" type="number" min="0.0001" max="9999999999.9999" step="0.0001" required /></FormField></div>
            <FormField id="correction-reason" label="Alasan dan bukti" :error="form.errors.reason"><textarea id="correction-reason" v-model="form.reason" class="form-input" rows="4" required /></FormField>
            <div class="form-actions"><Link :href="index_url" class="button button--secondary">Batal</Link><button type="submit" class="button button--primary" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan Draft' }}</button></div>
        </form></section>
    </div>
</template>
