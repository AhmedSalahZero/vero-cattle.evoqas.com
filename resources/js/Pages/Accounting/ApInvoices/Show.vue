<template>
  <AuthenticatedLayout>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ invoice.reference || apRef(invoice.id) }}</div>
      <div class="cv-page-hero-sub">
        {{ n(invoice.supplier,'name') }} · {{ invoice.date }}
        <span v-if="invoice.currency !== 'EGP'" style="color:var(--cv-amber-text);margin-inline-start:0.4rem">
          · 1 {{ invoice.currency }} = {{ fmtRate(invoice.exchange_rate) }} {{ t.egp }}
        </span>
      </div>
      <div class="cv-page-hero-actions">
        <Link :href="route('ap-invoices.index')" class="cv-btn cv-btn-ghost cv-btn-sm">{{ t.back }}</Link>
        <Link
          v-if="canEdit && !['paid','cancelled'].includes(invoice.status)"
          :href="route('ap-invoices.edit', invoice.id)"
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
              <div v-if="field.sub" style="font-size:0.65rem;color:var(--cv-text-muted)">{{ field.sub }}</div>
            </div>
          </div>
          <!-- FX box -->
          <div v-if="invoice.currency !== 'EGP'"
            style="margin:0 1.25rem 1.25rem;background:var(--cv-blue-light);border:1px solid var(--cv-blue-border);border-radius:var(--cv-radius);padding:0.75rem 1rem">
            <div style="font-size:0.65rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--cv-blue-text);margin-bottom:0.3rem">
              {{ t.fxSummary }}
            </div>
            <div class="flex gap-4 flex-wrap">
              <div><div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.fxRate }}</div><div style="font-size:0.88rem;font-weight:700;color:var(--cv-blue-text)">1 {{ invoice.currency }} = {{ fmtRate(invoice.exchange_rate) }} {{ t.egp }}</div></div>
              <div><div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.totalEgp }}</div><div style="font-size:0.88rem;font-weight:700;color:var(--cv-amber-text)">{{ fmt(invoice.total_egp) }} {{ t.egp }}</div></div>
              <div><div style="font-size:0.62rem;color:var(--cv-text-muted)">{{ t.balanceEgp }}</div><div style="font-size:0.88rem;font-weight:700;color:var(--cv-danger-text)">{{ fmt(invoice.balance_egp) }} {{ t.egp }}</div></div>
            </div>
          </div>
        </div>

        <!-- Accrual Matching Panel — only for cattle and inventory invoices -->
        <div v-if="invoice.invoice_type !== 'other'" class="cv-card" style="padding:0;overflow:hidden">
          <div class="flex items-center justify-between"
            style="padding:0.875rem 1.25rem;border-bottom:1px solid var(--cv-border)">
            <div style="display:flex;align-items:center;gap:0.6rem">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--cv-amber-text)" stroke-width="1.7">
                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                <rect x="9" y="3" width="6" height="4" rx="1"/>
                <path d="M9 12h6M9 16h4"/>
              </svg>
              <span style="font-weight:700;font-size:0.9rem;color:var(--cv-text-primary)">{{ t.accrualTitle }}</span>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="invoice.matched_accruals_count > 0" class="cv-badge cv-badge-green" style="font-size:0.65rem">
                {{ invoice.matched_accruals_count }} {{ t.accrualMatched }}
              </span>
              <span v-else class="cv-badge cv-badge-amber" style="font-size:0.65rem">{{ t.accrualNone }}</span>
            </div>
          </div>

          <div style="padding:1rem 1.25rem">

            <!-- Info note -->
            <div style="background:var(--cv-blue-light);border:1px solid var(--cv-blue-border);border-radius:var(--cv-radius);padding:0.625rem 0.875rem;margin-bottom:1rem;font-size:0.775rem;color:var(--cv-blue-text);line-height:1.5">
              {{ t.accrualInfo }}
            </div>

            <!-- Already matched receipts -->
            <div v-if="pendingAccruals.matched.length" style="margin-bottom:1.25rem">
              <div style="font-size:0.65rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--cv-success-text);margin-bottom:0.5rem">
                ✓ {{ t.accrualMatchedList }}
              </div>
              <div class="flex flex-col gap-1">
                <div v-for="tx in pendingAccruals.matched" :key="tx.id"
                  class="flex items-center justify-between"
                  style="padding:0.5rem 0.75rem;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border-subtle);border-radius:var(--cv-radius);border-inline-start:3px solid var(--cv-success-text)">
                  <div style="flex:1;min-width:0">
                     <div style="font-size:0.8rem;font-weight:600;color:var(--cv-text-primary)">
                        <span v-if="tx.type === 'livestock_receipt'" style="color:var(--cv-green-bright)">
                          🐄 {{ lang === 'ar' ? 'استلام مواشي' : 'Livestock Receipt' }}
                        </span>
                        <span v-else>{{ lang === 'ar' ? (tx.item_name_ar || tx.item_name_en) : (tx.item_name_en || tx.item_name_ar) }}</span>
                      </div>
                    <div style="font-size:0.68rem;color:var(--cv-text-muted)">
                      {{ tx.date }}
                      <span v-if="tx.po_reference"> · {{ tx.po_reference }}</span>
                      <span v-else-if="tx.location_en || tx.location_ar"> · {{ lang === 'ar' ? (tx.location_ar || tx.location_en) : (tx.location_en || tx.location_ar) }}</span>
                      <span v-if="tx.ref_no"> · {{ tx.ref_no }}</span>
                    </div>
                  </div>
                  <div style="display:flex;align-items:center;gap:0.75rem">
                    <div style="text-align:end">
                      <div style="font-size:0.8rem;font-weight:700;color:var(--cv-blue-text)">{{ fmt(tx.total) }} {{ invoice.currency }}</div>
                      <div style="font-size:0.65rem;color:var(--cv-text-muted)">{{ tx.qty }} × {{ fmt(tx.unit_cost) }}</div>
                    </div>
                    <button
                      v-if="!['paid','cancelled'].includes(invoice.status)"
                      @click="openUnmatch(tx)"
                      style="padding:0.3rem 0.5rem;border-radius:var(--cv-radius);background:transparent;border:1px solid var(--cv-border);cursor:pointer;color:var(--cv-danger-text);font-size:0.7rem"
                      :title="t.accrualUnmatch">✕</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Available to match -->
            <div v-if="pendingAccruals.unmatched.length">
              <div style="font-size:0.65rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:0.5rem">
                {{ t.accrualAvailable }}
              </div>
              <div class="flex flex-col gap-1" style="margin-bottom:0.75rem">
                <label v-for="tx in pendingAccruals.unmatched" :key="tx.id"
                  class="flex items-center gap-3"
                  style="padding:0.5rem 0.75rem;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);border-radius:var(--cv-radius);cursor:pointer"
                  :style="selectedTxIds.includes(tx.id) ? 'border-color:var(--cv-amber);background:var(--cv-amber-light)' : ''">
                  <input type="checkbox" :value="tx.id" v-model="selectedTxIds"
                    style="width:1rem;height:1rem;accent-color:var(--cv-amber);cursor:pointer;flex-shrink:0">
                  <div style="flex:1;min-width:0">
                    <div style="font-size:0.8rem;font-weight:600;color:var(--cv-text-primary)">
                      {{ lang === 'ar' ? (tx.item_name_ar || tx.item_name_en) : (tx.item_name_en || tx.item_name_ar) }}
                    </div>
                    <div style="font-size:0.68rem;color:var(--cv-text-muted)">
                      {{ tx.date }} · {{ lang === 'ar' ? (tx.location_ar || tx.location_en) : (tx.location_en || tx.location_ar) }}
                      <span v-if="tx.ref_no"> · {{ tx.ref_no }}</span>
                    </div>
                  </div>
                  <div style="text-align:end;flex-shrink:0">
                    <div style="font-size:0.8rem;font-weight:700;color:var(--cv-blue-text)">{{ fmt(tx.total) }} {{ invoice.currency }}</div>
                    <div style="font-size:0.65rem;color:var(--cv-text-muted)">{{ tx.qty }} × {{ fmt(tx.unit_cost) }}</div>
                  </div>
                </label>
              </div>

              <!-- Selection summary + match button -->
              <div v-if="selectedTxIds.length" class="flex items-center justify-between"
                style="padding:0.625rem 0.875rem;background:var(--cv-amber-light);border:1px solid var(--cv-amber);border-radius:var(--cv-radius)">
                <div style="font-size:0.8rem;color:var(--cv-amber-text);font-weight:600">
                  {{ selectedTxIds.length }} {{ t.accrualSelected }} · {{ fmt(selectedTotal) }} {{ invoice.currency }}
                </div>
                <button class="cv-btn cv-btn-amber cv-btn-sm" @click="openMatchConfirm" :disabled="matchForm.processing">
                  {{ t.accrualMatchBtn }}
                </button>
              </div>
            </div>

            <!-- No accruals at all -->
            <div v-if="!pendingAccruals.matched.length && !pendingAccruals.unmatched.length"
              style="text-align:center;padding:1.5rem;color:var(--cv-text-muted);font-size:0.825rem">
              {{ t.accrualEmpty }}
            </div>
          </div>
        </div>

        <!-- Direct Expense Panel — only for 'other' invoices -->
        <div v-if="invoice.invoice_type === 'other'" class="cv-card" style="padding:0;overflow:hidden">
          <div class="flex items-center gap-2"
            style="padding:0.875rem 1.25rem;border-bottom:1px solid var(--cv-border)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--cv-blue-text)" stroke-width="1.7">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
              <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
            </svg>
            <span style="font-weight:700;font-size:0.9rem;color:var(--cv-text-primary)">{{ t.directExpenseTitle }}</span>
          </div>
          <div style="padding:1rem 1.25rem">
            <div style="font-size:0.8rem;color:var(--cv-text-secondary);line-height:1.6;margin-bottom:0.875rem">
              {{ t.directExpenseInfo }}
            </div>
            <!-- Journal entry info box -->
            <div style="background:var(--cv-blue-light);border:1px solid var(--cv-blue-border);border-radius:var(--cv-radius);padding:0.75rem 1rem">
              <div style="font-size:0.65rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:var(--cv-blue-text);margin-bottom:0.5rem">
                {{ t.journalEntry }}
              </div>
              <div v-if="invoice.journal_entry" style="font-size:0.85rem">
                <Link :href="route('journal-entries.show', invoice.journal_entry.id)"
                  style="color:var(--cv-blue-text);text-decoration:none;font-weight:600">
                  {{ invoice.journal_entry.reference }}
                </Link>
                <span style="color:var(--cv-text-muted);margin-inline-start:0.5rem">· {{ invoice.journal_entry.date }}</span>
                <span class="cv-badge cv-badge-green" style="margin-inline-start:0.5rem;font-size:0.62rem">{{ invoice.journal_entry.status }}</span>
              </div>
              <div v-else style="font-size:0.8rem;color:var(--cv-amber-text)">
                {{ t.directExpenseNoJe }}
              </div>
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

        <!-- Journal Entry link — for cattle/inventory types only -->
        <div v-if="invoice.journal_entry && invoice.invoice_type !== 'other'" class="cv-card" style="padding:0.875rem 1.25rem">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.25rem">{{ t.journalEntry }}</div>
          <Link :href="route('journal-entries.show', invoice.journal_entry.id)"
            style="font-size:0.875rem;color:var(--cv-blue-text);text-decoration:none;font-weight:600">
            {{ invoice.journal_entry.reference }} — {{ invoice.journal_entry.date }}
          </Link>
        </div>

      </div>
    </div>
    <!-- Match Confirm Modal -->
    <div v-if="showMatchModal" class="cv-modal-backdrop" @click.self="showMatchModal=false">
      <div class="cv-modal" style="max-width:440px">
        <div class="cv-modal-header">
          <span style="color:var(--cv-amber-text)">{{ t.accrualMatchBtn }}</span>
          <button @click="showMatchModal=false" class="cv-modal-close">✕</button>
        </div>
        <div class="cv-modal-body">
          <p style="font-size:0.875rem;color:var(--cv-text-secondary);margin-bottom:0.75rem">{{ t.matchConfirmNote }}</p>
          <div style="background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);border-radius:var(--cv-radius);padding:0.75rem">
            <div class="flex justify-between" style="font-size:0.8rem;margin-bottom:0.25rem">
              <span style="color:var(--cv-text-muted)">{{ t.accrualSelected }}</span>
              <span style="color:var(--cv-text-primary);font-weight:600">{{ selectedTxIds.length }}</span>
            </div>
            <div class="flex justify-between" style="font-size:0.8rem">
              <span style="color:var(--cv-text-muted)">{{ t.matchTotalLabel }}</span>
              <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(selectedTotal) }} {{ invoice.currency }}</span>
            </div>
          </div>
        </div>
        <div class="cv-modal-footer">
          <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="showMatchModal=false">{{ t.cancel }}</button>
          <button class="cv-btn cv-btn-amber cv-btn-sm" @click="confirmMatch" :disabled="matchForm.processing">
            {{ t.accrualMatchBtn }}
          </button>
        </div>
      </div>
    </div>

    <!-- Unmatch Confirm Modal -->
    <div v-if="showUnmatchModal" class="cv-modal-backdrop" @click.self="showUnmatchModal=false">
      <div class="cv-modal" style="max-width:420px">
        <div class="cv-modal-header">
          <span style="color:var(--cv-danger-text)">{{ t.accrualUnmatch }}</span>
          <button @click="showUnmatchModal=false" class="cv-modal-close">✕</button>
        </div>
        <div class="cv-modal-body">
          <p style="font-size:0.875rem;color:var(--cv-text-secondary)">{{ t.unmatchConfirmNote }}</p>
          <div v-if="unmatchTarget" style="margin-top:0.75rem;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);border-radius:var(--cv-radius);padding:0.75rem;font-size:0.8rem;color:var(--cv-text-muted)">
            {{ lang === 'ar' ? (unmatchTarget.item_name_ar || unmatchTarget.item_name_en) : (unmatchTarget.item_name_en || unmatchTarget.item_name_ar) }}
            · {{ unmatchTarget.date }}
            · <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(unmatchTarget.total) }} {{ invoice.currency }}</span>
          </div>
        </div>
        <div class="cv-modal-footer">
          <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="showUnmatchModal=false">{{ t.cancel }}</button>
          <button class="cv-btn cv-btn-danger cv-btn-sm" @click="confirmUnmatch" :disabled="unmatchForm.processing">
            {{ t.accrualUnmatch }}
          </button>
        </div>
      </div>
    </div>

  </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, usePage, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
  invoice:         { type: Object, required: true },
  pendingAccruals: { type: Object, default: () => ({ matched: [], unmatched: [] }) },
})

