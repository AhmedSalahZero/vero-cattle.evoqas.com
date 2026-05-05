<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO — Suppliers/Index.vue
//
//  ┌─────────────────────────────────────────────────────────────┐
//  │  COMPONENT PLAN                                             │
//  ├─────────────────────────────────────────────────────────────┤
//  │  1. PROPS SHAPE (from SupplierController::index)            │
//  │     suppliers[]  — formatted supplier objects               │
//  │       .id, .name_en, .name_ar, .code, .type                 │
//  │       .contact_person, .phone, .email, .city, .country      │
//  │       .payment_terms_days, .is_active, .odoo_partner_id     │
//  │       .ap_invoices_count, .procurement_records_count        │
//  │       .animals_count, .created_at                           │
//  │     companies[]  — super admin filter only                  │
//  │     filters      — { search, type, is_active }              │
//  │     user.permissions     — 'super_admin' | role name                │
//  │                                                             │
//  │  2. ACTIONS                                                 │
//  │     Create supplier  — amber CTA (if can create)            │
//  │     Edit supplier    — pencil icon button                   │
//  │     Toggle active    — toggle icon button                   │
//  │     Delete supplier  — trash icon → confirm modal           │
//  │                                                             │
//  │  3. CARD GRID COLUMNS (displayed per card)                  │
//  │     Avatar (initials) · Bilingual name · Code badge         │
//  │     Type badge · Active/Inactive badge                      │
//  │     Phone · Email · City                                    │
//  │     AP Invoices count · Procurement count · Animals count   │
//  │     Odoo sync badge (if odoo_partner_id set)                │
//  │     Payment terms · Created date                            │
//  │                                                             │
//  │  4. FILTERS / SEARCH                                        │
//  │     Text search: name_en, name_ar, code, phone, email       │
//  │     Type filter: animal | feed | medicine | equipment | other│
//  │     Status filter: active / inactive / all                  │
//  │                                                             │
//  │  5. PERMISSION GUARDS                                       │
//  │     Create button: create suppliers                         │
//  │     Edit button:   edit suppliers                           │
//  │     Toggle:        edit suppliers                           │
//  │     Delete button: delete suppliers                         │
//  │                                                             │
//  │  6. MOBILE / RTL                                            │
//  │     card grid min 300px, stacks to 1 col on mobile          │
//  │     dir="rtl" on root when lang === 'ar'                    │
//  │     text-start (logical) for all text                       │
//  │     icons flip as needed via transform scale                │
//  └─────────────────────────────────────────────────────────────┘
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    suppliers:  { type: Array,  default: () => [] },
    companies:  { type: Array,  default: () => [] },
    filters:    { type: Object, default: () => ({}) },
})

const { locale: lang, isRtl, n } = useLocale()

// ── Permission helpers ────────────────────────────────────────────
const { can } = usePermissions()
const canCreate = computed(() => can('create suppliers'))
const canEdit = computed(() => can('edit suppliers'))
const canDelete = computed(() => can('delete suppliers'))

// ── Filters (local reactive state — synced to URL) ────────────────
const search   = ref(props.filters.search   ?? '')
const typeFilter = ref(props.filters.type   ?? '')
const statusFilter = ref(
    props.filters.is_active !== undefined && props.filters.is_active !== ''
        ? String(props.filters.is_active)
        : ''
)

function applyFilters() {
    router.get(route('suppliers.index'), {
        search:    search.value   || undefined,
        type:      typeFilter.value || undefined,
        is_active: statusFilter.value !== '' ? statusFilter.value : undefined,
    }, { preserveState: true, replace: true })
}

function clearFilters() {
    search.value       = ''
    typeFilter.value   = ''
    statusFilter.value = ''
    router.get(route('suppliers.index'), {}, { preserveState: false })
}

// ── Delete modal ──────────────────────────────────────────────────
const deleteTarget = ref(null)
function openDelete(supplier) { deleteTarget.value = supplier }
function closeDelete()        { deleteTarget.value = null }
function confirmDelete() {
    if (!deleteTarget.value) return
    router.delete(route('suppliers.destroy', deleteTarget.value.id), {
        onSuccess: closeDelete,
    })
}

