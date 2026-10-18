<script setup>
/**
 * FxRates/Index.vue — Daily FX Rates
 * ─────────────────────────────────────────────────────────────────
 * Index-only panel page (§7.5). No separate create/edit routes.
 * All rate entry via the inline "Update Rates" slide panel.
 *
 * 6 supported currencies: USD · EUR · GBP · SAR · AED · QAR
 * Rate meaning: 1 foreign unit = rate EGP
 *
 * Bilingual rule: ALL strings are in the ui computed object.
 * When locale switches, every label, placeholder, badge, and heading
 * changes instantly — no hardcoded English text anywhere in template.
 */
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const props = defineProps({
    currencies:        { type: Array,  default: () => [] },
    currentRates:      { type: Array,  default: () => [] },
    history:           { type: Array,  default: () => [] },
    companies:         { type: Array,  default: () => [] },
    selectedCompanyId: { type: Number, default: null },
    filters:           { type: Object, default: () => ({}) },
})

const { locale: lang, isRtl } = useLocale()
const { isSuperAdmin, can }    = usePermissions()

const canEdit   = computed(() => isSuperAdmin.value || can('edit fx_rates'))
const canDelete = computed(() => isSuperAdmin.value || can('edit fx_rates'))

// ══════════════════════════════════════════════════════════════════
// ALL UI STRINGS — bilingual, locale-reactive
// Rule: NEVER write a hardcoded English/Arabic string in the template.
// Always reference ui.value.xxx here.
// ══════════════════════════════════════════════════════════════════
const ui = computed(() => lang.value === 'ar' ? {
    // ── Hero ──────────────────────────────────────────────────────
    eyebrow:            'المحاسبة',
    title:              'أسعار الصرف',
    subtitle:           'أسعار الصرف اليومية — جنيه مصري لكل وحدة من العملة الأجنبية',
    updateBtn:          'تحديث الأسعار',

    // ── Panel ─────────────────────────────────────────────────────
    panelTitle:         'تحديث أسعار الصرف',
    panelSubtitle:      'أدخل أسعار اليوم. تُستخدم هذه الأسعار للتعبئة التلقائية عند إنشاء المعاملات — كل معاملة تحتفظ بسعرها الخاص.',
    rateDateLabel:      'تاريخ السعر',
    rateColHeader:      'السعر (جنيه / وحدة)',
    notesColHeader:     'ملاحظات (اختياري)',
    notesPlaceholder:   'مثال: سعر البنك المركزي / سعر البنك',
    saveBtn:            'حفظ الأسعار',
    cancelBtn:          'إلغاء',

    // ── Currency tiles ────────────────────────────────────────────
    currentRatesTitle:  'الأسعار الحالية',
    rateLabel:          'السعر',
    egpUnit:            'جنيه',
    lastUpdated:        'آخر تحديث',
    today:              'اليوم',
    yesterday:          'أمس',
    daysAgo:            (n) => `منذ ${n} أيام`,
    notSet:             'لم يُضبط',
    staleWarning:       'السعر أقدم من يوم واحد',

    // ── History ───────────────────────────────────────────────────
    historyTitle:       'سجل الأسعار',
    historyEmpty:       'لم يتم إدخال أي أسعار حتى الآن.',
    colCurrency:        'العملة',
    colRate:            'السعر (جنيه)',
    colDate:            'التاريخ',
    colNotes:           'ملاحظات',
    colUpdatedBy:       'بواسطة',
    colSavedAt:         'وقت الحفظ',
    colActions:         'إجراءات',

    // ── Delete modal ──────────────────────────────────────────────
    deleteTitle:        'حذف السعر',
    deleteNote:         'سيتم حذف هذا السجل فقط. المعاملات التي استخدمت هذا السعر لن تتأثر.',
    deleteConfirm:      'تأكيد الحذف',
    deleteCancel:       'إلغاء',

    // ── SA ────────────────────────────────────────────────────────
    selectCompany:      'اختر الشركة',
    companyLabel:       'الشركة',
    noCompanySelected:  'يرجى اختيار شركة لعرض الأسعار',

    // ── Currency full names ───────────────────────────────────────
    currencyNames: {
        USD: 'دولار أمريكي',
        EUR: 'يورو',
        GBP: 'جنيه إسترليني',
        SAR: 'ريال سعودي',
        AED: 'درهم إماراتي',
        QAR: 'ريال قطري',
    },

    // ── Misc ──────────────────────────────────────────────────────
    rateInputPlaceholder: 'أدخل السعر',
    infoNote: 'ملاحظة: هذه الأسعار تُستخدم للتعبئة التلقائية فقط. كل معاملة (قيد يومي، فاتورة، دفعة) تحتفظ بسعر الصرف الخاص بها.',

} : {
    // ── Hero ──────────────────────────────────────────────────────
    eyebrow:            'Accounting',
    title:              'FX Rates',
    subtitle:           'Daily working exchange rates — EGP per 1 unit of foreign currency',
    updateBtn:          'Update Rates',

    // ── Panel ─────────────────────────────────────────────────────
    panelTitle:         'Update Exchange Rates',
    panelSubtitle:      'Set today\'s working rates. These pre-fill the rate field on new transactions — each transaction stores its own rate.',
    rateDateLabel:      'Effective Date',
    rateColHeader:      'Rate (EGP / unit)',
    notesColHeader:     'Notes (optional)',
    notesPlaceholder:   'e.g. CBE rate / bank rate',
    saveBtn:            'Save Rates',
    cancelBtn:          'Cancel',

    // ── Currency tiles ────────────────────────────────────────────
    currentRatesTitle:  'Current Rates',
    rateLabel:          'Rate',
    egpUnit:            'EGP',
    lastUpdated:        'Last updated',
    today:              'Today',
    yesterday:          'Yesterday',
    daysAgo:            (n) => `${n} days ago`,
    notSet:             'Not set',
    staleWarning:       'Rate is more than 1 day old',

    // ── History ───────────────────────────────────────────────────
    historyTitle:       'Rate History',
    historyEmpty:       'No rates have been entered yet.',
    colCurrency:        'Currency',
    colRate:            'Rate (EGP)',
    colDate:            'Date',
    colNotes:           'Notes',
    colUpdatedBy:       'Updated By',
    colSavedAt:         'Saved At',
    colActions:         'Actions',

    // ── Delete modal ──────────────────────────────────────────────
    deleteTitle:        'Delete Rate',
    deleteNote:         'Only this history record is removed. Transactions that used this rate are unaffected.',
    deleteConfirm:      'Delete',
    deleteCancel:       'Cancel',

    // ── SA ────────────────────────────────────────────────────────
    selectCompany:      'Select Company',
    companyLabel:       'Company',
    noCompanySelected:  'Please select a company to view rates',

    // ── Currency full names ───────────────────────────────────────
    currencyNames: {
        USD: 'US Dollar',
        EUR: 'Euro',
        GBP: 'British Pound',
        SAR: 'Saudi Riyal',
        AED: 'UAE Dirham',
        QAR: 'Qatari Riyal',
    },

    // ── Misc ──────────────────────────────────────────────────────
    rateInputPlaceholder: 'Enter rate',
    infoNote: 'Note: These rates are for auto-fill convenience only. Each transaction (journal entry, invoice, payment) always stores its own exchange rate.',
})