const { locale: lang, isRtl, n } = useLocale()
const page         = usePage()
const authUser     = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const { can } = usePermissions()
const canEdit = computed(() => isSuperAdmin.value || can('edit ap_invoices'))

// ── Accrual matching state ────────────────────────────────────────
const selectedTxIds   = ref([])
const showMatchModal  = ref(false)
const showUnmatchModal = ref(false)
const unmatchTarget   = ref(null)

const selectedTotal = computed(() =>
  props.pendingAccruals.unmatched
    .filter(tx => selectedTxIds.value.includes(tx.id))
    .reduce((sum, tx) => sum + tx.total, 0)
)

const matchForm   = useForm({ transaction_ids: [] })
const unmatchForm = useForm({})

function openMatchConfirm() {
  matchForm.transaction_ids = [...selectedTxIds.value]
  showMatchModal.value = true
}

function confirmMatch() {
  matchForm.transaction_ids = [...selectedTxIds.value]
  matchForm.patch(route('ap-invoices.match-accruals', props.invoice.id), {
    onSuccess: () => {
      showMatchModal.value = false
      selectedTxIds.value = []
    },
  })
}

function openUnmatch(tx) {
  unmatchTarget.value = tx
  showUnmatchModal.value = true
}

function confirmUnmatch() {
  unmatchForm.patch(
    route('ap-invoices.unmatch-accrual', [props.invoice.id, unmatchTarget.value.id]),
    {
      onSuccess: () => {
        showUnmatchModal.value = false
        unmatchTarget.value = null
      },
    }
  )
}

