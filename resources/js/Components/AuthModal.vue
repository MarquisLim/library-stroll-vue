<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center" @click.self="close">
            <div class="bg-gray-800 w-full max-w-md mx-4 rounded-xl p-6 text-white">
                <!-- ───── tabs -->
                <div class="flex mb-4 border-b border-gray-700">
                    <button class="flex-1 py-2 text-center"
                            :class="tab==='login' ? activeCls : idleCls"
                            @click="tab='login'">Вход</button>
                    <button class="flex-1 py-2 text-center"
                            :class="tab==='register' ? activeCls : idleCls"
                            @click="tab='register'">Регистрация</button>
                </div>

                <!-- ───── LOGIN -->
                <form v-if="tab==='login'" @submit.prevent="submitLogin">
                    <label class="block mb-3 text-sm">
                        Почта
                        <input v-model="login.email" type="email" required class="input"/>
                        <span v-if="loginErr.email" class="err">{{ loginErr.email[0] }}</span>
                    </label>

                    <label class="block mb-4 text-sm">
                        Пароль
                        <input v-model="login.password" type="password" required class="input"/>
                        <span v-if="loginErr.password" class="err">{{ loginErr.password[0] }}</span>
                    </label>

                    <div class="flex justify-between items-center mb-4 text-sm">
                        <button type="button" class="link" @click="tab='register'">Регистрация</button>
                        <a :href="route('password.request')" class="text-gray-400 hover:underline">Забыли пароль?</a>
                    </div>

                    <button type="submit" class="btn w-full" :disabled="loginLoading">Войти</button>
                </form>

                <!-- ───── REGISTER -->
                <form v-else @submit.prevent="submitRegister">
                    <label class="block mb-3 text-sm">
                        Имя
                        <input v-model="reg.name" type="text" required class="input"/>
                        <span v-if="regErr.name"  class="err">{{ regErr.name[0] }}</span>
                    </label>

                    <label class="block mb-3 text-sm">
                        Почта
                        <input v-model="reg.email" type="email" required class="input"/>
                        <span v-if="regErr.email" class="err">{{ regErr.email[0] }}</span>
                    </label>

                    <label class="block mb-3 text-sm">
                        Пароль
                        <input v-model="reg.password" type="password" required class="input"/>
                        <span v-if="regErr.password" class="err">{{ regErr.password[0] }}</span>
                    </label>

                    <label class="block mb-4 text-sm">
                        Подтвердите пароль
                        <input v-model="reg.password_confirmation" type="password" required class="input"/>
                    </label>

                    <button type="submit" class="btn w-full mb-3" :disabled="regLoading">
                        Зарегистрироваться
                    </button>

                    <div class="text-sm text-center">
                        Уже зарегистрированы?
                        <button type="button" class="link" @click="tab='login'">Войти</button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios   from 'axios'
import { Inertia } from '@inertiajs/inertia'

const emit = defineEmits(['close','success'])
const tab  = ref('login')

/* ――― блокируем скролл ――― */
onMounted(()=>document.documentElement.classList.add('overflow-hidden'))
onBeforeUnmount(()=>document.documentElement.classList.remove('overflow-hidden'))

/* css helpers (т.к. внутри :class) */
const activeCls = 'border-b-2 border-purple-400 text-purple-400'
const idleCls   = 'text-gray-400'

/* ---------- login ---------- */
const login        = ref({ email:'', password:'' })
const loginErr     = ref({})
const loginLoading = ref(false)

async function submitLogin () {
    loginLoading.value = true
    loginErr.value = {}
    try{
        await axios.post('/login', login.value, { headers:{ Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' } })
        // подтянуть fresh auth-prop
        await Inertia.reload({ only:['auth'], preserveScroll:true })
        emit('success')
    }catch(e){
        if(e.response?.status===422) loginErr.value = e.response.data.errors
    }finally{
        loginLoading.value = false
    }
}

/* ---------- register ---------- */
const reg        = ref({ name:'', email:'', password:'', password_confirmation:'' })
const regErr     = ref({})
const regLoading = ref(false)

async function submitRegister () {
    regLoading.value = true
    regErr.value = {}
    try{
        await axios.post('/register', reg.value, { headers:{ Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' } })
        await Inertia.reload({ only:['auth'], preserveScroll:true })
        emit('success')
    }catch(e){
        if(e.response?.status===422) regErr.value = e.response.data.errors
    }finally{
        regLoading.value = false
    }
}

function close () { emit('close') }
</script>

<style scoped>
.input{ @apply mt-1 w-full px-3 py-2 rounded bg-gray-700 focus:outline-none }
.btn  { @apply bg-purple-600 hover:bg-purple-500 py-2 rounded }
.link { @apply text-purple-400 hover:underline }
.err  { @apply text-red-500 text-xs }
html.overflow-hidden,body.overflow-hidden{ overflow:hidden }
</style>
