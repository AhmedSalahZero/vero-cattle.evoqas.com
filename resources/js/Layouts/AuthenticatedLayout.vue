<script setup>
/**
 * AuthenticatedLayout.vue
 * ─────────────────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Authenticated shell
 *
 * Slots
 *   default  → page content
 *   #header  → breadcrumb / page title shown in the top header bar
 *
 * Inertia shared props (HandleInertiaRequests middleware):
 *   auth.user          → { name, language, theme, is_super_admin, role }
 *   accounting_mode    → 'standalone' | 'odoo'
 *   current_farm       → { id, name_en, name_ar } | null
 *   flash.success      → one-time success string
 *   flash.error        → one-time error string
 *
 * Changes vs original:
 *   • Added isSuperAdmin and userRole computed helpers
 *   • Added a "Settings" navGroup at the bottom of navGroups
 *     containing: Users (Farm Owner + Super Admin), Farms (Farm Owner + Super Admin),
 *     Companies (Super Admin only)
 *   • navGroups converted from a static const to a computed so the Settings
 *     group can react to the logged-in user's role without a page reload
 *   • Added a "Manage Users" shortcut inside the user dropdown menu
 * ─────────────────────────────────────────────────────────────────────────────
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

// ── Sidebar scroll preservation ───────────────────────────────────
const sidebarScrollTop = ref(0)
const sidebarEl        = ref(null)

router.on('before', () => {
    if (sidebarEl.value) sidebarScrollTop.value = sidebarEl.value.scrollTop
})

router.on('navigate', () => {
    setTimeout(() => {
        if (sidebarEl.value) sidebarEl.value.scrollTop = sidebarScrollTop.value
    }, 500)
})

// ── Inertia page ─────────────────────────────────────────────────
const page           = usePage()
const { user, isSuperAdmin, can } = usePermissions()
const accountingMode = computed(() => page.props.accounting_mode ?? 'standalone')
const currentFarm    = computed(() => page.props.current_farm ?? null)

const canManageUsers = computed(() => can('view users'))
const canManageFarms = computed(() => can('view farms'))
const canManageCompanies = computed(() => can('view companies'))

// ── Language ─────────────────────────────────────────────────────
// Single source of truth — shared singleton from useLocale().
// Every page that calls useLocale() shares the same locale ref,
// so toggling here instantly re-renders ALL components app-wide.
const { locale: lang, isRtl, setLocale } = useLocale()

const switchLang = (l) => {
    if (lang.value === l) return
    // 1. Update the shared singleton → every component reacts instantly
    setLocale(l)
    // 2. Persist to DB in the background (no page reload needed)
    router.post(route('language.toggle'), { language: l }, {
        preserveState:  true,
        preserveScroll: true,
        only: [],
    })
}

// Bilingual label helper used in this layout's template
const t = (obj) => (lang.value === 'ar' ? obj.ar : obj.en)

// ── Sidebar ───────────────────────────────────────────────────────
const sidebarCollapsed  = ref(false)
const mobileSidebarOpen = ref(false)
const toggleSidebar     = () => { sidebarCollapsed.value  = !sidebarCollapsed.value }
const toggleMobile      = () => { mobileSidebarOpen.value = !mobileSidebarOpen.value }
const closeMobile       = () => { mobileSidebarOpen.value = false }

// ── User menu ─────────────────────────────────────────────────────
const userMenuOpen   = ref(false)
const toggleUserMenu = () => { userMenuOpen.value = !userMenuOpen.value }
const closeUserMenu  = () => { userMenuOpen.value = false }

const handleOutside = (e) => {
    const el = document.getElementById('cv-user-menu-trigger')
    if (el && !el.contains(e.target)) closeUserMenu()
}
onMounted(()      => document.addEventListener('click',  handleOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleOutside))

// ── Computed helpers ──────────────────────────────────────────────
const userInitials = computed(() => {
    const name = user.value?.name ?? 'U'
    return name.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()
})

const isActive = (routeName) => {
    try { return route().current(routeName + '*') } catch { return false }
}

const logout = () => router.post(route('logout'))

// ══════════════════════════════════════════════════════════════════
// NAVIGATION STRUCTURE
// Converted to computed so the Settings group reacts to auth role.
// ══════════════════════════════════════════════════════════════════
const navGroups = computed(() => {

    const groups = [

        // ── Operations ──────────────────────────────────────────────
        {
            label: { en: 'Operations', ar: 'العمليات' },
            items: [
                {
                    label:     { en: 'Dashboard', ar: 'لوحة المؤشرات' },
                    routeName: 'dashboard',
                    icon: `<rect x="3" y="3" width="7" height="7" rx="1"/>
                           <rect x="14" y="3" width="7" height="7" rx="1"/>
                           <rect x="3" y="14" width="7" height="7" rx="1"/>
                           <rect x="14" y="14" width="7" height="7" rx="1"/>`,
                },
                {
                    label:     { en: 'Lots', ar: 'المجموعات' },
                    routeName: 'lots.index',
                    icon: `<path d="M2 20h20"/>
                           <path d="M5 20V8l7-5 7 5v12"/>
                           <path d="M9 20v-5h6v5"/>`,
                },
                {
                    label:     { en: 'Procurement', ar: 'المشتريات' },
                    routeName: 'procurement.index',
                    icon: `<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                           <line x1="3" y1="6" x2="21" y2="6"/>
                           <path d="M16 10a4 4 0 0 1-8 0"/>`,
                },
                {
                    label:     { en: 'Cattle', ar: 'المواشي' },
                    routeName: 'animals.index',
                    icon: `<path d="M4 9c0-1.1.9-2 2-2h2L9 5h6l1 2h2a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9z"/>
                           <circle cx="9" cy="12" r="1" fill="currentColor"/>
                           <circle cx="15" cy="12" r="1" fill="currentColor"/>
                           <path d="M9 16s1 1 3 1 3-1 3-1"/>`,
                },
                
            ],
        },

        // ── Herd Health ──────────────────────────────────────────────
        {
            label: { en: 'Herd Health', ar: 'صحة القطيع' },
            items: [
                {
                    label:     { en: 'Health Records', ar: 'السجلات الصحية' },
                    routeName: 'health.index',
                    icon: `<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>`,
                },
                {
                    label:     { en: 'Vaccinations', ar: 'التطعيمات' },
                    routeName: 'vaccinations.index',
                    icon: `<path d="M18 2l4 4"/>
                           <path d="m17 7 3-3"/>
                           <path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/>
                           <path d="m9 11 4 4"/>
                           <path d="m5 19-3 3"/>
                           <path d="m14 4 6 6"/>`,
                },
                // {
                //     label:     { en: 'Breeding', ar: 'التزاوج' },
                //     routeName: 'breeding.index',
                //     icon: `<circle cx="8" cy="8" r="4"/>
                //            <circle cx="16" cy="16" r="4"/>
                //            <path d="M12 8c0 2.2-1.8 4-4 4"/>
                //            <path d="M12 16c0-2.2 1.8-4 4-4"/>
                //            <line x1="12" y1="8" x2="12" y2="16"/>`,
                // },
                // {
                //     label:     { en: 'Calving', ar: 'الولادة' },
                //     routeName: 'calvings.index',
                //     icon: `<path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/>`,
                // },
            ],
        },

        // ── Feed & Weight ────────────────────────────────────────────
        {
            label: { en: 'Feed & Weight', ar: 'الغذاء والوزن' },
            items: [
                // {
                //     label:     { en: 'Feed Items', ar: 'المواد العلفية' },
                //     routeName: 'feed.items.index',
                //     icon: `<path d="M12 22V12"/>
                //            <path d="M12 12C12 7 8 4 8 4s0 5 4 8z"/>
                //            <path d="M12 12c0-5 4-8 4-8s0 5-4 8z"/>
                //            <path d="M12 17c-2.5 0-4-2-4-2s1.5-2 4-2 4 2 4 2-1.5 2-4 2z"/>`,
                // },
                // {
                //     label:     { en: 'Rations', ar: 'الحصص الغذائية' },
                //     routeName: 'feed.rations.index',
                //     icon: `<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                //            <rect x="9" y="3" width="6" height="4" rx="1"/>
                //            <path d="M9 12h6"/>
                //            <path d="M9 16h4"/>`,
                // },
                {
                    label:     { en: 'Feed Consumption', ar: 'استهلاك العلف' },
                    routeName: 'feed.consumption.index',
                    icon: `<path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
                           <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
                           <line x1="6" y1="1" x2="6" y2="4"/>
                           <line x1="10" y1="1" x2="10" y2="4"/>
                           <line x1="14" y1="1" x2="14" y2="4"/>`,
                },
                {
                    label:     { en: 'Weight Records', ar: 'سجلات الوزن' },
                    routeName: 'weights.index',
                    icon: `<path d="M12 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" fill="currentColor"/>
                           <path d="M12 3v4"/>
                           <path d="M3 20h18"/>
                           <path d="M5 20l3-8h8l3 8"/>
                           <path d="M3 11h18"/>
                           <path d="M3 11L6 3"/>
                           <path d="M21 11l-3-8"/>`,
                },
            ],
        },

        // ── Sales & Stock ────────────────────────────────────────────
        {
            label: { en: 'Sales & Stock', ar: 'المبيعات والمخزون' },
            items: [
                {
                    label:     { en: 'Inventory', ar: 'المخزون' },
                    routeName: 'inventory.index',
                    icon: `<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                           <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                           <line x1="12" y1="22.08" x2="12" y2="12"/>`,
                },
                {
                    label:     { en: 'Slaughter', ar: 'الذبح' },
                    routeName: 'slaughter.index',
                    icon: `<path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                           <path d="M12 7V4"/>
                           <path d="M8 7V5"/>
                           <path d="M16 7V5"/>`,
                },
                {
                    label:     { en: 'Sales', ar: 'المبيعات' },
                    routeName: 'sales.index',
                    icon: `<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                           <polyline points="17 6 23 6 23 12"/>`,
                },
            ],
        },

        // ── Accounting ───────────────────────────────────────────────
        {
            label: { en: 'Accounting', ar: 'المحاسبة' },
            items: [
                {
                    label:     { en: 'Chart of Accounts', ar: 'دليل الحسابات' },
                    routeName: 'accounts.index',
                    icon: `<rect x="10" y="2" width="4" height="4" rx="1"/>
                           <rect x="2" y="17" width="4" height="4" rx="1"/>
                           <rect x="10" y="17" width="4" height="4" rx="1"/>
                           <rect x="18" y="17" width="4" height="4" rx="1"/>
                           <path d="M12 6v4"/>
                           <path d="M4 17v-2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2"/>
                           <path d="M12 10v3"/>`,
                },
                {
                    label:     { en: 'Fiscal Years', ar: 'السنوات المالية' },
                    routeName: 'fiscal-years.index',
                    icon: `<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                           <line x1="16" y1="2" x2="16" y2="6"/>
                           <line x1="8" y1="2" x2="8" y2="6"/>
                           <line x1="3" y1="10" x2="21" y2="10"/>
                           <path d="M8 14h.01"/>
                           <path d="M12 14h.01"/>
                           <path d="M16 14h.01"/>`,
                },
                {
                    label:     { en: 'Journal Entries', ar: 'القيود اليومية' },
                    routeName: 'journal-entries.index',
                    icon: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                           <polyline points="14 2 14 8 20 8"/>
                           <line x1="16" y1="13" x2="8" y2="13"/>
                           <line x1="16" y1="17" x2="8" y2="17"/>
                           <polyline points="10 9 9 9 8 9"/>`,
                },
                {
                    label:     { en: 'AP Invoices', ar: 'فواتير الموردين' },
                    routeName: 'ap-invoices.index',
                    icon: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                           <polyline points="14 2 14 8 20 8"/>
                           <path d="M12 18v-6"/>
                           <path d="M9 15l3 3 3-3"/>`,
                },
                {
                    label:     { en: 'AR Invoices', ar: 'فواتير العملاء' },
                    routeName: 'ar-invoices.index',
                    icon: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                           <polyline points="14 2 14 8 20 8"/>
                           <path d="M12 12v6"/>
                           <path d="M9 15l3-3 3 3"/>`,
                },
                {
                    label:     { en: 'Payments', ar: 'المدفوعات' },
                    routeName: 'payments.index',
                    icon: `<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                           <line x1="1" y1="10" x2="23" y2="10"/>
                           <path d="M6 15h2"/>
                           <path d="M10 15h4"/>`,
                },
                {
                    label:     { en: 'Farm Cost Allocation', ar: 'تخصيص تكاليف المزرعة' },
                    routeName: 'farm-cost-allocations.index',
                    icon: `<path d="M12 2v20"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        <circle cx="12" cy="12" r="2" fill="currentColor"/>`,
                },
                
                {
                    label:     { en: 'FX Rates', ar: 'أسعار الصرف' },
                    routeName: 'fx-rates.index',
                    icon: `<path d="M8 3L4 7l4 4"/>
                        <path d="M4 7h16"/>
                        <path d="M16 21l4-4-4-4"/>
                        <path d="M20 17H4"/>`,
                },
             
                {
                    label:     { en: 'Fixed Assets', ar: 'الأصول الثابتة' },
                    routeName: 'fixed-assets.index',
                    icon: `<path d="M2 20h20"/>
                           <path d="M9 20V8l-5 3"/>
                           <path d="M15 20V8l5 3"/>
                           <rect x="9" y="3" width="6" height="5"/>
                           <path d="M12 8v5"/>`,
                },
                {
                    label:     { en: 'Suppliers', ar: 'الموردون' },
                    routeName: 'suppliers.index',
                    icon: `<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                           <circle cx="9" cy="7" r="4"/>
                           <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                           <path d="M16 3.13a4 4 0 0 1 0 7.75"/>`,
                },
                {
                    label:     { en: 'Customers', ar: 'العملاء' },
                    routeName: 'customers.index',
                    icon: `<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                           <circle cx="12" cy="7" r="4"/>
                           <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                           <path d="M23 21v-2a3 3 0 0 0-2-2.83"/>`,
                },
                 {
                    label:     { en: 'Other Settings', ar: ' إعدادات آخرى' },
                    routeName: 'settings.index',
                    icon: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                           <polyline points="14 2 14 8 20 8"/>
                           <line x1="8" y1="13" x2="16" y2="13"/>
                           <path d="M8 17h4"/>
                           <path d="M16 17v-2a2 2 0 0 0-4 0v2"/>`,
                },
                {
                    label:     { en: 'Opening Balances', ar: 'الأرصدة الافتتاحية' },
                    routeName: 'opening-balances.index',
                    icon: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                           <polyline points="14 2 14 8 20 8"/>
                           <line x1="8" y1="13" x2="16" y2="13"/>
                           <path d="M8 17h4"/>
                           <path d="M16 17v-2a2 2 0 0 0-4 0v2"/>`,
                },
               
            ],
        },
   
    // ── Reports & Intelligence ───────────────────────────────────
    {      
        label: { en: 'Reports', ar: 'التقارير' },
        items: [
            {
                    label:     { en: 'Bank Statements', ar: 'كشوف البنك' },
                    routeName: 'bank-statements.index',
                    icon: `<path d="M3 22V12"/>
                           <path d="M21 22V12"/>
                           <path d="M7 22V12"/>
                           <path d="M11 22V12"/>
                           <path d="M15 22V12"/>
                           <path d="M19 22V12"/>
                           <path d="M1 12l11-9 11 9"/>
                           <path d="M1 22h22"/>`,
            },
            {
                label:     { en: 'Statements', ar: 'كشوف الحساب' },
                routeName: 'statements.index',
                icon: `<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                           <polyline points="14 2 14 8 20 8"/>
                           <line x1="8" y1="13" x2="16" y2="13"/>
                           <path d="M8 17h4"/>
                           <path d="M16 17v-2a2 2 0 0 0-4 0v2"/>`,
            },
            {
                label:     { en: 'Financial Reports', ar: 'التقارير المالية' },
                routeName: 'reports.index',
                icon: `<path d="M12 3v18"/>
                    <path d="M5 7l7-4 7 4"/>
                    <path d="M5 7c0 3.3-2 6-2 6s2 2.7 2 6"/>
                    <path d="M19 7c0 3.3 2 6 2 6s-2 2.7-2 6"/>
                    <path d="M3 19h4"/>
                    <path d="M17 19h4"/>`,
            },
            {
                label:     { en: 'Analytics Reports', ar: 'التقارير التحليلية ' },
                routeName: 'analytics.index',
                icon: `<path d="M18 20V10"/>
                    <path d="M12 20V4"/>
                    <path d="M6 20v-6"/>
                    <path d="M2 20h20"/>
                    <path d="M18 7l-3-3-3 3"/>`,
            },
            {
                    label:     { en: 'Budget', ar: 'الموازنة التقديرية' },
                    routeName: 'budgets.index',
                    icon: `<path d="M12 20V10"/>
                           <path d="M18 20V4"/>
                           <path d="M6 20v-4"/>
                           <path d="M2 14h20" stroke-dasharray="3 3"/>`,
            },
        ],
    },
        
    ]

    // ── Settings group — built dynamically based on role ──────────
    // Items are only included when the logged-in user has the right
    // to access them. This means a Vet never sees Users or Companies.
    const settingsItems = []
    

    // Farms — Farm Owner and Super Admin
    if (canManageFarms.value) {
        settingsItems.push({
            label:     { en: 'Farms', ar: 'المزارع' },
            routeName: 'farms.index',
            // House / farm building icon
            icon: `<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                   <polyline points="9 22 9 12 15 12 15 22"/>`,
        })
    }

    // Users — Farm Owner and Super Admin
    if (canManageUsers.value) {
        settingsItems.push({
            label:     { en: 'Users', ar: 'المستخدمون' },
            routeName: 'users.index',
            // People / group icon — user management
            icon: `<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                   <circle cx="9" cy="7" r="4"/>
                   <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                   <path d="M16 3.13a4 4 0 0 1 0 7.75"/>`,
        })
    }

    

    // Companies — Super Admin only
    if (canManageCompanies.value) {
        settingsItems.push({
            label:     { en: 'Companies', ar: 'الشركات' },
            routeName: 'companies.index',
            // Office building icon
            icon: `<path d="M3 21h18"/>
                   <path d="M5 21V7l8-4v18"/>
                   <path d="M19 21V11l-6-4"/>
                   <path d="M9 9v.01"/>
                   <path d="M9 12v.01"/>
                   <path d="M9 15v.01"/>
                   <path d="M9 18v.01"/>`,
        })
    }

    // Only add the Settings group when the user can access at least one item
    if (settingsItems.length > 0) {
        groups.push({
            label: { en: 'Settings', ar: 'الإعدادات' },
            items: settingsItems,
        })
    }

    return groups
})
</script>

<template>
    <!-- Mobile backdrop -->
    <Transition name="cv-fade">
        <div
            v-if="mobileSidebarOpen"
            class="cv-mobile-overlay"
            @click="closeMobile"
            aria-hidden="true"
        ></div>
    </Transition>

    <!-- Root layout -->
    <div
        class="cv-layout"
        :dir="isRtl ? 'rtl' : 'ltr'"
        :lang="lang"
    >

        <!-- ══════════════════════════════════════════
             SIDEBAR
        ══════════════════════════════════════════ -->
        <aside
            class="cv-sidebar"
            :class="{
                'cv-sidebar--collapsed':   sidebarCollapsed,
                'cv-sidebar--mobile-open': mobileSidebarOpen,
            }"
            :aria-label="isRtl ? 'القائمة الرئيسية' : 'Main navigation'"
        >
            <!-- ── Logo row ────────────────────────────────── -->
            <div class="cv-sidebar-logo">
                <div class="cv-sidebar-logo-icon" aria-hidden="true">
                    <svg width="18" height="16" viewBox="0 0 18 16" fill="none">
                        <path d="M1 1L9 15L17 1" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M4 1L9 11L14 1" stroke="rgba(255,255,255,0.40)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <div v-if="!sidebarCollapsed" class="cv-sidebar-logo-text">
                    <span class="cv-sidebar-logo-text-en">VERO </span>
                    <span class="cv-sidebar-logo-text-ar">لإدارة الماشية</span>
                </div>

                <!-- Collapse toggle — desktop only -->
                <button
                    class="cv-sidebar-collapse-btn"
                    :style="sidebarCollapsed ? 'margin-inline-start:0' : 'margin-inline-start:auto'"
                    @click="toggleSidebar"
                    :aria-label="sidebarCollapsed
                        ? (isRtl ? 'توسيع الشريط الجانبي' : 'Expand sidebar')
                        : (isRtl ? 'طي الشريط الجانبي'   : 'Collapse sidebar')"
                >
                    <svg
                        width="13" height="13" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor"
                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <polyline
                            v-if="(!sidebarCollapsed && !isRtl) || (sidebarCollapsed && isRtl)"
                            points="15 18 9 12 15 6"
                        />
                        <polyline v-else points="9 18 15 12 9 6"/>
                    </svg>
                </button>
            </div>

            <!-- ── Current farm chip ───────────────────────── -->
            <div
                v-if="!sidebarCollapsed && currentFarm"
                class="cv-sidebar-farm"
                role="button"
                tabindex="0"
            >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                <span class="cv-sidebar-farm-name">
                    {{ isRtl
                        ? (currentFarm.name_ar ?? currentFarm.name_en)
                        : (currentFarm.name_en ?? currentFarm.name_ar) }}
                </span>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-inline-start:auto;opacity:0.35" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </div>

            <!-- ── Navigation ──────────────────────────────── -->
            <nav ref="sidebarEl" class="cv-sidebar-nav cv-scroll">
                <template v-for="group in navGroups" :key="group.label.en">

                    <!-- Group label (expanded) / divider (collapsed) -->
                    <div v-if="!sidebarCollapsed" class="cv-sidebar-section-label">
                        {{ t(group.label) }}
                    </div>
                    <div v-else class="cv-sidebar-section-divider"></div>

                    <Link
                        v-for="item in group.items"
                        :key="item.routeName"
                        :href="route(item.routeName)"
                        class="cv-sidebar-item"
                        :class="{ active: isActive(item.routeName) }"
                        :title="sidebarCollapsed ? t(item.label) : undefined"
                        :aria-current="isActive(item.routeName) ? 'page' : undefined"
                    >
                        <!-- Icon -->
                        <svg
                            class="cv-sidebar-item-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                            v-html="item.icon"
                        ></svg>

                        <!-- Label (hidden when collapsed) -->
                        <span v-if="!sidebarCollapsed" class="cv-sidebar-item-text">
                            {{ t(item.label) }}
                        </span>

                        <!-- Alert badge -->
                        <span
                            v-if="item.badge && !sidebarCollapsed"
                            class="cv-sidebar-badge"
                            :class="{ 'cv-sidebar-badge-red': item.badgeType === 'danger' }"
                        >{{ item.badge }}</span>
                    </Link>

                </template>
            </nav>

            <!-- ── Sidebar footer — accounting mode ───────── -->
            <div v-if="!sidebarCollapsed" class="cv-sidebar-footer">
                <div
                    class="cv-mode-badge"
                    :class="{ 'cv-mode-badge-odoo': accountingMode === 'odoo' }"
                >
                    <span class="cv-mode-dot"></span>
                    <span v-if="accountingMode === 'odoo'">
                        {{ isRtl ? 'مزامنة أودو' : 'Odoo Sync' }}
                    </span>
                    <span v-else>
                        {{ isRtl ? 'مستقل' : 'Standalone' }}
                    </span>
                </div>
            </div>

        </aside><!-- /sidebar -->

        <!-- ══════════════════════════════════════════
             MAIN COLUMN
        ══════════════════════════════════════════ -->
        <div class="cv-main">

            <!-- ── Top header ──────────────────────────────── -->
            <header class="cv-header">

                <!-- Mobile hamburger -->
                <button
                    class="cv-header-hamburger"
                    @click="toggleMobile"
                    :aria-label="isRtl ? 'فتح القائمة' : 'Open navigation'"
                    :aria-expanded="mobileSidebarOpen"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="3" y1="6"  x2="21" y2="6"/>
                        <line x1="3" y1="12" x2="21" y2="12"/>
                        <line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>

                <!-- Page breadcrumb slot -->
                <div class="cv-header-breadcrumb" style="flex:1;min-width:0">
                    <slot name="header">
                        <span class="cv-header-breadcrumb-current">
                            {{ isRtl ? 'لوحة المؤشرات' : 'Dashboard' }}
                        </span>
                    </slot>
                </div>

                <!-- Right-side actions -->
                <div class="cv-header-actions">

                    <!-- Language switcher -->
                    <div
                        class="cv-lang-switcher"
                        role="group"
                        :aria-label="isRtl ? 'تغيير اللغة' : 'Language'"
                    >
                        <button
                            class="cv-lang-btn"
                            :class="{ active: lang === 'en' }"
                            @click="switchLang('en')"
                            :aria-pressed="lang === 'en'"
                            aria-label="English"
                        >EN</button>
                        <button
                            class="cv-lang-btn"
                            :class="{ active: lang === 'ar' }"
                            @click="switchLang('ar')"
                            :aria-pressed="lang === 'ar'"
                            aria-label="العربية"
                        >ع</button>
                    </div>

                    <!-- Notifications bell -->
                    <button
                        class="cv-header-notif-btn"
                        :aria-label="isRtl ? 'الإشعارات' : 'Notifications'"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <span class="cv-header-notif-dot" aria-hidden="true"></span>
                    </button>

                    <!-- Active company indicator for Super Admin -->
                    <div v-if="isSuperAdmin && $page.props.selected_company" class="cv-header-farm-chip" style="background:rgba(29,111,164,0.12);border-color:rgba(29,111,164,0.3);color:#1D6FA4;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/>
                        </svg>
                        <span>{{ isRtl ? ($page.props.selected_company.name_ar ?? $page.props.selected_company.name_en) : ($page.props.selected_company.name_en ?? $page.props.selected_company.name_ar) }}</span>
                    </div>

                    <!-- Farm selector chip (header version) -->
                    <div v-if="currentFarm" class="cv-header-farm-chip">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        <span>{{ isRtl ? (currentFarm.name_ar ?? currentFarm.name_en) : (currentFarm.name_en ?? currentFarm.name_ar) }}</span>
                    </div>

                    <!-- User menu trigger -->
                    <div
                        id="cv-user-menu-trigger"
                        class="cv-header-user"
                        role="button"
                        tabindex="0"
                        :aria-expanded="userMenuOpen"
                        aria-haspopup="menu"
                        @click="toggleUserMenu"
                        @keydown.enter.prevent="toggleUserMenu"
                        @keydown.space.prevent="toggleUserMenu"
                        @keydown.escape="closeUserMenu"
                    >
                        <div class="cv-avatar cv-avatar-sm" aria-hidden="true">{{ userInitials }}</div>

                        <div class="cv-header-user-info">
                            <span class="cv-header-user-name">{{ user?.name ?? '—' }}</span>
                        </div>

                        <!-- Chevron -->
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cv-text-muted);flex-shrink:0" aria-hidden="true">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>

                        <!-- Dropdown menu -->
                        <Transition name="cv-dropdown-fade">
                            <div
                                v-if="userMenuOpen"
                                class="cv-dropdown cv-header-user-dropdown"
                                role="menu"
                            >
                                <!-- User info header -->
                                <div class="cv-dropdown-user-header">
                                    <div class="cv-avatar cv-avatar-sm">{{ userInitials }}</div>
                                    <div>
                                        <div style="font-size:0.75rem;font-weight:600;color:var(--cv-text-primary)">{{ user?.name ?? '—' }}</div>
                                        <div style="font-size:0.65rem;color:var(--cv-text-muted)">{{ user?.email ?? '' }}</div>
                                    </div>
                                </div>

                                <div class="cv-dropdown-sep"></div>

                                <!-- Profile -->
                                <Link
                                    :href="route('profile.edit')"
                                    class="cv-dropdown-item"
                                    role="menuitem"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    {{ isRtl ? 'الملف الشخصي' : 'Profile' }}
                                </Link>

                                <!-- ── Manage Users (shortcut in dropdown) ──────── -->
                                <!-- Shown only to Farm Owner and Super Admin        -->
                                <Link
                                    v-if="canManageUsers"
                                    :href="route('users.index')"
                                    class="cv-dropdown-item"
                                    role="menuitem"
                                    @click="closeUserMenu"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                        <circle cx="9" cy="7" r="4"/>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                    {{ isRtl ? 'إدارة المستخدمين' : 'Manage Users' }}
                                </Link>

                                <!-- Settings -->
                                <Link
                                    v-if="route().has('settings.index')"
                                    :href="route('settings.index')"
                                    class="cv-dropdown-item"
                                    role="menuitem"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <circle cx="12" cy="12" r="3"/>
                                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                    </svg>
                                    {{ isRtl ? 'الإعدادات' : 'Settings' }}
                                </Link>

                                <div class="cv-dropdown-sep"></div>

                                <!-- Sign out -->
                                <button
                                    type="button"
                                    class="cv-dropdown-item cv-dropdown-item-danger"
                                    role="menuitem"
                                    @click.stop="logout"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                        <polyline points="16 17 21 12 16 7"/>
                                        <line x1="21" y1="12" x2="9" y2="12"/>
                                    </svg>
                                    {{ isRtl ? 'تسجيل الخروج' : 'Sign out' }}
                                </button>
                            </div>
                        </Transition>
                    </div>

                </div><!-- /cv-header-actions -->
            </header>

            <!-- Flash messages -->
            <div
                v-if="$page.props.flash?.success || $page.props.flash?.error"
                class="cv-flash-zone"
                role="alert"
                aria-live="polite"
            >
                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    {{ $page.props.flash.success }}
                </div>
                <div v-if="$page.props.flash?.error" class="cv-alert cv-alert-danger">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    {{ $page.props.flash.error }}
                </div>
            </div>

            <!-- Page content -->
            <main>
                
                <slot />
            </main>

        </div><!-- /cv-main -->
    </div><!-- /cv-layout -->
</template>

<style scoped>
/* ══════════════════════════════════════════════════════════════════
   MOBILE OVERLAY
══════════════════════════════════════════════════════════════════ */
.cv-mobile-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.60);
    backdrop-filter: blur(3px);
    z-index: 39;
}
.cv-fade-enter-active { transition: opacity 0.2s ease; }
.cv-fade-leave-active { transition: opacity 0.15s ease; }
.cv-fade-enter-from,
.cv-fade-leave-to     { opacity: 0; }

