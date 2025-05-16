<template>
    <div class="modal modal-open">
        <div class="modal-box bg-base-100">
            <h3 class="font-bold text-lg">Создать пользователя</h3>

            <input v-model="form.name" class="input input-bordered w-full mt-3" placeholder="Имя"/>
            <input v-model="form.email" class="input input-bordered w-full mt-3" placeholder="E-mail"/>
            <input v-model="form.password" type="password" class="input input-bordered w-full mt-3" placeholder="Пароль"/>

            <label class="label mt-3"><span class="label-text">Роли</span></label>
            <div class="flex flex-wrap gap-2">
                <label v-for="r in roles" :key="r" class="flex items-center gap-2">
                    <input type="checkbox" :value="r" v-model="form.roles" class="checkbox checkbox-sm" />
                    {{ r }}
                </label>
            </div>

            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button class="btn btn-primary" @click="create" :disabled="!canSave">Создать</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, computed } from 'vue'
import axios from 'axios'
const props = defineProps({ roles: {type:Array, default:()=>[]} })
const emit  = defineEmits(['close','created'])

const form = reactive({ name:'', email:'', password:'', roles: [] })
const canSave = computed(()=> form.name && form.email && form.password && form.roles.length)

function create(){
    axios.post('/admin/users',form)
        .then(res=>emit('created',res.data.user))
        .finally(()=>emit('close'))
}
</script>
