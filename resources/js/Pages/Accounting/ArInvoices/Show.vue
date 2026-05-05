<template>
   <AuthenticatedLayout>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ invoice.reference || arRef(invoice.id) }}</div>
      <div class="cv-page-hero-sub">
        {{ n(invoice.customer,'name') }} · {{ invoice.date }}
        <span v-if="invoice.currency !== 'EGP'" style="color:var(--cv-blue-text);margin-inline-start:0.4rem">
          · 1 {{ invoice.currency }} = {{ fmtRate(invoice.exchange_rate) }} {{ t.egp }}
        </span>
      </div>
      <div class="cv-page-hero-actions">
        <Link :href="route('ar-invoices.index')" class="cv-btn cv-btn-ghost cv-btn-sm">{{ t.back }}</Link>
        <Link
          v-if="canEdit && !['paid','cancelled'].includes(invoice.status)"
          :href="route('ar-invoices.edit', invoice.id)"
          class="cv-btn cv-btn-amber cv-btn-sm"
        >{{ t.edit }}</Link>
      </div>
    </div>

    <div class="cv-page">
      <div class="flex flex-col gap-4" style="max-width:860px">

        <!-- Status + amounts -->
        <div class="cv-card" :class="stripeClass(invoice.status)" style="padding:0;overflow:hidden">
          <div class="flex items-center justify-between" style="padding:1rem 1.25rem;border-bottom:1px solid var(--cv-border)">
            <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">{{ t.invoiceDetails }}</div>
            <span class="cv-badge" :class="badgeClass(invoice.status)">{{ statusLabel(invoice.status) }}</span>
          </div>
          <div class="flex flex-wrap" style="padding:1.25rem;gap:1.5rem">
            <div v-for="field in headerFields" :key="field.label" style="min-width:140px">
              <div style="font-size:0.65rem;color:var(--cv-text-muted);margin-bottom:0.2rem;text-transform:uppercase;letter-spacing:0.06em">{{ field.label }}</div>
              <div :style="`font-size:0.95rem;font-weight:600;color:${field.color||'var(--cv-text-primary)'}`">{{ field.value }}</div>
            </div>
          </div>
          <!-- FX box -->
          <div v-if="invoice.currency !== 'EGP'"
            style="margin:0 1.25rem 1.25rem;background:var(--cv-blue-light);border:1px solid var(--cv-blue-border);border-radius:var(--cv-radius);padding:0.75rem 1rem">
            <div style="font-size:0.65rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--cv-blue-text);margin-bottom:0.3rem">{{ t.fxSummary }}</div>
            <div class="flex gap-4 flex-wrap">
              <div><div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.fxRate }}</div><div style="font-size:0.88rem;font-weight:700;color:var(--cv-blue-text)">1 {{ invoice.currency }} = {{ fmtRate(invoice.exchange_rate) }} {{ t.egp }}</div></div>
              <div><div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.totalEgp }}</div><div style="font-size:0.88rem;font-weight:700;color:var(--cv-green-bright)">{{ fmt(invoice.total_egp) }} {{ t.egp }}</div></div>
              <div><div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.balanceEgp }}</div><div style="font-size:0.88rem;font-weight:700;color:var(--cv-blue-text)">{{ fmt(invoice.balance_egp) }} {{ t.egp }}</div></div>
            </div>
          </div>
        </div>

        <!-- Payments -->
        <div v-if="invoice.payments && invoice.payments.length" class="cv-card">
          <div style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:0.875rem">{{ t.payments }}</div>
          <div class="flex flex-col gap-2">
            <div v-for="p in invoice.payments" :key="p.id"
              class="flex items-center justify-between"
              style="padding:0.6rem 0.75rem;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);border-radius:var(--cv-radius)">
              <div style="font-size:0.8rem;color:var(--cv-text-secondary)">{{ p.date }}</div>
              <div style="font-size:0.8rem;color:var(--cv-text-muted)">{{ p.method }}</div>
              <div style="font-size:0.9rem;font-weight:700;color:var(--cv-success-text)">{{ fmt(p.amount) }} {{ invoice.currency }}</div>
            </div>
          </div>
        </div>

        <!-- Notes -->
        <div v-if="invoice.notes" class="cv-card">
          <div style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:0.5rem">{{ t.notes }}</div>
          <div style="font-size:0.875rem;color:var(--cv-text-secondary);line-height:1.6">{{ invoice.notes }}</div>
        </div>

        <!-- Journal Entry -->
        <div v-if="invoice.journal_entry" class="cv-card" style="padding:0.875rem 1.25rem">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.25rem">{{ t.journalEntry }}</div>
          <Link :href="route('journal-entries.show', invoice.journal_entry.id)"
            style="font-size:0.875rem;color:var(--cv-blue-text);text-decoration:none;font-weight:600">
            {{ invoice.journal_entry.reference }} — {{ invoice.journal_entry.date }}
          </Link>
        </div>

      </div>
    </div>
  </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
  invoice: { type: Object, required: true },
})

