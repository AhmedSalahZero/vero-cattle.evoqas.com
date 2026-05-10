<script setup>
/**
 * Companies/Index.vue — VERO Cattle Management
 * Super Admin · Company List
 *
 * Props: companies[] → each has farms_count, users_count,
 *        subscription_starts_at, subscription_ends_at,
 *        is_active, accounting_mode, currency, country, logo
 */
import { ref, computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

defineOptions({ layout: AuthenticatedLayout })

const props = defineProps({
    companies:       { type: Array,  default: () => [] },
    warningDays:     { type: Number, default: 30 },
    activeCompanyId: { type: Number, default: null },  // session('selected_company_id')
})

const page  = usePage()
const flash = computed(() => page.props.flash ?? {})

// ── Filters ────────────────────────────────────────────────────────
const search             = ref('')
const filterStatus       = ref('')
const filterSubscription = ref('')

const filtered = computed(() => {
    const q = search.value.toLowerCase()
    return props.companies.filter(c => {
        if (q && !c.name_en.toLowerCase().includes(q) &&
                 !c.name_ar?.toLowerCase().includes(q) &&
                 !c.country?.toLowerCase().includes(q) &&
                 !c.email?.toLowerCase().includes(q)) return false
        if (filterStatus.value === 'active'   && !c.is_active)  return false
        if (filterStatus.value === 'inactive' &&  c.is_active)  return false
        const sub = subStatus(c)
        if (filterSubscription.value === 'expired'  && sub !== 'expired')  return false
        if (filterSubscription.value === 'expiring' && sub !== 'expiring') return false
        if (filterSubscription.value === 'active'   && sub !== 'active')   return false
        return true
    })
})

// ── Subscription helpers ───────────────────────────────────────────
function subStatus(c) {
    if (!c.subscription_ends_at) return 'none'
    const today = new Date(); today.setHours(0,0,0,0)
    const end   = new Date(c.subscription_ends_at)
    const diff  = Math.ceil((end - today) / 86400000)
    if (diff < 0)                  return 'expired'
    if (diff <= props.warningDays) return 'expiring'
    return 'active'
}
function subDays(c) {
    if (!c.subscription_ends_at) return null
    const today = new Date(); today.setHours(0,0,0,0)
    return Math.ceil((new Date(c.subscription_ends_at) - today) / 86400000)
}
function subLabel(c) {
    const days = subDays(c)
    if (days === null) return 'Unrestricted'
    if (days < 0)      return `Expired ${Math.abs(days)}d ago`
    if (days === 0)    return 'Expires today'
    if (days <= props.warningDays) return `${days} day${days===1?'':'s'} left`
    const m = Math.round(days / 30)
    return `${m} month${m===1?'':'s'} left`
}

// ── Enter / Exit company context ──────────────────────────────────
function enterCompany(c) {
    router.post(route('companies.enter', c.id))
}
function exitCompany() {
    router.post(route('companies.exit'))
}

// ── Delete confirm ─────────────────────────────────────────────────
const deleteTarget  = ref(null)
const deleteLoading = ref(false)
function confirmDelete(c) { deleteTarget.value = c }
function cancelDelete()   { deleteTarget.value = null }
function executeDelete() {
    if (!deleteTarget.value) return
    deleteLoading.value = true
    router.delete(route('companies.destroy', deleteTarget.value.id), {
        onFinish: () => { deleteLoading.value = false; deleteTarget.value = null },
    })
}

// ── Toggle active ──────────────────────────────────────────────────
function toggleActive(c) {
    router.patch(route('companies.toggle-active', c.id), {}, { preserveScroll: true })
}

// ── Utility ────────────────────────────────────────────────────────
function initials(c) {
    return c.name_en.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase()
}
function formatDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' })
}
const gradients = [
    'linear-gradient(135deg,#1A6B3A,#4DBF7A)',
    'linear-gradient(135deg,#0F4D28,#2E9E5A)',
    'linear-gradient(135deg,#E8830A,#F5A623)',
    'linear-gradient(135deg,#1D6FA4,#4DA8D8)',
    'linear-gradient(135deg,#6D3AAD,#9B6FD8)',
    'linear-gradient(135deg,#AD3A3A,#D87070)',
]
function avatarGrad(c) { return gradients[(c.id ?? 0) % gradients.length] }
</script>

