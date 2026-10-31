<script setup>
import { reactive } from "vue";
import { Head, router, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
defineOptions({ layout: AppLayout });
const props = defineProps({ reconciliation: Object, abilities: Object });
const page = usePage();
const response = useForm({ agree: true, note: "" });
const resolve = useForm({ note: "" });
const physical = reactive({});
const qty = (v) =>
    v === null || v === undefined
        ? "Belum diisi"
        : Number(v).toLocaleString("id-ID", { maximumFractionDigits: 0 });
</script>
<template>
    <Head :title="reconciliation.reconciliation_no" />
    <div class="units-page">
        <PageBanner
            :title="reconciliation.reconciliation_no"
            :description="`${reconciliation.vendor.vendor_name} · ${reconciliation.period_start} sampai ${reconciliation.period_end}`"
            icon="checkCircle"
        />
        <section class="surface-card">
            <div class="section-heading">
                <div>
                    <h2>Status {{ reconciliation.status }}</h2>
                    <p>Versi {{ reconciliation.version }}</p>
                </div>
                <div class="form-actions">
                    <button
                        v-if="abilities.submit_review"
                        class="button button--primary"
                        @click="
                            router.post(
                                `/monitoring/reconciliations/${reconciliation.id}/submit-review`,
                            )
                        "
                    >
                        Kirim ke Vendor
                    </button>
                    <a
                        :href="`/monitoring/reconciliations/${reconciliation.id}/export/xlsx`"
                        class="button button--secondary"
                        >Excel</a
                    ><a
                        :href="`/monitoring/reconciliations/${reconciliation.id}/export/pdf`"
                        class="button button--secondary"
                        >PDF</a
                    ><button
                        v-if="abilities.finalize"
                        class="button button--primary"
                        @click="
                            router.post(
                                `/monitoring/reconciliations/${reconciliation.id}/finalize`,
                            )
                        "
                    >
                        Finalisasi
                    </button>
                </div>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Barang / Lokasi</th>
                            <th>Awal</th>
                            <th>Masuk</th>
                            <th>Keluar</th>
                            <th>Sistem</th>
                            <th>Fisik</th>
                            <th>Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in reconciliation.lines" :key="line.id">
                            <td>
                                {{ line.item?.part_number }} ·
                                {{ line.item?.item_name }}<br />{{
                                    line.location?.location_name
                                }}
                            </td>
                            <td>{{ qty(line.opening_quantity) }}</td>
                            <td>{{ qty(line.in_quantity) }}</td>
                            <td>{{ qty(line.out_quantity) }}</td>
                            <td>{{ qty(line.system_closing_quantity) }}</td>
                            <td>
                                <span v-if="!abilities.edit_physical">{{
                                    qty(line.physical_quantity)
                                }}</span>
                                <div v-else class="form-actions">
                                    <input
                                        v-model.number="physical[line.id]"
                                        type="number"
                                        min="0"
                                        step="1"
                                        class="form-input"
                                    /><button
                                        class="text-button"
                                        @click="
                                            router.patch(
                                                `/monitoring/reconciliation-lines/${line.id}/physical`,
                                                { quantity: physical[line.id] },
                                                { preserveScroll: true },
                                            )
                                        "
                                    >
                                        Simpan
                                    </button>
                                </div>
                            </td>
                            <td>{{ qty(line.variance_quantity) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="surface-card form-stack">
            <h2>Respons dan penyelesaian</h2>
            <template v-if="abilities.respond"
                ><select v-model="response.agree" class="form-input">
                    <option :value="true">Setuju</option>
                    <option :value="false">Tidak setuju</option></select
                ><textarea
                    v-model="response.note"
                    class="form-input"
                    placeholder="Catatan vendor"
                /><button
                    class="button button--primary"
                    @click="
                        response.post(
                            `/monitoring/reconciliations/${reconciliation.id}/respond`,
                        )
                    "
                >
                    Kirim respons
                </button></template
            ><template v-if="abilities.resolve">
                <textarea
                    v-model="resolve.note"
                    class="form-input"
                    placeholder="Catatan penyelesaian selisih"
                /><button
                    class="button button--secondary"
                    @click="
                        resolve.post(
                            `/monitoring/reconciliations/${reconciliation.id}/resolve`,
                        )
                    "
                >
                    Selesaikan selisih
                </button></template
            >
            <ul>
                <li v-for="item in reconciliation.responses" :key="item.id">
                    {{ item.actor?.name }} · {{ item.decision }} ·
                    {{ item.note }}
                </li>
            </ul>
        </section>
    </div>
</template>
