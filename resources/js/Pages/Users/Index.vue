<script setup>
/**
 * Users/Index.vue — VERO Cattle Management
 *
 * Who sees what:
 *   Super Admin  → all users across all companies, can filter by company
 *   Farm Owner   → only users in their own company
 *
 * Props from UserController@index:
 *   users        : User[]        — paginated/filtered user list
 *   companies    : Company[]     — for super-admin company filter (empty for farm owners)
 *   filters      : object        — active search/filter state
 *   user.permissions     : string        — 'super_admin' | 'Farm Owner' | …
 *   myCompanyId  : number|null   — the logged-in user's company id
 *   quota        : object|null   — { used, max, remaining } | null if unlimited
 */
import { ref, computed, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    users:       { type: Array,  default: () => [] },
    companies:   { type: Array,  default: () => [] },
    filters:     { type: Object, default: () => ({}) },
    myCompanyId: { type: Number, default: null },
    quota:       { type: Object, default: null },   // { used, max, remaining } or null
})

const { isSuperAdmin, can } = usePermissions()

// ── Search & filter state ─────────────────────────────────────────
const search     = ref(props.filters.search     ?? '')
const filterRole = ref(props.filters.role       ?? '')
const filterStatus = ref(props.filters.status   ?? '')
const filterCompany = ref(props.filters.company_id ?? '')

// Debounced search — fires 350ms after user stops typing
let searchTimer = null
watch(search, (val) => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => applyFilters(), 350)
})

function applyFilters() {
    router.get(route('users.index'), {
        search:     search.value     || undefined,
        role:       filterRole.value || undefined,
        status:     filterStatus.value || undefined,
        company_id: filterCompany.value || undefined,
    }, { preserveState: true, replace: true })
}

function resetFilters() {
    search.value        = ''
    filterRole.value    = ''
    filterStatus.value  = ''
    filterCompany.value = ''
    applyFilters()
}

const hasFilters = computed(() =>
    search.value || filterRole.value || filterStatus.value || filterCompany.value
)

// ── Delete modal ──────────────────────────────────────────────────
const deleteTarget = ref(null)   // user object
function confirmDelete(user) { deleteTarget.value = user }
function cancelDelete()      { deleteTarget.value = null }
function executeDelete() {
    if (!deleteTarget.value) return
    router.delete(route('users.destroy', deleteTarget.value.id), {
        onFinish: () => { deleteTarget.value = null },
    })
}

// ── Toggle active ─────────────────────────────────────────────────
function toggleActive(user) {
    router.patch(route('users.toggle-active', user.id), {}, {
        preserveScroll: true,
    })
}

// ── Quota pill logic ──────────────────────────────────────────────
const quotaPct = computed(() => {
    if (!props.quota?.max) return null
    return Math.min(100, Math.round((props.quota.used / props.quota.max) * 100))
})
const quotaAtLimit = computed(() =>
    props.quota?.max && props.quota.remaining <= 0
)

// ── Role display ──────────────────────────────────────────────────
// Roles are now display labels only — access is controlled by individual permissions.
const roleLabels = {
    'Company Admin': { label: 'Company Admin', labelAr: 'مدير الشركة',  type: 'amber' },
    'Farm Manager':  { label: 'Farm Manager',  labelAr: 'مدير المزرعة', type: 'blue'  },
    'Veterinarian':  { label: 'Veterinarian',  labelAr: 'طبيب بيطري',   type: 'amber' },
    'Feed Manager':  { label: 'Feed Manager',  labelAr: 'مدير الأعلاف', type: 'amber' },
    'Data Entry':    { label: 'Data Entry',    labelAr: 'إدخال بيانات', type: 'muted' },
    'Accountant':    { label: 'Accountant',    labelAr: 'محاسب',        type: 'blue'  },
}
function roleInfo(roleName) {
    return roleLabels[roleName] ?? { label: roleName ?? 'Custom', type: 'muted' }
}

