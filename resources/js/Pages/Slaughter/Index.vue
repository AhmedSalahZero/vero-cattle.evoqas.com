<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO — Slaughter/Index.vue
//  Index-only pattern: all CRUD via slide-in panel.
//  URL stays /slaughter throughout.
//  📌 METHODOLOGY REFERENCE: DressingPercentage (#19)
//  dressing_pct = (deadweight_kg / liveweight_kg) × 100
// ══════════════════════════════════════════════════════════════════
import { ref, computed, watch, nextTick } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n,today } = useLocale()

const props = defineProps({
    records:  Object,
    farms:    Array,
    lots:     Array,
    filters:  Object,
})

// ── Permission helpers ─────────────────────────────────────────────
const { can } = usePermissions()
const canCreate = computed(() => can('create slaughter'))
const canEdit   = computed(() => can('edit slaughter'))
const canDelete = computed(() => can('delete slaughter'))

// ── Filter state ───────────────────────────────────────────────────
const filterFarm    = ref(props.filters?.farm_id   ?? '')
const filterLot     = ref(props.filters?.lot_id    ?? '')
const filterDateFrom= ref(props.filters?.date_from ?? '')
const filterDateTo  = ref(props.filters?.date_to   ?? '')

function applyFilters() {
    router.get(route('slaughter.index'), {
        farm_id:   filterFarm.value    || undefined,
        lot_id:    filterLot.value     || undefined,
        date_from: filterDateFrom.value|| undefined,
        date_to:   filterDateTo.value  || undefined,
    }, { preserveState: true, replace: true })
}
function clearFilters() {
    filterFarm.value = ''; filterLot.value = ''
    filterDateFrom.value = ''; filterDateTo.value = ''
    applyFilters()
}

// Lots filtered by selected farm
const filteredLots = computed(() =>
    filterFarm.value
        ? props.lots.filter(l => String(l.farm_id) === String(filterFarm.value))
        : props.lots
)

// ── Panel state ────────────────────────────────────────────────────
const panelOpen = ref(false)
const panelMode = ref('create') // 'create' | 'edit'
const editingId = ref(null)

// Animals list loaded from API
const panelAnimals = ref([])
const loadingAnimals = ref(false)

const form = useForm({
    animal_id:      '',
    farm_id:        '',
    lot_id:         '',
    date:           localToday(),
    abattoir:       '',
    liveweight_kg:  '',
    deadweight_kg:  '',
    grade:          '',
    brand_number:   '',
    slaughter_cost: '',
    currency:       'EGP',
    notes:          '',
})

// Live dressing% preview
// 📌 METHODOLOGY REFERENCE: DressingPercentage
const liveDressingPct = computed(() => {
    const lw = parseFloat(form.liveweight_kg)
    const dw = parseFloat(form.deadweight_kg)
    if (lw > 0 && dw > 0 && dw <= lw) {
        return ((dw / lw) * 100).toFixed(1)
    }
    return null
})

// Cascade: farm → lots
const panelLots = computed(() =>
    form.farm_id
        ? props.lots.filter(l => String(l.farm_id) === String(form.farm_id))
        : []
)

// QA-049: while an existing record is being loaded into the form these watchers must stay quiet —
// before, opening Edit cleared the lot and the animal (they reset themselves as farm/lot were filled in).
let suppressCascade = false
watch(() => form.farm_id, () => {
    if (suppressCascade) return
    form.lot_id     = ''
    form.animal_id  = ''
    panelAnimals.value = []
    if (form.farm_id) loadAnimals()
})

watch(() => form.lot_id, () => {
    if (suppressCascade) return
    form.animal_id = ''
    panelAnimals.value = []
    if (form.lot_id) loadAnimals()
})

// QA-050: newest answer wins (a slow answer for an earlier farm/lot must not replace the list)
let animalsReq = 0
async function loadAnimals() {
    const myReq = ++animalsReq
    loadingAnimals.value = true
    try {
        const params = new URLSearchParams()
        if (form.farm_id) params.append('farm_id', form.farm_id)
        if (form.lot_id)  params.append('lot_id',  form.lot_id)
        // ✅ uses web middleware (session auth) — not sanctum
        const res = await fetch(route('slaughter.animals') + '?' + params)
        if (!res.ok) throw new Error('HTTP ' + res.status)
        const list = await res.json()
        if (myReq !== animalsReq) return
        panelAnimals.value = list
    } catch {
        if (myReq !== animalsReq) return
        panelAnimals.value = []
    } finally {
        if (myReq === animalsReq) loadingAnimals.value = false
    }
}

