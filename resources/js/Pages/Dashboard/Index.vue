<script setup>
import { ref, onMounted } from 'vue';
import DBService from '@/Service/Utils/DBService.js';

const loading        = ref(false);
const dashboard_data = ref({});

onMounted(fetchDashboardData);

function fetchDashboardData() {
    
}
</script>

<template>
    <PageHeader
        title="Dashboard"
        subtitle="Registrations overview"
        :show-search="false"
    />

    <Content>
        
    </Content>
</template>
