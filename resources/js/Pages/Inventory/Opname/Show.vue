<script setup>
import { computed, reactive } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ session: Object, lines: Array, can: Object, urls: Object });
const page = usePage();
const errors = computed(() => page.props.errors || {});
const isAdmin = computed(() => props.lines.length > 0 && "system_quantity" in props.lines[0]);
const draft = reactive(Object.fromEntries(props.lines.map((line) => [line.id, line.counted_quantity ?? ""])));
const statusLabel = { COUNTING: "Sedang dihitung", COUNTED: "Menunggu persetujuan", RESOLVING: "Menyelesaikan selisih", COMPLETED: "Selesai", CANCELLED: "Dibatalkan" };
const stateLabel = { BALANCE: "Sesuai", SHORT: "Kurang", OVER: "Lebih" };
const stateClass = { BALANCE: "active", SHORT: "danger", OVER: "warning" };
const allCounted = computed(() => props.lines.every((line) => draft[line.id] !== "" && draft[line.id] !== null));
const save = (line) => router.put(line.count_url, { quantity: String(draft[line.id]) }, { preserveScroll: true });
const submit = () => confirm("Kirim hasil hitung ke Admin? Hasil tidak dapat diubah setelah dikirim.") && router.post(props.urls.submit);
const approve = () => confirm("Setujui hasil? Setiap selisih menjadi draft Koreksi Stok dan barangnya dibekukan.") && router.post(props.urls.approve);
const close = () => router.post(props.urls.close);
const cancel = () => { const reason = prompt("Alasan pembatalan"); if (reason) router.post(props.urls.cancel, { reason }); };
const recount = (line) => router.post(line.recount_url, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="session.number" />
    <div class="units-page">
        <PageBanner :title="session.number" :description="`${statusLabel[session.status]} · dibuat oleh ${session.creator}`" icon="checkCircle" />
        <div v-if="Object.keys(errors).length" class="field-error" role="alert" tabindex="-1"><strong>Tindakan belum dapat diproses.</strong><ul><li v-for="(message, field) in errors" :key="field">{{ message }}</li></ul></div>
        <div class="form-actions">
            <Link :href="urls.index" class="button button--secondary">Kembali</Link>
            <button v-if="can.submit" type="button" class="button button--primary" :disabled="!allCounted" @click="submit">Kirim hasil hitung</button>
            <button v-if="can.approve" type="button" class="button button--primary" @click="approve">Setujui hasil</button>
            <button v-if="can.close" type="button" class="button button--primary" @click="close">Tutup sesi</button>
            <button v-if="can.cancel" type="button" class="button button--danger" @click="cancel">Batalkan sesi</button>
        </div>
        <section class="surface-card">
            <div class="table-card-heading"><div><h2>Hasil hitung</h2><p v-if="!isAdmin">Masukkan jumlah fisik yang Anda hitung. Stok sistem tidak ditampilkan.</p><p v-else>Selisih = hitung dikurangi stok sistem saat hasil dikirim.</p></div></div>
            <div class="table-scroll" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Barang</th><th>Vendor</th><th>Lokasi</th><th>Hitung</th><template v-if="isAdmin"><th>Sistem</th><th>Selisih</th><th>Status</th><th>Koreksi</th></template></tr></thead>
                    <tbody>
                        <template v-for="line in lines" :key="line.id">
                            <tr>
                                <td>{{ line.item }}</td><td>{{ line.vendor || "Stok internal" }}</td><td>{{ line.location }}</td>
                                <td>
                                    <form v-if="can.count" class="inline-count" @submit.prevent="save(line)">
                                        <input v-model="draft[line.id]" class="form-input" type="number" min="0" step="0.0001" inputmode="decimal" :aria-label="`Hitung ${line.item}`" required />
                                        <button type="submit" class="button button--secondary">Simpan</button>
                                    </form>
                                    <span v-else>{{ line.counted_quantity ?? "—" }}</span>
                                </td>
                                <template v-if="isAdmin">
                                    <td>{{ line.system_quantity ?? "—" }}</td>
                                    <td>{{ line.difference ?? "—" }}</td>
                                    <td><span v-if="line.state" class="status-badge" :class="`status-badge--${stateClass[line.state]}`"><span class="status-badge__dot" aria-hidden="true" />{{ stateLabel[line.state] }}</span><span v-else>—</span></td>
                                    <td><span v-if="line.adjustment_status">{{ line.adjustment_status }}</span><button v-if="line.recount_url" type="button" class="text-button" @click="recount(line)">Hitung ulang</button><span v-if="!line.adjustment_status && !line.recount_url">—</span></td>
                                </template>
                            </tr>
                            <tr v-if="isAdmin && line.movements.length">
                                <td colspan="8"><strong>Perubahan stok setelah dihitung</strong>
                                    <ul class="movement-log"><li v-for="move in line.movements" :key="move.transaction_no">{{ move.posted_at }} · {{ move.type }} · {{ move.quantity }} · {{ move.transaction_no }}</li></ul>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
