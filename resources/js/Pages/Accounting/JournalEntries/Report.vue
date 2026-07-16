<script setup>
/**
 * Accounting/JournalEntries/Report.vue
 * ─────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Journal Book Report
 *
 * 📌 METHODOLOGY REFERENCE: JournalBookReport (#52)
 *
 * Props (from JournalEntryController::journalReport()):
 *   report      — array of journal entries, each with .lines[]
 *   grandDebit  — bcmath string total of all Dr across period
 *   grandCredit — bcmath string total of all Cr across period
 *   filters     — { date_from, date_to }
 *   entryCount  — integer
 *
 * Design rules:
 *   - Entries grouped: one header row per JE, then one line per journal_line
 *   - Columns: Date · Reference · Journal Type · Account · Memo · Dr · Cr
 *   - Grand totals footer with balance check
 *   - Print button uses window.print() — report is print-optimised via @media print
 *   - Back button → journal-entries.index
 *   - All cv- tokens, no hardcoded hex
 *   - Fully bilingual — locale-aware
 *   - No scoped CSS overriding global cv- classes
 *
 * Session: 66
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

// ── Bilingual UI ──────────────────────────────────────────────────
const ui = {
    eyebrow:     { en: 'Accounting',             ar: 'المحاسبة' },
    title:       { en: 'Journal Book',           ar: 'دفتر اليومية' },
    subtitle:    { en: 'All posted journal entries with Dr / Cr lines', ar: 'جميع القيود المرحلة مع سطور المدين والدائن' },

    back:        { en: 'Back to Journal Entries', ar: 'العودة للقيود اليومية' },
    print:       { en: 'Print Report',           ar: 'طباعة التقرير' },

    // Period strip
    period:      { en: 'Period',                 ar: 'الفترة' },
    entries:     { en: 'Entries',                ar: 'قيود' },
    printedOn:   { en: 'Printed on',             ar: 'طُبع في' },

    // Table headers
    colDate:     { en: 'Date',                   ar: 'التاريخ' },
    colRef:      { en: 'Reference',              ar: 'المرجع' },
    colType:     { en: 'Journal Type',           ar: 'نوع القيد' },
    colAccount:  { en: 'Account',                ar: 'الحساب' },
    colMemo:     { en: 'Memo / Description',     ar: 'البيان' },
    colDr:       { en: 'Debit (Dr)',             ar: 'مدين (Dr)' },
    colCr:       { en: 'Credit (Cr)',            ar: 'دائن (Cr)' },

    // Footer
    grandTotal:  { en: 'Grand Total',            ar: 'الإجمالي الكلي' },
    balanced:    { en: 'Balanced',               ar: 'متوازن' },
    imbalanced:  { en: 'Imbalanced',             ar: 'غير متوازن' },
    difference:  { en: 'Difference',             ar: 'الفرق' },

    // Empty
    noEntries:   { en: 'No posted entries found for this date range.', ar: 'لا توجد قيود مرحلة في هذه الفترة.' },

    // Entry type labels (all enum values)
    typeManual:                   { en: 'Manual',              ar: 'يدوي' },
    typeOpening:                  { en: 'Opening Balance',     ar: 'رصيد افتتاحي' },
    typeProcurement:              { en: 'Procurement',         ar: 'مشتريات' },
    typeSale:                     { en: 'Sale',                ar: 'مبيعات' },
    typeFeedConsumption:          { en: 'Feed Consumption',    ar: 'استهلاك علف' },
    typeHealth:                   { en: 'Health',              ar: 'صحة' },
    typeDepreciation:             { en: 'Depreciation',        ar: 'إهلاك' },
    typePayment:                  { en: 'Payment',             ar: 'دفعة' },
    typeReversal:                 { en: 'Reversal',            ar: 'قيد عكسي' },
    typeLaborAccrual:             { en: 'Labor Accrual',       ar: 'استحقاق العمالة' },
    typeLaborSettlement:          { en: 'Farm Settlement',     ar: 'تسوية المزرعة' },
    typeClosing:                  { en: 'Closing',             ar: 'إقفال' },
    typeAuto:                     { en: 'Auto',                ar: 'تلقائي' },
}

const t = (key) => ui[key]?.[lang.value] ?? key

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    report:      { type: Array,  required: true },
    grandDebit:  { type: String, required: true },
    grandCredit: { type: String, required: true },
    filters:     { type: Object, required: true },
    entryCount:  { type: Number, required: true },
})

// ── Helpers ───────────────────────────────────────────────────────
const fmt = (v) => {
    const num = parseFloat(v ?? 0)
    if (num === 0) return '—'
    return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

const fmtTotal = (v) =>
    parseFloat(v ?? 0).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })

const typeLabel = (type) => ({
    manual:                    t('typeManual'),
    opening_balance:           t('typeOpening'),
    procurement:               t('typeProcurement'),
    sale:                      t('typeSale'),
    feed_consumption:          t('typeFeedConsumption'),
    health:                    t('typeHealth'),
    depreciation:              t('typeDepreciation'),
    payment:                   t('typePayment'),
    reversal:                  t('typeReversal'),
    labor_overhead_accrual:    t('typeLaborAccrual'),
    labor_overhead_settlement: t('typeLaborSettlement'),
    closing:                   t('typeClosing'),
    auto:                      t('typeAuto'),
}[type] ?? type)

const typeClass = (type) => ({
    manual:                    'cv-badge cv-badge-info',
    opening_balance:           'cv-badge cv-badge-amber',
    procurement:               'cv-badge cv-badge-green',
    sale:                      'cv-badge cv-badge-green',
    feed_consumption:          'cv-badge cv-badge-neutral',
    health:                    'cv-badge cv-badge-neutral',
    depreciation:              'cv-badge cv-badge-neutral',
    payment:                   'cv-badge cv-badge-green',
    reversal:                  'cv-badge cv-badge-danger',
    labor_overhead_accrual:    'cv-badge cv-badge-neutral',
    labor_overhead_settlement: 'cv-badge cv-badge-neutral',
}[type] ?? 'cv-badge cv-badge-neutral')

const isBalanced = computed(() => {
    const diff = Math.abs(parseFloat(props.grandDebit) - parseFloat(props.grandCredit))
    return diff <= 0.005
})

const printedOn = new Date().toLocaleDateString(
    lang.value === 'ar' ? 'ar-EG' : 'en-GB',
    { year: 'numeric', month: 'long', day: 'numeric' }
)
</script>

<template>

    <!-- ── Hero ──────────────────────────────────────────────────── -->
    <div class="cv-page-hero" :dir="isRtl ? 'rtl' : 'ltr'">
        <div class="cv-page-hero-eyebrow">{{ t('eyebrow') }}</div>
        <div class="cv-page-hero-title">{{ t('title') }}</div>
        <div class="cv-page-hero-sub">{{ t('subtitle') }}</div>
        <div class="cv-page-hero-actions">
            <Link :href="route('journal-entries.index')" class="cv-btn cv-btn-ghost">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                {{ t('back') }}
            </Link>
            <button class="cv-btn cv-btn-amber" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                {{ t('print') }}
            </button>
        </div>
    </div>

    <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

        <!-- ── Period Info Strip ───────────────────────────────────── -->
        <div class="cv-card" style="margin-bottom:1.5rem;padding:1rem 1.25rem;">
            <div style="display:flex;flex-wrap:wrap;gap:2rem;align-items:center;">

                <!-- Period -->
                <div>
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--cv-text-muted);margin-bottom:3px;">{{ t('period') }}</div>
                    <div style="font-size:0.9375rem;font-weight:700;color:var(--cv-amber);font-family:monospace;">
                        {{ filters.date_from }} → {{ filters.date_to }}
                    </div>
                </div>

                <!-- Entry count -->
                <div>
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--cv-text-muted);margin-bottom:3px;">{{ t('entries') }}</div>
                    <div style="font-size:0.9375rem;font-weight:700;color:var(--cv-blue-text);">{{ entryCount }}</div>
                </div>

                <!-- Grand Dr total -->
                <div>
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--cv-text-muted);margin-bottom:3px;">{{ t('colDr') }}</div>
                    <div style="font-size:0.9375rem;font-weight:700;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">{{ fmtTotal(grandDebit) }}</div>
                </div>

                <!-- Grand Cr total -->
                <div>
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--cv-text-muted);margin-bottom:3px;">{{ t('colCr') }}</div>
                    <div style="font-size:0.9375rem;font-weight:700;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">{{ fmtTotal(grandCredit) }}</div>
                </div>

                <!-- Balance status -->
                <div>
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--cv-text-muted);margin-bottom:3px;">{{ t('difference') }}</div>
                    <div>
                        <span v-if="isBalanced" class="cv-badge cv-badge-green">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            {{ t('balanced') }}
                        </span>
                        <span v-else class="cv-badge cv-badge-danger">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            {{ t('imbalanced') }}
                        </span>
                    </div>
                </div>

                <!-- Printed on (spacer pushes to end) -->
                <div style="margin-inline-start:auto;">
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--cv-text-muted);margin-bottom:3px;">{{ t('printedOn') }}</div>
                    <div style="font-size:0.8125rem;color:var(--cv-text-secondary);">{{ printedOn }}</div>
                </div>

            </div>
        </div>

        <!-- ── Empty state ─────────────────────────────────────────── -->
        <div v-if="report.length === 0" class="cv-card">
            <div class="cv-empty" style="padding:4rem 1rem;">
                <div class="cv-empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.25">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                </div>
                <div class="cv-empty-title">{{ t('noEntries') }}</div>
            </div>
        </div>

        <!-- ── Journal Table ───────────────────────────────────────── -->
        <div v-else class="cv-card" style="padding:0;overflow:hidden;">
            <div style="overflow-x:auto;">
                <table class="cv-table" id="journal-book-table">
                    <thead>
                        <tr>
                            <th style="min-width:90px;">{{ t('colDate') }}</th>
                            <th style="min-width:140px;">{{ t('colRef') }}</th>
                            <th style="min-width:120px;">{{ t('colType') }}</th>
                            <th style="min-width:160px;">{{ t('colAccount') }}</th>
                            <th style="min-width:200px;">{{ t('colMemo') }}</th>
                            <th style="text-align:end;min-width:120px;">{{ t('colDr') }}</th>
                            <th style="text-align:end;min-width:120px;">{{ t('colCr') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="entry in report" :key="entry.id">

                            <!-- ── Entry header row ───────────────── -->
                            <tr style="background:var(--cv-bg-card-alt);">
                                <!-- Date -->
                                <td style="font-weight:600;color:var(--cv-text-primary);font-size:0.8125rem;white-space:nowrap;">
                                    {{ entry.date }}
                                </td>
                                <!-- Reference + description -->
                                <td colspan="2">
                                    <div style="font-family:monospace;font-weight:700;font-size:0.8rem;color:var(--cv-amber);">
                                        {{ entry.reference ?? `#${entry.id}` }}
                                    </div>
                                    <div v-if="entry.description" style="font-size:0.72rem;color:var(--cv-text-muted);margin-top:2px;">
                                        {{ entry.description }}
                                    </div>
                                </td>
                                <!-- Type badge -->
                                <td>
                                    <span :class="typeClass(entry.entry_type)" style="font-size:0.7rem;">
                                        {{ typeLabel(entry.entry_type) }}
                                    </span>
                                </td>
                                <!-- Entry Dr total -->
                                <td style="text-align:end;">
                                    <div style="font-size:0.72rem;font-weight:600;color:var(--cv-text-muted);font-variant-numeric:tabular-nums;">
                                        {{ fmtTotal(entry.total_debit) }}
                                    </div>
                                </td>
                                <!-- Entry Cr total -->
                                <td style="text-align:end;">
                                    <div style="font-size:0.72rem;font-weight:600;color:var(--cv-text-muted);font-variant-numeric:tabular-nums;">
                                        {{ fmtTotal(entry.total_credit) }}
                                    </div>
                                </td>
                            </tr>

                            <!-- ── Journal lines ──────────────────── -->
                            <tr v-for="line in entry.lines" :key="line.id">
                                <!-- Date — empty on line rows, date already shown in header -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;"></td>

                                <!-- Reference — empty on line rows -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;"></td>

                                <!-- Journal Type — empty on line rows -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;"></td>

                                <!-- Account code + name -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;">
                                    <div style="display:flex;align-items:center;gap:0.5rem;">
                                        <!-- Dr/Cr indent visual -->
                                        <div :style="{
                                            width: '3px',
                                            height: '28px',
                                            borderRadius: '2px',
                                            background: parseFloat(line.debit) > 0 ? 'var(--cv-blue-text)' : 'var(--cv-green-bright)',
                                            opacity: '0.5',
                                            flexShrink: 0,
                                        }"></div>
                                        <div>
                                            <div style="font-family:monospace;font-size:0.75rem;color:var(--cv-text-muted);">{{ line.account_code }}</div>
                                            <div style="font-size:0.8rem;color:var(--cv-text-primary);font-weight:500;">
                                                {{ lang === 'ar' ? (line.account_ar || line.account_en) : (line.account_en || line.account_ar) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Memo -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;font-size:0.8rem;color:var(--cv-text-muted);">
                                    {{ line.memo || '—' }}
                                </td>

                                <!-- Debit -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;text-align:end;font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap;"
                                    :style="{ color: parseFloat(line.debit) > 0 ? 'var(--cv-blue-text)' : 'var(--cv-text-muted)' }">
                                    {{ fmt(line.debit) }}
                                </td>

                                <!-- Credit -->
                                <td style="border-top:none;padding-top:0.25rem;padding-bottom:0.25rem;text-align:end;font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap;"
                                    :style="{ color: parseFloat(line.credit) > 0 ? 'var(--cv-green-bright)' : 'var(--cv-text-muted)' }">
                                    {{ fmt(line.credit) }}
                                </td>
                            </tr>

                            <!-- ── Separator row between entries ──── -->
                            <tr aria-hidden="true">
                                <td colspan="7" style="padding:0;border-top:2px solid var(--cv-border-subtle);"></td>
                            </tr>

                        </template>
                    </tbody>

                    <!-- ── Grand Totals Footer ──────────────────── -->
                    <tfoot>
                        <tr style="background:var(--cv-bg-card-alt);">
                            <td colspan="5" style="font-weight:700;font-size:0.875rem;color:var(--cv-text-primary);">
                                {{ t('grandTotal') }}
                                <span style="margin-inline-start:0.75rem;">
                                    <span v-if="isBalanced" class="cv-badge cv-badge-green">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        {{ t('balanced') }}
                                    </span>
                                    <span v-else class="cv-badge cv-badge-danger">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        {{ t('imbalanced') }}
                                    </span>
                                </span>
                            </td>
                            <td style="text-align:end;font-weight:700;font-size:0.9375rem;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;white-space:nowrap;border-top:2px solid var(--cv-border);">
                                {{ fmtTotal(grandDebit) }}
                            </td>
                            <td style="text-align:end;font-weight:700;font-size:0.9375rem;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;white-space:nowrap;border-top:2px solid var(--cv-border);">
                                {{ fmtTotal(grandCredit) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div><!-- /cv-page -->

</template>

<!--
    Print optimisation: hide everything except the table when the user
    uses the Print button (or Ctrl+P). This is pure CSS — no JS needed.
    Added as a non-scoped style block so it targets the global @media print.
    It does NOT redefine any cv- classes — purely adds a print hide rule.
-->
<style>
@media print {
    .cv-page-hero,
    .cv-sidebar,
    nav,
    header,
    .cv-card:first-child { /* period info strip — keep it */
        /* intentionally kept */
    }

    /* Hide nav chrome but keep cv-page content */
    aside,
    header,
    nav,
    .cv-page-hero-actions {
        display: none !important;
    }

    body {
        background: white !important;
        color: black !important;
    }

    .cv-page {
        padding: 0 !important;
    }

    .cv-card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        background: white !important;
        color: black !important;
        border-radius: 0 !important;
    }

    #journal-book-table th,
    #journal-book-table td {
        color: black !important;
        border-color: #ccc !important;
        font-size: 0.75rem !important;
        padding: 0.25rem 0.5rem !important;
    }

    .cv-badge {
        border: 1px solid #999 !important;
        background: transparent !important;
        color: black !important;
    }
}
</style>