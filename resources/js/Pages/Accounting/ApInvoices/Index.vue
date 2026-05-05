<template>
   <AuthenticatedLayout>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- ── Hero ──────────────────────────────────────────────────── -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ t.title }}</div>
      <div class="cv-page-hero-sub">{{ t.sub }}</div>
      <div class="cv-page-hero-actions">
        <Link v-if="canCreate" :href="route('ap-invoices.create')" class="cv-btn cv-btn-amber cv-btn-sm">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          {{ t.newInvoice }}
        </Link>
      </div>
    </div>

    <div class="cv-page">

      <!-- ── KPI Strip ─────────────────────────────────────────── -->
      <div class="grid gap-3 mb-5" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <div class="cv-stat-card cv-stat-card-amber">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiTotal }}</div>
          <div style="font-size:1.35rem;font-weight:700;color:var(--cv-amber-text)">{{ invoices.total }}</div>
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

      <!-- ── Aging ─────────────────────────────────────────────── -->
      <div class="cv-card mb-5" style="padding:1rem 1.25rem">
        <div style="font-size:0.68rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--cv-amber-text);margin-bottom:0.75rem">
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

      <!-- ── Filters ────────────────────────────────────────────── -->
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
            <label class="cv-label">{{ t.filterSupplier }}</label>
            <select v-model="af.supplier_id" class="cv-select cv-select-sm" @change="applyFilters">
              <option value="">{{ t.allSuppliers }}</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ n(s,'name') }}</option>
            </select>
          </div>
          <div class="cv-field" style="margin:0;min-width:128px">
            <label class="cv-label">{{ t.filterStatus }}</label>
            <select v-model="af.status" class="cv-select cv-select-sm" @change="applyFilters">
              <option value="">{{ t.allStatuses }}</option>
              <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
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

      <!-- ── Cards ─────────────────────────────────────────────── -->
      <div v-if="invoices.data.length" class="grid gap-4" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr))">
        <div v-for="inv in invoices.data" :key="inv.id"
          class="cv-card" :class="stripeClass(inv.status)"
          style="padding:0;overflow:hidden">

          <!-- Header -->
          <div class="flex items-start justify-between" style="padding:1rem 1.25rem 0.75rem">
            <div style="min-width:0;flex:1">
              <div style="font-size:0.63rem;color:var(--cv-text-muted);margin-bottom:0.15rem;font-family:monospace">{{ inv.reference || apRef(inv.id) }}</div>
              <div style="font-weight:700;font-size:0.95rem;color:var(--cv-text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ n(inv.supplier,'name') }}</div>
              <div style="font-size:0.72rem;color:var(--cv-text-muted);margin-top:0.1rem">{{ inv.date }}</div>
            </div>
            <span class="cv-badge" :class="badgeClass(inv.status)" style="margin-inline-start:0.75rem;flex-shrink:0">{{ statusLabel(inv.status) }}</span>
          </div>

          <!-- Amounts -->
          <div class="flex" style="padding:0 1.25rem 0.75rem;border-bottom:1px solid var(--cv-border);gap:0">
            <div style="flex:1;padding-inline-end:0.75rem">
              <div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.total }}</div>
              <div style="font-size:0.92rem;font-weight:700;color:var(--cv-blue-text)">
                {{ fmtAmt(inv.total) }}
                <span style="font-size:0.62rem;font-weight:400;color:var(--cv-text-muted)">{{ inv.currency }}</span>
              </div>
              <!-- 📌 InvoiceForeignCurrencyBalance -->
              <div v-if="inv.currency !== 'EGP'" style="font-size:0.63rem;color:var(--cv-text-muted)">≈ {{ fmt(inv.total_egp) }} {{ t.egp }}</div>
            </div>
            <div style="flex:1;border-inline-start:1px solid var(--cv-border);padding-inline-start:0.75rem;padding-inline-end:0.75rem">
              <div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.paid }}</div>
              <div style="font-size:0.92rem;font-weight:600;color:var(--cv-success-text)">{{ fmtAmt(inv.paid) }}</div>
            </div>
            <div style="flex:1;border-inline-start:1px solid var(--cv-border);padding-inline-start:0.75rem">
              <div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.balance }}</div>
              <div style="font-size:0.92rem;font-weight:700" :style="`color:${parseFloat(inv.balance)>0?'var(--cv-danger-text)':'var(--cv-success-text)'}`">
                {{ fmtAmt(inv.balance) }}
              </div>
              <div v-if="inv.currency !== 'EGP'" style="font-size:0.63rem;color:var(--cv-text-muted)">≈ {{ fmt(inv.balance_egp) }} {{ t.egp }}</div>
            </div>
          </div>

          <!-- FX pill -->
          <div v-if="inv.currency !== 'EGP'"
            style="padding:0.38rem 1.25rem;background:var(--cv-amber-light);border-bottom:1px solid var(--cv-amber-border)">
            <span style="font-size:0.63rem;color:var(--cv-amber-text);font-weight:600">
              1 {{ inv.currency }} = {{ fmtRate(inv.exchange_rate) }} {{ t.egp }}
            </span>
          </div>

          <!-- Footer -->
          <div class="flex items-center justify-between" style="padding:0.6rem 1.25rem">
            <div style="font-size:0.72rem;color:var(--cv-text-muted)">
              <span v-if="inv.due_date">{{ t.due }}:
                <span :style="`font-weight:600;color:${isPastDue(inv.due_date)?'var(--cv-danger-text)':'var(--cv-text-secondary)'}`">{{ inv.due_date }}</span>
              </span>
              <span v-else>{{ t.noDue }}</span>
            </div>
            <div class="flex gap-1">
              <Link :href="route('ap-invoices.show', inv.id)" class="cv-action-btn cv-action-btn-blue" :title="t.view">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </Link>
              <Link v-if="canEdit(inv)" :href="route('ap-invoices.edit', inv.id)" class="cv-action-btn" :title="t.edit">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </Link>
              <button v-if="canDelete(inv)" class="cv-action-btn cv-action-btn-danger" :title="t.delete" @click="openDelete(inv)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty -->
      <div v-else class="cv-card" style="text-align:center;padding:3.5rem 2rem">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cv-text-muted);margin:0 auto 1rem"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>
        <div style="color:var(--cv-text-muted);font-size:0.875rem;margin-bottom:1rem">{{ t.empty }}</div>
        <Link v-if="canCreate" :href="route('ap-invoices.create')" class="cv-btn cv-btn-amber cv-btn-sm" style="display:inline-flex">{{ t.newInvoice }}</Link>
      </div>

      <!-- Pagination -->
      <div v-if="invoices.last_page > 1" class="flex justify-center gap-2 mt-5 flex-wrap">
        <Link v-for="p in invoices.links" :key="p.label" :href="p.url || '#'"
          class="cv-btn cv-btn-ghost cv-btn-sm"
          :style="p.active ? 'background:var(--cv-green-dim);color:var(--cv-green-bright);border-color:var(--cv-green-border)' : p.url ? '' : 'opacity:0.35;pointer-events:none'"
          v-html="p.label"
        />
      </div>

    </div>

    <!-- Delete Modal -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="deleteTarget = null">
        <div class="cv-modal cv-modal-sm">
          <div class="cv-modal-header">
            <div style="text-align:center;width:100%">
              <div style="width:48px;height:48px;border-radius:50%;background:var(--cv-danger-bg);border:1.5px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
              </div>
              <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">{{ t.deleteTitle }}</div>
            </div>
          </div>
          <div class="cv-modal-body">
            <div class="cv-alert-danger" style="font-size:0.82rem;text-align:center">
              {{ t.deleteMsg }} <strong>{{ deleteTarget ? (deleteTarget.reference || apRef(deleteTarget.id)) : '' }}</strong>?
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
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
  invoices:  { type: Object, required: true },
  aging:     { type: Object, required: true },
  suppliers: { type: Array,  default: () => [] },
  companies: { type: Array,  default: () => [] },
  filters:   { type: Object, default: () => ({}) },
})