function openCreate() {
    suppressCascade = false
    form.reset()
    form.date = localToday()
    form.currency = 'EGP'
    editingId.value = null
    panelMode.value = 'create'
    panelAnimals.value = []
    panelOpen.value = true
}

async function openEdit(rec) {
    suppressCascade     = true
    form.animal_id      = ''
    form.farm_id        = rec.farm_id
    form.lot_id         = rec.lot_id ?? ''
    form.date           = rec.date
    form.abattoir       = rec.abattoir ?? ''
    form.liveweight_kg  = rec.liveweight_kg ?? ''
    form.deadweight_kg  = rec.deadweight_kg ?? ''
    form.grade          = rec.grade ?? ''
    form.brand_number   = rec.brand_number ?? ''
    form.slaughter_cost = rec.slaughter_cost ?? ''
    form.currency       = rec.currency ?? 'EGP'
    form.notes          = rec.notes ?? ''
    form.clearErrors()
    editingId.value     = rec.id
    panelMode.value     = 'edit'
    // QA-049: the animal of an existing record is already 'slaughtered', so it is not in the list of
    // active animals. Show that one animal; farm, lot and animal cannot be changed on an existing record
    // (the server keeps them as they are).
    panelAnimals.value  = rec.animal ? [rec.animal] : []
    form.animal_id      = rec.animal_id
    panelOpen.value     = true
    await nextTick()
    suppressCascade = false
}

function closePanel() { panelOpen.value = false }
const formErrorList = computed(() => [...new Set(Object.values(form.errors || {}))])

function submitForm() {
    if (panelMode.value === 'create') {
        form.post(route('slaughter.store'), {
            onSuccess: closePanel,
        })
    } else {
        form.put(route('slaughter.show', editingId.value), {
            onSuccess: closePanel,
        })
    }
}

// ── Delete modal ───────────────────────────────────────────────────
const deleteModal   = ref(false)
const deletingRecord= ref(null)

function confirmDelete(rec) {
    deletingRecord.value = rec
    deleteModal.value    = true
}
function doDelete() {
    router.delete(route('slaughter.show', deletingRecord.value.id), {
        onSuccess: () => { deleteModal.value = false }
    })
}

// ── Grade badge color ──────────────────────────────────────────────
function gradeClass(grade) {
    if (!grade) return 'cv-badge-muted'
    const g = grade.toUpperCase()
    if (['A+','A','1'].includes(g)) return 'cv-badge-success'
    if (['B','2'].includes(g))      return 'cv-badge-info'
    if (['C','3'].includes(g))      return 'cv-badge-warning'
    return 'cv-badge-muted'
}

// ── Dressing pct color ─────────────────────────────────────────────
function dressingClass(pct) {
    if (!pct) return 'cv-badge-muted'
    if (pct >= 55) return 'cv-badge-success'
    if (pct >= 48) return 'cv-badge-info'
    if (pct >= 42) return 'cv-badge-warning'
    return 'cv-badge-danger'
}

// ── Stat counts ────────────────────────────────────────────────────
const totalRecords = computed(() => props.records?.meta?.total ?? props.records?.data?.length ?? 0)
const totalCost = computed(() =>
    (props.records?.data ?? []).reduce((s, r) => s + parseFloat(r.slaughter_cost || 0), 0)
)
const avgDressing = computed(() => {
    const withPct = (props.records?.data ?? []).filter(r => r.dressing_pct)
    if (!withPct.length) return null
    return (withPct.reduce((s, r) => s + parseFloat(r.dressing_pct), 0) / withPct.length).toFixed(1)
})

// ── Number format ──────────────────────────────────────────────────
const fmt = (n) => n == null ? '—' : Number(n).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtW = (n) => n == null ? '—' : Number(n).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
</script>

