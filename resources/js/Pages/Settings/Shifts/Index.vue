<script setup>
import { nextTick } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import AppLayout from "../../../Layouts/AppLayout.vue";
import PageBanner from "../../../Components/PageBanner.vue";
import FormField from "../../../Components/FormField.vue";

defineOptions({ layout: AppLayout });
const props = defineProps({
    definitions: Array,
    versions: Array,
    timezone: String,
    can_manage: Boolean,
    next_date: String,
    submit_url: String,
});
function latestDefinition(code) {
    // The controller supplies version history newest first, including scheduled versions.
    return props.versions?.find((definition) => definition.code === code)
        ?? props.definitions.find((definition) => definition.code === code);
}

const day = latestDefinition("DAY");
const form = useForm({
    code: "DAY",
    name: day?.name ?? "Siang",
    start_time: day?.start_time ?? "06:00",
    end_time: day?.end_time ?? "18:00",
    effective_from: props.next_date ?? "",
});

function changeShift() {
    const definition = latestDefinition(form.code);
    if (definition) {
        form.name = definition.name;
        form.start_time = definition.start_time;
        form.end_time = definition.end_time;
    }
    form.clearErrors();
}

function save() {
    if (form.processing) return;
    form.post(props.submit_url, {
        preserveScroll: true,
        onSuccess: () => { form.effective_from = props.next_date; },
        onFinish: async () => {
            if (!form.hasErrors) return;
            await nextTick();
            document.querySelector('[aria-invalid="true"]')?.focus();
        },
    });
}
</script>

<template>
    <Head title="Pengaturan Shift" />
    <div class="units-page">
        <PageBanner title="Pengaturan Shift" description="Pengelompokan operasional menggunakan waktu WITA (Asia/Makassar)." icon="layers" />
        <section class="surface-card form-card" aria-label="Shift saat ini">
            <div class="table-card-heading"><div><h2>Shift saat ini</h2><p>Jadwal berlaku dalam WITA · {{ timezone }}</p></div></div>
            <div class="form-grid form-card__body">
                <div v-for="definition in definitions" :key="definition.id">
                    <h3>{{ definition.code }} · {{ definition.name }}</h3>
                    <p>{{ definition.start_time }} – {{ definition.end_time }} WITA{{ definition.code === 'NIGHT' ? ' (hari berikutnya)' : '' }}</p>
                </div>
            </div>
        </section>
        <template v-if="can_manage">
            <form novalidate @submit.prevent="save">
                <fieldset class="item-form-fieldset" :disabled="form.processing">
                    <section class="surface-card form-card" aria-labelledby="future-shift-heading">
                        <div class="table-card-heading"><div><h2 id="future-shift-heading">Jadwalkan versi mendatang</h2><p>Perubahan menyimpan versi baru. Riwayat shift tersimpan tetap dipertahankan.</p></div></div>
                        <div class="form-grid form-card__body">
                            <FormField id="shift-code" label="Kode shift" :error="form.errors.code" required>
                                <select id="shift-code" v-model="form.code" class="form-select" :aria-invalid="Boolean(form.errors.code)" :aria-describedby="form.errors.code ? 'shift-code-error' : undefined" @change="changeShift"><option value="DAY">DAY</option><option value="NIGHT">NIGHT</option></select>
                            </FormField>
                            <FormField id="shift-name" label="Nama shift" :error="form.errors.name" required>
                                <input id="shift-name" v-model="form.name" class="form-input" maxlength="100" :aria-invalid="Boolean(form.errors.name)" :aria-describedby="form.errors.name ? 'shift-name-error' : undefined" />
                            </FormField>
                            <FormField id="shift-start" label="Mulai shift" :error="form.errors.start_time" required hint="Jam WITA (24 jam).">
                                <input id="shift-start" v-model="form.start_time" type="time" class="form-input" :aria-invalid="Boolean(form.errors.start_time)" :aria-describedby="form.errors.start_time ? 'shift-start-error' : 'shift-start-hint'" />
                            </FormField>
                            <FormField id="shift-end" label="Selesai shift" :error="form.errors.end_time" required hint="Batas yang sama menjadi awal shift berikutnya.">
                                <input id="shift-end" v-model="form.end_time" type="time" class="form-input" :aria-invalid="Boolean(form.errors.end_time)" :aria-describedby="form.errors.end_time ? 'shift-end-error' : 'shift-end-hint'" />
                            </FormField>
                            <FormField id="shift-effective" label="Berlaku mulai" :error="form.errors.effective_from" required hint="Tanggal operasional WITA. Pilih setelah versi terakhir.">
                                <input id="shift-effective" v-model="form.effective_from" type="date" :min="next_date" class="form-input" :aria-invalid="Boolean(form.errors.effective_from)" :aria-describedby="form.errors.effective_from ? 'shift-effective-error' : 'shift-effective-hint'" />
                            </FormField>
                        </div>
                        <div class="form-card__body"><p>Jam DAY dan NIGHT disesuaikan bersama agar jadwal tetap berkesinambungan. Pada pergantian versi, NIGHT sebelumnya berakhir saat DAY baru dimulai. Pilih tanggal lebih jauh jika shift tersebut sudah tersimpan.</p></div>
                    </section>
                </fieldset>
                <footer class="page-form-footer"><button type="submit" class="button button--primary" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan Versi Mendatang' }}</button></footer>
            </form>
            <section class="surface-card" aria-labelledby="shift-versions-heading">
                <div class="table-card-heading"><div><h2 id="shift-versions-heading">Riwayat versi</h2><p>Definisi lama dan jadwal mendatang.</p></div></div>
                <div class="table-scroll" tabindex="0" role="region" aria-label="Tabel versi shift, dapat digeser horizontal">
                    <table class="data-table"><caption class="sr-only">Versi definisi shift WITA</caption><thead><tr><th scope="col">Berlaku mulai</th><th scope="col">Kode</th><th scope="col">Nama</th><th scope="col">Mulai WITA</th><th scope="col">Selesai WITA</th></tr></thead><tbody><tr v-for="version in versions" :key="version.id"><td>{{ version.effective_from }}</td><td>{{ version.code }}</td><td>{{ version.name }}</td><td>{{ version.start_time }}</td><td>{{ version.end_time }}{{ version.code === 'NIGHT' ? ' (+1 hari)' : '' }}</td></tr></tbody></table>
                </div>
            </section>
        </template>
        <p v-else>Pengaturan shift dikelola oleh Admin.</p>
    </div>
</template>
