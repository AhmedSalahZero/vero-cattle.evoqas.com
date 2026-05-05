<script setup>
/**
 * Dashboard/Index.vue
 * ─────────────────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Main Dashboard
 *
 * Props from DashboardController::index():
 *   dashboard        → { operational, financial, alerts, charts, generated_at }
 *   farms            → [{ id, name_en, name_ar }]
 *   active_farm      → int|null
 *   auth_role        → string
 *   can_view_reports → bool  (false for Data Entry — financial section hidden)
 *
 * Layout: AuthenticatedLayout (sidebar + header already handled there)
 * ─────────────────────────────────────────────────────────────────────────────
 */
import { ref, computed } from 'vue'
import { router, usePage, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    dashboard:        { type: Object,  required: true },
    farms:            { type: Array,   default: () => [] },
    active_farm:      { type: [Number, String], default: null },
    auth_role:        { type: String,  default: '' },
    can_view_reports: { type: Boolean, default: false },
})

// ── Inertia shared props ──────────────────────────────────────────
const page = usePage()
const user = computed(() => page.props.auth?.user ?? {})
const lang = computed(() => user.value?.language ?? 'en')
const isAr = computed(() => lang.value === 'ar')

// ── Bilingual helper ──────────────────────────────────────────────
const t = (en, ar) => isAr.value ? ar : en

// ── Data shortcuts ────────────────────────────────────────────────
const op     = computed(() => props.dashboard?.operational ?? {})
const fin    = computed(() => props.dashboard?.financial   ?? {})
const alrt   = computed(() => props.dashboard?.alerts      ?? {})
const charts = computed(() => props.dashboard?.charts      ?? {})

// ── Farm filter ───────────────────────────────────────────────────
const selectedFarm = ref(props.active_farm ?? '')

function applyFarmFilter() {
    router.get(
        route('dashboard'),
        { farm_id: selectedFarm.value || '' },
        { preserveState: true, replace: true, preserveScroll: true }
    )
}

