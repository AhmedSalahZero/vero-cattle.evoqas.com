<script setup>
import { ref, computed, onMounted } from 'vue'
import { router, Link, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'


const props = defineProps({
    procurement:   { type: Object, required: true },
    linkedInvoices:{ type: Array,  default: () => [] },
})

const { locale: lang, isRtl, n } = useLocale()
const authUser   = usePage().props.auth.user
const canEdit    = computed(() => authUser.is_super_admin || authUser.permissions?.includes('edit procurement'))
const canDelete  = computed(() => authUser.is_super_admin || authUser.permissions?.includes('delete procurement'))
const canReceive = computed(() => authUser.is_super_admin || authUser.permissions?.includes('create animals'))

const po = computed(() => props.procurement)

// ── PO type flags ─────────────────────────────────────────────────
const isCattle    = computed(() => (po.value.po_type ?? 'cattle') === 'cattle')
const isInventory = computed(() => po.value.po_type === 'inventory')

// ── Confirm PO modal ──────────────────────────────────────────────
const confirmPoModal   = ref(false)
const confirmPoLoading = ref(false)
function executeConfirmPo() {
    confirmPoLoading.value = true
    const confirmRoute = isInventory.value
    ? route('procurement.confirm-inventory-po', po.value.id)
    : route('procurement.confirm-po', po.value.id)
    router.patch(confirmRoute, {}, {
        onFinish: () => { confirmPoLoading.value = false; confirmPoModal.value = false },
    })
}

// ── Force Close modal ─────────────────────────────────────────────
const forceCloseModal   = ref(false)
const forceCloseLoading = ref(false)
const forceCloseReason  = ref('')
const forceCloseError   = ref('')
function openForceClose() { forceCloseModal.value = true; forceCloseReason.value = ''; forceCloseError.value = '' }
function executeForceClose() {
    if (forceCloseReason.value.trim().length < 10) {
        forceCloseError.value = lang.value === 'ar'
            ? 'السبب يجب أن يكون 10 أحرف على الأقل'
            : 'Reason must be at least 10 characters'
        return
    }
    forceCloseLoading.value = true
    // Route to the correct force-close method based on PO type
    const routeName = isInventory.value
        ? 'procurement.force-close-inventory'
        : 'procurement.force-close'
    router.patch(route(routeName, po.value.id),
        { closure_reason: forceCloseReason.value },
        { onFinish: () => { forceCloseLoading.value = false; forceCloseModal.value = false } }
    )
}

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal   = ref(false)
const deleteLoading = ref(false)
function executeDelete() {
    deleteLoading.value = true
    router.delete(route('procurement.destroy', po.value.id), {
        onFinish: () => { deleteLoading.value = false },
    })
}

// ── Helpers ───────────────────────────────────────────────────────
function fmt(val, dec = 2) {
    if (val === null || val === undefined || val === '') return '—'
    return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-EG', {
        minimumFractionDigits: dec, maximumFractionDigits: dec,
    })
}

function poStatusBadgeClass(status) {
    return {
        draft:               'cv-badge-neutral',
        open:                'cv-badge-info',
        partially_received:  'cv-badge-amber',
        on_track:            'cv-badge-info',
        fully_received:      'cv-badge-green',
        over_received:       'cv-badge-danger',
        force_closed:        'cv-badge-neutral',
    }[status] ?? 'cv-badge-neutral'
}

function poStatusLabel(status) {
    if (lang.value === 'ar') return {
        draft: 'مسودة', open: 'مفتوح', partially_received: 'استلام جزئي',
        on_track: 'في المسار', fully_received: 'مكتمل',
        over_received: 'زيادة استلام', force_closed: 'مغلق قسراً',
    }[status] ?? status
    return {
        draft: 'Draft', open: 'Open', partially_received: 'Partially Received',
        on_track: 'On Track', fully_received: 'Fully Received',
        over_received: 'Over Received', force_closed: 'Force Closed',
    }[status] ?? status
}

function lineStatusBadgeClass(status) {
    return { open: 'cv-badge-info', partially_received: 'cv-badge-amber', fully_received: 'cv-badge-green', over_received: 'cv-badge-danger' }[status] ?? 'cv-badge-neutral'
}

function invoiceStatusBadgeClass(status) {
    return { open: 'cv-badge-info', partially_paid: 'cv-badge-amber', paid: 'cv-badge-green', overdue: 'cv-badge-danger', cancelled: 'cv-badge-neutral' }[status] ?? 'cv-badge-neutral'
}

function receiptModeBadge(mode) {
    if (lang.value === 'ar') return { bulk_avg: 'متوسط', bulk_range: 'وحدة', individual: 'فردي' }[mode] ?? mode
    return { bulk_avg: 'Bulk Avg', bulk_range: 'Per Unit', individual: 'Single' }[mode] ?? mode
}

// ── Cattle receipt progress ───────────────────────────────────────
const progressPct = computed(() => {
    const max = Number(po.value.max_head) || 1
    const rec = Number(po.value.received_head) || 0
    return Math.min(100, Math.round((rec / max) * 100))
})
const progressColor = computed(() => {
    if (po.value.is_over_received) return 'var(--cv-danger-text)'
    if (po.value.po_status === 'fully_received') return 'var(--cv-green-bright)'
    if (po.value.po_status === 'on_track') return 'var(--cv-blue-text)'
    return 'var(--cv-amber)'
})