/* ══════════════════════════════════════════════════════════════════
   SIDEBAR EXTENSIONS
══════════════════════════════════════════════════════════════════ */
.cv-sidebar {
    height: 100vh;
    overflow-x: hidden;
    overflow-y: hidden;
    z-index: 40;
    transition: width 0.22s ease;
}

/* Collapsed — icon-only */
.cv-sidebar--collapsed {
    width: var(--cv-sidebar-collapsed, 64px);
}
.cv-sidebar--collapsed .cv-sidebar-item-text    { display: none; }
.cv-sidebar--collapsed .cv-sidebar-section-label{ display: none; }
.cv-sidebar--collapsed .cv-sidebar-farm         { display: none; }
.cv-sidebar--collapsed .cv-sidebar-footer       { display: none; }

/* Mobile — off-screen drawer */
@media (max-width: 767px) {
    .cv-sidebar {
        position: fixed;
        inset-block: 0;
        inset-inline-start: 0;
        height: 100dvh;
        transform: translateX(-100%);
        transition: transform 0.22s ease, width 0.22s ease;
        z-index: 40;
    }
    [dir="rtl"] .cv-sidebar { transform: translateX(100%); }
    .cv-sidebar--mobile-open { transform: translateX(0) !important; }
}

/* Collapse toggle — desktop only */
.cv-sidebar-collapse-btn {
    display: none;
    width: 1.8rem;
    height: 1.625rem;
    border-radius: var(--cv-radius-sm, 6px);
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.28);
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    
    /* FIXES: */
    position: relative; 
    z-index: 9999999; /* Higher than the main page content */
    margin: 5px;
    transition: all 0.15s ease;
    transition: background-color 0.15s ease, color 0.15s ease;
}
/* Keep the toggle button visible and centered when sidebar is collapsed */
.cv-sidebar--collapsed .cv-sidebar-collapse-btn {
    margin-inline-start: 10px;
    margin-inline-end: 5px;
    align-self: center;
    margin:15px
}