<template>
    <Head title="Companies — VERO Cattle" />
    
         <div class="pg-shell">

            <!-- ══ PAGE HERO ════════════════════════════════════════ -->
            <div class="pg-hero">
                <div class="pg-hero-glow"></div>
                <div class="pg-hero-dots"></div>
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <p class="hero-eyebrow">Super Admin · Company Management</p>
                            <h1 class="hero-title">Companies</h1>
                            <p class="hero-sub">
                                {{ companies.length }}
                                {{ companies.length === 1 ? 'company' : 'companies' }} registered
                            </p>
                        </div>
                        <Link :href="route('companies.create')" class="btn-create">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                            </svg>
                            New Company
                        </Link>
                    </div>
                </div>
            </div>

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-14 pt-6">

                <!-- ══ ACTIVE COMPANY BANNER ══════════════════════ -->
                <div v-if="activeCompanyId" class="active-company-banner mb-5">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>
                        </svg>
                        <span>
                            Currently operating in:
                            <strong>{{ companies.find(c => c.id === activeCompanyId)?.name_en ?? 'Company #' + activeCompanyId }}</strong>
                        </span>
                    </div>
                    <button class="exit-btn" @click="exitCompany">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Exit Company
                    </button>
                </div>

                <!-- ══ FLASH ═══════════════════════════════════════ -->
                <Transition name="fade">
                    <div v-if="flash.success" class="flash flash-success mb-5">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        {{ flash.success }}
                    </div>
                </Transition>
                <Transition name="fade">
                    <div v-if="flash.error" class="flash flash-error mb-5">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        {{ flash.error }}
                    </div>
                </Transition>

                <!-- ══ STAT PILLS ══════════════════════════════════ -->
                <div class="stat-row mb-6">
                    <div class="stat-pill">
                        <span class="sdot sdot-green"></span>
                        <span class="sval">{{ companies.filter(c=>c.is_active).length }}</span>
                        <span class="slbl">Active</span>
                    </div>
                    <div class="stat-pill">
                        <span class="sdot sdot-amber"></span>
                        <span class="sval">{{ companies.filter(c=>subStatus(c)==='expiring').length }}</span>
                        <span class="slbl">Expiring Soon</span>
                    </div>
                    <div class="stat-pill">
                        <span class="sdot sdot-red"></span>
                        <span class="sval">{{ companies.filter(c=>subStatus(c)==='expired').length }}</span>
                        <span class="slbl">Expired</span>
                    </div>
                    <div class="stat-pill">
                        <span class="sdot sdot-blue"></span>
                        <span class="sval">{{ companies.reduce((a,c)=>a+(c.farms_count??0),0) }}</span>
                        <span class="slbl">Total Farms</span>
                    </div>
                </div>

                <!-- ══ FILTER BAR ══════════════════════════════════ -->
                <div class="filter-bar mb-6">
                    <div class="filter-search-wrap">
                        <svg class="fsearch-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                        </svg>
                        <input v-model="search" type="search" placeholder="Search companies…" class="fsearch-input" />
                    </div>
                    <select v-model="filterStatus" class="fselect">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <select v-model="filterSubscription" class="fselect">
                        <option value="">All Subscriptions</option>
                        <option value="active">Active</option>
                        <option value="expiring">Expiring Soon</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>

                <!-- ══ EMPTY STATE ═════════════════════════════════ -->
                <div v-if="filtered.length === 0" class="empty-shell">
                    <div class="empty-icon">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                        </svg>
                    </div>
                    <h3 class="empty-title">No Companies Found</h3>
                    <p class="empty-sub">
                        {{ companies.length === 0
                            ? 'Get started by creating your first company.'
                            : 'Try adjusting your search or filters.' }}
                    </p>
                    <Link v-if="companies.length === 0" :href="route('companies.create')" class="btn-create mt-5 inline-flex">
                        Create First Company
                    </Link>
                </div>

                <!-- ══ CARD GRID ═══════════════════════════════════ -->
                <div v-else class="co-grid">
                    <div v-for="company in filtered" :key="company.id" class="co-card">

                        <!-- Top accent stripe -->
                        <div class="co-stripe"
                             :class="{
                                 'stripe-green':    company.is_active && subStatus(company) !== 'expired' && subStatus(company) !== 'expiring',
                                 'stripe-amber':    subStatus(company) === 'expiring',
                                 'stripe-red':      subStatus(company) === 'expired',
                                 'stripe-inactive': !company.is_active,
                             }">
                        </div>

                        <!-- Header -->
                        <div class="co-head">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                <div v-if="company.logo" class="co-logo">
                                    <img :src="`/storage/${company.logo}`" :alt="company.name_en" class="w-full h-full object-cover"/>
                                </div>
                                <div v-else class="co-avatar" :style="{ background: avatarGrad(company) }">
                                    {{ initials(company) }}
                                </div>
                                <div class="min-w-0">
                                    <h3 class="co-name">{{ company.name_en }}</h3>
                                    <p class="co-name-ar" dir="rtl">{{ company.name_ar }}</p>
                                </div>
                            </div>
                            <button @click="toggleActive(company)"
                                    :class="company.is_active ? 'sb-active' : 'sb-inactive'"
                                    class="status-btn">
                                <span class="sb-dot"></span>
                                {{ company.is_active ? 'Active' : 'Inactive' }}
                            </button>
                        </div>

                        <!-- Chips row -->
                        <div class="chip-row">
                            <span v-if="company.country" class="chip">
                                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                {{ company.country }}
                            </span>
                            <span v-if="company.currency" class="chip">
                                {{ company.currency }}
                            </span>
                            <span :class="company.accounting_mode==='odoo' ? 'chip-odoo' : 'chip-sa'">
                                {{ company.accounting_mode === 'odoo' ? 'Odoo' : 'Standalone' }}
                            </span>
                        </div>

                        <!-- Subscription block -->
                        <div class="sub-block" :class="`sub-${subStatus(company)}`">
                            <div class="sub-inner">
                                <div>
                                    <p class="sub-lbl">Subscription Period</p>
                                    <p class="sub-dates">
                                        <span v-if="company.subscription_starts_at">{{ formatDate(company.subscription_starts_at) }}</span>
                                        <span v-if="company.subscription_starts_at && company.subscription_ends_at"> → </span>
                                        <span v-if="company.subscription_ends_at">{{ formatDate(company.subscription_ends_at) }}</span>
                                        <span v-if="!company.subscription_ends_at" class="sub-none-txt">No expiry set</span>
                                    </p>
                                </div>
                                <span :class="`sub-rem sub-rem-${subStatus(company)}`">{{ subLabel(company) }}</span>
                            </div>
                            <div v-if="subStatus(company)==='expiring' && subDays(company)!==null" class="sub-bar-track">
                                <div class="sub-bar-fill"
                                     :style="{width: Math.min(100, Math.round((subDays(company)/warningDays)*100))+'%'}">
                                </div>
                            </div>
                        </div>

                        <!-- Stats row -->
                        <div class="co-stats">
                            <div class="co-stat">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                                </svg>
                                <span class="co-stat-val">{{ company.farms_count ?? 0 }}</span>
                                <span class="co-stat-lbl">Farms</span>
                            </div>
                            <div class="co-divider"></div>
                            <div class="co-stat">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span class="co-stat-val">{{ company.users_count ?? 0 }}</span>
                                <span class="co-stat-lbl">Users</span>
                            </div>
                            <div class="co-divider"></div>
                            <div class="co-stat flex-1 min-w-0">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                <span class="co-stat-lbl truncate">{{ company.email ?? '—' }}</span>
                            </div>
                        </div>

                        <!-- Footer actions -->
                        <div class="co-footer">
                            <span class="co-id">#{{ company.id }}</span>
                            <div class="co-actions">
                                <!-- Enter company context -->
                                <button @click="enterCompany(company)"
                                        class="act-btn act-enter"
                                        :class="{ 'act-enter-active': activeCompanyId === company.id }"
                                        :title="activeCompanyId === company.id ? 'Currently active' : 'Enter this company'">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>
                                    </svg>
                                    {{ activeCompanyId === company.id ? 'Active' : 'Enter' }}
                                </button>
                                <Link :href="route('companies.edit', company.id)" class="act-btn act-edit" title="Edit">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                                    </svg>
                                    Edit
                                </Link>
                                <button @click="confirmDelete(company)"
                                        :disabled="(company.users_count??0) > 0"
                                        :title="(company.users_count??0)>0 ? 'Has users — reassign or delete users first' : 'Delete company and all its data'"
                                        class="act-btn act-del"
                                        :class="{'act-disabled':(company.users_count??0)>0}">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ══ DELETE MODAL ═══════════════════════════════════════════ -->
        <Teleport to="body">
            <Transition name="modal">
                <div v-if="deleteTarget" class="del-backdrop" @click.self="cancelDelete">
                    <div class="del-box">
                        <div class="del-icon-wrap">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                            </svg>
                        </div>
                        <h3 class="del-title">Delete Company</h3>
                        <p class="del-body">
                            Are you sure you want to permanently delete
                            <strong class="del-company">{{ deleteTarget?.name_en }}</strong>?
                            This cannot be undone.
                        </p>
                        <div class="del-footer">
                            <button class="del-btn-cancel" @click="cancelDelete">Cancel</button>
                            <button class="del-btn-confirm"
                                    :class="{'btn-loading': deleteLoading}"
                                    @click="executeDelete">
                                <svg v-if="!deleteLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                                </svg>
                                Delete Company
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

    
</template>