// ── Toggle active ─────────────────────────────────────────────────
function toggleActive(supplier) {
    router.patch(route('suppliers.toggle-active', supplier.id), {}, {
        preserveScroll: true,
    })
}

// ── Supplier type label ───────────────────────────────────────────
const typeLabels = {
    en: { animal: 'Animal', feed: 'Feed', medicine: 'Medicine', equipment: 'Equipment', other: 'Other' },
    ar: { animal: 'مواشي', feed: 'أعلاف', medicine: 'أدوية', equipment: 'معدات', other: 'أخرى' },
}
function typeLabel(type) {
    return (typeLabels[lang.value] ?? typeLabels.en)[type] ?? type
}

// ── Stripe class by type ──────────────────────────────────────────
function stripeClass(type) {
    return { animal: 'cv-card-stripe-green', feed: 'cv-card-stripe-amber',
             medicine: 'cv-card-stripe-red', equipment: 'cv-card-stripe-blue',
             other: 'cv-card-stripe-muted' }[type] ?? 'cv-card-stripe-muted'
}

// ── Badge class by type ───────────────────────────────────────────
function typeBadgeClass(type) {
    return { animal: 'cv-badge-green', feed: 'cv-badge-amber',
             medicine: 'cv-badge-danger', equipment: 'cv-badge-info',
             other: 'cv-badge-neutral' }[type] ?? 'cv-badge-neutral'
}

