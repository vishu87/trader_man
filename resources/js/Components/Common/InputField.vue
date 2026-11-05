<script setup>
const { label="", error={}, req = false, type="text", editable = true, cls='', placeholder='', disabled=false} = defineProps(['label','error','req','type', 'editable','cls','placeholder','disabled'])
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'
const model = defineModel()

const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();

  return `${day}-${month}-${year}`;
}

</script>

<template>
    <div :class="`form-group ${cls}`" v-if="editable && type != 'date'">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <input :type="type" v-model="model" :placeholder="placeholder" class="form-control" :disabled="disabled"/>
        <span class="error" v-if="error.$error">{{ error.$errors[0].$message }}</span>
    </div>
    <div :class="`form-group ${cls}`" v-if="editable && type == 'date'">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <VueDatePicker v-model="model" :disabled="disabled" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" ></VueDatePicker>
        <span class="error" v-if="error.$error">{{ error.$errors[0].$message }}</span>
    </div>
    <div v-if="!editable">
        <span v-if="type != 'date'">{{model}}</span>
        <DateShow v-else :date="model" />
    </div>
</template>