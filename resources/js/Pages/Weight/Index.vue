<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Weight/Index.vue
//
//  SESSION-GROUPED layout:
//    Each row = one weighing session (same lot + date + recorder).
//    Click a session row → expands to show individual animals.
//
//  Sold/slaughtered animals:
//    Edit and Delete buttons are hidden for sold/slaughtered animals.
//    The server also blocks those requests with 422.
//
//  Panels:
//    A) Single entry  — one animal, date, weight_kg
//    B) Batch by lot  — one lot, N animals in table rows
//    C) Edit          — correct a single existing record
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'
import axios from 'axios'

defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()
const page = usePage()

// ── Props ──────────────────────────────────────────────────────────
const props = defineProps({
    sessions: Array,   // grouped sessions from controller
    lots:     Array,
    filters:  Object,
    kpis:     Object,  // { total_records, avg_weight, avg_adg, last_date }
})

// ── Permissions ────────────────────────────────────────────────────
const { can } = usePermissions()
const canCreate = computed(() => can('create weight'))
const canEdit   = computed(() => can('edit weight'))
const canDelete = computed(() => can('delete weight'))

// ── Expanded session rows ──────────────────────────────────────────
const expandedSessions = ref(new Set())
function toggleSession(key) {
    if (expandedSessions.value.has(key)) {
        expandedSessions.value.delete(key)
    } else {
        expandedSessions.value.add(key)
    }
    // Force reactivity on Set
    expandedSessions.value = new Set(expandedSessions.value)
}
function isExpanded(key) {
    return expandedSessions.value.has(key)
}

// ── Filter state ───────────────────────────────────────────────────
const filterLot      = ref(props.filters?.lot_id    ?? '')
const filterDateFrom = ref(props.filters?.date_from ?? '')
const filterDateTo   = ref(props.filters?.date_to   ?? '')
const searchLot      = ref('')

const displaySessions = computed(() => {
    if (!searchLot.value.trim()) return props.sessions
    const q = searchLot.value.toLowerCase()
    return props.sessions.filter(s =>
        s.lot_name?.toLowerCase().includes(q) ||
        s.session_ref?.toLowerCase().includes(q) ||
        s.recorder?.toLowerCase().includes(q)
    )
})

function applyFilters() {
    router.get(route('weights.index'), {
        lot_id:    filterLot.value    || undefined,
        date_from: filterDateFrom.value || undefined,
        date_to:   filterDateTo.value   || undefined,
    }, { preserveState: true, replace: true })
}

function clearFilters() {
    filterLot.value = ''
    filterDateFrom.value = ''
    filterDateTo.value = ''
    searchLot.value = ''
    router.get(route('weights.index'))
}

// ── ADG badge colour helper ────────────────────────────────────────
function adgClass(val) {
    if (val == null || val === 0) return 'cv-badge-neutral'
    const v = parseFloat(val)
    if (v >= 1.2) return 'cv-badge-green'
    if (v >= 0.7) return 'cv-badge-amber'
    return 'cv-badge-danger'
}

// ── Number formatter ───────────────────────────────────────────────
function fmt(val, decimals = 2) {
    if (val == null) return '—'
    const num = parseFloat(val)
    return isNaN(num) ? '—' : num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}

// ── Is animal locked (sold or slaughtered) ─────────────────────────
function isLocked(animal) {
    return animal?.status === 'sold' || animal?.status === 'slaughtered'
}

// ── Panel A — Single entry ─────────────────────────────────────────
const panelA        = ref(false)
const panelAAnimals = ref([])

const formA = useForm({
    farm_id:         '',
    lot_id:          '',
    animal_id:       '',
    date:            localToday(),
    weight_kg:       '',
    scale_reference: '',
    session_ref:     '',
    notes:           '',
})

// QA-045: only the newest answer may fill the animal list (a slow answer for the previous lot is ignored)
let lotAnimalsReqA = 0
watch(() => formA.lot_id, async (lotId) => {
    const myReq = ++lotAnimalsReqA
    panelAAnimals.value = []
    formA.animal_id = ''
    if (!lotId) return
    try {
        const { data } = await axios.get(route('weights.lot-animals', lotId))
        if (myReq !== lotAnimalsReqA) return
        panelAAnimals.value = data.animals ?? []
    } catch {
        panelAAnimals.value = []
    }
})

function openPanelA() {
    formA.reset()
    formA.date = localToday()
    panelAAnimals.value = []
    panelA.value = true
}

function submitA() {
    formA.post(route('weights.store'), {
        onSuccess: () => { panelA.value = false }
    })
}

// ── Panel B — Batch by lot ─────────────────────────────────────────
const panelB        = ref(false)
const batchAnimals  = ref([])
const batchLotId    = ref('')
const batchDate     = ref(localToday())
const batchSession  = ref('')
const batchScale    = ref('')
const batchNotes    = ref('')
const batchReadings = ref([])
const batchErrors   = ref({})
const batchLoading  = ref(false)