// ── User initials (avatar fallback) ──────────────────────────────
function initials(user) {
    const name = user?.name_en ?? user?.name ?? ''
    if (!name || typeof name !== 'string') return '?'
    return name.split(' ').slice(0, 2).map(w => w[0] ?? '').join('').toUpperCase() || '?'
}

// ── Flash message ─────────────────────────────────────────────────
const flash = computed(() => usePage().props.flash ?? {})
</script>

<template>
    <Head title="Users" />
    <AuthenticatedLayout>
        <template #header>
            <span class="eyebrow-crumb">
                {{ isSuperAdmin ? 'Super Admin' : 'Settings' }}
            </span>
            <svg class="w-3 h-3 mx-1.5 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <span style="font-size:0.8125rem;font-weight:500;color:var(--cv-text-secondary)">
                Users
            </span>
        </template>

        <div class="pg-shell">

            <!-- ══ HERO ════════════════════════════════════════════ -->
            <div class="pg-hero">
                <div class="pg-hero-glow"></div>
                <div class="pg-hero-dots"></div>
                <div class="hero-inner">
                    <div class="hero-left">
                        <p class="hero-eyebrow">
                            {{ isSuperAdmin ? 'Super Admin · All Companies' : 'Settings · User Management' }}
                        </p>
                        <h1 class="hero-title">Users</h1>
                        <p class="hero-sub">
                            {{ isSuperAdmin
                                ? 'Manage all users across every company'
                                : 'Manage who has access to your company' }}
                        </p>
                        <!-- بالعربية -->
                        <p class="hero-sub" dir="rtl">
                            {{ isSuperAdmin ? 'إدارة جميع المستخدمين في كل الشركات' : 'إدارة المستخدمين في شركتك' }}
                        </p>
                    </div>
                    <div class="hero-right">
                        <!-- Quota pill for company admins -->
                        <div v-if="quota?.max" class="quota-hero-pill" :class="quotaAtLimit ? 'quota-pill-red' : quotaPct >= 80 ? 'quota-pill-amber' : 'quota-pill-green'">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                            </svg>
                            {{ quota.used }} / {{ quota.max }} users
                            <span v-if="quotaAtLimit" class="quota-full-tag">FULL</span>
                        </div>
                        <div v-else-if="!isSuperAdmin" class="quota-hero-pill quota-pill-muted">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
                            </svg>
                            Unlimited Users
                        </div>

                        <!-- CTA -->
                        <Link
                            v-if="!quotaAtLimit || isSuperAdmin"
                            :href="route('users.create')"
                            class="btn-primary-hero">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z"/>
                            </svg>
                            Add User
                            <!-- بالعربية -->
                            <span style="font-size:0.7rem;opacity:0.65;font-weight:600">/ إضافة مستخدم</span>
                        </Link>
                        <!-- Quota full — disabled button -->
                        <div v-else class="btn-primary-hero btn-disabled-hero" title="User quota reached for this company">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                            </svg>
                            Quota Reached
                        </div>
                    </div>
                </div>

                <!-- Quota progress bar (company admin only, when cap exists) -->
                <div v-if="quota?.max && !isSuperAdmin" class="quota-bar-hero-wrap">
                    <div class="quota-bar-hero-track">
                        <div class="quota-bar-hero-fill"
                             :class="quotaAtLimit ? 'fill-red' : quotaPct >= 80 ? 'fill-amber' : 'fill-green'"
                             :style="{ width: quotaPct + '%' }">
                        </div>
                    </div>
                    <p v-if="quotaAtLimit" class="quota-bar-hero-msg">
                        User limit reached — contact your system administrator to increase the quota.
                        <span dir="rtl" style="display:block;margin-top:2px">تم الوصول إلى الحد الأقصى للمستخدمين — اتصل بمسؤول النظام لزيادة الحصة.</span>
                    </p>
                </div>
            </div>

            <!-- ══ FLASH MESSAGE ══════════════════════════════════ -->
            <div class="content-wrap">
                <Transition name="flash">
                    <div v-if="flash.success" class="flash-success">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ flash.success }}
                    </div>
                </Transition>

                <!-- ══ TOOLBAR ══════════════════════════════════════ -->
                <div class="toolbar">
                    <!-- Search -->
                    <div class="search-wrap">
                        <svg class="search-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                        </svg>
                        <input v-model="search" type="text"
                               class="search-input"
                               placeholder="Search by name or email… / البحث بالاسم أو البريد"/>
                    </div>

                    <!-- Filters row -->
                    <div class="filter-row">
                        <!-- Role filter -->
                        <select v-model="filterRole" @change="applyFilters" class="filter-select">
                            <option value="">All Labels</option>
                            <option value="Company Admin">Company Admin</option>
                            <option value="Farm Manager">Farm Manager</option>
                            <option value="Veterinarian">Veterinarian</option>
                            <option value="Feed Manager">Feed Manager</option>
                            <option value="Data Entry">Data Entry</option>
                            <option value="Accountant">Accountant</option>
                        </select>

                        <!-- Status filter -->
                        <select v-model="filterStatus" @change="applyFilters" class="filter-select">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>

                        <!-- Company filter — super admin only -->
                        <select v-if="isSuperAdmin" v-model="filterCompany" @change="applyFilters" class="filter-select">
                            <option value="">All Companies</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">
                                {{ c.name_en }}
                            </option>
                        </select>

                        <!-- Clear filters -->
                        <button v-if="hasFilters" @click="resetFilters" class="btn-clear">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Clear
                        </button>
                    </div>
                </div>

                <!-- ══ RESULTS COUNT ════════════════════════════════ -->
                <div class="results-meta">
                    <span class="results-count">{{ users.length }} user{{ users.length !== 1 ? 's' : '' }}</span>
                    <span v-if="hasFilters" class="results-filtered">— filtered</span>
                </div>

                <!-- ══ EMPTY STATE ══════════════════════════════════ -->
                <div v-if="users.length === 0" class="empty-state">
                    <div class="empty-icon-wrap">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                        </svg>
                    </div>
                    <h3 class="empty-title">No users found</h3>
                    <p class="empty-sub">
                        {{ hasFilters ? 'Try adjusting your filters.' : 'Create the first user for this company.' }}
                    </p>
                    <p class="empty-sub" dir="rtl">
                        {{ hasFilters ? 'حاول تغيير الفلاتر.' : 'أنشئ أول مستخدم لهذه الشركة.' }}
                    </p>
                    <Link v-if="!hasFilters && (!quotaAtLimit || isSuperAdmin)"
                          :href="route('users.create')"
                          class="btn-empty-cta">
                        Add First User / إضافة أول مستخدم
                    </Link>
                </div>

                <!-- ══ USER CARD GRID ════════════════════════════════ -->
                <div v-else class="user-grid">
                    <div v-for="user in users" :key="user.id"
                         class="user-card"
                         :class="{
                            'card-inactive': !user.is_active,
                            'card-super':    user.is_super_admin,
                         }">

                        <!-- Left stripe — colour encodes status -->
                        <div class="card-stripe"
                             :class="user.is_super_admin ? 'stripe-blue'
                                   : !user.is_active     ? 'stripe-muted'
                                   : 'stripe-green'">
                        </div>

                        <!-- Card body -->
                        <div class="card-body">
                            <!-- Top row: avatar + name + badges -->
                            <div class="card-top">
                                <!-- Avatar -->
                                <div class="user-avatar"
                                     :class="user.is_super_admin ? 'avatar-blue' : 'avatar-green'">
                                    <img v-if="user.avatar"
                                         :src="`/storage/${user.avatar}`"
                                         :alt="user.name_en"
                                         class="w-full h-full object-cover"/>
                                    <span v-else class="avatar-initials">{{ initials(user) }}</span>
                                </div>

                                <!-- Name block -->
                                <div class="card-name-block">
                                    <div class="card-name">{{ user.name_en }}</div>
                                    <div v-if="user.name_ar" class="card-name-ar" dir="rtl">{{ user.name_ar }}</div>
                                    <div class="card-email">{{ user.email }}</div>
                                </div>

                                <!-- Super admin badge -->
                                <div v-if="user.is_super_admin" class="badge-super">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                                    </svg>
                                    Super Admin
                                </div>
                            </div>

                            <!-- Meta row: role, company, job title -->
                            <div class="card-meta">
                                <!-- Role badge — display label only -->
                                <span v-if="!user.is_super_admin && user.role"
                                      class="role-badge"
                                      :class="`role-badge-${roleInfo(user.role).type}`">
                                    {{ roleInfo(user.role).label }}
                                </span>

                                <!-- Permission count chip -->
                                <span v-if="!user.is_super_admin"
                                      class="perm-chip"
                                      :class="user.permission_count > 0 ? 'perm-chip-active' : 'perm-chip-empty'">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                                    </svg>
                                    {{ user.permission_count > 0 ? `${user.permission_count} permissions` : 'No permissions' }}
                                </span>

                                <!-- Company — super admin view only -->
                                <span v-if="isSuperAdmin && user.company_name" class="meta-chip meta-chip-blue">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                                    </svg>
                                    {{ user.company_name }}
                                </span>

                                <!-- Job title -->
                                <span v-if="user.job_title_en" class="meta-chip">
                                    {{ user.job_title_en }}
                                </span>
                            </div>

                            <!-- Footer row: status + last login + actions -->
                            <div class="card-footer">
                                <!-- Status badge -->
                                <span class="status-badge"
                                      :class="user.is_active ? 'status-active' : 'status-inactive'">
                                    <span class="status-dot"
                                          :class="user.is_active ? 'dot-green' : 'dot-grey'"></span>
                                    {{ user.is_active ? 'Active' : 'Inactive' }}
                                </span>

                                <!-- Last login -->
                                <span v-if="user.last_login_at" class="last-login">
                                    <svg class="w-3 h-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ user.last_login_at }}
                                </span>

                                <!-- Actions -->
                                <div class="card-actions">
                                    <!-- Toggle active -->
                                    <button v-if="!user.is_super_admin"
                                            @click="toggleActive(user)"
                                            class="action-btn"
                                            :class="user.is_active ? 'action-amber' : 'action-green'"
                                            :title="user.is_active ? 'Deactivate user' : 'Activate user'">
                                        <svg v-if="user.is_active" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                        </svg>
                                        <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </button>

                                    <!-- Edit -->
                                    <Link :href="route('users.edit', user.id)"
                                          class="action-btn action-blue"
                                          title="Edit user">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/>
                                        </svg>
                                    </Link>

                                    <!-- Delete — not allowed on super admin accounts -->
                                    <button v-if="!user.is_super_admin"
                                            @click="confirmDelete(user)"
                                            class="action-btn action-danger"
                                            title="Delete user">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ DELETE CONFIRM MODAL ══════════════════════════════ -->
        <Transition name="modal">
            <div v-if="deleteTarget" class="modal-backdrop" @click.self="cancelDelete">
                <div class="modal-box">
                    <div class="modal-danger-icon">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                    </div>
                    <h3 class="modal-title">Delete User?</h3>
                    <p class="modal-sub">
                        You are about to delete
                        <strong style="color:var(--cv-text-primary)">{{ deleteTarget?.name_en }}</strong>.
                        This cannot be undone.
                    </p>
                    <p class="modal-sub" dir="rtl" style="text-align:right">
                        أنت على وشك حذف هذا المستخدم. لا يمكن التراجع عن هذا الإجراء.
                    </p>
                    <div class="modal-alert">
                        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        All of this user's data, roles, and farm assignments will be permanently removed.
                    </div>
                    <div class="modal-footer">
                        <button @click="cancelDelete" class="btn-modal-cancel">Cancel / إلغاء</button>
                        <button @click="executeDelete" class="btn-modal-danger">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                            </svg>
                            Yes, Delete User
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────────────── */
.pg-shell { min-height: 100vh; background-color: var(--cv-bg); }