// ── i18n ─────────────────────────────────────────────────────────
const i18n = {
  en: {
    eyebrow:'Accounting', back:'Back', edit:'Edit Invoice',
    invoiceDetails:'Invoice Details',
    fxSummary:'Foreign Currency', fxRate:'Exchange Rate', totalEgp:'Total (EGP)', balanceEgp:'Balance (EGP)', egp:'EGP',
    payments:'Payment History', notes:'Notes', journalEntry:'Journal Entry',
    fieldTotal:'Total', fieldPaid:'Paid', fieldBalance:'Balance',
    fieldDate:'Invoice Date', fieldDue:'Due Date', fieldRef:'Reference',
    statuses:{ open:'Open', partially_paid:'Partial', paid:'Paid', overdue:'Overdue', cancelled:'Cancelled' },
    // Accrual matching
    accrualTitle:       'AP Accruals — Goods Receipt Matching',
    accrualMatched:     'matched',
    accrualNone:        'No receipts matched',
    accrualInfo:        'Match the inventory receipts that this invoice covers. Each matched receipt clears its AP Accruals balance and moves it to Accounts Payable.',
    accrualMatchedList: 'Receipts matched to this invoice',
    accrualAvailable:   'Unmatched receipts from this supplier',
    accrualEmpty:       'No inventory receipts found for this supplier.',
    accrualSelected:    'receipts selected',
    accrualMatchBtn:    'Match Selected',
    accrualUnmatch:     'Remove Match',
    matchConfirmNote:   'These receipts will be linked to this invoice. The AP Accruals balance will be cleared on journal posting.',
    matchTotalLabel:    'Total accrual value',
    unmatchConfirmNote: 'This receipt will be unlinked from the invoice and returned to the unmatched accruals pool.',
    cancel:             'Cancel',
    directExpenseTitle: 'Direct Expense Invoice',
    directExpenseInfo:  'This invoice was posted directly to the selected expense or asset account. A journal entry was automatically created on save: Dr Expense Account / Cr Accounts Payable (2210).',
    directExpenseNoJe:  'Journal entry pending — please post manually from Journal Entries.',
  },
  ar: {
    eyebrow:'المحاسبة', back:'رجوع', edit:'تعديل الفاتورة',
    invoiceDetails:'تفاصيل الفاتورة',
    fxSummary:'العملة الأجنبية', fxRate:'سعر الصرف', totalEgp:'الإجمالي (ج.م)', balanceEgp:'الرصيد (ج.م)', egp:'ج.م',
    payments:'سجل المدفوعات', notes:'ملاحظات', journalEntry:'قيد محاسبي',
    fieldTotal:'الإجمالي', fieldPaid:'المدفوع', fieldBalance:'الرصيد',
    fieldDate:'تاريخ الفاتورة', fieldDue:'تاريخ الاستحقاق', fieldRef:'المرجع',
    statuses:{ open:'مفتوحة', partially_paid:'جزئية', paid:'مدفوعة', overdue:'متأخرة', cancelled:'ملغاة' },
    // Accrual matching
    accrualTitle:       'إقفال مستحقات الموردين — مطابقة بوالص الاستلام',
    accrualMatched:     'مطابق',
    accrualNone:        'لا توجد استلامات مطابقة',
    accrualInfo:        'طابق بوالص الاستلام التي تغطيها هذه الفاتورة. كل استلام مطابق يقفل رصيد المستحقات وينقله إلى الذمم الدائنة.',
    accrualMatchedList: 'الاستلامات المطابقة لهذه الفاتورة',
    accrualAvailable:   'الاستلامات غير المطابقة لهذا المورد',
    accrualEmpty:       'لا توجد بوالص استلام لهذا المورد.',
    accrualSelected:    'استلامات مختارة',
    accrualMatchBtn:    'مطابقة المختار',
    accrualUnmatch:     'إلغاء المطابقة',
    matchConfirmNote:   'ستُربط هذه الاستلامات بالفاتورة. يُقفل رصيد المستحقات عند ترحيل القيد.',
    matchTotalLabel:    'إجمالي المستحقات',
    unmatchConfirmNote: 'سيُلغى ربط هذا الاستلام بالفاتورة ويعود إلى مجموعة المستحقات غير المطابقة.',
    cancel:             'إلغاء',
    directExpenseTitle: 'فاتورة مصروف مباشر',
    directExpenseInfo:  'تم ترحيل هذه الفاتورة مباشرة إلى حساب المصروف أو الأصل المختار. تم إنشاء قيد محاسبي تلقائياً عند الحفظ: مدين حساب المصروف / دائن ذمم دائنة (2210).',
    directExpenseNoJe:  'القيد المحاسبي معلق — يرجى ترحيله يدوياً من صفحة القيود.',
  },
}
const t = computed(() => i18n[lang.value] ?? i18n.en)

