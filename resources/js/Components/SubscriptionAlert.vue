<template>
  <div
    v-if="showAlert"
    class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-4"
  >
    <div
      class="rounded-lg border px-4 py-3 text-sm"
      :class="alertClass"
    >
      {{ alertMessage }}
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useSubscriptionStore } from '@/stores/subscription'

const page = usePage()
const store = useSubscriptionStore()

const authUser = computed(() => page.props.auth?.user ?? null)

const showAlert = computed(() =>
  !!authUser.value &&
  !authUser.value.is_super_admin &&
  (store.isExpired || store.isExpiringSoon)
)

const alertClass = computed(() =>
  store.isExpired
    ? 'border-red-500/40 bg-red-900/20 text-red-300'
    : 'border-amber-500/40 bg-amber-900/20 text-amber-200'
)

const alertMessage = computed(() => {
  if (store.isExpired) {
    return store.message || 'Subscription expired. Please contact support.'
  }

  return `Your subscription will expire in ${store.remainingDays} day${store.remainingDays === 1 ? '' : 's'}.`
})

let refreshInterval = null

onMounted(() => {
  if (!authUser.value || authUser.value.is_super_admin) return
  store.fetchStatus()
  refreshInterval = window.setInterval(() => {
    store.fetchStatus()
  }, 24 * 60 * 60 * 1000)
})

onBeforeUnmount(() => {
  if (refreshInterval) {
    window.clearInterval(refreshInterval)
  }
})
</script>

