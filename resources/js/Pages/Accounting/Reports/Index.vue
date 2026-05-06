<script setup>
// 📌 METHODOLOGY REFERENCES:
//   #5  TrialBalance          — FinancialReportService::trialBalance()
//   #6  ProfitAndLoss         — FinancialReportService::profitAndLoss()
//   #7  BalanceSheet          — FinancialReportService::balanceSheet()
//   #8  CashFlow              — FinancialReportService::cashFlow()
//   #9  ADG_FCR               — LivestockIntelligenceService::adgFcrForLot()
//   #10 WAC                   — LivestockIntelligenceService::currentWac()
//   #11 GrossMarginBreakEven  — LivestockIntelligenceService::costingForLot()

import { ref, computed, watch, onMounted } from 'vue'
import { router, usePage }                  from '@inertiajs/vue3'
import AuthenticatedLayout                  from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale }                        from '@/Composables/useLocale'
import { usePermissions }                   from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

// ── Auth & permissions ────────────────────────────────────────────
const { isSuperAdmin, can } = usePermissions()
const canView = computed(() => isSuperAdmin.value || can('view reports'))

// ── Locale ────────────────────────────────────────────────────────
const { locale, isRtl, n } = useLocale()
const lang = locale   // alias for brevity

// ── Bilingual UI strings ──────────────────────────────────────────
const ui = computed(() => {
    const ar = lang.value === 'ar'
    return {
        // Page
        eyebrow:          ar ? 'التقارير المالية'             : 'Financial Reports',
        title:            ar ? 'التقارير'                     : 'Reports',
        sub:              ar ? 'القوائم المالية والتحليلات التشغيلية' : 'Financial statements & operational analytics',
        print:            ar ? 'طباعة'                        : 'Print',
        run:              ar ? 'تشغيل التقرير'                : 'Run Report',
        noData:           ar ? 'لا توجد بيانات للفترة المحددة' : 'No data for the selected period',
        selectCompany:    ar ? 'اختر الشركة'                  : 'Select Company',
        company:          ar ? 'الشركة'                       : 'Company',
        // Tabs
        tabTb:            ar ? 'ميزان المراجعة'               : 'Trial Balance',
        tabPl:            ar ? 'الأرباح والخسائر'             : 'Profit & Loss',
        tabBs:            ar ? 'الميزانية العمومية'           : 'Balance Sheet',
        tabCf:            ar ? 'التدفق النقدي'                : 'Cash Flow',
        tabGm:            ar ? 'هامش الربح'                   : 'Gross Margin',
        tabAdg:           ar ? 'معدل النمو / معامل التحويل'   : 'ADG / FCR',
        // Filters
        dateFrom:         ar ? 'من تاريخ'                     : 'Date From',
        dateTo:           ar ? 'إلى تاريخ'                    : 'Date To',
        asOf:             ar ? 'بتاريخ'                       : 'As of Date',
        periodStart:      ar ? 'بداية الفترة'                 : 'Period Start',
        periodEnd:        ar ? 'نهاية الفترة'                 : 'Period End',
        fyId:             ar ? 'السنة المالية'                : 'Fiscal Year',
        compareFrom:      ar ? 'مقارنة من'                    : 'Compare From',
        compareTo:        ar ? 'مقارنة إلى'                   : 'Compare To',
        farm:             ar ? 'المزرعة'                      : 'Farm',
        lot:              ar ? 'المجموعة'                     : 'Lot',
        allFarms:         ar ? 'كل المزارع'                   : 'All Farms',
        allLots:          ar ? 'كل المجموعات'                 : 'All Lots',
        // Trial Balance
        account:          ar ? 'الحساب'                       : 'Account',
        code:             ar ? 'الكود'                        : 'Code',
        opening:          ar ? 'الرصيد الافتتاحي'             : 'Opening',
        periodDr:         ar ? 'مدين الفترة'                  : 'Period Dr',
        periodCr:         ar ? 'دائن الفترة'                  : 'Period Cr',
        closing:          ar ? 'الرصيد الختامي'               : 'Closing',
        balanced:         ar ? 'متوازن ✓'                     : 'Balanced ✓',
        imbalanced:       ar ? 'غير متوازن ✗'                 : 'Imbalanced ✗',
        totals:           ar ? 'الإجماليات'                   : 'Totals',
        // P&L
        revenue:          ar ? 'الإيرادات'                    : 'Revenue',
        cogs:             ar ? 'تكلفة المبيعات'               : 'Cost of Goods Sold',
        grossProfit:      ar ? 'مجمل الربح'                   : 'Gross Profit',
        grossMarginPct:   ar ? 'نسبة هامش الربح الإجمالي'     : 'Gross Margin %',
        expenses:         ar ? 'المصروفات'                    : 'Expenses',
        netIncome:        ar ? 'صافي الدخل'                   : 'Net Income',
        currentPeriod:    ar ? 'الفترة الحالية'               : 'Current Period',
        comparison:       ar ? 'فترة المقارنة'                : 'Comparison',
        variance:         ar ? 'التغير'                       : 'Variance',
        // Balance Sheet
        assets:           ar ? 'الأصول'                       : 'Assets',
        liabilities:      ar ? 'الالتزامات'                   : 'Liabilities',
        equity:           ar ? 'حقوق الملكية'                 : 'Equity',
        totalAssets:      ar ? 'إجمالي الأصول'                : 'Total Assets',
        totalLiab:        ar ? 'إجمالي الالتزامات'            : 'Total Liabilities',
        totalEquity:      ar ? 'إجمالي حقوق الملكية'          : 'Total Equity',
        liabPlusEq:       ar ? 'الالتزامات + حقوق الملكية'    : 'Liabilities + Equity',
        assertion:        ar ? 'الأصول = الالتزامات + حقوق الملكية' : 'Assets = Liabilities + Equity',
        // Cash Flow
        operating:        ar ? 'التدفق التشغيلي'              : 'Operating Activities',
        investing:        ar ? 'التدفق الاستثماري'            : 'Investing Activities',
        financing:        ar ? 'التدفق التمويلي'              : 'Financing Activities',
        netChange:        ar ? 'صافي التغير في النقدية'        : 'Net Change in Cash',
        openingCash:      ar ? 'النقدية الافتتاحية'           : 'Opening Cash',
        closingCash:      ar ? 'النقدية الختامية'             : 'Closing Cash',
        netIncomeItem:    ar ? 'صافي الدخل'                   : 'Net Income',
        depreciationItem: ar ? 'الإهلاك'                      : 'Depreciation',
        deltaAr:          ar ? 'تغير الذمم المدينة'            : 'Δ Receivables',
        deltaAp:          ar ? 'تغير الذمم الدائنة'            : 'Δ Payables',
        deltaInv:         ar ? 'تغير المخزون'                 : 'Δ Inventory',
        faMovement:       ar ? 'حركة الأصول الثابتة'          : 'Fixed Asset Movement',
        loanEqMovement:   ar ? 'حركة القروض وحقوق الملكية'    : 'Loan & Equity Movement',
        // Gross Margin
        headCount:        ar ? 'عدد الرؤوس'                   : 'Head Count',
        totalRevenue:     ar ? 'إجمالي الإيرادات'             : 'Total Revenue',
        totalCogs:        ar ? 'إجمالي التكلفة'               : 'Total COGS',
        grossMargin:      ar ? 'هامش الربح الإجمالي'          : 'Gross Margin',
        roi:              ar ? 'العائد على الاستثمار'          : 'ROI %',
        avgAdg:           ar ? 'متوسط النمو اليومي'            : 'Avg ADG',
        avgFcr:           ar ? 'متوسط معامل التحويل'          : 'Avg FCR',
        costBreakdown:    ar ? 'تفاصيل التكلفة'               : 'Cost Breakdown',
        purchase:         ar ? 'شراء'                         : 'Purchase',
        feed:             ar ? 'علف'                          : 'Feed',
        veterinary:       ar ? 'بيطري'                        : 'Veterinary',
        vaccination:      ar ? 'تطعيم'                        : 'Vaccination',
        slaughter:        ar ? 'ذبح'                          : 'Slaughter',
        overhead:         ar ? 'مصاريف عامة'                  : 'Overhead',
        // ADG/FCR
        weightInitial:    ar ? 'الوزن الابتدائي (كغ)'         : 'Initial Weight (kg)',
        weightFinal:      ar ? 'الوزن النهائي (كغ)'           : 'Final Weight (kg)',
        weightGain:       ar ? 'الزيادة في الوزن (كغ)'        : 'Weight Gain (kg)',
        daysOnFeed:       ar ? 'أيام التغذية'                 : 'Days on Feed',
        adgKgDay:         ar ? 'النمو اليومي (كغ/يوم)'        : 'ADG (kg/day)',
        feedDmTotal:      ar ? 'إجمالي المادة الجافة (كغ)'    : 'Total Feed DM (kg)',
        fcrLabel:         ar ? 'معامل التحويل الغذائي'        : 'FCR',
        noWeightData:     ar ? 'لا توجد بيانات وزن كافية'     : 'Insufficient weight data',
        // misc
        egp:              ar ? 'ج.م'                          : 'EGP',
        na:               ar ? 'غ.م'                          : 'N/A',
        lotLabel:         ar ? 'مجموعة'                       : 'Lot',
        rank:             ar ? 'الترتيب'                      : 'Rank',
    }
})

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    // Active report data — only one will be populated per page load
    report:       { type: [Object, Array], default: null },
    filters:      { type: Object, default: () => ({}) },
    // Meta
    companies:    { type: Array, default: () => [] },   // SA only
    fiscalYears:  { type: Array, default: () => [] },
    farms:        { type: Array, default: () => [] },
    lots:         { type: Array, default: () => [] },
    // Which tab is active — sent by controller
    activeTab:    { type: String, default: 'trial-balance' },
})