.cv-sidebar--collapsed .cv-sidebar-logo {
    justify-content: center;
    padding-inline: 10px;
}
@media (min-width: 768px) {
    .cv-sidebar-collapse-btn { display: flex; }
}
.cv-sidebar-collapse-btn:hover {
    background: rgba(255,255,255,0.10);
    color: rgba(255,255,255,0.75);
}


/* Farm chip */
.cv-sidebar-farm {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0.375rem 0.625rem;
    padding: 0.4375rem 0.625rem;
    border-radius: var(--cv-radius-sm, 6px);
    background: rgba(92, 196, 106, 0.07);
    border: 1px solid rgba(92, 196, 106, 0.12);
    cursor: pointer;
    color: rgba(180, 230, 195, 0.55);
    transition: background-color 0.15s ease;
}
.cv-sidebar-farm:hover,
.cv-sidebar-farm:focus-visible {
    background: rgba(228, 231, 4, 0.12);
    outline: none;
}
.cv-sidebar-farm-name {
    font-size: 0.72rem;
    font-weight: 600;
    color: rgba(223, 240, 200, 0.85);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    max-width: 140px;
}

/* Nav area */
.cv-sidebar-nav {
    flex: 1;
    min-height: 0;
    padding-bottom: 0.5rem;
    overflow-y: auto;
}

