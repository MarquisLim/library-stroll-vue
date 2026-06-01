<script setup>
import { ref, watch, nextTick } from 'vue'
import axios from 'axios'
import { useArtworkActions } from '@/stores/useArtworkActions'

const emit = defineEmits(['close'])
const props = defineProps({ show: Boolean })

const dialogRef  = ref(null)
const actions = useArtworkActions()
const tab        = ref('login')
const loginData  = ref({ email: '', password: '' })
const regData    = ref({ name: '', email: '', password: '', password_confirmation: '', terms: false })
const loginErr   = ref({})
const regErr     = ref({})
const loading    = ref(false)

watch(() => props.show, (v) => {
    if (v) nextTick(() => dialogRef.value?.showModal())
    else dialogRef.value?.close()
})

axios.interceptors.request.use(cfg => {
    const t = document.head.querySelector('meta[name="csrf-token"]')?.content
    if (t) cfg.headers['X-CSRF-TOKEN'] = t
    return cfg
})

function close() {
    emit('close')
}

function submitLogin() {
    loading.value = true
    loginErr.value = {}
    axios.post(route('login.store'), loginData.value)
        .then(({ data }) => {
            if (data?.two_factor) {
                window.location.href = route('two-factor.login')
                return
            }
            actions.notify('Вы успешно вошли', 'success')
            close()
            setTimeout(() => window.location.reload(), 500)
        })
        .catch(e => {
            if (e.response?.status === 422) loginErr.value = e.response.data.errors
            if (e.response?.status === 419) window.location.reload()
        })
        .finally(() => loading.value = false)
}

function submitRegister() {
    loading.value = true
    regErr.value = {}
    axios.post(route('register.store'), regData.value)
        .then(() => {
            actions.notify('Вы успешно зарегистрировались', 'success')
            close()
            setTimeout(() => window.location.reload(), 500)
        })
        .catch(e => {
            if (e.response?.status === 422) regErr.value = e.response.data.errors
            if (e.response?.status === 419) window.location.reload()
        })
        .finally(() => loading.value = false)
}
</script>

<template>
    <dialog ref="dialogRef" class="modal" @close="close">
        <div class="modal-box relative bg-base-100 p-6 w-full max-w-md">
            <button @click="dialogRef.close()" class="btn btn-sm btn-circle absolute right-2 top-2">✕</button>

            <div class="flex justify-center mb-4">
                <img src="/logo.png" alt="Logo" class="h-10 w-auto" />
            </div>

            <div class="tabs tabs-boxed w-full mb-4">
                <button
                    class="tab flex-1"
                    :class="tab === 'login' ? 'tab-active bg-primary text-primary-content rounded' : ''"
                    @click.prevent="tab = 'login'"
                >Вход</button>
                <button
                    class="tab flex-1"
                    :class="tab === 'register' ? 'tab-active bg-primary text-primary-content rounded' : ''"
                    @click.prevent="tab = 'register'"
                >Регистрация</button>
            </div>


            <!-- LOGIN -->
            <form v-if="tab === 'login'" @submit.prevent="submitLogin" class="space-y-4">
                <div>
                    <label class="label"><span class="label-text">Почта</span></label>
                    <input
                        v-model="loginData.email"
                        type="email"
                        placeholder="Почта"
                        required
                        class="input input-bordered w-full placeholder-base-content/50"
                    />
                    <p v-if="loginErr.email" class="text-error text-sm mt-1">
                        {{ Array.isArray(loginErr.email) ? loginErr.email[0] : loginErr.email }}
                    </p>
                </div>

                <div>
                    <label class="label"><span class="label-text">Пароль</span></label>
                    <input
                        v-model="loginData.password"
                        type="password"
                        placeholder="Пароль"
                        required
                        class="input input-bordered w-full placeholder-base-content/50"
                    />
                    <p v-if="loginErr.password" class="text-error text-sm mt-1">
                        {{ Array.isArray(loginErr.password) ? loginErr.password[0] : loginErr.password }}
                    </p>
                </div>

                <div class="flex justify-between items-center">
                    <button type="button" class="btn btn-ghost btn-sm" @click.prevent="tab = 'register'">Регистрация</button>
                    <a :href="route('password.request')" class="text-sm hover:underline">Забыли пароль?</a>
                </div>

                <button type="submit" class="btn btn-primary w-full" :disabled="loading">Войти</button>
            </form>

            <!-- REGISTER -->
            <form v-else @submit.prevent="submitRegister" class="space-y-4">
                <div>
                    <label class="label"><span class="label-text">Никнейм</span></label>
                    <input
                        v-model="regData.name"
                        type="text"
                        placeholder="Ваш ник"
                        required
                        class="input input-bordered w-full placeholder-base-content/50"
                    />
                    <p v-if="regErr.name" class="text-error text-sm mt-1">
                        {{ Array.isArray(regErr.name) ? regErr.name[0] : regErr.name }}
                    </p>
                </div>

                <div>
                    <label class="label"><span class="label-text">Почта</span></label>
                    <input
                        v-model="regData.email"
                        type="email"
                        placeholder="Почта"
                        required
                        class="input input-bordered w-full placeholder-base-content/50"
                    />
                    <p v-if="regErr.email" class="text-error text-sm mt-1">
                        {{ Array.isArray(regErr.email) ? regErr.email[0] : regErr.email }}
                    </p>
                </div>

                <div>
                    <label class="label"><span class="label-text">Пароль</span></label>
                    <input
                        v-model="regData.password"
                        type="password"
                        placeholder="Пароль"
                        required
                        class="input input-bordered w-full placeholder-base-content/50"
                    />
                    <p v-if="regErr.password" class="text-error text-sm mt-1">
                        {{ Array.isArray(regErr.password) ? regErr.password[0] : regErr.password }}
                    </p>
                </div>

                <div>
                    <label class="label"><span class="label-text">Повторите пароль</span></label>
                    <input
                        v-model="regData.password_confirmation"
                        type="password"
                        placeholder="Повторите пароль"
                        required
                        class="input input-bordered w-full placeholder-base-content/50"
                    />
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer">
                        <input type="checkbox" v-model="regData.terms" class="checkbox checkbox-primary mr-2" />
                        <div class="ms-2 text-sm flex flex-wrap">
                            Я принимаю
                            <a target="_blank" :href="route('terms')" class="underline text-primary hover:text-primary-900 mx-1">
                                Пользовательское соглашение
                            </a>
                            и
                            <a target="_blank" :href="route('privacy')" class="underline text-primary hover:text-primary-900 mx-1">
                                Политику конфиденциальности
                            </a>
                        </div>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-full" :disabled="!regData.terms || loading">
                    Подтвердить
                </button>

                <p class="text-center text-sm">
                    Уже зарегистрированы?
                    <button type="button" class="btn btn-ghost btn-sm" @click.prevent="tab = 'login'">Войти</button>
                </p>
            </form>
        </div>

        <!-- Фон (закрывает при клике вне) -->
        <form method="dialog" class="modal-backdrop">
            <button></button>
        </form>
    </dialog>
</template>
