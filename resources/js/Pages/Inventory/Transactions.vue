<script setup>
/**
 * Inventory/Transactions.vue
 * Transaction ledger for one master item.
 * Modal A: Record transaction (receipt / consumption / adjustment)
 * Modal B: Record transfer (between two locations — atomic pair)
 */
import { ref, computed, watch } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    item:           Object,
    po:             { type: Object, default: null },  // pre-filled PO context from procurement
    locationStocks: Array,   // [{stock_id, location_id, location_name_en/ar, farm_name_en/ar, is_central, qty_on_hand, unit_cost}]
    transactions:   Object,  // paginated
    locations:      Array,
    suppliers:      Array,   // 📌 InventoryReceiptAccrual — for receipt supplier selector
    accrualSummary: Array,   // 📌 InventoryReceiptAccrual — unmatched accruals per supplier
    filters:        Object,
})

// ── Filters ───────────────────────────────────────────────────────
const filters = ref({
    location_id:    props.filters?.location_id    ?? '',
    type:           props.filters?.type           ?? '',
    date_from:      props.filters?.date_from      ?? '',
    date_to:        props.filters?.date_to        ?? '',
    unmatched_only: props.filters?.unmatched_only ?? '',  // 📌 InventoryReceiptAccrual
})
function applyFilters() {
    router.get(route('inventory.transactions', props.item.id), filters.value, { preserveState:true, replace:true })
}
// 📌 InventoryReceiptAccrual — toggle unmatched-only filter from accrual panel
function toggleUnmatched() {
    filters.value.unmatched_only = filters.value.unmatched_only ? '' : '1'
    applyFilters()
}

// ── Transaction Modal (receipt / consumption / adjustment) ────────
const txModal      = ref(false)
const txSubmitting = ref(false)
const poLineBeingReceived = ref(null)
const txForm   = useForm({
    stock_id:    '',
    location_id: '',
    type:        'receipt',
    date:        new Date().toISOString().slice(0,10),
    qty:         '',
    unit_cost:   '',
    supplier_id: '',   // 📌 InventoryReceiptAccrual — set on receipt only
    ref_no:      '',   // 📌 InventoryReceiptAccrual — delivery note / GRN / PO ref
    notes:       '',
})

const selectedLocationStock = computed(() =>
    props.locationStocks.find(s => s.location_id == txForm.location_id) ?? null
)

// Auto-fill unit_cost from location stock when location selected
watch(() => txForm.location_id, (locId) => {
    const s = props.locationStocks.find(s => s.location_id == locId)
    if (s) {
        txForm.stock_id   = s.stock_id
        txForm.unit_cost  = s.unit_cost
    }
})

function openTxModal(poLine = null) {
    txForm.reset()
    txForm.type = 'receipt'
    txForm.date = new Date().toISOString().slice(0,10)
    poLineBeingReceived.value = poLine ?? null

       if (poLine) {
        txForm.location_id  = poLine.location_id ?? ''
        txForm.qty          = poLine.remaining_qty
        txForm.unit_cost    = ''
        txForm.supplier_id  = props.po?.supplier_id ?? ''
        txForm.ref_no       = props.po?.po_reference ?? ''
    } else if (props.po) {
        txForm.supplier_id  = props.po.supplier_id ?? ''
        txForm.ref_no       = props.po.po_reference ?? ''
    }

    txModal.value = true
}
function submitTx() {
    if (props.po && poLineBeingReceived.value) {
        txSubmitting.value = true
        router.post(
            route('procurement.confirm-inventory-receipt', props.po.id),
            {
                batch_date:  txForm.date,
                batch_notes: txForm.notes,
                lines: [{
                    procurement_item_line_id: poLineBeingReceived.value.id,
                    qty_received:             txForm.qty,
                    unit_cost_actual:         txForm.unit_cost,
                    location_id:              txForm.location_id,
                    notes:                    txForm.notes,
                }],
            },
            {
                onSuccess: () => {
                    txSubmitting.value = false
                    txModal.value = false
                    txForm.reset()
                    poLineBeingReceived.value = null
                },
                onError: (errors) => {
                    txSubmitting.value = false
                    const firstError = Object.values(errors)[0]
                    if (firstError) alert(firstError)
                },
                onFinish: () => { txSubmitting.value = false },
            }
        )
        return
    }

    txForm.post(route('inventory.record-transaction'), {
        onSuccess: () => { txModal.value = false; txForm.reset() },
    })
}

// ── Transfer Modal ────────────────────────────────────────────────
const trfModal = ref(false)
const trfForm  = useForm({
    master_item_id:     props.item.id,
    source_location_id: '',
    dest_location_id:   '',
    qty:                '',
    date:               new Date().toISOString().slice(0,10),
    notes:              '',
})

const sourceStock = computed(() =>
    props.locationStocks.find(s => s.location_id == trfForm.source_location_id) ?? null
)
const destLocOptions = computed(() =>
    props.locationStocks.filter(s => s.location_id != trfForm.source_location_id)
)

function openTrfModal() {
    trfForm.reset()
    trfForm.master_item_id = props.item.id
    trfForm.date = new Date().toISOString().slice(0,10)
    trfModal.value = true
}
function submitTrf() {
    trfForm.post(route('inventory.record-transfer'), {
        onSuccess: () => { trfModal.value = false; trfForm.reset() },
    })
}

// ── Reversal ──────────────────────────────────────────────────────
// Opens the existing tx modal pre-filled as an adjustment with
// opposite qty, same unit_cost, and a reversal note.
// Applies to: receipt (reverse = negative adjustment)
//             consumption (reverse = positive adjustment)
const reversalModal  = ref(false)
const reversalTarget = ref(null)   // the tx being reversed

function openReversal(tx) {
    reversalTarget.value = tx
    reversalModal.value  = true
}

function confirmReversal() {
    const tx = reversalTarget.value
    // Resolve stock_id from locationStocks by matching location_id
    const ls = props.locationStocks.find(s => s.location_id == tx.location_id)
    if (!ls) return

    txForm.reset()
    txForm.stock_id    = ls.stock_id
    txForm.location_id = tx.location_id
    txForm.type        = 'adjustment'
    txForm.date        = new Date().toISOString().slice(0, 10)
    // receipt reversal = negative qty; consumption reversal = positive qty
    txForm.qty         = tx.type === 'receipt' ? -Number(tx.qty) : Number(tx.qty)
    txForm.unit_cost   = tx.unit_cost
    txForm.notes       = (lang.value === 'ar' ? 'عكس حركة #' : 'Reversal of tx #') + tx.id

    reversalModal.value = false
    txModal.value       = true
}