/* Thin divider in collapsed mode */
.cv-sidebar-section-divider {
    height: 1px;
    background: rgba(255,255,255,0.06);
    margin: 0.375rem 0.625rem;
}

/* Item label */
.cv-sidebar-item-text {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.8125rem;
}

/* Sidebar footer */
.cv-sidebar-footer {
    padding: 0.75rem 1rem;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}

/* ══════════════════════════════════════════════════════════════════
   HEADER EXTENSIONS
══════════════════════════════════════════════════════════════════ */

/* Mobile hamburger */
.cv-header-hamburger {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex-shrink: 0;
    border-radius: var(--cv-radius-sm, 6px);
    border: 1px solid var(--cv-border);
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer;
    transition: var(--cv-transition);
}
.cv-header-hamburger:hover {
    background: var(--cv-bg-hover);
    color: var(--cv-text-primary);
}
@media (min-width: 768px) {
    .cv-header-hamburger { display: none; }
}

/* Actions group */
.cv-header-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

/* Notification button with amber dot */
.cv-header-notif-btn {
    position: relative;
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--cv-radius-sm, 6px);
    border: 1px solid var(--cv-border);
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer;
    transition: var(--cv-transition);
    flex-shrink: 0;
}
.cv-header-notif-btn:hover {
    background: var(--cv-bg-hover);
    color: var(--cv-text-secondary);
    border-color: var(--cv-border-md);
}
.cv-header-notif-dot {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 6px;
    height: 6px;
    background: var(--cv-amber);
    border-radius: 50%;
    border: 1.5px solid var(--cv-bg-header);
    box-shadow: 0 0 5px rgba(232, 160, 48, 0.55);
}

