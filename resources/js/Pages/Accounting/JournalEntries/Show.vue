<script setup>
/**
 * Accounting/JournalEntries/Show.vue
 * ─────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Journal Entry Detail View
 *
 * Props (from JournalEntryController::show()):
 *   entry — full entry with lines array (formatEntryFull())
 *
 * Bilingual rule:
 *   All UI strings via t() helper.
 *   Account names via n() helper (name_en / name_ar).
 *   Reversal modal fully bilingual.
 *
 * Displays:
 *   - Full header: reference, date, FY, type, status, description
 *   - Lines table: account code + bilingual name · Dr · Cr · memo · farm/lot
 *   - Balance footer: Σ Dr, Σ Cr, balance check badge
 *   - Actions: Edit (draft), Post (draft), Reverse (posted)
 *   - Reversal modal for posted entries
 *
 * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation
 *   |Σ debit − Σ credit| ≤ 0.005 — shown in balance footer.
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'

const { locale: lang, isRtl, n } = useLocale()

// ── Bilingual UI strings ──────────────────────────────────────────
const ui = {
    eyebrow:        { en: 'Accounting',              ar: 'المحاسبة' },
    title:          { en: 'Journal Entry',           ar: 'القيد اليومي' },
    back:           { en: 'Back to entries',         ar: 'العودة للقيود' },

    // Header labels
    labelRef:       { en: 'Reference',              ar: 'المرجع' },
    labelDate:      { en: 'Date',                   ar: 'التاريخ' },
    labelFy:        { en: 'Fiscal Year',             ar: 'السنة المالية' },
    labelType:      { en: 'Entry Type',              ar: 'نوع القيد' },
    labelStatus:    { en: 'Status',                  ar: 'الحالة' },
    labelDesc:      { en: 'Description',             ar: 'الوصف' },
    labelPostedBy:  { en: 'Posted by',               ar: 'رحّله' },
    labelPostedAt:  { en: 'Posted at',               ar: 'وقت الترحيل' },
    labelNotes:     { en: 'Notes',                   ar: 'ملاحظات' },
    labelSource:    { en: 'Source',                  ar: 'المصدر' },
    labelReversal:  { en: 'Reversal of',             ar: 'عكس قيد' },

    // Lines table
    linesTitle:     { en: 'Journal Lines',           ar: 'سطور القيد' },
    colAccount:     { en: 'Account',                 ar: 'الحساب' },
    colDebit:       { en: 'Debit (Dr)',              ar: 'مدين (Dr)' },
    colCredit:      { en: 'Credit (Cr)',             ar: 'دائن (Cr)' },
    colMemo:        { en: 'Memo',                    ar: 'ملاحظة' },
    colCostCenter:  { en: 'Cost Centre',             ar: 'مركز التكلفة' },
    totalDebit:     { en: 'Total Debit',             ar: 'إجمالي المدين' },
    totalCredit:    { en: 'Total Credit',            ar: 'إجمالي الدائن' },
    difference:     { en: 'Difference',              ar: 'الفرق' },
    balanced:       { en: 'Balanced',                ar: 'متوازن' },
    imbalanced:     { en: 'Imbalanced',              ar: 'غير متوازن' },

    // Status / type labels
    statusDraft:    { en: 'Draft',                   ar: 'مسودة' },
    statusPosted:   { en: 'Posted',                  ar: 'مرحلة' },
    statusReversed: { en: 'Reversed',                ar: 'معكوس' },
    typeManual:     { en: 'Manual',                  ar: 'يدوي' },
    typeOpening:    { en: 'Opening Balance',         ar: 'رصيد افتتاحي' },
    typeProcurement:{ en: 'Procurement',             ar: 'مشتريات' },
    typeSale:       { en: 'Sale',                    ar: 'مبيعات' },
    typeFeed:       { en: 'Feed Consumption',        ar: 'استهلاك علف' },
    typeHealth:     { en: 'Health',                  ar: 'صحة' },
    typeDepreciation:{ en: 'Depreciation',           ar: 'إهلاك' },
    typePayment:    { en: 'Payment',                 ar: 'دفعة' },
    typeReversal:   { en: 'Reversal',               ar: 'قيد عكسي' },

    // Actions
    edit:           { en: 'Edit Entry',              ar: 'تعديل القيد' },
    post:           { en: 'Post Entry',              ar: 'ترحيل القيد' },
    reverse:        { en: 'Reverse Entry',           ar: 'عكس القيد' },
    cancel:         { en: 'Cancel',                  ar: 'إلغاء' },

    // Auto-entry banner
    autoTitle:      { en: 'Auto-Generated Entry',    ar: 'قيد تلقائي' },
    autoBody:       { en: 'This entry was created automatically by the system. It cannot be edited manually.', ar: 'هذا القيد أنشأه النظام تلقائياً ولا يمكن تعديله يدوياً.' },

    // Post modal
    postTitle:      { en: 'Confirm Post',            ar: 'تأكيد الترحيل' },
    postBody:       { en: 'Once posted, this entry cannot be edited — only reversed.', ar: 'بعد الترحيل لا يمكن تعديل القيد — يمكن عكسه فقط.' },
    postConfirm:    { en: 'Post Entry',              ar: 'ترحيل القيد' },

    // Reverse modal
    revTitle:       { en: 'Reverse Journal Entry',   ar: 'عكس القيد اليومي' },
    revDate:        { en: 'Reversal Date',           ar: 'تاريخ العكس' },
    revDesc:        { en: 'Reversal Description (optional)', ar: 'وصف العكس (اختياري)' },
    revNote:        { en: 'A mirror entry will be created with all debits and credits swapped, and this entry will be marked as Reversed.', ar: 'سيتم إنشاء قيد معكوس بتبديل المدين والدائن، وسيُعلَّم هذا القيد بأنه معكوس.' },
    revConfirm:     { en: 'Reverse Entry',           ar: 'عكس القيد' },

    // WIP Methodology reference note
    wipNote:        { en: 'This entry relates to Livestock WIP costing (Methodology #23 — LivestockWIPCosting)', ar: 'هذا القيد يتعلق بتكلفة المواشي قيد التشغيل (المنهجية #23 — LivestockWIPCosting)' },
}

const t = (key) => ui[key]?.[lang.value] ?? key

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    entry: { type: Object, required: true },
})

// ── Derived state ─────────────────────────────────────────────────
const isAutoEntry  = computed(() => !!props.entry.source_type && props.entry.entry_type !== 'manual' && props.entry.entry_type !== 'opening_balance')
const isDraft      = computed(() => props.entry.status === 'draft')
const isPosted     = computed(() => props.entry.status === 'posted')
const isReversed   = computed(() => props.entry.status === 'reversed')
const canEdit      = computed(() => isDraft.value && !isAutoEntry.value)
const canPost      = computed(() => isDraft.value)
const canReverse   = computed(() => isPosted.value)

const lines        = computed(() => props.entry.lines ?? [])
const totalDebit   = computed(() => lines.value.reduce((s, l) => s + (parseFloat(l.debit)  || 0), 0))
const totalCredit  = computed(() => lines.value.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0))
const difference   = computed(() => Math.abs(totalDebit.value - totalCredit.value))
const isBalanced   = computed(() => difference.value <= 0.005)

// ── Helpers ───────────────────────────────────────────────────────
const formatAmt = (v) => {
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

// ── Post modal ────────────────────────────────────────────────────
const postModal = ref(false)
const confirmPost = () => {
    router.patch(route('journal-entries.post', props.entry.id), {}, {
        onSuccess: () => postModal.value = false,
    })
}

// ── Reverse modal ─────────────────────────────────────────────────
const revModal  = ref(false)
const revForm   = ref({ reversal_date: '', reversal_description: '' })
const openRev   = () => {
    revForm.value = { reversal_date: localToday(), reversal_description: '' }
    revModal.value = true
}
const confirmRev = () => {
    router.post(route('journal-entries.reverse', props.entry.id), revForm.value, {
        onSuccess: () => revModal.value = false,
    })
}

// ── WIP entry types (Methodology #23 — LivestockWIPCosting) ──────
const wipTypes = ['procurement', 'sale', 'feed_consumption', 'health']
const isWipEntry = computed(() => wipTypes.includes(props.entry.entry_type))
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <span class="cv-header-breadcrumb-current">{{ entry.reference ?? `#${entry.id}` }}</span>
        </template>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ──────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t('eyebrow') }}</div>
                <div class="cv-page-hero-title" style="font-family:monospace;">{{ entry.reference ?? `#${entry.id}` }}</div>
                <div class="cv-page-hero-sub" style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <span :class="statusClass(entry.status)">{{ statusLabel(entry.status) }}</span>
                    <span :class="typeClass(entry.entry_type)">{{ typeLabel(entry.entry_type) }}</span>
                    <span v-if="isBalanced" class="cv-badge cv-badge-green">
                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        {{ t('balanced') }}
                    </span>
                    <span v-else class="cv-badge cv-badge-danger">{{ t('imbalanced') }}</span>
                </div>
                <div class="cv-page-hero-actions">
                    <Link :href="route('journal-entries.index')" class="cv-btn cv-btn-ghost">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        {{ t('back') }}
                    </Link>
                    <Link v-if="canEdit" :href="route('journal-entries.edit', entry.id)" class="cv-btn cv-btn-secondary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        {{ t('edit') }}
                    </Link>
                    <button v-if="canPost" class="cv-btn cv-btn-amber" @click="postModal = true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 2 11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                        {{ t('post') }}
                    </button>
                    <button v-if="canReverse" class="cv-btn cv-btn-ghost" @click="openRev">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/>
                        </svg>
                        {{ t('reverse') }}
                    </button>
                </div>
            </div>

            <!-- ── Auto-entry banner ──────────────────────────────── -->
            <div v-if="isAutoEntry" style="background:var(--cv-info-bg);border:1px solid var(--cv-info-border);border-radius:var(--cv-radius-md);padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:0.75rem;align-items:flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-blue-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>
                    <div style="font-size:0.875rem;font-weight:600;color:var(--cv-blue-text);">{{ t('autoTitle') }}</div>
                    <div style="font-size:0.8125rem;color:var(--cv-text-muted);margin-top:2px;">{{ t('autoBody') }}</div>
                </div>
            </div>

            <!-- ── WIP costing note (Methodology #23) ────────────── -->
            <div v-if="isWipEntry" style="background:var(--cv-green-dim);border:1px solid var(--cv-green-border);border-radius:var(--cv-radius-md);padding:0.75rem 1rem;margin-bottom:1.25rem;display:flex;gap:0.625rem;align-items:center;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--cv-green-bright)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                <span style="font-size:0.75rem;color:var(--cv-success-text);">{{ t('wipNote') }}</span>
            </div>

            <!-- ── Entry Header Card ──────────────────────────────── -->
            <div class="cv-card" style="margin-bottom:1.25rem;">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;">

                    <div>
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelRef') }}</div>
                        <div style="font-size:0.9375rem;font-weight:700;color:var(--cv-text-primary);font-family:monospace;">{{ entry.reference ?? `#${entry.id}` }}</div>
                    </div>

                    <div>
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelDate') }}</div>
                        <div style="font-size:0.875rem;color:var(--cv-text-primary);">{{ entry.date }}</div>
                    </div>

                    <div>
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelFy') }}</div>
                        <div style="font-size:0.875rem;color:var(--cv-text-primary);">{{ entry.fiscal_year?.year ?? '—' }}</div>
                    </div>

                    <div>
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelStatus') }}</div>
                        <span :class="statusClass(entry.status)">{{ statusLabel(entry.status) }}</span>
                    </div>

                    <div>
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelType') }}</div>
                        <span :class="typeClass(entry.entry_type)">{{ typeLabel(entry.entry_type) }}</span>
                    </div>

                    <div v-if="entry.poster">
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelPostedBy') }}</div>
                        <div style="font-size:0.875rem;color:var(--cv-text-primary);">{{ entry.poster.name }}</div>
                    </div>

                    <div v-if="entry.posted_at">
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelPostedAt') }}</div>
                        <div style="font-size:0.875rem;color:var(--cv-text-primary);">{{ entry.posted_at }}</div>
                    </div>

                    <div v-if="entry.description" style="grid-column:1/-1;">
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelDesc') }}</div>
                        <div style="font-size:0.875rem;color:var(--cv-text-secondary);">{{ entry.description }}</div>
                    </div>

                    <div v-if="entry.notes" style="grid-column:1/-1;">
                        <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">{{ t('labelNotes') }}</div>
                        <div style="font-size:0.875rem;color:var(--cv-text-muted);white-space:pre-wrap;">{{ entry.notes }}</div>
                    </div>
                </div>
            </div>

            <!-- ── Journal Lines Table ────────────────────────────── -->
            <div class="cv-card" style="margin-bottom:1.25rem;overflow-x:auto;">
                <div style="font-size:0.875rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:1rem;">{{ t('linesTitle') }}</div>

                <table style="width:100%;border-collapse:collapse;min-width:600px;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--cv-border-md);">
                            <th style="text-start:start;padding:0.5rem 0.625rem;font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">{{ t('colAccount') }}</th>
                            <th style="text-align:end;padding:0.5rem 0.625rem;font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-blue-text);">{{ t('colDebit') }}</th>
                            <th style="text-align:end;padding:0.5rem 0.625rem;font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-blue-text);">{{ t('colCredit') }}</th>
                            <th style="text-start:start;padding:0.5rem 0.625rem;font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">{{ t('colMemo') }}</th>
                            <th style="text-start:start;padding:0.5rem 0.625rem;font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">{{ t('colCostCenter') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, idx) in lines"
                            :key="idx"
                            style="border-bottom:1px solid var(--cv-border);transition:var(--cv-transition);"
                            :style="parseFloat(line.debit) > 0 ? 'background:transparent' : 'background:var(--cv-bg-card-alt)'"
                        >
                            <!-- Account -->
                            <td style="padding:0.625rem 0.625rem;">
                                <div style="display:flex;align-items:center;gap:0.5rem;">
                                    <span style="font-size:0.75rem;font-weight:700;color:var(--cv-blue-text);font-family:monospace;min-width:40px;">{{ line.account?.code }}</span>
                                    <span style="font-size:0.8125rem;color:var(--cv-text-primary);">{{ n(line.account) }}</span>
                                </div>
                            </td>

                            <!-- Debit -->
                            <td style="padding:0.625rem 0.625rem;text-align:end;">
                                <span v-if="parseFloat(line.debit) > 0" style="font-size:0.875rem;font-weight:700;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">
                                    {{ formatAmt(line.debit) }}
                                </span>
                                <span v-else style="color:var(--cv-text-muted);">—</span>
                            </td>

                            <!-- Credit -->
                            <td style="padding:0.625rem 0.625rem;text-align:end;">
                                <span v-if="parseFloat(line.credit) > 0" style="font-size:0.875rem;font-weight:700;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">
                                    {{ formatAmt(line.credit) }}
                                </span>
                                <span v-else style="color:var(--cv-text-muted);">—</span>
                            </td>

                            <!-- Memo -->
                            <td style="padding:0.625rem 0.625rem;">
                                <span style="font-size:0.8125rem;color:var(--cv-text-muted);">{{ line.memo ?? '—' }}</span>
                            </td>

                            <!-- Cost centre: farm / lot / animal -->
                            <td style="padding:0.625rem 0.625rem;">
                                <div style="display:flex;flex-direction:column;gap:2px;">
                                    <span v-if="line.farm" style="font-size:0.75rem;color:var(--cv-text-muted);">{{ n(line.farm) }}</span>
                                    <span v-if="line.lot" style="font-size:0.75rem;color:var(--cv-text-muted);">{{ line.lot.name }}</span>
                                    <span v-if="line.animal" style="font-size:0.75rem;color:var(--cv-text-muted);">{{ line.animal.tag }}</span>
                                    <span v-if="!line.farm && !line.lot && !line.animal" style="color:var(--cv-text-muted);">—</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <!-- Balance Footer -->
                    <tfoot>
                        <tr style="border-top:2px solid var(--cv-border-md);">
                            <td style="padding:0.75rem 0.625rem;font-size:0.75rem;font-weight:700;color:var(--cv-text-secondary);">
                                {{ lines.length }} {{ isRtl ? 'سطر' : 'lines' }}
                            </td>
                            <td style="padding:0.75rem 0.625rem;text-align:end;">
                                <div style="font-size:0.875rem;font-weight:800;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">{{ formatAmt(totalDebit) }}</div>
                                <div style="font-size:0.6rem;font-weight:600;color:var(--cv-text-muted);text-transform:uppercase;letter-spacing:0.08em;">{{ t('totalDebit') }}</div>
                            </td>
                            <td style="padding:0.75rem 0.625rem;text-align:end;">
                                <div style="font-size:0.875rem;font-weight:800;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">{{ formatAmt(totalCredit) }}</div>
                                <div style="font-size:0.6rem;font-weight:600;color:var(--cv-text-muted);text-transform:uppercase;letter-spacing:0.08em;">{{ t('totalCredit') }}</div>
                            </td>
                            <td colspan="2" style="padding:0.75rem 0.625rem;text-align:end;">
                                <span v-if="isBalanced" class="cv-badge cv-badge-green">
                                    <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                    {{ t('balanced') }}
                                </span>
                                <span v-else class="cv-badge cv-badge-danger">
                                    {{ t('imbalanced') }} (Δ {{ formatAmt(difference) }})
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

        </div><!-- /cv-page -->

        <!-- ══════════════════════════════════════════════════════════
             POST MODAL
        ══════════════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="postModal" class="cv-modal-backdrop" @click.self="postModal = false">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <span style="font-weight:600;color:var(--cv-text-primary);">{{ t('postTitle') }}</span>
                        <button class="cv-action-btn" @click="postModal = false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="cv-modal-body">
                        <div style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);padding:0.875rem;margin-bottom:1rem;">
                            <div style="font-family:monospace;font-weight:700;color:var(--cv-text-primary);">{{ entry.reference ?? `#${entry.id}` }}</div>
                            <div style="font-size:0.75rem;color:var(--cv-text-muted);margin-top:4px;">{{ entry.date }} · {{ formatAmt(entry.total_debit) }} EGP</div>
                        </div>
                        <div style="background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:var(--cv-radius-sm);padding:0.75rem;font-size:0.8125rem;color:var(--cv-amber-text);">
                            {{ t('postBody') }}
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="postModal = false">{{ t('cancel') }}</button>
                        <button class="cv-btn cv-btn-amber" @click="confirmPost">{{ t('postConfirm') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══════════════════════════════════════════════════════════
             REVERSE MODAL
        ══════════════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="revModal" class="cv-modal-backdrop" @click.self="revModal = false">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <span style="font-weight:600;color:var(--cv-text-primary);">{{ t('revTitle') }}</span>
                        <button class="cv-action-btn" @click="revModal = false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;">
                        <!-- Summary of entry being reversed -->
                        <div style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);padding:0.875rem;">
                            <div style="font-family:monospace;font-weight:700;color:var(--cv-text-primary);">{{ entry.reference ?? `#${entry.id}` }}</div>
                            <div style="font-size:0.75rem;color:var(--cv-text-muted);margin-top:4px;display:flex;gap:1rem;">
                                <span>{{ entry.date }}</span>
                                <span>{{ t('totalDebit') }}: {{ formatAmt(entry.total_debit) }} EGP</span>
                            </div>
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ t('revDate') }} <span style="color:var(--cv-danger-text);">*</span></label>
                            <input type="date" class="cv-input" v-model="revForm.reversal_date">
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ t('revDesc') }}</label>
                            <input type="text" class="cv-input" v-model="revForm.reversal_description" :placeholder="isRtl ? 'وصف العكس…' : 'Reversal note…'">
                        </div>
                        <div style="background:var(--cv-info-bg);border:1px solid var(--cv-info-border);border-radius:var(--cv-radius-sm);padding:0.75rem;font-size:0.8125rem;color:var(--cv-blue-text);">
                            {{ t('revNote') }}
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="revModal = false">{{ t('cancel') }}</button>
                        <button class="cv-btn cv-btn-danger" @click="confirmRev" :disabled="!revForm.reversal_date">{{ t('revConfirm') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>