<template>
   
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ─────────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ lang === 'ar' ? 'العمليات' : 'Operations' }}</div>
                <div class="cv-page-hero-title">{{ lang === 'ar' ? 'سجلات الذبح' : 'Slaughter Records' }}</div>
                <div class="cv-page-hero-sub">
                    {{ lang === 'ar' ? 'تتبع أحداث الذبح ونسب التصافي والتكاليف' : 'Track slaughter events, dressing percentages, and costs' }}
                </div>
                <div class="cv-page-hero-actions">
                    <button v-if="canCreate" class="cv-btn cv-btn-amber" @click="openCreate">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        {{ lang === 'ar' ? 'تسجيل ذبح' : 'Record Slaughter' }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- ── Stats row ─────────────────────────────────────── -->
                <div class="flex gap-3 mb-5 flex-wrap">
                    <div class="cv-card flex-1 min-w-[140px] p-4">
                        <div class="text-xs" style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'إجمالي السجلات' : 'Total Records' }}</div>
                        <div class="text-2xl font-bold mt-1" style="color:var(--cv-blue-text)">{{ totalRecords }}</div>
                    </div>
                    <div class="cv-card flex-1 min-w-[140px] p-4">
                        <div class="text-xs" style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'إجمالي تكاليف الصفحة' : 'Page Slaughter Cost' }}</div>
                        <div class="text-2xl font-bold mt-1" style="color:var(--cv-amber-text)">{{ fmt(totalCost) }} <span class="text-sm font-normal">{{ lang === 'ar' ? 'ج.م' : 'EGP' }}</span></div>
                    </div>
                    <div class="cv-card flex-1 min-w-[140px] p-4">
                        <div class="text-xs" style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'متوسط نسبة التصافي' : 'Avg Dressing %' }}</div>
                        <div class="text-2xl font-bold mt-1" style="color:var(--cv-green-bright)">
                            {{ avgDressing != null ? avgDressing + '%' : '—' }}
                        </div>
                    </div>
                </div>

                <!-- ── Filters ───────────────────────────────────────── -->
                <div class="cv-card p-4 mb-5">
                    <div class="flex flex-wrap gap-3 items-end">
                        <div class="flex-1 min-w-[160px]">
                            <label class="cv-label">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</label>
                            <select v-model="filterFarm" class="cv-select">
                                <option value="">{{ lang === 'ar' ? 'كل المزارع' : 'All Farms' }}</option>
                                <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                            </select>
                        </div>
                        <div class="flex-1 min-w-[160px]">
                            <label class="cv-label">{{ lang === 'ar' ? 'الحظيرة' : 'Lot' }}</label>
                            <select v-model="filterLot" class="cv-select">
                                <option value="">{{ lang === 'ar' ? 'كل الحظائر' : 'All Lots' }}</option>
                                <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                            </select>
                        </div>
                        <div class="flex-1 min-w-[140px]">
                            <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'Date From' }}</label>
                            <input v-model="filterDateFrom" type="date" class="cv-input"/>
                        </div>
                        <div class="flex-1 min-w-[140px]">
                            <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'Date To' }}</label>
                            <input v-model="filterDateTo" type="date" class="cv-input"/>
                        </div>
                        <div class="flex gap-2">
                            <button class="cv-btn cv-btn-amber" @click="applyFilters">
                                {{ lang === 'ar' ? 'تطبيق' : 'Apply' }}
                            </button>
                            <button class="cv-btn cv-btn-ghost" @click="clearFilters">
                                {{ lang === 'ar' ? 'مسح' : 'Clear' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Table ─────────────────────────────────────────── -->
                <div class="cv-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="cv-table">
                            <thead>
                                <tr>
                                    <th class="text-start">{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                                    <th class="text-start">{{ lang === 'ar' ? 'الماشية' : 'Animal' }}</th>
                                    <th class="text-start">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</th>
                                    <th class="text-start">{{ lang === 'ar' ? 'الحظيرة' : 'Lot' }}</th>
                                    <th class="text-end">{{ lang === 'ar' ? 'وزن حي (كجم)' : 'Liveweight (kg)' }}</th>
                                    <th class="text-end">{{ lang === 'ar' ? 'وزن ذبيحة (كجم)' : 'Deadweight (kg)' }}</th>
                                    <th class="text-center">{{ lang === 'ar' ? 'نسبة التصافي' : 'Dressing %' }}</th>
                                    <th class="text-center">{{ lang === 'ar' ? 'الدرجة' : 'Grade' }}</th>
                                    <th class="text-end">{{ lang === 'ar' ? 'تكلفة الذبح' : 'Slaughter Cost' }}</th>
                                    <th class="text-center">{{ lang === 'ar' ? 'إجراءات' : 'Actions' }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-if="records.data.length">
                                    <tr v-for="rec in records.data" :key="rec.id">
                                        <td>
                                            <span class="font-medium" style="color:var(--cv-text-primary)">{{ rec.date }}</span>
                                        </td>
                                        <td>
                                            <div class="font-semibold text-sm" style="color:var(--cv-text-primary)">{{ rec.animal?.tag ?? '—' }}</div>
                                            <div class="text-xs" style="color:var(--cv-text-muted)">{{ rec.animal?.breed ?? '' }}</div>
                                        </td>
                                        <td style="color:var(--cv-text-secondary)">{{ rec.farm ? n(rec.farm,'name') : '—' }}</td>
                                        <td style="color:var(--cv-text-secondary)">{{ rec.lot?.name ?? '—' }}</td>
                                        <td class="text-end font-mono" style="color:var(--cv-blue-text)">{{ fmtW(rec.liveweight_kg) }}</td>
                                        <td class="text-end font-mono" style="color:var(--cv-blue-text)">{{ fmtW(rec.deadweight_kg) }}</td>
                                        <td class="text-center">
                                            <span v-if="rec.dressing_pct" :class="['cv-badge', dressingClass(rec.dressing_pct)]">
                                                {{ Number(rec.dressing_pct).toFixed(1) }}%
                                            </span>
                                            <span v-else class="cv-badge cv-badge-muted">—</span>
                                        </td>
                                        <td class="text-center">
                                            <span v-if="rec.grade" :class="['cv-badge', gradeClass(rec.grade)]">{{ rec.grade }}</span>
                                            <span v-else class="cv-badge cv-badge-muted">—</span>
                                        </td>
                                        <td class="text-end font-mono" style="color:var(--cv-amber-text)">
                                            {{ fmt(rec.slaughter_cost) }}
                                        </td>
                                        <td class="text-center">
                                            <div class="flex gap-1 justify-center">
                                                <button v-if="canEdit" class="cv-btn cv-btn-ghost" style="padding:0.25rem 0.5rem;font-size:0.75rem" @click="openEdit(rec)">
                                                    {{ lang === 'ar' ? 'تعديل' : 'Edit' }}
                                                </button>
                                                <button v-if="canDelete" class="cv-btn cv-btn-ghost" style="padding:0.25rem 0.5rem;font-size:0.75rem;color:var(--cv-danger-text)" @click="confirmDelete(rec)">
                                                    {{ lang === 'ar' ? 'حذف' : 'Delete' }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-else>
                                    <td colspan="10">
                                        <div class="cv-empty">
                                            <div class="cv-empty-icon">🔪</div>
                                            <div class="cv-empty-title">{{ lang === 'ar' ? 'لا توجد سجلات ذبح' : 'No slaughter records' }}</div>
                                            <div class="cv-empty-sub">{{ lang === 'ar' ? 'ابدأ بتسجيل أول عملية ذبح' : 'Start by recording the first slaughter event' }}</div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="records.meta?.last_page > 1" class="flex justify-center gap-1 p-4">
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

            <!-- ══════════════════════════════════════════════════════
                 SLIDE-IN PANEL
            ══════════════════════════════════════════════════════ -->
            <Transition name="panel-fade">
                <div v-if="panelOpen" class="cv-modal-backdrop" @click.self="closePanel">
                    <div
                        class="cv-scroll"
                        :style="{
                            position:'fixed', top:0, bottom:0,
                            [isRtl ? 'left' : 'right']: 0,
                            width:'500px', maxWidth:'100vw',
                            background:'var(--cv-bg-modal)',
                            borderLeft: isRtl ? 'none' : '1px solid var(--cv-border-md)',
                            borderRight: isRtl ? '1px solid var(--cv-border-md)' : 'none',
                            zIndex: 60, overflowY:'auto',
                        }"
                    >
                        <!-- Panel header -->
                        <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--cv-border-md)">
                            <div>
                                <div class="font-semibold" style="color:var(--cv-text-primary)">
                                    {{ panelMode === 'create'
                                        ? (lang === 'ar' ? 'تسجيل ذبح جديد' : 'Record Slaughter')
                                        : (lang === 'ar' ? 'تعديل سجل الذبح' : 'Edit Slaughter Record') }}
                                </div>
                                <!-- Live dressing preview -->
                                <div v-if="liveDressingPct" class="mt-1 flex items-center gap-2">
                                    <span class="text-xs" style="color:var(--cv-text-muted)">{{ lang === 'ar' ? 'نسبة التصافي المحسوبة:' : 'Calculated dressing:' }}</span>
                                    <span :class="['cv-badge text-sm font-bold', dressingClass(parseFloat(liveDressingPct))]">{{ liveDressingPct }}%</span>
                                </div>
                            </div>
                            <button class="cv-btn cv-btn-ghost" @click="closePanel">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <div class="p-5 flex flex-col gap-5">
                            <!-- Section 1: Location -->
                            <div class="cv-card p-4">
                                <div class="cv-section-title">
                                    <span class="cv-number-badge" style="background:var(--cv-amber-light);color:var(--cv-amber);border:1px solid var(--cv-amber-border);border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">{{ lang === 'ar' ? '١' : '1' }}</span>
                                    {{ lang === 'ar' ? 'الموقع والماشية' : 'Location & Animal' }}
                                </div>
                                <div class="flex flex-col gap-3">
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }} <span style="color:var(--cv-danger)">*</span></label>
                                        <select v-model="form.farm_id" class="cv-select" :disabled="panelMode === 'edit'" :class="{'cv-input-error': form.errors.farm_id}">
                                            <option value="">{{ lang === 'ar' ? 'اختر المزرعة' : 'Select farm' }}</option>
                                            <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                                        </select>
                                        <div v-if="form.errors.farm_id" class="cv-error">{{ form.errors.farm_id }}</div>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'الحظيرة' : 'Lot' }}</label>
                                        <select v-model="form.lot_id" class="cv-select" :disabled="!form.farm_id || panelMode === 'edit'">
                                            <option value="">{{ lang === 'ar' ? 'اختر الحظيرة (اختياري)' : 'Select lot (optional)' }}</option>
                                            <option v-for="l in panelLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'الماشية' : 'Animal' }} <span style="color:var(--cv-danger)">*</span></label>
                                        <select v-model="form.animal_id" class="cv-select" :disabled="!form.farm_id || loadingAnimals || panelMode === 'edit'" :class="{'cv-input-error': form.errors.animal_id}">
                                            <option value="">{{ loadingAnimals ? (lang==='ar'?'جاري التحميل…':'Loading…') : (lang==='ar'?'اختر الماشية':'Select animal') }}</option>
                                            <option v-for="a in panelAnimals" :key="a.id" :value="a.id">{{ a.tag }} — {{ a.breed }}</option>
                                        </select>
                                        <div v-if="form.errors.animal_id" class="cv-error">{{ form.errors.animal_id }}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2: Weights & Dressing -->
                            <div class="cv-card p-4">
                                <div class="cv-section-title">
                                    <span style="background:var(--cv-blue-light);color:var(--cv-blue-text);border:1px solid var(--cv-blue-border);border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">{{ lang === 'ar' ? '٢' : '2' }}</span>
                                    {{ lang === 'ar' ? 'الأوزان ونسبة التصافي' : 'Weights & Dressing %' }}
                                </div>
                                <!-- 📌 METHODOLOGY REFERENCE: DressingPercentage -->
                                <div class="text-xs mb-3 p-2 rounded" style="background:var(--cv-info-bg);color:var(--cv-info-text);border:1px solid var(--cv-info-border)">
                                    {{ lang === 'ar' ? 'نسبة التصافي = (وزن الذبيحة ÷ وزن حي) × ١٠٠ — تُحسب تلقائياً' : 'Dressing % = (Deadweight ÷ Liveweight) × 100 — auto-calculated' }}
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'الوزن الحي (كجم)' : 'Liveweight (kg)' }} <span style="color:var(--cv-danger)">*</span></label>
                                        <input v-model="form.liveweight_kg" type="number" step="0.01" min="0" class="cv-input" :class="{'cv-input-error': form.errors.liveweight_kg}" :placeholder="lang==='ar'?'مثال: 450':'e.g. 450'"/>
                                        <div v-if="form.errors.liveweight_kg" class="cv-error">{{ form.errors.liveweight_kg }}</div>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'وزن الذبيحة (كجم)' : 'Deadweight (kg)' }}</label>
                                        <input v-model="form.deadweight_kg" type="number" step="0.01" min="0" class="cv-input" :placeholder="lang==='ar'?'مثال: 252':'e.g. 252'"/>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'الدرجة' : 'Grade' }}</label>
                                        <input v-model="form.grade" type="text" class="cv-input" :placeholder="lang==='ar'?'مثال: A+':'e.g. A+'"/>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'رقم العلامة' : 'Brand Number' }}</label>
                                        <input v-model="form.brand_number" type="text" class="cv-input" :placeholder="lang==='ar'?'رقم التعريف':'ID number'"/>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Event Details -->
                            <div class="cv-card p-4">
                                <div class="cv-section-title">
                                    <span style="background:var(--cv-green-dim);color:var(--cv-green-bright);border:1px solid var(--cv-green-border);border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700">{{ lang === 'ar' ? '٣' : '3' }}</span>
                                    {{ lang === 'ar' ? 'تفاصيل الحدث' : 'Event Details' }}
                                </div>
                                <div class="flex flex-col gap-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="cv-label">{{ lang === 'ar' ? 'التاريخ' : 'Date' }} <span style="color:var(--cv-danger)">*</span></label>
                                            <input v-model="form.date" type="date" class="cv-input" :max="today" :class="{'cv-input-error': form.errors.date}"/>
                                            <div v-if="form.errors.date" class="cv-error">{{ form.errors.date }}</div>
                                        </div>
                                        <div>
                                            <label class="cv-label">{{ lang === 'ar' ? 'تكلفة الذبح' : 'Slaughter Cost' }}</label>
                                            <input v-model="form.slaughter_cost" type="number" step="0.01" min="0" class="cv-input" placeholder="0.00"/>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'المسلخ' : 'Abattoir' }}</label>
                                        <input v-model="form.abattoir" type="text" class="cv-input" :placeholder="lang==='ar'?'اسم المسلخ':'Abattoir name'"/>
                                    </div>
                                    <div>
                                        <label class="cv-label">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</label>
                                        <textarea v-model="form.notes" rows="2" class="cv-textarea" :placeholder="lang==='ar'?'ملاحظات إضافية…':'Additional notes…'"/>
                                    </div>
                                </div>
                            </div>

                            <!-- QA-051: every message from the server is shown (cost, deadweight, grade … had no place on screen) -->
                            <div v-if="formErrorList.length" class="cv-alert cv-alert-danger">
                                <ul style="margin:0;padding-inline-start:1.1rem">
                                    <li v-for="m in formErrorList" :key="m" style="font-size:.8rem">{{ m }}</li>
                                </ul>
                            </div>
                            <!-- Actions -->
                            <div class="flex justify-end gap-3">
                                <button class="cv-btn cv-btn-ghost" @click="closePanel">{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}</button>
                                <button class="cv-btn cv-btn-amber" :disabled="form.processing" @click="submitForm">
                                    {{ form.processing
                                        ? (lang==='ar'?'جاري الحفظ…':'Saving…')
                                        : panelMode === 'create'
                                            ? (lang==='ar'?'حفظ السجل':'Save Record')
                                            : (lang==='ar'?'حفظ التعديلات':'Save Changes') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ══════════════════════════════════════════════════════
                 DELETE MODAL
            ══════════════════════════════════════════════════════ -->
            <Transition name="modal-fade">
                <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
                    <div class="cv-modal" style="max-width:420px">
                        <div class="cv-modal-header">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full flex items-center justify-center" style="background:var(--cv-danger-bg)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="20" height="20" style="color:var(--cv-danger-text)">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                    </svg>
                                </div>
                                <span class="font-semibold" style="color:var(--cv-text-primary)">{{ lang === 'ar' ? 'حذف سجل الذبح' : 'Delete Slaughter Record' }}</span>
                            </div>
                        </div>
                        <div class="cv-modal-body" style="color:var(--cv-text-secondary)">
                            <p>{{ lang === 'ar' ? 'سيتم حذف سجل الذبح للماشية' : 'This will permanently delete the slaughter record for' }}
                                <strong style="color:var(--cv-text-primary)"> {{ deletingRecord?.animal?.tag }}</strong>
                                {{ lang === 'ar' ? 'بتاريخ' : 'dated' }}
                                <strong style="color:var(--cv-text-primary)"> {{ deletingRecord?.date }}</strong>.
                            </p>
                            <p class="mt-2 text-sm" style="color:var(--cv-danger-text)">{{ lang === 'ar' ? 'لا يمكن التراجع عن هذا الإجراء.' : 'This action cannot be undone.' }}</p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}</button>
                            <button class="cv-btn cv-btn-danger" @click="doDelete">{{ lang === 'ar' ? 'حذف نهائياً' : 'Delete Permanently' }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    
</template>

<style scoped>
.panel-fade-enter-active, .panel-fade-leave-active { transition: opacity 0.2s ease; }
.panel-fade-enter-from, .panel-fade-leave-to       { opacity: 0; }
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.2s ease; }
.modal-fade-enter-from, .modal-fade-leave-to       { opacity: 0; }
</style>