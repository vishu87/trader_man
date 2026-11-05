<script setup>
import { ref, computed } from "vue";
import axios from "axios";

const { label, error, temp = 0, cls='' } = defineProps(['label','error','temp','cls'])
const s3_url_link = import.meta.env.VITE_S3_URL
const model = defineModel() 

const file = ref(null);
const uploading = ref(false);

const fileName = computed(() => file.value?.name);
const fileExtension = computed(() => fileName.value?.substr(fileName.value?.lastIndexOf(".") + 1));
const fileMimeType = computed(() => file.value?.type);

function onFileChanged(event) {
    const target = event.target;
    if (target && target.files) {
        file.value = target.files[0];
        submitFile();
    }
}

const submitFile = async () => {
    let formData = new FormData();
    formData.append('file', file.value);
    uploading.value = true;
    try {
        const endpoint = base_url + "/uploads/file?temp=" + temp;
        const response = await axios({
            method: "post",
            url: endpoint,
            data: formData,
            headers: { "Content-Type": "multipart/form-data" }
        });
        console.log(response.data.path)
        model.value = response.data.path;
        uploading.value = false;
    } catch (error) {
        uploading.value = false;
    }
};
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label">{{ label }}</label>
        <div v-if="uploading">Uploading....</div>
        <div v-if="!uploading">
            <a class="btn btn-info btn-sm mx-1" v-if="model" :href="s3_url_link + model" target="_blank">View</a>
            <button class="btn btn-danger btn-sm" v-if="model" @click="model = ''">Remove</button>
            <!--- <input v-if="!model" type="file" id="fileElem" multiple accept="image/*" @change="onFileChanged($event)" capture />-->
            <input v-if="!model" type="file" id="fileElem" @change="onFileChanged($event)" capture />
        </div>
    </div>
</template>