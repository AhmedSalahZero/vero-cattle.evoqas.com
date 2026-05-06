<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO — Sales/Index.vue
//  📌 METHODOLOGY REFERENCE: ROIperLot (#20)
//  📌 METHODOLOGY REFERENCE: SaleConfirmArInvoice (#28)
//
//  CURRENT SESSION — Revenue Journal Entry
//
//  CHANGE 1: revenueAccounts prop added
//    Passed from SalesController::index() — all active posting
//    accounts of type 'revenue' for this company.
//
//  CHANGE 2: confirmSale() no longer fires immediately
//    Opening the confirm button now opens confirmModal instead.
//    The modal shows a revenue account dropdown + sale summary.
//    Auto-selects if only one account available (zero friction).
//
//  CHANGE 3: doConfirm() posts revenue_account_id with the request
//    PATCH sales.confirm → { revenue_account_id: selectedAccountId }
//    Controller validates, creates AR invoice, posts T5a + T5b JEs.
// ══════════════════════════════════════════════════════════════════
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'
defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    records:         Object,   // paginated
    farms:           Array,
    customers:       Array,
    filters:         Object,
    revenueAccounts: Array,    // ← NEW: for confirm modal dropdown
})

// ── Permissions ────────────────────────────────────────────────────
const { can } = usePermissions()
const canCreate  = computed(() => can('create sales'))
const canEdit    = computed(() => can('edit sales'))
const canConfirm = computed(() => can('edit sales'))
const canDelete  = computed(() => can('delete sales'))

// ── Filters ────────────────────────────────────────────────────────
const filterFarm     = ref(props.filters?.farm_id     ?? '')
const filterCustomer = ref(props.filters?.customer_id ?? '')
const filterStatus   = ref(props.filters?.status      ?? '')
const filterDateFrom = ref(props.filters?.date_from   ?? '')
const filterDateTo   = ref(props.filters?.date_to     ?? '')
const showFilters    = ref(false)

const hasActiveFilters = computed(() =>
    filterFarm.value || filterCustomer.value || filterStatus.value ||
    filterDateFrom.value || filterDateTo.value
)

function applyFilters() {
    router.get(route('sales.index'), {
        farm_id:     filterFarm.value     || undefined,
        customer_id: filterCustomer.value || undefined,
        status:      filterStatus.value   || undefined,
        date_from:   filterDateFrom.value || undefined,
        date_to:     filterDateTo.value   || undefined,
    }, { preserveState: true, replace: true })
}
function clearFilters() {
    filterFarm.value = ''; filterCustomer.value = ''
    filterStatus.value = ''; filterDateFrom.value = ''; filterDateTo.value = ''
    applyFilters()
}

// ── KPI totals (current page) ──────────────────────────────────────
const rows = computed(() => props.records?.data ?? [])

const kpiTotal     = computed(() => props.records?.meta?.total ?? rows.value.length)
const kpiConfirmed = computed(() => rows.value.filter(r => ['confirmed','partially_paid','paid'].includes(r.status)).length)
const kpiRevenue   = computed(() => rows.value
    .filter(r => ['confirmed','partially_paid','paid'].includes(r.status))
    .reduce((s, r) => s + parseFloat(r.total_amount || 0), 0))
const kpiDraft     = computed(() => rows.value.filter(r => r.status === 'draft').length)

// ── Status config ──────────────────────────────────────────────────
const STATUS = {
    draft:          { badge: 'cv-badge-warning', ar: 'مسودة',        en: 'Draft' },
    confirmed:      { badge: 'cv-badge-success', ar: 'مؤكد',         en: 'Confirmed' },
    partially_paid: { badge: 'cv-badge-warning', ar: 'مدفوع جزئياً', en: 'Part. Paid' },
    paid:           { badge: 'cv-badge-info',    ar: 'مدفوع',        en: 'Paid' },
    cancelled:      { badge: 'cv-badge-danger',  ar: 'ملغي',         en: 'Cancelled' },
}
const statusLabel = s => STATUS[s]?.[lang.value] ?? s
const statusBadge = s => STATUS[s]?.badge ?? 'cv-badge-muted'

// ══════════════════════════════════════════════════════════════════
//  CONFIRM MODAL — Revenue Account Selection
// ══════════════════════════════════════════════════════════════════

const confirmModal      = ref(false)
const confirmingRecord  = ref(null)
const confirmAccountId  = ref(null)
const confirmProcessing = ref(false)
const confirmError      = ref('')

// Open the confirm modal for a specific sale row
function openConfirm(rec) {
    confirmingRecord.value  = rec
    confirmError.value      = ''
    confirmProcessing.value = false

    // Auto-select if only one revenue account available
    const accounts = props.revenueAccounts ?? []
    confirmAccountId.value = accounts.length === 1 ? accounts[0].id : null

    confirmModal.value = true
}