// ── Formatters ────────────────────────────────────────────────────
function fmt(v, decimals = 0) {
    if (v == null || isNaN(Number(v))) return '—'
    return Number(v).toLocaleString(isAr.value ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}

function fmtCurrency(v) {
    if (v == null || isNaN(Number(v))) return '—'
    return Number(v).toLocaleString(isAr.value ? 'ar-EG' : 'en-US', {
        style: 'currency', currency: 'EGP',
        minimumFractionDigits: 0, maximumFractionDigits: 0,
    })
}

function fmtPct(v) {
    if (v == null || isNaN(Number(v))) return '—'
    return `${Number(v).toFixed(1)}%`
}

// ── Monthly chart data ────────────────────────────────────────────
const monthlyData = computed(() => charts.value?.monthly_revenue_cogs ?? [])

const chartMax = computed(() => {
    const vals = monthlyData.value.flatMap(m => [m.revenue ?? 0, m.cogs ?? 0])
    return Math.max(...vals, 1)
})

function barHeight(value) {
    return `${Math.max(2, Math.round((Number(value) / chartMax.value) * 100))}%`
}

// ── Lot breakdown ─────────────────────────────────────────────────
const lotData  = computed(() => charts.value?.head_count_by_lot ?? [])
const lotTotal = computed(() => lotData.value.reduce((s, l) => s + (Number(l.head_count) || 0), 0))

function lotPct(head) {
    if (!lotTotal.value) return 0
    return Math.round((Number(head) / lotTotal.value) * 100)
}

// ── Alert total ───────────────────────────────────────────────────
const totalAlerts = computed(() =>
    (alrt.value.slaughter_due_30d     ?? 0) +
    (alrt.value.vaccinations_due_7d   ?? 0) +
    (alrt.value.animals_in_quarantine ?? 0) +
    (alrt.value.withdrawal_pending    ?? 0) +
    (alrt.value.overdue_ap_invoices   ?? 0)
)

// ── Gross margin KPI card colour ──────────────────────────────────
function marginCardClass(pct) {
    if (pct == null) return 'cv-stat-card-blue'
    if (Number(pct) >= 20) return 'cv-stat-card-green'
    if (Number(pct) >= 0)  return 'cv-stat-card-amber'
    return 'cv-stat-card-red'
}

// ── Formatted timestamp ───────────────────────────────────────────
const generatedAt = computed(() => {
    if (!props.dashboard?.generated_at) return ''
    try {
        return new Date(props.dashboard.generated_at).toLocaleString(
            isAr.value ? 'ar-EG' : 'en-US',
            { dateStyle: 'medium', timeStyle: 'short' }
        )
    } catch { return '' }
})
</script>

<template>
    <AuthenticatedLayout>

        <!-- ── Breadcrumb slot ────────────────────────────────────── -->
        <template #header>
            <span class="cv-header-breadcrumb-current">
                {{ t('Dashboard', 'لوحة المؤشرات') }}
            </span>
        </template>

        <!-- ════════════════════════════════════════════════════════
             PAGE HERO
        ════════════════════════════════════════════════════════ -->
        <div class="cv-page-hero">
            <div class="cv-page-hero-eyebrow">{{ t('VERO CATTLE MANAGEMENT', 'فيرو — إدارة الماشية') }}</div>

            <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;position:relative;">
                <div>
                    <h1 class="cv-page-hero-title">{{ t('Dashboard', 'لوحة المؤشرات') }}</h1>
                    <p class="cv-page-hero-sub" v-if="generatedAt">
                        {{ t('Last updated', 'آخر تحديث') }}: {{ generatedAt }}
                    </p>
                </div>

                <!-- Farm filter — only shown when there are multiple farms -->
                <div v-if="farms.length > 1" style="display:flex;align-items:center;gap:0.5rem;position:relative;">
                    <label class="cv-label" style="margin:0;white-space:nowrap;color:rgba(180,220,190,0.55);font-size:0.65rem;">
                        {{ t('Farm', 'المزرعة') }}
                    </label>
                    <select
                        v-model="selectedFarm"
                        class="cv-select"
                        style="width:180px;font-size:0.78rem;padding:0.375rem 0.625rem;"
                        @change="applyFarmFilter"
                    >
                        <option value="">{{ t('All Farms', 'كل المزارع') }}</option>
                        <option
                            v-for="farm in farms"
                            :key="farm.id"
                            :value="farm.id"
                        >
                            {{ isAr ? (farm.name_ar ?? farm.name_en) : farm.name_en }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════
             PAGE BODY
        ════════════════════════════════════════════════════════ -->
        <div class="dash-body">

            <!-- ── ALERTS BANNER ─────────────────────────────────── -->
            <div v-if="totalAlerts > 0" class="dash-alerts-strip" role="alert">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span style="font-weight:600;">
                    {{ totalAlerts }} {{ t('active alerts require attention', 'تنبيه يستوجب المتابعة') }}
                </span>
                <div class="dash-alerts-pills">
                    <span v-if="alrt.slaughter_due_30d" class="dash-alert-pill dash-alert-pill-amber">
                        🐄 {{ alrt.slaughter_due_30d }} {{ t('due for slaughter', 'موعد الذبح') }}
                    </span>
                    <span v-if="alrt.vaccinations_due_7d" class="dash-alert-pill dash-alert-pill-blue">
                        💉 {{ alrt.vaccinations_due_7d }} {{ t('vaccinations due', 'تطعيمات مستحقة') }}
                    </span>
                    <span v-if="alrt.animals_in_quarantine" class="dash-alert-pill dash-alert-pill-amber">
                        ⚠ {{ alrt.animals_in_quarantine }} {{ t('in quarantine', 'في الحجر') }}
                    </span>
                    <span v-if="alrt.withdrawal_pending" class="dash-alert-pill dash-alert-pill-amber">
                        🩺 {{ alrt.withdrawal_pending }} {{ t('withdrawal pending', 'فترة سحب') }}
                    </span>
                    <span v-if="alrt.overdue_ap_invoices" class="dash-alert-pill dash-alert-pill-red">
                        📤 {{ alrt.overdue_ap_invoices }} {{ t('overdue invoices', 'فواتير متأخرة') }}
                    </span>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 SECTION 1 — OPERATIONAL KPIs
            ══════════════════════════════════════════════════ -->
            <div class="dash-section-label">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                {{ t('Operational KPIs', 'مؤشرات التشغيل') }}
            </div>

            <div class="dash-kpi-grid">

                <!-- Active Head Count -->
                <div class="cv-stat-card cv-stat-card-green">
                    <div class="cv-stat-card-label">{{ t('Active Head', 'الرأس النشطة') }}</div>
                    <div class="cv-stat-card-value">{{ fmt(op.active_head_count) }}</div>
                    <div class="cv-stat-card-sub">
                        {{ op.active_lots ?? 0 }} {{ t('active lots', ' مجموعات نشطة') }}
                        <span v-if="op.mortality_mtd" style="margin-inline-start:0.5rem;color:var(--cv-danger-text)">
                            · {{ op.mortality_mtd }} {{ t('dead MTD', 'نفوق') }}
                        </span>
                    </div>
                </div>

                <!-- Average Daily Gain -->
                <div class="cv-stat-card cv-stat-card-blue">
                    <div class="cv-stat-card-label">{{ t('Avg. Daily Gain', 'متوسط المكسب اليومي') }}</div>
                    <div class="cv-stat-card-value">
                        {{ op.avg_adg_kg_day != null ? fmt(op.avg_adg_kg_day, 3) : '—' }}
                    </div>
                    <div class="cv-stat-card-sub">{{ t('kg / head / day', 'كجم / رأس / يوم') }}</div>
                </div>

                <!-- Feed Cost MTD -->
                <div class="cv-stat-card cv-stat-card-amber">
                    <div class="cv-stat-card-label">{{ t('Feed Cost MTD', 'تكلفة العلف — هذا الشهر') }}</div>
                    <div class="cv-stat-card-value">{{ fmtCurrency(op.feed_cost_mtd) }}</div>
                    <div class="cv-stat-card-sub">{{ t('From inventory consumption', 'من استهلاك المخزون') }}</div>
                </div>

                <!-- Vet + Vaccination Cost MTD -->
                <div class="cv-stat-card">
                    <div class="cv-stat-card-label">{{ t('Health Cost MTD', 'التكلفة الصحية — هذا الشهر') }}</div>
                    <div class="cv-stat-card-value" style="color:var(--cv-text-primary)">
                        {{ fmtCurrency((op.vet_cost_mtd ?? 0) + (op.vaccination_cost_mtd ?? 0)) }}
                    </div>
                    <div class="cv-stat-card-sub">
                        {{ t('Vet', 'بيطرة') }}: {{ fmtCurrency(op.vet_cost_mtd) }}
                        · {{ t('Vaccines', 'تطعيم') }}: {{ fmtCurrency(op.vaccination_cost_mtd) }}
                    </div>
                </div>

            </div>

            <!-- ══════════════════════════════════════════════════
                 SECTION 2 — FINANCIAL KPIs (role-gated)
            ══════════════════════════════════════════════════ -->
            <template v-if="can_view_reports">

                <div class="dash-section-label">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    {{ t('Financial KPIs', 'المؤشرات المالية') }}
                </div>

                <div class="dash-kpi-grid">

                    <!-- Revenue MTD -->
                    <div class="cv-stat-card cv-stat-card-green">
                        <div class="cv-stat-card-label">{{ t('Revenue MTD', 'إيرادات الشهر') }}</div>
                        <div class="cv-stat-card-value">{{ fmtCurrency(fin.revenue_mtd) }}</div>
                        <div class="cv-stat-card-sub">{{ t('YTD', 'السنة') }}: {{ fmtCurrency(fin.revenue_ytd) }}</div>
                    </div>

                    <!-- Gross Margin % MTD -->
                    <div class="cv-stat-card" :class="marginCardClass(fin.gross_margin_pct_mtd)">
                        <div class="cv-stat-card-label">{{ t('Gross Margin MTD', 'هامش الربح الإجمالي') }}</div>
                        <div class="cv-stat-card-value">{{ fmtPct(fin.gross_margin_pct_mtd) }}</div>
                        <div class="cv-stat-card-sub">
                            {{ t('Net income', 'صافي الدخل') }}: {{ fmtCurrency(fin.net_income_mtd) }}
                        </div>
                    </div>

                    <!-- Outstanding Payables -->
                    <div class="cv-stat-card" :class="(fin.overdue_payables ?? 0) > 0 ? 'cv-stat-card-red' : ''">
                        <div class="cv-stat-card-label">{{ t('Outstanding Payables', 'مستحقات الموردين') }}</div>
                        <div class="cv-stat-card-value">{{ fmtCurrency(fin.outstanding_payables) }}</div>
                        <div class="cv-stat-card-sub" v-if="(fin.overdue_payables ?? 0) > 0" style="color:var(--cv-danger-text)">
                            ⚠ {{ fmtCurrency(fin.overdue_payables) }} {{ t('overdue', 'متأخرة') }}
                        </div>
                        <div class="cv-stat-card-sub" v-else>{{ t('All current', 'كلها جارية') }}</div>
                    </div>

                    <!-- Outstanding Receivables -->
                    <div class="cv-stat-card cv-stat-card-blue">
                        <div class="cv-stat-card-label">{{ t('Outstanding Receivables', 'مستحقات العملاء') }}</div>
                        <div class="cv-stat-card-value">{{ fmtCurrency(fin.outstanding_receivables) }}</div>
                        <div class="cv-stat-card-sub">
                            {{ t('Gross margin YTD', 'هامش السنة') }}: {{ fmtPct(fin.gross_margin_pct_ytd) }}
                        </div>
                    </div>

                </div>

            </template>

            <!-- ══════════════════════════════════════════════════
                 SECTION 3 — CHARTS ROW (financial only)
            ══════════════════════════════════════════════════ -->
            <div class="dash-charts-row" v-if="can_view_reports">

                <!-- Monthly Revenue vs COGS bar chart -->
                <div class="cv-card dash-chart-card">
                    <div class="dash-chart-header">
                        <span class="cv-section-title" style="margin:0">
                            {{ t('Revenue vs COGS — YTD', 'الإيرادات مقابل التكلفة') }}
                        </span>
                        <Link :href="route('reports.profit-loss')" class="cv-btn cv-btn-ghost cv-btn-sm">
                            {{ t('P&L', 'ح.أ.خ') }}
                        </Link>
                    </div>

                    <div v-if="monthlyData.length === 0" class="cv-empty" style="padding:2rem 0">
                        <div class="cv-empty-icon">📊</div>
                        <div class="cv-empty-title">{{ t('No data yet', 'لا توجد بيانات') }}</div>
                    </div>

                    <template v-else>
                        <div class="dash-bar-chart">
                            <div
                                v-for="m in monthlyData"
                                :key="m.month"
                                class="dash-bar-group"
                                :title="`${m.label}\n${t('Revenue','إيرادات')}: ${fmtCurrency(m.revenue)}\nCOGS: ${fmtCurrency(m.cogs)}`"
                            >
                                <div class="dash-bar-pair">
                                    <div class="dash-bar dash-bar-revenue" :style="{ height: barHeight(m.revenue ?? 0) }"></div>
                                    <div class="dash-bar dash-bar-cogs"    :style="{ height: barHeight(m.cogs    ?? 0) }"></div>
                                </div>
                                <div class="dash-bar-label">{{ (m.label ?? '').slice(0, 3) }}</div>
                            </div>
                        </div>

                        <div class="dash-chart-legend">
                            <span class="dash-legend-dot" style="background:var(--cv-green-bright)"></span>
                            <span>{{ t('Revenue', 'الإيرادات') }}</span>
                            <span class="dash-legend-dot" style="background:var(--cv-danger);margin-inline-start:0.75rem"></span>
                            <span>{{ t('COGS', 'ت.ب.م') }}</span>
                        </div>
                    </template>
                </div>

                <!-- Head count by lot -->
                <div class="cv-card dash-chart-card">
                    <div class="dash-chart-header">
                        <span class="cv-section-title" style="margin:0">
                            {{ t('Active Head by Lot', 'الرأس النشطة حسب المجموعة ') }}
                        </span>
                        <Link :href="route('lots.index')" class="cv-btn cv-btn-ghost cv-btn-sm">
                            {{ t('All Lots', 'الأفواج') }}
                        </Link>
                    </div>

                    <div v-if="lotData.length === 0" class="cv-empty" style="padding:2rem 0">
                        <div class="cv-empty-icon">🏷</div>
                        <div class="cv-empty-title">{{ t('No active lots', 'لا توجد  مجموعات نشطة') }}</div>
                    </div>

                    <template v-else>
                        <div class="dash-lot-list">
                            <div v-for="lot in lotData" :key="lot.id" class="dash-lot-row">
                                <div class="dash-lot-name">{{ lot.name }}</div>
                                <div class="dash-lot-bar-wrap">
                                    <div class="dash-lot-bar" :style="{ width: lotPct(lot.head_count) + '%' }"></div>
                                </div>
                                <div class="dash-lot-count">
                                    {{ fmt(lot.head_count) }}
                                    <span class="dash-lot-pct">{{ lotPct(lot.head_count) }}%</span>
                                </div>
                            </div>
                        </div>

                        <div class="dash-lot-total" v-if="lotTotal > 0">
                            {{ t('Total', 'الإجمالي') }}: <strong>{{ fmt(lotTotal) }}</strong> {{ t('head', 'رأس') }}
                        </div>
                    </template>
                </div>

            </div>

            <!-- Lot chart for non-financial roles (still operational insight) -->
            <div class="cv-card dash-chart-card" v-if="!can_view_reports && lotData.length > 0">
                <div class="dash-chart-header">
                    <span class="cv-section-title" style="margin:0">
                        {{ t('Active Head by Lot', 'الرأس النشطة حسب المجموعة ') }}
                    </span>
                    <Link :href="route('lots.index')" class="cv-btn cv-btn-ghost cv-btn-sm">
                        {{ t('All Lots', 'الأفواج') }}
                    </Link>
                </div>
                <div class="dash-lot-list">
                    <div v-for="lot in lotData" :key="lot.id" class="dash-lot-row">
                        <div class="dash-lot-name">{{ lot.name }}</div>
                        <div class="dash-lot-bar-wrap">
                            <div class="dash-lot-bar" :style="{ width: lotPct(lot.head_count) + '%' }"></div>
                        </div>
                        <div class="dash-lot-count">
                            {{ fmt(lot.head_count) }}
                            <span class="dash-lot-pct">{{ lotPct(lot.head_count) }}%</span>
                        </div>
                    </div>
                </div>
                <div class="dash-lot-total">
                    {{ t('Total', 'الإجمالي') }}: <strong>{{ fmt(lotTotal) }}</strong> {{ t('head', 'رأس') }}
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 SECTION 4 — ALERT DETAIL CARDS
            ══════════════════════════════════════════════════ -->
            <template v-if="totalAlerts > 0">
                <div class="dash-section-label">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    {{ t('Alerts', 'التنبيهات') }}
                </div>

                <div class="dash-alert-grid">

                    <Link
                        v-if="alrt.slaughter_due_30d"
                        :href="route('lots.index')"
                        class="cv-card cv-card-stripe-amber cv-card-clickable dash-alert-card"
                    >
                        <div class="dash-alert-card-num" style="color:var(--cv-amber-text)">{{ alrt.slaughter_due_30d }}</div>
                        <div class="dash-alert-card-label">{{ t('Animals due for slaughter within 30 days', 'ماشيةات موعد ذبحها خلال 30 يومًا') }}</div>
                        <div class="dash-alert-card-action">{{ t('View Lots →', 'عرض ال مجموعات ←') }}</div>
                    </Link>

                    <Link
                        v-if="alrt.vaccinations_due_7d"
                        :href="route('vaccinations.index')"
                        class="cv-card cv-card-stripe-blue cv-card-clickable dash-alert-card"
                    >
                        <div class="dash-alert-card-num" style="color:var(--cv-blue-text)">{{ alrt.vaccinations_due_7d }}</div>
                        <div class="dash-alert-card-label">{{ t('Vaccinations due within 7 days', 'تطعيمات مستحقة خلال 7 أيام') }}</div>
                        <div class="dash-alert-card-action">{{ t('View Vaccinations →', 'عرض التطعيمات ←') }}</div>
                    </Link>

                    <Link
                        v-if="alrt.animals_in_quarantine"
                        :href="route('animals.index')"
                        class="cv-card cv-card-stripe-amber cv-card-clickable dash-alert-card"
                    >
                        <div class="dash-alert-card-num" style="color:var(--cv-amber-text)">{{ alrt.animals_in_quarantine }}</div>
                        <div class="dash-alert-card-label">{{ t('Animals in quarantine', 'ماشيةات في الحجر الصحي') }}</div>
                        <div class="dash-alert-card-action">{{ t('View Animals →', 'عرض الماشيةات ←') }}</div>
                    </Link>

                    <Link
                        v-if="alrt.withdrawal_pending"
                        :href="route('health.index')"
                        class="cv-card cv-card-stripe-amber cv-card-clickable dash-alert-card"
                    >
                        <div class="dash-alert-card-num" style="color:var(--cv-amber-text)">{{ alrt.withdrawal_pending }}</div>
                        <div class="dash-alert-card-label">{{ t('Animals with active withdrawal period', 'ماشيةات في فترة السحب') }}</div>
                        <div class="dash-alert-card-action">{{ t('View Health Records →', 'عرض السجلات الصحية ←') }}</div>
                    </Link>

                    <Link
                        v-if="alrt.overdue_ap_invoices && can_view_reports"
                        :href="route('ap-invoices.index')"
                        class="cv-card cv-card-stripe-red cv-card-clickable dash-alert-card"
                    >
                        <div class="dash-alert-card-num" style="color:var(--cv-danger-text)">{{ alrt.overdue_ap_invoices }}</div>
                        <div class="dash-alert-card-label">{{ t('Overdue supplier invoices', 'فواتير موردين متأخرة') }}</div>
                        <div class="dash-alert-card-action">{{ t('View AP Invoices →', 'عرض فواتير الموردين ←') }}</div>
                    </Link>

                </div>
            </template>

            <!-- ══════════════════════════════════════════════════
                 SECTION 5 — QUICK ACTIONS
            ══════════════════════════════════════════════════ -->
            <div class="dash-section-label">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                </svg>
                {{ t('Quick Actions', 'إجراءات سريعة') }}
            </div>

            <div class="dash-quick-grid">
                <Link :href="route('animals.create')" class="cv-card dash-quick-card">
                    <span class="dash-quick-icon">🐄</span>
                    <span class="dash-quick-label">{{ t('Add Animal', 'إضافة ماشية') }}</span>
                </Link>
                <Link :href="route('weights.index')" class="cv-card dash-quick-card">
                    <span class="dash-quick-icon">⚖</span>
                    <span class="dash-quick-label">{{ t('Record Weight', 'تسجيل وزن') }}</span>
                </Link>
                <Link :href="route('health.create')" class="cv-card dash-quick-card">
                    <span class="dash-quick-icon">💉</span>
                    <span class="dash-quick-label">{{ t('Health Record', 'سجل صحي') }}</span>
                </Link>
                <Link :href="route('feed.consumption.index')" class="cv-card dash-quick-card">
                    <span class="dash-quick-icon">🌾</span>
                    <span class="dash-quick-label">{{ t('Feed Entry', 'إدخال علف') }}</span>
                </Link>
                <Link :href="route('lots.create')" class="cv-card dash-quick-card">
                    <span class="dash-quick-icon">🏷</span>
                    <span class="dash-quick-label">{{ t('New Lot', 'مجموعة  جديد') }}</span>
                </Link>
                <Link v-if="can_view_reports" :href="route('reports.profit-loss')" class="cv-card dash-quick-card">
                    <span class="dash-quick-icon">📊</span>
                    <span class="dash-quick-label">{{ t('P&L Report', 'تقرير الأرباح') }}</span>
                </Link>
            </div>

        </div><!-- /dash-body -->
    </AuthenticatedLayout>
</template>

<style scoped>
/* ══════════════════════════════════════════════════════════════════
   DASHBOARD PAGE LAYOUT
══════════════════════════════════════════════════════════════════ */

.dash-body {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* ── Section label ───────────────────────────────────────────────── */
.dash-section-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--cv-text-muted);
    margin-bottom: -0.375rem;
}

/* ══════════════════════════════════════════════════════════════════
   ALERT BANNER STRIP
══════════════════════════════════════════════════════════════════ */
.dash-alerts-strip {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.625rem 0.875rem;
    background: var(--cv-warning-bg);
    border: 1px solid var(--cv-warning-border);
    border-radius: var(--cv-radius);
    font-size: 0.78rem;
    color: var(--cv-warning-text);
}

.dash-alerts-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-inline-start: 0.25rem;
}

