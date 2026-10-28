<script setup>
import { nextTick, ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AppIcon from "../../Components/AppIcon.vue";
import FormField from "../../Components/FormField.vue";

const page = usePage();
const form = useForm({ email: "", password: "", remember: false });
const showPassword = ref(false);

function submit() {
    if (form.processing) return;
    form.post(page.props.routes.login, {
        onFinish: () => form.reset("password"),
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"]')?.focus();
        },
    });
}
</script>

<template>
    <Head title="Masuk" />
    <main class="login-page">
        <section class="login-story">
            <div class="login-brand">
                <span class="login-brand__mark"
                    ><AppIcon name="warehouse" /></span
                ><span>WAREHOUSE SYSTEM</span>
            </div>
            <div class="login-story__content">
                <p class="page-banner__eyebrow">CONSIGNMENT CONTROL CENTER</p>
                <h1>Data yang tertata.<br />Kerja yang lebih mudah.</h1>
                <p>
                    Kelola barang, Vendor, satuan, dan lokasi dalam satu tempat.
                </p>
                <div class="login-story__detail">
                    <AppIcon name="layers" /><span>Master Data</span
                    ><span class="login-story__line" /><AppIcon
                        name="ruler"
                    /><span>Satuan</span>
                </div>
            </div>
            <p class="login-story__footer">
                Warehouse System · Consignment Inventory
            </p>
        </section>
        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-card">
                <span class="login-card__icon"><AppIcon name="lock" /></span>
                <p class="section-eyebrow">AKSES INTERNAL</p>
                <h2 id="login-title">Selamat datang kembali</h2>
                <p class="login-card__description">
                    Masuk dengan akun Anda untuk melanjutkan.
                </p>
                <form class="login-form" novalidate @submit.prevent="submit">
                    <FormField
                        id="email"
                        label="Email"
                        :error="form.errors.email"
                        required
                        ><input
                            id="email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            class="form-input"
                            :class="{
                                'form-input--invalid': form.errors.email,
                            }"
                            autocomplete="username"
                            placeholder="nama@perusahaan.com"
                            required
                            autofocus
                            :aria-invalid="Boolean(form.errors.email)"
                            :aria-describedby="
                                form.errors.email ? 'email-error' : undefined
                            " /></FormField
                    ><FormField
                        id="password"
                        label="Kata sandi"
                        :error="form.errors.password"
                        required
                        ><div class="password-input">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                name="password"
                                class="form-input"
                                :class="{
                                    'form-input--invalid': form.errors.password,
                                }"
                                autocomplete="current-password"
                                placeholder="Masukkan kata sandi"
                                required
                                :aria-invalid="Boolean(form.errors.password)"
                                :aria-describedby="
                                    form.errors.password
                                        ? 'password-error'
                                        : undefined
                                "
                            /><button
                                type="button"
                                class="icon-button"
                                :aria-label="
                                    showPassword
                                        ? 'Sembunyikan kata sandi'
                                        : 'Tampilkan kata sandi'
                                "
                                :aria-pressed="showPassword"
                                @click="showPassword = !showPassword"
                            >
                                <AppIcon name="eye" />
                            </button></div></FormField
                    ><label class="remember-label"
                        ><input v-model="form.remember" type="checkbox" /> Ingat
                        saya</label
                    ><button
                        type="submit"
                        class="button button--primary login-submit"
                        :disabled="form.processing"
                    >
                        <span
                            v-if="form.processing"
                            class="spinner"
                            aria-hidden="true"
                        />{{
                            form.processing
                                ? "Memeriksa…"
                                : "Masuk ke aplikasi"
                        }}<AppIcon v-if="!form.processing" name="arrowRight" />
                    </button>
                </form>
                <p class="login-help">
                    <AppIcon name="shield" /> Belum punya akses? Hubungi
                    pengelola aplikasi.
                </p>
            </div>
        </section>
    </main>
</template>