// ── Page & SA company ─────────────────────────────────────────────
const page = usePage()
const authUser = computed(() => page.props.auth?.user)

// SA: company_id resolved from filter or first company
const selectedCompanyId = ref(
    props.filters?.company_id
        ? String(props.filters.company_id)
        : (authUser.value?.company_id ? String(authUser.value.company_id) : '')
)

// ── Active tab ────────────────────────────────────────────────────
const activeTab = ref(props.activeTab || 'trial-balance')

// ── Filter state — initialise from props.filters ──────────────────
const today = new Date().toISOString().slice(0, 10)
const firstOfMonth = today.slice(0, 8) + '01'
const firstOfYear  = today.slice(0, 4) + '-01-01'

// Trial Balance
const tbStart = ref(props.filters?.period_start || firstOfYear)
const tbEnd   = ref(props.filters?.period_end   || today)

// P&L
const plFrom       = ref(props.filters?.date_from    || firstOfYear)
const plTo         = ref(props.filters?.date_to      || today)
const plCmpFrom    = ref(props.filters?.compare_from || '')
const plCmpTo      = ref(props.filters?.compare_to   || '')
const plShowCompare= ref(!!(props.filters?.compare_from))

// Balance Sheet
const bsAsOf = ref(props.filters?.as_of_date     || today)
const bsFyId  = ref(props.filters?.fiscal_year_id || '')

// Cash Flow
const cfFrom = ref(props.filters?.date_from || firstOfYear)
const cfTo   = ref(props.filters?.date_to   || today)

// Gross Margin
const gmFarm = ref(props.filters?.farm_id   || '')
const gmFrom = ref(props.filters?.date_from || firstOfYear)
const gmTo   = ref(props.filters?.date_to   || today)

// ADG/FCR
const adgLot = ref(props.filters?.lot_id || '')

// ── Tab definitions ───────────────────────────────────────────────
const tabs = computed(() => [
    { key: 'trial-balance',  label: ui.value.tabTb,  route: 'reports.trial-balance'  },
    { key: 'profit-loss',    label: ui.value.tabPl,  route: 'reports.profit-loss'    },
    { key: 'balance-sheet',  label: ui.value.tabBs,  route: 'reports.balance-sheet'  },
    { key: 'cash-flow',      label: ui.value.tabCf,  route: 'reports.cash-flow'      },
    { key: 'gross-margin',   label: ui.value.tabGm,  route: 'reports.gross-margin'   },
    { key: 'adg-fcr',        label: ui.value.tabAdg, route: 'reports.adg-fcr'        },
])

// ── Run report ────────────────────────────────────────────────────
function runReport() {
    const params = { company_id: selectedCompanyId.value || undefined }

    switch (activeTab.value) {
        case 'trial-balance':
            params.period_start = tbStart.value
            params.period_end   = tbEnd.value
            router.get(route('reports.trial-balance'), params)
            break
        case 'profit-loss':
            params.date_from    = plFrom.value
            params.date_to      = plTo.value
            if (plShowCompare.value && plCmpFrom.value && plCmpTo.value) {
                params.compare_from = plCmpFrom.value
                params.compare_to   = plCmpTo.value
            }
            router.get(route('reports.profit-loss'), params)
            break
        case 'balance-sheet':
            params.as_of_date     = bsAsOf.value
            params.fiscal_year_id = bsFyId.value || undefined
            router.get(route('reports.balance-sheet'), params)
            break
        case 'cash-flow':
            params.date_from = cfFrom.value
            params.date_to   = cfTo.value
            router.get(route('reports.cash-flow'), params)
            break
        case 'gross-margin':
            params.farm_id   = gmFarm.value || undefined
            params.date_from = gmFrom.value || undefined
            params.date_to   = gmTo.value   || undefined
            router.get(route('reports.gross-margin'), params)
            break
        case 'adg-fcr':
            params.lot_id = adgLot.value || undefined
            router.get(route('reports.adg-fcr'), params)
            break
    }
}

function switchTab(key) {
    activeTab.value = key
    // Don't auto-run — user picks filters then hits Run
}

// ── Formatting helpers ────────────────────────────────────────────
function fmt(val, decimals = 0) {
    if (val === null || val === undefined) return ui.value.na
    const num = parseFloat(val)
    if (isNaN(num)) return ui.value.na
    return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}

function fmtAmt(val) { return fmt(val, 2) }

function amtClass(val) {
    const num = parseFloat(val)
    if (isNaN(num) || num === 0) return ''
    return num < 0 ? 'neg' : ''
}

function varianceClass(val, type = 'expense') {
    // Revenue: positive variance = good (green). Expense/COGS: negative = good (green)
    const num = parseFloat(val)
    if (isNaN(num) || num === 0) return ''
    if (type === 'revenue') return num > 0 ? 'pos' : 'neg'
    return num < 0 ? 'pos' : 'neg'
}

// ── Computed report data ──────────────────────────────────────────
const report = computed(() => props.report)

// P&L convenience
const plCurrent = computed(() => report.value?.current  || null)
const plCompare = computed(() => report.value?.compare   || null)

// Balance sheet totals check
const bsBalanced   = computed(() => report.value?.balanced ?? null)
const bsImbalance  = computed(() => report.value?.imbalance ?? 0)

