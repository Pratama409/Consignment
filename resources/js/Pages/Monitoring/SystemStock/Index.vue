<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import Pagination from "../../../Components/Pagination.vue";
defineOptions({ layout: AppLayout });
defineProps({ rows: Object, filters: Object });
</script>
<template>
    <Head title="Stock Sistem" />
    <div class="units-page">
        <PageBanner
            title="Stock Sistem"
            description="Saldo aktual per pemilik, barang, dan lokasi dengan drill-down ledger serta lot."
            icon="warehouse"
        />
        <section class="surface-card">
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th>Jenis</th>
                            <th>Vendor</th>
                            <th>Lokasi</th>
                            <th>Saldo</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows.data" :key="row.id">
                            <td>{{ row.item }}<br />{{ row.part_number }}</td>
                            <td>{{ row.ownership }}</td>
                            <td>{{ row.vendor }}</td>
                            <td>{{ row.location }}</td>
                            <td><span v-if="row.counting">Sedang dihitung</span><template v-else>{{ row.quantity }} {{ row.unit }}</template></td>
                            <td>
                                <Link
                                    :href="`/monitoring/system-stock/${row.id}`"
                                    class="text-button"
                                    >Detail</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="rows" noun="bucket stok" />
        </section>
    </div>
</template>