// ── Currency flag emojis (universal, no translation needed) ──────
const currencyFlags = {
    USD: '🇺🇸', EUR: '🇪🇺', GBP: '🇬🇧',
    SAR: '🇸🇦', AED: '🇦🇪', QAR: '🇶🇦',
}

// ── SA company filter ─────────────────────────────────────────────
const selectedCompany = ref(props.selectedCompanyId ?? '')
const applyCompanyFilter = () => {
    router.get(route('fx-rates.index'), { company_id: selectedCompany.value }, {
        preserveState: true, preserveScroll: true,
    })
}

// ══════════════════════════════════════════════════════════════════
// UPDATE PANEL
// ══════════════════════════════════════════════════════════════════
const panelOpen = ref(false)

// Build form rows — one per currency, pre-filled with latest known rate
const buildFormRates = () =>
    (props.currencies ?? ['USD','EUR','GBP','SAR','AED','QAR']).map(currency => {
        const current = props.currentRates.find(r => r.currency === currency)
        return {
            currency,
            rate:  current?.rate ?? '',
            notes: '',
        }
    })

const form = useForm({
    rate_date: localToday(),
    rates:     buildFormRates(),
})

const openPanel = () => {
    form.reset()
    form.rate_date = localToday()
    form.rates     = buildFormRates()
    panelOpen.value = true
}