// ── Inventory item lines progress ─────────────────────────────────
const inventoryItemLines = computed(() => po.value.item_lines ?? [])
const inventoryReceiptBatches = computed(() => po.value.inventory_receipt_batches ?? [])

const itemLinesProgress = computed(() => {
    if (!inventoryItemLines.value.length) return { pct: 0, orderedTotal: 0, receivedTotal: 0 }
    const orderedTotal  = inventoryItemLines.value.reduce((s, l) => s + Number(l.qty_ordered  ?? 0), 0)
    const receivedTotal = inventoryItemLines.value.reduce((s, l) => s + Number(l.qty_received ?? 0), 0)
    const pct = orderedTotal > 0 ? Math.min(100, Math.round((receivedTotal / orderedTotal) * 100)) : 0
    return { pct, orderedTotal, receivedTotal }
})

const inventoryItemsSubtotal = computed(() =>
    inventoryItemLines.value.reduce((s, l) => s + Number(l.total_estimated ?? 0), 0)
)

const isDraft       = computed(() => po.value.po_status === 'draft')
const canReceiveNow = computed(() => po.value.can_receive)
// First open item line — used to navigate to its transactions page with PO context
const inventoryReceiveUrl = computed(() => {
    const firstOpenLine = inventoryItemLines.value.find(l => l.line_status === 'open')
    const itemId = firstOpenLine?.inventory_item_id ?? inventoryItemLines.value[0]?.inventory_item_id
    if (!itemId) return route('procurement.show', po.value.id)
    return route('inventory.transactions', itemId) + '?po_id=' + po.value.id
})
// Auto-scroll to receipt section when arriving via #receipt hash (from Index.vue button)
onMounted(() => {
    if (window.location.hash === '#receipt') {
        const el = document.getElementById('receipt')
        if (el) el.scrollIntoView({ behavior: 'smooth' })
    }
})
</script>

