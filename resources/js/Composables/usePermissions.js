import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function usePermissions() {
    const page = usePage()

    const user = computed(() => page.props.auth?.user || {})
    const isSuperAdmin = computed(() => !!user.value?.is_super_admin)
    const permissions = computed(() => user.value?.permissions || [])

    const can = (permission) => {
        return isSuperAdmin.value || permissions.value.includes(permission)
    }

    return {
        user,
        isSuperAdmin,
        permissions,
        can,
    }
}
