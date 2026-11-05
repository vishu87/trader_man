<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';

const {name, type='text', modelValue, cls='', req=false, label='', placeholder='', options=[], opt_id='value', opt_name='label', select_name='Select', disabled=false, left_box_text=null, right_box_text=null, box_type='text'} = defineProps(['name','type','modelValue','cls','req','label','placeholder','options','opt_id','opt_name','select_name', 'disabled', 'left_box_text', 'right_box_text', 'box_type']); 

const processReq = computed(() => {
    if(!req){
      return null;
    } else {
        return yup.string().required(); 
    }
});

const { value, errorMessage } = useField(() => name, processReq, {
  syncVModel: true,
});
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label">{{label}} <span class="text-danger" v-if="req">*</span></label>
        <div v-if="left_box_text || right_box_text" class="input-group">
            <div v-if="left_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ left_box_text }}</span>
                <i :class="left_box_text" v-else></i>
            </div>

            <select class="form-control" v-model="value" :disabled="disabled">
                <option value="">{{ select_name }}</option>
                <option v-for="opt in options" :value="opt[opt_id]">{{ opt[opt_name] }}</option>
            </select>

            <div v-if="right_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ right_box_text }}</span>
                <i :class="right_box_text" v-else></i>
            </div>
        </div>
        <div v-else>
            <select class="form-control" v-model="value" :disabled="disabled">
                <option value="">{{ select_name }}</option>
                <option v-for="opt in options" :value="opt[opt_id]">{{ opt[opt_name] }}</option>
            </select>
        </div>
        <span class="text-danger" style="font-size: 11px">{{ errorMessage }}</span>
    </div>

</template>