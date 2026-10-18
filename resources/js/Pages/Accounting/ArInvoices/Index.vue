<template>
  
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- Hero -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ t.title }}</div>
      <div class="cv-page-hero-sub">{{ t.sub }}</div>
      <div class="cv-page-hero-actions">
        <Link v-if="canCreate" :href="route('ar-invoices.create')" class="cv-btn cv-btn-amber cv-btn-sm">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          {{ t.newInvoice }}
        </Link>
      </div>
    </div>

    <div class="cv-page">

      <!-- KPI Strip -->
      <div class="grid gap-3 mb-5" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <div class="cv-stat-card cv-stat-card-green">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiTotal }}</div>
          <div style="font-size:1.35rem;font-weight:700;color:var(--cv-green-bright)">{{ invoices.total }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-blue">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiOpen }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-blue-text)">{{ fmt(aging.current + aging['1_30']) }}</div>
          <div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.egp }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-amber">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiOverdue }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-amber-text)">{{ fmt(aging['31_60'] + aging['61_90'] + aging['over_90']) }}</div>
          <div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.egp }}</div>
        </div>
        <div class="cv-stat-card" style="border-top:2px solid var(--cv-danger)">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiCritical }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-danger-text)">{{ fmt(aging['over_90']) }}</div>
          <div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.egp }}</div>
        </div>
      </div>

      <!-- Aging Panel -->
      <div class="cv-card mb-5" style="padding:1rem 1.25rem">
        <div style="font-size:0.68rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--cv-green-bright);margin-bottom:0.75rem">
          {{ t.agingTitle }}
        </div>
        <div class="flex flex-wrap gap-3">
          <div v-for="b in agingBuckets" :key="b.key"
            style="flex:1;min-width:108px;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);border-radius:var(--cv-radius);padding:0.6rem 0.875rem">
            <div style="font-size:0.63rem;color:var(--cv-text-muted);margin-bottom:0.12rem">{{ b.label }}</div>
            <div :style="`font-size:0.92rem;font-weight:700;color:${b.color}`">{{ fmt(aging[b.key]) }}</div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="cv-card mb-4" style="padding:0.875rem 1.25rem">
        <div class="flex flex-wrap gap-3 items-end">
          <div v-if="isSuperAdmin" class="cv-field" style="margin:0;min-width:155px">
            <label class="cv-label">{{ t.filterCompany }}</label>
            <select v-model="af.company_id" class="cv-select cv-select-sm" @change="applyFilters">
              <option value="">{{ t.allCompanies }}</option>
              <option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c,'name') }}</option>
            </select>
          </div>
          <div class="cv-field" style="margin:0;min-width:150px">
            <label class="cv-label">{{ t.filterCustomer }}</label>
            <select v-model="af.customer_id" class="cv-select cv-select-sm" @change="applyFilters">
              <option value="">{{ t.allCustomers }}</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ n(c,'name') }}</option>
            </select>
          </div>
          <div class="cv-field" style="margin:0;min-width:128px">
            <label class="cv-label">{{ t.filterStatus }}</label>
            <select v-model="af.status" class="cv-select cv-select-sm" @change="applyFilters">
              <option value="">{{ t.allStatuses }}</option>
              <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <!-- Match filter — client-side only, not sent to server -->
          <div class="cv-field" style="margin:0;min-width:130px">
            <label class="cv-label">{{ t.filterMatch }}</label>
            <select v-model="matchFilter" class="cv-select cv-select-sm">
              <option value="">{{ t.allMatch }}</option>
              <option value="collected">{{ t.matchCollected }}</option>
              <option value="partial">{{ t.matchPartial }}</option>
              <option value="open">{{ t.matchOpen }}</option>
            </select>
          </div>
          <div class="cv-field" style="margin:0">
            <label class="cv-label">{{ t.from }}</label>
            <input type="date" v-model="af.date_from" class="cv-input cv-input-sm" @change="applyFilters" />
          </div>
          <div class="cv-field" style="margin:0">
            <label class="cv-label">{{ t.to }}</label>
            <input type="date" v-model="af.date_to" class="cv-input cv-input-sm" @change="applyFilters" />
          </div>
          <button class="cv-btn cv-btn-ghost cv-btn-sm" style="margin-bottom:1rem" @click="clearFilters">{{ t.clear }}</button>
        </div>
      </div>

      <!-- Table Card -->
      <div class="cv-card" style="padding:0;overflow:hidden">

        <!-- Empty state -->
        <div v-if="!visibleRows.length" style="padding:3rem;text-align:center;color:var(--cv-text-muted);font-size:0.85rem">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 0.75rem;display:block;color:var(--cv-border)"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="15" x2="13" y2="15"/></svg>
          {{ t.empty }}
        </div>

        <!-- Table -->
        <div v-else style="overflow-x:auto">
          <table class="cv-table" style="min-width:860px">
            <thead>
              <tr>
                <th style="width:120px">{{ t.colRef }}</th>
                <th style="width:96px">{{ t.colDate }}</th>
                <th>{{ t.colCustomer }}</th>
                <th style="width:90px">{{ t.colStatus }}</th>
                <th style="width:100px">{{ t.colMatch }}</th>
                <th style="width:130px;text-align:end">{{ t.colTotal }}</th>
                <th style="width:110px;text-align:end">{{ t.colPaid }}</th>
                <th style="width:120px;text-align:end">{{ t.colBalance }}</th>
                <th style="width:96px">{{ t.colDue }}</th>
                <th style="width:88px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in visibleRows" :key="inv.id">

                <!-- Reference -->
                <td>
                  <span style="font-family:monospace;font-size:0.72rem;color:var(--cv-text-muted)">
                    {{ inv.reference || arRef(inv.id) }}
                  </span>
                </td>

                <!-- Date -->
                <td style="font-size:0.78rem;color:var(--cv-text-secondary)">{{ inv.date }}</td>

                <!-- Customer -->
                <td>
                  <span style="font-weight:600;font-size:0.82rem;color:var(--cv-text-primary)">
                    {{ n(inv.customer,'name') }}
                  </span>
                </td>

                <!-- Status badge -->
                <td>
                  <span class="cv-badge" :class="statusBadgeClass(inv.status)">{{ statusLabel(inv.status) }}</span>
                </td>

                <!-- Match badge -->
                <td>
                  <!-- Collected: paid in full -->
                  <span v-if="inv.status === 'paid'"
                    class="cv-badge cv-badge-green"
                    style="display:inline-flex;align-items:center;gap:0.25rem">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ t.matchCollected }}
                  </span>
                  <!-- Partial: some payments but not fully paid -->
                  <span v-else-if="inv.payment_count > 0"
                    class="cv-badge cv-badge-amber"
                    style="display:inline-flex;align-items:center;gap:0.25rem">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    {{ t.matchPartial }}
                  </span>
                  <!-- Open: no payments at all -->
                  <span v-else
                    class="cv-badge cv-badge-neutral"
                    style="display:inline-flex;align-items:center;gap:0.25rem">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>
                    {{ t.matchOpen }}
                  </span>
                </td>

                <!-- Total -->
                <td style="text-align:end">
                  <div style="font-weight:700;font-size:0.82rem;color:var(--cv-blue-text)">
                    {{ fmt(inv.total) }}
                    <span style="font-size:0.63rem;font-weight:400;color:var(--cv-text-muted)">{{ inv.currency }}</span>
                  </div>
                  <div v-if="inv.currency !== 'EGP'" style="font-size:0.62rem;color:var(--cv-text-muted)">
                    ≈ {{ fmt(inv.total_egp) }} {{ t.egp }}
                  </div>
                </td>

                <!-- Paid -->
                <td style="text-align:end">
                  <div style="font-size:0.82rem;font-weight:600;color:var(--cv-success-text)">{{ fmt(inv.paid) }}</div>
                </td>

                <!-- Balance -->
                <td style="text-align:end">
                  <div style="font-size:0.82rem;font-weight:700"
                    :style="`color:${parseFloat(inv.balance)>0?'var(--cv-green-bright)':'var(--cv-success-text)'}`">
                    {{ fmt(inv.balance) }}
                  </div>
                  <div v-if="inv.currency !== 'EGP'" style="font-size:0.62rem;color:var(--cv-text-muted)">
                    ≈ {{ fmt(inv.balance_egp) }} {{ t.egp }}
                  </div>
                  <!-- FX rate sub-line -->
                  <div v-if="inv.currency !== 'EGP'"
                    style="font-size:0.6rem;color:var(--cv-blue-text);font-weight:600;margin-top:0.1rem">
                    1 {{ inv.currency }} = {{ fmtRate(inv.exchange_rate) }} {{ t.egp }}
                  </div>
                </td>

                <!-- Due date -->
                <td>
                  <span v-if="inv.due_date"
                    style="font-size:0.75rem"
                    :style="`color:${isPastDue(inv.due_date) && inv.status !== 'paid' ? 'var(--cv-danger-text)' : 'var(--cv-text-secondary)'}`">
                    {{ inv.due_date }}
                  </span>
                  <span v-else style="font-size:0.72rem;color:var(--cv-text-muted)">—</span>
                </td>

                <!-- Actions -->
                <td>
                  <div class="flex gap-1 justify-end">
                    <Link :href="route('ar-invoices.show', inv.id)"
                      class="cv-btn cv-btn-ghost cv-btn-xs"
                      style="padding:0.2rem 0.45rem"
                      :title="t.view">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </Link>
                    <Link v-if="canEdit(inv)" :href="route('ar-invoices.edit', inv.id)"
                      class="cv-btn cv-btn-ghost cv-btn-xs"
                      style="padding:0.2rem 0.45rem"
                      :title="t.edit">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </Link>
                    <button v-if="canDelete(inv)"
                      class="cv-btn cv-btn-ghost cv-btn-xs"
                      style="padding:0.2rem 0.45rem;color:var(--cv-danger-text)"
                      :title="t.delete"
                      @click="openDelete(inv)">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                  </div>
                </td>

              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="invoices.last_page > 1"
          class="flex items-center justify-between flex-wrap gap-2"
          style="padding:0.75rem 1.25rem;border-top:1px solid var(--cv-border)">
          <div style="font-size:0.75rem;color:var(--cv-text-muted)">
            {{ t.showing }} {{ invoices.from }}–{{ invoices.to }} {{ t.of }} {{ invoices.total }}
          </div>
          <div class="flex gap-1 flex-wrap">
            <Link v-for="link in invoices.links" :key="link.label"
              :href="link.url || '#'"
              class="cv-btn cv-btn-ghost cv-btn-xs"
              :class="{ 'cv-btn-amber': link.active }"
              :style="!link.url ? 'opacity:0.35;pointer-events:none' : ''"
              preserve-state
              v-html="link.label" />
          </div>
        </div>
      </div>

    </div><!-- /cv-page -->

    <!-- Delete modal -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="deleteTarget = null">
        <div class="cv-modal" style="max-width:420px">
          <div class="cv-modal-header">
            <div class="flex items-center gap-2">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--cv-danger-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              </div>
              <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">{{ t.deleteTitle }}</div>
            </div>
          </div>
          <div class="cv-modal-body">
            <div class="cv-alert-danger" style="font-size:0.82rem;text-align:center">
              {{ t.deleteMsg }} <strong>{{ deleteTarget ? (deleteTarget.reference || arRef(deleteTarget.id)) : '' }}</strong>?
            </div>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="deleteTarget = null">{{ t.cancel }}</button>
            <button class="cv-btn cv-btn-danger cv-btn-sm" :disabled="deleting" @click="doDelete">
              {{ deleting ? t.deleting : t.confirmDelete }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
  
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const props = defineProps({
  invoices:  { type: Object, required: true },
  aging:     { type: Object, required: true },
  customers: { type: Array,  default: () => [] },
  companies: { type: Array,  default: () => [] },
  filters:   { type: Object, default: () => ({}) },
})

const { locale: lang, isRtl, n } = useLocale()
const page         = usePage()
const authUser     = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const { can } = usePermissions()
const canCreate  = computed(() => isSuperAdmin.value || can('create ar_invoices'))
const canEditP   = computed(() => isSuperAdmin.value || can('edit ar_invoices'))
const canDeleteP = computed(() => isSuperAdmin.value || can('delete ar_invoices'))

// ── i18n ───────────────────────────────────────────────────────────
const i18n = {
  en: {
    eyebrow:'Accounting', title:'AR Invoices', sub:'Customer invoices and receivables management',
    newInvoice:'New Invoice',
    kpiTotal:'Total Invoices', kpiOpen:'Open Receivables', kpiOverdue:'Overdue Receivables', kpiCritical:'Critical (90+ Days)',
    egp:'EGP', agingTitle:'Aging Analysis — Days Past Due',
    aging_current:'Current', aging_1_30:'1–30 Days', aging_31_60:'31–60 Days', aging_61_90:'61–90 Days', aging_over_90:'90+ Days',
    filterCompany:'Company', allCompanies:'All Companies',
    filterCustomer:'Customer', allCustomers:'All Customers',
    filterStatus:'Status', allStatuses:'All Statuses',
    filterMatch:'Collection', allMatch:'All',
    matchCollected:'Collected', matchPartial:'Partial', matchOpen:'Uncollected',
    from:'From', to:'To', clear:'Clear',
    colRef:'Reference', colDate:'Date', colCustomer:'Customer', colStatus:'Status',
    colMatch:'Collection', colTotal:'Total', colPaid:'Received', colBalance:'Balance',
    colDue:'Due Date',
    view:'View', edit:'Edit', delete:'Delete',
    showing:'Showing', of:'of',
    empty:'No AR invoices found. Create your first customer invoice.',
    deleteTitle:'Delete Invoice', deleteMsg:'Delete invoice',
    cancel:'Cancel', confirmDelete:'Delete', deleting:'Deleting…',
    statuses:{ open:'Open', partially_paid:'Partial', paid:'Paid', overdue:'Overdue', cancelled:'Cancelled' },
  },
  ar: {
    eyebrow:'المحاسبة', title:'فواتير العملاء', sub:'إدارة فواتير العملاء والمستحقات',
    newInvoice:'فاتورة جديدة',
    kpiTotal:'إجمالي الفواتير', kpiOpen:'مستحقات مفتوحة', kpiOverdue:'مستحقات متأخرة', kpiCritical:'حرج (+90 يوم)',
    egp:'ج.م', agingTitle:'تحليل الأعمار — أيام التأخر',
    aging_current:'جارية', aging_1_30:'1–30 يوم', aging_31_60:'31–60 يوم', aging_61_90:'61–90 يوم', aging_over_90:'+90 يوم',
    filterCompany:'الشركة', allCompanies:'كل الشركات',
    filterCustomer:'العميل', allCustomers:'كل العملاء',
    filterStatus:'الحالة', allStatuses:'كل الحالات',
    filterMatch:'التحصيل', allMatch:'الكل',
    matchCollected:'محصّلة', matchPartial:'جزئي', matchOpen:'غير محصّلة',
    from:'من', to:'إلى', clear:'مسح',
    colRef:'المرجع', colDate:'التاريخ', colCustomer:'العميل', colStatus:'الحالة',
    colMatch:'التحصيل', colTotal:'الإجمالي', colPaid:'المحصّل', colBalance:'المتبقي',
    colDue:'الاستحقاق',
    view:'عرض', edit:'تعديل', delete:'حذف',
    showing:'عرض', of:'من',
    empty:'لا توجد فواتير عملاء. أنشئ أول فاتورة.',
    deleteTitle:'حذف الفاتورة', deleteMsg:'حذف الفاتورة',
    cancel:'إلغاء', confirmDelete:'حذف', deleting:'جارٍ الحذف…',
    statuses:{ open:'مفتوحة', partially_paid:'جزئية', paid:'مدفوعة', overdue:'متأخرة', cancelled:'ملغاة' },
  },
}
const t = computed(() => i18n[lang.value] ?? i18n.en)

// ── Aging config ───────────────────────────────────────────────────
const agingBuckets = computed(() => [
  { key:'current', label:t.value.aging_current, color:'var(--cv-success-text)' },
  { key:'1_30',    label:t.value.aging_1_30,    color:'var(--cv-blue-text)' },
  { key:'31_60',   label:t.value.aging_31_60,   color:'var(--cv-amber-text)' },
  { key:'61_90',   label:t.value.aging_61_90,   color:'var(--cv-warning-text)' },
  { key:'over_90', label:t.value.aging_over_90, color:'var(--cv-danger-text)' },
])

// ── Status helpers ─────────────────────────────────────────────────
const statusOptions = computed(() => Object.entries(t.value.statuses).map(([v,l]) => ({ value:v, label:l })))
function statusLabel(s) { return t.value.statuses[s] ?? s }
function statusBadgeClass(s) {
  return {
    open:'cv-badge-green',
    partially_paid:'cv-badge-amber',
    paid:'cv-badge-green',
    overdue:'cv-badge-danger',
    cancelled:'cv-badge-neutral',
  }[s] ?? 'cv-badge-neutral'
}

// ── Match filter (client-side only) ───────────────────────────────
// matchFilter is intentionally NOT included in the router.get() call
// in applyFilters() — it filters the already-paginated page of data only.
const matchFilter = ref('')

const visibleRows = computed(() => {
  if (!matchFilter.value) return props.invoices.data
  return props.invoices.data.filter(inv => {
    if (matchFilter.value === 'collected') return inv.status === 'paid'
    if (matchFilter.value === 'partial')   return inv.payment_count > 0 && inv.status !== 'paid'
    if (matchFilter.value === 'open')      return inv.payment_count === 0 && inv.status !== 'paid'
    return true
  })
})

// ── Utility ────────────────────────────────────────────────────────
function arRef(id) { return 'AR-' + String(id).padStart(5,'0') }
function isPastDue(d) { return d && d < localToday() }
function canEdit(inv)   { return canEditP.value && !['paid','cancelled'].includes(inv.status) }
function canDelete(inv) { return canDeleteP.value && inv.status === 'open' && parseFloat(inv.paid) === 0 }
function fmt(v) {
  const num = parseFloat(v) || 0
  return lang.value === 'ar'
    ? num.toLocaleString('ar-EG', { minimumFractionDigits:2, maximumFractionDigits:2 })
    : num.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 })
}
function fmtRate(v) {
  const num = parseFloat(v) || 1
  return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits:4, maximumFractionDigits:6 })
}

// ── Filters ────────────────────────────────────────────────────────
const af = ref({
  company_id:  props.filters?.company_id  ?? '',
  customer_id: props.filters?.customer_id ?? '',
  status:      props.filters?.status      ?? '',
  date_from:   props.filters?.date_from   ?? '',
  date_to:     props.filters?.date_to     ?? '',
})

function applyFilters() {
  // matchFilter is client-side only — never sent to the server
  const q = {}
  Object.entries(af.value).forEach(([k,v]) => { if (v !== '') q[k] = v })
  router.get(route('ar-invoices.index'), q, { preserveState:true, replace:true })
}

function clearFilters() {
  af.value = { company_id:'', customer_id:'', status:'', date_from:'', date_to:'' }
  matchFilter.value = ''
  router.get(route('ar-invoices.index'))
}

// ── Delete ─────────────────────────────────────────────────────────
const deleteTarget = ref(null)
const deleting     = ref(false)
function openDelete(inv) { deleteTarget.value = inv }
function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  router.delete(route('ar-invoices.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; deleting.value = false },
    onError:   () => { deleting.value = false },
  })
}
</script>