<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    requiresConfirmation: Boolean,
});

const page = usePage();
const enabling = ref(false);
const confirming = ref(false);
const disabling = ref(false);
const setupVisible = ref(false);
const enableError = ref('');
const qrCode = ref(null);
const setupKey = ref(null);
const recoveryCodes = ref([]);

const confirmationForm = useForm({
    code: '',
});

const isPendingConfirmation = computed(
    () => confirming.value || page.props.auth.user?.two_factor_pending_confirmation,
);

const twoFactorEnabled = computed(
    () => ! enabling.value && (
        page.props.auth.user?.two_factor_enabled
        || isPendingConfirmation.value
        || setupVisible.value
    ),
);

watch(twoFactorEnabled, () => {
    if (! twoFactorEnabled.value) {
        confirmationForm.reset();
        confirmationForm.clearErrors();
        setupVisible.value = false;
        qrCode.value = null;
        setupKey.value = null;
        recoveryCodes.value = [];
    }
});

const loadSetupData = () => Promise.all([
    showQrCode(),
    showSetupKey(),
    showRecoveryCodes(),
]);

const enableTwoFactorAuthentication = () => {
    enabling.value = true;
    enableError.value = '';

    router.post(route('two-factor.enable'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            setupVisible.value = true;
            confirming.value = props.requiresConfirmation;
            return loadSetupData();
        },
        onError: (errors) => {
            enableError.value = errors?.password
                ?? errors?.message
                ?? 'Не удалось включить двухфакторную аутентификацию.';
        },
        onFinish: () => {
            enabling.value = false;
        },
    });
};

const showQrCode = () => axios.get(route('two-factor.qr-code')).then(response => {
    qrCode.value = response.data.svg;
});

const showSetupKey = () => axios.get(route('two-factor.secret-key')).then(response => {
    setupKey.value = response.data.secretKey;
});

const showRecoveryCodes = () => axios.get(route('two-factor.recovery-codes')).then(response => {
    recoveryCodes.value = response.data;
});

const confirmTwoFactorAuthentication = () => {
    confirmationForm.post(route('two-factor.confirm'), {
        errorBag: 'confirmTwoFactorAuthentication',
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            setupVisible.value = false;
            qrCode.value = null;
            setupKey.value = null;
            recoveryCodes.value = [];
            confirmationForm.reset();
            router.reload({ preserveScroll: true });
        },
    });
};

const regenerateRecoveryCodes = () => {
    axios
        .post(route('two-factor.recovery-codes'))
        .then(() => showRecoveryCodes());
};

const disableTwoFactorAuthentication = () => {
    disabling.value = true;

    router.delete(route('two-factor.disable'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirming.value = false;
            setupVisible.value = false;
            qrCode.value = null;
            setupKey.value = null;
            recoveryCodes.value = [];
        },
        onFinish: () => {
            disabling.value = false;
        },
    });
};

onMounted(() => {
    if (page.props.auth.user?.two_factor_pending_confirmation && ! qrCode.value) {
        setupVisible.value = true;
        confirming.value = true;
        loadSetupData().catch(() => {});
    }
});
</script>

