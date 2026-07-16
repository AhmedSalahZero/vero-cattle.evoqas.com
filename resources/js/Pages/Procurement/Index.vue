<script setup>
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const props = defineProps({
    records:   { type: Object, required: true },
    farms:     { type: Array,  default: () => [] },
    suppliers: { type: Array,  default: () => [] },
    companies: { type: Array,  default: () => [] },
    filters:   { type: Object, default: () => ({}) },
})

const { locale: lang, isRtl, n } = useLocale()
const { can, isSuperAdmin } = usePermissions()
const canCreate  = computed(() => isSuperAdmin.value || can('create procurement'))
const canEdit    = computed(() => isSuperAdmin.value || can('edit procurement'))
const canDelete  = computed(() => isSuperAdmin.value || can('delete procurement'))
const canReceive = computed(() => isSuperAdmin.value || can('create animals'))

// ── Filters ───────────────────────────────────────────────────────
const filterForm = ref({
    farm_id:     props.filters.farm_id     ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    po_status:   props.filters.po_status   ?? '',
    po_type:     props.filters.po_type     ?? '',
    date_from:   props.filters.date_from   ?? '',
    date_to:     props.filters.date_to     ?? '',
})

function applyFilters() {
    router.get(route('procurement.index'), filterForm.value, { preserveState: true, replace: true })
}
function resetFilters() {
    filterForm.value = { farm_id: '', supplier_id: '', po_status: '', po_type: '', date_from: '', date_to: '' }
    applyFilters()
}
const hasActiveFilters = computed(() => Object.values(filterForm.value).some(v => v !== ''))

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal   = ref(false)
const deleteTarget  = ref(null)
const deleteLoading = ref(false)

function confirmDelete(rec) { deleteTarget.value = rec; deleteModal.value = true }
function executeDelete() {
    if (!deleteTarget.value) return
    deleteLoading.value = true
    router.delete(route('procurement.destroy', deleteTarget.value.id), {
        onFinish: () => { deleteLoading.value = false; deleteModal.value = false; deleteTarget.value = null },
    })
}

// ── Confirm PO modal (draft → open) ──────────────────────────────
const confirmPoModal  = ref(false)
const confirmPoTarget = ref(null)
function openConfirmPo(rec) { confirmPoTarget.value = rec; confirmPoModal.value = true }
function executeConfirmPo() {
    if (!confirmPoTarget.value) return
    const confirmRoute = confirmPoTarget.value.po_type === 'inventory'
    ? route('procurement.confirm-inventory-po', confirmPoTarget.value.id)
    : route('procurement.confirm-po', confirmPoTarget.value.id)
    router.patch(confirmRoute, {}, {
        onFinish: () => { confirmPoModal.value = false; confirmPoTarget.value = null },
    })
}

// ── PO Status helpers ─────────────────────────────────────────────
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
        draft:               'مسودة',
        open:                'مفتوح',
        partially_received:  'استلام جزئي',
        on_track:            'في المسار',
        fully_received:      'مكتمل',
        over_received:       'زيادة استلام',
        force_closed:        'مغلق قسراً',
    }[status] ?? status
    return {
        draft:               'Draft',
        open:                'Open',
        partially_received:  'Partial',
        on_track:            'On Track',
        fully_received:      'Complete',
        over_received:       'Over Received',
        force_closed:        'Force Closed',
    }[status] ?? status
}

// ── Receipt progress bar ──────────────────────────────────────────
function receiptProgress(rec) {
    if (rec.po_type === 'inventory') {
        const max = Number(rec.ordered_qty) || 1
        const received = Number(rec.received_qty) || 0
        return Math.min(100, Math.round((received / max) * 100))
    }
    const max = Number(rec.max_head) || 1
    const received = Number(rec.received_head) || 0
    return Math.min(100, Math.round((received / max) * 100))
}

function progressColor(rec) {
    if (rec.is_over_received) return 'var(--cv-danger-text)'
    if (rec.po_status === 'fully_received') return 'var(--cv-green-bright)'
    if (rec.po_status === 'on_track') return 'var(--cv-blue-text)'
    return 'var(--cv-amber)'
}

