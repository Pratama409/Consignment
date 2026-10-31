<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import AppIcon from "../../Components/AppIcon.vue";
import PageBanner from "../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ batch: Object, can_activate: Boolean, activate_url: String, index_url: String });
const form = useForm({ idempotency_key: `opening:${props.batch.id}` });
const activate = () => { if (confirm("Aktifkan saldo awal? Setelah aktif data tidak dapat diedit.")) form.post(props.activate_url); };
</script>

<template>
    <Head title="Preview Saldo Awal" />
    <div class="units-page">
        <PageBanner title="Preview Saldo Awal" :description="`${batch.source} · cut-off ${batch.cutoff_at}`" icon="upload" />
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>{{ batch.status === 'PREVIEW' ? 'Belum mengubah stok' : 'Sudah diaktifkan' }}</h2><p>Periksa barang, kepemilikan, lokasi, dan quantity sebelum aktivasi.</p></div><span class="status-badge" :class="batch.status === 'ACTIVATED' ? 'status-badge--active' : ''">{{ batch.status }}</span></div>
            <p v-if="form.errors.activation" class="field-error" role="alert">{{ form.errors.activation }}</p>
            <div class="table-scroll" role="region" aria-label="Baris opening balance" tabindex="0"><table class="data-table"><thead><tr><th>Baris</th><th>Kode barang</th><th>Nama</th><th>Vendor</th><th>Lokasi</th><th>Quantity</th></tr></thead><tbody><tr v-for="line in batch.lines" :key="line.row_number"><td>{{ line.row_number }}</td><td>{{ line.item_code }}</td><td>{{ line.item_name }}</td><td>{{ line.vendor || 'Internal' }}</td><td>{{ line.location }}</td><td>{{ line.quantity }}</td></tr></tbody></table></div>
            <div class="form-actions"><Link :href="index_url" class="button button--secondary"><AppIcon name="arrowLeft" /> Kembali</Link><button v-if="can_activate" class="button button--primary" type="button" :disabled="form.processing" @click="activate">{{ form.processing ? 'Mengaktifkan…' : 'Aktifkan Saldo Awal' }}</button></div>
        </section>
    </div>
</template>
