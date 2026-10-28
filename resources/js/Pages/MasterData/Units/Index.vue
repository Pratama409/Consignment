<script setup>
import AppLayout from "../../../Layouts/AppLayout.vue";
import ReferenceManager from "../../../Components/ReferenceManager.vue";
import { references } from "../../../Support/references";
defineOptions({ layout: AppLayout });
defineProps({
    units: Object,
    filters: Object,
    summary: Object,
    can: Object,
    store_url: String,
});
</script>
<template>
    <ReferenceManager
        :definition="references.units"
        :records="units"
        :filters="filters"
        :summary="summary"
        :can="can"
        :store-url="store_url"
    />
</template>