let lotAnimalsReqB = 0
watch(batchLotId, async (lotId) => {
    const myReq = ++lotAnimalsReqB
    batchAnimals.value  = []
    batchReadings.value = []
    if (!lotId) return
    try {
        const { data } = await axios.get(route('weights.lot-animals', lotId))
        if (myReq !== lotAnimalsReqB) return
        batchAnimals.value  = data.animals ?? []
        batchReadings.value = batchAnimals.value.map(a => ({
            animal_id: a.id,
            tag:       a.tag,
            breed:     a.breed ?? '',
            weight_kg: '',
        }))
    } catch {
        batchAnimals.value = []
    }
})

function openPanelB() {
    batchLotId.value    = ''
    batchDate.value     = localToday()
    batchSession.value  = ''
    batchScale.value    = ''
    batchNotes.value    = ''
    batchReadings.value = []
    batchErrors.value   = {}
    panelB.value = true
}

async function submitBatch() {
    if (batchLoading.value) return          // QA-046: ignore a second click while the first is still being saved
    batchErrors.value = {}
    const filled = batchReadings.value.filter(r => r.weight_kg !== '' && r.weight_kg != null)
    if (!filled.length) {
        batchErrors.value.readings = lang.value === 'ar'
            ? 'أدخل وزناً لماشية واحدة على الأقل'
            : 'Enter weight for at least one animal'
        return
    }
    batchLoading.value = true
    try {
        router.post(route('weights.batch'), {
            lot_id:          batchLotId.value,
            date:            batchDate.value,
            session_ref:     batchSession.value,
            scale_reference: batchScale.value,
            notes:           batchNotes.value,
            readings:        filled.map(r => ({ animal_id: r.animal_id, weight_kg: r.weight_kg })),
        }, {
            onSuccess: () => { panelB.value = false },
            onError:   (e) => { batchErrors.value = e },
            onFinish:  () => { batchLoading.value = false },
        })
    } catch (e) {
        batchLoading.value = false
    }
}
// QA-047: every message from the server is listed (before, only one kind was shown)
const batchErrorList = computed(() => [...new Set(Object.values(batchErrors.value || {}))])

// ── Panel C — Edit single record ───────────────────────────────────
const panelC     = ref(false)
const editRecord = ref(null)

const formC = useForm({
    date:            '',
    weight_kg:       '',
    scale_reference: '',
    session_ref:     '',
    notes:           '',
})

function openEdit(record) {
    editRecord.value      = record
    formC.date            = record.date
    formC.weight_kg       = record.weight_kg
    formC.scale_reference = record.scale_reference ?? ''
    formC.session_ref     = record.session_ref     ?? ''
    formC.notes           = record.notes           ?? ''
    panelC.value = true
}

function submitEdit() {
    formC.patch(route('weights.update', editRecord.value.id), {
        onSuccess: () => { panelC.value = false }
    })
}

// ── Delete modal ───────────────────────────────────────────────────
const deleteModal  = ref(false)
const deleteTarget = ref(null)

function confirmDelete(record) {
    deleteTarget.value = record
    deleteModal.value  = true
}

function doDelete() {
    router.delete(route('weights.destroy', deleteTarget.value.id), {
        onSuccess: () => { deleteModal.value = false }
    })
}
</script>

