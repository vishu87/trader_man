<script setup>
	import { ref } from 'vue'
	import { Cropper } from 'vue-advanced-cropper'
	import 'vue-advanced-cropper/dist/style.css'
	import DBService from '@/Service/Utils/DBService.js'
	const props = defineProps({
	    modalId: {
	        type: String,
	        default: 'change-picture-modal'
	    },
	    uploadUrl: {
	        type: String,
	        default: '/upload/photo'
	    },
	    updateUrl: {
	        type: String,
	        default: ''
	    },
	    entityId: {
	        type: Number,
	        default: 0
	    },
	    width: {
	        type: Number,
	        default: 300
	    },

	    height: {
	        type: Number,
	        default: 300
	    },

	    aspectRatio: {
	        type: Number,
	        default: 1
	    },

	    idField: {
	        type: String,
	        default: 'player_id'
	    },

	    fieldName: {
	        type: String,
	        default: 'picture'
	    },
	    modelValue: {
		    type: String,
		    default: ''
		}

	})

	const emit = defineEmits(['uploaded','update:modelValue'])
	const s3_url_link = import.meta.env.VITE_S3_URL
	const cropperRef = ref(null)
	const imageUrl = ref('')
	const processing = ref(false)
	const fileInput = ref(null)

	function openModal() {
	    $('#' + props.modalId).modal('show')
	}

	function closeModal() {
	    $('#' + props.modalId).modal('hide')
	}

	function onFileChange(event) {
	    const file = event.target.files[0]
	    if (!file) return
	    imageUrl.value = URL.createObjectURL(file)
	}

	function resetCropperState() {
	    imageUrl.value = ''
	    if (fileInput.value) {
	        fileInput.value.value = ''
	    }
	}

	function upload() {
	    const result = cropperRef.value?.getResult()
	    if (!result) return
	    const canvas = result.canvas
	    canvas.toBlob(async (blob) => {
	        const formData = new FormData()
	        formData.append('photo', blob, 'profile.jpg')
	        formData.append('resize', 1)
	        formData.append('crop', 1)
	        formData.append('width', props.width)
	        formData.append('height', props.height)
	        // formData.append('thumb', 1)
	        processing.value = true

	        try {
	            DBService.postData(props.uploadUrl, formData).then(function(data){
	                if(data.success){
						emit('uploaded', data)
						emit('update:modelValue', data.path)
	                    resetCropperState()
	                    closeModal()
	                } else {
						bootbox.alert(data.message)
	                }
					processing.value = false
	            })
	        } catch (error) {
	            console.error(error)
	            processing.value = false
	        }
	    }, 'image/jpeg')
	}

	function removeImage(){
    	emit('update:modelValue', '')
	}
</script>

<template>
	<div v-if="modalId == 'simple'">
	    <div class="avatar-upload-wrapper">
	        <input ref="fileInput" type="file" accept="image/*" class="hidden-file-input" @change="onFileChange" />
	        <div class="avatar-upload" :class="{ 'has-image': modelValue }" @click="fileInput.click()">
            	<template v-if="modelValue">
                	<img :src="s3_url_link + modelValue" class="avatar-preview"/>
            	</template>

            	<template v-else>
                	<i class="bi bi-camera"></i>
                	<span>Photo</span>
            	</template>
        	</div>
        	<button v-if="modelValue" type="button" class="remove-btn" @click="removeImage" >
            	<i class="bi bi-x"></i>
        	</button>
    	</div>
    	<div v-if="imageUrl" class="cropper-wrapper mt-3">
        	<Cropper ref="cropperRef" :src="imageUrl" :stencil-props="{aspectRatio: aspectRatio}" class="cropper" />
    	</div>
    	<div v-if="imageUrl" class="text-right mt-3">
	        <button	@click="upload"	class="btn btn-primary"	:disabled="processing">
	            {{ processing ? 'Uploading...' : 'Upload' }}
	        </button>
	        <button class="btn btn-light ms-2" @click="resetCropperState" >
	            Discard
	        </button>
		</div>
	</div>

    <Modal v-else title="Change Picture" :id="modalId">
        <input ref="fileInput" type="file" accept="image/*" @change="onFileChange"/>
        <div v-if="imageUrl" class="cropper-wrapper mt-3" >
            <Cropper ref="cropperRef" :src="imageUrl" :stencil-props="{aspectRatio: aspectRatio}" class="cropper"/>
        </div>
        <div v-if="imageUrl" class="text-right mt-3">
            <button @click="upload" class="btn btn-primary" :disabled="processing">{{ processing ? 'Uploading...' : 'Upload' }}</button>
            <button class="btn btn-light ms-2" @click="resetCropperState">Discard</button>
        </div>
    </Modal>
</template>

<style scoped>
	.cropper-wrapper {
	    width: 100%;
	    max-width: 500px;
	    height: 400px;
	    margin: auto;
	}

	.cropper {
	    width: 100%;
	    height: 400px;
	    background: #f8f8f8;
	}

	.hidden-file-input{
	    display:none;
	}

	.avatar-upload-wrapper{
	    position:relative;
	    width:110px;
	    height:110px;
	}

	.avatar-upload{
	    width:110px;
	    height:110px;
	    border-radius:12px;
	    border:2px dashed #d6d6d6;
	    display:flex;
	    flex-direction:column;
	    align-items:center;
	    justify-content:center;
	    font-size:10px;
	    color:#8b8b8b;
	    cursor:pointer;
	    transition:.2s;
	    gap:4px;
	    overflow:hidden;
	    background:#fafafa;
	}

	.avatar-upload::after{
	    position:absolute;
	    inset:0;
	    background:rgba(0,0,0,.45);
	    color:#fff;
	    display:flex;
	    align-items:center;
	    justify-content:center;
	    opacity:0;
	    transition:.2s;
	    font-size:12px;
	    font-weight:600;
	}

	.avatar-upload.has-image::after{
	    content:'Change Photo';
	}

	.avatar-upload.has-image:hover::after{
	    opacity:1;
	}

	.avatar-upload:hover{
	    border-color:#2563eb;
	    background:#eff4ff;
	    color:#2563eb;
	}

	.avatar-upload i{
	    font-size:20px;
	}

	.avatar-preview{
	    width:100%;
	    height:100%;
	    object-fit:cover;
	}

	.remove-btn{
	    position:absolute;
	    top:-6px;
	    right:-6px;
	    width:20px;
	    height:20px;
	    border:none;
	    border-radius:50%;
	    background:#ef4444;
	    color:#fff;
	    font-size:12px;
	    display:flex;
	    align-items:center;
	    justify-content:center;
	    cursor:pointer;
	}

</style>