.dash-alert-pill {
    font-size: 0.65rem;
    font-weight: 600;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    border: 1px solid;
    white-space: nowrap;
}
.dash-alert-pill-amber {
    background: var(--cv-warning-bg);
    color: var(--cv-warning-text);
    border-color: var(--cv-warning-border);
}
.dash-alert-pill-blue {
    background: var(--cv-info-bg);
    color: var(--cv-info-text);
    border-color: var(--cv-info-border);
}
.dash-alert-pill-red {
    background: var(--cv-danger-bg);
    color: var(--cv-danger-text);
    border-color: var(--cv-danger-border);
}

/* ══════════════════════════════════════════════════════════════════
   KPI GRID  (4-col on wide, 2-col on tablet, 1-col on mobile)
══════════════════════════════════════════════════════════════════ */
.dash-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}
@media (max-width: 1100px) { .dash-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px)  { .dash-kpi-grid { grid-template-columns: 1fr; } }

/* ══════════════════════════════════════════════════════════════════
   CHARTS ROW
══════════════════════════════════════════════════════════════════ */
.dash-charts-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 900px) { .dash-charts-row { grid-template-columns: 1fr; } }

.dash-chart-card {
    display: flex;
    flex-direction: column;
    gap: 0.875rem;
}

.dash-chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