<template>
    <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- ══════════════════════════════════════════════════════════
         PAGE HERO
    ══════════════════════════════════════════════════════════ -->
    <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="5" r="3"/>
                <path d="M6.5 8a7 7 0 1 0 11 0"/>
                <line x1="12" y1="2" x2="12" y2="8"/>
                <line x1="4" y1="20" x2="20" y2="20"/>
            </svg>
            {{ lang === 'ar' ? 'الغذاء والوزن' : 'Feed & Weight' }}
        </div>
        <div class="cv-page-hero-title">
            {{ lang === 'ar' ? 'سجلات الوزن' : 'Weight Records' }}
        </div>
        <div class="cv-page-hero-sub">
            {{ lang === 'ar'
                ? 'جلسات الوزن مجمّعة — انقر على أي جلسة لعرض تفاصيل الماشيةات'
                : 'Weighing sessions grouped by date & lot — click a session to expand animals' }}
        </div>
        <div class="cv-page-hero-actions" v-if="canCreate">
            <button class="cv-btn cv-btn-ghost" @click="openPanelB">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="8" y="2" width="8" height="4" rx="1"/>
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="13" y2="16"/>
                </svg>
                {{ lang === 'ar' ? 'تسجيل دفعي' : 'Batch Entry' }}
            </button>
            <button class="cv-btn cv-btn-amber" @click="openPanelA">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                {{ lang === 'ar' ? 'تسجيل وزن' : 'Add Weight' }}
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         KPI STAT CARDS
    ══════════════════════════════════════════════════════════ -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:1.5rem">
        <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ lang === 'ar' ? 'إجمالي السجلات' : 'Total Records' }}</div>
            <div class="cv-stat-card-value">{{ (kpis.total_records ?? 0).toLocaleString() }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-green">
            <div class="cv-stat-card-label">{{ lang === 'ar' ? 'متوسط الوزن (كجم)' : 'Avg Weight (kg)' }}</div>
            <div class="cv-stat-card-value">{{ kpis.avg_weight != null ? fmt(kpis.avg_weight) : '—' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-amber">
            <div class="cv-stat-card-label">{{ lang === 'ar' ? 'متوسط النمو اليومي' : 'Avg ADG (kg/day)' }}</div>
            <div class="cv-stat-card-value">{{ kpis.avg_adg != null ? fmt(kpis.avg_adg, 3) : '—' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ lang === 'ar' ? 'آخر جلسة وزن' : 'Last Weigh Date' }}</div>
            <div class="cv-stat-card-value" style="font-size:1rem">{{ kpis.last_date ?? '—' }}</div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         FILTERS
    ══════════════════════════════════════════════════════════ -->
    <div class="cv-card" style="margin-bottom:1.25rem;padding:1rem">
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">

            <!-- Search sessions -->
            <div class="cv-field" style="flex:1;min-width:160px">
                <label class="cv-label">{{ lang === 'ar' ? 'بحث' : 'Search' }}</label>
                <div class="cv-input-icon-wrap">
                    <svg class="cv-input-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input v-model="searchLot" class="cv-input" type="text"
                           :placeholder="lang === 'ar' ? 'القطيع أو المُسجِّل أو الجلسة...' : 'Lot, recorder or session…'" />
                </div>
            </div>

            <!-- Lot filter -->
            <div class="cv-field" style="flex:1;min-width:180px">
                <label class="cv-label">{{ lang === 'ar' ? 'القطيع' : 'Lot' }}</label>
                <select v-model="filterLot" class="cv-select">
                    <option value="">{{ lang === 'ar' ? '— جميع القطعان —' : '— All lots —' }}</option>
                    <option v-for="lot in lots" :key="lot.id" :value="lot.id">
                        {{ lot.name }} — {{ n(lot.farm, 'name') }}
                    </option>
                </select>
            </div>

            <!-- Date from -->
            <div class="cv-field" style="flex:1;min-width:140px">
                <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'Date From' }}</label>
                <input v-model="filterDateFrom" class="cv-input" type="date" />
            </div>

            <!-- Date to -->
            <div class="cv-field" style="flex:1;min-width:140px">
                <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'Date To' }}</label>
                <input v-model="filterDateTo" class="cv-input" type="date" />
            </div>

            <div style="display:flex;gap:0.5rem">
                <button class="cv-btn cv-btn-amber" @click="applyFilters">
                    {{ lang === 'ar' ? 'تصفية' : 'Filter' }}
                </button>
                <button class="cv-btn cv-btn-ghost" @click="clearFilters">
                    {{ lang === 'ar' ? 'مسح' : 'Clear' }}
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SESSION TABLE
    ══════════════════════════════════════════════════════════ -->
    <div class="cv-card" style="overflow:hidden">

        <!-- Empty state -->
        <div v-if="!displaySessions.length" style="padding:3rem;text-align:center">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"
                 style="color:var(--cv-text-muted);margin:0 auto 1rem">
                <circle cx="12" cy="5" r="3"/>
                <path d="M6.5 8a7 7 0 1 0 11 0"/>
                <line x1="12" y1="2" x2="12" y2="8"/>
                <line x1="4" y1="20" x2="20" y2="20"/>
            </svg>
            <div style="color:var(--cv-text-muted);font-size:0.9rem">
                {{ lang === 'ar' ? 'لا توجد جلسات وزن' : 'No weighing sessions found' }}
            </div>
        </div>

        <div v-else style="overflow-x:auto">
            <table class="cv-table" style="min-width:700px">
                <thead>
                    <tr>
                        <!-- Expand toggle column -->
                        <th style="width:36px"></th>
                        <th class="text-start">{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'القطيع' : 'Lot' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'رقم الجلسة' : 'Session Ref' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'عدد الماشيةات' : 'Animals' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'متوسط الوزن' : 'Avg Weight' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'متوسط النمو' : 'Avg ADG' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'المُسجِّل' : 'Recorder' }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="session in displaySessions" :key="session.key">

                        <!-- ── Session header row ── -->
                        <tr class="session-row" @click="toggleSession(session.key)"
                            style="cursor:pointer">

                            <!-- Expand chevron -->
                            <td style="padding-inline-start:0.75rem">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.5"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     :style="{ transform: isExpanded(session.key) ? 'rotate(90deg)' : 'rotate(0deg)',
                                               transition: 'transform 200ms ease',
                                               color: 'var(--cv-amber)' }">
                                    <polyline points="9 18 15 12 9 6"/>
                                </svg>
                            </td>

                            <!-- Date -->
                            <td>
                                <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                    {{ session.date }}
                                </span>
                            </td>

                            <!-- Lot -->
                            <td>
                                <span style="font-size:0.85rem;color:var(--cv-text-secondary)">
                                    {{ session.lot_name }}
                                </span>
                            </td>

                            <!-- Session ref -->
                            <td>
                                <span v-if="session.session_ref"
                                      style="font-size:0.78rem;color:var(--cv-text-muted);font-family:monospace">
                                    {{ session.session_ref }}
                                </span>
                                <span v-else style="color:var(--cv-text-muted)">—</span>
                            </td>

                            <!-- Animal count badge -->
                            <td>
                                <span class="cv-badge cv-badge-info">
                                    {{ session.animal_count }}
                                    {{ lang === 'ar' ? 'ماشية' : 'animals' }}
                                </span>
                            </td>

                            <!-- Avg weight -->
                            <td>
                                <span style="color:var(--cv-blue-text);font-weight:700">
                                    {{ session.avg_weight != null ? fmt(session.avg_weight) + ' kg' : '—' }}
                                </span>
                            </td>

                            <!-- Avg ADG -->
                            <td>
                                <span v-if="session.avg_adg != null"
                                      :class="['cv-badge', adgClass(session.avg_adg)]">
                                    {{ fmt(session.avg_adg, 3) }} {{ lang === 'ar' ? 'كجم/يوم' : 'kg/d' }}
                                </span>
                                <span v-else class="cv-badge cv-badge-neutral">—</span>
                            </td>

                            <!-- Recorder -->
                            <td>
                                <span style="font-size:0.8rem;color:var(--cv-text-muted)">
                                    {{ session.recorder }}
                                </span>
                            </td>
                        </tr>

                        <!-- ── Expanded animal sub-table ── -->
                        <tr v-if="isExpanded(session.key)" :key="session.key + '_expanded'">
                            <td colspan="8" style="padding:0;background:var(--cv-bg-page)">
                                <div style="padding:0.5rem 1rem 0.75rem 2.5rem">
                                    <table class="cv-table" style="min-width:600px;background:var(--cv-bg-page)">
                                        <thead>
                                            <tr style="font-size:0.75rem">
                                                <th class="text-start">{{ lang === 'ar' ? 'الماشية' : 'Animal' }}</th>
                                                <th class="text-start">{{ lang === 'ar' ? 'الوزن (كجم)' : 'Weight (kg)' }}</th>
                                                <th class="text-start">{{ lang === 'ar' ? 'النمو' : 'Gain' }}</th>
                                                <th class="text-start">{{ lang === 'ar' ? 'معدل النمو' : 'ADG' }}</th>
                                                <th class="text-start">{{ lang === 'ar' ? 'الأيام' : 'Days' }}</th>
                                                <th class="text-start" v-if="canEdit || canDelete">
                                                    {{ lang === 'ar' ? 'إجراءات' : 'Actions' }}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="animal in session.animals" :key="animal.id">

                                                <!-- Animal -->
                                                <td>
                                                    <div style="display:flex;align-items:center;gap:0.45rem">
                                                        <div class="cv-avatar cv-avatar-sm"
                                                             :style="{ background: isLocked(animal.animal)
                                                                ? 'linear-gradient(135deg,rgba(100,100,100,.3),rgba(120,120,120,.5))'
                                                                : 'linear-gradient(135deg,var(--cv-green-dim),var(--cv-green-bright) 80%)',
                                                                fontSize: '0.6rem', flexShrink: 0 }">
                                                            {{ animal.animal?.tag?.slice(0,3) ?? '?' }}
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:600;font-size:0.83rem;color:var(--cv-text-primary)">
                                                                {{ animal.animal?.tag ?? '—' }}
                                                            </div>
                                                            <div style="font-size:0.73rem;color:var(--cv-text-muted)">
                                                                {{ animal.animal?.breed ?? '' }}
                                                                <span v-if="isLocked(animal.animal)"
                                                                      class="cv-badge cv-badge-neutral"
                                                                      style="font-size:0.65rem;padding:0.1rem 0.4rem;margin-inline-start:4px">
                                                                    {{ animal.animal?.status === 'sold'
                                                                        ? (lang === 'ar' ? 'مباع' : 'Sold')
                                                                        : (lang === 'ar' ? 'مذبوح' : 'Slaughtered') }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Weight -->
                                                <td>
                                                    <span style="color:var(--cv-blue-text);font-weight:700">
                                                        {{ fmt(animal.weight_kg) }}
                                                    </span>
                                                </td>

                                                <!-- Gain -->
                                                <td>
                                                    <span v-if="animal.total_gain_kg != null && animal.total_gain_kg !== 0"
                                                          style="color:var(--cv-green-bright);font-size:0.85rem">
                                                        +{{ fmt(animal.total_gain_kg) }} kg
                                                    </span>
                                                    <span v-else style="color:var(--cv-text-muted)">—</span>
                                                </td>

                                                <!-- ADG -->
                                                <td>
                                                    <span v-if="animal.adg_kg != null && animal.adg_kg > 0"
                                                          :class="['cv-badge', adgClass(animal.adg_kg)]">
                                                        {{ fmt(animal.adg_kg, 3) }} {{ lang === 'ar' ? 'كجم/يوم' : 'kg/d' }}
                                                    </span>
                                                    <span v-else class="cv-badge cv-badge-neutral">—</span>
                                                </td>

                                                <!-- Days -->
                                                <td>
                                                    <span v-if="animal.days_on_feed != null"
                                                          style="color:var(--cv-text-secondary);font-size:0.83rem">
                                                        {{ animal.days_on_feed }} {{ lang === 'ar' ? 'يوم' : 'd' }}
                                                    </span>
                                                    <span v-else style="color:var(--cv-text-muted)">—</span>
                                                </td>

                                                <!-- Actions — hidden for sold/slaughtered -->
                                                <td v-if="canEdit || canDelete">
                                                    <div v-if="!isLocked(animal.animal)"
                                                         style="display:flex;gap:0.3rem">
                                                        <button v-if="canEdit"
                                                                class="cv-action-btn cv-action-btn-blue"
                                                                :title="lang === 'ar' ? 'تعديل' : 'Edit'"
                                                                @click.stop="openEdit(animal)">
                                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                                                 stroke="currentColor" stroke-width="1.7"
                                                                 stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                            </svg>
                                                        </button>
                                                        <button v-if="canDelete"
                                                                class="cv-action-btn cv-action-btn-danger"
                                                                :title="lang === 'ar' ? 'حذف' : 'Delete'"
                                                                @click.stop="confirmDelete(animal)">
                                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                                                 stroke="currentColor" stroke-width="1.7"
                                                                 stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="3 6 5 6 21 6"/>
                                                                <path d="M19 6l-1 14H6L5 6"/>
                                                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                                                <path d="M9 6V4h6v2"/>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                    <span v-else style="font-size:0.75rem;color:var(--cv-text-muted)">
                                                        {{ lang === 'ar' ? 'مقفل' : 'Locked' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>

                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         PANEL A — SINGLE WEIGHT ENTRY
    ══════════════════════════════════════════════════════════ -->
    <Transition name="cv-panel">
        <div v-if="panelA" class="cv-modal-backdrop" @click.self="panelA = false">
            <aside class="cv-slide-panel" :dir="isRtl ? 'rtl' : 'ltr'" style="width:min(480px,100vw)">
                <div class="cv-modal-header">
                    <div>
                        <div style="font-size:0.7rem;color:var(--cv-amber);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">
                            {{ lang === 'ar' ? 'سجل الوزن' : 'Weight Record' }}
                        </div>
                        <h2 style="margin:0;font-size:1.05rem;font-weight:700;color:var(--cv-text-primary)">
                            {{ lang === 'ar' ? 'تسجيل وزن ماشية' : 'Record Single Weight' }}
                        </h2>
                    </div>
                    <button class="cv-action-btn" @click="panelA = false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;overflow-y:auto">
                    <!-- Section 1 — Location & Animal -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">1</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'الموقع والماشية' : 'Location & Animal' }}
                            </span>
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'القطيع *' : 'Lot *' }}</label>
                            <select v-model="formA.lot_id" class="cv-select"
                                    :class="{ 'cv-input-error': formA.errors.lot_id }">
                                <option value="">{{ lang === 'ar' ? '— اختر القطيع —' : '— Select lot —' }}</option>
                                <option v-for="lot in lots" :key="lot.id" :value="lot.id">
                                    {{ lot.name }} — {{ n(lot.farm, 'name') }}
                                </option>
                            </select>
                            <div v-if="formA.errors.lot_id" class="cv-field-error">{{ formA.errors.lot_id }}</div>
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'الماشية *' : 'Animal *' }}</label>
                            <select v-model="formA.animal_id" class="cv-select"
                                    :disabled="!formA.lot_id || !panelAAnimals.length"
                                    :class="{ 'cv-input-error': formA.errors.animal_id }">
                                <option value="">{{ lang === 'ar' ? '— اختر الماشية —' : '— Select animal —' }}</option>
                                <option v-for="a in panelAAnimals" :key="a.id" :value="a.id">
                                    {{ a.tag }} {{ a.breed ? `— ${a.breed}` : '' }}
                                </option>
                            </select>
                            <div v-if="formA.errors.animal_id" class="cv-field-error">{{ formA.errors.animal_id }}</div>
                        </div>
                    </div>

                    <!-- Section 2 — Weight Data -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">2</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'بيانات الوزن' : 'Weight Data' }}
                            </span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'التاريخ *' : 'Date *' }}</label>
                                <input v-model="formA.date" class="cv-input" type="date"
                                       :class="{ 'cv-input-error': formA.errors.date }" />
                                <div v-if="formA.errors.date" class="cv-field-error">{{ formA.errors.date }}</div>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'الوزن (كجم) *' : 'Weight (kg) *' }}</label>
                                <input v-model="formA.weight_kg" class="cv-input" type="number"
                                       min="0.1" max="2000" step="0.1"
                                       :placeholder="lang === 'ar' ? 'مثال: 350.5' : 'e.g. 350.5'"
                                       :class="{ 'cv-input-error': formA.errors.weight_kg }" />
                                <div v-if="formA.errors.weight_kg" class="cv-field-error">{{ formA.errors.weight_kg }}</div>
                            </div>
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'مرجع الميزان' : 'Scale Reference' }}</label>
                            <input v-model="formA.scale_reference" class="cv-input" type="text"
                                   :placeholder="lang === 'ar' ? 'رقم الجهاز...' : 'Scale ID…'" />
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'رقم الجلسة' : 'Session Ref' }}</label>
                            <input v-model="formA.session_ref" class="cv-input" type="text"
                                   :placeholder="lang === 'ar' ? 'معرّف الجلسة...' : 'Session ID…'" />
                        </div>
                    </div>

                    <!-- Section 3 — Notes -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">3</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}
                            </span>
                        </div>
                        <div class="cv-field">
                            <textarea v-model="formA.notes" class="cv-textarea" rows="3"
                                      :placeholder="lang === 'ar' ? 'ملاحظات إضافية...' : 'Additional notes…'" />
                        </div>
                    </div>
                </div>

                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="panelA = false">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <button class="cv-btn cv-btn-amber" :disabled="formA.processing" @click="submitA">
                        <svg v-if="formA.processing" width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite">
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                        </svg>
                        {{ lang === 'ar' ? 'حفظ السجل' : 'Save Record' }}
                    </button>
                </div>
            </aside>
        </div>
    </Transition>

    <!-- ══════════════════════════════════════════════════════════
         PANEL B — BATCH BY LOT
    ══════════════════════════════════════════════════════════ -->
    <Transition name="cv-panel">
        <div v-if="panelB" class="cv-modal-backdrop" @click.self="panelB = false">
            <aside class="cv-slide-panel" :dir="isRtl ? 'rtl' : 'ltr'" style="width:min(700px,100vw)">
                <div class="cv-modal-header">
                    <div>
                        <div style="font-size:0.7rem;color:var(--cv-amber);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">
                            {{ lang === 'ar' ? 'سجل الوزن' : 'Weight Record' }}
                        </div>
                        <h2 style="margin:0;font-size:1.05rem;font-weight:700;color:var(--cv-text-primary)">
                            {{ lang === 'ar' ? 'تسجيل دفعي للقطيع' : 'Batch Lot Weighing' }}
                        </h2>
                    </div>
                    <button class="cv-action-btn" @click="panelB = false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;overflow-y:auto">
                    <!-- Section 1 — Session Details -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">1</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'بيانات الجلسة' : 'Session Details' }}
                            </span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <div class="cv-field" style="grid-column:1/-1">
                                <label class="cv-label">{{ lang === 'ar' ? 'القطيع *' : 'Lot *' }}</label>
                                <select v-model="batchLotId" class="cv-select">
                                    <option value="">{{ lang === 'ar' ? '— اختر القطيع —' : '— Select lot —' }}</option>
                                    <option v-for="lot in lots" :key="lot.id" :value="lot.id">
                                        {{ lot.name }} — {{ n(lot.farm, 'name') }}
                                    </option>
                                </select>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'تاريخ الوزن *' : 'Weigh Date *' }}</label>
                                <input v-model="batchDate" class="cv-input" type="date" />
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'مرجع الميزان' : 'Scale Reference' }}</label>
                                <input v-model="batchScale" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'رقم الجهاز...' : 'Scale ID…'" />
                            </div>
                            <div class="cv-field" style="grid-column:1/-1">
                                <label class="cv-label">{{ lang === 'ar' ? 'رقم الجلسة' : 'Session Ref' }}</label>
                                <input v-model="batchSession" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'معرّف الجلسة...' : 'Session identifier…'" />
                            </div>
                        </div>
                    </div>

                    <!-- Section 2 — Animal Weights -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">2</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'أوزان الماشيةات' : 'Animal Weights' }}
                            </span>
                            <span v-if="batchReadings.length" class="cv-badge cv-badge-info" style="margin-inline-start:auto">
                                {{ batchReadings.length }} {{ lang === 'ar' ? 'ماشية' : 'animals' }}
                            </span>
                        </div>

                        <div v-if="!batchLotId"
                             style="padding:1.5rem;text-align:center;color:var(--cv-text-muted);font-size:0.85rem">
                            {{ lang === 'ar' ? 'اختر القطيع أولاً لعرض الماشيةات' : 'Select a lot above to load animals' }}
                        </div>
                        <div v-else-if="batchLotId && !batchReadings.length"
                             style="padding:1.5rem;text-align:center;color:var(--cv-text-muted);font-size:0.85rem">
                            {{ lang === 'ar' ? 'لا توجد ماشيةات نشطة في هذا القطيع' : 'No active animals in this lot' }}
                        </div>
                        <div v-else style="overflow-x:auto">
                            <table class="cv-table">
                                <thead>
                                    <tr>
                                        <th class="text-start">{{ lang === 'ar' ? 'الرقم' : 'Tag' }}</th>
                                        <th class="text-start">{{ lang === 'ar' ? 'السلالة' : 'Breed' }}</th>
                                        <th class="text-start" style="width:160px">{{ lang === 'ar' ? 'الوزن (كجم)' : 'Weight (kg)' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in batchReadings" :key="row.animal_id">
                                        <td><span style="font-weight:600;font-size:0.85rem">{{ row.tag }}</span></td>
                                        <td><span style="font-size:0.8rem;color:var(--cv-text-muted)">{{ row.breed || '—' }}</span></td>
                                        <td>
                                            <input v-model="row.weight_kg" class="cv-input" type="number"
                                                   min="0.1" max="2000" step="0.1"
                                                   :placeholder="lang === 'ar' ? 'اتركه فارغاً للتخطي' : 'Leave blank to skip'"
                                                   style="font-size:0.85rem;padding:0.3rem 0.6rem" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="batchErrorList.length" class="cv-alert cv-alert-danger" style="margin-top:0.5rem">
                            <ul style="margin:0;padding-inline-start:1.1rem">
                                <li v-for="m in batchErrorList" :key="m" style="font-size:.8rem">{{ m }}</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Section 3 — Notes -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">3</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'ملاحظات الجلسة' : 'Session Notes' }}
                            </span>
                        </div>
                        <div class="cv-field">
                            <textarea v-model="batchNotes" class="cv-textarea" rows="2"
                                      :placeholder="lang === 'ar' ? 'ملاحظات عامة...' : 'General session notes…'" />
                        </div>
                    </div>
                </div>

                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="panelB = false">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <button class="cv-btn cv-btn-amber"
                            :disabled="batchLoading || !batchLotId || !batchDate"
                            @click="submitBatch">
                        <svg v-if="batchLoading" width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite">
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                        </svg>
                        {{ lang === 'ar' ? 'حفظ الجلسة' : 'Save Session' }}
                    </button>
                </div>
            </aside>
        </div>
    </Transition>

    <!-- ══════════════════════════════════════════════════════════
         PANEL C — EDIT SINGLE RECORD
    ══════════════════════════════════════════════════════════ -->
    <Transition name="cv-panel">
        <div v-if="panelC" class="cv-modal-backdrop" @click.self="panelC = false">
            <aside class="cv-slide-panel" :dir="isRtl ? 'rtl' : 'ltr'" style="width:min(440px,100vw)">
                <div class="cv-modal-header">
                    <div>
                        <div style="font-size:0.7rem;color:var(--cv-amber);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">
                            {{ lang === 'ar' ? 'تعديل' : 'Edit Record' }}
                        </div>
                        <h2 style="margin:0;font-size:1.05rem;font-weight:700;color:var(--cv-text-primary)">
                            {{ editRecord?.animal?.tag ?? '—' }}
                            <span style="font-weight:400;font-size:0.88rem;color:var(--cv-text-muted)">
                                · {{ editRecord?.date }}
                            </span>
                        </h2>
                    </div>
                    <button class="cv-action-btn" @click="panelC = false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;overflow-y:auto">
                    <div class="cv-card">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'التاريخ *' : 'Date *' }}</label>
                                <input v-model="formC.date" class="cv-input" type="date"
                                       :class="{ 'cv-input-error': formC.errors.date }" />
                                <div v-if="formC.errors.date" class="cv-field-error">{{ formC.errors.date }}</div>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'الوزن (كجم) *' : 'Weight (kg) *' }}</label>
                                <input v-model="formC.weight_kg" class="cv-input" type="number"
                                       min="0.1" max="2000" step="0.1"
                                       :class="{ 'cv-input-error': formC.errors.weight_kg }" />
                                <div v-if="formC.errors.weight_kg" class="cv-field-error">{{ formC.errors.weight_kg }}</div>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'مرجع الميزان' : 'Scale Ref' }}</label>
                                <input v-model="formC.scale_reference" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'رقم الجهاز...' : 'Scale ID…'" />
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'رقم الجلسة' : 'Session Ref' }}</label>
                                <input v-model="formC.session_ref" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'معرّف الجلسة...' : 'Session ID…'" />
                            </div>
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</label>
                            <textarea v-model="formC.notes" class="cv-textarea" rows="3"
                                      :placeholder="lang === 'ar' ? 'ملاحظات إضافية...' : 'Notes…'" />
                        </div>
                    </div>
                </div>

                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="panelC = false">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <button class="cv-btn cv-btn-amber" :disabled="formC.processing" @click="submitEdit">
                        {{ lang === 'ar' ? 'حفظ التعديلات' : 'Save Changes' }}
                    </button>
                </div>
            </aside>
        </div>
    </Transition>

    <!-- ══════════════════════════════════════════════════════════
         DELETE MODAL
    ══════════════════════════════════════════════════════════ -->
    <Transition name="cv-modal-fade">
        <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
            <div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
                <div class="cv-modal-header" style="border-bottom:none;justify-content:center;padding-bottom:0">
                    <div style="width:52px;height:52px;border-radius:50%;background:rgba(239,68,68,.12);display:flex;align-items:center;justify-content:center">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                             style="color:var(--cv-danger-text)">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                </div>
                <div class="cv-modal-body" style="text-align:center">
                    <h3 style="margin:0 0 .5rem;font-size:1rem;font-weight:700;color:var(--cv-text-primary)">
                        {{ lang === 'ar' ? 'حذف سجل الوزن؟' : 'Delete Weight Record?' }}
                    </h3>
                    <p style="margin:0;font-size:0.85rem;color:var(--cv-text-muted)">
                        <span v-if="lang === 'ar'">
                            سيتم حذف وزن
                            <strong style="color:var(--cv-danger-text)">{{ deleteTarget?.animal?.tag }}</strong>
                            بتاريخ {{ deleteTarget?.date }} نهائياً.
                        </span>
                        <span v-else>
                            Weight record for
                            <strong style="color:var(--cv-danger-text)">{{ deleteTarget?.animal?.tag }}</strong>
                            on {{ deleteTarget?.date }} will be permanently removed.
                        </span>
                    </p>
                    <div class="cv-alert cv-alert-danger" style="margin-top:1rem;font-size:0.8rem">
                        {{ lang === 'ar'
                            ? 'قد يؤثر الحذف على حسابات معدل النمو اليومي (ADG).'
                            : 'Deleting this record may affect ADG calculations for this animal.' }}
                    </div>
                </div>
                <div class="cv-modal-footer" style="justify-content:center;gap:0.75rem">
                    <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <button class="cv-btn cv-btn-danger" @click="doDelete">
                        {{ lang === 'ar' ? 'نعم، احذف' : 'Yes, Delete' }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    </div>
</template>

<style scoped>
.cv-slide-panel {
    position: fixed;
    inset-block: 0;
    inset-inline-end: 0;
    background: var(--cv-bg-card);
    border-inline-start: 1px solid var(--cv-border);
    display: flex;
    flex-direction: column;
    box-shadow: -8px 0 32px rgba(0,0,0,.35);
    z-index: 1000;
    overflow: hidden;
}

/* Session row hover */
.session-row:hover td {
    background: rgba(255,255,255,0.03);
}

.cv-panel-enter-active, .cv-panel-leave-active { transition: opacity 200ms ease; }
.cv-panel-enter-active .cv-slide-panel,
.cv-panel-leave-active .cv-slide-panel { transition: transform 250ms cubic-bezier(.4,0,.2,1); }
.cv-panel-enter-from, .cv-panel-leave-to { opacity: 0; }
.cv-panel-enter-from .cv-slide-panel,
.cv-panel-leave-to .cv-slide-panel { transform: translateX(100%); }

.cv-section-badge {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, rgba(245,158,11,.2), var(--cv-amber));
    color: #000; font-size: 0.72rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

@keyframes spin { to { transform: rotate(360deg); } }

.cv-modal-fade-enter-active, .cv-modal-fade-leave-active { transition: opacity 200ms ease; }
.cv-modal-fade-enter-from, .cv-modal-fade-leave-to { opacity: 0; }
</style>