// Trial balance balanced check
const tbBalanced   = computed(() => report.value?.balanced ?? null)

// Gross margin: array of lot costing objects
const gmRows = computed(() => {
    if (!report.value) return []
    return Array.isArray(report.value) ? report.value : []
})

// ADG/FCR: single lot or array
const adgData = computed(() => {
    if (!report.value) return null
    if (Array.isArray(report.value)) return report.value
    return [report.value]
})

function printPage() { window.print() }
</script>

<template>
   
        <div class="reports-root" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ──────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ ui.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ ui.title }}</div>
                <div class="cv-page-hero-sub">{{ ui.sub }}</div>
                <div class="cv-page-hero-actions">
                    <button class="cv-btn cv-btn-ghost print-hide" @click="printPage">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7">
                            <polyline points="6 9 6 2 18 2 18 9"/>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                            <rect x="6" y="14" width="12" height="8"/>
                        </svg>
                        {{ ui.print }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- ── SA Company selector ──────────────────────── -->
                <div v-if="isSuperAdmin && companies.length" class="sa-company-bar print-hide">
                    <span class="sa-label">{{ ui.company }}</span>
                    <select v-model="selectedCompanyId" class="cv-select sa-select">
                        <option value="">{{ ui.selectCompany }}</option>
                        <option v-for="c in companies" :key="c.id" :value="String(c.id)">
                            {{ n(c) }}
                        </option>
                    </select>
                </div>

                <!-- ── Tab bar ───────────────────────────────────── -->
                <div class="report-tabs print-hide">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        class="report-tab-btn"
                        :class="{ active: activeTab === tab.key }"
                        @click="switchTab(tab.key)"
                    >{{ tab.label }}</button>
                </div>

                <!-- ═══════════════════════════════════════════════ -->
                <!-- FILTER PANELS (print-hide) -->
                <!-- ═══════════════════════════════════════════════ -->

                <!-- Trial Balance filters -->
                <div v-if="activeTab === 'trial-balance'" class="filter-panel print-hide">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.periodStart }}</label>
                            <input type="date" v-model="tbStart" class="cv-input" />
                        </div>
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.periodEnd }}</label>
                            <input type="date" v-model="tbEnd" class="cv-input" />
                        </div>
                    </div>
                    <button class="cv-btn cv-btn-amber" @click="runReport">{{ ui.run }}</button>
                </div>

                <!-- P&L filters -->
                <div v-if="activeTab === 'profit-loss'" class="filter-panel print-hide">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.dateFrom }}</label>
                            <input type="date" v-model="plFrom" class="cv-input" />
                        </div>
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.dateTo }}</label>
                            <input type="date" v-model="plTo" class="cv-input" />
                        </div>
                        <div class="filter-field filter-field-full">
                            <label class="compare-toggle">
                                <input type="checkbox" v-model="plShowCompare" />
                                <span>{{ lang === 'ar' ? 'إضافة فترة مقارنة' : 'Add Comparison Period' }}</span>
                            </label>
                        </div>
                        <template v-if="plShowCompare">
                            <div class="filter-field">
                                <label class="cv-label">{{ ui.compareFrom }}</label>
                                <input type="date" v-model="plCmpFrom" class="cv-input" />
                            </div>
                            <div class="filter-field">
                                <label class="cv-label">{{ ui.compareTo }}</label>
                                <input type="date" v-model="plCmpTo" class="cv-input" />
                            </div>
                        </template>
                    </div>
                    <button class="cv-btn cv-btn-amber" @click="runReport">{{ ui.run }}</button>
                </div>

                <!-- Balance Sheet filters -->
                <div v-if="activeTab === 'balance-sheet'" class="filter-panel print-hide">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.asOf }}</label>
                            <input type="date" v-model="bsAsOf" class="cv-input" />
                        </div>
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.fyId }}</label>
                            <select v-model="bsFyId" class="cv-select">
                                <option value="">—</option>
                                <option v-for="fy in fiscalYears" :key="fy.id" :value="String(fy.id)">
                                    {{ fy.name || (fy.start_date + ' → ' + fy.end_date) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <button class="cv-btn cv-btn-amber" @click="runReport">{{ ui.run }}</button>
                </div>

                <!-- Cash Flow filters -->
                <div v-if="activeTab === 'cash-flow'" class="filter-panel print-hide">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.dateFrom }}</label>
                            <input type="date" v-model="cfFrom" class="cv-input" />
                        </div>
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.dateTo }}</label>
                            <input type="date" v-model="cfTo" class="cv-input" />
                        </div>
                    </div>
                    <button class="cv-btn cv-btn-amber" @click="runReport">{{ ui.run }}</button>
                </div>

                <!-- Gross Margin filters -->
                <div v-if="activeTab === 'gross-margin'" class="filter-panel print-hide">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.farm }}</label>
                            <select v-model="gmFarm" class="cv-select">
                                <option value="">{{ ui.allFarms }}</option>
                                <option v-for="f in farms" :key="f.id" :value="String(f.id)">
                                    {{ n(f) }}
                                </option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.dateFrom }}</label>
                            <input type="date" v-model="gmFrom" class="cv-input" />
                        </div>
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.dateTo }}</label>
                            <input type="date" v-model="gmTo" class="cv-input" />
                        </div>
                    </div>
                    <button class="cv-btn cv-btn-amber" @click="runReport">{{ ui.run }}</button>
                </div>

                <!-- ADG/FCR filters -->
                <div v-if="activeTab === 'adg-fcr'" class="filter-panel print-hide">
                    <div class="filter-grid">
                        <div class="filter-field">
                            <label class="cv-label">{{ ui.lot }}</label>
                            <select v-model="adgLot" class="cv-select">
                                <option value="">{{ ui.allLots }}</option>
                                <option v-for="l in lots" :key="l.id" :value="String(l.id)">
                                    {{ l.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <button class="cv-btn cv-btn-amber" @click="runReport">{{ ui.run }}</button>
                </div>

                <!-- ═══════════════════════════════════════════════ -->
                <!-- REPORT BODIES -->
                <!-- ═══════════════════════════════════════════════ -->

                <!-- No data placeholder -->
                <div v-if="!report" class="cv-empty no-data-state">
                    <div class="cv-empty-icon">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10 9 9 9 8 9"/>
                        </svg>
                    </div>
                    <div class="cv-empty-title">{{ ui.noData }}</div>
                    <div class="cv-empty-sub">{{ ui.run }}</div>
                </div>

                <!-- ────────────────────────────────────────────── -->
                <!-- TAB: TRIAL BALANCE (#5 TrialBalance)           -->
                <!-- ────────────────────────────────────────────── -->
                <div v-if="activeTab === 'trial-balance' && report" class="report-body">
                    <!-- Print header -->
                    <div class="print-header">
                        <div class="print-title">{{ ui.tabTb }}</div>
                        <div class="print-period">{{ report.period_start }} → {{ report.period_end }}</div>
                    </div>

                    <!-- Assertion badge -->
                    <div class="assertion-bar" :class="tbBalanced ? 'assertion-ok' : 'assertion-fail'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <path v-if="tbBalanced" d="M20 6L9 17l-5-5"/>
                            <path v-else d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                        {{ tbBalanced ? ui.balanced : ui.imbalanced }}
                        <span v-if="!tbBalanced" class="assertion-detail">
                            ({{ fmtAmt(report.imbalance) }} {{ ui.egp }})
                        </span>
                    </div>

                    <!-- Table -->
                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th class="col-code">{{ ui.code }}</th>
                                    <th class="col-name">{{ ui.account }}</th>
                                    <th class="col-num">{{ ui.opening }}</th>
                                    <th class="col-num">{{ ui.periodDr }}</th>
                                    <th class="col-num">{{ ui.periodCr }}</th>
                                    <th class="col-num">{{ ui.closing }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in report.rows" :key="row.account_id"
                                    class="report-row">
                                    <td class="col-code code-cell">{{ row.code }}</td>
                                    <td class="col-name">
                                        {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                    </td>
                                    <td class="col-num" :class="amtClass(row.opening)">
                                        {{ fmtAmt(row.opening) }}
                                    </td>
                                    <td class="col-num blue-num">{{ fmtAmt(row.period_debit) }}</td>
                                    <td class="col-num blue-num">{{ fmtAmt(row.period_credit) }}</td>
                                    <td class="col-num bold-num" :class="amtClass(row.closing)">
                                        {{ fmtAmt(row.closing) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="totals-row">
                                    <td colspan="2" class="totals-label">{{ ui.totals }}</td>
                                    <td class="col-num bold-num">{{ fmtAmt(report.totals?.opening) }}</td>
                                    <td class="col-num bold-num blue-num">{{ fmtAmt(report.totals?.period_debit) }}</td>
                                    <td class="col-num bold-num blue-num">{{ fmtAmt(report.totals?.period_credit) }}</td>
                                    <td class="col-num bold-num"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- ────────────────────────────────────────────── -->
                <!-- TAB: PROFIT & LOSS (#6 ProfitAndLoss)          -->
                <!-- ────────────────────────────────────────────── -->
                <div v-if="activeTab === 'profit-loss' && report && plCurrent" class="report-body">
                    <div class="print-header">
                        <div class="print-title">{{ ui.tabPl }}</div>
                        <div class="print-period">{{ report.date_from }} → {{ report.date_to }}</div>
                    </div>

                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th class="col-code">{{ ui.code }}</th>
                                    <th class="col-name">{{ ui.account }}</th>
                                    <th class="col-num">{{ ui.currentPeriod }}</th>
                                    <th v-if="plCompare" class="col-num">{{ ui.comparison }}</th>
                                    <th v-if="plCompare" class="col-num">{{ ui.variance }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Revenue section -->
                                <tr class="section-header-row">
                                    <td colspan="5">{{ ui.revenue }}</td>
                                </tr>
                                <tr v-for="row in plCurrent.revenue_lines" :key="'rev-' + row.account_id"
                                    class="report-row">
                                    <td class="col-code code-cell">{{ row.code }}</td>
                                    <td class="col-name">
                                        {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                    </td>
                                    <td class="col-num blue-num">{{ fmtAmt(row.amount) }}</td>
                                    <td v-if="plCompare" class="col-num blue-num">
                                        {{ fmtAmt(plCompare.revenue?.find(r => r.account_id === row.account_id)?.amount) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num"
                                        :class="varianceClass(row.amount - (plCompare.revenue?.find(r => r.account_id === row.account_id)?.amount || 0), 'revenue')">
                                        {{ fmtAmt(row.amount - (plCompare.revenue?.find(r => r.account_id === row.account_id)?.amount || 0)) }}
                                    </td>
                                </tr>
                                <tr class="subtotal-row">
                                    <td colspan="2" class="subtotal-label">{{ lang === 'ar' ? 'إجمالي الإيرادات' : 'Total Revenue' }}</td>
                                    <td class="col-num bold-num blue-num">{{ fmtAmt(plCurrent.total_revenue) }}</td>
                                    <td v-if="plCompare" class="col-num bold-num blue-num">{{ fmtAmt(plCompare.total_revenue) }}</td>
                                    <td v-if="plCompare" class="col-num bold-num"
                                        :class="varianceClass(plCurrent.total_revenue - plCompare.total_revenue, 'revenue')">
                                        {{ fmtAmt(plCurrent.total_revenue - plCompare.total_revenue) }}
                                    </td>
                                </tr>

                                <!-- COGS section -->
                                <tr class="section-header-row">
                                    <td colspan="5">{{ ui.cogs }}</td>
                                </tr>
                                <tr v-for="row in plCurrent.cogs_lines" :key="'cogs-' + row.account_id"
                                    class="report-row">
                                    <td class="col-code code-cell">{{ row.code }}</td>
                                    <td class="col-name">
                                        {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                    </td>
                                    <td class="col-num" :class="amtClass(-row.amount)">{{ fmtAmt(row.amount) }}</td>
                                    <td v-if="plCompare" class="col-num">
                                        {{ fmtAmt(plCompare.cogs?.find(r => r.account_id === row.account_id)?.amount) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num"
                                        :class="varianceClass(-(row.amount - (plCompare.cogs?.find(r => r.account_id === row.account_id)?.amount || 0)), 'expense')">
                                        {{ fmtAmt(row.amount - (plCompare.cogs?.find(r => r.account_id === row.account_id)?.amount || 0)) }}
                                    </td>
                                </tr>

                                <!-- Gross Profit line -->
                                <tr class="gross-profit-row">
                                    <td colspan="2" class="gross-profit-label">{{ ui.grossProfit }}</td>
                                    <td class="col-num bold-num" :class="amtClass(plCurrent.gross_profit)">
                                        {{ fmtAmt(plCurrent.gross_profit) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num bold-num" :class="amtClass(plCompare.gross_profit)">
                                        {{ fmtAmt(plCompare.gross_profit) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num bold-num"
                                        :class="varianceClass(plCurrent.gross_profit - plCompare.gross_profit, 'revenue')">
                                        {{ fmtAmt(plCurrent.gross_profit - plCompare.gross_profit) }}
                                    </td>
                                </tr>

                                <!-- GM % -->
                                <tr class="gm-pct-row">
                                    <td colspan="2" class="gm-pct-label">{{ ui.grossMarginPct }}</td>
                                    <td class="col-num blue-num">
                                        {{ plCurrent.gross_margin_pct != null ? fmt(plCurrent.gross_margin_pct, 1) + '%' : ui.na }}
                                    </td>
                                    <td v-if="plCompare" class="col-num blue-num">
                                        {{ plCompare?.gross_margin_pct != null ? fmt(plCompare.gross_margin_pct, 1) + '%' : ui.na }}
                                    </td>
                                    <td v-if="plCompare"></td>
                                </tr>

                                <!-- Expenses -->
                                <tr class="section-header-row">
                                    <td colspan="5">{{ ui.expenses }}</td>
                                </tr>
                                <tr v-for="row in plCurrent.expense_lines" :key="'exp-' + row.account_id"
                                    class="report-row">
                                    <td class="col-code code-cell">{{ row.code }}</td>
                                    <td class="col-name">
                                        {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                    </td>
                                    <td class="col-num" :class="amtClass(-row.amount)">{{ fmtAmt(row.amount) }}</td>
                                    <td v-if="plCompare" class="col-num">
                                        {{ fmtAmt(plCompare.expenses?.find(r => r.account_id === row.account_id)?.amount) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num"
                                        :class="varianceClass(-(row.amount - (plCompare.expenses?.find(r => r.account_id === row.account_id)?.amount || 0)), 'expense')">
                                        {{ fmtAmt(row.amount - (plCompare.expenses?.find(r => r.account_id === row.account_id)?.amount || 0)) }}
                                    </td>
                                </tr>

                                <!-- Net Income -->
                                <tr class="net-income-row">
                                    <td colspan="2" class="net-income-label">{{ ui.netIncome }}</td>
                                    <td class="col-num bold-num" :class="amtClass(plCurrent.net_income)">
                                        {{ fmtAmt(plCurrent.net_income) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num bold-num" :class="amtClass(plCompare.net_income)">
                                        {{ fmtAmt(plCompare.net_income) }}
                                    </td>
                                    <td v-if="plCompare" class="col-num bold-num"
                                        :class="varianceClass(plCurrent.net_income - plCompare.net_income, 'revenue')">
                                        {{ fmtAmt(plCurrent.net_income - plCompare.net_income) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ────────────────────────────────────────────── -->
                <!-- TAB: BALANCE SHEET (#7 BalanceSheet)           -->
                <!-- ────────────────────────────────────────────── -->
                <div v-if="activeTab === 'balance-sheet' && report" class="report-body">
                    <div class="print-header">
                        <div class="print-title">{{ ui.tabBs }}</div>
                        <div class="print-period">{{ report.as_of_date }}</div>
                    </div>

                    <!-- Assertion badge -->
                    <div class="assertion-bar" :class="bsBalanced ? 'assertion-ok' : 'assertion-fail'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <path v-if="bsBalanced" d="M20 6L9 17l-5-5"/>
                            <path v-else d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                        {{ ui.assertion }}:
                        <strong>{{ fmtAmt(report.total_assets) }}</strong>
                        =
                        <strong>{{ fmtAmt(report.total_liabilities + report.total_equity) }}</strong>
                        <span class="cv-badge" :class="bsBalanced ? 'cv-badge-success' : 'cv-badge-danger'">
                            {{ bsBalanced ? ui.balanced : ui.imbalanced }}
                        </span>
                        <span v-if="!bsBalanced" class="assertion-detail">
                            (Δ {{ fmtAmt(bsImbalance) }})
                        </span>
                    </div>

                    <!-- Two-column layout: Assets | Liabilities + Equity -->
                    <div class="bs-columns">
                        <!-- Assets column -->
                        <div class="bs-col">
                            <div class="bs-col-header assets-header">{{ ui.assets }}</div>
                            <table class="report-table bs-table">
                                <tbody>
                                    <tr v-for="row in report.assets" :key="'a-' + row.account_id"
                                        class="report-row">
                                        <td class="col-code code-cell">{{ row.code }}</td>
                                        <td class="col-name">
                                            {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                        </td>
                                        <td class="col-num blue-num">{{ fmtAmt(row.balance) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="totals-row">
                                        <td colspan="2" class="totals-label">{{ ui.totalAssets }}</td>
                                        <td class="col-num bold-num blue-num">{{ fmtAmt(report.total_assets) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Liabilities + Equity column -->
                        <div class="bs-col">
                            <div class="bs-col-header liab-header">{{ ui.liabilities }}</div>
                            <table class="report-table bs-table">
                                <tbody>
                                    <tr v-for="row in report.liabilities" :key="'l-' + row.account_id"
                                        class="report-row">
                                        <td class="col-code code-cell">{{ row.code }}</td>
                                        <td class="col-name">
                                            {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                        </td>
                                        <td class="col-num" :class="amtClass(-row.balance)">{{ fmtAmt(row.balance) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="subtotal-row">
                                        <td colspan="2" class="subtotal-label">{{ ui.totalLiab }}</td>
                                        <td class="col-num bold-num">{{ fmtAmt(report.total_liabilities) }}</td>
                                    </tr>
                                </tfoot>
                            </table>

                            <div class="bs-col-header equity-header" style="margin-top: 1.5rem">{{ ui.equity }}</div>
                            <table class="report-table bs-table">
                                <tbody>
                                    <tr v-for="row in report.equity" :key="'e-' + (row.account_id || row.code)"
                                        class="report-row"
                                        :class="{ 'ytd-row': row.code === 'RE_YTD' }">
                                        <td class="col-code code-cell">{{ row.code }}</td>
                                        <td class="col-name">
                                            {{ lang === 'ar' ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar) }}
                                        </td>
                                        <td class="col-num" :class="amtClass(-row.balance)">{{ fmtAmt(row.balance) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="subtotal-row">
                                        <td colspan="2" class="subtotal-label">{{ ui.totalEquity }}</td>
                                        <td class="col-num bold-num">{{ fmtAmt(report.total_equity) }}</td>
                                    </tr>
                                    <tr class="totals-row">
                                        <td colspan="2" class="totals-label">{{ ui.liabPlusEq }}</td>
                                        <td class="col-num bold-num blue-num">
                                            {{ fmtAmt(report.total_liabilities + report.total_equity) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ────────────────────────────────────────────── -->
                <!-- TAB: CASH FLOW (#8 CashFlow indirect method)   -->
                <!-- ────────────────────────────────────────────── -->
                <div v-if="activeTab === 'cash-flow' && report" class="report-body">
                    <div class="print-header">
                        <div class="print-title">{{ ui.tabCf }}</div>
                        <div class="print-period">{{ report.date_from }} → {{ report.date_to }}</div>
                    </div>

                    <div class="cf-layout">
                        <!-- Operating -->
                        <div class="cf-section">
                            <div class="cf-section-header operating-header">{{ ui.operating }}</div>
                            <table class="cf-table">
                                <tbody>
                                    <tr><td>{{ ui.netIncomeItem }}</td>
                                        <td class="cf-num" :class="amtClass(report.operating?.net_income)">{{ fmtAmt(report.operating?.net_income) }}</td></tr>
                                    <tr><td>{{ ui.depreciationItem }}</td>
                                        <td class="cf-num blue-num">{{ fmtAmt(report.operating?.depreciation) }}</td></tr>
                                    <tr><td>{{ ui.deltaAr }}</td>
                                        <td class="cf-num" :class="amtClass(report.operating?.delta_ar)">{{ fmtAmt(report.operating?.delta_ar) }}</td></tr>
                                    <tr><td>{{ ui.deltaAp }}</td>
                                        <td class="cf-num" :class="amtClass(report.operating?.delta_ap)">{{ fmtAmt(report.operating?.delta_ap) }}</td></tr>
                                    <tr><td>{{ ui.deltaInv }}</td>
                                        <td class="cf-num" :class="amtClass(report.operating?.delta_inventory)">{{ fmtAmt(report.operating?.delta_inventory) }}</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="cf-total-row">
                                        <td class="cf-total-label">{{ lang === 'ar' ? 'صافي التدفق التشغيلي' : 'Net Operating CF' }}</td>
                                        <td class="cf-num bold-num" :class="amtClass(report.operating?.total)">{{ fmtAmt(report.operating?.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Investing -->
                        <div class="cf-section">
                            <div class="cf-section-header investing-header">{{ ui.investing }}</div>
                            <table class="cf-table">
                                <tbody>
                                    <tr><td>{{ ui.faMovement }}</td>
                                        <td class="cf-num" :class="amtClass(report.investing?.fixed_asset_movement)">{{ fmtAmt(report.investing?.fixed_asset_movement) }}</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="cf-total-row">
                                        <td class="cf-total-label">{{ lang === 'ar' ? 'صافي التدفق الاستثماري' : 'Net Investing CF' }}</td>
                                        <td class="cf-num bold-num" :class="amtClass(report.investing?.total)">{{ fmtAmt(report.investing?.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Financing -->
                        <div class="cf-section">
                            <div class="cf-section-header financing-header">{{ ui.financing }}</div>
                            <table class="cf-table">
                                <tbody>
                                    <tr><td>{{ ui.loanEqMovement }}</td>
                                        <td class="cf-num" :class="amtClass(report.financing?.loan_equity_movement)">{{ fmtAmt(report.financing?.loan_equity_movement) }}</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="cf-total-row">
                                        <td class="cf-total-label">{{ lang === 'ar' ? 'صافي التدفق التمويلي' : 'Net Financing CF' }}</td>
                                        <td class="cf-num bold-num" :class="amtClass(report.financing?.total)">{{ fmtAmt(report.financing?.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Summary -->
                        <div class="cf-summary">
                            <div class="cf-summary-row">
                                <span>{{ ui.netChange }}</span>
                                <span class="cf-summary-num bold-num" :class="amtClass(report.net_change)">
                                    {{ fmtAmt(report.net_change) }}
                                </span>
                            </div>
                            <div class="cf-summary-divider"></div>
                            <div class="cf-summary-row">
                                <span>{{ ui.openingCash }}</span>
                                <span class="cf-summary-num blue-num">{{ fmtAmt(report.opening_cash) }}</span>
                            </div>
                            <div class="cf-summary-row cf-summary-closing">
                                <span>{{ ui.closingCash }}</span>
                                <span class="cf-summary-num bold-num blue-num">{{ fmtAmt(report.closing_cash) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ────────────────────────────────────────────── -->
                <!-- TAB: GROSS MARGIN RANKING (#11 GrossMargin)    -->
                <!-- ────────────────────────────────────────────── -->
                <div v-if="activeTab === 'gross-margin' && report" class="report-body">
                    <div class="print-header">
                        <div class="print-title">{{ ui.tabGm }}</div>
                    </div>

                    <div v-if="gmRows.length === 0" class="cv-empty">
                        <div class="cv-empty-title">{{ ui.noData }}</div>
                    </div>

                    <div v-else class="gm-layout">
                        <!-- Ranking cards -->
                        <div class="gm-cards">
                            <div
                                v-for="(row, idx) in gmRows"
                                :key="'gm-' + row.lot_id"
                                class="gm-card"
                                :class="{
                                    'gm-card-gold':   idx === 0,
                                    'gm-card-silver': idx === 1,
                                    'gm-card-bronze': idx === 2,
                                }"
                            >
                                <div class="gm-rank-badge">{{ idx + 1 }}</div>
                                <div class="gm-card-body">
                                    <div class="gm-lot-name">{{ ui.lotLabel }} #{{ row.lot_id }}</div>
                                    <div class="gm-kpis">
                                        <div class="gm-kpi">
                                            <span class="gm-kpi-label">{{ ui.headCount }}</span>
                                            <span class="gm-kpi-val blue-num">{{ fmt(row.head_count) }}</span>
                                        </div>
                                        <div class="gm-kpi">
                                            <span class="gm-kpi-label">{{ ui.totalRevenue }}</span>
                                            <span class="gm-kpi-val blue-num">{{ fmtAmt(row.total_revenue) }}</span>
                                        </div>
                                        <div class="gm-kpi">
                                            <span class="gm-kpi-label">{{ ui.totalCogs }}</span>
                                            <span class="gm-kpi-val" :class="amtClass(-row.total_cogs)">{{ fmtAmt(row.total_cogs) }}</span>
                                        </div>
                                        <div class="gm-kpi">
                                            <span class="gm-kpi-label">{{ ui.grossMargin }}</span>
                                            <span class="gm-kpi-val bold-num" :class="row.gross_margin >= 0 ? 'pos' : 'neg'">
                                                {{ fmtAmt(row.gross_margin) }}
                                            </span>
                                        </div>
                                        <div class="gm-kpi">
                                            <span class="gm-kpi-label">{{ ui.grossMarginPct }}</span>
                                            <span class="gm-kpi-val bold-num" :class="(row.gross_margin_pct || 0) >= 0 ? 'pos' : 'neg'">
                                                {{ row.gross_margin_pct != null ? fmt(row.gross_margin_pct, 1) + '%' : ui.na }}
                                            </span>
                                        </div>
                                        <div class="gm-kpi">
                                            <span class="gm-kpi-label">{{ ui.avgAdg }}</span>
                                            <span class="gm-kpi-val blue-num">
                                                {{ row.avg_adg != null ? fmt(row.avg_adg, 3) : ui.na }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Cost breakdown bar -->
                                    <div class="cost-bar-wrap">
                                        <div class="cost-bar-label">{{ ui.costBreakdown }}</div>
                                        <div class="cost-bar">
                                            <template v-if="row.cost_breakdown && row.total_cogs > 0">
                                                <div class="cost-seg cost-seg-purchase"
                                                     :style="{ width: (row.cost_breakdown.purchase / row.total_cogs * 100) + '%' }"
                                                     :title="ui.purchase + ': ' + fmtAmt(row.cost_breakdown.purchase)"></div>
                                                <div class="cost-seg cost-seg-feed"
                                                     :style="{ width: (row.cost_breakdown.feed / row.total_cogs * 100) + '%' }"
                                                     :title="ui.feed + ': ' + fmtAmt(row.cost_breakdown.feed)"></div>
                                                <div class="cost-seg cost-seg-vet"
                                                     :style="{ width: ((row.cost_breakdown.veterinary + row.cost_breakdown.vaccination) / row.total_cogs * 100) + '%' }"
                                                     :title="ui.veterinary"></div>
                                                <div class="cost-seg cost-seg-other"
                                                     :style="{ width: ((row.cost_breakdown.slaughter + row.cost_breakdown.overhead) / row.total_cogs * 100) + '%' }"
                                                     :title="ui.overhead"></div>
                                            </template>
                                        </div>
                                        <div class="cost-legend">
                                            <span class="cost-legend-item"><span class="cost-dot cost-dot-purchase"></span>{{ ui.purchase }}</span>
                                            <span class="cost-legend-item"><span class="cost-dot cost-dot-feed"></span>{{ ui.feed }}</span>
                                            <span class="cost-legend-item"><span class="cost-dot cost-dot-vet"></span>{{ ui.veterinary }}</span>
                                            <span class="cost-legend-item"><span class="cost-dot cost-dot-other"></span>{{ ui.overhead }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ────────────────────────────────────────────── -->
                <!-- TAB: ADG / FCR (#9 ADG_FCR)                   -->
                <!-- ────────────────────────────────────────────── -->
                <div v-if="activeTab === 'adg-fcr' && report" class="report-body">
                    <div class="print-header">
                        <div class="print-title">{{ ui.tabAdg }}</div>
                    </div>

                    <div v-if="!adgData || adgData.length === 0" class="cv-empty">
                        <div class="cv-empty-title">{{ ui.noData }}</div>
                    </div>

                    <div v-else class="adg-grid">
                        <div
                            v-for="row in adgData"
                            :key="'adg-' + (row.lot_id || row.animal_id)"
                            class="adg-card"
                        >
                            <!-- Card header -->
                            <div class="adg-card-header">
                                <div class="adg-card-title">
                                    <span v-if="row.lot_id">{{ ui.lotLabel }} #{{ row.lot_id }}</span>
                                    <span v-if="row.head_count" class="adg-head-count">
                                        · {{ fmt(row.head_count) }} {{ lang === 'ar' ? 'رأس' : 'head' }}
                                    </span>
                                </div>
                                <div v-if="row.reason" class="adg-no-data">{{ ui.noWeightData }}</div>
                            </div>

                            <template v-if="!row.reason">
                                <div class="adg-kpi-grid">
                                    <div class="adg-kpi">
                                        <div class="adg-kpi-label">{{ ui.weightInitial }}</div>
                                        <div class="adg-kpi-val blue-num">{{ fmt(row.weight_initial, 1) }}</div>
                                    </div>
                                    <div class="adg-kpi">
                                        <div class="adg-kpi-label">{{ ui.weightFinal }}</div>
                                        <div class="adg-kpi-val blue-num">{{ fmt(row.weight_final, 1) }}</div>
                                    </div>
                                    <div class="adg-kpi">
                                        <div class="adg-kpi-label">{{ ui.weightGain }}</div>
                                        <div class="adg-kpi-val bold-num" :class="(row.weight_gain || 0) >= 0 ? 'pos' : 'neg'">
                                            {{ fmt(row.weight_gain, 1) }}
                                        </div>
                                    </div>
                                    <div class="adg-kpi">
                                        <div class="adg-kpi-label">{{ ui.daysOnFeed }}</div>
                                        <div class="adg-kpi-val blue-num">{{ fmt(row.days_on_feed) }}</div>
                                    </div>
                                    <div class="adg-kpi adg-kpi-highlight">
                                        <div class="adg-kpi-label">{{ ui.adgKgDay }}</div>
                                        <div class="adg-kpi-val bold-num pos">{{ fmt(row.adg_kg_day, 3) }}</div>
                                    </div>
                                    <div class="adg-kpi adg-kpi-highlight">
                                        <div class="adg-kpi-label">{{ ui.fcrLabel }}</div>
                                        <div class="adg-kpi-val bold-num blue-num">
                                            {{ row.fcr != null ? fmt(row.fcr, 3) : ui.na }}
                                        </div>
                                    </div>
                                    <div class="adg-kpi">
                                        <div class="adg-kpi-label">{{ ui.feedDmTotal }}</div>
                                        <div class="adg-kpi-val blue-num">{{ fmt(row.feed_dm_total, 2) }}</div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            </div><!-- end cv-page -->
        </div><!-- end reports-root -->
    
</template>

<style scoped>
/* ── Root ──────────────────────────────────────────────────────── */
.reports-root { min-height: 100vh; background: var(--cv-bg); }

/* ── SA Company bar ────────────────────────────────────────────── */
.sa-company-bar {
    display: flex; align-items: center; gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding: 0.75rem 1rem;
    background: var(--cv-amber-light);
    border: 1px solid var(--cv-amber-border);
    border-radius: var(--cv-radius);
}
.sa-label { font-size: 0.8125rem; color: var(--cv-amber-text); font-weight: 600; white-space: nowrap; }
.sa-select { flex: 1; max-width: 280px; }

/* ── Tab bar ───────────────────────────────────────────────────── */
.report-tabs {
    display: flex; flex-wrap: wrap; gap: 0.375rem;
    margin-bottom: 1.25rem;
    border-bottom: 1px solid var(--cv-border-md);
    padding-bottom: 0.75rem;
}
.report-tab-btn {
    padding: 0.4375rem 1rem;
    border-radius: var(--cv-radius-sm);
    font-size: 0.8125rem; font-weight: 500;
    border: 1px solid transparent;
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer; transition: var(--cv-transition); font-family: inherit;
}
.report-tab-btn:hover {
    background: var(--cv-bg-hover); color: var(--cv-text-primary);
}
.report-tab-btn.active {
    background: var(--cv-green-dim);
    border-color: var(--cv-green-border);
    color: var(--cv-green-bright); font-weight: 600;
}

/* ── Filter panel ──────────────────────────────────────────────── */
.filter-panel {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem;
}
.filter-grid {
    display: flex; flex-wrap: wrap; gap: 0.875rem; flex: 1;
}
.filter-field { display: flex; flex-direction: column; gap: 0.3125rem; min-width: 180px; }
.filter-field-full { flex-basis: 100%; }
.compare-toggle {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.8125rem; color: var(--cv-text-secondary); cursor: pointer;
}

/* ── Report body ───────────────────────────────────────────────── */
.report-body {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius-md);
    overflow: hidden;
}
.no-data-state {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius-md);
    padding: 4rem 2rem;
}

/* ── Print header (hidden on screen, shown on print) ──────────── */
.print-header { display: none; }

/* ── Assertion bar ─────────────────────────────────────────────── */
.assertion-bar {
    display: flex; align-items: center; gap: 0.625rem;
    padding: 0.75rem 1.25rem;
    font-size: 0.8125rem; font-weight: 500;
    border-bottom: 1px solid var(--cv-border-md);
}
.assertion-ok   { background: var(--cv-success-bg); color: var(--cv-success-text); }
.assertion-fail { background: var(--cv-danger-bg);  color: var(--cv-danger-text);  }
.assertion-detail { opacity: 0.7; font-weight: 400; }

/* ── Tables ────────────────────────────────────────────────────── */
.report-table-wrap { overflow-x: auto; }
.report-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.8125rem;
}
.report-table thead tr {
    background: var(--cv-bg-card-alt);
    border-bottom: 2px solid var(--cv-border-md);
}
.report-table thead th {
    padding: 0.625rem 1rem;
    color: var(--cv-text-muted); font-weight: 600;
    font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em;
    text-align: start; white-space: nowrap;
}
.report-row { border-bottom: 1px solid var(--cv-border); transition: var(--cv-transition); }
.report-row:hover { background: var(--cv-bg-hover); }
.report-row td { padding: 0.5rem 1rem; color: var(--cv-text-primary); }

/* Column widths */
.col-code { width: 80px; }
.col-name { min-width: 200px; }
.col-num  { text-align: end; white-space: nowrap; min-width: 130px; }

.code-cell { color: var(--cv-text-muted); font-size: 0.75rem; font-family: monospace; }
.blue-num  { color: var(--cv-blue-text); }
.bold-num  { font-weight: 700; }
.pos       { color: var(--cv-success-text); }
.neg       { color: var(--cv-danger-text); }

/* Section headers */
.section-header-row td {
    padding: 0.625rem 1rem;
    font-size: 0.6875rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--cv-amber-text);
    background: var(--cv-amber-light);
    border-bottom: 1px solid var(--cv-amber-border);
}

/* Subtotal row */
.subtotal-row td {
    padding: 0.5625rem 1rem;
    background: var(--cv-bg-card-alt);
    border-top: 1px solid var(--cv-border-md);
    border-bottom: 1px solid var(--cv-border-md);
}
.subtotal-label { color: var(--cv-text-secondary); font-weight: 600; font-size: 0.8125rem; }

/* Gross profit row */
.gross-profit-row td {
    padding: 0.625rem 1rem;
    background: var(--cv-green-dim);
    border-top: 2px solid var(--cv-green-border);
    border-bottom: 1px solid var(--cv-green-border);
}
.gross-profit-label { color: var(--cv-green-bright); font-weight: 700; }

/* GM% row */
.gm-pct-row td { padding: 0.375rem 1rem; background: var(--cv-bg-card-alt); }
.gm-pct-label  { color: var(--cv-text-muted); font-size: 0.75rem; }

/* Net income row */
.net-income-row td {
    padding: 0.75rem 1rem;
    background: var(--cv-info-bg);
    border-top: 2px solid var(--cv-info-border);
    border-bottom: 2px solid var(--cv-info-border);
}
.net-income-label { color: var(--cv-info-text); font-weight: 700; font-size: 0.9375rem; }

/* YTD net income equity line */
.ytd-row td { background: var(--cv-info-bg); font-style: italic; }

/* Totals footer */
.totals-row td {
    padding: 0.75rem 1rem;
    background: var(--cv-bg-sidebar);
    border-top: 2px solid var(--cv-border-md);
}
.totals-label { color: var(--cv-text-primary); font-weight: 700; font-size: 0.875rem; }

/* ── Balance Sheet columns ─────────────────────────────────────── */
.bs-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
}
@media (max-width: 768px) {
    .bs-columns { grid-template-columns: 1fr; }
}
.bs-col { padding: 1rem; border-inline-end: 1px solid var(--cv-border-md); }
.bs-col:last-child { border-inline-end: none; }
.bs-col-header {
    font-size: 0.6875rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    padding: 0.5rem 0; margin-bottom: 0.5rem;
    border-bottom: 2px solid;
}
.assets-header { color: var(--cv-blue-text);        border-color: var(--cv-blue);        }
.liab-header   { color: var(--cv-danger-text);       border-color: var(--cv-danger);      }
.equity-header { color: var(--cv-amber-text);        border-color: var(--cv-amber);       }
.bs-table      { width: 100%; }

/* ── Cash Flow layout ──────────────────────────────────────────── */
.cf-layout { padding: 1.25rem; display: flex; flex-direction: column; gap: 1.5rem; }
.cf-section {
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius);
    overflow: hidden;
}
.cf-section-header {
    padding: 0.625rem 1rem;
    font-size: 0.6875rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
}
.operating-header  { background: var(--cv-green-dim);  color: var(--cv-green-bright); }
.investing-header  { background: var(--cv-blue-light);  color: var(--cv-blue-text);   }
.financing-header  { background: var(--cv-amber-light); color: var(--cv-amber-text);  }

.cf-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
.cf-table tbody tr { border-bottom: 1px solid var(--cv-border); }
.cf-table tbody tr:hover { background: var(--cv-bg-hover); }
.cf-table tbody td { padding: 0.5rem 1rem; color: var(--cv-text-secondary); }
.cf-num { text-align: end; white-space: nowrap; min-width: 140px; }
.cf-total-row td { padding: 0.625rem 1rem; background: var(--cv-bg-card); font-weight: 600; border-top: 1px solid var(--cv-border-md); }
.cf-total-label { color: var(--cv-text-primary); }

.cf-summary {
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius);
    padding: 1rem 1.25rem;
    max-width: 400px;
}
.cf-summary-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.5rem 0; font-size: 0.875rem; color: var(--cv-text-secondary);
}
.cf-summary-closing { font-size: 1rem; color: var(--cv-text-primary); }
.cf-summary-num     { text-align: end; }
.cf-summary-divider { border-top: 1px solid var(--cv-border-md); margin: 0.5rem 0; }

/* ── Gross Margin cards ────────────────────────────────────────── */
.gm-layout { padding: 1.25rem; }
.gm-cards  { display: flex; flex-direction: column; gap: 1rem; }
.gm-card {
    position: relative;
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius-md);
    padding: 1.25rem 1.25rem 1.25rem 3.5rem;
    display: flex; gap: 1rem;
}
.gm-card-gold   { border-color: #B8860B; background: linear-gradient(135deg, rgba(184,134,11,0.08), var(--cv-bg-card-alt)); }
.gm-card-silver { border-color: #888;    background: linear-gradient(135deg, rgba(136,136,136,0.06), var(--cv-bg-card-alt)); }
.gm-card-bronze { border-color: #8B4513; background: linear-gradient(135deg, rgba(139,69,19,0.06), var(--cv-bg-card-alt)); }

.gm-rank-badge {
    position: absolute;
    inset-inline-start: 1rem; top: 50%; transform: translateY(-50%);
    width: 2rem; height: 2rem;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.875rem; font-weight: 700;
    background: var(--cv-bg-card); border: 2px solid var(--cv-border-md);
    color: var(--cv-text-muted);
}
.gm-card-gold   .gm-rank-badge { border-color: #B8860B; color: #B8860B; }
.gm-card-silver .gm-rank-badge { border-color: #888;    color: #888;    }
.gm-card-bronze .gm-rank-badge { border-color: #8B4513; color: #8B4513; }

.gm-card-body { flex: 1; }
.gm-lot-name  { font-size: 0.9375rem; font-weight: 700; color: var(--cv-text-primary); margin-bottom: 0.875rem; }
.gm-kpis      { display: flex; flex-wrap: wrap; gap: 0.625rem; margin-bottom: 1rem; }
.gm-kpi       { background: var(--cv-bg-card); border: 1px solid var(--cv-border); border-radius: var(--cv-radius-sm); padding: 0.375rem 0.625rem; min-width: 110px; }
.gm-kpi-label { font-size: 0.625rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.06em; }
.gm-kpi-val   { font-size: 0.9375rem; font-weight: 600; color: var(--cv-text-primary); margin-top: 0.125rem; }

/* Cost breakdown bar */
.cost-bar-wrap { margin-top: 0.5rem; }
.cost-bar-label { font-size: 0.625rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.375rem; }
.cost-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--cv-border); }
.cost-seg { height: 100%; }
.cost-seg-purchase { background: var(--cv-blue);         }
.cost-seg-feed     { background: var(--cv-green-bright); }
.cost-seg-vet      { background: var(--cv-amber);        }
.cost-seg-other    { background: var(--cv-danger);       }
.cost-legend       { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.375rem; }
.cost-legend-item  { display: flex; align-items: center; gap: 0.3125rem; font-size: 0.6875rem; color: var(--cv-text-muted); }
.cost-dot          { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }
.cost-dot-purchase { background: var(--cv-blue);         }
.cost-dot-feed     { background: var(--cv-green-bright); }
.cost-dot-vet      { background: var(--cv-amber);        }
.cost-dot-other    { background: var(--cv-danger);       }

/* ── ADG / FCR grid ────────────────────────────────────────────── */
.adg-grid {
    padding: 1.25rem;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 1rem;
}
.adg-card {
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border-md);
    border-radius: var(--cv-radius-md);
    overflow: hidden;
}
.adg-card-header {
    padding: 0.75rem 1rem;
    background: var(--cv-bg-card);
    border-bottom: 1px solid var(--cv-border-md);
}
.adg-card-title { font-size: 0.875rem; font-weight: 700; color: var(--cv-text-primary); }
.adg-head-count { font-weight: 400; color: var(--cv-text-muted); }
.adg-no-data    { font-size: 0.75rem; color: var(--cv-text-muted); margin-top: 0.25rem; font-style: italic; }
.adg-kpi-grid {
    display: grid; grid-template-columns: repeat(2, 1fr);
    gap: 1px; background: var(--cv-border);
}
.adg-kpi {
    padding: 0.75rem 1rem;
    background: var(--cv-bg-card-alt);
}
.adg-kpi-highlight { background: var(--cv-bg-card); }
.adg-kpi-label { font-size: 0.6875rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.25rem; }
.adg-kpi-val   { font-size: 1rem; font-weight: 600; color: var(--cv-text-primary); }

/* ── Print styles ──────────────────────────────────────────────── */
@media print {
    .print-hide { display: none !important; }
    .print-header { display: block; padding: 1rem; border-bottom: 2px solid #333; margin-bottom: 1rem; }
    .print-title  { font-size: 1.25rem; font-weight: 700; }
    .print-period { font-size: 0.875rem; color: #666; }
    .report-body  { border: none; border-radius: 0; }
    .report-row:hover { background: transparent; }
    .bs-columns   { grid-template-columns: 1fr 1fr; }
    .gm-cards     { break-inside: avoid; }
    .adg-grid     { grid-template-columns: repeat(2, 1fr); }
}
</style>