function closeConfirmModal() {
    confirmModal.value     = false
    confirmingRecord.value = null
    confirmAccountId.value = null
    confirmError.value     = ''
}

// Submit the confirmation with the selected revenue account
function doConfirm() {
    if (!confirmAccountId.value) {
        confirmError.value = lang.value === 'ar'
            ? 'الرجاء اختيار حساب الإيرادات أولاً'
            : 'Please select a revenue account first'
        return
    }

    confirmProcessing.value = true
    confirmError.value      = ''

    router.patch(
        route('sales.confirm', confirmingRecord.value.id),
        { revenue_account_id: confirmAccountId.value },
        {
            onSuccess: () => { closeConfirmModal() },
            onError:   (errors) => {
                confirmError.value = errors.revenue_account_id
                    ?? errors.message
                    ?? (lang.value === 'ar' ? 'حدث خطأ، يرجى المحاولة مرة أخرى' : 'An error occurred, please try again')
            },
            onFinish: () => { confirmProcessing.value = false },
        }
    )
}

// ── Delete modal ───────────────────────────────────────────────────
const cancelModal      = ref(false)
const cancellingRecord = ref(null)
const cancelProcessing = ref(false)

const deleteModal      = ref(false)
const deletingRecord   = ref(null)
const deleteProcessing = ref(false)

function openDelete(rec) { deletingRecord.value = rec; deleteModal.value = true }
function openCancel(rec) { cancellingRecord.value = rec; cancelModal.value = true }
function closeCancelModal() { cancelModal.value = false; cancellingRecord.value = null }
function doCancel() {
    cancelProcessing.value = true
    router.post(route('sales.cancel', cancellingRecord.value.id), {}, {
        onSuccess: () => { closeCancelModal() },
        onFinish:  () => { cancelProcessing.value = false },
    })
}

// ── Delete modal
function doDelete() {
    deleteProcessing.value = true
    router.delete(route('sales.destroy', deletingRecord.value.id), {
        onSuccess: () => { deleteModal.value = false; deletingRecord.value = null },
        onFinish:  () => { deleteProcessing.value = false },
    })
}

// ── Formatters ─────────────────────────────────────────────────────
const locale = computed(() => lang.value === 'ar' ? 'ar-EG' : 'en-GB')

function fmtMoney(val) {
    if (val == null) return '—'
    return Number(val).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(locale.value, { year: 'numeric', month: 'short', day: 'numeric' })
}

function isDue(rec) {
    if (!rec.due_date || rec.status === 'paid') return false
    return new Date(rec.due_date) < new Date()
}

// Revenue account display helper
function accountLabel(acc) {
    const name = lang.value === 'ar' ? (acc.name_ar || acc.name_en) : (acc.name_en || acc.name_ar)
    return acc.code + ' — ' + name
}
</script>

