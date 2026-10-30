<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PageBanner from "../../Components/PageBanner.vue";
import Pagination from "../../Components/Pagination.vue";
defineOptions({ layout: AppLayout });
defineProps({ requests: Object });
</script>
<template><Head title="Material Request"/><div class="units-page"><PageBanner title="Material Request" description="Antrean permintaan, persiapan, approval, dan serah terima barang." icon="box"/><section class="surface-card"><div class="table-scroll"><table class="data-table"><thead><tr><th>Nomor</th><th>Pemohon</th><th>Departemen</th><th>Dibutuhkan</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="request in requests.data" :key="request.id"><td><strong>{{ request.number }}</strong></td><td>{{ request.applicant }}</td><td>{{ request.department }}</td><td>{{ request.needed_at }}</td><td>{{ request.status }}</td><td><Link :href="request.show_url" class="text-button">Buka</Link></td></tr></tbody></table></div><p v-if="!requests.data.length" class="page-note">Belum ada Material Request.</p><Pagination :paginator="requests" noun="material request"/></section></div></template>
