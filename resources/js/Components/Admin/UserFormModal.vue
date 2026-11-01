<script setup>
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
const props = defineProps({ user: Object, roles: { type: Array, default: () => [] } })
const emit = defineEmits(['close', 'saved'])
const form = reactive({ name: props.user?.name || '', email: props.user?.email || '', password: '', roles: (props.user?.roles || []).map(role => role.name) })
const processing = ref(false)
const errors = ref({})
const requestError = ref('')
const editing = computed(() => Boolean(props.user))
const roleLabels = { User: 'Участник', Moderator: 'Модератор', Admin: 'Администратор', SuperAdmin: 'Суперадминистратор' }
const fieldError = key => Array.isArray(errors.value[key]) ? errors.value[key][0] : errors.value[key]
async function submit() {
    if (processing.value) return
    processing.value = true
    errors.value = {}
    requestError.value = ''
    try {
        const { data } = await axios.post(editing.value ? `/admin/users/${props.user.id}` : '/admin/users', form)
        emit('saved', data.user)
        emit('close')
    } catch (error) {
        if (error.response?.status === 422) errors.value = error.response.data.errors || {}
        else requestError.value = 'Не удалось сохранить пользователя. Попробуйте ещё раз.'
    } finally { processing.value = false }
}
</script>

<template>
    <Modal :show="true" max-width="lg" :closeable="!processing" @close="emit('close')">
        <form class="p-6 sm:p-8" @submit.prevent="submit">
            <div class="mb-7 flex items-start justify-between gap-4">
                <div><p class="ui-eyebrow">Администрирование</p><h2 class="mt-3 text-2xl">{{ editing ? 'Профиль пользователя' : 'Новый пользователь' }}</h2><p class="ui-muted mt-2 text-sm">{{ editing ? 'Данные аккаунта и роли на платформе.' : 'Создайте аккаунт и назначьте права доступа.' }}</p></div>
                <button type="button" class="btn btn-ghost btn-sm btn-square -mr-2 -mt-2" aria-label="Закрыть форму пользователя" :disabled="processing" @click="emit('close')"><i class="mdi mdi-close text-xl" /></button>
            </div>
            <div v-if="requestError" role="alert" class="mb-5 rounded-xl bg-error/10 p-4 text-sm text-error">{{ requestError }}</div>
            <fieldset :disabled="processing" class="space-y-5">
                <label class="block text-sm font-semibold">Имя<input v-model="form.name" required maxlength="100" autocomplete="off" class="input mt-2 w-full" placeholder="Имя пользователя" /><span v-if="fieldError('name')" class="mt-2 block text-xs text-error">{{ fieldError('name') }}</span></label>
                <label class="block text-sm font-semibold">Электронная почта<input v-model="form.email" required type="email" autocomplete="off" class="input mt-2 w-full" placeholder="name@example.com" /><span v-if="fieldError('email')" class="mt-2 block text-xs text-error">{{ fieldError('email') }}</span></label>
                <label class="block text-sm font-semibold">{{ editing ? 'Новый пароль' : 'Пароль' }}<input v-model="form.password" type="password" :required="!editing" minlength="6" autocomplete="new-password" class="input mt-2 w-full" :placeholder="editing ? 'Оставьте пустым, чтобы сохранить текущий' : 'Не менее 6 символов'" /><span v-if="fieldError('password')" class="mt-2 block text-xs text-error">{{ fieldError('password') }}</span></label>
                <div><p class="text-sm font-semibold">Роли и доступ</p><p class="ui-muted mt-1 text-xs">Выберите роли для этого аккаунта.</p><div class="mt-3 grid gap-2 sm:grid-cols-2"><label v-for="role in roles" :key="role" :class="['flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm', form.roles.includes(role) ? 'border-primary/30 bg-primary/5' : 'border-base-300']"><input type="checkbox" :value="role" v-model="form.roles" class="checkbox checkbox-primary checkbox-sm" />{{ roleLabels[role] || role }}</label></div><p v-if="fieldError('roles')" class="mt-2 text-xs text-error">{{ fieldError('roles') }}</p></div>
            </fieldset>
            <div class="mt-7 flex flex-wrap justify-end gap-3 border-t border-base-300 pt-5"><button type="button" class="btn btn-ghost" :disabled="processing" @click="emit('close')">Отмена</button><button class="btn btn-primary" :disabled="processing || !form.name.trim() || !form.email || !form.roles.length || (!editing && !form.password)"><span v-if="processing" class="loading loading-spinner loading-xs" />{{ editing ? 'Сохранить изменения' : 'Создать пользователя' }}</button></div>
        </form>
    </Modal>
</template>
