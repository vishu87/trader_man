<script setup>
  const { title='', id, size="" } = defineProps(['title','id','size'])

  function dataDismiss(){
    $('#' + id).modal('hide');
  }
</script>

<template>
  <div class="modal fade" :id="id" tabindex="-1">
    <div :class="`modal-dialog ${size}`">
      <div class="modal-content">
        <div class="modal-header" v-if="title">
          <h4 class="modal-title">{{title}}</h4>
          <button type="button" class="close btn" data-dismiss="modal" @click.prevent="dataDismiss()"><i class="icon-close" style="font-size: 140%;"></i></button>
        </div>
        <div class="modal-body">
          <slot></slot>
        </div>
      </div>
    </div>
  </div>
</template>