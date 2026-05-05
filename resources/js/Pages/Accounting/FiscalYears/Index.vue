<script setup>
/**
 * Accounting/FiscalYears/Index.vue
 *
 * Index-only pattern (panel CRUD — no separate /create route needed).
 * The controller already has store(), close(), reopen(), lock(), destroy().
 * All CRUD happens via slide-in panel — user never leaves this page.
 *
 * Bilingual: All labels switch via useLocale(). Arabic mode → ALL Arabic.
 * No _en/_ar field pairs — fiscal years have no bilingual text fields.
 */

import { ref, computed, reactive } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    years:     { type: Array,  default: () => [] },
    companies: { type: Array,  default: () => [] },  // populated for super admin only
})

// ── Locale ────────────────────────────────────────────────────────
const { locale: lang, isRtl } = useLocale()

// ── Helpers ───────────────────────────────────────────────────────
const { isSuperAdmin, can } = usePermissions()

const fmtDate = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(lang.value === 'ar' ? 'ar-EG' : 'en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    })
}

const statusBadge = (s) => ({
    open:   'cv-badge-green',
    closed: 'cv-badge-amber',
    locked: 'cv-badge-neutral',
}[s] ?? 'cv-badge-neutral')

const statusLabel = (s) => {
    if (lang.value === 'ar') return { open: 'مفتوحة', closed: 'مغلقة', locked: 'مقفلة' }[s] ?? s
    return { open: 'Open', closed: 'Closed', locked: 'Locked' }[s] ?? s
}

const stripeClass = (s) => ({
    open:   'cv-card-stripe-green',
    closed: 'cv-card-stripe-amber',
    locked: 'cv-card-stripe-muted',
}[s] ?? 'cv-card-stripe-muted')

// ── Stats ─────────────────────────────────────────────────────────
const openCount   = computed(() => props.years.filter(y => y.status === 'open').length)
const closedCount = computed(() => props.years.filter(y => y.status === 'closed').length)
const lockedCount = computed(() => props.years.filter(y => y.status === 'locked').length)

// ── Create panel ──────────────────────────────────────────────────
const showPanel = ref(false)

// Auto-suggest next year and dates
const suggestYear = computed(() => {
    if (props.years.length === 0) return new Date().getFullYear()
    return Math.max(...props.years.map(y => y.year)) + 1
})
const suggestStart = computed(() => `${suggestYear.value}-01-01`)
const suggestEnd   = computed(() => `${suggestYear.value}-12-31`)

const form = useForm({
    company_id: '',
    year:       suggestYear.value,
    start_date: suggestStart.value,
    end_date:   suggestEnd.value,
})

const openPanel = () => {
    form.company_id = ''
    form.year       = suggestYear.value
    form.start_date = suggestStart.value
    form.end_date   = suggestEnd.value
    form.clearErrors()
    showPanel.value = true
}

// Auto-fill dates when year changes
const onYearChange = () => {
    if (form.year >= 2000 && form.year <= 2099) {
        form.start_date = `${form.year}-01-01`
        form.end_date   = `${form.year}-12-31`
    }
}

const submitCreate = () => {
    form.post(route('fiscal-years.store'), {
        onSuccess: () => { showPanel.value = false },
    })
}

// ── Action buttons — processing guards ───────────────────────────
const processingId  = ref(null)
const processingAct = ref(null)

const doAction = (action, id) => {
    const routeMap = {
        close:   route('fiscal-years.close',  id),
        reopen:  route('fiscal-years.reopen', id),
        lock:    route('fiscal-years.lock',   id),
        destroy: route('fiscal-years.destroy', id),
    }
    const method = action === 'destroy' ? 'delete' : 'patch'

    processingId.value  = id
    processingAct.value = action
    router[method](routeMap[action], {}, {
        onFinish: () => { processingId.value = null; processingAct.value = null },
    })
}

// ── Confirm modals ────────────────────────────────────────────────
const confirmModal = reactive({ show: false, action: null, year: null })

const askConfirm = (action, year) => {
    confirmModal.action = action
    confirmModal.year   = year
    confirmModal.show   = true
}

const runConfirm = () => {
    const { action, year } = confirmModal
    confirmModal.show = false
    doAction(action, year.id)
}

