<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import AppIcon from "../../Components/AppIcon.vue";
import PageBanner from "../../Components/PageBanner.vue";
import Pagination from "../../Components/Pagination.vue";
import EmptyState from "../../Components/ui/EmptyState.vue";
import StatusPill from "../../Components/ui/StatusPill.vue";
defineOptions({ layout: AppLayout });
defineProps({ refills: Object, can_create: Boolean, create_url: String });
</script>

<template>
    <Head title="Vendor Refill" />
    <div class="units-page">
        <PageBanner title="Vendor Refill" description="Pantau permintaan, revisi, pengiriman, dan penerimaan Vendor." icon="truck" />
        <section class="surface-card">
            <div class="table-card-heading">
                <div>
                    <h2>Daftar refill</h2>
                    <p>Semua proses refill yang dapat Anda akses.</p>
                </div>
                <Link v-if="can_create" :href="create_url" class="button button--primary"><AppIcon name="plus" /> Buat Refill</Link>
            </div>
            <div v-if="refills.data.length" class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Nomor</th><th>Vendor</th><th>Tanggal</th><th>Status</th><th><span class="sr-only">Aksi</span></th></tr></thead>
                    <tbody>
                        <tr v-for="refill in refills.data" :key="refill.id">
                            <td><strong>{{ refill.number }}</strong></td>
                            <td>{{ refill.vendor }}</td>
                            <td>{{ refill.requested_at }}</td>
                            <td>
                                <StatusPill :status="refill.status" />
                                <span v-if="refill.follow_up_open" class="status-badge status-badge--warning"><span class="status-badge__dot" aria-hidden="true" />Perlu ditindaklanjuti</span>
                            </td>
                            <td class="numeric"><Link :href="refill.show_url" class="row-action">Buka</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="truck" title="Belum ada refill" hint="Permintaan refill ke Vendor akan muncul di sini." />
            <Pagination :paginator="refills" noun="refill" />
        </section>
    </div>
</template>