/* ── Bar chart ─────────────────────────────────────────────────── */
.dash-bar-chart {
    display: flex;
    align-items: flex-end;
    gap: 0.375rem;
    height: 110px;
}

.dash-bar-group {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    height: 100%;
}

.dash-bar-pair {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    flex: 1;
    width: 100%;
    justify-content: center;
}

.dash-bar {
    width: 46%;
    border-radius: 3px 3px 0 0;
    transition: height 0.35s ease;
    min-height: 2px;
}
.dash-bar-revenue { background: var(--cv-green-bright); opacity: 0.82; }
.dash-bar-cogs    { background: var(--cv-danger);        opacity: 0.68; }

.dash-bar-label {
    font-size: 0.56rem;
    color: var(--cv-text-muted);
    white-space: nowrap;
}

.dash-chart-legend {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.68rem;
    color: var(--cv-text-muted);
}

.dash-legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    flex-shrink: 0;
}

/* ── Lot breakdown list ────────────────────────────────────────── */
.dash-lot-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.dash-lot-row {
    display: grid;
    grid-template-columns: 110px 1fr 64px;
    align-items: center;
    gap: 0.5rem;
}

.dash-lot-name {
    font-size: 0.75rem;
    color: var(--cv-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dash-lot-bar-wrap {
    height: 6px;
    background: var(--cv-bg-card-alt);
    border-radius: 9999px;
    overflow: hidden;
}

.dash-lot-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--cv-green), var(--cv-green-bright));
    border-radius: 9999px;
    transition: width 0.45s ease;
    min-width: 2px;
}

