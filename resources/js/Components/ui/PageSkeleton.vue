<template>
    <div class="page-skeleton" role="status" aria-live="polite" aria-busy="true">
        <span class="sr-only">Memuat halaman…</span>
        <div class="skeleton page-skeleton__banner" />
        <div class="page-skeleton__row">
            <div v-for="n in 3" :key="n" class="skeleton page-skeleton__card" />
        </div>
        <div class="skeleton page-skeleton__table" />
    </div>
</template>