/* ── Breadcrumb ──────────────────────────────────────────────── */
.eyebrow-crumb {
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.12em; text-transform: uppercase; color: var(--cv-amber);
}

/* ── Hero ────────────────────────────────────────────────────── */
.pg-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #0A3A1E 0%, #0D3D22 50%, #0F4A28 100%);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    padding-bottom: 0;
}
.pg-hero-glow {
    position: absolute; top: -80px; right: -80px;
    width: 420px; height: 420px; border-radius: 50%;
    background: radial-gradient(circle, rgba(77,191,122,0.12) 0%, transparent 65%);
    pointer-events: none;
}
.pg-hero-dots {
    position: absolute; inset: 0; pointer-events: none;
    background-image: radial-gradient(circle, rgba(255,255,255,0.045) 1px, transparent 1px);
    background-size: 22px 22px;
    mask-image: linear-gradient(to bottom, rgba(0,0,0,0.4), transparent);
}
.hero-inner {
    position: relative; z-index: 10;
    max-width: 80rem; margin: 0 auto;
    padding: 1.75rem 1.5rem 1.5rem;
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 1.5rem; flex-wrap: wrap;
}
.hero-left { flex: 1; min-width: 0; }
.hero-right { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; flex-shrink: 0; }

.hero-eyebrow {
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.16em; text-transform: uppercase;
    color: #4DBF7A; margin-bottom: 0.3rem;
}
.hero-title {
    font-size: 1.625rem; font-weight: 800; color: #ffffff;
    line-height: 1.1; letter-spacing: -0.02em; margin-bottom: 0.25rem;
}
.hero-sub { font-size: 0.8125rem; color: rgba(255,255,255,0.5); margin-top: 0.125rem; line-height: 1.4; }