.dash-lot-count {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    text-align: end;
}

.dash-lot-pct {
    font-size: 0.6rem;
    color: var(--cv-text-muted);
    font-weight: 400;
    margin-inline-start: 0.2rem;
}

.dash-lot-total {
    font-size: 0.72rem;
    color: var(--cv-text-muted);
    text-align: end;
    padding-top: 0.375rem;
    border-top: 1px solid var(--cv-border);
    margin-top: 0.125rem;
}

/* ══════════════════════════════════════════════════════════════════
   ALERT DETAIL CARDS
══════════════════════════════════════════════════════════════════ */
.dash-alert-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
    gap: 0.875rem;
}

.dash-alert-card {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    text-decoration: none;
    padding: 0.875rem 1rem;
}

.dash-alert-card-num {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.02em;
}

.dash-alert-card-label {
    font-size: 0.75rem;
    color: var(--cv-text-secondary);
    line-height: 1.3;
    flex: 1;
}

.dash-alert-card-action {
    font-size: 0.65rem;
    font-weight: 600;
    color: var(--cv-text-muted);
    margin-top: 0.375rem;
}

/* ══════════════════════════════════════════════════════════════════
   QUICK ACTIONS
══════════════════════════════════════════════════════════════════ */
.dash-quick-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.75rem;
}

.dash-quick-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    text-decoration: none;
    cursor: pointer;
    text-align: center;
}

.dash-quick-card:hover {
    border-color: var(--cv-green-border);
    background: var(--cv-bg-hover);
}

.dash-quick-icon  { font-size: 1.5rem; }
.dash-quick-label {
    font-size: 0.71rem;
    font-weight: 600;
    color: var(--cv-text-secondary);
    line-height: 1.2;
}
</style>