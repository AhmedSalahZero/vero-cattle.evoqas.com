<script setup>
/**
 * Accounting/JournalEntries/Index.vue
 * ─────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Journal Entries Index
 *
 * Props (from JournalEntryController::index()):
 *   entries     — paginated list (formatted by formatEntry())
 *   fiscalYears — available fiscal years for filter dropdown
 *   filters     — active filter values
 *   user.permissions    — 'super_admin' | role string
 *
 * Bilingual rule:
 *   Every UI string is keyed in the `ui` object as { en, ar }.
 *   Switching locale instantly shows 100% Arabic or English.
 *   Account names use n() helper (name_en / name_ar).
 *
 * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation
 *   |Σ debit − Σ credit| ≤ 0.005 — balance check shown on every card.
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const { locale: lang, isRtl, n } = useLocale()

// ── Bilingual UI strings ──────────────────────────────────────────
const ui = {
    // Hero
    eyebrow:        { en: 'Accounting',              ar: 'المحاسبة' },
    title:          { en: 'Journal Entries',         ar: 'القيود اليومية' },
    subtitle:       { en: 'Double-entry ledger — every entry must balance', ar: 'دفتر القيد المزدوج — كل قيد يجب أن يكون متوازناً' },
    newEntry:       { en: 'New Entry',               ar: 'قيد جديد' },

    // Filters
    filterTitle:    { en: 'Filter & Search',         ar: 'تصفية وبحث' },
    allStatuses:    { en: 'All Statuses',            ar: 'جميع الحالات' },
    allTypes:       { en: 'All Types',               ar: 'جميع الأنواع' },
    allYears:       { en: 'All Fiscal Years',        ar: 'كل السنوات المالية' },
    refSearch:      { en: 'Search reference…',       ar: 'بحث بالمرجع…' },
    dateFrom:       { en: 'From date',               ar: 'من تاريخ' },
    dateTo:         { en: 'To date',                 ar: 'إلى تاريخ' },
    clearFilters:   { en: 'Clear',                   ar: 'مسح' },

    // Stats
    statTotal:      { en: 'Total Entries',           ar: 'إجمالي القيود' },
    statPosted:     { en: 'Posted',                  ar: 'مرحلة' },
    statDraft:      { en: 'Draft',                   ar: 'مسودة' },
    statUnbalanced: { en: 'Unbalanced',              ar: 'غير متوازنة' },

    // Card
    lines:          { en: 'lines',                   ar: 'سطور' },
    debit:          { en: 'Debit',                   ar: 'مدين' },
    credit:         { en: 'Credit',                  ar: 'دائن' },
    balanced:       { en: 'Balanced',                ar: 'متوازن' },
    imbalanced:     { en: 'Imbalanced',              ar: 'غير متوازن' },
    postedBy:       { en: 'Posted by',               ar: 'رحّله' },
    fy:             { en: 'FY',                      ar: 'سنة' },

    // Actions
    view:           { en: 'View',                    ar: 'عرض' },
    edit:           { en: 'Edit',                    ar: 'تعديل' },
    post:           { en: 'Post',                    ar: 'ترحيل' },
    reverse:        { en: 'Reverse',                 ar: 'عكس' },
    delete:         { en: 'Delete',                  ar: 'حذف' },

    // Status badges
    statusDraft:    { en: 'Draft',                   ar: 'مسودة' },
    statusPosted:   { en: 'Posted',                  ar: 'مرحلة' },
    statusReversed: { en: 'Reversed',                ar: 'معكوس' },

    // Entry types
    typeManual:     { en: 'Manual',                  ar: 'يدوي' },
    typeOpening:    { en: 'Opening Balance',         ar: 'رصيد افتتاحي' },
    typeProcurement:{ en: 'Procurement',             ar: 'مشتريات' },
    typeSale:       { en: 'Sale',                    ar: 'مبيعات' },
    typeFeed:       { en: 'Feed Consumption',        ar: 'استهلاك علف' },
    typeHealth:     { en: 'Health',                  ar: 'صحة' },
    typeDepreciation:{ en: 'Depreciation',           ar: 'إهلاك' },
    typePayment:    { en: 'Payment',                 ar: 'دفعة' },
    typeReversal:   { en: 'Reversal',                ar: 'قيد عكسي' },

    // Post modal
    postTitle:      { en: 'Post Journal Entry',      ar: 'ترحيل القيد' },
    postNote:       { en: 'Once posted, this entry cannot be edited. It can only be reversed.', ar: 'بعد الترحيل لا يمكن تعديل القيد — يمكن فقط عكسه.' },
    postConfirm:    { en: 'Post Entry',              ar: 'ترحيل القيد' },
    cancel:         { en: 'Cancel',                  ar: 'إلغاء' },

    // Reverse modal
    revTitle:       { en: 'Reverse Journal Entry',   ar: 'عكس القيد اليومي' },
    revDate:        { en: 'Reversal Date',           ar: 'تاريخ العكس' },
    revDesc:        { en: 'Reversal Description (optional)', ar: 'وصف العكس (اختياري)' },
    revNote:        { en: 'A mirror entry will be created with all debits and credits swapped.', ar: 'سيتم إنشاء قيد معكوس بتبديل المدين والدائن.' },
    revConfirm:     { en: 'Reverse Entry',           ar: 'عكس القيد' },

    // Delete modal
    delTitle:       { en: 'Delete Journal Entry',    ar: 'حذف القيد' },
    delNote:        { en: 'This draft entry will be permanently deleted. This action cannot be undone.', ar: 'سيتم حذف هذه المسودة نهائياً. لا يمكن التراجع عن هذا الإجراء.' },
    delConfirm:     { en: 'Delete Entry',            ar: 'حذف القيد' },

    // Auto-entry info
    autoEntry:      { en: 'Auto-generated entry — read only', ar: 'قيد تلقائي — للقراءة فقط' },

    // Empty
    emptyTitle:     { en: 'No journal entries yet',  ar: 'لا توجد قيود يومية بعد' },
    emptySub:       { en: 'Create your first manual journal entry to get started.', ar: 'أنشئ أول قيد يومي يدوي للبدء.' },

    // Pagination
    showing:        { en: 'Showing',                 ar: 'عرض' },
    of:             { en: 'of',                      ar: 'من' },
    prev:           { en: 'Previous',                ar: 'السابق' },
    next:           { en: 'Next',                    ar: 'التالي' },
}

const t = (key) => ui[key]?.[lang.value] ?? key

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    entries:     Object,  // paginated
    fiscalYears: Array,
    filters:     Object,
})

// ── Filters ───────────────────────────────────────────────────────
const localFilters = ref({
    status:         props.filters?.status         ?? '',
    entry_type:     props.filters?.entry_type     ?? '',
    fiscal_year_id: props.filters?.fiscal_year_id ?? '',
    date_from:      props.filters?.date_from       ?? '',
    date_to:        props.filters?.date_to         ?? '',
    reference:      props.filters?.reference       ?? '',
})

const applyFilters = () => {
    router.get(route('journal-entries.index'), localFilters.value, {
        preserveState: true, preserveScroll: true,
    })
}

const clearFilters = () => {
    localFilters.value = { status: '', entry_type: '', fiscal_year_id: '', date_from: '', date_to: '', reference: '' }
    applyFilters()
}

const hasActiveFilters = computed(() =>
    Object.values(localFilters.value).some(v => v !== '')
)

// ── Stats ─────────────────────────────────────────────────────────
const allEntries   = computed(() => props.entries?.data ?? [])
const totalEntries = computed(() => props.entries?.total ?? 0)
const postedCount  = computed(() => allEntries.value.filter(e => e.status === 'posted').length)
const draftCount   = computed(() => allEntries.value.filter(e => e.status === 'draft').length)
const unbalancedCount = computed(() => allEntries.value.filter(e => !e.is_balanced).length)

// ── Helpers ───────────────────────────────────────────────────────
const formatAmount = (v) => {
    const num = parseFloat(v ?? 0)
    return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const statusClass = (s) => ({
    draft:    'cv-badge cv-badge-amber',
    posted:   'cv-badge cv-badge-green',
    reversed: 'cv-badge cv-badge-neutral',
}[s] ?? 'cv-badge cv-badge-neutral')

const statusLabel = (s) => ({
    draft:    t('statusDraft'),
    posted:   t('statusPosted'),
    reversed: t('statusReversed'),
}[s] ?? s)

const typeLabel = (type) => ({
    manual:          t('typeManual'),
    opening_balance: t('typeOpening'),
    procurement:     t('typeProcurement'),
    sale:            t('typeSale'),
    feed_consumption:t('typeFeed'),
    health:          t('typeHealth'),
    depreciation:    t('typeDepreciation'),
    payment:         t('typePayment'),
    reversal:        t('typeReversal'),
}[type] ?? type)

const typeClass = (type) => ({
    manual:          'cv-badge cv-badge-info',
    opening_balance: 'cv-badge cv-badge-amber',
    procurement:     'cv-badge cv-badge-green',
    sale:            'cv-badge cv-badge-green',
    feed_consumption:'cv-badge cv-badge-neutral',
    health:          'cv-badge cv-badge-neutral',
    depreciation:    'cv-badge cv-badge-neutral',
    payment:         'cv-badge cv-badge-green',
    reversal:        'cv-badge cv-badge-danger',
}[type] ?? 'cv-badge cv-badge-neutral')

const stripeClass = (status) => ({
    draft:    'cv-card-stripe-amber',
    posted:   'cv-card-stripe-green',
    reversed: 'cv-card-stripe-muted',
}[status] ?? 'cv-card-stripe-muted')

const isAutoEntry = (entry) => !!entry.source_type && entry.entry_type !== 'manual' && entry.entry_type !== 'opening_balance'
const canEdit     = (entry) => entry.status === 'draft' && !isAutoEntry(entry)
const canPost     = (entry) => entry.status === 'draft'
const canReverse  = (entry) => entry.status === 'posted'
const canDelete   = (entry) => entry.status === 'draft' && !isAutoEntry(entry)

// ── Post modal ────────────────────────────────────────────────────
const postModal  = ref(false)
const postTarget = ref(null)
const openPost   = (entry) => { postTarget.value = entry; postModal.value = true }
const closePost  = () => { postModal.value = false; postTarget.value = null }
const confirmPost = () => {
    router.patch(route('journal-entries.post', postTarget.value.id), {}, {
        onSuccess: closePost,
    })
}

// ── Reverse modal ─────────────────────────────────────────────────
const revModal    = ref(false)
const revTarget   = ref(null)
const revForm     = ref({ reversal_date: '', reversal_description: '' })
const openRev     = (entry) => {
    revTarget.value = entry
    revForm.value   = { reversal_date: new Date().toISOString().slice(0, 10), reversal_description: '' }
    revModal.value  = true
}
const closeRev    = () => { revModal.value = false; revTarget.value = null }
const confirmRev  = () => {
    router.post(route('journal-entries.reverse', revTarget.value.id), revForm.value, {
        onSuccess: closeRev,
    })
}

// ── Delete modal ──────────────────────────────────────────────────
const delModal   = ref(false)
const delTarget  = ref(null)
const openDel    = (entry) => { delTarget.value = entry; delModal.value = true }
const closeDel   = () => { delModal.value = false; delTarget.value = null }
const confirmDel = () => {
    router.delete(route('journal-entries.destroy', delTarget.value.id), {
        onSuccess: closeDel,
    })
}

// ── Pagination ────────────────────────────────────────────────────
const goToPage = (url) => { if (url) router.get(url, {}, { preserveScroll: true }) }
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <span class="cv-header-breadcrumb-current">{{ t('title') }}</span>
        </template>

        <!-- ── Hero ──────────────────────────────────────────────── -->
        <div class="cv-page-hero" :dir="isRtl ? 'rtl' : 'ltr'">
            <div class="cv-page-hero-eyebrow">{{ t('eyebrow') }}</div>
            <div class="cv-page-hero-title">{{ t('title') }}</div>
            <div class="cv-page-hero-sub">{{ t('subtitle') }}</div>
            <div class="cv-page-hero-actions">
                <Link :href="route('journal-entries.create')" class="cv-btn cv-btn-amber">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    {{ t('newEntry') }}
                </Link>
            </div>
        </div>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── KPI Strip ──────────────────────────────────────── -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
                <div class="cv-stat-card cv-stat-card-blue">
                    <div class="cv-stat-card-label">{{ t('statTotal') }}</div>
                    <div class="cv-stat-card-value">{{ totalEntries }}</div>
                </div>
                <div class="cv-stat-card cv-stat-card-green">
                    <div class="cv-stat-card-label">{{ t('statPosted') }}</div>
                    <div class="cv-stat-card-value">{{ postedCount }}</div>
                </div>
                <div class="cv-stat-card cv-stat-card-amber">
                    <div class="cv-stat-card-label">{{ t('statDraft') }}</div>
                    <div class="cv-stat-card-value">{{ draftCount }}</div>
                </div>
                <div class="cv-stat-card" :class="unbalancedCount > 0 ? 'cv-stat-card-red' : ''">
                    <div class="cv-stat-card-label">{{ t('statUnbalanced') }}</div>
                    <div class="cv-stat-card-value">{{ unbalancedCount }}</div>
                </div>
            </div>

            <!-- ── Filter Bar ─────────────────────────────────────── -->
            <div class="cv-card" style="margin-bottom:1.5rem;">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:0.75rem;align-items:end;">

                    <!-- Status -->
                    <div class="cv-field">
                        <label class="cv-label">{{ t('allStatuses') }}</label>
                        <select class="cv-select" v-model="localFilters.status" @change="applyFilters">
                            <option value="">{{ t('allStatuses') }}</option>
                            <option value="draft">{{ t('statusDraft') }}</option>
                            <option value="posted">{{ t('statusPosted') }}</option>
                            <option value="reversed">{{ t('statusReversed') }}</option>
                        </select>
                    </div>

                    <!-- Entry Type -->
                    <div class="cv-field">
                        <label class="cv-label">{{ t('allTypes') }}</label>
                        <select class="cv-select" v-model="localFilters.entry_type" @change="applyFilters">
                            <option value="">{{ t('allTypes') }}</option>
                            <option value="manual">{{ t('typeManual') }}</option>
                            <option value="opening_balance">{{ t('typeOpening') }}</option>
                            <option value="procurement">{{ t('typeProcurement') }}</option>
                            <option value="sale">{{ t('typeSale') }}</option>
                            <option value="feed_consumption">{{ t('typeFeed') }}</option>
                            <option value="health">{{ t('typeHealth') }}</option>
                            <option value="depreciation">{{ t('typeDepreciation') }}</option>
                            <option value="payment">{{ t('typePayment') }}</option>
                            <option value="reversal">{{ t('typeReversal') }}</option>
                        </select>
                    </div>

                    <!-- Fiscal Year -->
                    <div class="cv-field">
                        <label class="cv-label">{{ t('allYears') }}</label>
                        <select class="cv-select" v-model="localFilters.fiscal_year_id" @change="applyFilters">
                            <option value="">{{ t('allYears') }}</option>
                            <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">{{ fy.year }}</option>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div class="cv-field">
                        <label class="cv-label">{{ t('dateFrom') }}</label>
                        <input type="date" class="cv-input" v-model="localFilters.date_from" @change="applyFilters">
                    </div>

                    <!-- Date To -->
                    <div class="cv-field">
                        <label class="cv-label">{{ t('dateTo') }}</label>
                        <input type="date" class="cv-input" v-model="localFilters.date_to" @change="applyFilters">
                    </div>

                    <!-- Reference search -->
                    <div class="cv-field">
                        <label class="cv-label">{{ t('refSearch') }}</label>
                        <div class="cv-search-wrap">
                            <svg class="cv-search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <input type="text" class="cv-search-input" v-model="localFilters.reference"
                                :placeholder="t('refSearch')" @input.stop @keyup.enter="applyFilters">
                        </div>
                    </div>

                    <!-- Clear button -->
                    <div class="cv-field" style="display:flex;align-items:flex-end;">
                        <button v-if="hasActiveFilters" class="cv-btn cv-btn-ghost cv-btn-sm" @click="clearFilters">
                            {{ t('clearFilters') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Entry Cards ────────────────────────────────────── -->
            <div v-if="allEntries.length === 0" class="cv-empty" style="padding:5rem 1rem;">
                <div class="cv-empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.25">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                </div>
                <div class="cv-empty-title">{{ t('emptyTitle') }}</div>
                <div class="cv-empty-sub">{{ t('emptySub') }}</div>
            </div>

            <div v-else style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem;">
                <div
                    v-for="entry in allEntries"
                    :key="entry.id"
                    class="cv-card"
                    :class="stripeClass(entry.status)"
                    style="display:flex;flex-direction:column;gap:0.875rem;"
                >
                    <!-- Row 1: Reference + Status badges -->
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                        <div>
                            <div style="font-size:0.9375rem;font-weight:700;color:var(--cv-text-primary);font-family:monospace;">
                                {{ entry.reference ?? `#${entry.id}` }}
                            </div>
                            <div style="font-size:0.75rem;color:var(--cv-text-muted);margin-top:2px;">
                                {{ entry.date }} &bull; {{ t('fy') }} {{ entry.fiscal_year?.year ?? '—' }}
                            </div>
                        </div>
                        <div style="display:flex;gap:0.375rem;flex-wrap:wrap;">
                            <span :class="statusClass(entry.status)">{{ statusLabel(entry.status) }}</span>
                            <span :class="typeClass(entry.entry_type)">{{ typeLabel(entry.entry_type) }}</span>
                        </div>
                    </div>

                    <!-- Description -->
                    <div v-if="entry.description" style="font-size:0.8125rem;color:var(--cv-text-secondary);line-height:1.4;">
                        {{ entry.description }}
                    </div>

                    <!-- Auto-entry badge -->
                    <div v-if="isAutoEntry(entry)" style="display:flex;align-items:center;gap:0.375rem;background:var(--cv-info-bg);border:1px solid var(--cv-info-border);border-radius:var(--cv-radius-sm);padding:0.375rem 0.625rem;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cv-blue-text);flex-shrink:0;">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span style="font-size:0.6875rem;color:var(--cv-blue-text);">{{ t('autoEntry') }}</span>
                    </div>

                    <!-- Debit / Credit / Balance row -->
                    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:0.5rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius-sm);padding:0.625rem 0.75rem;">
                        <div>
                            <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:2px;">{{ t('debit') }}</div>
                            <div style="font-size:0.875rem;font-weight:700;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">{{ formatAmount(entry.total_debit) }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:2px;">{{ t('credit') }}</div>
                            <div style="font-size:0.875rem;font-weight:700;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">{{ formatAmount(entry.total_credit) }}</div>
                        </div>
                        <div style="display:flex;align-items:center;">
                            <span v-if="entry.is_balanced" class="cv-badge cv-badge-green">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                {{ t('balanced') }}
                            </span>
                            <span v-else class="cv-badge cv-badge-danger">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                                {{ t('imbalanced') }}
                            </span>
                        </div>
                    </div>

                    <!-- Lines count + poster -->
                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.75rem;color:var(--cv-text-muted);">
                        <span>{{ entry.lines_count }} {{ t('lines') }}</span>
                        <span v-if="entry.poster">{{ t('postedBy') }}: {{ entry.poster.name }}</span>
                    </div>

                    <!-- Action buttons -->
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;padding-top:0.25rem;border-top:1px solid var(--cv-border);">
                        <!-- View -->
                        <Link :href="route('journal-entries.show', entry.id)" class="cv-btn cv-btn-secondary cv-btn-sm">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            {{ t('view') }}
                        </Link>

                        <!-- Edit (draft, non-auto) -->
                        <Link v-if="canEdit(entry)" :href="route('journal-entries.edit', entry.id)" class="cv-btn cv-btn-ghost cv-btn-sm">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            {{ t('edit') }}
                        </Link>

                        <!-- Post (draft) -->
                        <button v-if="canPost(entry)" class="cv-btn cv-btn-amber cv-btn-sm" @click="openPost(entry)">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="22 2 11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                            </svg>
                            {{ t('post') }}
                        </button>

                        <!-- Reverse (posted) -->
                        <button v-if="canReverse(entry)" class="cv-btn cv-btn-ghost cv-btn-sm" @click="openRev(entry)">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/>
                            </svg>
                            {{ t('reverse') }}
                        </button>

                        <!-- Delete (draft, non-auto) -->
                        <button v-if="canDelete(entry)" class="cv-btn cv-btn-sm" style="background:transparent;border:1px solid transparent;color:var(--cv-text-muted);" @click="openDel(entry)">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>
                            </svg>
                            {{ t('delete') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Pagination ──────────────────────────────────────── -->
            <div v-if="entries?.links?.length > 3" style="display:flex;justify-content:center;margin-top:2rem;">
                <div class="cv-pagination">
                    <button
                        class="cv-page-btn"
                        :disabled="!entries.prev_page_url"
                        @click="goToPage(entries.prev_page_url)"
                    >{{ t('prev') }}</button>

                    <template v-for="link in entries.links" :key="link.label">
                        <button
                            v-if="link.label !== '&laquo; Previous' && link.label !== 'Next &raquo;'"
                            class="cv-page-btn"
                            :class="{ active: link.active }"
                            @click="goToPage(link.url)"
                            :disabled="!link.url"
                            v-html="link.label"
                        />
                    </template>

                    <button
                        class="cv-page-btn"
                        :disabled="!entries.next_page_url"
                        @click="goToPage(entries.next_page_url)"
                    >{{ t('next') }}</button>
                </div>
            </div>

        </div><!-- /cv-page -->

        <!-- ══════════════════════════════════════════════════════════
             POST CONFIRMATION MODAL
        ══════════════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="postModal" class="cv-modal-backdrop" @click.self="closePost">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <span style="font-weight:600;color:var(--cv-text-primary);">{{ t('postTitle') }}</span>
                        <button class="cv-action-btn" @click="closePost">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="cv-modal-body">
                        <!-- Entry summary -->
                        <div style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);padding:0.875rem;margin-bottom:1rem;">
                            <div style="font-size:0.8125rem;font-weight:700;color:var(--cv-text-primary);font-family:monospace;">{{ postTarget?.reference ?? `#${postTarget?.id}` }}</div>
                            <div style="font-size:0.75rem;color:var(--cv-text-muted);margin-top:4px;">{{ postTarget?.date }} &bull; {{ formatAmount(postTarget?.total_debit) }} EGP</div>
                        </div>
                        <div style="background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:var(--cv-radius-sm);padding:0.75rem;font-size:0.8125rem;color:var(--cv-amber-text);">
                            {{ t('postNote') }}
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closePost">{{ t('cancel') }}</button>
                        <button class="cv-btn cv-btn-amber" @click="confirmPost">{{ t('postConfirm') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══════════════════════════════════════════════════════════
             REVERSE MODAL
        ══════════════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="revModal" class="cv-modal-backdrop" @click.self="closeRev">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <span style="font-weight:600;color:var(--cv-text-primary);">{{ t('revTitle') }}</span>
                        <button class="cv-action-btn" @click="closeRev">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;">
                        <div class="cv-field">
                            <label class="cv-label">{{ t('revDate') }}</label>
                            <input type="date" class="cv-input" v-model="revForm.reversal_date">
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ t('revDesc') }}</label>
                            <input type="text" class="cv-input" v-model="revForm.reversal_description"
                                :placeholder="isRtl ? 'وصف العكس (اختياري)' : 'Reversal description…'">
                        </div>
                        <div style="background:var(--cv-info-bg);border:1px solid var(--cv-info-border);border-radius:var(--cv-radius-sm);padding:0.75rem;font-size:0.8125rem;color:var(--cv-blue-text);">
                            {{ t('revNote') }}
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closeRev">{{ t('cancel') }}</button>
                        <button class="cv-btn cv-btn-danger" @click="confirmRev" :disabled="!revForm.reversal_date">{{ t('revConfirm') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══════════════════════════════════════════════════════════
             DELETE CONFIRMATION MODAL
        ══════════════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="delModal" class="cv-modal-backdrop" @click.self="closeDel">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <span style="font-weight:600;color:var(--cv-danger-text);">{{ t('delTitle') }}</span>
                        <button class="cv-action-btn" @click="closeDel">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="cv-modal-body" style="text-align:center;padding:2rem 1.5rem;">
                        <div style="width:52px;height:52px;background:var(--cv-danger-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>
                            </svg>
                        </div>
                        <div style="font-size:0.9375rem;font-weight:600;color:var(--cv-text-primary);margin-bottom:0.5rem;">{{ delTarget?.reference ?? `#${delTarget?.id}` }}</div>
                        <div style="font-size:0.8125rem;color:var(--cv-text-muted);">{{ t('delNote') }}</div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closeDel">{{ t('cancel') }}</button>
                        <button class="cv-btn cv-btn-danger" @click="confirmDel">{{ t('delConfirm') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>
