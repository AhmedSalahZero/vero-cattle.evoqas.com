<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useLocale } from '@/Composables/useLocale';

const { t } = useLocale();
const tx = {
    en: {
        title: 'Email Verification',
        intro: 'Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you did not receive the email, we will gladly send you another.',
        sent: 'A new verification link has been sent to the email address you provided during registration.',
        resend: 'Resend Verification Email',
        logout: 'Log Out',
    },
    ar: {
        title: 'تأكيد البريد الإلكتروني',
        intro: 'شكرًا على التسجيل! قبل البدء، هل يمكنك تأكيد بريدك الإلكتروني بالضغط على الرابط الذي أرسلناه لك؟ إذا لم تصلك الرسالة، يمكننا إرسال واحدة جديدة.',
        sent: 'تم إرسال رابط تحقق جديد إلى البريد الإلكتروني الذي أدخلته أثناء التسجيل.',
        resend: 'إعادة إرسال بريد التحقق',
        logout: 'تسجيل الخروج',
    },
};

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head :title="t(tx, '').title" />

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ t(tx, '').intro }}
        </div>

        <div
            class="mb-4 text-sm font-medium text-green-600 dark:text-green-400"
            v-if="verificationLinkSent"
        >
            {{ t(tx, '').sent }}
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t(tx, '').resend }}
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:text-gray-400 dark:hover:text-gray-100 dark:focus:ring-offset-gray-800"
                    >{{ t(tx, '').logout }}</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
