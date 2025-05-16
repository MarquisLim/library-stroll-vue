<template>
    <div class="modal modal-open">
        <div class="modal-box bg-base-100">
            <h3 class="font-bold text-lg">Редактировать пользователя</h3>

            <input v-model="local.name"  class="input input-bordered w-full mt-3"/>
            <input v-model="local.email" class="input input-bordered w-full mt-3"/>
            <input v-model="local.password" type="password" placeholder="Новый пароль (оставьте пустым)"
                   class="input input-bordered w-full mt-3"/>

            <label class="label"><span class="label-text">Роли</span></label>
            <div class="flex flex-wrap gap-2 mt-2">
                <label v-for="r in roles" :key="r" class="flex items-center gap-2">
                    <input type="checkbox" :value="r" v-model="local.roles" class="checkbox checkbox-sm" />
                    {{ r }}
                </label>
            </div>

            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button class="btn btn-primary" @click="save" :disabled="!local.name||!local.email">Сохранить</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive, watch } from 'vue'
import axios from 'axios'
const props = defineProps({ user:Object, roles:Array })
const emit  = defineEmits(['close','saved'])

const local = reactive({
    name : props.user.name,
    email: props.user.email,
    password:'',
    roles: props.user.roles.map(r=>r.name),
})
watch(() => props.user, u=>{
    Object.assign(local,{
        name:u.name, email:u.email, password:'', role:u.roles[0]?.name || props.roles[0]
    })
})

function save(){
    axios.post(`/admin/users/${props.user.id}`, local)
        .then(()=>emit('saved'))
        .finally(()=>emit('close'))
}
</script>