function fmt(val) {
    if (val === null || val === undefined) return '—'
    return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-EG', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    })
}

// ── KPI stats ─────────────────────────────────────────────────────
const stats = computed(() => {
    const all = props.records.data ?? []
    return {
        total:     props.records.total ?? all.length,
        draft:     all.filter(r => r.po_status === 'draft').length,
        open:      all.filter(r => ['open','partially_received','on_track'].includes(r.po_status)).length,
        complete:  all.filter(r => r.po_status === 'fully_received').length,
        over:      all.filter(r => r.po_status === 'over_received').length,
    }
})
</script>

<template>
   

        <!-- ══ PAGE HERO ══════════════════════════════════════════ -->
        <div class="cv-page-hero" :dir="isRtl ? 'rtl' : 'ltr'">
            <div class="cv-page-hero-eyebrow">{{ lang === 'ar' ? 'المشتريات' : 'Procurement' }}</div>
            <div class="cv-page-hero-title">{{ lang === 'ar' ? 'أوامر الشراء' : 'Purchase Orders' }}</div>
            <div class="cv-page-hero-sub">
                {{ lang === 'ar'
                    ? 'أنشئ أمر الشراء أولاً، ثم استلم الحيوانات بضغطة زر'
                    : 'Create a PO first, then receive animals with a single button' }}
            </div>
            <div class="cv-page-hero-actions">
                 <Link v-if="canCreate" :href="route('procurement.create') + '?type=inventory'" class="cv-btn cv-btn-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                        <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
                        <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>
                    </svg>
                    {{ lang === 'ar' ? '+ أمر شراء مخزون' : '+ Inventory PO' }}
                </Link>
                <Link v-if="canCreate" :href="route('procurement.create') + '?type=cattle'" class="cv-btn cv-btn-amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    {{ lang === 'ar' ? '+ أمر شراء ماشية' : '+ Cattle PO' }}
                </Link>
            </div>
        </div>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ KPI STRIP ══════════════════════════════════════ -->
            <div class="grid gap-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
                <div class="cv-stat-card cv-stat-card-amber">
                    <div class="cv-stat-card-label">{{ lang === 'ar' ? 'الكل' : 'Total POs' }}</div>
                    <div class="cv-stat-card-value">{{ stats.total }}</div>
                </div>
                <div class="cv-stat-card cv-stat-card-amber">
                    <div class="cv-stat-card-label">{{ lang === 'ar' ? 'مسودة' : 'Draft' }}</div>
                    <div class="cv-stat-card-value">{{ stats.draft }}</div>
                </div>
                <div class="cv-stat-card cv-stat-card-blue">
                    <div class="cv-stat-card-label">{{ lang === 'ar' ? 'مفتوح / جاري' : 'Open / Active' }}</div>
                    <div class="cv-stat-card-value">{{ stats.open }}</div>
                </div>
                <div class="cv-stat-card cv-stat-card-green">
                    <div class="cv-stat-card-label">{{ lang === 'ar' ? 'مكتمل' : 'Complete' }}</div>
                    <div class="cv-stat-card-value">{{ stats.complete }}</div>
                </div>
                <div v-if="stats.over > 0" class="cv-stat-card"
                     style="border-color:var(--cv-danger-border);background:var(--cv-danger-bg)">
                    <div class="cv-stat-card-label" style="color:var(--cv-danger-text)">
                        {{ lang === 'ar' ? 'زيادة استلام' : 'Over-Received' }}
                    </div>
                    <div class="cv-stat-card-value" style="color:var(--cv-danger-text)">{{ stats.over }}</div>
                </div>
            </div>

            <!-- ══ FILTERS ════════════════════════════════════════ -->
            <div class="cv-card mb-5" style="padding:1rem 1.25rem">
                <div class="flex flex-wrap gap-3 items-end">
                    <div class="cv-field" style="min-width:150px;flex:1">
                        <label class="cv-label">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</label>
                        <select v-model="filterForm.farm_id" class="cv-select" @change="applyFilters">
                            <option value="">{{ lang === 'ar' ? 'كل المزارع' : 'All Farms' }}</option>
                            <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
                        </select>
                    </div>
                    <div class="cv-field" style="min-width:150px;flex:1">
                        <label class="cv-label">{{ lang === 'ar' ? 'المورد' : 'Supplier' }}</label>
                        <select v-model="filterForm.supplier_id" class="cv-select" @change="applyFilters">
                            <option value="">{{ lang === 'ar' ? 'كل الموردين' : 'All Suppliers' }}</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ n(s, 'name') }}</option>
                        </select>
                    </div>
                    <div class="cv-field" style="min-width:150px;flex:1">
                        <label class="cv-label">{{ lang === 'ar' ? 'الحالة' : 'PO Status' }}</label>
                        <select v-model="filterForm.po_status" class="cv-select" @change="applyFilters">
                            <option value="">{{ lang === 'ar' ? 'الكل' : 'All' }}</option>
                            <option value="draft">{{ lang === 'ar' ? 'مسودة' : 'Draft' }}</option>
                            <option value="open">{{ lang === 'ar' ? 'مفتوح' : 'Open' }}</option>
                            <option value="partially_received">{{ lang === 'ar' ? 'استلام جزئي' : 'Partially Received' }}</option>
                            <option value="on_track">{{ lang === 'ar' ? 'في المسار' : 'On Track' }}</option>
                            <option value="fully_received">{{ lang === 'ar' ? 'مكتمل' : 'Fully Received' }}</option>
                            <option value="over_received">{{ lang === 'ar' ? 'زيادة استلام' : 'Over Received' }}</option>
                            <option value="force_closed">{{ lang === 'ar' ? 'مغلق قسراً' : 'Force Closed' }}</option>
                        </select>
                    </div>
                    <div class="cv-field" style="min-width:130px;flex:1">
                        <label class="cv-label">{{ lang === 'ar' ? 'نوع الأمر' : 'PO Type' }}</label>
                        <select v-model="filterForm.po_type" class="cv-select" @change="applyFilters">
                            <option value="">{{ lang === 'ar' ? 'الكل' : 'All Types' }}</option>
                            <option value="cattle">{{ lang === 'ar' ? 'ماشية' : 'Cattle' }}</option>
                            <option value="inventory">{{ lang === 'ar' ? 'مخزون' : 'Inventory' }}</option>
                        </select>
                    </div>
                    <div class="cv-field" style="min-width:130px;flex:1">
                        <label class="cv-label">{{ lang === 'ar' ? 'من' : 'From' }}</label>
                        <input type="date" v-model="filterForm.date_from" class="cv-input" @change="applyFilters" />
                    </div>
                    <div class="cv-field" style="min-width:130px;flex:1">
                        <label class="cv-label">{{ lang === 'ar' ? 'إلى' : 'To' }}</label>
                        <input type="date" v-model="filterForm.date_to" class="cv-input" @change="applyFilters" />
                    </div>
                    <div v-if="hasActiveFilters" style="flex:0;padding-bottom:.1rem">
                        <button class="cv-btn cv-btn-ghost" @click="resetFilters">
                            {{ lang === 'ar' ? 'إعادة' : 'Reset' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- ══ TABLE ══════════════════════════════════════════ -->
            <div v-if="records.data.length > 0" class="cv-card" style="padding:0;overflow:hidden">
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                        <thead>
                            <tr style="border-bottom:1px solid var(--cv-border);background:var(--cv-bg-card-alt)">
                                <th style="padding:.75rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);white-space:nowrap">
                                    {{ lang === 'ar' ? 'رقم الأمر / التاريخ' : 'PO / Date' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);white-space:nowrap">
                                    {{ lang === 'ar' ? 'المورد' : 'Supplier' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);white-space:nowrap">
                                    {{ lang === 'ar' ? 'المزرعة / اللوت' : 'Farm / Lot' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);white-space:nowrap">
                                    {{ lang === 'ar' ? 'التقدم' : 'Progress' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text);white-space:nowrap">
                                    {{ lang === 'ar' ? 'إجمالي الأمر' : 'PO Total' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);white-space:nowrap">
                                    {{ lang === 'ar' ? 'الاستحقاق' : 'Due' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);white-space:nowrap">
                                    {{ lang === 'ar' ? 'الحالة' : 'Status' }}
                                </th>
                                <th style="padding:.75rem 1rem;text-align:center;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
                                    {{ lang === 'ar' ? 'إجراءات' : 'Actions' }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rec in records.data" :key="rec.id"
                                style="border-bottom:1px solid var(--cv-border);transition:background .15s"
                                @mouseover="$event.currentTarget.style.background='var(--cv-bg-hover)'"
                                @mouseleave="$event.currentTarget.style.background=''">

                              <!-- PO ref / Date / Type badge -->
                                <td style="padding:.75rem 1rem">
                                    <div style="font-weight:700;color:var(--cv-amber);font-size:.8rem">
                                        {{ rec.po_reference || ('#' + rec.id) }}
                                    </div>
                                    <div style="font-size:.68rem;color:var(--cv-text-muted);margin-top:2px">
                                        {{ rec.date }}
                                    </div>
                                    <span :style="rec.po_type === 'inventory'
                                        ? 'font-size:.62rem;font-weight:600;color:var(--cv-amber);background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);padding:1px 6px;border-radius:4px;display:inline-block;margin-top:3px'
                                        : 'font-size:.62rem;font-weight:600;color:var(--cv-green-bright);background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);padding:1px 6px;border-radius:4px;display:inline-block;margin-top:3px'">
                                        {{ rec.po_type === 'inventory'
                                            ? (lang === 'ar' ? 'مخزون' : 'Inventory')
                                            : (lang === 'ar' ? 'ماشية' : 'Cattle') }}
                                    </span>
                                </td>

                                <!-- Supplier -->
                                <td style="padding:.75rem 1rem">
                                    <div style="color:var(--cv-text-secondary);font-weight:500">
                                        {{ n(rec.supplier, 'name') }}
                                    </div>
                                </td>

                                <!-- Farm / Lot -->
                                <td style="padding:.75rem 1rem">
                                    <div style="color:var(--cv-text-secondary)">{{ n(rec.farm, 'name') }}</div>
                                    <div v-if="rec.lot" style="font-size:.68rem;color:var(--cv-text-muted)">{{ rec.lot.name }}</div>
                                </td>

                                <!-- Receipt Progress -->
                                <td style="padding:.75rem 1rem;min-width:160px">
                                    <div class="flex items-center gap-2">
                                        <!-- Progress bar -->
                                        <div style="flex:1;height:6px;background:var(--cv-border-md);border-radius:999px;overflow:hidden">
                                            <div :style="{
                                                width: receiptProgress(rec) + '%',
                                                height: '100%',
                                                background: progressColor(rec),
                                                borderRadius: '999px',
                                                transition: 'width .3s',
                                            }"></div>
                                        </div>
                                        <!-- Count -->
                                        <div style="font-size:.72rem;white-space:nowrap;color:var(--cv-text-secondary)">
                                            <template v-if="rec.po_type === 'cattle'">
                                                <span :style="rec.is_over_received ? 'color:var(--cv-danger-text);font-weight:700' : 'color:var(--cv-blue-text);font-weight:700'">
                                                    {{ rec.received_head ?? 0 }}
                                                </span>
                                                <span style="color:var(--cv-text-muted)"> / {{ rec.min_head }}–{{ rec.max_head }} {{ lang === 'ar' ? 'رأس' : 'head' }}</span>
                                            </template>
                                            <template v-else>
                                                <span :style="rec.received_qty > rec.ordered_qty ? 'color:var(--cv-danger-text);font-weight:700' : 'color:var(--cv-blue-text);font-weight:700'">
                                                    {{ fmt(rec.received_qty ?? 0) }}
                                                </span>
                                                <span style="color:var(--cv-text-muted)"> / {{ fmt(rec.ordered_qty ?? 0) }}</span>
                                            </template>
                                        </div>
                                    </div>
                                    <!-- Over-received warning tag -->
                                    <div v-if="rec.is_over_received"
                                         style="font-size:.62rem;color:var(--cv-danger-text);margin-top:3px;font-weight:600">
                                        ⚠ {{ lang === 'ar' ? 'تجاوز الحد الأقصى' : 'Exceeds max' }}
                                    </div>
                                </td>

                                <!-- PO Total Value -->
                                <td style="padding:.75rem 1rem;text-align:end;white-space:nowrap">
                                    <span style="font-weight:600;color:var(--cv-blue-text)">
                                        {{ rec.total_amount ? fmt(rec.total_amount) : '—' }}
                                    </span>
                                    <span style="font-size:.65rem;color:var(--cv-text-muted);margin-inline-start:2px">
                                        {{ rec.total_amount ? (rec.currency ?? 'EGP') : '' }}
                                    </span>
                                </td>

                                <!-- Due date -->
                                <td style="padding:.75rem 1rem;white-space:nowrap">
                                    <span v-if="rec.due_date" style="color:var(--cv-amber);font-size:.75rem">{{ rec.due_date }}</span>
                                    <span v-else style="color:var(--cv-text-muted)">—</span>
                                </td>

                                <!-- PO Status -->
                                <td style="padding:.75rem 1rem;text-align:center">
                                    <span class="cv-badge" :class="poStatusBadgeClass(rec.po_status)">
                                        {{ poStatusLabel(rec.po_status) }}
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td style="padding:.75rem 1rem">
                                    <div class="flex items-center justify-center gap-1">

                                        <!-- View -->
                                        <Link :href="route('procurement.show', rec.id)"
                                              class="cv-action-btn cv-action-btn-blue"
                                              :title="lang === 'ar' ? 'عرض' : 'View'">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                            </svg>
                                        </Link>

                                        <!-- Edit (draft only) -->
                                        <Link v-if="canEdit && rec.can_edit"
                                              :href="route('procurement.edit', rec.id)"
                                              class="cv-action-btn"
                                              :title="lang === 'ar' ? 'تعديل' : 'Edit'">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                                                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                        </Link>

                                        <!-- Confirm PO (draft → open) -->
                                        <button v-if="canEdit && rec.po_status === 'draft'"
                                                class="cv-action-btn cv-action-btn-amber"
                                                :title="lang === 'ar' ? 'تأكيد الأمر' : 'Confirm PO'"
                                                @click="openConfirmPo(rec)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                                                <polyline points="20 6 9 17 4 12"/>
                                            </svg>
                                        </button>

                                      <!-- ★ RECEIVE CATTLE BUTTON ★ — cattle POs only -->
                                        <Link v-if="canReceive && rec.can_receive && rec.po_type === 'cattle'"
                                              :href="route('procurement.receive', rec.id)"
                                              class="cv-action-btn cv-action-btn-green"
                                              :title="lang === 'ar' ? 'استلام ماشية' : 'Receive Cattle'">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                                                <path d="M3 9l2-5h14l2 5"/>
                                                <rect x="5" y="9" width="14" height="10" rx="2"/>
                                                <path d="M8 9V7M16 9V7"/>
                                                <circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>
                                            </svg>
                                        </Link>

                                        <!-- ★ RECEIVE ITEMS BUTTON ★ — inventory POs only -->
                                        <!-- Navigates to Inventory Transactions page with po_id pre-filled -->
                                        <Link v-if="canReceive && rec.can_receive && rec.po_type === 'inventory'"
                                              :href="route('procurement.show', rec.id) + '?open_receive=1'"
                                              class="cv-action-btn cv-action-btn-green"
                                              :title="lang === 'ar' ? 'استلام بضاعة' : 'Receive Items'">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                                                <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
                                                <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>
                                            </svg>
                                        </Link>

                                        <!-- Delete (draft only) -->
                                        <button v-if="canDelete && rec.can_delete"
                                                class="cv-action-btn cv-action-btn-danger"
                                                :title="lang === 'ar' ? 'حذف' : 'Delete'"
                                                @click="confirmDelete(rec)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6l-1 14H6L5 6"/>
                                                <path d="M10 11v6M14 11v6"/>
                                                <path d="M9 6V4h6v2"/>
                                            </svg>
                                        </button>

                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══ EMPTY STATE ════════════════════════════════════ -->
            <div v-else class="cv-card" style="padding:3rem;text-align:center">
                <div style="width:52px;height:52px;margin:0 auto 1rem;background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:var(--cv-radius);display:flex;align-items:center;justify-content:center">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" style="width:24px;height:24px">
                        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 01-8 0"/>
                    </svg>
                </div>
                <div style="font-size:.9rem;font-weight:600;color:var(--cv-text-primary);margin-bottom:.375rem">
                    {{ lang === 'ar' ? 'لا توجد أوامر شراء' : 'No Purchase Orders' }}
                </div>
                <div style="font-size:.78rem;color:var(--cv-text-muted);margin-bottom:1.25rem">
                    {{ lang === 'ar' ? 'أنشئ أمر شراء أولاً قبل إضافة الحيوانات' : 'Create a purchase order before adding animals' }}
                </div>
                <Link v-if="canCreate" :href="route('procurement.create')" class="cv-btn cv-btn-amber">
                    {{ lang === 'ar' ? 'إضافة أول أمر شراء' : 'Add First Purchase Order' }}
                </Link>
            </div>

            <!-- ══ PAGINATION ══════════════════════════════════════ -->
            <div v-if="records.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
                <template v-for="link in records.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" class="cv-page-btn" :class="{ active: link.active }" v-html="link.label" />
                    <span v-else class="cv-page-btn" style="opacity:.35;cursor:not-allowed" v-html="link.label" />
                </template>
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
                        <div style="font-size:.9rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:.375rem">
                            {{ lang === 'ar' ? 'تأكيد أمر الشراء' : 'Confirm Purchase Order' }}
                        </div>
                        <div style="font-size:.78rem;color:var(--cv-text-muted)">
                            {{ lang === 'ar'
                                ? `هل تريد تأكيد "${confirmPoTarget?.po_reference || '#' + confirmPoTarget?.id}"؟ بعد التأكيد تصبح جاهزاً لاستلام الحيوانات.`
                                : `Confirm "${confirmPoTarget?.po_reference || '#' + confirmPoTarget?.id}"? After confirmation you can start receiving animals.` }}
                        </div>
                    </div>
                    <div class="cv-modal-footer" style="justify-content:center">
                        <button class="cv-btn cv-btn-ghost" @click="confirmPoModal = false">
                            {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                        </button>
                        <button class="cv-btn cv-btn-amber" @click="executeConfirmPo">
                            {{ lang === 'ar' ? 'نعم، تأكيد' : 'Yes, Confirm' }}
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
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6M14 11v6"/>
                                <path d="M9 6V4h6v2"/>
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
                        <div class="cv-alert cv-alert-danger" style="margin-top:.75rem;text-align:start">
                            {{ lang === 'ar' ? 'سيتم حذف جميع البنود التفصيلية أيضاً.' : 'All breakdown lines will also be deleted.' }}
                        </div>
                    </div>
                    <div class="cv-modal-footer" style="justify-content:center">
                        <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">
                            {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                        </button>
                        <button class="cv-btn cv-btn-danger" :disabled="deleteLoading" @click="executeDelete">
                            {{ deleteLoading ? (lang === 'ar' ? 'جارٍ…' : 'Processing…') : (lang === 'ar' ? 'نعم، احذف' : 'Yes, Delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    
</template>