const { locale: lang, isRtl, n } = useLocale()
const page         = usePage()
const authUser     = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const { can } = usePermissions()
const canEdit = computed(() => isSuperAdmin.value || can('edit ar_invoices'))

const i18n = {
  en: {
    eyebrow:'Accounting', back:'Back', edit:'Edit Invoice',
    invoiceDetails:'Invoice Details',
    fxSummary:'Foreign Currency', fxRate:'Exchange Rate', totalEgp:'Total (EGP)', balanceEgp:'Outstanding (EGP)', egp:'EGP',
    payments:'Payment History', notes:'Notes', journalEntry:'Journal Entry',
    fieldTotal:'Total', fieldPaid:'Received', fieldBalance:'Outstanding',
    fieldDate:'Invoice Date', fieldDue:'Due Date', fieldRef:'Reference',
    statuses:{ open:'Open', partially_paid:'Partial', paid:'Paid', overdue:'Overdue', cancelled:'Cancelled' },
  },
  ar: {
    eyebrow:'المحاسبة', back:'رجوع', edit:'تعديل الفاتورة',
    invoiceDetails:'تفاصيل الفاتورة',
    fxSummary:'العملة الأجنبية', fxRate:'سعر الصرف', totalEgp:'الإجمالي (ج.م)', balanceEgp:'المتبقي (ج.م)', egp:'ج.م',
    payments:'سجل المدفوعات', notes:'ملاحظات', journalEntry:'قيد محاسبي',
    fieldTotal:'الإجمالي', fieldPaid:'المحصّل', fieldBalance:'المتبقي',
    fieldDate:'تاريخ الفاتورة', fieldDue:'تاريخ الاستحقاق', fieldRef:'المرجع',
    statuses:{ open:'مفتوحة', partially_paid:'جزئية', paid:'مدفوعة', overdue:'متأخرة', cancelled:'ملغاة' },
  },
}
const t = computed(() => i18n[lang.value] ?? i18n.en)

function arRef(id) { return 'AR-' + String(id).padStart(5,'0') }
function statusLabel(s) { return t.value.statuses[s] ?? s }
function badgeClass(s) {
  return { open:'cv-badge-green', partially_paid:'cv-badge-amber', paid:'cv-badge-green', overdue:'cv-badge-danger', cancelled:'cv-badge-neutral' }[s] ?? 'cv-badge-neutral'
}
function stripeClass(s) {
  return { open:'cv-card-stripe-green', partially_paid:'cv-card-stripe-amber', paid:'cv-card-stripe-green', overdue:'cv-card-stripe-red', cancelled:'cv-card-stripe-muted' }[s] ?? ''
}
function fmt(v) {
  const num = parseFloat(v)||0
  return lang.value === 'ar'
    ? num.toLocaleString('ar-EG',{minimumFractionDigits:2,maximumFractionDigits:2})
    : num.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})
}
function fmtRate(v) {
  const num = parseFloat(v)||1
  return num.toLocaleString(lang.value==='ar'?'ar-EG':'en-US',{minimumFractionDigits:4,maximumFractionDigits:6})
}

const headerFields = computed(() => {
  const inv = props.invoice
  return [
    { label:t.value.fieldRef,     value: inv.reference || arRef(inv.id) },
    { label:t.value.fieldDate,    value: inv.date },
    { label:t.value.fieldDue,     value: inv.due_date || '—' },
    { label:t.value.fieldTotal,   value: fmt(inv.total) + ' ' + inv.currency,   color:'var(--cv-blue-text)' },
    { label:t.value.fieldPaid,    value: fmt(inv.paid)  + ' ' + inv.currency,   color:'var(--cv-success-text)' },
    { label:t.value.fieldBalance, value: fmt(inv.balance) + ' ' + inv.currency, color:'var(--cv-green-bright)' },
  ]
})
</script>