const confirmTitle = computed(() => {
    if (!confirmModal.action) return ''
    const map = {
        close:   lang.value === 'ar' ? 'تأكيد إغلاق السنة المالية' : 'Confirm Close Fiscal Year',
        lock:    lang.value === 'ar' ? 'تأكيد قفل السنة المالية' : 'Confirm Lock Fiscal Year',
        destroy: lang.value === 'ar' ? 'تأكيد حذف السنة المالية' : 'Confirm Delete Fiscal Year',
    }
    return map[confirmModal.action] ?? ''
})

const confirmBody = computed(() => {
    if (!confirmModal.action || !confirmModal.year) return ''
    const y = confirmModal.year.year
    if (confirmModal.action === 'close')
        return lang.value === 'ar'
            ? `إغلاق السنة المالية ${y} سيمنع إضافة قيود جديدة لها. يمكن إعادة فتحها لاحقاً.`
            : `Closing FY ${y} will prevent new journal entries. It can be reopened later.`
    if (confirmModal.action === 'lock')
        return lang.value === 'ar'
            ? `قفل السنة المالية ${y} إجراء لا رجعة فيه — لن تتمكن من إعادة فتحها مطلقاً.`
            : `Locking FY ${y} is irreversible — it can never be reopened.`
    if (confirmModal.action === 'destroy')
        return lang.value === 'ar'
            ? `حذف السنة المالية ${y} لا يمكن التراجع عنه.`
            : `Deleting FY ${y} cannot be undone.`
    return ''
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ HERO ══════════════════════════════════════════════ -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">
                    {{ lang === 'ar' ? 'المحاسبة' : 'Accounting' }}
                </div>
                <div class="cv-page-hero-title">
                    {{ lang === 'ar' ? 'السنوات المالية' : 'Fiscal Years' }}
                </div>
                <div class="cv-page-hero-sub">
                    {{ lang === 'ar'
                        ? 'إدارة دورة حياة السنوات المالية — مفتوحة ← مغلقة ← مقفلة'
                        : 'Manage the lifecycle of fiscal years — open → closed → locked' }}
                </div>
                <div class="cv-page-hero-actions">
                    <button type="button" class="cv-btn cv-btn-amber" @click="openPanel">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        {{ lang === 'ar' ? 'إضافة سنة مالية' : 'Add Fiscal Year' }}
                    </button>
                </div>
            </div>

            <div class="cv-page" style="display:flex;flex-direction:column;gap:1.5rem;padding-bottom:3rem">

                <!-- ══ KPI STRIP ══════════════════════════════════════ -->
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem">
                    <div class="fy-stat fy-stat-blue">
                        <div class="fy-stat-label">{{ lang === 'ar' ? 'إجمالي السنوات' : 'Total' }}</div>
                        <div class="fy-stat-value">{{ years.length }}</div>
                    </div>
                    <div class="fy-stat fy-stat-green">
                        <div class="fy-stat-label">{{ lang === 'ar' ? 'مفتوحة' : 'Open' }}</div>
                        <div class="fy-stat-value">{{ openCount }}</div>
                    </div>
                    <div class="fy-stat fy-stat-amber">
                        <div class="fy-stat-label">{{ lang === 'ar' ? 'مغلقة' : 'Closed' }}</div>
                        <div class="fy-stat-value">{{ closedCount }}</div>
                    </div>
                    <div class="fy-stat fy-stat-muted">
                        <div class="fy-stat-label">{{ lang === 'ar' ? 'مقفلة' : 'Locked' }}</div>
                        <div class="fy-stat-value">{{ lockedCount }}</div>
                    </div>
                </div>

                <!-- ══ EMPTY STATE ════════════════════════════════════ -->
                <div
                    v-if="years.length === 0"
                    class="cv-card"
                    style="padding:3rem;display:flex;flex-direction:column;align-items:center;gap:1rem;text-align:center"
                >
                    <div style="width:3.5rem;height:3.5rem;border-radius:50%;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);display:flex;align-items:center;justify-content:center">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cv-amber)">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size:0.95rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:0.375rem">
                            {{ lang === 'ar' ? 'لا توجد سنوات مالية بعد' : 'No Fiscal Years Yet' }}
                        </div>
                        <div style="font-size:0.8rem;color:var(--cv-text-muted);max-width:380px;line-height:1.6">
                            {{ lang === 'ar'
                                ? 'السنة المالية ضرورية لتسجيل القيود المحاسبية والأرصدة الافتتاحية. أنشئ سنتك المالية الأولى الآن.'
                                : 'A fiscal year is required before you can record journal entries or opening balances. Create your first one now.' }}
                        </div>
                    </div>
                    <button type="button" class="cv-btn cv-btn-amber" style="margin-top:0.5rem" @click="openPanel">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        {{ lang === 'ar' ? 'إنشاء السنة المالية الأولى' : 'Create First Fiscal Year' }}
                    </button>
                </div>

                <!-- ══ CARD GRID ══════════════════════════════════════ -->
                <div
                    v-if="years.length > 0"
                    style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem"
                >
                    <div
                        v-for="fy in years"
                        :key="fy.id"
                        class="cv-card"
                        :class="stripeClass(fy.status)"
                        style="display:flex;flex-direction:column"
                    >
                        <!-- Card header -->
                        <div style="padding:1.125rem 1.25rem 0.875rem;border-bottom:1px solid var(--cv-border);display:flex;align-items:center;justify-content:space-between;gap:0.75rem">
                            <div style="display:flex;align-items:center;gap:0.75rem">
                                <div class="fy-avatar" :class="`fy-avatar-${fy.status}`">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                </div>
                                <div>
                                    <div style="font-size:1.1rem;font-weight:800;color:var(--cv-text-primary)">
                                        {{ fy.year }}
                                    </div>
                                    <div style="font-size:0.7rem;color:var(--cv-text-muted)">
                                        {{ fmtDate(fy.start_date) }} → {{ fmtDate(fy.end_date) }}
                                    </div>
                                </div>
                            </div>
                            <span class="cv-badge" :class="statusBadge(fy.status)">
                                {{ statusLabel(fy.status) }}
                            </span>
                        </div>

                        <!-- Card body -->
                        <div style="padding:0.875rem 1.25rem;display:flex;flex-direction:column;gap:0.5rem;flex:1">
                            <div class="fy-row">
                                <span class="fy-label">{{ lang === 'ar' ? 'القيود المحاسبية' : 'Journal Entries' }}</span>
                                <span class="fy-value" style="color:var(--cv-blue-text)">{{ fy.journal_entries_count ?? 0 }}</span>
                            </div>
                            <div v-if="fy.closed_at" class="fy-row">
                                <span class="fy-label">{{ lang === 'ar' ? 'تاريخ الإغلاق' : 'Closed At' }}</span>
                                <span class="fy-value">{{ fmtDate(fy.closed_at) }}</span>
                            </div>

                            <div v-if="fy.status === 'open'" style="margin-top:0.25rem;padding:0.5rem 0.625rem;background:var(--cv-success-bg);border:1px solid var(--cv-success-border);border-radius:var(--cv-radius-sm);font-size:0.7rem;color:var(--cv-success-text)">
                                {{ lang === 'ar' ? '✓ مفتوحة — يمكن تسجيل القيود' : '✓ Open — journal entries accepted' }}
                            </div>
                            <div v-if="fy.status === 'closed'" style="margin-top:0.25rem;padding:0.5rem 0.625rem;background:var(--cv-warning-bg);border:1px solid var(--cv-warning-border);border-radius:var(--cv-radius-sm);font-size:0.7rem;color:var(--cv-warning-text)">
                                {{ lang === 'ar' ? '⚠ مغلقة — لا قيود جديدة — يمكن إعادة الفتح' : '⚠ Closed — no new entries — can be reopened' }}
                            </div>
                            <div v-if="fy.status === 'locked'" style="margin-top:0.25rem;padding:0.5rem 0.625rem;background:rgba(255,255,255,0.03);border:1px solid var(--cv-border);border-radius:var(--cv-radius-sm);font-size:0.7rem;color:var(--cv-text-muted)">
                                {{ lang === 'ar' ? '🔒 مقفلة — لا يمكن التعديل مطلقاً' : '🔒 Locked — permanently read-only' }}
                            </div>
                        </div>

                        <!-- Card footer -->
                        <div style="padding:0.75rem 1.25rem;border-top:1px solid var(--cv-border);display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">

                            <template v-if="fy.status === 'open'">
                                <button type="button" class="cv-btn cv-btn-secondary cv-btn-sm" :disabled="processingId === fy.id" @click="askConfirm('close', fy)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    {{ lang === 'ar' ? 'إغلاق' : 'Close' }}
                                </button>
                                <button v-if="fy.journal_entries_count === 0" type="button" class="cv-action-btn cv-action-btn-danger" :disabled="processingId === fy.id" @click="askConfirm('destroy', fy)" :title="lang === 'ar' ? 'حذف' : 'Delete'">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                </button>
                            </template>

                            <template v-if="fy.status === 'closed'">
                                <button type="button" class="cv-btn cv-btn-secondary cv-btn-sm" :disabled="processingId === fy.id" @click="doAction('reopen', fy.id)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                    {{ lang === 'ar' ? 'إعادة فتح' : 'Reopen' }}
                                </button>
                                <button type="button" class="cv-btn cv-btn-danger cv-btn-sm" :disabled="processingId === fy.id" @click="askConfirm('lock', fy)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    {{ lang === 'ar' ? 'قفل نهائي' : 'Lock' }}
                                </button>
                            </template>

                            <div v-if="fy.status === 'locked'" style="font-size:0.72rem;color:var(--cv-text-muted);display:flex;align-items:center;gap:0.375rem">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                {{ lang === 'ar' ? 'مقفلة نهائياً' : 'Permanently locked' }}
                            </div>

                            <a :href="route('opening-balances.create', { fiscal_year_id: fy.id })" class="cv-btn cv-btn-ghost cv-btn-sm" style="margin-inline-start:auto">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><path d="M8 17h4"/></svg>
                                {{ lang === 'ar' ? 'الأرصدة الافتتاحية' : 'Opening Balances' }}
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ══ CREATE PANEL ═══════════════════════════════════════ -->
            <Transition name="fy-panel-fade">
                <div v-if="showPanel" class="cv-modal-backdrop" @click.self="showPanel = false">
                    <div class="cv-modal cv-modal-sm" role="dialog" :dir="isRtl ? 'rtl' : 'ltr'" style="max-width:460px;width:100%">
                        <div class="cv-modal-header">
                            <div style="display:flex;align-items:center;gap:0.625rem">
                                <div style="width:2rem;height:2rem;border-radius:50%;background:linear-gradient(135deg,rgba(232,160,48,0.25),rgba(232,160,48,0.1));border:1px solid rgba(232,160,48,0.3);display:flex;align-items:center;justify-content:center">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </div>
                                <span style="font-weight:700;font-size:0.9rem">
                                    {{ lang === 'ar' ? 'إضافة سنة مالية جديدة' : 'Add New Fiscal Year' }}
                                </span>
                            </div>
                            <button type="button" class="cv-action-btn" @click="showPanel = false" style="margin-inline-start:auto">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem">
                            <div class="cv-alert cv-alert-success" style="font-size:0.78rem">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                {{ lang === 'ar'
                                    ? 'السنة المالية الجديدة تبدأ بحالة "مفتوحة" تلقائياً.'
                                    : 'New fiscal years start as "Open" automatically.' }}
                            </div>

                            <!-- Company selector — super admin only -->
                        <div v-if="isSuperAdmin" class="cv-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'الشركة' : 'Company' }}
                                <span style="color:var(--cv-danger-text)">*</span>
                            </label>
                            <select v-model="form.company_id" class="cv-select">
                                <option value="">{{ lang === 'ar' ? '— اختر الشركة —' : '— Select Company —' }}</option>
                                <option v-for="c in companies" :key="c.id" :value="c.id">
                                    {{ lang === 'ar' ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar) }}
                                </option>
                            </select>
                            <div v-if="form.errors.company_id" class="cv-field-error">{{ form.errors.company_id }}</div>
                        </div>

                        <div class="cv-field">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'السنة' : 'Year' }}
                                    <span style="color:var(--cv-danger-text)">*</span>
                                </label>
                                <input
                                    v-model.number="form.year"
                                    type="number" min="2000" max="2099"
                                    class="cv-input"
                                    placeholder="2025"
                                    @change="onYearChange"
                                    style="font-size:1.1rem;font-weight:700;color:var(--cv-amber)"
                                />
                                <div v-if="form.errors.year" class="cv-field-error">{{ form.errors.year }}</div>
                            </div>

                            <div class="cv-field">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'تاريخ البداية' : 'Start Date' }}
                                    <span style="color:var(--cv-danger-text)">*</span>
                                </label>
                                <input v-model="form.start_date" type="date" class="cv-input" />
                                <div v-if="form.errors.start_date" class="cv-field-error">{{ form.errors.start_date }}</div>
                            </div>

                            <div class="cv-field">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'تاريخ النهاية' : 'End Date' }}
                                    <span style="color:var(--cv-danger-text)">*</span>
                                </label>
                                <input v-model="form.end_date" type="date" class="cv-input" />
                                <div v-if="form.errors.end_date" class="cv-field-error">{{ form.errors.end_date }}</div>
                            </div>
                        </div>

                        <div class="cv-modal-footer">
                            <button type="button" class="cv-btn cv-btn-ghost" @click="showPanel = false">
                                {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button type="button" class="cv-btn cv-btn-amber" :disabled="form.processing" @click="submitCreate">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                {{ form.processing
                                    ? (lang === 'ar' ? 'جارٍ الحفظ...' : 'Saving...')
                                    : (lang === 'ar' ? 'إنشاء السنة المالية' : 'Create Fiscal Year') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ══ CONFIRM MODAL ══════════════════════════════════════ -->
            <Transition name="fy-panel-fade">
                <div v-if="confirmModal.show" class="cv-modal-backdrop" @click.self="confirmModal.show = false">
                    <div class="cv-modal cv-modal-sm" role="dialog" :dir="isRtl ? 'rtl' : 'ltr'">
                        <div class="cv-modal-header">
                            <div style="display:flex;align-items:center;gap:0.625rem">
                                <div style="width:2rem;height:2rem;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                </div>
                                <span style="font-weight:700;font-size:0.875rem">{{ confirmTitle }}</span>
                            </div>
                        </div>
                        <div class="cv-modal-body">
                            <p style="color:var(--cv-text-secondary);font-size:0.875rem;line-height:1.6;margin:0">{{ confirmBody }}</p>
                        </div>
                        <div class="cv-modal-footer">
                            <button type="button" class="cv-btn cv-btn-ghost" @click="confirmModal.show = false">
                                {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button
                                type="button"
                                :class="confirmModal.action === 'lock' || confirmModal.action === 'destroy' ? 'cv-btn cv-btn-danger' : 'cv-btn cv-btn-amber'"
                                @click="runConfirm"
                            >
                                {{ confirmModal.action === 'close'   ? (lang === 'ar' ? 'إغلاق' : 'Close')
                                 : confirmModal.action === 'lock'    ? (lang === 'ar' ? 'قفل نهائي' : 'Lock')
                                 : confirmModal.action === 'destroy' ? (lang === 'ar' ? 'حذف' : 'Delete')
                                 : '' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.fy-stat { padding:0.875rem 1rem; border-radius:var(--cv-radius); background:var(--cv-bg-card); border:1px solid var(--cv-border); position:relative; overflow:hidden; }
.fy-stat::before { content:''; position:absolute; top:0; inset-inline-start:0; inset-inline-end:0; height:3px; border-radius:var(--cv-radius) var(--cv-radius) 0 0; }
.fy-stat-blue::before   { background: var(--cv-blue-text); }
.fy-stat-green::before  { background: var(--cv-green-bright); }
.fy-stat-amber::before  { background: var(--cv-amber); }
.fy-stat-muted::before  { background: var(--cv-border-md); }
.fy-stat-label { font-size:0.62rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--cv-text-muted); margin-bottom:0.375rem; }
.fy-stat-value { font-size:1.5rem; font-weight:800; }
.fy-stat-blue  .fy-stat-value { color: var(--cv-blue-text); }
.fy-stat-green .fy-stat-value { color: var(--cv-green-bright); }
.fy-stat-amber .fy-stat-value { color: var(--cv-amber); }
.fy-stat-muted .fy-stat-value { color: var(--cv-text-muted); }

.fy-avatar { width:2.5rem; height:2.5rem; border-radius:var(--cv-radius-sm); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.fy-avatar-open   { background:rgba(92,196,106,0.12);  border:1px solid rgba(92,196,106,0.25);  color:var(--cv-green-bright); }
.fy-avatar-closed { background:rgba(232,160,48,0.12);  border:1px solid rgba(232,160,48,0.25);  color:var(--cv-amber); }
.fy-avatar-locked { background:rgba(255,255,255,0.04); border:1px solid var(--cv-border);        color:var(--cv-text-muted); }

.fy-row   { display:flex; align-items:center; justify-content:space-between; gap:0.5rem; }
.fy-label { font-size:0.72rem; color:var(--cv-text-muted); }
.fy-value { font-size:0.8rem; font-weight:600; color:var(--cv-text-secondary); text-align:end; }

.fy-panel-fade-enter-active { transition: opacity 0.18s ease; }
.fy-panel-fade-leave-active { transition: opacity 0.12s ease; }
.fy-panel-fade-enter-from,
.fy-panel-fade-leave-to { opacity: 0; }
</style>