const { locale: lang, isRtl, n } = useLocale()
const page         = usePage()
const authUser     = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const { can } = usePermissions()
const canCreate  = computed(() => isSuperAdmin.value || can('create ap_invoices'))
const canEditP   = computed(() => isSuperAdmin.value || can('edit ap_invoices'))
const canDeleteP = computed(() => isSuperAdmin.value || can('delete ap_invoices'))

const i18n = {
  en: {
    eyebrow:'Accounting', title:'AP Invoices', sub:'Supplier invoices and payables management',
    newInvoice:'New Invoice',
    kpiTotal:'Total Invoices', kpiOpen:'Open Balance', kpiOverdue:'Overdue Balance', kpiCritical:'Critical (90+ Days)',
    egp:'EGP', agingTitle:'Aging Analysis — Days Past Due',
    aging_current:'Current', aging_1_30:'1–30 Days', aging_31_60:'31–60 Days', aging_61_90:'61–90 Days', aging_over_90:'90+ Days',
    filterCompany:'Company', allCompanies:'All Companies',
    filterSupplier:'Supplier', allSuppliers:'All Suppliers',
    filterStatus:'Status', allStatuses:'All Statuses',
    from:'From', to:'To', clear:'Clear',
    total:'Total', paid:'Paid', balance:'Balance',
    due:'Due', noDue:'No due date',
    view:'View', edit:'Edit', delete:'Delete',
    empty:'No AP invoices found. Create your first supplier invoice.',
    deleteTitle:'Delete Invoice', deleteMsg:'Delete invoice',
    cancel:'Cancel', confirmDelete:'Delete', deleting:'Deleting…',
    statuses:{ open:'Open', partially_paid:'Partial', paid:'Paid', overdue:'Overdue', cancelled:'Cancelled' },
  },
  ar: {
    eyebrow:'المحاسبة', title:'فواتير الموردين', sub:'إدارة فواتير الموردين والمستحقات',
    newInvoice:'فاتورة جديدة',
    kpiTotal:'إجمالي الفواتير', kpiOpen:'الرصيد المفتوح', kpiOverdue:'رصيد المتأخرات', kpiCritical:'حرج (+90 يوم)',
    egp:'ج.م', agingTitle:'تحليل الأعمار — أيام التأخر',
    aging_current:'جارية', aging_1_30:'1–30 يوم', aging_31_60:'31–60 يوم', aging_61_90:'61–90 يوم', aging_over_90:'+90 يوم',
    filterCompany:'الشركة', allCompanies:'كل الشركات',
    filterSupplier:'المورد', allSuppliers:'كل الموردين',
    filterStatus:'الحالة', allStatuses:'كل الحالات',
    from:'من', to:'إلى', clear:'مسح',
    total:'الإجمالي', paid:'المدفوع', balance:'الرصيد',
    due:'الاستحقاق', noDue:'بدون تاريخ استحقاق',
    view:'عرض', edit:'تعديل', delete:'حذف',
    empty:'لا توجد فواتير موردين. أنشئ أول فاتورة.',
    deleteTitle:'حذف الفاتورة', deleteMsg:'حذف الفاتورة',
    cancel:'إلغاء', confirmDelete:'حذف', deleting:'جارٍ الحذف…',
    statuses:{ open:'مفتوحة', partially_paid:'جزئية', paid:'مدفوعة', overdue:'متأخرة', cancelled:'ملغاة' },
  },
}
const t = computed(() => i18n[lang.value] ?? i18n.en)