<style scoped>

/* ── Page shell ─────────────────────────────────────────────────── */
.pg-shell { min-height: 100vh; background-color: var(--cv-bg); }

/* ── Hero ───────────────────────────────────────────────────────── */
.pg-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #0A3A1E 0%, #155C30 55%, #1A6B3A 100%);
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.pg-hero-glow {
    position: absolute; top: -60px; right: -60px;
    width: 380px; height: 380px; border-radius: 50%;
    background: radial-gradient(circle, rgba(77,191,122,0.18) 0%, transparent 65%);
    pointer-events: none;
}
.pg-hero-dots {
    position: absolute; inset: 0; pointer-events: none;
    background-image: radial-gradient(circle, rgba(255,255,255,0.06) 1px, transparent 1px);
    background-size: 22px 22px;
    mask-image: linear-gradient(to bottom, rgba(0,0,0,0.5), transparent);
}
.hero-eyebrow {
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.16em; text-transform: uppercase;
    color: #4DBF7A; margin-bottom: 0.5rem;
}
.hero-title {
    font-size: 1.875rem; font-weight: 800;
    color: #ffffff; line-height: 1.1;
    letter-spacing: -0.02em;
}
.hero-sub {
    font-size: 0.875rem; color: rgba(255,255,255,0.5);
    margin-top: 0.375rem;
}
.btn-create {
    display: inline-flex; align-items: center; gap: 0.5rem;
    background: #4DBF7A; color: #062210;
    font-size: 0.875rem; font-weight: 700;
    padding: 0.6875rem 1.375rem;
    border-radius: 10px; border: none;
    text-decoration: none; cursor: pointer;
    transition: all 0.15s ease;
    position: relative; z-index: 1;
    box-shadow: 0 4px 14px rgba(77,191,122,0.3);
}
.btn-create:hover {
    background: #5DD98A;
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(77,191,122,0.4);
}

