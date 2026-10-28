<script setup>
import AppLayout from "../../../Layouts/AppLayout.vue";
import ReferenceManager from "../../../Components/ReferenceManager.vue";
import { references } from "../../../Support/references";
defineOptions({ layout: AppLayout });
defineProps({
    resource: String,
    records: Object,
    filters: Object,
    summary: Object,
    can: Object,
    store_url: String,
});
</script>
<template>
    <ReferenceManager
        :key="resource"
        :definition="references[resource]"
        :records="records"
        :filters="filters"
        :summary="summary"
        :can="can"
        :store-url="store_url"
    />
</template>