// ── Initials for avatar ───────────────────────────────────────────
function initials(supplier) {
    const name = n(supplier, 'name')
    if (!name) return '?'
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

// ── UI strings ────────────────────────────────────────────────────
const ui = computed(() => lang.value === 'ar' ? {
    eyebrow:       'إدارة الموردين',
    title:         'الموردون',
    sub:           'موردو المواشي والأعلاف والأدوية والمعدات',
    addBtn:        'إضافة مورد',
    searchPlaceholder: 'بحث بالاسم أو الكود أو الهاتف...',
    filterType:    'نوع المورد',
    filterStatus:  'الحالة',
    allTypes:      'كل الأنواع',
    allStatuses:   'كل الحالات',
    activeOnly:    'نشط',
    inactiveOnly:  'غير نشط',
    clearFilters:  'مسح الفلاتر',
    editBtn:       'تعديل',
    deleteBtn:     'حذف',
    noSuppliers:   'لا يوجد موردون',
    noSuppliersHint: 'ابدأ بإضافة مورد جديد',
    phone:         'الهاتف',
    email:         'البريد',
    city:          'المدينة',
    apInvoices:    'فواتير مستحقة',
    procurement:   'طلبات شراء',
    animals:       'مواشي',
    payTerms:      'شروط الدفع',
    days:          'يوم',
    odooSynced:    'متزامن مع أودو',
    deleteTitle:   'حذف المورد',
    deleteMsg:     (name) => `هل أنت متأكد من حذف المورد "${name}"؟`,
    deleteWarning: 'لا يمكن التراجع عن هذا الإجراء.',
    cancelBtn:     'إلغاء',
    confirmDelete: 'نعم، احذف',
    contact:       'جهة الاتصال',
    addedDate:     'تاريخ الإضافة',
} : {
    eyebrow:       'Supplier Management',
    title:         'Suppliers',
    sub:           'Animal, feed, medicine and equipment suppliers',
    addBtn:        'Add Supplier',
    searchPlaceholder: 'Search by name, code, or phone…',
    filterType:    'Supplier Type',
    filterStatus:  'Status',
    allTypes:      'All Types',
    allStatuses:   'All Statuses',
    activeOnly:    'Active',
    inactiveOnly:  'Inactive',
    clearFilters:  'Clear Filters',
    editBtn:       'Edit',
    deleteBtn:     'Delete',
    noSuppliers:   'No suppliers found',
    noSuppliersHint: 'Start by adding a new supplier',
    phone:         'Phone',
    email:         'Email',
    city:          'City',
    apInvoices:    'AP Invoices',
    procurement:   'Procurement',
    animals:       'Animals',
    payTerms:      'Payment Terms',
    days:          'days',
    odooSynced:    'Odoo Synced',
    deleteTitle:   'Delete Supplier',
    deleteMsg:     (name) => `Are you sure you want to delete "${name}"?`,
    deleteWarning: 'This action cannot be undone.',
    cancelBtn:     'Cancel',
    confirmDelete: 'Yes, Delete',
    contact:       'Contact',
    addedDate:     'Added',
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── PAGE HERO ─────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ ui.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ ui.title }}</div>
                <div class="cv-page-hero-sub">{{ ui.sub }}</div>
                <div class="cv-page-hero-actions">
                    <Link
                        v-if="canCreate"
                        :href="route('suppliers.create')"
                        class="cv-btn cv-btn-amber"
                    >
                        <!-- Plus icon -->
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        {{ ui.addBtn }}
                    </Link>
                </div>
            </div>

            <!-- ── PAGE CONTENT ──────────────────────────────────── -->
            <div class="cv-page">

                <!-- ── FILTER BAR ─────────────────────────────────── -->
                <div class="cv-card" style="margin-bottom:1.25rem; padding:1rem 1.25rem;">
                    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">

                        <!-- Search -->
                        <div style="flex:1; min-width:220px;">
                            <div class="cv-input-icon-wrap">
                                <input
                                    v-model="search"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="ui.searchPlaceholder"
                                    @keyup.enter="applyFilters"
                                />
                                <span class="cv-input-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.8"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="7"/>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                    </svg>
                                </span>
                            </div>
                        </div>

                        <!-- Type filter -->
                        <div style="min-width:160px;">
                            <select v-model="typeFilter" class="cv-select" @change="applyFilters">
                                <option value="">{{ ui.allTypes }}</option>
                                <option value="animal">{{ typeLabels[lang].animal }}</option>
                                <option value="feed">{{ typeLabels[lang].feed }}</option>
                                <option value="medicine">{{ typeLabels[lang].medicine }}</option>
                                <option value="equipment">{{ typeLabels[lang].equipment }}</option>
                                <option value="other">{{ typeLabels[lang].other }}</option>
                            </select>
                        </div>

                        <!-- Status filter -->
                        <div style="min-width:140px;">
                            <select v-model="statusFilter" class="cv-select" @change="applyFilters">
                                <option value="">{{ ui.allStatuses }}</option>
                                <option value="1">{{ ui.activeOnly }}</option>
                                <option value="0">{{ ui.inactiveOnly }}</option>
                            </select>
                        </div>

                        <!-- Clear -->
                        <button
                            v-if="search || typeFilter || statusFilter"
                            class="cv-btn cv-btn-ghost cv-btn-sm"
                            @click="clearFilters"
                        >{{ ui.clearFilters }}</button>
                    </div>
                </div>

                <!-- ── SUPPLIER CARD GRID ────────────────────────── -->
                <div
                    v-if="suppliers.length"
                    style="display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1rem;"
                >
                    <div
                        v-for="supplier in suppliers"
                        :key="supplier.id"
                        class="cv-card"
                        :class="stripeClass(supplier.type)"
                        style="display:flex; flex-direction:column; gap:0;"
                    >
                        <!-- Card header -->
                        <div style="display:flex; align-items:flex-start; gap:0.75rem; margin-bottom:0.875rem;">
                            <!-- Avatar -->
                            <div class="cv-avatar cv-avatar-lg" style="flex-shrink:0;">
                                {{ initials(supplier) }}
                            </div>

                            <div style="flex:1; min-width:0;">
                                <!-- Name + status badge -->
                                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.25rem;">
                                    <span style="font-size:0.9375rem; font-weight:700; color:var(--cv-text-primary); line-height:1.2;">
                                        {{ n(supplier, 'name') }}
                                    </span>
                                    <span
                                        class="cv-badge"
                                        :class="supplier.is_active ? 'cv-badge-green' : 'cv-badge-neutral'"
                                    >
                                        {{ supplier.is_active
                                            ? (lang === 'ar' ? 'نشط' : 'Active')
                                            : (lang === 'ar' ? 'غير نشط' : 'Inactive') }}
                                    </span>
                                </div>

                                <!-- Code + type badges -->
                                <div style="display:flex; gap:0.375rem; flex-wrap:wrap;">
                                    <span
                                        v-if="supplier.code"
                                        class="cv-tag cv-tag-blue"
                                    >#{{ supplier.code }}</span>
                                    <span class="cv-badge" :class="typeBadgeClass(supplier.type)">
                                        {{ typeLabel(supplier.type) }}
                                    </span>
                                    <span v-if="supplier.odoo_partner_id" class="cv-tag">
                                        <!-- Link icon for Odoo sync -->
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round"
                                             style="margin-inline-end:2px;">
                                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                                        </svg>
                                        {{ ui.odooSynced }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Contact info row -->
                        <div style="display:flex; flex-direction:column; gap:0.375rem; margin-bottom:0.875rem; padding-bottom:0.875rem; border-bottom:1px solid var(--cv-border);">
                            <div v-if="supplier.contact_person" style="display:flex; align-items:center; gap:0.5rem; font-size:0.8rem; color:var(--cv-text-secondary);">
                                <!-- Person icon -->
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     style="color:var(--cv-text-muted); flex-shrink:0;">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                                <span>{{ supplier.contact_person }}</span>
                            </div>
                            <div v-if="supplier.phone" style="display:flex; align-items:center; gap:0.5rem; font-size:0.8rem; color:var(--cv-text-secondary);">
                                <!-- Phone icon -->
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     style="color:var(--cv-text-muted); flex-shrink:0;">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91A16 16 0 0 0 16 17l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                                <span style="direction:ltr; unicode-bidi:plaintext;">{{ supplier.phone }}</span>
                            </div>
                            <div v-if="supplier.email" style="display:flex; align-items:center; gap:0.5rem; font-size:0.8rem; color:var(--cv-text-secondary); overflow:hidden;">
                                <!-- Email icon -->
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     style="color:var(--cv-text-muted); flex-shrink:0;">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                    <polyline points="22,6 12,13 2,6"/>
                                </svg>
                                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ supplier.email }}</span>
                            </div>
                            <div v-if="supplier.city" style="display:flex; align-items:center; gap:0.5rem; font-size:0.8rem; color:var(--cv-text-secondary);">
                                <!-- Location icon -->
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     style="color:var(--cv-text-muted); flex-shrink:0;">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                                <span>{{ supplier.city }}</span>
                            </div>
                        </div>

                        <!-- Stats row -->
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:0.5rem; margin-bottom:0.875rem; text-align:center;">
                            <div style="background:var(--cv-bg-card-alt); border-radius:var(--cv-radius-sm); padding:0.5rem 0.25rem;">
                                <div style="font-size:1rem; font-weight:700; color:var(--cv-blue-text); line-height:1.1;">
                                    {{ supplier.ap_invoices_count }}
                                </div>
                                <div style="font-size:0.58rem; color:var(--cv-text-muted); margin-top:2px; text-transform:uppercase; letter-spacing:0.06em;">
                                    {{ ui.apInvoices }}
                                </div>
                            </div>
                            <div style="background:var(--cv-bg-card-alt); border-radius:var(--cv-radius-sm); padding:0.5rem 0.25rem;">
                                <div style="font-size:1rem; font-weight:700; color:var(--cv-amber-text); line-height:1.1;">
                                    {{ supplier.procurement_records_count }}
                                </div>
                                <div style="font-size:0.58rem; color:var(--cv-text-muted); margin-top:2px; text-transform:uppercase; letter-spacing:0.06em;">
                                    {{ ui.procurement }}
                                </div>
                            </div>
                            <div style="background:var(--cv-bg-card-alt); border-radius:var(--cv-radius-sm); padding:0.5rem 0.25rem;">
                                <div style="font-size:1rem; font-weight:700; color:var(--cv-green-bright); line-height:1.1;">
                                    {{ supplier.animals_count }}
                                </div>
                                <div style="font-size:0.58rem; color:var(--cv-text-muted); margin-top:2px; text-transform:uppercase; letter-spacing:0.06em;">
                                    {{ ui.animals }}
                                </div>
                            </div>
                        </div>

                        <!-- Footer: payment terms + actions -->
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:auto; padding-top:0.75rem; border-top:1px solid var(--cv-border);">
                            <span style="font-size:0.75rem; color:var(--cv-text-muted);">
                                {{ ui.payTerms }}: <strong style="color:var(--cv-text-secondary);">{{ supplier.payment_terms_days }} {{ ui.days }}</strong>
                            </span>

                            <div style="display:flex; gap:0.375rem;">
                                <!-- Toggle active -->
                                <button
                                    v-if="canEdit"
                                    class="cv-action-btn"
                                    :title="supplier.is_active
                                        ? (lang === 'ar' ? 'إلغاء التفعيل' : 'Deactivate')
                                        : (lang === 'ar' ? 'تفعيل' : 'Activate')"
                                    @click="toggleActive(supplier)"
                                >
                                    <!-- Power icon -->
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.7"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18.36 6.64a9 9 0 1 1-12.73 0"/>
                                        <line x1="12" y1="2" x2="12" y2="12"/>
                                    </svg>
                                </button>

                                <!-- Edit -->
                                <Link
                                    v-if="canEdit"
                                    :href="route('suppliers.edit', supplier.id)"
                                    class="cv-action-btn cv-action-btn-amber"
                                    :title="ui.editBtn"
                                >
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.7"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </Link>

                                <!-- Delete -->
                                <button
                                    v-if="canDelete"
                                    class="cv-action-btn cv-action-btn-danger"
                                    :title="ui.deleteBtn"
                                    @click="openDelete(supplier)"
                                >
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.7"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                        <path d="M10 11v6M14 11v6"/>
                                        <path d="M9 6V4h6v2"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── EMPTY STATE ─────────────────────────────────── -->
                <div
                    v-else
                    class="cv-card"
                    style="text-align:center; padding:3rem 1.5rem;"
                >
                    <!-- Supplier/handshake icon -->
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.2"
                         stroke-linecap="round" stroke-linejoin="round"
                         style="color:var(--cv-text-muted); margin:0 auto 1rem;">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <div style="font-size:1rem; font-weight:600; color:var(--cv-text-secondary); margin-bottom:0.5rem;">
                        {{ ui.noSuppliers }}
                    </div>
                    <div style="font-size:0.8125rem; color:var(--cv-text-muted); margin-bottom:1.25rem;">
                        {{ ui.noSuppliersHint }}
                    </div>
                    <Link
                        v-if="canCreate"
                        :href="route('suppliers.create')"
                        class="cv-btn cv-btn-amber cv-btn-sm"
                    >{{ ui.addBtn }}</Link>
                </div>

            </div><!-- .cv-page -->
        </div>

        <!-- ── DELETE CONFIRM MODAL ──────────────────────────────── -->
        <Transition name="modal">
            <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="closeDelete">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <span class="cv-modal-title" style="color:var(--cv-danger-text);">
                            {{ ui.deleteTitle }}
                        </span>
                        <button class="cv-action-btn" @click="closeDelete">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="cv-modal-body" style="text-align:center;">
                        <!-- Danger icon -->
                        <div style="width:3rem; height:3rem; border-radius:50%; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round"
                                 style="color:var(--cv-danger-text);">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                        <div class="cv-alert cv-alert-danger" style="text-align:start; margin-bottom:0.5rem;">
                            {{ ui.deleteMsg(n(deleteTarget, 'name')) }}
                        </div>
                        <p style="font-size:0.8125rem; color:var(--cv-text-muted);">{{ ui.deleteWarning }}</p>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closeDelete">{{ ui.cancelBtn }}</button>
                        <button class="cv-btn cv-btn-danger" @click="confirmDelete">{{ ui.confirmDelete }}</button>
                    </div>
                </div>
            </div>
        </Transition>

    </AuthenticatedLayout>
</template>
