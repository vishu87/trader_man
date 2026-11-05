<script setup>
import { ref, computed } from "vue";
import axios from "axios";

const { temp = 0, ticket_id } = defineProps(['temp','ticket_id'])

const emit = defineEmits(['uploaded']);

// const file = ref(null);
const uploading = ref(false);

const isDragging = ref(false)
const fileInput = ref(null)
const selected_files = ref([])

const triggerFileSelect = () => {
  fileInput.value.click()
}

const handleFileChange = (e) => {
  const files = e.target.files
  for(var i = 0; i < files.length; i++){
    submitFile(files[i]);
    selected_files.value.push(files[i]);
  }
}

const handleDrop = (e) => {
  isDragging.value = false
  const files = e.dataTransfer.files
  for(var i = 0; i < files.length; i++){
    submitFile(files[i]);
    selected_files.value.push(files[i]);
  }
}

const submitFile = async (file) => {
    let formData = new FormData();
    formData.append('file', file);
    file.uploading = true;
    try {
        const endpoint = base_url + "/uploads/file?temp=" + temp;
        const response = await axios({
            method: "post",
            url: endpoint,
            data: formData,
            headers: { "Content-Type": "multipart/form-data" }
        });
        file.uploading = false;
        emit('uploaded', file.name, response.data.path);
        for(var i = 0; i < selected_files.value.length; i++){
          if(selected_files.value[i].name == file.name){
            selected_files.value.splice(i, 1); 
          }
        }
    } catch (error) {
        file.uploading = false;
    }
};
</script>

<template>
  {{ ticket_id }}
    <div 
        class="drop-box"
        id="dropBox"
        @click="triggerFileSelect"
        @dragover.prevent="isDragging = true"
        @dragleave="isDragging = false"
        @drop.prevent="handleDrop"
        :class="{ dragover: isDragging }">
        
        <p>Drag & drop a file here<br>or click to upload</p>

        <div v-if="selected_files.length > 0">
          <div v-for="file in selected_files">
            {{ file.name }} <span class="spinner-border text-primary" v-if="file.uploading"></span>
          </div>
        </div>

        <input type="file" ref="fileInput" @change="handleFileChange" hidden>

  </div>
</template>

<style type="text/css" scoped>
    .drop-box {
      border: 2px dashed #AAA;
      border-radius: 12px;
      padding: 40px;
      text-align: center;
      background-color: #fff;
      transition: background-color 0.3s;
      width: 100%;
      cursor: pointer;
      margin-bottom: 20px;
      background-color: #f5f5f5;
    }

    .drop-box.dragover {
      background-color: #e6f0ff;
    }

    .drop-box input {
      display: none;
    }

    .drop-box p {
      margin: 0;
      font-size: 14px;
      color: #333;
    }
</style>