const closePanel = () => { panelOpen.value = false }

const submitRates = () => {
    // Filter out rows with no rate entered
    const filled = form.rates.filter(r => r.rate !== '' && r.rate !== null)
    if (!filled.length) return

    form.transform(data => ({
        ...data,
        rates: filled,
        // Super Admin must send the selected company_id with every store request
        ...(isSuperAdmin.value ? { company_id: selectedCompany.value } : {}),
    })).post(route('fx-rates.store'), {
        preserveScroll: true,
        onSuccess: () => { panelOpen.value = false },
    })
}

// ══════════════════════════════════════════════════════════════════
// DELETE MODAL
// ══════════════════════════════════════════════════════════════════
const deleteModal  = ref(false)
const deleteTarget = ref(null)

const openDelete = (row) => { deleteTarget.value = row; deleteModal.value = true }
const closeDelete = () => { deleteModal.value = false; deleteTarget.value = null }

const confirmDelete = () => {
    router.delete(route('fx-rates.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onSuccess: closeDelete,
    })
}

// ── Staleness helpers ─────────────────────────────────────────────
const staleLabel = (rate) => {
    if (!rate || rate.rate === null) return ui.value.notSet
    if (rate.days_ago === 0)  return ui.value.today
    if (rate.days_ago === 1)  return ui.value.yesterday
    return ui.value.daysAgo(rate.days_ago)
}

const staleClass = (rate) => {
    if (!rate || rate.rate === null) return 'cv-badge-neutral'
    if (rate.days_ago === 0)  return 'cv-badge-green'
    if (rate.days_ago === 1)  return 'cv-badge-amber'
    return 'cv-badge-danger'
}

// ── Stripe color per currency ─────────────────────────────────────
const currencyStripe = {
    USD: 'cv-card-stripe-blue',
    EUR: 'cv-card-stripe-blue',
    GBP: 'cv-card-stripe-amber',
    SAR: 'cv-card-stripe-green',
    AED: 'cv-card-stripe-green',
    QAR: 'cv-card-stripe-green',
}
</script>

