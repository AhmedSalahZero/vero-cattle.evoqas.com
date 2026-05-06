<script setup>
/**
 * Accounting/Payments/Index.vue
 * ─────────────────────────────────────────────────────────────────
 * 📌 METHODOLOGY REFERENCE: MultiInvoicePaymentAllocation (#46)
 * 📌 METHODOLOGY REFERENCE: CheckCollectionLifecycle (#30)
 * 📌 METHODOLOGY REFERENCE: NonPartnerPaymentClassification (#31)
 * 📌 METHODOLOGY REFERENCE: InvoiceBalanceCalc (#2)
 *
 * CHANGES vs previous version:
 *  1. SA cascade watch fixed — calls /payments/data-for-company, not /fx-rates/latest
 *  2. AR invoice selector → multi-select with per-invoice allocation amount input
 *  3. AP invoice selector → same pattern
 *  4. form.allocations[] replaces form.ar_invoice_id / form.ap_invoice_id
 *  5. rowClass() moved into <script setup> — no more Options API block
 *  6. Collections ledger table: multi-invoice display in customer column
 *  7. Payments ledger table: multi-invoice display in supplier column
 * ─────────────────────────────────────────────────────────────────
 */
import { ref, computed, watch } from 'vue'
import { useForm, usePage, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

defineOptions({ layout: AuthenticatedLayout })

const props = defineProps({
    collections:     Object,
    payments:        Object,
    rejectedChecks:  Array,
    checkSummary:    Array,
    kpis:            Object,
    filters:         Object,
    companies:       Array,
    suppliers:       Array,
    customers:       Array,
    bankAccounts:    Array,
    expenseAccounts: Array,
    openApInvoices:  Array,
    openArInvoices:  Array,
})

const { locale: lang, isRtl, n } = useLocale()
const page         = usePage()
const authUser     = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const canCreate  = computed(() => isSuperAdmin.value || authUser.value?.permissions?.includes('create payments'))
const canEditP   = computed(() => isSuperAdmin.value || authUser.value?.permissions?.includes('edit payments'))
const canDeleteP = computed(() => isSuperAdmin.value || authUser.value?.permissions?.includes('delete payments'))

// ── Translations ───────────────────────────────────────────────────
const tx = computed(() => lang.value === 'ar' ? {
    title:           'المدفوعات والتحصيلات',
    subtitle:        'إدارة المدفوعات والتحصيلات ودفاتر الشيكات',
    eyebrow:         'المحاسبة',
    tabCollections:  'التحصيلات',
    tabPayments:     'المدفوعات',
    tabChecks:       'سجل الشيكات',
    totalCollections:'إجمالي التحصيلات',
    totalPayments:   'إجمالي المدفوعات',
    checksInSafe:    'شيكات في الخزينة',
    checksUnderCol:  'شيكات تحت التحصيل',
    checksRejected:  'شيكات مرفوضة',
    rejectedTitle:   '⚠ خزينة الشيكات المرفوضة',
    rejectedDesc:    'توجد شيكات مرفوضة تحتاج إلى إجراء',
    resubmit:        'إعادة الإرسال للبنك',
    returnToCustomer:'إعادة للعميل',
    newCollection:   '+ تحصيل جديد',
    newPayment:      '+ دفعة جديدة',
    category:        'نوع المعاملة',
    date:            'التاريخ',
    amount:          'المبلغ',
    currency:        'العملة',
    method:          'طريقة الدفع',
    bankName:        'اسم البنك',
    bankAccount:     'حساب البنك',
    checkNumber:     'رقم الشيك',
    checkBank:       'البنك المسحوب عليه',
    checkDrawnAcct:  'رقم الحساب على الشيك',
    reference:       'المرجع',
    notes:           'ملاحظات',
    supplier:        'المورد',
    customer:        'العميل',
    invoices:        'الفواتير',
    invoiceSelector: 'اختر الفواتير للتسوية',
    allocated:       'المبلغ المخصص',
    remaining:       'المتبقي',
    balance:         'الرصيد',
    allocationTotal: 'إجمالي التخصيص',
    mustMatchAmount: 'يجب أن يساوي مبلغ الدفع',
    addInvoice:      '+ إضافة فاتورة',
    removeInvoice:   'حذف',
    noOpenInvoices:  'لا توجد فواتير مفتوحة لهذا المورد/العميل',
    subType:         'التصنيف الفرعي',
    subTypeComment:  'تعليق',
    payeeName:       'الاسم',
    expenseAccount:  'حساب المصروف',
    save:            'حفظ',
    cancel:          'إلغاء',
    cash:            'نقدي',
    bank_transfer:   'تحويل بنكي',
    cheque:          'شيك',
    bank_deposit:    'إيداع بنكي',
    customer_collection:    'تحصيل من عميل',
    employee_collection:    'استرداد من موظف',
    shareholder_collection: 'تمويل من مساهم',
    other_collection:       'تحصيل أخرى',
    supplier_payment:       'دفعة لمورد',
    employee_payment:       'دفعة لموظف',
    shareholder_payment:    'دفعة لمساهم',
    expense_payment:        'مصروف مباشر',
    other_payment:          'دفعة أخرى',
    custody_refund:             'استرداد عهدة',
    loan_refund:                'استرداد قرض',
    capital_injection:          'ضخ رأس مال',
    shareholder_loan:           'قرض مساهم',
    custody:                    'عهدة',
    employee_loan:              'قرض موظف',
    salary_advance:             'سلفة',
    dividend_payment:           'توزيع أرباح',
    funding_from_shareholders:  'تمويل من مساهمين',
    other:                      'أخرى',
    in_safe:              'في الخزينة',
    under_collection:     'تحت التحصيل',
    collected:            'تم التحصيل',
    rejected:             'مرفوض',
    returned_to_customer: 'أعيد للعميل',
    checkRegTitle:        'سجل الشيكات',
    bank:                 'البنك',
    checkStatus:          'حالة الشيك',
    sendToBank:           'إرسال للبنك',
    markCollected:        'تسجيل التحصيل',
    markRejected:         'تسجيل الرفض',
    collectionDate:       'تاريخ التحصيل',
    sentToBankDate:       'تاريخ الإرسال للبنك',
    rejectionDate:        'تاريخ الرفض',
    receivedDate:         'تاريخ الاستلام',
    deleteConfirm:        'تأكيد الحذف',
    deleteMsg:            'هل أنت متأكد من حذف هذه المعاملة؟',
    confirmDelete:        'حذف',
    bankRequired:         '* اسم البنك مطلوب لكل المعاملات البنكية',
    noData:               'لا توجد معاملات',
    all:                  'الكل',
    filterByMethod:       'طريقة الدفع',
    filterByCategory:     'النوع',
    filterByStatus:       'الحالة',
    filterByBank:         'البنك',
    company:              'الشركة',
} : {
    title:           'Payments & Collections',
    subtitle:        'Manage incoming collections, outgoing payments, and check tracking',
    eyebrow:         'Accounting',
    tabCollections:  'Collections',
    tabPayments:     'Payments',
    tabChecks:       'Check Register',
    totalCollections:'Total Collections',
    totalPayments:   'Total Payments',
    checksInSafe:    'Checks in Safe',
    checksUnderCol:  'Under Collection',
    checksRejected:  'Rejected Checks',
    rejectedTitle:   '⚠ Rejected Checks Safe',
    rejectedDesc:    'These checks require immediate action',
    resubmit:        'Re-submit to Bank',
    returnToCustomer:'Return to Customer',
    newCollection:   '+ New Collection',
    newPayment:      '+ New Payment',
    category:        'Transaction Type',
    date:            'Date',
    amount:          'Amount',
    currency:        'Currency',
    method:          'Payment Method',
    bankName:        'Bank Name',
    bankAccount:     'Bank Account',
    checkNumber:     'Check Number',
    checkBank:       'Drawn on Bank',
    checkDrawnAcct:  'Account on Check',
    reference:       'Reference',
    notes:           'Notes',
    supplier:        'Supplier',
    customer:        'Customer',
    invoices:        'Invoices',
    invoiceSelector: 'Select invoices to settle',
    allocated:       'Allocated',
    remaining:       'Remaining',
    balance:         'Balance',
    allocationTotal: 'Allocation Total',
    mustMatchAmount: 'must equal payment amount',
    addInvoice:      '+ Add Invoice',
    removeInvoice:   'Remove',
    noOpenInvoices:  'No open invoices found',
    subType:         'Sub-type',
    subTypeComment:  'Comment',
    payeeName:       'Name',
    expenseAccount:  'Expense Account',
    save:            'Save',
    cancel:          'Cancel',
    cash:            'Cash',
    bank_transfer:   'Bank Transfer',
    cheque:          'Check',
    bank_deposit:    'Bank Deposit',
    customer_collection:    'Collection from Customer',
    employee_collection:    'Received from Employee',
    shareholder_collection: 'Capital from Shareholder',
    other_collection:       'Other Collection',
    supplier_payment:       'Payment to Supplier',
    employee_payment:       'Payment to Employee',
    shareholder_payment:    'Payment to Shareholder',
    expense_payment:        'Direct Expense',
    other_payment:          'Other Payment',
    custody_refund:             'Custody Refund',
    loan_refund:                'Loan Refund',
    capital_injection:          'Capital Injection',
    shareholder_loan:           'Shareholder Loan',
    custody:                    'Custody',
    employee_loan:              'Employee Loan',
    salary_advance:             'Salary Advance',
    dividend_payment:           'Dividend Payment',
    funding_from_shareholders:  'Funding from Shareholders',
    other:                      'Other',
    in_safe:              'In Safe',
    under_collection:     'Under Collection',
    collected:            'Collected',
    rejected:             'Rejected',
    returned_to_customer: 'Returned to Customer',
    checkRegTitle:        'Check Register',
    bank:                 'Bank',
    checkStatus:          'Check Status',
    sendToBank:           'Send to Bank',
    markCollected:        'Mark Collected',
    markRejected:         'Mark Rejected',
    collectionDate:       'Collection Date',
    sentToBankDate:       'Sent to Bank Date',
    rejectionDate:        'Rejection Date',
    receivedDate:         'Received Date',
    deleteConfirm:        'Confirm Delete',
    deleteMsg:            'Are you sure you want to delete this transaction?',
    confirmDelete:        'Delete',
    bankRequired:         '* Bank name is required for all bank transactions',
    noData:               'No transactions found',
    all:                  'All',
    filterByMethod:       'Method',
    filterByCategory:     'Type',
    filterByStatus:       'Status',
    filterByBank:         'Bank',
    company:              'Company',
})

// ── Tabs ───────────────────────────────────────────────────────────
const activeTab = ref('collections')

// ── SA company cascade ─────────────────────────────────────────────
const selectedCompanyId      = ref(props.filters?.company_id ?? props.companies?.[0]?.id ?? '')
const dynamicSuppliers       = ref([...props.suppliers])
const dynamicCustomers       = ref([...props.customers])
const dynamicBankAccounts    = ref([...props.bankAccounts])
const dynamicExpenseAccounts = ref([...props.expenseAccounts])
const dynamicOpenAp          = ref([...props.openApInvoices])
const dynamicOpenAr          = ref([...props.openArInvoices])

// BUG FIX: was hitting /fx-rates/latest — now correctly calls /payments/data-for-company
watch(selectedCompanyId, async (id) => {
    if (!id) return
    try {
        const res = await fetch(`/payments/data-for-company?company_id=${id}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (!res.ok) return
        const data = await res.json()
        dynamicSuppliers.value       = data.suppliers       ?? []
        dynamicCustomers.value       = data.customers       ?? []
        dynamicBankAccounts.value    = data.bankAccounts    ?? []
        dynamicExpenseAccounts.value = data.expenseAccounts ?? []
        dynamicOpenAp.value          = data.openApInvoices  ?? []
        dynamicOpenAr.value          = data.openArInvoices  ?? []
        // Reset allocation rows when company changes
        selectedInvoices.value = []
    } catch { /* silent */ }
})

// ── Modal state ────────────────────────────────────────────────────
const showModal        = ref(false)
const modalMode        = ref('collection') // 'collection' | 'payment'
const deleteModal      = ref(false)
const deleteTarget     = ref(null)
const checkActionModal  = ref(false)
const checkActionTarget = ref(null)
const checkAction       = ref('')

// ── Multi-invoice allocation state ────────────────────────────────
// 📌 MultiInvoicePaymentAllocation (#46)
//
// selectedInvoices is an array of objects:
//   { invoice_id: number, allocated_amount: string, invoice: <invoice object> }
//
// The user picks an invoice from the dropdown, it gets added to this list
// with its full balance as the default allocated amount (editable).
// The user can add multiple invoices and adjust amounts freely.
// Validation: Σ allocated_amount must equal form.amount.

const selectedInvoices  = ref([])  // rows in the allocation table
const invoiceToAdd      = ref('')  // the <select> value for adding a new row

// Available invoices for the current modal mode, minus already-selected ones
const availableInvoicePool = computed(() => {
    const pool = modalMode.value === 'collection' ? dynamicOpenAr.value : dynamicOpenAp.value
    const selectedIds = selectedInvoices.value.map(r => r.invoice_id)
    return pool.filter(inv => !selectedIds.includes(inv.id))
})

// Add an invoice to the allocation table
function addInvoiceRow() {
    if (!invoiceToAdd.value) return
    const pool = modalMode.value === 'collection' ? dynamicOpenAr.value : dynamicOpenAp.value
    const inv  = pool.find(i => i.id === Number(invoiceToAdd.value))
    if (!inv) return

    selectedInvoices.value.push({
        invoice_id:       inv.id,
        allocated_amount: String(inv.balance), // default: pay the full balance
        invoice:          inv,
    })
    invoiceToAdd.value = ''
}

function removeInvoiceRow(idx) {
    selectedInvoices.value.splice(idx, 1)
}

// Running total of all allocations — used for the balance indicator
const allocationTotal = computed(() => {
    return selectedInvoices.value.reduce((sum, row) => {
        return bcAdd(sum, row.allocated_amount || '0')
    }, 0)
})

// Simple float addition for display (not stored — server uses bcmath)
function bcAdd(a, b) {
    return Math.round((parseFloat(a || 0) + parseFloat(b || 0)) * 10000) / 10000
}

const allocationDiff = computed(() => {
    const total   = parseFloat(form.amount || 0)
    const alloced = allocationTotal.value
    return Math.round((total - alloced) * 100) / 100
})

const allocationBalanced = computed(() => Math.abs(allocationDiff.value) < 0.01)

// ── Forms ──────────────────────────────────────────────────────────
const form = useForm({
    company_id:          selectedCompanyId.value,
    payment_category:    '',
    sub_type:            '',
    sub_type_comment:    '',
    payee_name:          '',
    allocations:         [], // replaces ar_invoice_id / ap_invoice_id
    date:                new Date().toISOString().split('T')[0],
    amount:              '',
    currency:            'EGP',
    exchange_rate:       '',
    method:              'cash',
    bank_name:           '',
    bank_account_id:     '',
    check_number:        '',
    check_bank:          '',
    check_drawn_on_acct: '',
    expense_account_id:  '',
    reference:           '',
    notes:               '',
})

const checkForm = useForm({
    new_status:              '',
    bank_name:               '',
    bank_account_id:         '',
    check_collection_date:   new Date().toISOString().split('T')[0],
    check_sent_to_bank_date: new Date().toISOString().split('T')[0],
    check_rejection_date:    new Date().toISOString().split('T')[0],
})

// ── Computed helpers ───────────────────────────────────────────────
const isBankMethod = computed(() =>
    ['bank_transfer', 'cheque', 'bank_deposit'].includes(form.method)
)
const isCheck = computed(() => form.method === 'cheque')

// Filters the account list based on method:
// Cash → 14xx safe accounts only
// All other methods → 15xx bank accounts only
const filteredBankAccounts = computed(() => {
    if (form.method === 'cash') {
        return dynamicBankAccounts.value.filter(a => String(a.code).startsWith('14'))
    }
    return dynamicBankAccounts.value.filter(a => String(a.code).startsWith('15'))
})

const collectionCategories = [
    'customer_collection','employee_collection',
    'shareholder_collection','other_collection',
]
const paymentCategories = [
    'supplier_payment','employee_payment',
    'shareholder_payment','expense_payment','other_payment',
]

const employeeCollectionSubtypes    = ['custody_refund','loan_refund','other']
const employeePaymentSubtypes       = ['custody','employee_loan','salary_advance','other']
const shareholderCollectionSubtypes = ['capital_injection','shareholder_loan']
const shareholderPaymentSubtypes    = ['dividend_payment','funding_from_shareholders']

const showSubType = computed(() =>
    ['employee_collection','employee_payment',
     'shareholder_collection','shareholder_payment'].includes(form.payment_category)
)

const subTypeOptions = computed(() => {
    if (form.payment_category === 'employee_collection')    return employeeCollectionSubtypes
    if (form.payment_category === 'employee_payment')       return employeePaymentSubtypes
    if (form.payment_category === 'shareholder_collection') return shareholderCollectionSubtypes
    if (form.payment_category === 'shareholder_payment')    return shareholderPaymentSubtypes
    return []
})

// Invoice selector is shown for customer_collection and supplier_payment only
const showInvoiceSelector = computed(() =>
    form.payment_category === 'customer_collection' ||
    form.payment_category === 'supplier_payment'
)

const showExpenseAccount = computed(() =>
    ['expense_payment','other_collection','other_payment',
     'employee_collection','employee_payment',
     'shareholder_collection','shareholder_payment'].includes(form.payment_category)
)

const showPayeeName = computed(() =>
    ['other_collection','other_payment'].includes(form.payment_category)
)

// ── rowClass — moved from Options API into setup ───────────────────
function rowClass(item) {
    if (item.check_status === 'rejected')         return 'row-rejected'
    if (item.check_status === 'collected')        return 'row-collected'
    if (item.check_status === 'under_collection') return 'row-under-collection'
    return ''
}

// ── Display helper: get customer/supplier name from a payment row ──
function paymentPartyName(pay) {
    // New pattern: read from allocations
    if (pay.allocations?.length) {
        const first = pay.allocations[0]
        const inv   = first.ar_invoice ?? first.ap_invoice
        if (inv) {
            const party = inv.customer ?? inv.supplier
            return party ? n(party, 'name') : '—'
        }
    }
    // Legacy fallback
    if (pay.ar_invoice?.customer) return n(pay.ar_invoice.customer, 'name')
    if (pay.ap_invoice?.supplier) return n(pay.ap_invoice.supplier, 'name')
    if (pay.payee_name)           return pay.payee_name
    if (pay.expense_account)      return n(pay.expense_account, 'name')
    return '—'
}

// ── Methods ────────────────────────────────────────────────────────
function openCollection() {
    modalMode.value = 'collection'
    form.reset()
    selectedInvoices.value = []
    invoiceToAdd.value     = ''
    form.company_id        = selectedCompanyId.value
    form.date              = new Date().toISOString().split('T')[0]
    form.payment_category  = 'customer_collection'
    showModal.value        = true
}

function openPayment() {
    modalMode.value = 'payment'
    form.reset()
    selectedInvoices.value = []
    invoiceToAdd.value     = ''
    form.company_id        = selectedCompanyId.value
    form.date              = new Date().toISOString().split('T')[0]
    form.payment_category  = 'supplier_payment'
    showModal.value        = true
}

function closeModal() {
    showModal.value        = false
    selectedInvoices.value = []
    invoiceToAdd.value     = ''
    form.reset()
    form.clearErrors()
}

// Reset selected invoices when category changes (AR ↔ AP lists differ)
watch(() => form.payment_category, () => {
    selectedInvoices.value = []
    invoiceToAdd.value     = ''
})

// Reset bank account when method changes — 14xx ↔ 15xx are incompatible
watch(() => form.method, () => {
    form.bank_account_id = ''
})

function submitForm() {
    // Bank name required for bank methods
    if (['bank_transfer', 'cheque', 'bank_deposit'].includes(form.method) && !form.bank_name && !form.check_bank) {
        form.errors.bank_name = lang.value === 'ar'
            ? 'اسم البنك مطلوب'
            : 'Bank name is required for this payment method'
        return
    }

    form.allocations = selectedInvoices.value.map(row => ({
        invoice_id:       row.invoice_id,
        allocated_amount: parseFloat(row.allocated_amount || 0),
    }))

    form.post(route('payments.store'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    })
}

function openCheckAction(payment, action) {
    checkActionTarget.value = payment
    checkAction.value       = action
    checkForm.reset()
    checkForm.check_collection_date   = new Date().toISOString().split('T')[0]
    checkForm.check_sent_to_bank_date = new Date().toISOString().split('T')[0]
    checkForm.check_rejection_date    = new Date().toISOString().split('T')[0]
    checkActionModal.value  = true
}

function submitCheckAction() {
    if (checkAction.value === 'return') {
        router.post(route('payments.return-check', checkActionTarget.value.id), {}, {
            preserveScroll: true,
            onSuccess: () => { checkActionModal.value = false },
        })
        return
    }
    const statusMap = {
        send_to_bank: 'under_collection',
        collected:    'collected',
        rejected:     'rejected',
        resubmit:     'under_collection',
    }
    checkForm.new_status = statusMap[checkAction.value]
    checkForm.patch(route('payments.update-check-status', checkActionTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { checkActionModal.value = false },
    })
}

function confirmDelete(payment) {
    deleteTarget.value = payment
    deleteModal.value  = true
}

function doDelete() {
    router.delete(route('payments.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { deleteModal.value = false },
    })
}

// ── Check Register ─────────────────────────────────────────────────
const checkRegData     = ref([])
const checkRegLoading  = ref(false)
const checkBankFilter  = ref('')
const checkStatusFilter = ref('')

async function loadCheckRegister() {
    checkRegLoading.value = true
    const params = new URLSearchParams()
    if (selectedCompanyId.value) params.set('company_id', selectedCompanyId.value)
    if (checkBankFilter.value)   params.set('check_bank', checkBankFilter.value)
    if (checkStatusFilter.value) params.set('check_status', checkStatusFilter.value)
    const res  = await fetch(`/payments/checks?${params}`, { credentials: 'same-origin' })
    const data = await res.json()
    checkRegData.value    = data.grouped
    checkRegLoading.value = false
}

watch(activeTab, (t) => {
    if (t === 'checks') loadCheckRegister()
})

// ── Status color helpers ───────────────────────────────────────────
const checkStatusColor = {
    in_safe:              'badge-blue',
    under_collection:     'badge-amber',
    collected:            'badge-green',
    rejected:             'badge-red',
    returned_to_customer: 'badge-muted',
}

const methodIcon = {
    cash:          '💵',
    bank_transfer: '🏦',
    cheque:        '🗒️',
    bank_deposit:  '🏧',
}

function fmt(val) {
    return Number(val ?? 0).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

// ── FX Rate auto-fill ──────────────────────────────────────────────
const fetchingRate = ref(false)

async function fetchLatestRate() {
    if (!form.currency || form.currency === 'EGP') return
    fetchingRate.value = true
    try {
        const companyId = selectedCompanyId.value || ''
        const res = await fetch(
            `/fx-rates/latest?currency=${form.currency}&company_id=${companyId}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
        )
        if (!res.ok) return
        const data = await res.json()
        if (data?.rate) form.exchange_rate = data.rate
    } catch { /* silent */ }
    finally { fetchingRate.value = false }
}

watch(() => form.currency, (val) => {
    if (val === 'EGP') form.exchange_rate = ''
})
</script>

<template>
    

        <!-- ── Hero ─────────────────────────────────────────────── -->
        <div class="cv-page-hero">
            <div class="cv-page-hero-eyebrow">{{ tx.eyebrow }}</div>
            <div class="cv-page-hero-title">{{ tx.title }}</div>
            <div class="cv-page-hero-sub">{{ tx.subtitle }}</div>
            <div class="cv-page-hero-actions">
                <button v-if="canCreate && (activeTab === 'collections' || activeTab === 'checks')"
                    class="cv-btn cv-btn-amber"
                    @click="openCollection">
                    {{ tx.newCollection }}
                </button>
                <button v-if="canCreate && activeTab === 'payments'"
                    class="cv-btn cv-btn-amber"
                    @click="openPayment">
                    {{ tx.newPayment }}
                </button>
            </div>
        </div>

        <div class="cv-page">

            <!-- ── KPI strip ───────────────────────────────────── -->
            <div class="kpi-strip">
                <div class="kpi-card kpi-green">
                    <div class="kpi-value">{{ fmt(kpis?.total_collections) }}</div>
                    <div class="kpi-label">{{ tx.totalCollections }}</div>
                </div>
                <div class="kpi-card kpi-red">
                    <div class="kpi-value">{{ fmt(kpis?.total_payments) }}</div>
                    <div class="kpi-label">{{ tx.totalPayments }}</div>
                </div>
                <div class="kpi-card kpi-blue">
                    <div class="kpi-value">{{ kpis?.checks_in_safe ?? 0 }}</div>
                    <div class="kpi-label">{{ tx.checksInSafe }}</div>
                </div>
                <div class="kpi-card kpi-amber">
                    <div class="kpi-value">{{ kpis?.checks_under_collection ?? 0 }}</div>
                    <div class="kpi-label">{{ tx.checksUnderCol }}</div>
                </div>
                <div class="kpi-card kpi-danger">
                    <div class="kpi-value">{{ kpis?.checks_rejected ?? 0 }}</div>
                    <div class="kpi-label">{{ tx.checksRejected }}</div>
                </div>
            </div>

            <!-- ── SA Company selector ────────────────────────── -->
            <div v-if="isSuperAdmin" class="cv-card sa-company-bar">
                <label class="cv-label">{{ tx.company }}</label>
                <select v-model="selectedCompanyId" class="cv-select">
                    <option value="">— {{ tx.company }} —</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">
                        {{ n(c, 'name') }}
                    </option>
                </select>
            </div>

            <!-- ── Rejected Checks Safe panel ─────────────────── -->
            <div v-if="rejectedChecks?.length" class="rejected-safe-panel">
                <div class="rejected-safe-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span class="rejected-safe-title">{{ tx.rejectedTitle }}</span>
                    <span class="rejected-safe-count">{{ rejectedChecks.length }}</span>
                </div>
                <div class="rejected-safe-list">
                    <div v-for="chk in rejectedChecks" :key="chk.id" class="rejected-safe-row">
                        <div class="rejected-safe-info">
                            <span class="rejected-check-num">{{ chk.check_number ?? '—' }}</span>
                            <span class="rejected-check-bank">{{ chk.check_bank ?? chk.bank_name }}</span>
                            <span class="rejected-check-amount">{{ fmt(chk.amount) }} {{ chk.currency }}</span>
                            <span class="rejected-check-customer">{{ paymentPartyName(chk) }}</span>
                        </div>
                        <div class="rejected-safe-actions" v-if="canEditP">
                            <button class="cv-btn cv-btn-ghost btn-sm" @click="openCheckAction(chk, 'resubmit')">{{ tx.resubmit }}</button>
                            <button class="cv-btn cv-btn-danger btn-sm" @click="openCheckAction(chk, 'return')">{{ tx.returnToCustomer }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Tab bar ─────────────────────────────────────── -->
            <div class="tab-bar">
                <button class="tab-btn" :class="{ active: activeTab === 'collections' }" @click="activeTab = 'collections'">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    {{ tx.tabCollections }}
                    <span v-if="collections?.total" class="tab-count">{{ collections.total }}</span>
                </button>
                <button class="tab-btn" :class="{ active: activeTab === 'payments' }" @click="activeTab = 'payments'">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    {{ tx.tabPayments }}
                    <span v-if="payments?.total" class="tab-count">{{ payments.total }}</span>
                </button>
                <button class="tab-btn" :class="{ active: activeTab === 'checks' }" @click="activeTab = 'checks'">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h2"/><path d="M13 15h4"/></svg>
                    {{ tx.tabChecks }}
                    <span v-if="kpis?.checks_rejected" class="tab-count tab-count-red">{{ kpis.checks_rejected }}</span>
                </button>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB 1 — COLLECTIONS
            ══════════════════════════════════════════════════ -->
            <div v-show="activeTab === 'collections'">
                <div v-if="!collections?.data?.length" class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="cv-empty-title">{{ tx.noData }}</div>
                    <button v-if="canCreate" class="cv-btn cv-btn-amber" @click="openCollection">{{ tx.newCollection }}</button>
                </div>
                <div v-else class="ledger-table-wrap">
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>{{ tx.date }}</th>
                                <th>{{ tx.category }}</th>
                                <th>{{ tx.customer }}</th>
                                <th>{{ tx.invoices }}</th>
                                <th>{{ tx.method }}</th>
                                <th>{{ tx.checkStatus }}</th>
                                <th class="num-col">{{ tx.amount }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="col in collections.data" :key="col.id" :class="rowClass(col)">
                                <td class="date-col">{{ col.date }}</td>
                                <td>
                                    <span class="category-label">{{ tx[col.payment_category] ?? col.payment_category }}</span>
                                    <div v-if="col.sub_type" class="sub-type-pill">{{ tx[col.sub_type] ?? col.sub_type }}</div>
                                </td>
                                <td>{{ paymentPartyName(col) }}</td>
                                <td>
                                    <!-- Multi-invoice allocations display -->
                                    <template v-if="col.allocations?.length">
                                        <div v-for="alloc in col.allocations" :key="alloc.id" class="alloc-row-display">
                                            <span class="alloc-ref">{{ alloc.ar_invoice?.reference ?? '#' + alloc.ar_invoice_id }}</span>
                                            <span class="alloc-amt">{{ fmt(alloc.allocated_amount) }}</span>
                                        </div>
                                    </template>
                                    <!-- Legacy single-invoice display -->
                                    <span v-else-if="col.ar_invoice" class="muted-text">
                                        {{ col.ar_invoice.reference ?? '#' + col.ar_invoice.id }}
                                    </span>
                                    <span v-else class="muted-text">—</span>
                                </td>
                                <td>
                                    <span class="method-cell">{{ methodIcon[col.method] }} {{ tx[col.method] }}</span>
                                    <div v-if="col.bank_name" class="bank-sub">{{ col.bank_name }}</div>
                                    <div v-if="col.check_number" class="check-sub"># {{ col.check_number }}</div>
                                </td>
                                <td>
                                    <span v-if="col.check_status" class="cv-badge" :class="checkStatusColor[col.check_status]">{{ tx[col.check_status] }}</span>
                                    <span v-else class="muted-text">—</span>
                                </td>
                                <td class="num-col amount-col">{{ fmt(col.amount) }} <span class="currency-tag">{{ col.currency }}</span></td>
                                <td class="actions-col">
                                    <template v-if="col.method === 'cheque' && canEditP">
                                        <button v-if="col.check_status === 'in_safe'" class="action-btn action-btn-blue" :title="tx.sendToBank" @click="openCheckAction(col, 'send_to_bank')">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22V12"/><path d="M21 22V12"/><path d="M1 12l11-9 11 9"/><path d="M1 22h22"/></svg>
                                        </button>
                                        <button v-if="col.check_status === 'under_collection'" class="action-btn action-btn-green" :title="tx.markCollected" @click="openCheckAction(col, 'collected')">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                        </button>
                                        <button v-if="col.check_status === 'under_collection'" class="action-btn action-btn-red" :title="tx.markRejected" @click="openCheckAction(col, 'rejected')">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    </template>
                                    <button v-if="canDeleteP && col.check_status !== 'collected'" class="action-btn action-btn-delete" @click="confirmDelete(col)">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="collections?.last_page > 1" class="pagination-row">
                    <component :is="'a'" v-for="pg in collections.links" :key="pg.label" :href="pg.url"
                        class="page-link" :class="{ active: pg.active, disabled: !pg.url }" v-html="pg.label"/>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB 2 — PAYMENTS
            ══════════════════════════════════════════════════ -->
            <div v-show="activeTab === 'payments'">
                <div v-if="!payments?.data?.length" class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div class="cv-empty-title">{{ tx.noData }}</div>
                    <button v-if="canCreate" class="cv-btn cv-btn-amber" @click="openPayment">{{ tx.newPayment }}</button>
                </div>
                <div v-else class="ledger-table-wrap">
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>{{ tx.date }}</th>
                                <th>{{ tx.category }}</th>
                                <th>{{ tx.supplier }}</th>
                                <th>{{ tx.invoices }}</th>
                                <th>{{ tx.method }}</th>
                                <th>{{ tx.checkStatus }}</th>
                                <th class="num-col">{{ tx.amount }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="pay in payments.data" :key="pay.id" :class="rowClass(pay)">
                                <td class="date-col">{{ pay.date }}</td>
                                <td>
                                    <span class="category-label">{{ tx[pay.payment_category] ?? pay.payment_category }}</span>
                                    <div v-if="pay.sub_type" class="sub-type-pill">{{ tx[pay.sub_type] ?? pay.sub_type }}</div>
                                </td>
                                <td>{{ paymentPartyName(pay) }}</td>
                                <td>
                                    <template v-if="pay.allocations?.length">
                                        <div v-for="alloc in pay.allocations" :key="alloc.id" class="alloc-row-display">
                                            <span class="alloc-ref">{{ alloc.ap_invoice?.reference ?? '#' + alloc.ap_invoice_id }}</span>
                                            <span class="alloc-amt">{{ fmt(alloc.allocated_amount) }}</span>
                                        </div>
                                    </template>
                                    <span v-else-if="pay.ap_invoice" class="muted-text">
                                        {{ pay.ap_invoice.reference ?? '#' + pay.ap_invoice.id }}
                                    </span>
                                    <span v-else class="muted-text">—</span>
                                </td>
                                <td>
                                    <span class="method-cell">{{ methodIcon[pay.method] }} {{ tx[pay.method] }}</span>
                                    <div v-if="pay.bank_name" class="bank-sub">{{ pay.bank_name }}</div>
                                    <div v-if="pay.check_number" class="check-sub"># {{ pay.check_number }}</div>
                                </td>
                                <td>
                                    <span v-if="pay.check_status" class="cv-badge" :class="checkStatusColor[pay.check_status]">{{ tx[pay.check_status] }}</span>
                                    <span v-else class="muted-text">—</span>
                                </td>
                                <td class="num-col amount-col amount-out">{{ fmt(pay.amount) }} <span class="currency-tag">{{ pay.currency }}</span></td>
                                <td class="actions-col">
                                    <button v-if="canDeleteP" class="action-btn action-btn-delete" @click="confirmDelete(pay)">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="payments?.last_page > 1" class="pagination-row">
                    <component :is="'a'" v-for="pg in payments.links" :key="pg.label" :href="pg.url"
                        class="page-link" :class="{ active: pg.active, disabled: !pg.url }" v-html="pg.label"/>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB 3 — CHECK REGISTER
            ══════════════════════════════════════════════════ -->
            <div v-show="activeTab === 'checks'">
                <div class="filter-bar">
                    <select v-model="checkBankFilter" class="cv-select filter-select" @change="loadCheckRegister">
                        <option value="">{{ tx.all }} {{ tx.bank }}</option>
                        <option v-for="s in checkSummary" :key="s.bank" :value="s.bank">{{ s.bank }}</option>
                    </select>
                    <select v-model="checkStatusFilter" class="cv-select filter-select" @change="loadCheckRegister">
                        <option value="">{{ tx.all }}</option>
                        <option value="in_safe">{{ tx.in_safe }}</option>
                        <option value="under_collection">{{ tx.under_collection }}</option>
                        <option value="collected">{{ tx.collected }}</option>
                        <option value="rejected">{{ tx.rejected }}</option>
                    </select>
                    <button class="cv-btn cv-btn-ghost btn-sm" @click="loadCheckRegister">↺</button>
                </div>
                <div v-if="checkSummary?.length" class="check-bank-grid">
                    <div v-for="bank in checkSummary" :key="bank.bank" class="check-bank-card">
                        <div class="check-bank-name">{{ bank.bank }}</div>
                        <div class="check-bank-amount">{{ fmt(bank.total_amount) }} EGP</div>
                        <div class="check-bank-stats">
                            <span class="cb-stat cb-blue">{{ bank.in_safe }} {{ tx.in_safe }}</span>
                            <span class="cb-stat cb-amber">{{ bank.under_collection }} {{ tx.under_collection }}</span>
                            <span class="cb-stat cb-green">{{ bank.collected }} {{ tx.collected }}</span>
                            <span v-if="bank.rejected" class="cb-stat cb-red">{{ bank.rejected }} {{ tx.rejected }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="checkRegLoading" class="check-loading"><div class="cv-spinner"></div></div>
                <template v-if="!checkRegLoading">
                    <div v-for="group in checkRegData" :key="group.bank" class="check-group">
                        <div class="check-group-header">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 22V12"/><path d="M21 22V12"/><path d="M1 12l11-9 11 9"/></svg>
                            {{ group.bank }}
                            <span class="check-group-total">{{ fmt(group.total_amount) }} EGP</span>
                        </div>
                        <div class="ledger-table-wrap">
                            <table class="ledger-table">
                                <thead>
                                    <tr>
                                        <th>{{ tx.checkNumber }}</th>
                                        <th>{{ tx.receivedDate }}</th>
                                        <th>{{ tx.customer }}</th>
                                        <th class="num-col">{{ tx.amount }}</th>
                                        <th>{{ tx.checkStatus }}</th>
                                        <th>{{ tx.sentToBankDate }}</th>
                                        <th>{{ tx.collectionDate }}</th>
                                        <th v-if="canEditP"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="chk in group.checks" :key="chk.id" :class="rowClass(chk)">
                                        <td><span class="check-num-cell">{{ chk.check_number ?? '—' }}</span></td>
                                        <td class="date-col">{{ chk.check_received_date }}</td>
                                        <td>{{ paymentPartyName(chk) }}</td>
                                        <td class="num-col amount-col">{{ fmt(chk.amount) }}</td>
                                        <td>
                                            <span class="cv-badge" :class="checkStatusColor[chk.check_status]">{{ tx[chk.check_status] }}</span>
                                        </td>
                                        <td class="date-col muted-text">{{ chk.check_sent_to_bank_date ?? '—' }}</td>
                                        <td class="date-col muted-text">{{ chk.check_collection_date ?? '—' }}</td>
                                        <td v-if="canEditP" class="actions-col">
                                            <button v-if="chk.check_status === 'in_safe'" class="action-btn action-btn-blue" :title="tx.sendToBank" @click="openCheckAction(chk, 'send_to_bank')">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 22V12"/><path d="M21 22V12"/><path d="M1 12l11-9 11 9"/></svg>
                                            </button>
                                            <button v-if="chk.check_status === 'under_collection'" class="action-btn action-btn-green" :title="tx.markCollected" @click="openCheckAction(chk, 'collected')">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                            </button>
                                            <button v-if="chk.check_status === 'under_collection'" class="action-btn action-btn-red" :title="tx.markRejected" @click="openCheckAction(chk, 'rejected')">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-if="!checkRegData?.length && !checkRegLoading" class="cv-empty-state">
                        <div class="cv-empty-title">{{ tx.noData }}</div>
                    </div>
                </template>
            </div>

        </div><!-- end cv-page -->

        <!-- ══════════════════════════════════════════════════════
             COLLECTION / PAYMENT MODAL
        ══════════════════════════════════════════════════ -->
        <Transition name="cv-modal-fade">
        <div v-if="showModal" class="cv-modal-backdrop" @click.self="closeModal">
            <div class="cv-modal modal-lg" :dir="isRtl ? 'rtl' : 'ltr'">
                <div class="cv-modal-header">
                    <h2 class="cv-modal-title">
                        {{ modalMode === 'collection' ? tx.newCollection : tx.newPayment }}
                    </h2>
                    <button class="cv-modal-close" @click="closeModal">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="cv-modal-body">
                    <form @submit.prevent="submitForm">
                        <div class="modal-grid">

                            <!-- Category -->
                            <div class="field-full">
                                <label class="cv-label">{{ tx.category }}</label>
                                <select v-model="form.payment_category" class="cv-select" :class="{ 'input-error': form.errors.payment_category }">
                                    <option v-for="cat in (modalMode === 'collection' ? collectionCategories : paymentCategories)"
                                        :key="cat" :value="cat">
                                        {{ tx[cat] }}
                                    </option>
                                </select>
                                <div v-if="form.errors.payment_category" class="field-error">{{ form.errors.payment_category }}</div>
                            </div>

                            <!-- Sub-type -->
                            <template v-if="showSubType">
                                <div>
                                    <label class="cv-label">{{ tx.subType }}</label>
                                    <select v-model="form.sub_type" class="cv-select">
                                        <option value="">—</option>
                                        <option v-for="st in subTypeOptions" :key="st" :value="st">{{ tx[st] }}</option>
                                    </select>
                                </div>
                                <div v-if="form.sub_type === 'other'">
                                    <label class="cv-label">{{ tx.subTypeComment }}</label>
                                    <input v-model="form.sub_type_comment" class="cv-input" type="text" maxlength="255"/>
                                </div>
                            </template>

                            <!-- Payee name -->
                            <div v-if="showPayeeName" class="field-full">
                                <label class="cv-label">{{ tx.payeeName }}</label>
                                <input v-model="form.payee_name" class="cv-input" type="text" maxlength="255"/>
                            </div>

                            <!-- ── MULTI-INVOICE ALLOCATION PANEL ──────────────── -->
                            <!-- 📌 MultiInvoicePaymentAllocation (#46) -->
                            <div v-if="showInvoiceSelector" class="field-full">
                                <label class="cv-label">
                                    {{ tx.invoiceSelector }}
                                    <span class="required-star">*</span>
                                </label>

                                <!-- Add invoice row -->
                                <div class="invoice-add-row">
                                    <select v-model="invoiceToAdd" class="cv-select invoice-pick-select">
                                        <option value="">— {{ tx.invoices }} —</option>
                                        <option v-for="inv in availableInvoicePool" :key="inv.id" :value="inv.id">
                                            {{ n(inv.customer ?? inv.supplier ?? {}, 'name') }}
                                            — {{ inv.reference ?? '#' + inv.id }}
                                            — {{ lang === 'ar' ? 'رصيد' : 'Bal' }}: {{ fmt(inv.balance) }} {{ inv.currency }}
                                        </option>
                                    </select>
                                    <button type="button" class="cv-btn cv-btn-ghost btn-sm" @click="addInvoiceRow"
                                        :disabled="!invoiceToAdd">
                                        {{ tx.addInvoice }}
                                    </button>
                                </div>

                                <!-- No invoices available -->
                                <div v-if="availableInvoicePool.length === 0 && selectedInvoices.length === 0"
                                    class="no-invoices-hint">
                                    {{ tx.noOpenInvoices }}
                                </div>

                                <!-- Allocation table -->
                                <div v-if="selectedInvoices.length" class="allocation-table-wrap">
                                    <table class="allocation-table">
                                        <thead>
                                            <tr>
                                                <th>{{ lang === 'ar' ? 'الفاتورة' : 'Invoice' }}</th>
                                                <th class="num-col">{{ tx.balance }}</th>
                                                <th class="num-col">{{ tx.allocated }}</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(row, idx) in selectedInvoices" :key="row.invoice_id">
                                                <td>
                                                    <div class="alloc-inv-name">
                                                        {{ n(row.invoice.customer ?? row.invoice.supplier ?? {}, 'name') }}
                                                    </div>
                                                    <div class="alloc-inv-ref muted-text">
                                                        {{ row.invoice.reference ?? '#' + row.invoice_id }}
                                                        <span v-if="row.invoice.due_date" class="due-date-hint">
                                                            · {{ lang === 'ar' ? 'استحقاق' : 'due' }} {{ row.invoice.due_date }}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="num-col">
                                                    <span class="balance-display">{{ fmt(row.invoice.balance) }}</span>
                                                    <span class="currency-tag">{{ row.invoice.currency }}</span>
                                                </td>
                                                <td class="num-col">
                                                    <input
                                                        v-model="row.allocated_amount"
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        :max="row.invoice.balance"
                                                        class="cv-input alloc-amount-input"
                                                        :class="{ 'input-error': parseFloat(row.allocated_amount) > parseFloat(row.invoice.balance) }"
                                                    />
                                                    <div v-if="parseFloat(row.allocated_amount) > parseFloat(row.invoice.balance)"
                                                        class="field-error">
                                                        {{ lang === 'ar' ? 'يتجاوز الرصيد' : 'Exceeds balance' }}
                                                    </div>
                                                </td>
                                                <td>
                                                    <button type="button" class="action-btn action-btn-delete"
                                                        @click="removeInvoiceRow(idx)" :title="tx.removeInvoice">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="2" class="alloc-footer-label">{{ tx.allocationTotal }}</td>
                                                <td class="num-col alloc-footer-total" :class="allocationBalanced ? 'alloc-ok' : 'alloc-mismatch'">
                                                    {{ fmt(allocationTotal) }}
                                                </td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>

                                    <!-- Balance indicator -->
                                    <div class="alloc-balance-bar" :class="allocationBalanced ? 'alloc-bar-ok' : 'alloc-bar-warn'">
                                        <template v-if="allocationBalanced">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                            {{ lang === 'ar' ? 'التخصيص متوازن' : 'Allocation balanced' }}
                                        </template>
                                        <template v-else>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                            {{ tx.allocationTotal }}: {{ fmt(allocationTotal) }}
                                            · {{ tx.mustMatchAmount }}: {{ fmt(form.amount || 0) }}
                                            · {{ lang === 'ar' ? 'الفرق' : 'Diff' }}: {{ fmt(Math.abs(allocationDiff)) }}
                                        </template>
                                    </div>
                                </div>

                                <div v-if="form.errors.allocations" class="field-error">{{ form.errors.allocations }}</div>
                            </div>

                            <!-- Expense / GL account -->
                            <div v-if="showExpenseAccount" class="field-full">
                                <label class="cv-label">{{ tx.expenseAccount }}</label>
                                <select v-model="form.expense_account_id" class="cv-select">
                                    <option value="">—</option>
                                    <option v-for="acc in dynamicExpenseAccounts" :key="acc.id" :value="acc.id">
                                        {{ acc.code }} — {{ n(acc, 'name') }}
                                    </option>
                                </select>
                            </div>

                            <!-- Date -->
                            <div>
                                <label class="cv-label">{{ tx.date }}</label>
                                <input v-model="form.date" class="cv-input" type="date" :max="new Date().toISOString().split('T')[0]" :class="{ 'input-error': form.errors.date }"/>
                                <div v-if="form.errors.date" class="field-error">{{ form.errors.date }}</div>
                            </div>

                            <!-- Amount + Currency -->
                            <div>
                                <label class="cv-label">{{ tx.amount }}</label>
                                <div class="amount-currency-row">
                                    <input v-model="form.amount" class="cv-input amount-input" type="number" step="0.01" min="0.01" :class="{ 'input-error': form.errors.amount }"/>
                                    <select v-model="form.currency" class="cv-select currency-select">
                                        <option>EGP</option><option>USD</option><option>EUR</option>
                                        <option>GBP</option><option>SAR</option><option>AED</option>
                                    </select>
                                </div>
                                <div v-if="form.errors.amount" class="field-error">{{ form.errors.amount }}</div>
                            </div>

                            <!-- Exchange Rate -->
                            <div v-if="form.currency && form.currency !== 'EGP'" class="field-full">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'سعر الصرف' : 'Exchange Rate' }}
                                    <span style="color:var(--cv-text-muted);font-weight:400;font-size:0.72rem;">(1 {{ form.currency }} = ? EGP)</span>
                                </label>
                                <div style="display:flex;gap:0.5rem;align-items:center;">
                                    <input v-model="form.exchange_rate" type="number" step="0.000001" min="0.000001"
                                        class="cv-input"
                                        :placeholder="lang === 'ar' ? 'مثال: 50.25' : 'e.g. 50.25'"
                                        :class="{ 'input-error': form.errors.exchange_rate }"/>
                                    <button type="button" class="cv-btn cv-btn-ghost btn-sm"
                                        @click="fetchLatestRate" :disabled="fetchingRate">
                                        {{ fetchingRate ? '…' : (lang === 'ar' ? 'السعر الحالي' : 'Get Rate') }}
                                    </button>
                                </div>
                                <div v-if="form.errors.exchange_rate" class="field-error">{{ form.errors.exchange_rate }}</div>
                                <div v-if="form.amount && form.exchange_rate" style="font-size:0.72rem;color:var(--cv-blue-text);margin-top:0.25rem;">
                                    ≈ {{ fmt(Number(form.amount) * Number(form.exchange_rate)) }} EGP
                                </div>
                            </div>

                            <!-- Method -->
                            <div>
                                <label class="cv-label">{{ tx.method }}</label>
                                <select v-model="form.method" class="cv-select">
                                    <option value="cash">{{ tx.cash }}</option>
                                    <option value="bank_transfer">{{ tx.bank_transfer }}</option>
                                    <option value="bank_deposit">{{ tx.bank_deposit }}</option>
                                    <option value="cheque">{{ tx.cheque }}</option>
                                </select>
                            </div>

                            <!-- Bank name -->
                            <div v-if="isBankMethod">
                                <label class="cv-label">{{ tx.bankName }} <span class="required-star">*</span></label>
                                <input v-model="form.bank_name" class="cv-input" type="text" maxlength="100"
                                    :class="{ 'input-error': form.errors.bank_name }"
                                    :placeholder="lang === 'ar' ? 'مثال: البنك الأهلي' : 'e.g. CIB, NBE, HSBC'"/>
                                <div class="field-hint">{{ tx.bankRequired }}</div>
                                <div v-if="form.errors.bank_name" class="field-error">{{ form.errors.bank_name }}</div>
                            </div>

                            <!-- Bank / Cash account — required for ALL methods -->
                            <div class="field-full">
                                <label class="cv-label">
                                    {{ form.method === 'cash' ? (lang === 'ar' ? 'الخزينة النقدية' : 'Cash Safe Account') : tx.bankAccount }}
                                    <span class="required-star">*</span>
                                    <span style="color:var(--cv-text-muted);font-size:0.7rem;font-weight:400;">
                                        {{ form.method === 'cash' ? (lang === 'ar' ? '(140xx)' : '(140xx safe accounts only)') : (lang === 'ar' ? '(150xx)' : '(150xx bank accounts only)') }}
                                    </span>
                                </label>
                                <select v-model="form.bank_account_id" class="cv-select" :class="{ 'input-error': form.errors.bank_account_id }">
                                    <option value="">— {{ form.method === 'cash' ? (lang === 'ar' ? 'اختر الخزينة' : 'Select Safe') : (lang === 'ar' ? 'اختر الحساب البنكي' : 'Select Bank Account') }} —</option>
                                    <option v-for="acc in filteredBankAccounts" :key="acc.id" :value="acc.id">
                                        {{ acc.code }} — {{ n(acc, 'name') }}
                                        <template v-if="acc.currency && acc.currency !== 'EGP'"> ({{ acc.currency }})</template>
                                    </option>
                                </select>
                                <div v-if="form.errors.bank_account_id" class="field-error">{{ form.errors.bank_account_id }}</div>
                            </div>

                            <!-- Check fields -->
                            <template v-if="isCheck">
                                <div>
                                    <label class="cv-label">{{ tx.checkNumber }}</label>
                                    <input v-model="form.check_number" class="cv-input" type="text" maxlength="50"/>
                                </div>
                                <div>
                                    <label class="cv-label">{{ tx.checkBank }} <span class="required-star">*</span></label>
                                    <input v-model="form.check_bank" class="cv-input" type="text" maxlength="100"
                                        :placeholder="lang === 'ar' ? 'البنك المسحوب عليه' : 'Bank drawn on'"
                                        :class="{ 'input-error': form.errors.check_bank }"/>
                                    <div v-if="form.errors.check_bank" class="field-error">{{ form.errors.check_bank }}</div>
                                </div>
                                <div>
                                    <label class="cv-label">{{ tx.checkDrawnAcct }}</label>
                                    <input v-model="form.check_drawn_on_acct" class="cv-input" type="text" maxlength="50"/>
                                </div>
                            </template>

                            <!-- Reference -->
                            <div>
                                <label class="cv-label">{{ tx.reference }}</label>
                                <input v-model="form.reference" class="cv-input" type="text" maxlength="100"/>
                            </div>

                            <!-- Notes -->
                            <div class="field-full">
                                <label class="cv-label">{{ tx.notes }}</label>
                                <textarea v-model="form.notes" class="cv-textarea" rows="2" maxlength="1000"></textarea>
                            </div>

                        </div><!-- end modal-grid -->
                    </form>
                </div>
                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="closeModal">{{ tx.cancel }}</button>
                    <button class="cv-btn cv-btn-amber" :disabled="form.processing" @click="submitForm">
                        <span v-if="form.processing">…</span>
                        <span v-else>{{ tx.save }}</span>
                    </button>
                </div>
            </div>
        </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════
             CHECK ACTION MODAL
        ══════════════════════════════════════════════════ -->
        <Transition name="cv-modal-fade">
        <div v-if="checkActionModal" class="cv-modal-backdrop" @click.self="checkActionModal = false">
            <div class="cv-modal" :dir="isRtl ? 'rtl' : 'ltr'">
                <div class="cv-modal-header">
                    <h2 class="cv-modal-title">
                        <template v-if="checkAction === 'send_to_bank'">{{ tx.sendToBank }}</template>
                        <template v-else-if="checkAction === 'collected'">{{ tx.markCollected }}</template>
                        <template v-else-if="checkAction === 'rejected'">{{ tx.markRejected }}</template>
                        <template v-else-if="checkAction === 'resubmit'">{{ tx.resubmit }}</template>
                        <template v-else-if="checkAction === 'return'">{{ tx.returnToCustomer }}</template>
                    </h2>
                    <button class="cv-modal-close" @click="checkActionModal = false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="cv-modal-body">
                    <div class="check-summary-card">
                        <div class="csc-row"><span class="csc-label">{{ tx.checkNumber }}</span><span class="csc-value">{{ checkActionTarget?.check_number ?? '—' }}</span></div>
                        <div class="csc-row"><span class="csc-label">{{ tx.checkBank }}</span><span class="csc-value">{{ checkActionTarget?.check_bank ?? checkActionTarget?.bank_name ?? '—' }}</span></div>
                        <div class="csc-row"><span class="csc-label">{{ tx.amount }}</span><span class="csc-value amount-col">{{ fmt(checkActionTarget?.amount) }} {{ checkActionTarget?.currency }}</span></div>
                    </div>
                    <div v-if="checkAction === 'return'" class="danger-note">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span>{{ lang === 'ar' ? 'سيتم عكس جميع الفواتير المرتبطة تلقائياً' : 'All linked AR invoice balances will be automatically reversed.' }}</span>
                    </div>
                    <div v-if="checkAction === 'send_to_bank' || checkAction === 'resubmit'" class="modal-grid">
                        <div class="field-full">
                            <label class="cv-label">{{ tx.bankName }} <span class="required-star">*</span></label>
                            <input v-model="checkForm.bank_name" class="cv-input" type="text" maxlength="100" :placeholder="lang === 'ar' ? 'اسم البنك المستلم للشيك' : 'Bank receiving the check'"/>
                        </div>
                        <div class="field-full">
                            <label class="cv-label">{{ tx.bankAccount }}</label>
                            <select v-model="checkForm.bank_account_id" class="cv-select">
                                <option value="">—</option>
                                <option v-for="acc in dynamicBankAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ n(acc, 'name') }}</option>
                            </select>
                        </div>
                        <div class="field-full">
                            <label class="cv-label">{{ tx.sentToBankDate }}</label>
                            <input v-model="checkForm.check_sent_to_bank_date" class="cv-input" type="date"/>
                        </div>
                    </div>
                    <div v-if="checkAction === 'collected'" class="modal-grid">
                        <div class="field-full">
                            <label class="cv-label">{{ tx.collectionDate }}</label>
                            <input v-model="checkForm.check_collection_date" class="cv-input" type="date"/>
                        </div>
                    </div>
                    <div v-if="checkAction === 'rejected'" class="modal-grid">
                        <div class="field-full">
                            <label class="cv-label">{{ tx.rejectionDate }}</label>
                            <input v-model="checkForm.check_rejection_date" class="cv-input" type="date"/>
                        </div>
                    </div>
                </div>
                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="checkActionModal = false">{{ tx.cancel }}</button>
                    <button class="cv-btn" :class="checkAction === 'return' ? 'cv-btn-danger' : 'cv-btn-amber'"
                        :disabled="checkForm.processing" @click="submitCheckAction">
                        <span v-if="checkForm.processing">…</span>
                        <span v-else>
                            <template v-if="checkAction === 'send_to_bank' || checkAction === 'resubmit'">{{ tx.sendToBank }}</template>
                            <template v-else-if="checkAction === 'collected'">{{ tx.markCollected }}</template>
                            <template v-else-if="checkAction === 'rejected'">{{ tx.markRejected }}</template>
                            <template v-else-if="checkAction === 'return'">{{ tx.returnToCustomer }}</template>
                        </span>
                    </button>
                </div>
            </div>
        </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════
             DELETE CONFIRMATION MODAL
        ══════════════════════════════════════════════════ -->
        <Transition name="cv-modal-fade">
        <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
            <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                <div class="cv-modal-header">
                    <h2 class="cv-modal-title danger-title">{{ tx.deleteConfirm }}</h2>
                </div>
                <div class="cv-modal-body">
                    <p class="delete-msg">{{ tx.deleteMsg }}</p>
                    <div class="check-summary-card">
                        <div class="csc-row"><span class="csc-label">{{ tx.date }}</span><span class="csc-value">{{ deleteTarget?.date }}</span></div>
                        <div class="csc-row"><span class="csc-label">{{ tx.amount }}</span><span class="csc-value amount-col">{{ fmt(deleteTarget?.amount) }}</span></div>
                    </div>
                </div>
                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">{{ tx.cancel }}</button>
                    <button class="cv-btn cv-btn-danger" @click="doDelete">{{ tx.confirmDelete }}</button>
                </div>
            </div>
        </div>
        </Transition>

    
</template>

<style scoped>
/* ── KPI strip ──────────────────────────────────────────────────── */
.kpi-strip { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.kpi-card { flex: 1; min-width: 140px; background: var(--cv-bg-card); border: 1px solid var(--cv-border-md); border-radius: var(--cv-radius-md); padding: 0.875rem 1rem; border-top: 3px solid transparent; }
.kpi-green  { border-top-color: var(--cv-green-bright); }
.kpi-red    { border-top-color: var(--cv-danger); }
.kpi-blue   { border-top-color: var(--cv-blue); }
.kpi-amber  { border-top-color: var(--cv-amber); }
.kpi-danger { border-top-color: var(--cv-danger); }
.kpi-value { font-size: 1.3rem; font-weight: 700; color: var(--cv-blue-text); font-variant-numeric: tabular-nums; }
.kpi-label { font-size: 0.7rem; color: var(--cv-text-muted); margin-top: 0.15rem; text-transform: uppercase; letter-spacing: 0.04em; }

/* ── SA bar ──────────────────────────────────────────────────────── */
.sa-company-bar { padding: 0.75rem 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }

/* ── Rejected safe ───────────────────────────────────────────────── */
.rejected-safe-panel { background: var(--cv-warning-bg); border: 1px solid var(--cv-warning-border); border-radius: var(--cv-radius-md); padding: 0.875rem 1rem; margin-bottom: 1.25rem; }
.rejected-safe-header { display: flex; align-items: center; gap: 0.5rem; color: var(--cv-amber); font-weight: 700; font-size: 0.85rem; margin-bottom: 0.75rem; }
.rejected-safe-title { flex: 1; }
.rejected-safe-count { background: var(--cv-amber); color: #000; border-radius: 99px; padding: 0 0.4rem; font-size: 0.72rem; font-weight: 800; }
.rejected-safe-list { display: flex; flex-direction: column; gap: 0.5rem; }
.rejected-safe-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.5rem 0.75rem; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); border: 1px solid var(--cv-border-md); flex-wrap: wrap; }
.rejected-safe-info { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; font-size: 0.8rem; }
.rejected-check-num { font-weight: 700; color: var(--cv-text-primary); }
.rejected-check-bank { color: var(--cv-text-muted); }
.rejected-check-amount { color: var(--cv-blue-text); font-variant-numeric: tabular-nums; }
.rejected-check-customer { color: var(--cv-text-secondary); }
.rejected-safe-actions { display: flex; gap: 0.5rem; }

/* ── Tab bar ─────────────────────────────────────────────────────── */
.tab-bar { display: flex; gap: 0.25rem; border-bottom: 1px solid var(--cv-border-md); margin-bottom: 1.25rem; }
.tab-btn { display: flex; align-items: center; gap: 0.4rem; padding: 0.6rem 1rem; border: none; background: none; color: var(--cv-text-muted); font-size: 0.8125rem; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color 0.15s, border-color 0.15s; border-radius: var(--cv-radius-sm) var(--cv-radius-sm) 0 0; }
.tab-btn:hover { color: var(--cv-text-secondary); background: var(--cv-bg-hover); }
.tab-btn.active { color: var(--cv-amber); border-bottom-color: var(--cv-amber); background: var(--cv-amber-light); }
.tab-count { background: var(--cv-bg-hover); color: var(--cv-text-muted); border-radius: 99px; padding: 0 0.35rem; font-size: 0.68rem; font-weight: 700; }
.tab-count-red { background: var(--cv-danger-bg); color: var(--cv-danger-text); }

/* ── Filter bar ──────────────────────────────────────────────────── */
.filter-bar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.filter-select { min-width: 140px; }

/* ── Ledger table ────────────────────────────────────────────────── */
.ledger-table-wrap { overflow-x: auto; border-radius: var(--cv-radius-md); border: 1px solid var(--cv-border-md); }
.ledger-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
.ledger-table thead tr { background: var(--cv-bg-card-alt); border-bottom: 1px solid var(--cv-border-md); }
.ledger-table th { padding: 0.6rem 0.875rem; text-align: start; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--cv-text-muted); white-space: nowrap; }
.ledger-table td { padding: 0.625rem 0.875rem; border-bottom: 1px solid var(--cv-border-md); color: var(--cv-text-secondary); vertical-align: middle; }
.ledger-table tbody tr:last-child td { border-bottom: none; }
.ledger-table tbody tr:hover { background: var(--cv-bg-hover); }
.ledger-table tbody tr.row-rejected { background: rgba(224,112,112,0.04); }
.ledger-table tbody tr.row-collected { background: rgba(92,196,106,0.04); }
.ledger-table tbody tr.row-under-collection { background: rgba(232,160,48,0.04); }
.num-col { text-align: end; }
.date-col { white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--cv-text-muted); font-size: 0.77rem; }
.amount-col { color: var(--cv-blue-text); font-weight: 700; font-variant-numeric: tabular-nums; }
.amount-out { color: var(--cv-danger-text); }
.currency-tag { font-size: 0.68rem; color: var(--cv-text-muted); margin-inline-start: 0.2rem; }
.muted-text { color: var(--cv-text-muted); font-size: 0.78rem; }
.category-label { color: var(--cv-text-primary); font-weight: 500; font-size: 0.8rem; }
.sub-type-pill { display: inline-block; margin-top: 0.2rem; padding: 0.1rem 0.4rem; background: var(--cv-blue-light); color: var(--cv-blue-text); border-radius: 99px; font-size: 0.68rem; }
.method-cell { display: flex; align-items: center; gap: 0.3rem; }
.bank-sub { color: var(--cv-text-muted); font-size: 0.72rem; margin-top: 0.15rem; }
.check-sub { color: var(--cv-amber-text); font-size: 0.72rem; font-weight: 600; }
.check-num-cell { font-weight: 700; color: var(--cv-amber-text); font-family: monospace; font-size: 0.85rem; }

/* ── Allocation display in ledger ────────────────────────────────── */
.alloc-row-display { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem; padding: 0.05rem 0; }
.alloc-ref { color: var(--cv-text-secondary); }
.alloc-amt { color: var(--cv-blue-text); font-variant-numeric: tabular-nums; font-weight: 600; }

/* Badge variants */
.badge-blue   { background: var(--cv-blue-light);    color: var(--cv-blue-text);    border: 1px solid var(--cv-blue-border); }
.badge-amber  { background: var(--cv-amber-light);   color: var(--cv-amber-text);   border: 1px solid var(--cv-amber-border); }
.badge-green  { background: var(--cv-success-bg);    color: var(--cv-success-text); border: 1px solid var(--cv-success-border); }
.badge-red    { background: var(--cv-danger-bg);     color: var(--cv-danger-text);  border: 1px solid var(--cv-danger-border); }
.badge-muted  { background: var(--cv-bg-hover);      color: var(--cv-text-muted);   border: 1px solid var(--cv-border-md); }

/* ── Action buttons ──────────────────────────────────────────────── */
.actions-col { white-space: nowrap; }
.action-btn { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: var(--cv-radius-sm); border: 1px solid var(--cv-border-md); background: transparent; cursor: pointer; color: var(--cv-text-muted); margin-inline-start: 0.25rem; transition: color 0.15s, background 0.15s; }
.action-btn-blue:hover   { color: var(--cv-blue);         background: var(--cv-blue-light);   border-color: var(--cv-blue-border); }
.action-btn-green:hover  { color: var(--cv-green-bright); background: var(--cv-green-dim);    border-color: var(--cv-green-border); }
.action-btn-red:hover    { color: var(--cv-danger-text);  background: var(--cv-danger-bg);    border-color: var(--cv-danger-border); }
.action-btn-delete:hover { color: var(--cv-danger-text);  background: var(--cv-danger-bg);    border-color: var(--cv-danger-border); }

/* ── Pagination ──────────────────────────────────────────────────── */
.pagination-row { display: flex; gap: 0.25rem; justify-content: center; padding: 1rem 0 0.25rem; flex-wrap: wrap; }
.page-link { padding: 0.3rem 0.6rem; border-radius: var(--cv-radius-sm); font-size: 0.78rem; color: var(--cv-text-muted); border: 1px solid var(--cv-border-md); background: var(--cv-bg-card); text-decoration: none; transition: color 0.15s, background 0.15s; }
.page-link:hover  { color: var(--cv-amber); background: var(--cv-amber-light); }
.page-link.active { color: var(--cv-amber); background: var(--cv-amber-light); border-color: var(--cv-amber-border); }
.page-link.disabled { opacity: 0.35; pointer-events: none; }

/* ── Check Register ──────────────────────────────────────────────── */
.check-bank-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.check-bank-card { background: var(--cv-bg-card); border: 1px solid var(--cv-border-md); border-radius: var(--cv-radius-md); padding: 0.875rem 1rem; }
.check-bank-name { font-weight: 700; font-size: 0.85rem; color: var(--cv-text-primary); margin-bottom: 0.25rem; }
.check-bank-amount { font-size: 1.05rem; color: var(--cv-blue-text); font-weight: 700; font-variant-numeric: tabular-nums; margin-bottom: 0.5rem; }
.check-bank-stats { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.cb-stat { font-size: 0.68rem; font-weight: 600; padding: 0.1rem 0.35rem; border-radius: 99px; }
.cb-blue  { background: var(--cv-blue-light);   color: var(--cv-blue-text); }
.cb-amber { background: var(--cv-amber-light);  color: var(--cv-amber-text); }
.cb-green { background: var(--cv-success-bg);   color: var(--cv-success-text); }
.cb-red   { background: var(--cv-danger-bg);    color: var(--cv-danger-text); }

.check-group { margin-bottom: 1.25rem; }
.check-group-header { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.875rem; background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border-md); border-bottom: none; border-radius: var(--cv-radius-sm) var(--cv-radius-sm) 0 0; font-weight: 700; font-size: 0.82rem; color: var(--cv-text-primary); }
.check-group-total { margin-inline-start: auto; color: var(--cv-blue-text); font-variant-numeric: tabular-nums; }
.check-loading { display: flex; justify-content: center; padding: 2rem; }

/* ── Modal ───────────────────────────────────────────────────────── */
.modal-lg { max-width: 720px; }
.modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.875rem 1rem; }
.field-full { grid-column: 1 / -1; }
.amount-currency-row { display: flex; gap: 0.5rem; }
.amount-input { flex: 1; }
.currency-select { width: 80px; }
.field-hint { font-size: 0.7rem; color: var(--cv-amber-text); margin-top: 0.25rem; }
.required-star { color: var(--cv-danger-text); }
.input-error { border-color: var(--cv-danger) !important; }
.field-error { font-size: 0.7rem; color: var(--cv-danger-text); margin-top: 0.2rem; }

/* ── Multi-invoice allocation UI ─────────────────────────────────── */
.invoice-add-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
.invoice-pick-select { flex: 1; }

.no-invoices-hint { font-size: 0.78rem; color: var(--cv-text-muted); padding: 0.5rem 0; }

.allocation-table-wrap { border: 1px solid var(--cv-border-md); border-radius: var(--cv-radius-sm); overflow: hidden; margin-top: 0.5rem; }
.allocation-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
.allocation-table th { padding: 0.45rem 0.75rem; background: var(--cv-bg-card-alt); font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--cv-text-muted); text-align: start; border-bottom: 1px solid var(--cv-border-md); }
.allocation-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--cv-border-md); vertical-align: middle; }
.allocation-table tbody tr:last-child td { border-bottom: none; }
.allocation-table tfoot td { padding: 0.45rem 0.75rem; background: var(--cv-bg-card-alt); font-weight: 700; border-top: 1px solid var(--cv-border-md); }