/* Quota pill in hero */
.quota-hero-pill {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.4rem 0.875rem; border-radius: 9999px;
    font-size: 0.8125rem; font-weight: 700;
    border: 1px solid;
}
.quota-pill-green { background: rgba(26,107,58,0.2);  color: #4DBF7A; border-color: rgba(77,191,122,0.3); }
.quota-pill-amber { background: rgba(232,131,10,0.15); color: #F5A623; border-color: rgba(245,166,35,0.3); }
.quota-pill-red   { background: rgba(192,57,43,0.2);  color: #E74C3C; border-color: rgba(231,76,60,0.3); }
.quota-pill-muted { background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.45); border-color: rgba(255,255,255,0.12); }
.quota-full-tag {
    font-size: 0.6rem; font-weight: 800; letter-spacing: 0.1em;
    background: rgba(192,57,43,0.25); padding: 0.1rem 0.3rem; border-radius: 3px;
}

/* Hero CTA */
.btn-primary-hero {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.625rem 1.25rem; border-radius: 10px;
    background: linear-gradient(135deg, #C46D08, #E8830A);
    color: #1A0A00; font-size: 0.875rem; font-weight: 800;
    text-decoration: none; border: none; cursor: pointer;
    box-shadow: 0 4px 16px rgba(232,131,10,0.35);
    transition: all 0.15s ease;
}
.btn-primary-hero:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(232,131,10,0.45);
}
.btn-disabled-hero {
    background: rgba(255,255,255,0.08) !important;
    color: rgba(255,255,255,0.3) !important;
    box-shadow: none !important; cursor: not-allowed !important;
    transform: none !important;
}

/* Quota bar in hero */
.quota-bar-hero-wrap {
    position: relative; z-index: 10;
    max-width: 80rem; margin: 0 auto;
    padding: 0 1.5rem 1.25rem;
}
.quota-bar-hero-track {
    height: 4px; background: rgba(255,255,255,0.1); border-radius: 9999px; overflow: hidden;
}
.quota-bar-hero-fill { height: 100%; border-radius: 9999px; transition: width 0.6s ease; }
.fill-green { background: linear-gradient(90deg, #1A6B3A, #4DBF7A); }
.fill-amber { background: linear-gradient(90deg, #C46D08, #F5A623); }
.fill-red   { background: linear-gradient(90deg, #C0392B, #E74C3C); }
.quota-bar-hero-msg {
    font-size: 0.6875rem; color: rgba(231,76,60,0.85); margin-top: 0.5rem; line-height: 1.4;
}

/* ── Content area ────────────────────────────────────────────── */
.content-wrap {
    max-width: 80rem; margin: 0 auto;
    padding: 1.5rem 1.5rem 3rem;
}

/* ── Flash message ───────────────────────────────────────────── */
.flash-success {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 1rem; border-radius: 10px;
    background: rgba(26,107,58,0.08); border: 1px solid rgba(26,107,58,0.25);
    color: #2E9E5A; font-size: 0.875rem; font-weight: 600;
    margin-bottom: 1.25rem;
}

/* ── Toolbar ─────────────────────────────────────────────────── */
.toolbar {
    display: flex; flex-direction: column; gap: 0.75rem;
    margin-bottom: 1.25rem;
}
.search-wrap { position: relative; }
.search-icon {
    position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%);
    width: 1rem; height: 1rem; color: var(--cv-text-muted); pointer-events: none;
}
.search-input {
    width: 100%; padding: 0.625rem 0.875rem 0.625rem 2.5rem;
    background: var(--cv-bg-card); border: 1.5px solid var(--cv-border);
    border-radius: 10px; color: var(--cv-text-primary);
    font-size: 0.875rem; font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus {
    outline: none; border-color: #1A6B3A;
    box-shadow: 0 0 0 3px rgba(26,107,58,0.1);
}
.search-input::placeholder { color: var(--cv-text-placeholder); }

.filter-row { display: flex; gap: 0.625rem; flex-wrap: wrap; align-items: center; }
.filter-select {
    padding: 0.5rem 0.75rem;
    background: var(--cv-bg-card); border: 1.5px solid var(--cv-border);
    border-radius: 8px; color: var(--cv-text-secondary);
    font-size: 0.8125rem; font-family: inherit; cursor: pointer;
    transition: border-color 0.15s;
}
.filter-select:focus { outline: none; border-color: #1A6B3A; }
.btn-clear {
    display: inline-flex; align-items: center; gap: 0.35rem;
    padding: 0.5rem 0.75rem; border-radius: 8px;
    background: rgba(192,57,43,0.06); border: 1px solid rgba(192,57,43,0.2);
    color: #C0392B; font-size: 0.8125rem; font-weight: 600;
    cursor: pointer; transition: all 0.15s;
}
.btn-clear:hover { background: rgba(192,57,43,0.12); }

/* ── Results meta ────────────────────────────────────────────── */
.results-meta { display: flex; gap: 0.5rem; margin-bottom: 1rem; align-items: center; }
.results-count { font-size: 0.8125rem; font-weight: 700; color: var(--cv-text-secondary); }
.results-filtered { font-size: 0.75rem; color: var(--cv-text-muted); }

/* ── Empty state ─────────────────────────────────────────────── */
.empty-state {
    text-align: center; padding: 4rem 2rem;
    background: var(--cv-bg-card); border: 1px solid var(--cv-border);
    border-radius: 16px;
}
.empty-icon-wrap {
    width: 4rem; height: 4rem; border-radius: 16px; margin: 0 auto 1.25rem;
    background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border);
    display: flex; align-items: center; justify-content: center;
    color: var(--cv-text-muted);
}
.empty-title { font-size: 1rem; font-weight: 700; color: var(--cv-text-primary); margin-bottom: 0.375rem; }
.empty-sub { font-size: 0.875rem; color: var(--cv-text-muted); margin-bottom: 0.25rem; }
.btn-empty-cta {
    display: inline-flex; align-items: center; gap: 0.5rem;
    margin-top: 1.25rem; padding: 0.625rem 1.25rem; border-radius: 10px;
    background: linear-gradient(135deg, #C46D08, #E8830A);
    color: #1A0A00; font-size: 0.875rem; font-weight: 800;
    text-decoration: none; transition: all 0.15s;
}
.btn-empty-cta:hover { transform: translateY(-1px); }

/* ── User card grid ──────────────────────────────────────────── */
.user-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

/* ── Single user card ────────────────────────────────────────── */
.user-card {
    display: flex; overflow: hidden;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(10,58,30,0.05);
    transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.user-card:hover {
    border-color: var(--cv-border-md);
    box-shadow: 0 6px 20px rgba(10,58,30,0.1);
    transform: translateY(-2px);
}
.card-inactive { opacity: 0.65; }
.card-super { border-color: rgba(29,111,164,0.25) !important; }
.card-super:hover { border-color: rgba(29,111,164,0.45) !important; }

/* Coloured left border stripe */
.card-stripe { width: 4px; flex-shrink: 0; }
.stripe-green { background: linear-gradient(to bottom, #1A6B3A, #4DBF7A); }
.stripe-blue  { background: linear-gradient(to bottom, #1D6FA4, #4A9EC5); }
.stripe-muted { background: var(--cv-border); }

/* Card content */
.card-body { flex: 1; padding: 1rem 1rem 0.875rem; display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }

.card-top { display: flex; align-items: flex-start; gap: 0.75rem; }
.user-avatar {
    width: 2.75rem; height: 2.75rem; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
}
.avatar-green { background: linear-gradient(135deg, #1A6B3A, #2E9E5A); }
.avatar-blue  { background: linear-gradient(135deg, #1D6FA4, #2B8FC5); }
.avatar-initials { font-size: 0.875rem; font-weight: 800; color: white; }

.card-name-block { flex: 1; min-width: 0; }
.card-name    { font-size: 0.9375rem; font-weight: 700; color: var(--cv-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-name-ar { font-size: 0.8rem; color: var(--cv-text-secondary); margin-top: 1px; }
.card-email   { font-size: 0.75rem; color: var(--cv-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }

.badge-super {
    display: inline-flex; align-items: center; gap: 0.25rem;
    font-size: 0.625rem; font-weight: 800; letter-spacing: 0.08em;
    padding: 0.2rem 0.5rem; border-radius: 5px;
    background: rgba(29,111,164,0.12); color: #2B8FC5;
    border: 1px solid rgba(29,111,164,0.25); flex-shrink: 0;
}

/* Meta chips */
.card-meta { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.role-badge {
    display: inline-flex; align-items: center;
    font-size: 0.6875rem; font-weight: 700;
    padding: 0.2rem 0.5rem; border-radius: 5px; border: 1px solid;
}
.role-badge-green { background: rgba(26,107,58,0.1);  color: #2E9E5A; border-color: rgba(26,107,58,0.25); }
.role-badge-blue  { background: rgba(29,111,164,0.1); color: #2B8FC5; border-color: rgba(29,111,164,0.25); }
.role-badge-amber { background: rgba(232,131,10,0.1); color: #C46D08; border-color: rgba(232,131,10,0.25); }
.role-badge-muted { background: var(--cv-bg-card-alt); color: var(--cv-text-muted); border-color: var(--cv-border); }

/* Permission count chip */
.perm-chip {
    display: inline-flex; align-items: center; gap: 0.25rem;
    font-size: 0.6875rem; font-weight: 600;
    padding: 0.2rem 0.5rem; border-radius: 5px; border: 1px solid;
}
.perm-chip-active { background: rgba(29,111,164,0.08); color: var(--cv-blue-text); border-color: rgba(29,111,164,0.2); }
.perm-chip-empty  { background: rgba(192,57,43,0.06); color: #C0392B; border-color: rgba(192,57,43,0.2); }

.meta-chip {
    display: inline-flex; align-items: center; gap: 0.25rem;
    font-size: 0.6875rem; font-weight: 600; color: var(--cv-text-muted);
    background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border);
    padding: 0.2rem 0.5rem; border-radius: 5px;
}
.meta-chip-blue { color: var(--cv-blue-text); border-color: rgba(29,111,164,0.2); background: rgba(29,111,164,0.05); }

/* Card footer */
.card-footer {
    display: flex; align-items: center; gap: 0.5rem;
    border-top: 1px solid var(--cv-border);
    padding-top: 0.625rem; margin-top: auto;
}
.status-badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    font-size: 0.6875rem; font-weight: 700;
}
.status-active  { color: #2E9E5A; }
.status-inactive{ color: var(--cv-text-muted); }
.status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dot-green  { background: #2E9E5A; box-shadow: 0 0 0 2px rgba(46,158,90,0.2); }
.dot-grey   { background: var(--cv-border-md); }

.last-login {
    display: inline-flex; align-items: center; gap: 0.25rem;
    font-size: 0.6875rem; color: var(--cv-text-muted); margin-left: auto;
}

.card-actions { display: flex; gap: 0.375rem; margin-left: auto; }
.action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.875rem; height: 1.875rem; border-radius: 7px;
    border: 1px solid; cursor: pointer; background: transparent;
    transition: all 0.15s;
}
.action-green  { color: #1A6B3A; border-color: rgba(26,107,58,0.3);  }
.action-green:hover  { background: rgba(26,107,58,0.1); }
.action-amber  { color: #C46D08; border-color: rgba(196,109,8,0.3);  }
.action-amber:hover  { background: rgba(196,109,8,0.1); }
.action-blue   { color: var(--cv-blue-text); border-color: rgba(29,111,164,0.3); text-decoration: none; }
.action-blue:hover   { background: rgba(29,111,164,0.1); }
.action-danger { color: #C0392B; border-color: rgba(192,57,43,0.3);  }
.action-danger:hover { background: rgba(192,57,43,0.1); }

/* ── Delete modal ────────────────────────────────────────────── */
.modal-backdrop {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center; padding: 1.5rem;
}
.modal-box {
    background: var(--cv-bg-card); border: 1px solid var(--cv-border);
    border-radius: 16px; padding: 2rem;
    max-width: 28rem; width: 100%;
    box-shadow: 0 24px 64px rgba(0,0,0,0.4);
    text-align: center;
}
.modal-danger-icon {
    width: 3.5rem; height: 3.5rem; border-radius: 14px; margin: 0 auto 1.125rem;
    background: rgba(192,57,43,0.08); border: 1px solid rgba(192,57,43,0.25);
    display: flex; align-items: center; justify-content: center;
    color: #C0392B;
}
.modal-title { font-size: 1.0625rem; font-weight: 800; color: var(--cv-text-primary); margin-bottom: 0.5rem; }
.modal-sub   { font-size: 0.875rem; color: var(--cv-text-secondary); margin-bottom: 0.5rem; line-height: 1.5; }
.modal-alert {
    display: flex; align-items: flex-start; gap: 0.5rem;
    margin: 1rem 0; padding: 0.75rem;
    background: rgba(192,57,43,0.06); border: 1px solid rgba(192,57,43,0.2);
    border-radius: 8px; color: #C0392B;
    font-size: 0.8125rem; text-align: start;
}
.modal-footer { display: flex; gap: 0.75rem; justify-content: center; margin-top: 1.25rem; }
.btn-modal-cancel {
    flex: 1; padding: 0.625rem; border-radius: 10px;
    background: var(--cv-bg-card-alt); border: 1.5px solid var(--cv-border);
    color: var(--cv-text-secondary); font-size: 0.875rem; font-weight: 700;
    cursor: pointer; transition: border-color 0.15s;
}
.btn-modal-cancel:hover { border-color: var(--cv-border-md); }
.btn-modal-danger {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.375rem;
    padding: 0.625rem; border-radius: 10px;
    background: linear-gradient(135deg, #C0392B, #E74C3C);
    border: none; color: white; font-size: 0.875rem; font-weight: 800;
    cursor: pointer; transition: all 0.15s;
}
.btn-modal-danger:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(192,57,43,0.35); }

/* ── Transitions ─────────────────────────────────────────────── */
.flash-enter-active, .flash-leave-active { transition: opacity 0.3s, transform 0.3s; }
.flash-enter-from  { opacity: 0; transform: translateY(-8px); }
.flash-leave-to    { opacity: 0; }

.modal-enter-active { transition: opacity 0.2s, transform 0.2s; }
.modal-leave-active { transition: opacity 0.15s; }
.modal-enter-from   { opacity: 0; transform: scale(0.96); }
.modal-leave-to     { opacity: 0; }
</style>