/* ── Breadcrumb eyebrow ─────────────────────────────────────────── */
.eyebrow-crumb {
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--cv-amber);
}

/* ── Flash ──────────────────────────────────────────────────────── */
.flash {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.875rem 1.125rem; border-radius: 10px;
    font-size: 0.875rem; font-weight: 500; border: 1px solid;
}
.flash-success {
    background: rgba(26,107,58,0.08); border-color: rgba(26,107,58,0.22); color: #1A6B3A;
}
.flash-error {
    background: rgba(192,57,43,0.08); border-color: rgba(192,57,43,0.22); color: #C0392B;
}

/* ── Stat pills ─────────────────────────────────────────────────── */
.stat-row { display: flex; flex-wrap: wrap; gap: 0.625rem; }
.stat-pill {
    display: inline-flex; align-items: center; gap: 0.5rem;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 9999px;
    padding: 0.4rem 0.9rem;
}
.sdot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}
.sdot-green { background: #1A6B3A; box-shadow: 0 0 0 3px rgba(26,107,58,0.15); }
.sdot-amber { background: #E8830A; box-shadow: 0 0 0 3px rgba(232,131,10,0.15); }
.sdot-red   { background: #C0392B; box-shadow: 0 0 0 3px rgba(192,57,43,0.15); }
.sdot-blue  { background: #1D6FA4; box-shadow: 0 0 0 3px rgba(29,111,164,0.15); }
.sval  { font-size: 0.875rem; font-weight: 700; color: var(--cv-text-primary); }
.slbl  { font-size: 0.75rem; color: var(--cv-text-muted); }

/* ── Filter bar ─────────────────────────────────────────────────── */
.filter-bar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
.filter-search-wrap { position: relative; flex: 1; min-width: 220px; }
.fsearch-icon {
    position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%);
    width: 1rem; height: 1rem; color: var(--cv-text-muted); pointer-events: none;
}
.fsearch-input {
    width: 100%;
    background: var(--cv-bg-card);
    border: 1.5px solid var(--cv-border);
    border-radius: 10px;
    padding: 0.625rem 0.875rem 0.625rem 2.5rem;
    font-size: 0.875rem; color: var(--cv-text-primary);
    transition: border-color 0.15s, box-shadow 0.15s; font-family: inherit;
}
.fsearch-input:focus {
    outline: none; border-color: #1A6B3A;
    box-shadow: 0 0 0 3px rgba(26,107,58,0.12);
    background: var(--cv-bg-white);
}
.fsearch-input::placeholder { color: var(--cv-text-placeholder); }
.fselect {
    background: var(--cv-bg-card);
    border: 1.5px solid var(--cv-border);
    border-radius: 10px;
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem; color: var(--cv-text-secondary);
    min-width: 155px; cursor: pointer;
    font-family: inherit; transition: border-color 0.15s;
}
.fselect:focus { outline: none; border-color: #1A6B3A; }

/* ── Card grid ──────────────────────────────────────────────────── */
.co-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.25rem;
}

/* ── Company card ───────────────────────────────────────────────── */
.co-card {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 14px; overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow: 0 2px 10px rgba(10,58,30,0.06);
    transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
}
.co-card:hover {
    border-color: rgba(26,107,58,0.35);
    box-shadow: 0 10px 32px rgba(10,58,30,0.13);
    transform: translateY(-2px);
}

/* Accent stripe */
.co-stripe { height: 3.5px; width: 100%; }
.stripe-green    { background: linear-gradient(90deg, #1A6B3A 0%, #4DBF7A 100%); }
.stripe-amber    { background: linear-gradient(90deg, #C46D08 0%, #F5A623 100%); }
.stripe-red      { background: linear-gradient(90deg, #A93226 0%, #E74C3C 100%); }
.stripe-inactive { background: var(--cv-border-md); }

/* Card head */
.co-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 1.125rem 1.25rem 0.75rem; gap: 0.75rem;
}
.co-logo {
    width: 2.75rem; height: 2.75rem; border-radius: 10px;
    overflow: hidden; border: 1px solid var(--cv-border); flex-shrink: 0;
}
.co-avatar {
    width: 2.75rem; height: 2.75rem; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.875rem; font-weight: 800; color: white; flex-shrink: 0;
    letter-spacing: 0.05em;
}
.co-name {
    font-size: 0.9375rem; font-weight: 700;
    color: var(--cv-text-primary); line-height: 1.25;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;
}
.co-name-ar {
    font-size: 0.6875rem; color: var(--cv-text-muted);
    line-height: 1.3; margin-top: 1px;
}

/* Status toggle badge */
.status-btn {
    display: inline-flex; align-items: center; gap: 0.35rem;
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.08em; text-transform: uppercase;
    padding: 0.25rem 0.625rem; border-radius: 9999px;
    cursor: pointer; border: 1px solid;
    transition: opacity 0.15s; flex-shrink: 0;
}
.status-btn:hover { opacity: 0.72; }
.sb-active  { background:rgba(26,107,58,0.10); color:#1A6B3A; border-color:rgba(26,107,58,0.28); }
.sb-inactive{ background:#F1F5F9; color:#64748B; border-color:#CBD5E1; }
.sb-dot     { width:5px; height:5px; border-radius:50%; background:currentColor; }

/* Chip row */
.chip-row { display: flex; flex-wrap: wrap; gap: 0.375rem; padding: 0 1.25rem 0.875rem; }
.chip {
    display: inline-flex; align-items: center; gap: 0.3rem;
    font-size: 0.6875rem; font-weight: 500;
    background: var(--cv-bg-card-alt); color: var(--cv-text-muted);
    border: 1px solid var(--cv-border);
    padding: 0.2rem 0.55rem; border-radius: 6px;
}
.chip-sa {
    font-size: 0.6875rem; font-weight: 600;
    background: rgba(232,131,10,0.08); color: #C46D08;
    border: 1px solid rgba(232,131,10,0.22);
    padding: 0.2rem 0.55rem; border-radius: 6px;
}
.chip-odoo {
    font-size: 0.6875rem; font-weight: 600;
    background: rgba(29,111,164,0.08); color: #1D6FA4;
    border: 1px solid rgba(29,111,164,0.22);
    padding: 0.2rem 0.55rem; border-radius: 6px;
}

/* Subscription block */
.sub-block {
    margin: 0 1.25rem 0.875rem;
    border-radius: 10px; padding: 0.8rem 0.9rem; border: 1px solid;
}
.sub-none     { background: var(--cv-bg-card-alt);         border-color: var(--cv-border); }
.sub-active   { background: rgba(26,107,58,0.06);          border-color: rgba(26,107,58,0.22); }
.sub-expiring { background: rgba(232,131,10,0.06);         border-color: rgba(232,131,10,0.25); }
.sub-expired  { background: rgba(192,57,43,0.06);          border-color: rgba(192,57,43,0.25); }

.sub-inner { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.sub-lbl {
    font-size: 0.5625rem; font-weight: 700;
    letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--cv-text-muted); margin-bottom: 0.2rem;
}
.sub-dates  { font-size: 0.75rem; color: var(--cv-text-secondary); }
.sub-none-txt { color: var(--cv-text-muted); font-style: italic; }

.sub-rem { font-size: 0.8125rem; font-weight: 800; white-space: nowrap; }
.sub-rem-none     { color: var(--cv-text-muted); }
.sub-rem-active   { color: #1A6B3A; }
.sub-rem-expiring { color: #E8830A; }
.sub-rem-expired  { color: #C0392B; }

.sub-bar-track {
    height: 3px; margin-top: 0.5rem;
    background: rgba(232,131,10,0.15); border-radius: 9999px; overflow: hidden;
}
.sub-bar-fill {
    height: 100%; border-radius: 9999px;
    background: linear-gradient(90deg, #E8830A, #F5C660);
    transition: width 0.4s ease;
}

/* Stats row */
.co-stats {
    display: flex; align-items: center;
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--cv-border);
    gap: 0.5rem;
}
.co-stat {
    display: flex; align-items: center; gap: 0.375rem;
    color: var(--cv-text-muted); font-size: 0.75rem;
}
.co-stat-val { font-weight: 800; font-size: 0.9375rem; color: var(--cv-text-primary); }
.co-stat-lbl { color: var(--cv-text-muted); }
.co-divider  { width: 1px; height: 18px; background: var(--cv-border); flex-shrink: 0; }

/* Footer */
.co-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--cv-border);
    background: var(--cv-bg-card-alt);
}
.co-id { font-size: 0.6875rem; color: var(--cv-text-muted); font-variant-numeric: tabular-nums; }
.co-actions { display: flex; gap: 0.5rem; }

.act-btn {
    display: inline-flex; align-items: center; gap: 0.35rem;
    font-size: 0.75rem; font-weight: 700;
    padding: 0.4rem 0.8rem; border-radius: 7px;
    cursor: pointer; border: 1px solid; text-decoration: none;
    transition: all 0.15s ease;
}
.act-edit {
    background: rgba(26,107,58,0.08); color: #1A6B3A; border-color: rgba(26,107,58,0.22);
}
.act-edit:hover {
    background: rgba(26,107,58,0.16); border-color: rgba(26,107,58,0.45);
    box-shadow: 0 2px 8px rgba(26,107,58,0.15);
}
.act-del {
    background: rgba(192,57,43,0.08); color: #C0392B; border-color: rgba(192,57,43,0.22);
}
.act-del:hover {
    background: rgba(192,57,43,0.16); border-color: rgba(192,57,43,0.45);
    box-shadow: 0 2px 8px rgba(192,57,43,0.15);
}
.act-enter {
    background: rgba(29,111,164,0.08); color: #1D6FA4; border-color: rgba(29,111,164,0.22);
}
.act-enter:hover {
    background: rgba(29,111,164,0.16); border-color: rgba(29,111,164,0.45);
    box-shadow: 0 2px 8px rgba(29,111,164,0.15);
}
.act-enter-active {
    background: rgba(26,107,58,0.12); color: #1A6B3A; border-color: rgba(26,107,58,0.4);
}

/* Active company banner */
.active-company-banner {
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(29,111,164,0.08);
    border: 1px solid rgba(29,111,164,0.25);
    border-radius: 10px; padding: 0.625rem 1rem;
    color: #1D6FA4; font-size: 0.8125rem; font-weight: 600;
}
.exit-btn {
    display: inline-flex; align-items: center; gap: 0.3rem;
    font-size: 0.75rem; font-weight: 700;
    padding: 0.3rem 0.7rem; border-radius: 6px;
    background: rgba(192,57,43,0.08); color: #C0392B;
    border: 1px solid rgba(192,57,43,0.22);
    cursor: pointer; font-family: inherit;
    transition: background 0.15s;
}
.exit-btn:hover { background: rgba(192,57,43,0.16); }

.act-disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }

/* ── Empty state ────────────────────────────────────────────────── */
.empty-shell {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; text-align: center;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border); border-radius: 16px;
    padding: 5.5rem 2rem;
}
.empty-icon {
    width: 4.5rem; height: 4.5rem;
    background: rgba(26,107,58,0.07);
    border: 1px solid rgba(26,107,58,0.18);
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    color: #1A6B3A; margin-bottom: 1.25rem;
}
.empty-title { font-size: 1.125rem; font-weight: 700; color: var(--cv-text-primary); margin-bottom: 0.4rem; }
.empty-sub   { font-size: 0.875rem; color: var(--cv-text-muted); max-width: 340px; }

/* ── Delete modal ───────────────────────────────────────────────── */
.del-backdrop {
    position: fixed; inset: 0; z-index: 50;
    display: flex; align-items: center; justify-content: center; padding: 1.5rem;
    background: rgba(4,18,8,0.7); backdrop-filter: blur(5px);
}
.del-box {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 16px;
    box-shadow: 0 28px 72px rgba(0,0,0,0.25);
    width: 100%; max-width: 420px;
    padding: 1.75rem;
    display: flex; flex-direction: column; gap: 0.875rem;
}
.del-icon-wrap {
    width: 2.75rem; height: 2.75rem; border-radius: 50%;
    background: rgba(192,57,43,0.1); border: 1px solid rgba(192,57,43,0.25);
    display: flex; align-items: center; justify-content: center; color: #C0392B;
    align-self: flex-start;
}
.del-title   { font-size: 1.0625rem; font-weight: 800; color: var(--cv-text-primary); }
.del-body    { font-size: 0.875rem; color: var(--cv-text-secondary); line-height: 1.6; }
.del-company { color: var(--cv-text-primary); }
.del-footer  {
    display: flex; justify-content: flex-end; gap: 0.75rem;
    padding-top: 0.75rem; border-top: 1px solid var(--cv-border);
}
.del-btn-cancel {
    padding: 0.5625rem 1.125rem; border-radius: 9px;
    font-size: 0.875rem; font-weight: 600;
    background: var(--cv-bg-card-alt);
    border: 1.5px solid var(--cv-border);
    color: var(--cv-text-secondary); cursor: pointer;
    font-family: inherit; transition: border-color 0.15s;
}
.del-btn-cancel:hover { border-color: var(--cv-border-md); }
.del-btn-confirm {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.5625rem 1.25rem; border-radius: 9px;
    font-size: 0.875rem; font-weight: 700;
    background: #C0392B; border: 1.5px solid #C0392B;
    color: white; cursor: pointer; font-family: inherit;
    transition: background 0.15s, box-shadow 0.15s;
}
.del-btn-confirm:hover {
    background: #A93226;
    box-shadow: 0 4px 16px rgba(192,57,43,0.35);
}

/* ── Transitions ────────────────────────────────────────────────── */
.fade-enter-active  { transition: opacity 0.25s ease; }
.fade-leave-active  { transition: opacity 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.modal-enter-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.modal-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-from .del-box { transform: scale(0.96) translateY(8px); }
</style>