const agingBuckets = computed(() => [
  { key:'current', label:t.value.aging_current, color:'var(--cv-success-text)' },
  { key:'1_30',    label:t.value.aging_1_30,    color:'var(--cv-blue-text)' },
  { key:'31_60',   label:t.value.aging_31_60,   color:'var(--cv-amber-text)' },
  { key:'61_90',   label:t.value.aging_61_90,   color:'var(--cv-warning-text)' },
  { key:'over_90', label:t.value.aging_over_90, color:'var(--cv-danger-text)' },
])
const statusOptions = computed(() => Object.entries(t.value.statuses).map(([v,l]) => ({ value:v, label:l })))
function statusLabel(s) { return t.value.statuses[s] ?? s }
function badgeClass(s) {
  return { open:'cv-badge-info', partially_paid:'cv-badge-amber', paid:'cv-badge-green', overdue:'cv-badge-danger', cancelled:'cv-badge-neutral' }[s] ?? 'cv-badge-neutral'
}
function stripeClass(s) {
  return { open:'cv-card-stripe-blue', partially_paid:'cv-card-stripe-amber', paid:'cv-card-stripe-green', overdue:'cv-card-stripe-red', cancelled:'cv-card-stripe-muted' }[s] ?? ''
}
function apRef(id) { return 'AP-' + String(id).padStart(5,'0') }
function isPastDue(d) { return d && d < new Date().toISOString().slice(0,10) }
// Permission-aware guards: SA bypasses permission check, others need explicit permission
function canEdit(inv)   { return canEditP.value && !['paid','cancelled'].includes(inv.status) }
function canDelete(inv) { return canDeleteP.value && inv.status === 'open' && parseFloat(inv.paid) === 0 }
function fmt(v) {
  const num = parseFloat(v)||0
  return lang.value === 'ar'
    ? num.toLocaleString('ar-EG',{minimumFractionDigits:2,maximumFractionDigits:2})
    : num.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})
}
function fmtAmt(v) { return fmt(v) }
function fmtRate(v) {
  const num = parseFloat(v)||1
  return num.toLocaleString(lang.value==='ar'?'ar-EG':'en-US',{minimumFractionDigits:4,maximumFractionDigits:6})
}

const af = ref({ company_id:props.filters?.company_id??'', supplier_id:props.filters?.supplier_id??'', status:props.filters?.status??'', date_from:props.filters?.date_from??'', date_to:props.filters?.date_to??'' })
function applyFilters() {
  const q = {}; Object.entries(af.value).forEach(([k,v])=>{ if(v!=='') q[k]=v })
  router.get(route('ap-invoices.index'), q, { preserveState:true, replace:true })
}
function clearFilters() {
  af.value = { company_id:'', supplier_id:'', status:'', date_from:'', date_to:'' }
  router.get(route('ap-invoices.index'))
}

const deleteTarget = ref(null)
const deleting = ref(false)
function openDelete(inv) { deleteTarget.value = inv }
function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  router.delete(route('ap-invoices.destroy', deleteTarget.value.id), {
    onSuccess:()=>{ deleteTarget.value=null; deleting.value=false },
    onError:()=>{ deleting.value=false },
  })
}
</script>