.alloc-inv-name { font-weight: 600; color: var(--cv-text-primary); }
.alloc-inv-ref { font-size: 0.73rem; margin-top: 0.1rem; }
.due-date-hint { color: var(--cv-amber-text); }
.balance-display { color: var(--cv-blue-text); font-variant-numeric: tabular-nums; font-weight: 600; }
.alloc-amount-input { width: 110px; text-align: end; padding: 0.25rem 0.4rem; font-size: 0.82rem; }
.alloc-footer-label { color: var(--cv-text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
.alloc-footer-total { font-size: 0.92rem; }
.alloc-ok { color: var(--cv-green-bright); }
.alloc-mismatch { color: var(--cv-danger-text); }

.alloc-balance-bar { display: flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.75rem; font-size: 0.75rem; font-weight: 600; border-top: 1px solid var(--cv-border-md); }
.alloc-bar-ok   { background: var(--cv-success-bg); color: var(--cv-success-text); }
.alloc-bar-warn { background: var(--cv-warning-bg); color: var(--cv-amber-text); }

/* Check summary card */
.check-summary-card { background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border-md); border-radius: var(--cv-radius-sm); padding: 0.75rem 1rem; margin-bottom: 1rem; }
.csc-row { display: flex; justify-content: space-between; padding: 0.2rem 0; font-size: 0.82rem; }
.csc-label { color: var(--cv-text-muted); }
.csc-value { color: var(--cv-text-primary); font-weight: 600; }
.danger-note { display: flex; gap: 0.5rem; align-items: flex-start; background: var(--cv-danger-bg); border: 1px solid var(--cv-danger-border); border-radius: var(--cv-radius-sm); padding: 0.6rem 0.75rem; color: var(--cv-danger-text); font-size: 0.8rem; margin-bottom: 0.75rem; }
.danger-title { color: var(--cv-danger-text); }
.delete-msg { color: var(--cv-text-secondary); margin-bottom: 0.75rem; font-size: 0.85rem; }

/* ── Misc ────────────────────────────────────────────────────────── */
.btn-sm { padding: 0.3rem 0.65rem; font-size: 0.75rem; }
.cv-modal-fade-enter-active { transition: opacity 0.18s ease, transform 0.18s ease; }
.cv-modal-fade-leave-active { transition: opacity 0.12s ease; }
.cv-modal-fade-enter-from  { opacity: 0; transform: scale(0.97); }
.cv-modal-fade-leave-to    { opacity: 0; }
.cv-spinner { width: 28px; height: 28px; border: 3px solid var(--cv-border-md); border-top-color: var(--cv-amber); border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 640px) {
    .modal-grid { grid-template-columns: 1fr; }
    .field-full { grid-column: 1; }
    .kpi-strip { gap: 0.5rem; }
    .kpi-card  { min-width: 100px; }
    .tab-btn   { padding: 0.5rem 0.6rem; font-size: 0.75rem; }
    .invoice-add-row { flex-direction: column; align-items: stretch; }
}
</style>