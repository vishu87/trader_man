import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/inertia-vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import Header from '@/Components/Common/Header.vue';
import Portlet from '@/Components/Common/Portlet.vue';
import Content from '@/Components/Common/Content.vue';
import SubHeader from '@/Components/Common/SubHeader.vue';
import Modal from '@/Components/Common/Modal.vue';
import Loading from '@/Components/Common/Loading.vue';
import DateShow from '@/Components/Common/DateShow.vue';
import TimeShow from '@/Components/Common/TimeShow.vue';
import TableCont  from '@/Components/Common/TableCont.vue';
import Pagination from '@/Components/Common/Pagination.vue';
import InputField from '@/Components/Common/InputField.vue';
import InputText from '@/Components/Common/InputText.vue';
import Button2 from '@/Components/Common/Button2.vue';
import SelectField from '@/Components/Common/SelectField.vue';
import Editor from '@/Components/Common/Editor.vue';
import FileUpload from '@/Components/Common/FileUpload.vue';
import Link from '@/Components/Common/Link.vue';
import FileLink from '@/Components/Common/FileLink.vue';
import CheckBox from '@/Components/Common/CheckBox.vue';
import CheckBoxes from '@/Components/Common/CheckBoxes.vue';
import ViewHtml from '@/Components/Common/ViewHtml.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormText from '@/Components/Form/FormText.vue';
import FormButton from '@/Components/Form/FormButton.vue';
import Money from '@/Components/Common/Money.vue';
import RadioButtons from '@/Components/Common/RadioButtons.vue';
import PageHeader from '@/Components/Common/PageHeader.vue';
import Aside from '@/Components/Common/Aside.vue';
import FyYear from '@/Components/Common/FyYear.vue';

createInertiaApp({
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .component('Header', Header)
      .component('Portlet', Portlet)
      .component('Content', Content)
      .component('SubHeader', SubHeader)
      .component('Modal', Modal)
      .component('Loading', Loading)
      .component('TableCont', TableCont)
      .component('Pagination', Pagination)
      .component('InputField', InputField)
      .component('InputText', InputText)
      .component('FormButton', FormButton)
      .component('Button2', Button2)
      .component('SelectField', SelectField)
      .component('Editor', Editor)
      .component('DateShow', DateShow)
      .component('TimeShow', TimeShow)
      .component('FileUpload', FileUpload)
      .component('Link', Link)
      .component('FileLink', FileLink)
      .component('CheckBox', CheckBox)
      .component('CheckBoxes', CheckBoxes)
      .component('ViewHtml', ViewHtml)
      .component('FormInput', FormInput)
      .component('FormSelect', FormSelect)
      .component('FormText', FormText)
      .component('Money', Money)
      .component('RadioButtons', RadioButtons)
      .component('PageHeader', PageHeader)
      .component('Aside', Aside)
      .component('FyYear', FyYear)
      .mount(el);
  },
});