// ── Supplier patch popup ──────────────────────────────────────────
// Small focused popup — only supplier_id and ref_no.
// Only available on receipt rows where ap_invoice_id is null.
const supplierModal  = ref(false)
const supplierTarget = ref(null)
const supplierForm   = useForm({ supplier_id: '', ref_no: '' })

function openSupplierEdit(tx) {
    supplierTarget.value    = tx
    supplierForm.supplier_id = tx.supplier_id ?? ''
    supplierForm.ref_no      = tx.ref_no      ?? ''
    supplierModal.value      = true
}

function submitSupplierEdit() {
    supplierForm.patch(
        route('inventory.patch-supplier', supplierTarget.value.id),
        { onSuccess: () => { supplierModal.value = false; supplierForm.reset() } }
    )
}

// ── Helpers ───────────────────────────────────────────────────────
function locLabel(s) {
    if (!s) return '—'
    const name = lang.value==='ar' ? (s.location_name_ar||s.location_name_en) : (s.location_name_en||s.location_name_ar)
    const farm = lang.value==='ar' ? (s.farm_name_ar||s.farm_name_en) : (s.farm_name_en||s.farm_name_ar)
    return s.is_central ? `🏢 ${name}` : `🏠 ${farm?farm+' — ':''}${name}`
}

function txLocLabel(tx) {
    const name = lang.value==='ar' ? (tx.location_name_ar||tx.location_name_en) : (tx.location_name_en||tx.location_name_ar)
    return name ?? '—'
}

function counterpartLabel(tx) {
    if (!tx.counterpart_location_id) return null
    const name = lang.value==='ar' ? (tx.counterpart_name_ar||tx.counterpart_name_en) : (tx.counterpart_name_en||tx.counterpart_name_ar)
    return name
}

// 📌 LivestockOperationalCostFlow (#43) §8
// For feed/medicine/vaccine items, consumption is system-only.
// The 'consumption' type is kept in typeConfig for DISPLAY purposes
// (existing rows in the ledger still show their badge) but it is
// intentionally excluded from t.types so it never appears as a
// manual entry option in the Record Transaction modal.
const isOperationalCategory = computed(() =>
    ['feed', 'medicine', 'vaccine'].includes(props.item?.category)
)

const typeConfig = computed(() => ({
    receipt:      { label: lang.value==='ar'?'استلام':'Receipt',       color:'var(--cv-green-bright)', sign:'+' },
    consumption:  { label: lang.value==='ar'?'استهلاك':'Consumption',  color:'var(--cv-danger-text)',  sign:'-' },
    adjustment:   { label: lang.value==='ar'?'تسوية':'Adjustment',     color:'var(--cv-amber)',         sign:'±' },
    transfer_in:  { label: lang.value==='ar'?'تحويل وارد':'Transfer In', color:'var(--cv-blue-text)', sign:'+' },
    transfer_out: { label: lang.value==='ar'?'تحويل صادر':'Transfer Out',color:'var(--cv-text-muted)',sign:'-' },
}))

function fmt(v) {
    if (v==null) return '—'
    return Number(v).toLocaleString(lang.value==='ar'?'ar-EG':'en-US',{minimumFractionDigits:2,maximumFractionDigits:2})
}
function fmtQty(v) {
    if (v==null) return '—'
    return Number(v).toLocaleString(lang.value==='ar'?'ar-EG':'en-US',{minimumFractionDigits:0,maximumFractionDigits:3})
}

function itemName(item) {
    return lang.value==='ar' ? (item.name_ar||item.name_en||'—') : (item.name_en||item.name_ar||'—')
}

