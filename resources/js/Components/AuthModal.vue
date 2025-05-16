<template>
    <Teleport to="body">
        <div class="modal modal-open">
            <div class="modal-box relative bg-base-100">
                <!-- Close Button -->
                <button @click="close" class="btn btn-sm btn-circle absolute right-2 top-2">✕</button>

                <!-- Logo -->
                <div class="flex justify-center mb-4">
                    <img src="/logo.png" alt="Logo" class="h-16 w-auto" />
                </div>

                <!-- Tabs -->
                <div class="tabs tabs-boxed w-full mb-4">
                    <a :class="['tab flex-1', tab === 'login' ? 'tab-active' : '']" @click.prevent="tab = 'login'">Вход</a>
                    <a :class="['tab flex-1', tab === 'register' ? 'tab-active' : '']" @click.prevent="tab = 'register'">Регистрация</a>
                </div>

                <!-- Login Form -->
                <form v-if="tab === 'login'" @submit.prevent="submitLogin" class="space-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text">Почта</span>
                        </label>
                        <input v-model="login.email" type="email" placeholder="Введите email" required class="input input-bordered w-full" />
                        <p v-if="loginErr.email" class="text-error text-sm mt-1">{{ loginErr.email[0] }}</p>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">Пароль</span>
                        </label>
                        <input v-model="login.password" type="password" placeholder="Введите пароль" required class="input input-bordered w-full" />
                        <p v-if="loginErr.password" class="text-error text-sm mt-1">{{ loginErr.password[0] }}</p>
                    </div>

                    <div class="flex justify-between items-center">
                        <button type="button" class="link link-hover text-sm" @click.prevent="tab = 'register'">Регистрация</button>
                        <a :href="route('password.request')" class="link link-hover text-sm">Забыли пароль?</a>
                    </div>

                    <button type="submit" class="btn btn-primary w-full" :loading="loginLoading">Войти</button>
                </form>

                <!-- Register Form -->
                <form v-else @submit.prevent="submitRegister" class="space-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text">Никнейм</span>
                        </label>
                        <input v-model="reg.name" type="text" placeholder="Ваш ник" required class="input input-bordered w-full" />
                        <p v-if="regErr.name" class="text-error text-sm mt-1">{{ regErr.name[0] }}</p>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">Почта</span>
                        </label>
                        <input v-model="reg.email" type="email" placeholder="Введите email" required class="input input-bordered w-full" />
                        <p v-if="regErr.email" class="text-error text-sm mt-1">{{ regErr.email[0] }}</p>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">Пароль</span>
                        </label>
                        <input v-model="reg.password" type="password" placeholder="Придумайте пароль" required class="input input-bordered w-full" />
                        <p v-if="regErr.password" class="text-error text-sm mt-1">{{ regErr.password[0] }}</p>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">Подтверждение пароля</span>
                        </label>
                        <input v-model="reg.password_confirmation" type="password" placeholder="Повторите пароль" required class="input input-bordered w-full" />
                    </div>

                    <div class="form-control">
                        <label class="label cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="agreed"
                                class="checkbox checkbox-primary mr-2"
                            />
                            <span class="label-text text-sm">
                                Я принимаю
                                <a href="/terms" class="link link-primary underline">Пользовательское соглашение</a>
                                и
                                <a href="/privacy" class="link link-primary underline">Политику конфиденциальности</a>
                              </span>
                        </label>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary w-full"
                        :disabled="!agreed || regLoading"
                        :loading="regLoading"
                    >
                        Зарегистрироваться
                    </button>

                    <p class="text-center text-sm">
                        Уже зарегистрированы?
                        <button type="button" class="link link-hover" @click.prevent="tab = 'login'">Войти</button>
                    </p>
                </form>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import { Inertia } from '@inertiajs/inertia'

const emit = defineEmits(['close','success'])
const tab  = ref('login')

onMounted(() => document.documentElement.classList.add('overflow-hidden'))
onBeforeUnmount(() => document.documentElement.classList.remove('overflow-hidden'))

// login state
const login        = ref({ email:'', password:'' })
const loginErr     = ref({})
const loginLoading = ref(false)

// register state
const reg          = ref({ name:'', email:'', password:'', password_confirmation:'' })
const regErr       = ref({})
const regLoading   = ref(false)

const agreed       = ref(false)

async function submitLogin () {
    loginLoading.value = true
    loginErr.value = {}
    try {
        await axios.post('/login', login.value, { headers:{ Accept:'application/json','X-Requested-With':'XMLHttpRequest' } })
        await Inertia.reload({ only:['auth'], preserveScroll:true })
        emit('success')
    } catch(e) {
        if (e.response?.status === 422) loginErr.value = e.response.data.errors
    } finally {
        loginLoading.value = false
    }
}

async function submitRegister () {
    regLoading.value = true
    regErr.value = {}
    try {
        await axios.post('/register', reg.value, { headers:{ Accept:'application/json','X-Requested-With':'XMLHttpRequest' } })
        await Inertia.reload({ only:['auth'], preserveScroll:true })
        emit('success')
    } catch(e) {
        if (e.response?.status === 422) regErr.value = e.response.data.errors
    } finally {
        regLoading.value = false
    }
}

function close() {
    emit('close')
}
</script>
