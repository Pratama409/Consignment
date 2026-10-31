<script setup>
import { computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import FormField from "../../../Components/FormField.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ store_url: String, index_url: String, buckets: Array });
const form = useForm({ scope_type: "FULL", cycle_month: "", bucket_ids: [], note: "" });
const picksBuckets = computed(() => form.scope_type !== "FULL");
const holdsStock = computed(() => props.buckets.filter((bucket) => Number(bucket.quantity) > 0).length);
const submit = () => form.post(props.store_url);
</script>

<template>
    <Head title="Buat Sesi Stock Opname" />
    <div class="units-page">
        <PageBanner title="Buat Sesi Stock Opname" description="Tentukan jenis dan cakupan. Warehouse menghitung tanpa melihat stok sistem." icon="checkCircle" />
        <section class="surface-card form-card"><form class="form-card__body form-stack" @submit.prevent="submit">
            <FormField id="opname-scope" label="Jenis" :error="form.errors.scope_type">
                <select id="opname-scope" v-model="form.scope_type" class="form-input">
                    <option value="FULL">Full — semua posisi yang masih ada stok ({{ holdsStock }})</option>
                    <option value="PARTIAL">Parsial — pilih posisi stok</option>
                    <option value="CYCLE">Cycle count bulanan — pilih posisi stok</option>
                </select>
            </FormField>
            <FormField v-if="form.scope_type === 'CYCLE'" id="opname-month" label="Bulan cycle count" :error="form.errors.cycle_month"><input id="opname-month" v-model="form.cycle_month" class="form-input" type="month" required /></FormField>
            <FormField v-if="picksBuckets" id="opname-buckets" label="Posisi stok yang dihitung" :error="form.errors.bucket_ids">
                <div id="opname-buckets" class="table-scroll" tabindex="0">
                    <table class="data-table">
                        <thead><tr><th>Pilih</th><th>Barang</th><th>Vendor</th><th>Lokasi</th></tr></thead>
                        <tbody>
                            <tr v-for="bucket in buckets" :key="bucket.id">
                                <td><input v-model="form.bucket_ids" type="checkbox" :value="bucket.id" :aria-label="`Pilih ${bucket.item}`" /></td>
                                <td>{{ bucket.item }}</td><td>{{ bucket.vendor || "Stok internal" }}</td><td>{{ bucket.location }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </FormField>
            <FormField id="opname-note" label="Catatan" :error="form.errors.note"><textarea id="opname-note" v-model="form.note" class="form-input" rows="3" /></FormField>
            <div class="form-actions"><Link :href="index_url" class="button button--secondary">Batal</Link><button type="submit" class="button button--primary" :disabled="form.processing">{{ form.processing ? "Menyimpan…" : "Buat Sesi" }}</button></div>
        </form></section>
    </div>
</template>