<template>
    
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ──────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ ui.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ ui.title }}</div>
                <div class="cv-page-hero-sub">{{ ui.subtitle }}</div>
                <div class="cv-page-hero-actions">
                    <button
                        v-if="canEdit"
                        class="cv-btn cv-btn-amber"
                        :disabled="isSuperAdmin && !selectedCompany"
                        @click="openPanel"
                    >
                        <!-- currency swap icon -->
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8 3L4 7l4 4"/>
                            <path d="M4 7h16"/>
                            <path d="M16 21l4-4-4-4"/>
                            <path d="M20 17H4"/>
                        </svg>
                        {{ ui.updateBtn }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- ── SA Company selector ───────────────────────── -->
                <div v-if="isSuperAdmin" class="mb-6">
                    <div class="cv-card" style="padding:1rem 1.25rem;">
                        <div class="flex items-center gap-3 flex-wrap">
                            <label class="cv-label" style="min-width:80px;">
                                {{ ui.companyLabel }}
                            </label>
                            <select
                                v-model="selectedCompany"
                                class="cv-select"
                                style="min-width:240px;"
                                @change="applyCompanyFilter"
                            >
                                <option value="">{{ ui.selectCompany }}</option>
                                <option
                                    v-for="c in companies" :key="c.id" :value="c.id"
                                >{{ lang === 'ar' ? (c.name_ar || c.name_en) : c.name_en }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── No company selected (SA only) ─────────────── -->
                <div v-if="isSuperAdmin && !selectedCompany" class="cv-card" style="padding:2rem; text-align:center;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                         stroke="var(--cv-text-muted)" stroke-width="1.5"
                         stroke-linecap="round" stroke-linejoin="round"
                         style="margin:0 auto 1rem;">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    <p style="color:var(--cv-text-muted); font-size:0.875rem;">{{ ui.noCompanySelected }}</p>
                </div>

                <template v-else>

                    <!-- ── Info note ──────────────────────────────── -->
                    <div class="cv-alert cv-alert-info mb-6" style="font-size:0.8125rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        {{ ui.infoNote }}
                    </div>

                    <!-- ── Current Rates — 6 currency tiles ─────────── -->
                    <div class="mb-2" style="font-size:0.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--cv-text-muted);">
                        {{ ui.currentRatesTitle }}
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(200px,1fr)); gap:1rem; margin-bottom:2rem;">
                        <div
                            v-for="rate in currentRates"
                            :key="rate.currency"
                            class="cv-card"
                            :class="currencyStripe[rate.currency] || 'cv-card-stripe-blue'"
                        >
                            <div style="padding:1rem 1.25rem;">
                                <!-- Currency header -->
                                <div class="flex items-center gap-2 mb-3">
                                    <span style="font-size:1.5rem; line-height:1;">
                                        {{ currencyFlags[rate.currency] }}
                                    </span>
                                    <div>
                                        <div style="font-size:0.9375rem; font-weight:700; color:var(--cv-text-primary);">
                                            {{ rate.currency }}
                                        </div>
                                        <div style="font-size:0.6875rem; color:var(--cv-text-muted);">
                                            {{ ui.currencyNames[rate.currency] }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Rate value -->
                                <div v-if="rate.rate !== null" style="margin-bottom:0.75rem;">
                                    <span style="font-size:1.5rem; font-weight:800; color:var(--cv-blue-text); font-variant-numeric:tabular-nums;">
                                        {{ Number(rate.rate).toFixed(4) }}
                                    </span>
                                    <span style="font-size:0.75rem; color:var(--cv-text-muted); margin-inline-start:0.3rem;">
                                        {{ ui.egpUnit }}
                                    </span>
                                </div>
                                <div v-else style="font-size:1.25rem; font-weight:700; color:var(--cv-text-muted); margin-bottom:0.75rem;">
                                    —
                                </div>

                                <!-- Staleness badge -->
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="cv-badge" :class="staleClass(rate)" style="font-size:0.6875rem;">
                                        {{ staleLabel(rate) }}
                                    </span>
                                    <span
                                        v-if="rate.is_stale"
                                        title="{{ ui.staleWarning }}"
                                        style="color:var(--cv-danger-text); font-size:0.75rem;"
                                    >⚠</span>
                                </div>

                                <!-- Updated by -->
                                <div v-if="rate.updated_by" style="margin-top:0.5rem; font-size:0.6875rem; color:var(--cv-text-muted);">
                                    {{ rate.updated_by }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── History table ──────────────────────────── -->
                    <div class="cv-card">
                        <div style="padding:1rem 1.25rem 0.75rem; border-bottom:1px solid var(--cv-border); font-size:0.8125rem; font-weight:600; color:var(--cv-text-primary);">
                            {{ ui.historyTitle }}
                        </div>

                        <!-- Empty state -->
                        <div v-if="!history.length" style="padding:3rem; text-align:center;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                                 stroke="var(--cv-text-muted)" stroke-width="1.5"
                                 stroke-linecap="round" stroke-linejoin="round"
                                 style="margin:0 auto 1rem;">
                                <path d="M8 3L4 7l4 4"/><path d="M4 7h16"/>
                                <path d="M16 21l4-4-4-4"/><path d="M20 17H4"/>
                            </svg>
                            <p style="color:var(--cv-text-muted); font-size:0.875rem;">{{ ui.historyEmpty }}</p>
                        </div>

                        <!-- Table -->
                        <div v-else style="overflow-x:auto;">
                            <table class="cv-table" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>{{ ui.colCurrency }}</th>
                                        <th style="text-align:end;">{{ ui.colRate }}</th>
                                        <th>{{ ui.colDate }}</th>
                                        <th>{{ ui.colNotes }}</th>
                                        <th>{{ ui.colUpdatedBy }}</th>
                                        <th>{{ ui.colSavedAt }}</th>
                                        <th v-if="canDelete"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in history" :key="row.id">
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <span>{{ currencyFlags[row.currency] }}</span>
                                                <span style="font-weight:600; color:var(--cv-text-primary);">{{ row.currency }}</span>
                                                <span style="font-size:0.75rem; color:var(--cv-text-muted);">
                                                    {{ ui.currencyNames[row.currency] }}
                                                </span>
                                            </div>
                                        </td>
                                        <td style="text-align:end; color:var(--cv-blue-text); font-weight:600; font-variant-numeric:tabular-nums;">
                                            {{ Number(row.rate).toFixed(4) }}
                                        </td>
                                        <td style="color:var(--cv-text-secondary);">{{ row.rate_date }}</td>
                                        <td style="color:var(--cv-text-muted); font-size:0.8125rem;">{{ row.notes || '—' }}</td>
                                        <td style="color:var(--cv-text-muted); font-size:0.8125rem;">{{ row.updated_by || '—' }}</td>
                                        <td style="color:var(--cv-text-muted); font-size:0.8125rem;">{{ row.updated_at }}</td>
                                        <td v-if="canDelete" style="text-align:end;">
                                            <button
                                                class="cv-action-btn cv-action-btn-danger"
                                                @click="openDelete(row)"
                                                title="{{ ui.deleteTitle }}"
                                            >
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                     stroke="currentColor" stroke-width="1.7"
                                                     stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="3 6 5 6 21 6"/>
                                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                    <path d="M10 11v6"/><path d="M14 11v6"/>
                                                    <path d="M9 6V4h6v2"/>
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </template>
            </div><!-- end cv-page -->

            <!-- ══════════════════════════════════════════════════════
                 UPDATE RATES PANEL — slide in from inline-end
            ══════════════════════════════════════════════════════════ -->
            <Transition name="panel-slide">
                <div v-if="panelOpen" class="cv-modal-backdrop" @click.self="closePanel">
                    <div
                        class="cv-modal cv-modal-lg"
                        style="position:fixed; inset-inline-end:0; top:0; bottom:0; height:100vh; border-radius:0; overflow-y:auto; max-width:560px; width:100%;"
                        :dir="isRtl ? 'rtl' : 'ltr'"
                    >
                        <!-- Panel header -->
                        <div class="cv-modal-header">
                            <div>
                                <div style="font-size:1rem; font-weight:700; color:var(--cv-text-primary);">
                                    {{ ui.panelTitle }}
                                </div>
                                <div style="font-size:0.75rem; color:var(--cv-text-muted); margin-top:3px;">
                                    {{ ui.panelSubtitle }}
                                </div>
                            </div>
                            <button class="cv-btn cv-btn-ghost" style="padding:0.375rem;" @click="closePanel">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.7"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>

                        <div class="cv-modal-body">

                            <!-- Effective date -->
                            <div class="cv-field mb-5">
                                <label class="cv-label">{{ ui.rateDateLabel }}</label>
                                <input
                                    v-model="form.rate_date"
                                    type="date"
                                    class="cv-input"
                                    :class="{ 'border-red-500': form.errors.rate_date }"
                                />
                                <div v-if="form.errors.rate_date" class="cv-field-error">
                                    {{ form.errors.rate_date }}
                                </div>
                            </div>

                            <!-- Currency rows -->
                            <div style="border:1px solid var(--cv-border); border-radius:var(--cv-radius); overflow:hidden; margin-bottom:1.5rem;">

                                <!-- Column headers -->
                                <div style="display:grid; grid-template-columns:140px 1fr 1fr; gap:0; background:var(--cv-bg-card-alt); padding:0.625rem 1rem; border-bottom:1px solid var(--cv-border);">
                                    <div style="font-size:0.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--cv-text-muted);">
                                        {{ ui.colCurrency }}
                                    </div>
                                    <div style="font-size:0.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--cv-text-muted); padding-inline-start:0.5rem;">
                                        {{ ui.rateColHeader }}
                                    </div>
                                    <div style="font-size:0.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--cv-text-muted); padding-inline-start:0.5rem;">
                                        {{ ui.notesColHeader }}
                                    </div>
                                </div>

                                <!-- One row per currency -->
                                <div
                                    v-for="(row, idx) in form.rates"
                                    :key="row.currency"
                                    style="display:grid; grid-template-columns:140px 1fr 1fr; gap:0; align-items:center; padding:0.75rem 1rem; border-bottom:1px solid var(--cv-border);"
                                    :style="{ background: idx % 2 === 0 ? 'var(--cv-bg-card)' : 'var(--cv-bg-card-alt)' }"
                                >
                                    <!-- Currency identity -->
                                    <div class="flex items-center gap-2">
                                        <span style="font-size:1.25rem;">{{ currencyFlags[row.currency] }}</span>
                                        <div>
                                            <div style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">
                                                {{ row.currency }}
                                            </div>
                                            <div style="font-size:0.6875rem; color:var(--cv-text-muted);">
                                                {{ ui.currencyNames[row.currency] }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Rate input -->
                                    <div style="padding-inline-start:0.5rem;">
                                        <div class="cv-input-group">
                                            <span class="cv-input-prefix" style="font-size:0.75rem; color:var(--cv-text-muted);">
                                                {{ ui.egpUnit }}
                                            </span>
                                            <input
                                                v-model="row.rate"
                                                type="number"
                                                step="0.0001"
                                                min="0.0001"
                                                class="cv-input"
                                                :placeholder="ui.rateInputPlaceholder"
                                                style="font-variant-numeric:tabular-nums;"
                                            />
                                        </div>
                                        <div v-if="form.errors[`rates.${idx}.rate`]" class="cv-field-error" style="margin-top:4px;">
                                            {{ form.errors[`rates.${idx}.rate`] }}
                                        </div>
                                    </div>

                                    <!-- Notes input -->
                                    <div style="padding-inline-start:0.5rem;">
                                        <input
                                            v-model="row.notes"
                                            type="text"
                                            class="cv-input"
                                            :placeholder="ui.notesPlaceholder"
                                            style="font-size:0.8125rem;"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- General error -->
                            <div v-if="form.errors.rates" class="cv-alert cv-alert-danger mb-4">
                                {{ form.errors.rates }}
                            </div>

                        </div>

                        <!-- Panel footer -->
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="closePanel">
                                {{ ui.cancelBtn }}
                            </button>
                            <button
                                class="cv-btn cv-btn-amber"
                                :disabled="form.processing"
                                @click="submitRates"
                            >
                                <svg v-if="form.processing" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.7"
                                     style="animation:spin 1s linear infinite;">
                                    <path d="M21 12a9 9 0 1 1-6.22-8.56"/>
                                </svg>
                                {{ ui.saveBtn }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ══════════════════════════════════════════════════════
                 DELETE CONFIRMATION MODAL
            ══════════════════════════════════════════════════════════ -->
            <Transition name="fade">
                <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="closeDelete">
                    <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                        <div class="cv-modal-header">
                            <div style="display:flex; align-items:center; gap:0.625rem;">
                                <div style="width:32px; height:32px; border-radius:50%; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                         stroke="var(--cv-danger-text)" stroke-width="1.7"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                        <path d="M9 6V4h6v2"/>
                                    </svg>
                                </div>
                                <span style="font-weight:600; color:var(--cv-text-primary);">{{ ui.deleteTitle }}</span>
                            </div>
                        </div>
                        <div class="cv-modal-body">
                            <div v-if="deleteTarget" style="margin-bottom:1rem; padding:0.75rem 1rem; background:var(--cv-bg-card-alt); border-radius:var(--cv-radius); border:1px solid var(--cv-border);">
                                <div class="flex items-center gap-2 mb-1">
                                    <span>{{ currencyFlags[deleteTarget.currency] }}</span>
                                    <span style="font-weight:700; color:var(--cv-text-primary);">{{ deleteTarget.currency }}</span>
                                    <span style="color:var(--cv-blue-text); font-weight:600;">
                                        {{ Number(deleteTarget.rate).toFixed(4) }} {{ ui.egpUnit }}
                                    </span>
                                </div>
                                <div style="font-size:0.8125rem; color:var(--cv-text-muted);">{{ deleteTarget.rate_date }}</div>
                            </div>
                            <div class="cv-alert cv-alert-danger" style="font-size:0.8125rem;">
                                {{ ui.deleteNote }}
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="closeDelete">{{ ui.deleteCancel }}</button>
                            <button class="cv-btn cv-btn-danger" @click="confirmDelete">{{ ui.deleteConfirm }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
   
</template>

<style scoped>
@keyframes spin { to { transform: rotate(360deg); } }

.panel-slide-enter-active,
.panel-slide-leave-active { transition: opacity 0.2s, transform 0.25s; }
.panel-slide-enter-from,
.panel-slide-leave-to    { opacity: 0; transform: translateX(2rem); }

[dir="rtl"] .panel-slide-enter-from,
[dir="rtl"] .panel-slide-leave-to { transform: translateX(-2rem); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from,  .fade-leave-to      { opacity: 0; }
</style>