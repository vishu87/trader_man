<script setup>
    import { ref, onMounted, watch } from 'vue'
    const { filters, showTotal=true, itemName="", } = defineProps(['filters','showTotal','itemName'])
    const page_no = ref([])

    defineEmits(['set-page'])

    onMounted(() => {
        setPagination();
    })

    watch(
        () => filters.page_no,
        () => {
            setPagination();
        }
    )

    function setPagination() {
        var pages = [];
        if(filters.page_no == 1){
            pages.push(1);    
            pages.push(2);
            if(filters.max_page > 2) pages.push(3);    
        } else {
            if(filters.max_page == filters.page_no && filters.max_page > 2){
                pages.push(filters.page_no - 2);
            }
            pages.push(filters.page_no - 1);    
            pages.push(filters.page_no);
            if(filters.max_page != filters.page_no){
                pages.push(filters.page_no + 1);
            }
        }
        page_no.value = pages
    }
    
</script>

<template>
        <div class="text-muted fs-13" v-if="filters.max_page > 0 && showTotal">Showing <strong>{{filters.max_per_page*(filters.page_no-1) + 1}} - {{filters.max_per_page*filters.page_no < filters.total ? filters.max_per_page*filters.page_no : filters.total}}</strong> of <strong>{{filters.total}}</strong> {{itemName}}
        </div>
        <nav>
            <ul class="pagination mb-0" v-if="filters.max_page > 1">
                <li class="page-item">
                    <a class="page-link" href="javascript:;" @click="$emit('set-page',1)"> <i class="bi bi-chevron-double-left"></i> </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="javascript:;" @click="$emit('set-page',filters.page_no - 1)"> <i class="bi bi-chevron-left"></i> </a>
                </li>
                <li class="page-item" :class="{'active' : page_no == filters.page_no}" v-for="page_no in page_no">
                    <a class="page-link" href="javascript:;" @click="$emit('set-page',page_no)">{{page_no}}</a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="javascript:;" @click="$emit('set-page',filters.page_no + 1)"> <i class="bi bi-chevron-right"></i> </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="javascript:;" @click="$emit('set-page',filters.max_page)">  <i class="bi bi-chevron-double-right"></i> </a>
                </li>
            </ul>
        </nav>
</template>