/* Farm chip in header */
.cv-header-farm-chip {
    display: none;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.75rem;
    border-radius: var(--cv-radius-sm);
    background: rgba(92, 196, 106, 0.07);
    border: 1px solid rgba(92, 196, 106, 0.14);
    font-size: 0.7rem;
    font-weight: 600;
    color: rgba(180, 230, 195, 0.75);
    white-space: nowrap;
}
@media (min-width: 900px) {
    .cv-header-farm-chip { display: flex; }
}

/* User chip */
.cv-header-user {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.5rem;
    border-radius: var(--cv-radius, 9px);
    border: 1px solid transparent;
    cursor: pointer;
    user-select: none;
    outline: none;
    transition: var(--cv-transition);
}
.cv-header-user:hover,
.cv-header-user:focus-visible {
    background: var(--cv-bg-hover);
    border-color: var(--cv-border);
}

/* User info text — ≥640px only */
.cv-header-user-info { display: none; }
@media (min-width: 640px) {
    .cv-header-user-info { display: flex; flex-direction: column; }
}
.cv-header-user-name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--cv-text-primary);
    white-space: nowrap;
}

/* User dropdown header */
.cv-dropdown-user-header {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.625rem 0.75rem;
}

/* Dropdown panel */
.cv-header-user-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    inset-inline-end: 0;
    z-index: 60;
    min-width: 200px;
}

/* Dropdown button reset */
.cv-dropdown-item {
    width: 100%;
    text-align: start;
    background: none;
    border: none;
    font-family: inherit;
    cursor: pointer;
}

/* Dropdown animation */
.cv-dropdown-fade-enter-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.cv-dropdown-fade-leave-active { transition: opacity 0.10s ease; }
.cv-dropdown-fade-enter-from  { opacity: 0; transform: translateY(-4px); }
.cv-dropdown-fade-leave-to    { opacity: 0; }

/* ══════════════════════════════════════════════════════════════════
   FLASH ZONE
══════════════════════════════════════════════════════════════════ */
.cv-flash-zone {
    padding: 0.75rem 1.5rem 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
</style>