<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
defineOptions({ layout: AppLayout });
defineProps({ session: Object, lines: Array, back_url: String, printed_at: String });
const scopeLabel = { FULL: "Full", PARTIAL: "Parsial", CYCLE: "Cycle count" };
const stateLabel = { BALANCE: "Sesuai", SHORT: "Kurang", OVER: "Lebih" };
const print = () => window.print();
</script>

<template>
    <Head :title="`Laporan ${session.number}`" />
    <div class="units-page opname-report">
        <div class="form-actions opname-report__actions">
            <Link :href="back_url" class="button button--secondary">Kembali</Link>
            <button type="button" class="button button--primary" @click="print">Cetak</button>
        </div>
        <article class="surface-card opname-report__sheet">
            <header>
                <h1>Laporan Stock Opname</h1>
                <p><strong>{{ session.number }}</strong> · {{ scopeLabel[session.scope] }}<span v-if="session.cycle_month"> · {{ session.cycle_month }}</span> · {{ session.status }}</p>
                <p v-if="session.note">{{ session.note }}</p>
                <p class="opname-report__meta">Dicetak {{ printed_at }}</p>
            </header>
            <div class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Barang</th><th>Vendor</th><th>Lokasi</th><th>Sistem</th><th>Hitung</th><th>Selisih</th><th>Status</th></tr></thead>
                    <tbody>
                        <template v-for="(line, index) in lines" :key="index">
                            <tr>
                                <td>{{ line.item }}</td><td>{{ line.vendor || "Stok internal" }}</td><td>{{ line.location }}</td>
                                <td>{{ line.system_quantity ?? "—" }}</td><td>{{ line.counted_quantity ?? "—" }}</td><td>{{ line.difference ?? "—" }}</td><td>{{ line.state ? stateLabel[line.state] : "—" }}</td>
                            </tr>
                            <tr v-if="line.movements.length">
                                <td colspan="7"><strong>Perubahan stok setelah dihitung</strong>
                                    <ul class="movement-log"><li v-for="move in line.movements" :key="move.transaction_no">{{ move.posted_at }} · {{ move.type }} · {{ move.quantity }} · {{ move.transaction_no }}</li></ul>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <footer class="opname-report__signatures">
                <div v-for="(role, key) in { creator: 'Dibuat oleh', counter: 'Dihitung oleh', approver: 'Disetujui oleh' }" :key="key">
                    <span>{{ role }}</span>
                    <strong>{{ session[key]?.name ?? "—" }}</strong>
                    <small>{{ session[key]?.position ?? "" }}</small>
                </div>
            </footer>
        </article>
    </div>
</template>