const t = computed(() => lang.value === 'ar' ? {
    eyebrow:'المخزون', backToInventory:'العودة للمخزون',
    txTitle:'سجل الحركات', totalStock:'إجمالي المخزون',
    addTx:'تسجيل حركة', addTransfer:'تحويل بين مواقع',
    filterLoc:'الموقع', filterType:'النوع', dateFrom:'من', dateTo:'إلى', apply:'تطبيق',
    allLocs:'كل المواقع', allTypes:'كل الأنواع',
    date:'التاريخ', type:'النوع', location:'الموقع', qty:'الكمية',
    cost:'التكلفة', total:'الإجمالي', balance:'الرصيد', recorder:'المسجّل', notes:'ملاحظات',
    noTx:'لا توجد حركات مخزون', noTxSub:'سجّل أول حركة لهذا الصنف',
    // Transaction modal
    txModalTitle:'تسجيل حركة مخزون',
    txType:'نوع الحركة', txLoc:'الموقع', txQty:'الكمية', txCost:'تكلفة الوحدة', txDate:'التاريخ', txNotes:'ملاحظات',
    txSave:'حفظ الحركة', txCancel:'إلغاء',
    availableStock:'المخزون المتاح:',
    txSupplier:'المورد (اختياري)', txRefNo:'رقم إذن الاستلام / المرجع',
    txSupplierPlaceholder:'بدون مورد — لا يُسجَّل مستحق',
    txAccrualNote:'سيُسجَّل هذا الإيصال في مستحقات الموردين (AP Accruals) حتى يُطابَق بفاتورة.',
    accrualPanelTitle:'مستحقات غير مطابقة مع فواتير — تحتاج متابعة',
    accrualOldest:'أقدم:', accrualReceipts:'إيصال', accrualTotal:'إجمالي المستحقات',
    accrualShowUnmatched:'عرض غير المطابق فقط', accrualShowAll:'عرض الكل',
    accrualBadge:'مستحق', matchedBadge:'مطابق',
    supplier:'المورد / المرجع',
    actions:'إجراءات',
    reverseBtn:'عكس', reversalTitle:'عكس الحركة',
    reversalNote:'سيُسجَّل تسوية بكمية معاكسة لإلغاء هذه الحركة. لا يمكن حذف الحركات الأصلية.',
    reversalConfirm:'تأكيد العكس', reversalCancel:'إلغاء',
    editSupplierBtn:'تعديل المورد', editSupplierTitle:'تعديل المورد والمرجع',
    editSupplierSave:'حفظ', editSupplierCancel:'إلغاء',
    editSupplierLocked:'لا يمكن التعديل — الإيصال مطابق بفاتورة.',
    // 📌 LivestockOperationalCostFlow (#43) §8 — consumption removed from manual entry
    // Feed/medicine/vaccine consumption is system-only via their operational modules
    types: { receipt:'استلام', adjustment:'تسوية' },
    // Transfer modal
    trfModalTitle:'تحويل بين مواقع',
    trfFrom:'من موقع', trfTo:'إلى موقع', trfQty:'الكمية', trfDate:'التاريخ', trfNotes:'ملاحظات',
    trfSave:'تنفيذ التحويل', trfCancel:'إلغاء',
    trfAvail:'متاح:',
} : {
    eyebrow:'Inventory', backToInventory:'Back to Inventory',
    txTitle:'Transaction Ledger', totalStock:'Total Stock',
    addTx:'Record Transaction', addTransfer:'Transfer Between Locations',
    filterLoc:'Location', filterType:'Type', dateFrom:'From', dateTo:'To', apply:'Apply',
    allLocs:'All Locations', allTypes:'All Types',
    date:'Date', type:'Type', location:'Location', qty:'Qty',
    cost:'Unit Cost', total:'Total', balance:'Balance', recorder:'Recorded By', notes:'Notes',
    noTx:'No transactions found', noTxSub:'Record the first movement for this item',
    txModalTitle:'Record Stock Movement',
    txType:'Transaction Type', txLoc:'Location', txQty:'Quantity', txCost:'Unit Cost', txDate:'Date', txNotes:'Notes',
    txSave:'Save Transaction', txCancel:'Cancel',
    availableStock:'Available stock:',
    txSupplier:'Supplier (optional)', txRefNo:'Delivery Ref No (GRN / PO)',
    txSupplierPlaceholder:'No supplier — no accrual tracked',
    txAccrualNote:'This receipt will post to AP Accruals until matched to a supplier invoice.',
    accrualPanelTitle:'Unmatched AP Accruals — Pending Invoice Match',
    accrualOldest:'Oldest:', accrualReceipts:'receipt(s)', accrualTotal:'Total Accruals',
    accrualShowUnmatched:'Show Unmatched Only', accrualShowAll:'Show All',
    accrualBadge:'Accrued', matchedBadge:'Matched',
    supplier:'Supplier / Ref',
    actions:'Actions',
    reverseBtn:'Reverse', reversalTitle:'Reverse Transaction',
    reversalNote:'A correcting adjustment will be posted with the opposite qty. Original transactions cannot be deleted.',
    reversalConfirm:'Confirm Reversal', reversalCancel:'Cancel',
    editSupplierBtn:'Edit Supplier', editSupplierTitle:'Edit Supplier & Ref',
    editSupplierSave:'Save', editSupplierCancel:'Cancel',
    editSupplierLocked:'Cannot edit — receipt is already matched to an invoice.',
    // 📌 LivestockOperationalCostFlow (#43) §8 — consumption removed from manual entry
    types: { receipt:'Receipt', adjustment:'Adjustment' },
    trfModalTitle:'Transfer Between Locations',
    trfFrom:'From Location', trfTo:'To Location', trfQty:'Quantity', trfDate:'Date', trfNotes:'Notes',
    trfSave:'Execute Transfer', trfCancel:'Cancel',
    trfAvail:'Available:',
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ itemName(item) }}</div>
                <div class="cv-page-hero-sub">{{ t.txTitle }}</div>
                <div class="cv-page-hero-actions">
                    <Link :href="route('inventory.index')" class="cv-btn cv-btn-ghost">← {{ t.backToInventory }}</Link>
                    <button class="cv-btn cv-btn-ghost" @click="openTrfModal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="14" height="14"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                        {{ t.addTransfer }}
                    </button>
                    <button v-if="!po" class="cv-btn cv-btn-amber" @click="openTxModal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="14" height="14"><path d="M12 5v14M5 12h14"/></svg>
                        {{ t.addTx }}
                    </button>
                </div>
            </div>


            <div class="cv-page">

                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">{{ $page.props.flash.success }}</div>
                <div v-if="$page.props.flash?.error"   class="cv-alert cv-alert-danger  mb-4">{{ $page.props.flash.error }}</div>

                <!-- ══ PO CONTEXT BANNER (shown when navigated from a PO) ══ -->
                <div v-if="po" style="margin-bottom:1.25rem;padding:.85rem 1.1rem;background:color-mix(in srgb,var(--cv-amber) 8%,var(--cv-bg-card));border:1px solid var(--cv-amber-border);border-radius:var(--cv-radius);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap" :dir="isRtl ? 'rtl' : 'ltr'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0">
                        <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
                        <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>
                    </svg>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.8rem;font-weight:700;color:var(--cv-amber)">
                            {{ lang === 'ar' ? 'استلام بضاعة من أمر شراء' : 'Receiving against PO' }}
                            <span style="margin-inline-start:.35rem">{{ po.po_reference || ('#' + po.id) }}</span>
                        </div>
                        <div style="font-size:.72rem;color:var(--cv-text-muted);margin-top:2px">
                            {{ lang === 'ar' ? 'المورد:' : 'Supplier:' }}
                            {{ lang === 'ar' ? (po.supplier?.name_ar || po.supplier?.name_en) : (po.supplier?.name_en || po.supplier?.name_ar) }}
                            &nbsp;·&nbsp;
                            {{ (po.item_lines ?? []).filter(l => l.line_status === 'open').length }}
                            {{ lang === 'ar' ? 'بند مفتوح' : 'open line(s)' }}
                        </div>
                    </div>
                    <Link :href="route('procurement.show', po.id)" class="cv-btn cv-btn-ghost" style="font-size:.75rem;padding:.3rem .7rem">
                        {{ lang === 'ar' ? 'عرض أمر الشراء' : 'View PO' }}
                    </Link>
                </div>

                <!-- ══ PO ITEM LINES (quick receive table — only when po is set) ══ -->
                <div v-if="po && (po.item_lines ?? []).length > 0" class="cv-card mb-5" style="padding:0;overflow:hidden">
                    <div style="padding:.65rem 1rem;font-size:.72rem;font-weight:600;color:var(--cv-text-muted);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                        {{ lang === 'ar' ? 'بنود أمر الشراء — اضغط لاستلام' : 'PO Lines — click to receive' }}
                    </div>
                    <div v-for="line in po.item_lines" :key="line.id"
                         style="display:flex;align-items:center;gap:1rem;padding:.65rem 1rem;border-bottom:1px solid var(--cv-border);font-size:.8rem;flex-wrap:wrap">
                        <div style="flex:1;min-width:140px">
                            <div style="font-weight:600;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? (line.item_name_ar || line.item_name_en) : (line.item_name_en || line.item_name_ar) }}
                            </div>
                            <div style="font-size:.68rem;color:var(--cv-text-muted)">
                                {{ lang === 'ar' ? (line.location_name_ar || line.location_name_en) : (line.location_name_en || line.location_name_ar) }}
                            </div>
                        </div>
                        <div style="font-size:.75rem;color:var(--cv-text-secondary);white-space:nowrap">
                            {{ lang === 'ar' ? 'مطلوب:' : 'Ordered:' }}
                            <strong style="color:var(--cv-blue-text)">{{ line.qty_ordered }}</strong>
                            &nbsp;·&nbsp;
                            {{ lang === 'ar' ? 'مستلم:' : 'Received:' }}
                            <strong :style="line.line_status === 'fully_received' ? 'color:var(--cv-green-bright)' : 'color:var(--cv-amber)'">{{ line.qty_received }}</strong>
                            &nbsp;·&nbsp;
                            {{ lang === 'ar' ? 'متبقي:' : 'Remaining:' }}
                            <strong style="color:var(--cv-text-primary)">{{ line.remaining_qty }}</strong>
                        </div>
                        <span class="cv-badge" :class="line.line_status === 'fully_received' ? 'cv-badge-green' : line.line_status === 'over_received' ? 'cv-badge-danger' : 'cv-badge-amber'">
                            {{ line.line_status === 'fully_received' ? (lang === 'ar' ? 'مكتمل' : 'Complete') : line.line_status === 'over_received' ? (lang === 'ar' ? 'زيادة' : 'Over') : (lang === 'ar' ? 'مفتوح' : 'Open') }}
                        </span>
                        <button v-if="line.line_status === 'open'"
                                class="cv-btn cv-btn-amber" style="font-size:.72rem;padding:.3rem .75rem;white-space:nowrap"
                                @click="openTxModal(line)">
                            {{ lang === 'ar' ? 'استلام' : 'Receive' }}
                        </button>
                    </div>
                </div>

                <!-- Stock summary by location -->

                <div class="stock-summary mb-5">
                    <div v-for="s in locationStocks" :key="s.location_id" class="stock-loc-chip"
                         :class="{'chip-danger': s.is_out_of_stock, 'chip-amber': s.is_low_stock && !s.is_out_of_stock}">
                        <span class="chip-name">{{ locLabel(s) }}</span>
                        <span class="chip-qty">{{ fmtQty(s.qty_on_hand) }} {{ item.unit }}</span>
                    </div>
                </div>

                <!-- ── 📌 InventoryReceiptAccrual — Accrual Warning Panel ── -->
                <!-- Shown whenever unmatched supplier receipts exist.         -->
                <!-- Amber panel — each row: supplier, receipt count, oldest   -->
                <!-- date, and total unmatched value. Critical for AP control. -->
                <div v-if="accrualSummary && accrualSummary.length > 0" class="accrual-panel mb-4">
                    <div class="accrual-panel-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="18" height="18" style="flex-shrink:0">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        <span style="flex:1">{{ t.accrualPanelTitle }}</span>
                        <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="toggleUnmatched">
                            {{ filters.unmatched_only ? t.accrualShowAll : t.accrualShowUnmatched }}
                        </button>
                    </div>
                    <div class="accrual-rows">
                        <div v-for="row in accrualSummary" :key="row.supplier_id" class="accrual-row">
                            <span class="accrual-supplier">
                                {{ lang==='ar' ? (row.supplier_name_ar||row.supplier_name_en) : (row.supplier_name_en||row.supplier_name_ar) }}
                            </span>
                            <span class="accrual-badge-pill">{{ row.receipt_count }} {{ t.accrualReceipts }}</span>
                            <span class="accrual-oldest">{{ t.accrualOldest }} {{ row.oldest_receipt_date }}</span>
                            <span class="accrual-amount">{{ fmt(row.total_accrual) }}</span>
                        </div>
                        <div class="accrual-total-row">
                            <span>{{ t.accrualTotal }}</span>
                            <span>{{ fmt(accrualSummary.reduce((s,r) => s + r.total_accrual, 0)) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="cv-card p-3 mb-4">
                    <div class="grid gap-2" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
                        <select v-model="filters.location_id" class="cv-select cv-select-sm">
                            <option value="">{{ t.allLocs }}</option>
                            <option v-for="s in locationStocks" :key="s.location_id" :value="s.location_id">
                                {{ locLabel(s) }}
                            </option>
                        </select>
                        <select v-model="filters.type" class="cv-select cv-select-sm">
                            <option value="">{{ t.allTypes }}</option>
                            <option v-for="(cfg, val) in typeConfig" :key="val" :value="val">{{ cfg.label }}</option>
                        </select>
                        <input v-model="filters.date_from" type="date" class="cv-input cv-input-sm" :placeholder="t.dateFrom"/>
                        <input v-model="filters.date_to"   type="date" class="cv-input cv-input-sm" :placeholder="t.dateTo"/>
                        <button class="cv-btn cv-btn-amber cv-btn-sm" @click="applyFilters">{{ t.apply }}</button>
                    </div>
                </div>

                <!-- Transaction ledger -->
                <div v-if="transactions.data.length === 0" class="cv-empty-state">
                    <div style="font-size:3rem;opacity:.25;margin-bottom:1rem">📊</div>
                    <div class="cv-empty-title">{{ t.noTx }}</div>
                    <div class="cv-empty-sub">{{ t.noTxSub }}</div>
                </div>

                <div v-else class="tx-ledger">
                    <div class="tx-header">
                        <span>{{ t.date }}</span>
                        <span>{{ t.type }}</span>
                        <span>{{ t.location }}</span>
                        <span>{{ t.supplier }}</span>
                        <span class="text-end">{{ t.qty }}</span>
                        <span class="text-end">{{ t.cost }}</span>
                        <span class="text-end">{{ t.total }}</span>
                        <span class="text-end">{{ t.balance }}</span>
                        <span>{{ t.recorder }}</span>
                        <span>{{ t.actions }}</span>
                    </div>
                    <div v-for="tx in transactions.data" :key="tx.id" class="tx-row"
                         :class="{'tx-row-accrued': tx.is_accrued}">
                        <span class="tx-date">{{ tx.date }}</span>
                        <span>
                            <span class="tx-type-badge" :style="`color:${typeConfig[tx.type]?.color};background:${typeConfig[tx.type]?.color}18`">
                                {{ typeConfig[tx.type]?.label }}
                            </span>
                            <span v-if="counterpartLabel(tx)" class="tx-counterpart">
                                {{ tx.type === 'transfer_out' ? '→' : '←' }} {{ counterpartLabel(tx) }}
                            </span>
                        </span>
                        <span class="tx-loc">{{ txLocLabel(tx) }}</span>
                        <!-- 📌 InventoryReceiptAccrual — supplier name + ref + accrual badge -->
                        <span class="tx-supplier">
                            <template v-if="tx.supplier_id">
                                <span style="color:var(--cv-text-primary);font-size:0.78rem">
                                    {{ lang==='ar' ? (tx.supplier_name_ar||tx.supplier_name_en) : (tx.supplier_name_en||tx.supplier_name_ar) }}
                                </span>
                                <span v-if="tx.ref_no" style="display:block;font-size:0.7rem;color:var(--cv-text-muted)">{{ tx.ref_no }}</span>
                                <span v-if="tx.is_accrued"
                                      class="tx-accrual-badge">{{ t.accrualBadge }}</span>
                                <span v-else-if="tx.ap_invoice_id"
                                      class="tx-matched-badge">{{ t.matchedBadge }}</span>
                            </template>
                            <span v-else style="color:var(--cv-text-muted)">—</span>
                        </span>
                        <span class="tx-qty text-end" :style="`color:${typeConfig[tx.type]?.color}`">
                            {{ typeConfig[tx.type]?.sign }}{{ fmtQty(tx.qty) }} {{ item.unit }}
                        </span>
                        <span class="text-end text-xs" style="color:var(--cv-text-muted)">{{ fmt(tx.unit_cost) }}</span>
                        <span class="text-end text-sm" style="color:var(--cv-blue-text)">{{ fmt(tx.total) }}</span>
                        <span class="text-end font-semibold text-sm" style="color:var(--cv-text-primary)">{{ fmtQty(tx.qty_after) }}</span>
                        <span class="text-xs" style="color:var(--cv-text-muted)">{{ tx.recorder ?? '—' }}</span>
                        <!-- Actions cell -->
                        <span class="tx-actions">
                            <!-- Reverse — receipt and consumption only, not transfers or adjustments -->
                            <button v-if="tx.type === 'receipt' || tx.type === 'consumption'"
                                    class="tx-action-btn tx-action-reverse"
                                    @click="openReversal(tx)"
                                    :title="t.reverseBtn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13">
                                    <path d="M3 7h10a5 5 0 0 1 0 10H3"/><path d="M6 4l-3 3 3 3"/>
                                </svg>
                            </button>
                            <!-- Edit Supplier — receipt rows with no matched invoice only -->
                            <button v-if="tx.type === 'receipt' && !tx.ap_invoice_id"
                                    class="tx-action-btn tx-action-edit"
                                    @click="openSupplierEdit(tx)"
                                    :title="t.editSupplierBtn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            </button>
                        </span>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="transactions.last_page > 1" class="cv-pagination mt-4">
                    <Link v-for="link in transactions.links" :key="link.label"
                        :href="link.url ?? '#'" class="cv-page-link"
                        :class="{active: link.active, disabled: !link.url}"
                        v-html="link.label" preserve-scroll/>
                </div>

            </div>

            <!-- ── Transaction Modal ─────────────────────────────── -->
            <Transition name="fade">
                <div v-if="txModal" class="cv-modal-backdrop" @click.self="txModal=false">
                    <div class="cv-modal cv-modal-md">
                        <div class="cv-modal-header">
                            <h3 class="cv-modal-title">{{ t.txModalTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <div class="modal-grid">

                                <!-- 📌 LivestockOperationalCostFlow (#43) §8 — info note for feed/medicine/vaccine -->
                                <div v-if="isOperationalCategory" class="modal-span-2">
                                    <div class="accrual-info-note">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13" style="flex-shrink:0;margin-top:1px">
                                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        <span>{{ lang==='ar'
                                            ? 'الاستهلاك يُسجَّل تلقائياً من خلال وحدات الأعلاف / السجلات الصحية / التطعيمات.'
                                            : 'Consumption is recorded automatically through Feed Consumption, Health Records, or Vaccinations.' }}</span>
                                    </div>
                                </div>

                                <!-- Type -->

                                <div class="modal-span-2">
                                    <label class="cv-label">{{ t.txType }}</label>
                                    <div class="cv-btn-group">
                                        <button v-for="(label, val) in t.types" :key="val"
                                            type="button" class="cv-btn-seg"
                                            :class="{active: txForm.type===val}"
                                            @click="txForm.type=val">{{ label }}</button>
                                    </div>
                                </div>

                                <!-- Location -->
                                <div class="modal-span-2">
                                    <label class="cv-label">{{ t.txLoc }} <span class="cv-required">*</span></label>
                                    <select v-model="txForm.location_id" class="cv-select" :class="{'cv-input-error': txForm.errors.location_id}">
                                        <option value="">—</option>
                                        <template v-if="props.po">
                                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                               {{ loc.name_en || loc.name_ar || loc.id }}
                                            </option>
                                        </template>
                                        <template v-else>
                                            <option v-for="s in locationStocks" :key="s.location_id" :value="s.location_id">
                                                {{ locLabel(s) }} — {{ fmtQty(s.qty_on_hand) }} {{ item.unit }}
                                            </option>
                                        </template>
                                    </select>
                                    <div v-if="selectedLocationStock && txForm.type !== 'receipt'" class="stock-avail-badge mt-1">
                                        {{ t.availableStock }} <strong>{{ fmtQty(selectedLocationStock.qty_on_hand) }} {{ item.unit }}</strong>
                                    </div>
                                    <p v-if="txForm.errors.location_id" class="cv-error">{{ txForm.errors.location_id }}</p>
                                </div>

                                <!-- Date -->
                                <div>
                                    <label class="cv-label">{{ t.txDate }}</label>
                                    <input v-model="txForm.date" type="date" class="cv-input"/>
                                </div>

                                <!-- Qty -->
                                <div>
                                    <label class="cv-label">{{ t.txQty }} <span class="cv-required">*</span></label>
                                    <input v-model="txForm.qty" type="number" step="0.001"
                                        :min="txForm.type === 'adjustment' ? undefined : 0.001"
                                        class="cv-input"
                                        :class="{'cv-input-error': txForm.errors.qty}"/>
                                    <p v-if="txForm.errors.qty" class="cv-error">{{ txForm.errors.qty }}</p>
                                </div>

                                <!-- Unit cost (receipt only) -->
                                <div v-if="txForm.type === 'receipt'" class="modal-span-2">
                                    <label class="cv-label">{{ t.txCost }}</label>
                                    <input v-model="txForm.unit_cost" type="number" step="0.0001" min="0" class="cv-input"/>
                                </div>
                                <!-- 📌 InventoryReceiptAccrual — Supplier selector (receipt only) -->
                                <!-- If supplier selected → receipt posts to AP Accruals liability  -->
                                <!-- until matched to a supplier invoice. Leave blank = no accrual. -->
                                <div class="modal-span-2" style="margin-top:0.9rem">
                                    <label class="cv-label">{{ t.txSupplier }}</label>
                                    <select v-model="txForm.supplier_id" class="cv-select">
                                        <option value="">{{ t.txSupplierPlaceholder }}</option>
                                        <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                            {{ lang==='ar' ? (s.name_ar||s.name_en) : (s.name_en||s.name_ar) }}
                                        </option>
                                    </select>
                                    <!-- Accrual info note — shown once supplier is chosen -->
                                    <div v-if="txForm.supplier_id" class="accrual-info-note mt-1">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13" style="flex-shrink:0;margin-top:1px">
                                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        <span>{{ t.txAccrualNote }}</span>
                                    </div>
                                </div>

                                <!-- Ref No — shown only when supplier is selected -->
                                <div v-if="txForm.supplier_id" class="modal-span-2" style="margin-top:0.5rem">
                                    <label class="cv-label">{{ t.txRefNo }}</label>
                                    <input v-model="txForm.ref_no" type="text" class="cv-input"
                                      :placeholder="lang==='ar' ? 'مثال: GRN-2026-0045' : 'e.g. GRN-2026-0045'"/>
                                </div>

                                <!-- Notes -->
                                <div class="modal-span-2">
                                    <label class="cv-label">{{ t.txNotes }}</label>
                                    <textarea v-model="txForm.notes" class="cv-textarea" rows="2"/>
                                </div>

                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="txModal=false">{{ t.txCancel }}</button>
                            <button class="cv-btn cv-btn-amber" :disabled="txForm.processing || txSubmitting" @click="submitTx">
                                {{ txSubmitting ? (lang === 'ar' ? 'جارٍ الحفظ…' : 'Saving…') : t.txSave }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ── Transfer Modal ────────────────────────────────── -->
            <Transition name="fade">
                <div v-if="trfModal" class="cv-modal-backdrop" @click.self="trfModal=false">
                    <div class="cv-modal cv-modal-md">
                        <div class="cv-modal-header">
                            <h3 class="cv-modal-title">{{ t.trfModalTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <div class="modal-grid">

                                <!-- Source -->
                                <div class="modal-span-2">
                                    <label class="cv-label">{{ t.trfFrom }} <span class="cv-required">*</span></label>
                                    <select v-model="trfForm.source_location_id" class="cv-select"
                                        :class="{'cv-input-error': trfForm.errors.source_location_id}">
                                        <option value="">—</option>
                                        <option v-for="s in locationStocks" :key="s.location_id" :value="s.location_id"
                                            :disabled="s.qty_on_hand <= 0">
                                            {{ locLabel(s) }} — {{ fmtQty(s.qty_on_hand) }} {{ item.unit }}
                                            {{ s.qty_on_hand <= 0 ? (lang==='ar'?'(نفد)':'(empty)') : '' }}
                                        </option>
                                    </select>
                                    <div v-if="sourceStock" class="stock-avail-badge mt-1">
                                        {{ t.trfAvail }} <strong>{{ fmtQty(sourceStock.qty_on_hand) }} {{ item.unit }}</strong>
                                    </div>
                                    <p v-if="trfForm.errors.source_location_id" class="cv-error">{{ trfForm.errors.source_location_id }}</p>
                                </div>

                                <!-- Destination -->
                                <div class="modal-span-2">
                                    <label class="cv-label">{{ t.trfTo }} <span class="cv-required">*</span></label>
                                    <select v-model="trfForm.dest_location_id" class="cv-select"
                                        :class="{'cv-input-error': trfForm.errors.dest_location_id}"
                                        :disabled="!trfForm.source_location_id">
                                        <option value="">—</option>
                                        <option v-for="s in destLocOptions" :key="s.location_id" :value="s.location_id">
                                            {{ locLabel(s) }} — {{ fmtQty(s.qty_on_hand) }} {{ item.unit }}
                                        </option>
                                    </select>
                                    <p v-if="trfForm.errors.dest_location_id" class="cv-error">{{ trfForm.errors.dest_location_id }}</p>
                                </div>

                                <!-- Date + Qty -->
                                <div>
                                    <label class="cv-label">{{ t.trfDate }}</label>
                                    <input v-model="trfForm.date" type="date" class="cv-input"/>
                                </div>
                                <div>
                                    <label class="cv-label">{{ t.trfQty }} <span class="cv-required">*</span></label>
                                    <input v-model="trfForm.qty" type="number" step="0.001" min="0.001" class="cv-input"
                                        :class="{'cv-input-error': trfForm.errors.qty}"/>
                                    <p v-if="trfForm.errors.qty" class="cv-error">{{ trfForm.errors.qty }}</p>
                                </div>

                                <!-- Notes -->
                                <div class="modal-span-2">
                                    <label class="cv-label">{{ t.trfNotes }}</label>
                                    <textarea v-model="trfForm.notes" class="cv-textarea" rows="2"/>
                                </div>

                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="trfModal=false">{{ t.trfCancel }}</button>
                            <button class="cv-btn cv-btn-amber" :disabled="trfForm.processing" @click="submitTrf">{{ t.trfSave }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ── Reversal Confirmation Modal ──────────────────── -->
            <!-- Simple confirmation — shows what will be reversed,  -->
            <!-- user confirms then tx modal opens pre-filled.       -->
            <Transition name="fade">
                <div v-if="reversalModal" class="cv-modal-backdrop" @click.self="reversalModal=false">
                    <div class="cv-modal" style="max-width:420px">
                        <div class="cv-modal-header">
                            <h3 class="cv-modal-title">{{ t.reversalTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <!-- What is being reversed -->
                            <div v-if="reversalTarget" class="reversal-summary">
                                <div class="reversal-row">
                                    <span class="reversal-label">{{ t.date }}</span>
                                    <span class="reversal-val">{{ reversalTarget.date }}</span>
                                </div>
                                <div class="reversal-row">
                                    <span class="reversal-label">{{ t.type }}</span>
                                    <span class="reversal-val">
                                        <span class="tx-type-badge"
                                            :style="`color:${typeConfig[reversalTarget.type]?.color};background:${typeConfig[reversalTarget.type]?.color}18`">
                                            {{ typeConfig[reversalTarget.type]?.label }}
                                        </span>
                                    </span>
                                </div>
                                <div class="reversal-row">
                                    <span class="reversal-label">{{ t.qty }}</span>
                                    <span class="reversal-val" style="color:var(--cv-blue-text);font-weight:700">
                                        {{ fmtQty(reversalTarget.qty) }} {{ item.unit }}
                                    </span>
                                </div>
                                <div class="reversal-row">
                                    <span class="reversal-label">{{ t.cost }}</span>
                                    <span class="reversal-val">{{ fmt(reversalTarget.unit_cost) }}</span>
                                </div>
                                <div class="reversal-row">
                                    <span class="reversal-label">{{ t.total }}</span>
                                    <span class="reversal-val" style="color:var(--cv-blue-text)">{{ fmt(reversalTarget.total) }}</span>
                                </div>
                            </div>
                            <p class="reversal-note">{{ t.reversalNote }}</p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="reversalModal=false">{{ t.reversalCancel }}</button>
                            <button class="cv-btn cv-btn-amber" @click="confirmReversal">{{ t.reversalConfirm }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ── Edit Supplier Popup ───────────────────────────── -->
            <!-- Only appears on unmatched receipt rows.              -->
            <!-- Two fields only: supplier_id and ref_no.             -->
            <Transition name="fade">
                <div v-if="supplierModal" class="cv-modal-backdrop" @click.self="supplierModal=false">
                    <div class="cv-modal" style="max-width:380px">
                        <div class="cv-modal-header">
                            <h3 class="cv-modal-title">{{ t.editSupplierTitle }}</h3>
                        </div>
                        <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:0.9rem">
                            <div>
                                <label class="cv-label">{{ t.txSupplier }}</label>
                                <select v-model="supplierForm.supplier_id" class="cv-select">
                                    <option value="">{{ t.txSupplierPlaceholder }}</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                        {{ lang==='ar' ? (s.name_ar||s.name_en) : (s.name_en||s.name_ar) }}
                                    </option>
                                </select>
                                <p v-if="supplierForm.errors.supplier_id" class="cv-error">{{ supplierForm.errors.supplier_id }}</p>
                            </div>
                            <div>
                                <label class="cv-label">{{ t.txRefNo }}</label>
                                <input v-model="supplierForm.ref_no" type="text" class="cv-input"
                                       :placeholder="lang==='ar' ? 'مثال: GRN-2026-0045' : 'e.g. GRN-2026-0045'"/>
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="supplierModal=false">{{ t.editSupplierCancel }}</button>
                            <button class="cv-btn cv-btn-amber" :disabled="supplierForm.processing" @click="submitSupplierEdit">
                                {{ t.editSupplierSave }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* Stock summary chips */
.stock-summary { display:flex; flex-wrap:wrap; gap:0.5rem; }
.stock-loc-chip { display:flex; align-items:center; gap:0.6rem; padding:0.4rem 0.9rem; border-radius:8px; background:var(--cv-bg-card); border:1px solid var(--cv-border-muted); font-size:0.82rem; }
.chip-name { color:var(--cv-text-secondary); }
.chip-qty  { font-weight:700; color:var(--cv-blue-text); }
.chip-danger { border-color:var(--cv-danger-text); }
.chip-danger .chip-qty { color:var(--cv-danger-text); }
.chip-amber  { border-color:var(--cv-amber); }
.chip-amber .chip-qty  { color:var(--cv-amber); }

/* Ledger */
.tx-ledger { border:1px solid var(--cv-border-muted); border-radius:10px; overflow:hidden; }
.tx-header { display:grid; grid-template-columns: 90px 1fr 1fr 1fr 90px 80px 90px 80px 100px 70px; gap:0.5rem; padding:0.6rem 0.9rem; background:rgba(255,255,255,0.04); font-size:0.72rem; font-weight:600; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.04em; }
.tx-row { display:grid; grid-template-columns: 90px 1fr 1fr 1fr 90px 80px 90px 80px 100px 70px; gap:0.5rem; padding:0.6rem 0.9rem; border-top:1px solid var(--cv-border-muted); font-size:0.82rem; align-items:center; }
.tx-row:hover { background:rgba(255,255,255,0.02); }
.tx-date { color:var(--cv-text-muted); font-size:0.75rem; }
.tx-type-badge { padding:0.1rem 0.45rem; border-radius:4px; font-size:0.7rem; font-weight:600; }
.tx-counterpart { font-size:0.68rem; color:var(--cv-text-muted); display:block; margin-top:0.15rem; }
.tx-loc { font-size:0.75rem; color:var(--cv-text-muted); }
.tx-qty { font-weight:700; }
.text-end { text-align:end; }

/* Modal grid */
.modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.9rem; }
.modal-span-2 { grid-column:span 2; }

/* Segmented button */
.cv-btn-group { display:flex; border:1px solid var(--cv-border); border-radius:8px; overflow:hidden; }
.cv-btn-seg { flex:1; padding:0.4rem 0.6rem; font-size:0.8rem; background:transparent; color:var(--cv-text-secondary); border:none; cursor:pointer; transition:all 0.15s; }
.cv-btn-seg + .cv-btn-seg { border-inline-start:1px solid var(--cv-border); }
.cv-btn-seg.active { background:var(--cv-amber); color:#000; font-weight:600; }

/* Available stock badge */
.stock-avail-badge { font-size:0.73rem; padding:0.2rem 0.6rem; border-radius:6px; background:rgba(16,185,129,0.1); color:var(--cv-green-bright); display:inline-block; }

/* Misc */
.cv-btn-sm { padding:0.3rem 0.7rem; font-size:0.78rem; }
.cv-select-sm, .cv-input-sm { padding:0.3rem 0.6rem; font-size:0.82rem; }
.cv-modal-md { max-width:520px;}
.cv-label { display:block; font-size:0.78rem; font-weight:500; color:var(--cv-text-secondary); margin-bottom:0.25rem; }
.cv-required { color:var(--cv-danger-text); }
.cv-error { font-size:0.73rem; color:var(--cv-danger-text); margin-top:0.2rem; }
.cv-pagination { display:flex; gap:0.25rem; flex-wrap:wrap; }
.cv-page-link { padding:0.3rem 0.7rem; border-radius:6px; font-size:0.8rem; color:var(--cv-text-secondary); background:var(--cv-bg-card); border:1px solid var(--cv-border-muted); text-decoration:none; }
.cv-page-link.active { background:var(--cv-amber); color:#000; font-weight:600; }
.cv-page-link.disabled { opacity:0.4; pointer-events:none; }
.cv-empty-state { text-align:center; padding:4rem 2rem; }
.cv-empty-title { font-size:1rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:0.4rem; }
.cv-empty-sub { color:var(--cv-text-muted); font-size:0.85rem; }
.fade-enter-active, .fade-leave-active { transition:opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity:0; }

@media(max-width:768px) {
    .tx-header, .tx-row { grid-template-columns:70px 1fr 70px 70px 50px; }
    .tx-header span:nth-child(3), .tx-row span:nth-child(3),
    .tx-header span:nth-child(4), .tx-row span:nth-child(4),
    .tx-header span:nth-child(6), .tx-row span:nth-child(6),
    .tx-header span:nth-child(7), .tx-row span:nth-child(7),
    .tx-header span:nth-child(9), .tx-row span:nth-child(9) { display:none; }
    .modal-grid { grid-template-columns:1fr; }
    .modal-span-2 { grid-column:span 1; }
}

/* ── Action buttons in ledger rows ───────────────────────────── */
.tx-actions { display:flex; gap:4px; align-items:center; }
.tx-action-btn { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:6px; border:1px solid var(--cv-border-muted); background:transparent; cursor:pointer; transition:all 0.15s; color:var(--cv-text-muted); }
.tx-action-btn:hover { border-color:var(--cv-amber); color:var(--cv-amber); background:color-mix(in srgb,var(--cv-amber) 8%,transparent); }
.tx-action-reverse:hover { border-color:var(--cv-danger-text); color:var(--cv-danger-text); background:color-mix(in srgb,var(--cv-danger-text) 8%,transparent); }
.tx-action-edit:hover { border-color:var(--cv-blue-text); color:var(--cv-blue-text); background:color-mix(in srgb,var(--cv-blue-text) 8%,transparent); }

/* ── Reversal confirmation modal ─────────────────────────────── */
.reversal-summary { background:var(--cv-bg-card); border:1px solid var(--cv-border-muted); border-radius:8px; overflow:hidden; margin-bottom:0.9rem; }
.reversal-row { display:flex; justify-content:space-between; align-items:center; padding:0.45rem 0.85rem; border-bottom:1px solid var(--cv-border-muted); font-size:0.82rem; }
.reversal-row:last-child { border-bottom:none; }
.reversal-label { color:var(--cv-text-muted); font-size:0.75rem; }
.reversal-val { color:var(--cv-text-primary); }
.reversal-note { font-size:0.78rem; color:var(--cv-text-muted); line-height:1.5; padding:0.5rem 0.75rem; background:color-mix(in srgb,var(--cv-amber) 6%,var(--cv-bg-card)); border:1px solid color-mix(in srgb,var(--cv-amber) 20%,transparent); border-radius:6px; }

/* ── 📌 InventoryReceiptAccrual — Accrual Warning Panel ──────── */
.accrual-panel { border:1px solid color-mix(in srgb,var(--cv-amber) 35%,transparent); border-radius:10px; overflow:hidden; background:color-mix(in srgb,var(--cv-amber) 6%,var(--cv-bg-card)); }
.accrual-panel-header { display:flex; align-items:center; gap:0.6rem; padding:0.65rem 1rem; background:color-mix(in srgb,var(--cv-amber) 12%,transparent); color:var(--cv-amber); font-weight:600; font-size:0.85rem; border-bottom:1px solid color-mix(in srgb,var(--cv-amber) 20%,transparent); }
.accrual-rows { padding:0.25rem 0; }
.accrual-row { display:flex; align-items:center; gap:0.75rem; padding:0.5rem 1rem; border-bottom:1px solid var(--cv-border-muted); font-size:0.82rem; }
.accrual-row:last-of-type { border-bottom:none; }
.accrual-supplier { flex:1; color:var(--cv-text-primary); font-weight:500; }
.accrual-badge-pill { background:color-mix(in srgb,var(--cv-amber) 15%,transparent); color:var(--cv-amber); border-radius:99px; padding:1px 8px; font-size:0.73rem; font-weight:600; white-space:nowrap; }
.accrual-oldest { color:var(--cv-text-muted); font-size:0.75rem; white-space:nowrap; }
.accrual-amount { color:var(--cv-blue-text); font-weight:700; min-width:100px; text-align:end; }
.accrual-total-row { display:flex; justify-content:space-between; padding:0.6rem 1rem; font-weight:700; font-size:0.85rem; color:var(--cv-amber); border-top:1px solid color-mix(in srgb,var(--cv-amber) 20%,transparent); }

/* ── Accrual info note inside modal ─────────────────────────── */
.accrual-info-note { display:flex; align-items:flex-start; gap:0.4rem; padding:0.45rem 0.65rem; margin-top:0.4rem; background:color-mix(in srgb,var(--cv-amber) 10%,var(--cv-bg-card)); border:1px solid color-mix(in srgb,var(--cv-amber) 25%,transparent); border-radius:6px; color:var(--cv-amber); font-size:0.75rem; line-height:1.4; }

/* ── Supplier column in ledger ───────────────────────────────── */
.tx-supplier { font-size:0.78rem; }
.tx-accrual-badge { display:inline-block; margin-top:2px; padding:1px 6px; border-radius:4px; font-size:0.68rem; font-weight:600; background:color-mix(in srgb,var(--cv-amber) 15%,transparent); color:var(--cv-amber); }
.tx-matched-badge { display:inline-block; margin-top:2px; padding:1px 6px; border-radius:4px; font-size:0.68rem; font-weight:600; background:color-mix(in srgb,var(--cv-green-bright) 15%,transparent); color:var(--cv-green-bright); }
.tx-row-accrued { background:color-mix(in srgb,var(--cv-amber) 4%,transparent); }
.tx-row-accrued:hover { background:color-mix(in srgb,var(--cv-amber) 8%,transparent); }
</style>