<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'

const {name, type='text', modelValue, cls='', req=false, label='', validate = '', placeholder='', disabled=false, left_box_text=null, right_box_text=null, box_type='text'} = defineProps(['name','type','modelValue','cls','req','label','validate','placeholder', 'disabled', 'left_box_text', 'right_box_text', 'box_type']); 

const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();

  return `${day}-${month}-${year}`;
}

const processedReq = computed(() => {

    if(!req && !validate){
      return null;
    }

    let obj = yup.string();

    if(req){
        obj = obj.required();
    }

    if(validate == "email"){
        obj = obj.email();
    } else if(validate == "mobile"){
        obj = obj.matches(/^[0-9]{10,15}$/, {
            message: 'Please enter a valid mobile number',
            excludeEmptyString: true,
        });
    } else if(validate == "url"){
        obj = obj.url();
    }

    return obj;
});

const { value, errorMessage } = useField(() => name, processedReq, {
  syncVModel: true,
});
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label" >{{label}} <span class="text-danger" v-if="req">*</span></label>
        <div v-if="left_box_text || right_box_text" class="input-group">
            <div v-if="left_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ left_box_text }}</span>
                <i :class="left_box_text" v-else></i>
            </div>

                <input v-model="value" :type="type" class="form-control" v-if="type != 'date'" :disabled="disabled"  :placeholder="placeholder" />
        
                <VueDatePicker v-model="value" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" v-if="type == 'date'" :teleport="true" :disabled="disabled"></VueDatePicker>

            <div v-if="right_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ right_box_text }}</span>
                <i :class="right_box_text" v-else></i>
            </div>
        </div>
        <div v-else>
            <input v-model="value" :type="type" class="form-control" v-if="type != 'date'" :disabled="disabled"  :placeholder="placeholder" />
    
            <VueDatePicker v-model="value" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" v-if="type == 'date'" :teleport="true" :disabled="disabled"></VueDatePicker>
        </div>
        <span class="text-danger" style="font-size: 11px">{{ errorMessage }}</span>
    </div>
</template>