<template>
    <ActionSection>
        <template #title>
            <div class="text-xl leading-relaxed text-base-content">
                Двухфакторная аутентификация
            </div>
        </template>

        <template #description>
          <div class="text-base text-base-content">
            Добавьте дополнительную безопасность своей учетной записи, используя двухфакторную аутентификацию.
          </div>
        </template>

        <template #content>
            <h3 v-if="twoFactorEnabled && ! isPendingConfirmation" class="text-lg font-medium text-base-300">
                Вы включили двухфакторную аутентификацию.
            </h3>

            <h3 v-else-if="twoFactorEnabled && isPendingConfirmation" class="text-lg font-medium text-base-300">
                Завершите включение двухфакторной аутентификации.
            </h3>

            <h3 v-else class="text-lg font-medium text-base">
                Вы не включили двухфакторную аутентификацию.
            </h3>

            <div class="mt-3 max-w-xl text-sm text-base">
                <p>
                  Если включена двухфакторная аутентификация, во время аутентификации вам будет предложено ввести безопасный случайный токен. Вы можете получить этот токен из приложения Google Authenticator вашего телефона.
                </p>
            </div>

            <p v-if="enableError" class="mt-3 text-sm text-error">
                {{ enableError }}
            </p>

            <div v-if="twoFactorEnabled">
                <div v-if="qrCode">
                    <div class="mt-4 max-w-xl text-sm text-gray-600">
                        <p v-if="isPendingConfirmation" class="font-semibold">
                          Чтобы завершить включение двухфакторной аутентификации, отсканируйте следующий QR-код с помощью приложения аутентификации вашего телефона или введите ключ настройки и предоставьте сгенерированный OTP-код.
                        </p>

                        <p v-else>
                          Двухфакторная аутентификация теперь включена. Отсканируйте следующий QR-код с помощью приложения аутентификации вашего телефона или введите ключ настройки.
                        </p>
                    </div>

                    <div class="mt-4 p-2 inline-block bg-base-100 shadow-lg ring-1 ring-base-300" v-html="qrCode" />

                    <div v-if="setupKey" class="mt-4 max-w-xl text-sm text-gray-600">
                        <p class="font-semibold">
                          Ключ настройки: <span v-html="setupKey"></span>
                        </p>
                    </div>

                    <div v-if="isPendingConfirmation" class="mt-4">
                        <InputLabel for="code" value="Code" />

                        <TextInput
                            id="code"
                            v-model="confirmationForm.code"
                            type="text"
                            name="code"
                            class="block mt-1 w-1/2"
                            inputmode="numeric"
                            autofocus
                            autocomplete="one-time-code"
                            @keyup.enter="confirmTwoFactorAuthentication"
                        />

                        <InputError :message="confirmationForm.errors.code" class="mt-2" />
                    </div>
                </div>

                <p v-else-if="setupVisible || enabling" class="mt-4 text-sm text-base-content/70">
                    Загрузка QR-кода…
                </p>

                <div v-if="recoveryCodes.length > 0 && ! isPendingConfirmation">
                    <div class="mt-4 max-w-xl text-sm text-gray-600">
                        <p class="font-semibold">
                          Сохраните эти коды восстановления в безопасном менеджере паролей. Их можно использовать для восстановления доступа к вашей учетной записи, если ваше устройство двухфакторной аутентификации потеряно.
                        </p>
                    </div>

                    <div class="grid gap-1 max-w-xl mt-4 px-4 py-4 font-mono text-sm bg-gray-100 rounded-lg">
                        <div v-for="code in recoveryCodes" :key="code">
                            {{ code }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5">
                <div v-if="! twoFactorEnabled">
                    <ConfirmsPassword @confirmed="enableTwoFactorAuthentication">
                        <PrimaryButton type="button" :class="{ 'opacity-25': enabling }" :disabled="enabling">
                            Разрешить
                        </PrimaryButton>
                    </ConfirmsPassword>
                </div>

                <div v-else>
                    <ConfirmsPassword @confirmed="regenerateRecoveryCodes">
                        <SecondaryButton
                            v-if="recoveryCodes.length > 0 && ! isPendingConfirmation"
                            class="me-3"
                        >
                            Восстановить коды восстановления
                        </SecondaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="showRecoveryCodes">
                        <SecondaryButton
                            v-if="recoveryCodes.length === 0 && ! isPendingConfirmation"
                            class="me-3"
                        >
                          Показать коды восстановления
                        </SecondaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="disableTwoFactorAuthentication">
                        <SecondaryButton
                            v-if="isPendingConfirmation"
                            :class="{ 'opacity-25': disabling }"
                            :disabled="disabling"
                        >
                            Отмена
                        </SecondaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="disableTwoFactorAuthentication">
                        <DangerButton
                            v-if="! isPendingConfirmation"
                            :class="{ 'opacity-25': disabling }"
                            :disabled="disabling"
                        >
                            Запретить
                        </DangerButton>
                    </ConfirmsPassword>
                </div>
            </div>
        </template>
    </ActionSection>
</template>