<template>
    <AuthenticatedLayout>

        <!-- ══ PAGE HERO ══════════════════════════════════════════ -->
        <div class="cv-page-hero" :dir="isRtl ? 'rtl' : 'ltr'">
            <div class="cv-page-hero-eyebrow">{{ lang === 'ar' ? 'المشتريات' : 'Procurement' }}</div>
            <div class="cv-page-hero-title">
                {{ lang === 'ar' ? 'تفاصيل أمر الشراء' : 'Purchase Order Detail' }}
                <span style="color:var(--cv-amber);font-size:1rem;margin-inline-start:8px">
                    {{ po.po_reference || ('#' + po.id) }}
                </span>
            </div>
            <div class="cv-page-hero-sub">
                {{ n(po.supplier, 'name') }}
                <span v-if="po.farm"> · {{ n(po.farm, 'name') }}</span>
                <span v-if="isCattle && po.lot"> · {{ po.lot.name }}</span>
                <!-- PO type pill -->
                <span :style="isInventory
                    ? 'margin-inline-start:8px;font-size:.7rem;font-weight:600;color:var(--cv-amber);background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);padding:2px 8px;border-radius:4px'
                    : 'margin-inline-start:8px;font-size:.7rem;font-weight:600;color:var(--cv-green-bright);background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);padding:2px 8px;border-radius:4px'">
                    {{ isInventory ? (lang === 'ar' ? 'مخزون' : 'Inventory') : (lang === 'ar' ? 'ماشية' : 'Cattle') }}
                </span>
            </div>
            <div class="cv-page-hero-actions">
                <Link :href="route('procurement.index')" class="cv-btn cv-btn-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    {{ lang === 'ar' ? 'رجوع' : 'Back' }}
                </Link>

                <!-- Edit — draft only -->
                <Link v-if="canEdit && po.can_edit"
                      :href="route('procurement.edit', po.id)"
                      class="cv-btn cv-btn-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    {{ lang === 'ar' ? 'تعديل' : 'Edit' }}
                </Link>

                <!-- Confirm PO — draft only -->
                <button v-if="canEdit && isDraft" class="cv-btn cv-btn-amber" @click="confirmPoModal = true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    {{ lang === 'ar' ? 'تأكيد الأمر' : 'Confirm PO' }}
                </button>

                <!-- ★ RECEIVE CATTLE — cattle POs only ★ -->
                <Link v-if="isCattle && canReceive && canReceiveNow"
                      :href="route('procurement.receive', po.id)"
                      class="cv-btn cv-btn-green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                        <path d="M3 9l2-5h14l2 5"/>
                        <rect x="5" y="9" width="14" height="10" rx="2"/>
                        <path d="M8 9V7M16 9V7"/>
                        <circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>
                    </svg>
                    {{ lang === 'ar' ? 'استلام حيوانات' : 'Receive Animals' }}
                </Link>

                <!-- ★ RECEIVE ITEMS — inventory POs only ★ -->
                <Link v-if="isInventory && canReceive && canReceiveNow"
                     :href="inventoryReceiveUrl"
                      class="cv-btn cv-btn-green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                        <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
                        <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>
                    </svg>
                    {{ lang === 'ar' ? 'استلام بضاعة' : 'Receive Items' }}
                </Link>

                <!-- Force Close -->
                <button v-if="canEdit && po.can_force_close"
                        class="cv-btn cv-btn-ghost"
                        style="color:var(--cv-amber);border-color:var(--cv-amber-border)"
                        @click="openForceClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                    {{ lang === 'ar' ? 'إغلاق قسري' : 'Force Close' }}
                </button>

                <!-- Delete — draft only -->
                <button v-if="canDelete && po.can_delete"
                        class="cv-btn cv-btn-ghost"
                        style="color:var(--cv-danger-text);border-color:var(--cv-danger-border)"
                        @click="deleteModal = true">
                    {{ lang === 'ar' ? 'حذف' : 'Delete' }}
                </button>
            </div>
        </div>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ OVER-RECEIVED BANNER ═══════════════════════════ -->
            <div v-if="po.is_over_received" class="cv-alert cv-alert-danger mb-5">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span v-if="isCattle">
                    {{ lang === 'ar'
                        ? `تحذير: تم استلام ${po.received_head} رأس — يتجاوز الحد الأقصى ${po.max_head} رأس بمقدار ${po.received_head - po.max_head} رأس.`
                        : `Warning: ${po.received_head} head received — exceeds max of ${po.max_head} by ${po.received_head - po.max_head} head.` }}
                </span>
                <span v-else>
                    {{ lang === 'ar' ? 'تحذير: تم استلام كمية تتجاوز الكميات المطلوبة في بعض الأصناف.' : 'Warning: quantity received exceeds the ordered quantity for one or more items.' }}
                </span>
            </div>

            <!-- ══ FORCE CLOSED BANNER ════════════════════════════ -->
            <div v-if="po.po_status === 'force_closed'" class="cv-alert cv-alert-warning mb-5">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>
                    {{ lang === 'ar' ? 'تم إغلاق هذا الأمر قسراً. السبب: ' : 'This PO was force closed. Reason: ' }}
                    <strong>{{ po.closure_reason }}</strong>
                    <span style="font-size:.72rem;margin-inline-start:.5rem;color:var(--cv-text-muted)">{{ po.closed_at }}</span>
                </span>
            </div>

            <div class="grid gap-5" style="grid-template-columns:1fr 1fr">

                <!-- ══ SECTION 1 — PO Header Info ══════════════════ -->
                <div class="cv-card" style="grid-column:1/-1">
                    <div class="flex items-center gap-3 mb-4">
                        <div style="width:36px;height:36px;border-radius:8px;background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                                <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
                                <line x1="3" y1="6" x2="21" y2="6"/>
                                <path d="M16 10a4 4 0 01-8 0"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'معلومات أمر الشراء' : 'Purchase Order Information' }}
                            </div>
                        </div>
                        <span class="cv-badge ms-auto" :class="poStatusBadgeClass(po.po_status)">
                            {{ poStatusLabel(po.po_status) }}
                        </span>
                    </div>

                    <div class="grid gap-4" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
                        <div>
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'رقم الأمر' : 'PO Number' }}</div>
                            <div style="font-weight:700;color:var(--cv-amber)">{{ po.po_reference || '—' }}</div>
                        </div>
                        <div>
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'تاريخ الأمر' : 'PO Date' }}</div>
                            <div style="color:var(--cv-text-primary);font-weight:500">{{ po.date }}</div>
                        </div>
                        <div>
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'تاريخ الاستحقاق' : 'Due Date' }}</div>
                            <div style="color:var(--cv-amber);font-weight:500">{{ po.due_date || '—' }}</div>
                        </div>
                        <div>
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'المورد' : 'Supplier' }}</div>
                            <div style="color:var(--cv-text-primary);font-weight:500">{{ n(po.supplier, 'name') }}</div>
                        </div>
                        <div>
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</div>
                            <div style="color:var(--cv-text-primary);font-weight:500">{{ n(po.farm, 'name') || '—' }}</div>
                        </div>
                        <!-- Lot — cattle only -->
                        <div v-if="isCattle">
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'اللوت' : 'Lot' }}</div>
                            <div style="color:var(--cv-text-primary);font-weight:500">{{ po.lot?.name || '—' }}</div>
                        </div>
                        <!-- Est. Price/kg — cattle only -->
                        <div v-if="isCattle">
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'السعر المقدر/كجم' : 'Est. Price/kg' }}</div>
                            <div style="color:var(--cv-blue-text);font-weight:700">{{ fmt(po.estimated_price_per_kg, 4) }} {{ po.currency }}</div>
                        </div>
                        <!-- Items ordered — inventory only -->
                        <div v-if="isInventory">
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'أصناف مطلوبة' : 'Items Ordered' }}</div>
                            <div style="color:var(--cv-blue-text);font-weight:700">{{ inventoryItemLines.length }} {{ lang === 'ar' ? 'صنف' : 'item(s)' }}</div>
                        </div>
                        <div>
                            <div style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:3px">{{ lang === 'ar' ? 'أيام الدفع' : 'Payment Terms' }}</div>
                            <div style="color:var(--cv-text-primary)">{{ po.payment_terms_days }} {{ lang === 'ar' ? 'يوم' : 'days' }}</div>
                        </div>
                    </div>

                    <div v-if="po.notes" style="margin-top:1rem;padding:.75rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);border:1px solid var(--cv-border);font-size:.8rem;color:var(--cv-text-secondary)">
                        <span style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);display:block;margin-bottom:4px">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</span>
                        {{ po.notes }}
                    </div>
                </div>

                <!-- ══ SECTION 2 — Receipt Progress ════════════════
                     CATTLE: head count progress bar
                     INVENTORY: items progress bar
                ═════════════════════════════════════════════════════ -->

                <!-- CATTLE receipt progress -->
                <div v-if="isCattle" class="cv-card">
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:1rem">
                        {{ lang === 'ar' ? 'تقدم الاستلام' : 'Receipt Progress' }}
                    </div>
                    <div style="margin-bottom:1rem">
                        <div class="flex justify-between items-end mb-1">
                            <span style="font-size:2rem;font-weight:800;line-height:1;" :style="{ color: progressColor }">
                                {{ po.received_head ?? 0 }}
                            </span>
                            <span style="font-size:.8rem;color:var(--cv-text-muted)">
                                / {{ po.min_head }}–{{ po.max_head }} {{ lang === 'ar' ? 'رأس' : 'head' }}
                            </span>
                        </div>
                        <div style="height:10px;background:var(--cv-border-md);border-radius:999px;overflow:hidden">
                            <div :style="{
                                width: progressPct + '%',
                                height: '100%',
                                background: progressColor,
                                borderRadius: '999px',
                                transition: 'width .4s',
                            }"></div>
                        </div>
                        <div style="font-size:.7rem;color:var(--cv-text-muted);margin-top:.4rem">
                            {{ progressPct }}% {{ lang === 'ar' ? 'مكتمل' : 'complete' }}
                            <span v-if="po.remaining_head > 0 && !po.is_over_received">
                                · {{ po.remaining_head }} {{ lang === 'ar' ? 'متبقي' : 'remaining' }}
                            </span>
                        </div>
                    </div>
                    <div style="font-size:.78rem;color:var(--cv-text-muted)">
                        {{ po.receipt_batches_count ?? 0 }} {{ lang === 'ar' ? 'دفعات استلام' : 'receipt batch(es)' }}
                    </div>
                </div>

                <!-- INVENTORY receipt progress -->
                <div v-if="isInventory" class="cv-card">
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:1rem">
                        {{ lang === 'ar' ? 'تقدم الاستلام' : 'Receipt Progress' }}
                    </div>
                    <div style="margin-bottom:1rem">
                        <div class="flex justify-between items-end mb-1">
                            <span style="font-size:2rem;font-weight:800;line-height:1;color:var(--cv-blue-text)">
                                {{ fmt(itemLinesProgress.receivedTotal, 0) }}
                            </span>
                            <span style="font-size:.8rem;color:var(--cv-text-muted)">
                                / {{ fmt(itemLinesProgress.orderedTotal, 0) }} {{ lang === 'ar' ? 'وحدة' : 'units' }}
                            </span>
                        </div>
                        <div style="height:10px;background:var(--cv-border-md);border-radius:999px;overflow:hidden">
                            <div :style="{
                                width: itemLinesProgress.pct + '%',
                                height: '100%',
                                background: po.is_over_received ? 'var(--cv-danger-text)' : po.po_status === 'fully_received' ? 'var(--cv-green-bright)' : 'var(--cv-amber)',
                                borderRadius: '999px',
                                transition: 'width .4s',
                            }"></div>
                        </div>
                        <div style="font-size:.7rem;color:var(--cv-text-muted);margin-top:.4rem">
                            {{ itemLinesProgress.pct }}% {{ lang === 'ar' ? 'مكتمل' : 'complete' }}
                        </div>
                    </div>
                    <div style="font-size:.78rem;color:var(--cv-text-muted)">
                        {{ inventoryReceiptBatches.length }} {{ lang === 'ar' ? 'دفعات استلام' : 'receipt batch(es)' }}
                    </div>
                </div>

                <!-- ══ SECTION 3 — Cost Summary ══════════════════ -->
                <div class="cv-card">
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:1rem">
                        {{ lang === 'ar' ? 'ملخص التكلفة' : 'Cost Summary' }}
                    </div>
                    <div class="flex flex-col gap-2">
                        <!-- Inventory items subtotal — inventory only -->
                        <div v-if="isInventory" class="flex justify-between items-center" style="padding-bottom:.4rem;border-bottom:1px solid var(--cv-border)">
                            <span style="font-size:.8rem;color:var(--cv-text-secondary)">{{ lang === 'ar' ? 'قيمة الأصناف' : 'Items Value' }}</span>
                            <span style="font-weight:600;color:var(--cv-blue-text)">{{ fmt(inventoryItemsSubtotal) }}</span>
                        </div>
                        <div class="flex justify-between items-center" style="padding-bottom:.4rem;border-bottom:1px solid var(--cv-border)">
                            <span style="font-size:.8rem;color:var(--cv-text-secondary)">{{ lang === 'ar' ? 'الشحن' : 'Freight' }}</span>
                            <span style="font-weight:600;color:var(--cv-amber)">{{ fmt(po.freight_cost) }}</span>
                        </div>
                        <div class="flex justify-between items-center" style="padding-bottom:.4rem;border-bottom:1px solid var(--cv-border)">
                            <span style="font-size:.8rem;color:var(--cv-text-secondary)">{{ lang === 'ar' ? 'تكاليف أخرى' : 'Other Costs' }}</span>
                            <span style="font-weight:600;color:var(--cv-amber)">{{ fmt(po.other_costs) }}</span>
                        </div>
                        <div class="flex justify-between items-center" style="padding-top:.25rem">
                            <span style="font-size:.78rem;font-weight:700;color:var(--cv-text-primary)">
                                {{ isInventory ? (lang === 'ar' ? 'الإجمالي التقديري' : 'Estimated Total') : (lang === 'ar' ? 'تكاليف إضافية' : 'Additional Costs') }}
                            </span>
                            <span style="font-size:1rem;font-weight:800;color:var(--cv-blue-text)">
                                {{ isInventory
                                    ? fmt(inventoryItemsSubtotal + Number(po.freight_cost || 0) + Number(po.other_costs || 0))
                                    : fmt(Number(po.freight_cost || 0) + Number(po.other_costs || 0)) }}
                                <span style="font-size:.65rem;font-weight:400;color:var(--cv-text-muted)"> {{ po.currency ?? 'EGP' }}</span>
                            </span>
                        </div>
                    </div>
                </div>

            </div><!-- /grid -->

            <!-- ══════════════════════════════════════════════════════════════
                 SECTION 4 — Receipt History
                 CATTLE  → animal receipt batches table
                 INVENTORY → inventory receipt batches table
            ══════════════════════════════════════════════════════════════ -->

            <!-- CATTLE: Section 4 — Animal Receipt Batches -->
            <div v-if="isCattle" class="cv-card mt-5" style="padding:0;overflow:hidden">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--cv-border);display:flex;align-items:center;justify-content:space-between">
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
                        {{ lang === 'ar' ? 'سجل الاستلام' : 'Receipt History' }}
                        <span style="font-size:.7rem;font-weight:400;color:var(--cv-text-muted);margin-inline-start:6px">
                            ({{ po.receipt_batches?.length ?? 0 }} {{ lang === 'ar' ? 'دفعة' : 'batch(es)' }})
                        </span>
                    </div>
                    <Link v-if="canReceive && canReceiveNow"
                          :href="route('procurement.receive', po.id)"
                          class="cv-btn cv-btn-green"
                          style="font-size:.72rem;padding:.35rem .75rem">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        {{ lang === 'ar' ? 'استلام دفعة جديدة' : 'Receive New Batch' }}
                    </Link>
                </div>

                <div v-if="po.receipt_batches && po.receipt_batches.length > 0" style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                        <thead>
                            <tr style="border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">#</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الرؤوس' : 'Head' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'المجموع' : 'Total' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'طريقة الإدخال' : 'Mode' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الفاتورة' : 'Invoice' }}</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'بواسطة' : 'By' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(batch, idx) in po.receipt_batches" :key="batch.id"
                                style="border-bottom:1px solid var(--cv-border)"
                                @mouseover="$event.currentTarget.style.background='var(--cv-bg-hover)'"
                                @mouseleave="$event.currentTarget.style.background=''">
                                <td style="padding:.65rem 1rem;color:var(--cv-text-muted)">{{ idx + 1 }}</td>
                                <td style="padding:.65rem 1rem;color:var(--cv-text-primary);font-weight:500">{{ batch.date }}</td>
                                <td style="padding:.65rem 1rem;text-align:center;font-weight:700;color:var(--cv-blue-text)">{{ batch.received_head }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:700;color:var(--cv-blue-text)">{{ fmt(batch.subtotal) }}</td>
                                <td style="padding:.65rem 1rem;text-align:center">
                                    <span class="cv-badge cv-badge-neutral" style="font-size:.62rem">{{ receiptModeBadge(batch.receipt_mode) }}</span>
                                </td>
                                <td style="padding:.65rem 1rem;text-align:center">
                                    <span v-if="batch.is_matched" class="cv-badge cv-badge-green" style="font-size:.62rem">
                                        {{ lang === 'ar' ? 'مطابق' : 'Matched' }}
                                    </span>
                                    <span v-else class="cv-badge cv-badge-amber" style="font-size:.62rem">
                                        {{ lang === 'ar' ? 'معلق' : 'Pending' }}
                                    </span>
                                </td>
                                <td style="padding:.65rem 1rem;font-size:.72rem;color:var(--cv-text-muted)">{{ batch.created_by || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else style="padding:2.5rem;text-align:center">
                    <div style="font-size:.8rem;color:var(--cv-text-muted);margin-bottom:.75rem">
                        {{ lang === 'ar' ? 'لم يتم استلام حيوانات بعد' : 'No animals received yet' }}
                    </div>
                    <Link v-if="canReceive && canReceiveNow"
                          :href="route('procurement.receive', po.id)"
                          class="cv-btn cv-btn-amber"
                          style="font-size:.78rem">
                        {{ lang === 'ar' ? 'ابدأ الاستلام الآن' : 'Start Receiving Now' }}
                    </Link>
                </div>
            </div>

            <!-- INVENTORY: Section 4 — Ordered Item Lines -->
            <div v-if="isInventory && inventoryItemLines.length > 0" class="cv-card mt-5" style="padding:0;overflow:hidden">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--cv-border);display:flex;align-items:center;gap:.75rem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                        <rect x="9" y="3" width="6" height="4" rx="1"/>
                        <line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="12" y2="16"/>
                    </svg>
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
                        {{ lang === 'ar' ? 'الأصناف المطلوبة' : 'Ordered Items' }}
                        <span style="font-size:.7rem;font-weight:400;color:var(--cv-text-muted);margin-inline-start:6px">
                            ({{ inventoryItemLines.length }} {{ lang === 'ar' ? 'صنف' : 'item(s)' }})
                        </span>
                    </div>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                        <thead>
                            <tr style="border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الصنف' : 'Item' }}</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الموقع' : 'Location' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'الكمية المطلوبة' : 'Qty Ordered' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'المستلم' : 'Received' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'المتبقي' : 'Remaining' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'سعر الوحدة' : 'Unit Cost' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الحالة' : 'Status' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, idx) in inventoryItemLines" :key="line.id ?? idx"
                                style="border-bottom:1px solid var(--cv-border)"
                                @mouseover="$event.currentTarget.style.background='var(--cv-bg-hover)'"
                                @mouseleave="$event.currentTarget.style.background=''">
                                <td style="padding:.65rem 1rem">
                                    <div style="font-weight:600;color:var(--cv-text-primary)">
                                        {{ lang === 'ar' ? (line.item_name_ar || line.item_name_en) : (line.item_name_en || line.item_name_ar) }}
                                    </div>
                                    <div v-if="line.item_category" style="font-size:.65rem;color:var(--cv-text-muted);text-transform:capitalize">{{ line.item_category }}</div>
                                </td>
                                <td style="padding:.65rem 1rem;color:var(--cv-text-secondary);font-size:.78rem">
                                    {{ lang === 'ar' ? (line.location_name_ar || line.location_name_en) : (line.location_name_en || line.location_name_ar) || '—' }}
                                </td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:700;color:var(--cv-blue-text)">
                                    {{ fmt(line.qty_ordered, 3) }}
                                    <span style="font-size:.65rem;color:var(--cv-text-muted);margin-inline-start:2px">{{ line.item_unit }}</span>
                                </td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:600;color:var(--cv-green-bright)">
                                    {{ fmt(line.qty_received, 3) }}
                                </td>
                                <td style="padding:.65rem 1rem;text-align:end;color:var(--cv-text-secondary)">
                                    {{ fmt(line.remaining_qty, 3) }}
                                </td>
                                <td style="padding:.65rem 1rem;text-align:end;color:var(--cv-blue-text)">
                                    {{ fmt(line.unit_cost_estimated, 4) }}
                                </td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:700;color:var(--cv-blue-text)">
                                    {{ fmt(line.total_estimated) }}
                                    <span style="font-size:.65rem;color:var(--cv-text-muted);margin-inline-start:2px">{{ po.currency ?? 'EGP' }}</span>
                                </td>
                                <td style="padding:.65rem 1rem;text-align:center">
                                    <span class="cv-badge" :class="lineStatusBadgeClass(line.line_status)" style="font-size:.62rem">
                                        {{ line.line_status?.replace('_', ' ') || '—' }}
                                    </span>
                                    <div v-if="line.is_over_received" style="font-size:.6rem;color:var(--cv-danger-text);margin-top:2px;font-weight:600">⚠ {{ lang === 'ar' ? 'تجاوز' : 'Over' }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- INVENTORY: Section 5 — Inventory Receipt Batches -->
            <div v-if="isInventory" id="receipt" class="cv-card mt-5" style="padding:0;overflow:hidden">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--cv-border);display:flex;align-items:center;justify-content:space-between">
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
                        {{ lang === 'ar' ? 'سجل الاستلام' : 'Receipt History' }}
                        <span style="font-size:.7rem;font-weight:400;color:var(--cv-text-muted);margin-inline-start:6px">
                            ({{ inventoryReceiptBatches.length }} {{ lang === 'ar' ? 'دفعة' : 'batch(es)' }})
                        </span>
                    </div>
                    <Link v-if="canReceive && canReceiveNow"
                          :href="inventoryReceiveUrl"
                          class="cv-btn cv-btn-green"
                          style="font-size:.72rem;padding:.35rem .75rem">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        {{ lang === 'ar' ? 'استلام دفعة جديدة' : 'Receive New Batch' }}
                    </Link>
                </div>

                <div v-if="inventoryReceiptBatches.length > 0" style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                        <thead>
                            <tr style="border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">#</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'المجموع' : 'Total' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'قيد محاسبي' : 'Journal Entry' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الفاتورة' : 'Invoice' }}</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'بواسطة' : 'By' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(batch, idx) in inventoryReceiptBatches" :key="batch.id"
                                style="border-bottom:1px solid var(--cv-border)"
                                @mouseover="$event.currentTarget.style.background='var(--cv-bg-hover)'"
                                @mouseleave="$event.currentTarget.style.background=''">
                                <td style="padding:.65rem 1rem;color:var(--cv-text-muted)">{{ idx + 1 }}</td>
                                <td style="padding:.65rem 1rem;color:var(--cv-text-primary);font-weight:500">{{ batch.date }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:700;color:var(--cv-blue-text)">{{ fmt(batch.subtotal) }}</td>
                                <td style="padding:.65rem 1rem;text-align:center">
                                    <span v-if="batch.journal_entry" class="cv-badge cv-badge-green" style="font-size:.62rem">
                                        {{ batch.journal_entry.reference }}
                                    </span>
                                    <span v-else class="cv-badge cv-badge-neutral" style="font-size:.62rem">
                                        {{ lang === 'ar' ? 'لا يوجد' : 'None' }}
                                    </span>
                                </td>
                                <td style="padding:.65rem 1rem;text-align:center">
                                    <span v-if="batch.is_matched" class="cv-badge cv-badge-green" style="font-size:.62rem">
                                        {{ lang === 'ar' ? 'مطابق' : 'Matched' }}
                                    </span>
                                    <span v-else class="cv-badge cv-badge-amber" style="font-size:.62rem">
                                        {{ lang === 'ar' ? 'معلق' : 'Pending' }}
                                    </span>
                                </td>
                                <td style="padding:.65rem 1rem;font-size:.72rem;color:var(--cv-text-muted)">{{ batch.created_by || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else style="padding:2.5rem;text-align:center">
                    <div style="font-size:.8rem;color:var(--cv-text-muted);margin-bottom:.75rem">
                        {{ lang === 'ar' ? 'لم يتم استلام بضاعة بعد' : 'No items received yet' }}
                    </div>
                    <Link v-if="canReceive && canReceiveNow"
                          :href="inventoryReceiveUrl"
                          class="cv-btn cv-btn-amber"
                          style="font-size:.78rem">
                        {{ lang === 'ar' ? 'ابدأ الاستلام الآن' : 'Start Receiving Now' }}
                    </Link>
                </div>
            </div>

            <!-- ══ SECTION — Linked AP Invoices (both types) ══════ -->
            <div class="cv-card mt-5" style="padding:0;overflow:hidden">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--cv-border);display:flex;align-items:center;gap:.75rem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
                        {{ lang === 'ar' ? 'فواتير الشراء المرتبطة' : 'Linked AP Invoices' }}
                        <span style="font-size:.7rem;font-weight:400;color:var(--cv-text-muted);margin-inline-start:6px">
                            ({{ linkedInvoices.length }})
                        </span>
                    </div>
                </div>

                <div v-if="linkedInvoices.length > 0" style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                        <thead>
                            <tr style="border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'المرجع' : 'Reference' }}</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'المدفوع' : 'Paid' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الرصيد' : 'Balance' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الحالة' : 'Status' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in linkedInvoices" :key="inv.id"
                                style="border-bottom:1px solid var(--cv-border)"
                                @mouseover="$event.currentTarget.style.background='var(--cv-bg-hover)'"
                                @mouseleave="$event.currentTarget.style.background=''">
                                <td style="padding:.65rem 1rem;font-weight:600;color:var(--cv-amber)">{{ inv.reference || ('#' + inv.id) }}</td>
                                <td style="padding:.65rem 1rem;color:var(--cv-text-secondary)">{{ inv.date }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:700;color:var(--cv-blue-text)">{{ fmt(inv.total) }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;color:var(--cv-green-bright)">{{ fmt(inv.paid) }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:600;color:var(--cv-blue-text)">
                                    {{ fmt(inv.balance) }}
                                    <span style="font-size:.65rem;color:var(--cv-text-muted);margin-inline-start:2px">{{ inv.currency }}</span>
                                </td>
                                <td style="padding:.65rem 1rem;text-align:center">
                                    <span class="cv-badge" :class="invoiceStatusBadgeClass(inv.status)">{{ inv.status }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else style="padding:2rem;text-align:center">
                    <div style="font-size:.78rem;color:var(--cv-text-muted)">
                        {{ lang === 'ar'
                            ? 'لا توجد فواتير مرتبطة بعد — يظهر هنا بعد مطابقة المستلمات مع فاتورة مورد'
                            : 'No linked invoices yet — appears here after matching receipts to a supplier AP invoice' }}
                    </div>
                </div>
            </div>

            <!-- ══ SECTION — PO Estimate Lines (CATTLE only) ══════ -->
            <div v-if="isCattle && po.lines && po.lines.length > 0" class="cv-card mt-5" style="padding:0;overflow:hidden">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--cv-border)">
                    <div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
                        {{ lang === 'ar' ? 'تفصيل الأصناف المقدرة' : 'Estimated Breed Breakdown' }}
                        <span style="font-size:.72rem;font-weight:400;color:var(--cv-text-muted);margin-inline-start:4px">({{ lang === 'ar' ? 'للمرجع فقط' : 'for reference only' }})</span>
                    </div>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                        <thead>
                            <tr style="border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'السلالة' : 'Breed' }}</th>
                                <th style="padding:.6rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'الجنس' : 'Sex' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'وزن مقدر' : 'Est. Weight' }}</th>
                                <th style="padding:.6rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text)">{{ lang === 'ar' ? 'سعر/كجم' : 'Price/kg' }}</th>
                                <th style="padding:.6rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, idx) in po.lines" :key="line.id ?? idx"
                                style="border-bottom:1px solid var(--cv-border)">
                                <td style="padding:.65rem 1rem;color:var(--cv-text-primary);font-weight:500">{{ line.breed || '—' }}</td>
                                <td style="padding:.65rem 1rem;text-align:center;color:var(--cv-text-secondary)">{{ line.sex || '—' }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;color:var(--cv-blue-text)">{{ line.estimated_weight_kg ? fmt(line.estimated_weight_kg) + ' kg' : '—' }}</td>
                                <td style="padding:.65rem 1rem;text-align:end;font-weight:600;color:var(--cv-blue-text)">{{ line.price_per_kg ? fmt(line.price_per_kg, 4) : '—' }}</td>
                                <td style="padding:.65rem 1rem;font-size:.72rem;color:var(--cv-text-muted)">{{ line.notes || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /cv-page -->

        <!-- ══ CONFIRM PO MODAL ════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="confirmPoModal" class="cv-modal-backdrop" @click.self="confirmPoModal = false">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header" style="justify-content:center;border-bottom:none;padding-bottom:0">
                        <div style="width:52px;height:52px;border-radius:50%;background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);display:flex;align-items:center;justify-content:center">
                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </div>
                    </div>
                    <div class="cv-modal-body" style="text-align:center;padding-top:.75rem">
                        <div style="font-size:.9rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:.5rem">
                            {{ lang === 'ar' ? 'تأكيد أمر الشراء' : 'Confirm Purchase Order' }}
                        </div>
                        <div style="font-size:.78rem;color:var(--cv-text-muted);margin-bottom:.75rem">
                            {{ isCattle
                                ? (lang === 'ar' ? 'بعد التأكيد يصبح الأمر مفتوحاً لاستلام الحيوانات. لن تستطيع تعديله.' : 'After confirmation the PO becomes open for receiving animals. You cannot edit it.')
                                : (lang === 'ar' ? 'بعد التأكيد يصبح الأمر مفتوحاً لاستلام البضاعة. لن تستطيع تعديله.' : 'After confirmation the PO becomes open for receiving items. You cannot edit it.') }}
                        </div>
                        <div style="padding:.75rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);border:1px solid var(--cv-border);text-align:start;font-size:.78rem">
                            <div class="flex justify-between mb-1">
                                <span style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'المورد' : 'Supplier' }}</span>
                                <span style="color:var(--cv-text-primary);font-weight:600">{{ n(po.supplier, 'name') }}</span>
                            </div>
                            <!-- Cattle summary -->
                            <template v-if="isCattle">
                                <div class="flex justify-between mb-1">
                                    <span style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'النطاق' : 'Range' }}</span>
                                    <span style="color:var(--cv-blue-text);font-weight:600">{{ po.min_head }}–{{ po.max_head }} {{ lang === 'ar' ? 'رأس' : 'head' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'السعر المقدر/كجم' : 'Est. Price/kg' }}</span>
                                    <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(po.estimated_price_per_kg, 4) }} {{ po.currency ?? 'EGP' }}</span>
                                </div>
                            </template>
                            <!-- Inventory summary -->
                            <template v-else>
                                <div class="flex justify-between">
                                    <span style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'عدد الأصناف' : 'Items' }}</span>
                                    <span style="color:var(--cv-blue-text);font-weight:700">{{ inventoryItemLines.length }} {{ lang === 'ar' ? 'صنف' : 'item(s)' }}</span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="cv-modal-footer" style="justify-content:center">
                        <button class="cv-btn cv-btn-ghost" @click="confirmPoModal = false">{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}</button>
                        <button class="cv-btn cv-btn-amber" :disabled="confirmPoLoading" @click="executeConfirmPo">
                            {{ confirmPoLoading ? (lang === 'ar' ? 'جارٍ…' : 'Processing…') : (lang === 'ar' ? 'نعم، تأكيد' : 'Yes, Confirm') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══ FORCE CLOSE MODAL ══════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="forceCloseModal" class="cv-modal-backdrop" @click.self="forceCloseModal = false">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header" style="justify-content:center;border-bottom:none;padding-bottom:0">
                        <div style="width:52px;height:52px;border-radius:50%;background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);display:flex;align-items:center;justify-content:center">
                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px">
                                <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                        </div>
                    </div>
                    <div class="cv-modal-body" style="padding-top:.75rem">
                        <div style="font-size:.9rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:.375rem;text-align:center">
                            {{ lang === 'ar' ? 'إغلاق أمر الشراء قسراً' : 'Force Close Purchase Order' }}
                        </div>
                        <div style="font-size:.78rem;color:var(--cv-text-muted);margin-bottom:1rem;text-align:center">
                            {{ isCattle
                                ? (lang === 'ar'
                                    ? `تم استلام ${po.received_head} من ${po.max_head} رأس. أدخل سبب الإغلاق.`
                                    : `${po.received_head} of ${po.max_head} head received. Enter the reason for closing.`)
                                : (lang === 'ar'
                                    ? `استلام جزئي مسجّل. أدخل سبب إغلاق الأمر.`
                                    : `Partial receipt recorded. Enter the reason for closing this PO.`) }}
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'سبب الإغلاق' : 'Closure Reason' }}
                                <span style="color:var(--cv-danger-text)">*</span>
                            </label>
                            <textarea v-model="forceCloseReason" class="cv-textarea" rows="3"
                                      :placeholder="lang === 'ar'
                                        ? 'مثال: المورد أعلن عدم توفر الكمية المتبقية…'
                                        : 'e.g. Supplier confirmed remaining stock unavailable…'" />
                            <div v-if="forceCloseError" class="cv-field-error">{{ forceCloseError }}</div>
                        </div>
                    </div>
                    <div class="cv-modal-footer" style="justify-content:center">
                        <button class="cv-btn cv-btn-ghost" @click="forceCloseModal = false">{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}</button>
                        <button class="cv-btn cv-btn-amber" :disabled="forceCloseLoading" @click="executeForceClose">
                            {{ forceCloseLoading ? (lang === 'ar' ? 'جارٍ…' : 'Processing…') : (lang === 'ar' ? 'تأكيد الإغلاق' : 'Confirm Close') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══ DELETE MODAL ═══════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
                <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header" style="justify-content:center;border-bottom:none;padding-bottom:0">
                        <div style="width:52px;height:52px;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center">
                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
                            </svg>
                        </div>
                    </div>
                    <div class="cv-modal-body" style="text-align:center;padding-top:.75rem">
                        <div style="font-size:.9rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:.375rem">
                            {{ lang === 'ar' ? 'حذف أمر الشراء' : 'Delete Purchase Order' }}
                        </div>
                        <div style="font-size:.78rem;color:var(--cv-text-muted)">
                            {{ lang === 'ar' ? 'هل أنت متأكد؟ لا يمكن التراجع.' : 'Are you sure? This cannot be undone.' }}
                        </div>
                    </div>
                    <div class="cv-modal-footer" style="justify-content:center">
                        <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}</button>
                        <button class="cv-btn cv-btn-danger" :disabled="deleteLoading" @click="executeDelete">
                            {{ deleteLoading ? (lang === 'ar' ? 'جارٍ…' : 'Processing…') : (lang === 'ar' ? 'نعم، احذف' : 'Yes, Delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>