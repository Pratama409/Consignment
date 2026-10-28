<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import AppIcon from "../../../Components/AppIcon.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import SummaryCard from "../../../Components/SummaryCard.vue";
import ImportSteps from "../../../Components/ImportSteps.vue";
defineOptions({ layout: AppLayout });
defineProps({ batch: Object, result: Object, export_url: String });
const page = usePage();
</script>
<template>
    <Head title="Hasil Import" />
    <div class="units-page">
        <PageBanner
            title="Import Selesai"
            description="Data yang dikonfirmasi sudah tersimpan. Anda dapat melanjutkan pengelolaan melalui Master Barang."
            icon="checkCircle"
        /><ImportSteps :step="4" />
        <section class="summary-grid" aria-label="Hasil import">
            <SummaryCard
                label="Barang Baru"
                :value="result.created"
                description="Kode internal dibuat sistem"
                icon="box"
                tone="green"
            /><SummaryCard
                label="Barang Diperbarui"
                :value="result.updated"
                description="Identitas barang dipertahankan"
                icon="pencil"
            /><SummaryCard
                label="Baris Dilewati"
                :value="result.skipped_rows"
                description="Sesuai keputusan pada preview"
                icon="pause"
                tone="slate"
            />
        </section>
        <section class="surface-card form-card">
            <header class="form-card__heading">
                <div>
                    <h2>{{ batch.file_name }}</h2>
                    <p>
                        {{ result.source_rows }} baris sumber telah diproses
                        sesuai tinjauan.
                    </p>
                </div>
            </header>
            <div class="form-card__body form-stack">
                <p class="confirmation-description">
                    Gunakan hasil export yang menyertakan ITEM_CODE untuk
                    mencocokkan barang saat import berikutnya.
                </p>
                <div class="form-actions">
                    <Link
                        :href="page.props.routes.itemsIndex"
                        class="button button--primary"
                        >Lihat Master Barang <AppIcon name="arrowRight" /></Link
                    ><a :href="export_url" class="button button--secondary"
                        ><AppIcon name="arrowDown" /> Export Hasil</a
                    ><Link
                        :href="page.props.routes.importsIndex"
                        class="button button--secondary"
                        >Import File Lain</Link
                    >
                </div>
            </div>
            <div
                class="table-scroll"
                tabindex="0"
                role="region"
                aria-label="Barang hasil import"
            >
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Kode internal</th>
                            <th scope="col">Nama barang</th>
                            <th scope="col">Hasil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in result.items"
                            :key="`${item.id}-${item.action}`"
                        >
                            <td>
                                <Link
                                    :href="item.show_url"
                                    class="item-code-link"
                                    >{{ item.item_code }}</Link
                                >
                            </td>
                            <td>{{ item.item_name }}</td>
                            <td>
                                {{
                                    item.action === "created"
                                        ? "Ditambahkan"
                                        : "Diperbarui"
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="result.created + result.updated > 50"
                class="result-limit-note"
            >
                Menampilkan 50 barang pertama. Unduh Export Hasil untuk seluruh
                barang yang diproses.
            </p>
        </section>
    </div>
</template>