function apRef(id) { return 'AP-' + String(id).padStart(5,'0') }
function statusLabel(s) { return t.value.statuses[s] ?? s }
function badgeClass(s) {
  return { open:'cv-badge-info', partially_paid:'cv-badge-amber', paid:'cv-badge-green', overdue:'cv-badge-danger', cancelled:'cv-badge-neutral' }[s] ?? 'cv-badge-neutral'
}
function stripeClass(s) {
  return { open:'cv-card-stripe-blue', partially_paid:'cv-card-stripe-amber', paid:'cv-card-stripe-green', overdue:'cv-card-stripe-red', cancelled:'cv-card-stripe-muted' }[s] ?? ''
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
    { label:t.value.fieldRef,     value: inv.reference || apRef(inv.id) },
    { label:t.value.fieldDate,    value: inv.date },
    { label:t.value.fieldDue,     value: inv.due_date || '—' },
    { label:t.value.fieldTotal,   value: fmt(inv.total) + ' ' + inv.currency, color:'var(--cv-blue-text)' },
    { label:t.value.fieldPaid,    value: fmt(inv.paid) + ' ' + inv.currency,  color:'var(--cv-success-text)' },
    { label:t.value.fieldBalance, value: fmt(inv.balance) + ' ' + inv.currency, color: parseFloat(inv.balance)>0?'var(--cv-danger-text)':'var(--cv-success-text)' },
  ]
})
</script>