<template>
    
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- Flash -->
            <div v-if="$page.props.flash?.success" class="cv-alert-success" style="margin:1rem 1.5rem 0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;flex-shrink:0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.warning" class="cv-alert-warning" style="margin:1rem 1.5rem 0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                {{ $page.props.flash.warning }}
            </div>

            <!-- ══════════════════════════════════════════════════════
                 HERO
            ══════════════════════════════════════════════════════ -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ lang === 'ar' ? 'الإيرادات' : 'Revenue' }}</div>
                <div class="cv-page-hero-title">{{ lang === 'ar' ? 'سجلات المبيعات' : 'Sales Records' }}</div>
                <div class="cv-page-hero-sub">
                    {{ lang === 'ar'
                        ? 'فواتير بيع الماشية — مرتبة من الأحدث إلى الأقدم'
                        : 'Livestock sale invoices — newest first' }}
                </div>
                <div class="cv-page-hero-actions">
                    <Link v-if="canCreate" :href="route('sales.create')" class="cv-btn cv-btn-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        {{ lang === 'ar' ? 'بيع جديد' : 'New Sale' }}
                    </Link>
                    <button @click="showFilters = !showFilters" class="cv-btn cv-btn-ghost"
                        :style="hasActiveFilters ? 'color:var(--cv-amber);border-color:var(--cv-amber-border);' : ''">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        {{ lang === 'ar' ? 'تصفية' : 'Filter' }}
                        <span v-if="hasActiveFilters" class="filter-dot"></span>
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- ══════════════════════════════════════════════
                     KPI STRIP
                ══════════════════════════════════════════════ -->
                <div class="kpi-strip">
                    <div class="kpi-card">
                        <div class="kpi-label">{{ lang === 'ar' ? 'إجمالي السجلات' : 'Total Records' }}</div>
                        <div class="kpi-value" style="color:var(--cv-blue-text);">{{ kpiTotal }}</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">{{ lang === 'ar' ? 'مؤكد / مدفوع' : 'Confirmed / Paid' }}</div>
                        <div class="kpi-value" style="color:var(--cv-green-bright);">{{ kpiConfirmed }}</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">{{ lang === 'ar' ? 'مسودة' : 'Draft' }}</div>
                        <div class="kpi-value" style="color:var(--cv-amber);">{{ kpiDraft }}</div>
                    </div>
                    <div class="kpi-card" style="min-width:220px;">
                        <div class="kpi-label">{{ lang === 'ar' ? 'إيرادات الصفحة (مؤكد+مدفوع)' : 'Page Revenue (confirmed + paid)' }}</div>
                        <div class="kpi-value" style="color:var(--cv-amber);font-size:1rem;">
                            {{ fmtMoney(kpiRevenue) }}
                            <span style="font-size:0.7rem;opacity:0.6;margin-inline-start:3px;">EGP</span>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════
                     FILTER PANEL
                ══════════════════════════════════════════════ -->
                <Transition name="slide-down">
                    <div v-if="showFilters" class="cv-card" style="margin-bottom:1rem;">
                        <div class="filter-grid">
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</label>
                                <select v-model="filterFarm" class="cv-select" @change="applyFilters">
                                    <option value="">{{ lang === 'ar' ? 'كل المزارع' : 'All Farms' }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                                </select>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'العميل' : 'Customer' }}</label>
                                <select v-model="filterCustomer" class="cv-select" @change="applyFilters">
                                    <option value="">{{ lang === 'ar' ? 'كل العملاء' : 'All Customers' }}</option>
                                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ n(c,'name') }}</option>
                                </select>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'الحالة' : 'Status' }}</label>
                                <select v-model="filterStatus" class="cv-select" @change="applyFilters">
                                    <option value="">{{ lang === 'ar' ? 'كل الحالات' : 'All Statuses' }}</option>
                                    <option value="draft">{{ lang === 'ar' ? 'مسودة' : 'Draft' }}</option>
                                    <option value="confirmed">{{ lang === 'ar' ? 'مؤكد' : 'Confirmed' }}</option>
                                    <option value="partially_paid">{{ lang === 'ar' ? 'مدفوع جزئياً' : 'Partially Paid' }}</option>
                                    <option value="paid">{{ lang === 'ar' ? 'مدفوع' : 'Paid' }}</option>
                                    <option value="cancelled">{{ lang === 'ar' ? 'ملغي' : 'Cancelled' }}</option>
                                </select>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'Date From' }}</label>
                                <input type="date" v-model="filterDateFrom" class="cv-input" @change="applyFilters" />
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'Date To' }}</label>
                                <input type="date" v-model="filterDateTo" class="cv-input" @change="applyFilters" />
                            </div>
                            <div class="cv-field" style="display:flex;align-items:flex-end;">
                                <button v-if="hasActiveFilters" @click="clearFilters" class="cv-btn cv-btn-ghost"
                                    style="width:100%;justify-content:center;color:var(--cv-danger-text);border-color:var(--cv-danger-border);">
                                    {{ lang === 'ar' ? 'مسح الفلاتر' : 'Clear Filters' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>

                <!-- ══════════════════════════════════════════════
                     TABLE
                ══════════════════════════════════════════════ -->
                <div v-if="rows.length" class="cv-card" style="padding:0;overflow:hidden;">
                    <div class="table-wrap">
                        <table class="sales-table">
                            <thead>
                                <tr>
                                    <th>{{ lang === 'ar' ? 'الفاتورة' : 'Invoice' }}</th>
                                    <th>
                                        <span style="display:inline-flex;align-items:center;gap:4px;">
                                            {{ lang === 'ar' ? 'التاريخ' : 'Date' }}
                                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-green-bright)" stroke-width="2.5"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                style="width:10px;height:10px;">
                                                <line x1="12" y1="5" x2="12" y2="19"/>
                                                <polyline points="19 12 12 19 5 12"/>
                                            </svg>
                                        </span>
                                    </th>
                                    <th>{{ lang === 'ar' ? 'العميل' : 'Customer' }}</th>
                                    <th>{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</th>
                                    <th class="col-num">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                    <th class="col-num">{{ lang === 'ar' ? 'الخصم' : 'Disc.' }}</th>
                                    <th>{{ lang === 'ar' ? 'الاستحقاق' : 'Due Date' }}</th>
                                    <th style="text-align:center;">{{ lang === 'ar' ? 'البنود' : 'Lines' }}</th>
                                    <th>{{ lang === 'ar' ? 'الحالة' : 'Status' }}</th>
                                    <th style="text-align:center;">{{ lang === 'ar' ? 'إجراءات' : 'Actions' }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="rec in rows"
                                    :key="rec.id"
                                    class="table-row"
                                    @click="router.visit(route('sales.show', rec.id))"
                                >
                                    <td>
                                        <span class="invoice-ref">{{ rec.invoice_ref || ('# ' + rec.id) }}</span>
                                    </td>
                                    <td>
                                        <span style="color:var(--cv-text-secondary);font-size:0.8125rem;white-space:nowrap;">
                                            {{ fmtDate(rec.date) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color:var(--cv-text-primary);font-size:0.8125rem;">
                                            {{ n(rec.customer, 'name') || '—' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color:var(--cv-text-muted);font-size:0.8rem;">
                                            {{ n(rec.farm, 'name') || '—' }}
                                        </span>
                                    </td>
                                    <td class="col-num">
                                        <span style="color:var(--cv-amber);font-weight:700;font-variant-numeric:tabular-nums;font-size:0.8125rem;white-space:nowrap;">
                                            {{ fmtMoney(rec.total_amount) }}
                                        </span>
                                    </td>
                                    <td class="col-num">
                                        <span :style="rec.discount > 0 ? 'color:var(--cv-danger-text);' : 'color:var(--cv-text-muted);opacity:0.5;'"
                                            style="font-size:0.8rem;white-space:nowrap;">
                                            {{ rec.discount > 0 ? ('− ' + fmtMoney(rec.discount)) : '—' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span v-if="rec.due_date"
                                            :style="isDue(rec) ? 'color:var(--cv-danger-text);font-weight:600;' : 'color:var(--cv-text-muted);'"
                                            style="font-size:0.8rem;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
                                            {{ fmtDate(rec.due_date) }}
                                            <span v-if="isDue(rec)" class="cv-badge cv-badge-danger"
                                                style="font-size:0.6rem;padding:1px 5px;">
                                                {{ lang === 'ar' ? 'متأخر' : 'Overdue' }}
                                            </span>
                                        </span>
                                        <span v-else style="color:var(--cv-text-muted);font-size:0.8rem;opacity:0.5;">—</span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="lines-pill">{{ rec.lines_count ?? 0 }}</span>
                                    </td>
                                    <td>
                                        <span class="cv-badge" :class="statusBadge(rec.status)">
                                            {{ statusLabel(rec.status) }}
                                        </span>
                                    </td>
                                    <!-- Actions — @click.stop prevents row navigation -->
                                    <td @click.stop style="text-align:center;">
                                        <div class="action-cell">
                                            <!-- View -->
                                            <Link :href="route('sales.show', rec.id)"
                                                class="action-btn"
                                                :title="lang === 'ar' ? 'عرض التفاصيل' : 'View Details'">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </Link>
                                            <!-- Edit — draft only -->
                                            <Link v-if="canEdit && rec.status === 'draft'"
                                                :href="route('sales.edit', rec.id)"
                                                class="action-btn"
                                                :title="lang === 'ar' ? 'تعديل' : 'Edit'">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </Link>
                                            <!-- Confirm — draft only → opens modal -->
                                            <button v-if="canConfirm && rec.status === 'draft'"
                                                @click="openConfirm(rec)"
                                                class="action-btn action-btn-green"
                                                :title="lang === 'ar' ? 'تأكيد البيع' : 'Confirm Sale'">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                            </button>
                                            <!-- Cancel confirmed — no collections guard is server-side -->
                                            <button v-if="canConfirm && rec.status === 'confirmed'"
                                                @click="openCancel(rec)"
                                                class="action-btn action-btn-danger"
                                                :title="lang === 'ar' ? 'إلغاء / عكس البيع' : 'Cancel / Reverse Sale'">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                            </button>
                                            <!-- Delete — draft only -->
                                            <button v-if="canDelete && rec.status === 'draft'"
                                                @click="openDelete(rec)"
                                                class="action-btn action-btn-danger"
                                                :title="lang === 'ar' ? 'حذف' : 'Delete'">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Table footer -->
                    <div class="table-footer">
                        <span style="color:var(--cv-text-muted);font-size:0.75rem;">
                            {{ lang === 'ar'
                                ? `عرض ${rows.length} من ${records.meta?.total ?? rows.length} سجل`
                                : `Showing ${rows.length} of ${records.meta?.total ?? rows.length} records` }}
                        </span>
                        <div v-if="records.meta?.last_page > 1" class="cv-pagination">
                            <template v-for="link in records.meta.links" :key="link.label">
                                <button
                                    class="cv-page-btn"
                                    :class="{ active: link.active }"
                                    :disabled="!link.url"
                                    @click="link.url && router.get(link.url)"
                                    v-html="link.label"
                                />
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else class="cv-card">
                    <div class="cv-empty">
                        <div class="cv-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" style="width:52px;height:52px;opacity:0.18;"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        </div>
                        <div class="cv-empty-title">
                            {{ hasActiveFilters
                                ? (lang === 'ar' ? 'لا توجد نتائج لهذه الفلاتر' : 'No results for these filters')
                                : (lang === 'ar' ? 'لا توجد مبيعات مسجلة' : 'No sales records yet') }}
                        </div>
                        <div class="cv-empty-sub">
                            {{ hasActiveFilters
                                ? (lang === 'ar' ? 'جرب تغيير أو مسح الفلاتر' : 'Try changing or clearing filters')
                                : (lang === 'ar' ? 'ابدأ بتسجيل أول عملية بيع' : 'Start by creating the first sale') }}
                        </div>
                        <div style="margin-top:1.25rem;display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;">
                            <button v-if="hasActiveFilters" @click="clearFilters" class="cv-btn cv-btn-ghost">
                                {{ lang === 'ar' ? 'مسح الفلاتر' : 'Clear Filters' }}
                            </button>
                            <Link v-if="canCreate && !hasActiveFilters" :href="route('sales.create')" class="cv-btn cv-btn-amber">
                                {{ lang === 'ar' ? 'بيع جديد' : 'New Sale' }}
                            </Link>
                        </div>
                    </div>
                </div>

            </div><!-- /cv-page -->

            <!-- ══════════════════════════════════════════════════════
                 CONFIRM MODAL — Revenue Account Selection
            ══════════════════════════════════════════════════════ -->
            <Teleport to="body">
                <Transition name="modal-fade">
                    <div v-if="confirmModal" class="cv-modal-backdrop" @click.self="closeConfirmModal">
                        <div class="cv-modal cv-modal-sm">

                            <!-- Header -->
                            <div class="cv-modal-header">
                                <div style="display:flex;align-items:center;gap:0.75rem;">
                                    <div style="width:36px;height:36px;border-radius:50%;background:var(--cv-green-dim);border:1px solid var(--cv-green-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-green-bright)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    </div>
                                    <span style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary);">
                                        {{ lang === 'ar' ? 'تأكيد عملية البيع' : 'Confirm Sale' }}
                                    </span>
                                </div>
                                <button @click="closeConfirmModal" class="cv-modal-close">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>

                            <!-- Body -->
                            <div class="cv-modal-body">

                                <!-- Sale summary strip -->
                                <div class="confirm-summary">
                                    <div class="confirm-summary-row">
                                        <span class="confirm-summary-label">
                                            {{ lang === 'ar' ? 'الفاتورة' : 'Invoice' }}
                                        </span>
                                        <span class="confirm-summary-value invoice-ref">
                                            {{ confirmingRecord?.invoice_ref || ('# ' + confirmingRecord?.id) }}
                                        </span>
                                    </div>
                                    <div class="confirm-summary-row">
                                        <span class="confirm-summary-label">
                                            {{ lang === 'ar' ? 'العميل' : 'Customer' }}
                                        </span>
                                        <span class="confirm-summary-value">
                                            {{ n(confirmingRecord?.customer, 'name') || '—' }}
                                        </span>
                                    </div>
                                    <div class="confirm-summary-row">
                                        <span class="confirm-summary-label">
                                            {{ lang === 'ar' ? 'الإجمالي' : 'Total' }}
                                        </span>
                                        <span class="confirm-summary-value" style="color:var(--cv-amber);font-weight:700;">
                                            {{ fmtMoney(confirmingRecord?.total_amount) }}
                                            <span style="font-size:0.7rem;opacity:0.6;margin-inline-start:3px;">EGP</span>
                                        </span>
                                    </div>
                                </div>

                                <!-- Divider -->
                                <div style="border-top:1px solid var(--cv-border);margin:1rem 0;"></div>

                                <!-- Revenue account selector -->
                                <div class="cv-field">
                                    <label class="cv-label" style="color:var(--cv-amber);font-weight:700;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;display:inline;margin-inline-end:4px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                        {{ lang === 'ar' ? 'حساب الإيرادات *' : 'Revenue Account *' }}
                                    </label>

                                    <!-- No accounts available -->
                                    <div v-if="!revenueAccounts || revenueAccounts.length === 0"
                                        class="cv-alert-danger" style="font-size:0.8125rem;margin-top:0.25rem;">
                                        {{ lang === 'ar'
                                            ? 'لا توجد حسابات إيرادات متاحة. يرجى إضافة حسابات إيرادات في دليل الحسابات أولاً.'
                                            : 'No revenue accounts available. Please add revenue accounts in your Chart of Accounts first.' }}
                                    </div>

                                    <!-- Single account — show as read-only info -->
                                    <div v-else-if="revenueAccounts.length === 1" class="single-account-display">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-green-bright)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                        <span>{{ accountLabel(revenueAccounts[0]) }}</span>
                                        <span class="auto-badge">
                                            {{ lang === 'ar' ? 'تلقائي' : 'Auto' }}
                                        </span>
                                    </div>

                                    <!-- Multiple accounts — dropdown -->
                                    <select v-else v-model="confirmAccountId" class="cv-select" style="margin-top:0.35rem;">
                                        <option :value="null" disabled>
                                            {{ lang === 'ar' ? '— اختر حساب الإيرادات —' : '— Select revenue account —' }}
                                        </option>
                                        <option v-for="acc in revenueAccounts" :key="acc.id" :value="acc.id">
                                            {{ accountLabel(acc) }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Helper text -->
                                <p v-if="revenueAccounts && revenueAccounts.length > 0"
                                    style="font-size:0.775rem;color:var(--cv-text-muted);margin-top:0.5rem;line-height:1.5;">
                                    {{ lang === 'ar'
                                        ? 'سيتم ترحيل القيد المحاسبي إلى الحساب المختار تلقائياً عند التأكيد.'
                                        : 'A journal entry will be posted to the selected account automatically on confirmation.' }}
                                </p>

                                <!-- Error message -->
                                <div v-if="confirmError" class="cv-alert-danger" style="margin-top:0.75rem;font-size:0.8125rem;">
                                    {{ confirmError }}
                                </div>

                                <!-- Warning: no accounts = JE will not post -->
                                <div v-if="revenueAccounts && revenueAccounts.length === 0"
                                    class="cv-alert-warning" style="margin-top:0.75rem;font-size:0.8125rem;">
                                    {{ lang === 'ar'
                                        ? 'سيتم تأكيد البيع وإنشاء الفاتورة، لكن لن يتم ترحيل أي قيد محاسبي.'
                                        : 'The sale will be confirmed and AR invoice created, but no journal entry will be posted.' }}
                                </div>

                            </div>

                            <!-- Footer -->
                            <div class="cv-modal-footer">
                                <button @click="closeConfirmModal" class="cv-btn cv-btn-ghost" style="font-size:0.875rem;">
                                    {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                                </button>
                                <button
                                    @click="doConfirm"
                                    :disabled="confirmProcessing || !revenueAccounts || revenueAccounts.length === 0"
                                    class="cv-btn cv-btn-amber"
                                    style="font-size:0.875rem;min-width:120px;">
                                    <svg v-if="confirmProcessing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;animation:spin .8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    {{ confirmProcessing
                                        ? (lang === 'ar' ? 'جاري التأكيد...' : 'Confirming...')
                                        : (lang === 'ar' ? 'تأكيد البيع' : 'Confirm Sale') }}
                                </button>
                            </div>

                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- CANCEL / REVERSE MODAL -->
            <Teleport to="body">
                <Transition name="modal-fade">
                    <div v-if="cancelModal" class="cv-modal-backdrop" @click.self="closeCancelModal">
                        <div class="cv-modal cv-modal-sm">
                            <div class="cv-modal-header">
                                <div style="display:flex;align-items:center;gap:0.75rem;">
                                    <div style="width:36px;height:36px;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                    </div>
                                    <span style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary);">
                                        {{ lang === 'ar' ? 'إلغاء وعكس البيع' : 'Cancel and Reverse Sale' }}
                                    </span>
                                </div>
                                <button @click="closeCancelModal" class="cv-modal-close">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <div class="cv-modal-body" style="font-size:0.875rem;color:var(--cv-text-secondary);line-height:1.6;">
                                <div class="confirm-summary" style="margin-bottom:1rem;">
                                    <div class="confirm-summary-row">
                                        <span class="confirm-summary-label">{{ lang === 'ar' ? 'الفاتورة' : 'Invoice' }}</span>
                                        <span class="confirm-summary-value invoice-ref">{{ cancellingRecord?.invoice_ref || ('# ' + cancellingRecord?.id) }}</span>
                                    </div>
                                    <div class="confirm-summary-row">
                                        <span class="confirm-summary-label">{{ lang === 'ar' ? 'العميل' : 'Customer' }}</span>
                                        <span class="confirm-summary-value">{{ n(cancellingRecord?.customer, 'name') || '---' }}</span>
                                    </div>
                                    <div class="confirm-summary-row">
                                        <span class="confirm-summary-label">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</span>
                                        <span class="confirm-summary-value" style="color:var(--cv-amber);font-weight:700;">
                                            {{ fmtMoney(cancellingRecord?.total_amount) }}
                                            <span style="font-size:0.7rem;opacity:0.6;margin-inline-start:3px;">EGP</span>
                                        </span>
                                    </div>
                                </div>
                                <div style="background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);border-radius:var(--cv-radius-md);padding:0.75rem 1rem;margin-bottom:0.875rem;">
                                    <div style="font-size:0.7rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:0.5rem;">
                                        {{ lang === 'ar' ? 'ما سيحدث' : 'What will happen' }}
                                    </div>
                                    <ul style="margin:0;padding-inline-start:1.1rem;font-size:0.8125rem;display:flex;flex-direction:column;gap:0.3rem;">
                                        <li>{{ lang === 'ar' ? 'عكس قيود الإيرادات والمدينين' : 'Revenue and AR journal entries reversed' }}</li>
                                        <li>{{ lang === 'ar' ? 'عكس قيود تكلفة البضاعة إن وجدت' : 'COGS entries reversed if posted' }}</li>
                                        <li>{{ lang === 'ar' ? 'إلغاء فاتورة الحسابات المدينة' : 'AR invoice cancelled' }}</li>
                                        <li>{{ lang === 'ar' ? 'إعادة الحيوانات إلى حالة نشط' : 'Animals returned to active status' }}</li>
                                    </ul>
                                </div>
                                <div class="cv-alert-danger" style="font-size:0.8125rem;">
                                    {{ lang === 'ar'
                                        ? 'متاح فقط إذا لم يتم تسجيل أي تحصيل على الفاتورة.'
                                        : 'Only allowed if no collections have been recorded against this invoice.' }}
                                </div>
                            </div>
                            <div class="cv-modal-footer">
                                <button @click="closeCancelModal" class="cv-btn cv-btn-ghost" style="font-size:0.875rem;">
                                    {{ lang === 'ar' ? 'تراجع' : 'Go Back' }}
                                </button>
                                <button @click="doCancel" :disabled="cancelProcessing" class="cv-btn cv-btn-danger" style="font-size:0.875rem;min-width:140px;">
                                    <svg v-if="cancelProcessing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;animation:spin .8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                    {{ cancelProcessing
                                        ? (lang === 'ar' ? 'جاري الإلغاء...' : 'Cancelling...')
                                        : (lang === 'ar' ? 'تأكيد الإلغاء والعكس' : 'Confirm Cancel and Reverse') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- DELETE MODAL -->
            <Teleport to="body">
                <Transition name="modal-fade">
                    <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
                        <div class="cv-modal cv-modal-sm">
                            <div class="cv-modal-header">
                                <div style="display:flex;align-items:center;gap:0.75rem;">
                                    <div style="width:36px;height:36px;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    </div>
                                    <span style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary);">
                                        {{ lang === 'ar' ? 'حذف سجل البيع' : 'Delete Sale Record' }}
                                    </span>
                                </div>
                                <button @click="deleteModal = false" class="cv-modal-close">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <div class="cv-modal-body" style="font-size:0.875rem;color:var(--cv-text-secondary);line-height:1.6;">
                                <p>
                                    {{ lang === 'ar' ? 'سيتم حذف الفاتورة' : 'Invoice' }}
                                    <strong style="color:var(--cv-text-primary);">
                                        {{ deletingRecord?.invoice_ref || ('# ' + deletingRecord?.id) }}
                                    </strong>
                                    {{ lang === 'ar' ? 'وجميع بنودها نهائياً.' : 'and all its lines will be permanently deleted.' }}
                                </p>
                                <div class="cv-alert-danger" style="margin-top:0.75rem;font-size:0.8125rem;">
                                    {{ lang === 'ar' ? '⚠ لا يمكن التراجع عن هذا الإجراء.' : '⚠ This action cannot be undone.' }}
                                </div>
                            </div>
                            <div class="cv-modal-footer">
                                <button @click="deleteModal = false" class="cv-btn cv-btn-ghost" style="font-size:0.875rem;">
                                    {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                                </button>
                                <button @click="doDelete" :disabled="deleteProcessing" class="cv-btn cv-btn-danger" style="font-size:0.875rem;">
                                    <svg v-if="deleteProcessing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;animation:spin .8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    {{ lang === 'ar' ? 'حذف نهائياً' : 'Delete Permanently' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

        </div>
    
</template>

<style scoped>
/* ── KPI strip ───────────────────────────────────────────── */
.kpi-strip {
    display: flex; gap: 0.875rem; flex-wrap: wrap; margin-bottom: 1.125rem;
}
.kpi-card {
    flex: 1; min-width: 120px;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: var(--cv-radius-md);
    padding: 0.875rem 1.125rem;
}
.kpi-label {
    font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.07em;
    text-transform: uppercase; color: var(--cv-text-muted); margin-bottom: 0.3rem;
}
.kpi-value { font-size: 1.375rem; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1.2; }

/* ── Filter dot ──────────────────────────────────────────── */
.filter-dot {
    display: inline-block; width: 7px; height: 7px;
    border-radius: 50%; background: var(--cv-amber);
    margin-inline-start: 2px; flex-shrink: 0;
}

/* ── Filter grid ─────────────────────────────────────────── */
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.875rem;
}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }

.sales-table {
    width: 100%; border-collapse: collapse; font-size: 0.8125rem;
}
.sales-table thead tr {
    border-bottom: 1px solid var(--cv-border-md);
}
.sales-table th {
    padding: 0.625rem 0.875rem; text-align: start;
    font-size: 0.6875rem; font-weight: 700;
    letter-spacing: 0.07em; text-transform: uppercase;
    color: var(--cv-text-muted); white-space: nowrap;
    background: var(--cv-bg-card-alt);
}
.sales-table th.col-num { text-align: end; }

.sales-table tbody tr {
    border-bottom: 1px solid var(--cv-border);
    cursor: pointer;
    transition: background 0.1s ease;
}
.sales-table tbody tr:last-child { border-bottom: none; }
.sales-table tbody tr:hover      { background: var(--cv-bg-hover); }

.sales-table td {
    padding: 0.625rem 0.875rem;
    vertical-align: middle;
}
.sales-table td.col-num { text-align: end; }

/* ── Invoice ref ─────────────────────────────────────────── */
.invoice-ref {
    font-family: monospace; font-size: 0.8rem; font-weight: 700;
    color: var(--cv-blue-text); letter-spacing: 0.04em;
}

/* ── Lines pill ──────────────────────────────────────────── */
.lines-pill {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; height: 22px; padding: 0 6px;
    border-radius: 11px; font-size: 0.7rem; font-weight: 700;
    background: var(--cv-blue-light); border: 1px solid var(--cv-blue-border);
    color: var(--cv-blue-text);
}

/* ── Action cell ─────────────────────────────────────────── */
.action-cell {
    display: inline-flex; align-items: center; justify-content: center; gap: 4px;
}
.action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: var(--cv-radius-sm);
    border: 1px solid var(--cv-border); background: transparent;
    color: var(--cv-text-muted); cursor: pointer;
    transition: var(--cv-transition); text-decoration: none; flex-shrink: 0;
}
.action-btn:hover              { background: var(--cv-bg-hover); color: var(--cv-text-primary); border-color: var(--cv-border-md); }
.action-btn-green:hover        { background: var(--cv-green-dim);  color: var(--cv-green-bright); border-color: var(--cv-green-border); }
.action-btn-danger:hover       { background: var(--cv-danger-bg);  color: var(--cv-danger-text);  border-color: var(--cv-danger-border); }
.action-btn:disabled           { opacity: 0.4; cursor: not-allowed; }

/* ── Table footer ────────────────────────────────────────── */
.table-footer {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 0.5rem;
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--cv-border);
    background: var(--cv-bg-card-alt);
}

/* ── Confirm modal — sale summary strip ─────────────────── */
.confirm-summary {
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border);
    border-radius: var(--cv-radius-md);
    padding: 0.75rem 1rem;
    display: flex; flex-direction: column; gap: 0.45rem;
}
.confirm-summary-row {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.confirm-summary-label {
    font-size: 0.75rem; font-weight: 600; letter-spacing: 0.06em;
    text-transform: uppercase; color: var(--cv-text-muted); flex-shrink: 0;
}
.confirm-summary-value {
    font-size: 0.8125rem; color: var(--cv-text-primary); text-align: end;
}

/* ── Single account auto-select display ─────────────────── */
.single-account-display {
    display: flex; align-items: center; gap: 0.5rem;
    background: var(--cv-green-dim);
    border: 1px solid var(--cv-green-border);
    border-radius: var(--cv-radius-sm);
    padding: 0.5rem 0.75rem;
    font-size: 0.8125rem; color: var(--cv-text-primary);
    margin-top: 0.35rem;
}
.auto-badge {
    margin-inline-start: auto; flex-shrink: 0;
    font-size: 0.65rem; font-weight: 700; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--cv-green-bright);
    background: transparent;
    border: 1px solid var(--cv-green-border);
    border-radius: 4px; padding: 1px 6px;
}

/* ── Transitions ─────────────────────────────────────────── */
.slide-down-enter-active { transition: all 0.2s ease; }
.slide-down-leave-active { transition: all 0.15s ease; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-6px); }

.modal-fade-enter-active { transition: opacity 0.2s ease; }
.modal-fade-leave-active { transition: opacity 0.15s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }

@keyframes spin { to { transform: rotate(360deg); } }
</style>