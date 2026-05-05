<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Weight/Index.vue
//  Index-only pattern — all CRUD via slide-in panels.
//  No navigation away from /weight at any point.
//
//  Panels:
//    A) Single entry  — one animal, date, weight_kg
//    B) Batch by lot  — one lot, N animals in table rows
//    C) Edit          — correct a single existing record
//
//  Design rules followed:
//    § Global cv- classes from app.css — zero scoped overrides
//    § Arabic mode — ALL text in Arabic (labels, placeholders, badges)
//    § v-show="false" + v-model for bilingual counterpart fields
//    § text-start everywhere — never text-left
//    § --cv- tokens only — no hardcoded hex
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'
import axios from 'axios'

const { locale: lang, isRtl, n } = useLocale()
const page = usePage()

// ── Props ──────────────────────────────────────────────────────────
const props = defineProps({
    records:  Object,   // paginated — { data[], links[], meta{} }
    lots:     Array,    // [{ id, name, farm: { name_en, name_ar } }]
    filters:  Object,   // { lot_id, animal_id, date_from, date_to }
})

// ── Permissions ────────────────────────────────────────────────────
const authUser  = computed(() => page.props.auth?.user)
const { can } = usePermissions()
const canCreate = computed(() => can('create weight'))
const canEdit   = computed(() => can('edit weight'))
const canDelete = computed(() => can('delete weight'))

// ── Filter state ───────────────────────────────────────────────────
const filterLot     = ref(props.filters?.lot_id   ?? '')
const filterDateFrom = ref(props.filters?.date_from ?? '')
const filterDateTo   = ref(props.filters?.date_to   ?? '')
const searchTag      = ref('')

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
    searchTag.value = ''
    router.get(route('weights.index'))
}

// ── Client-side tag search ─────────────────────────────────────────
const displayRecords = computed(() => {
    if (!searchTag.value.trim()) return props.records.data
    const q = searchTag.value.toLowerCase()
    return props.records.data.filter(r =>
        r.animal?.tag?.toLowerCase().includes(q) ||
        r.animal?.breed?.toLowerCase().includes(q)
    )
})

// ── KPI summary from current page ─────────────────────────────────
const kpis = computed(() => {
    const data = props.records.data
    if (!data.length) return { count: 0, avgWeight: null, avgAdg: null, lastDate: null }
    const total   = data.reduce((s, r) => s + parseFloat(r.weight_kg), 0)
    const adgRows = data.filter(r => r.adg_kg != null)
    const adgSum  = adgRows.reduce((s, r) => s + parseFloat(r.adg_kg), 0)
    const dates   = data.map(r => r.date).sort()
    return {
        count:     props.records.meta?.total ?? data.length,
        avgWeight: +(total / data.length).toFixed(2),
        avgAdg:    adgRows.length ? +(adgSum / adgRows.length).toFixed(3) : null,
        lastDate:  dates[dates.length - 1] ?? null,
    }
})

// ── ADG badge colour helper ────────────────────────────────────────
function adgClass(val) {
    if (val == null) return 'cv-badge-neutral'
    const v = parseFloat(val)
    if (v >= 1.2)  return 'cv-badge-green'
    if (v >= 0.7)  return 'cv-badge-amber'
    return 'cv-badge-danger'
}

// ── Panel A — Single entry ─────────────────────────────────────────
const panelA      = ref(false)
const panelALots  = computed(() => props.lots)
const panelAAnimals = ref([])

const formA = useForm({
    farm_id:         '',
    lot_id:          '',
    animal_id:       '',
    date:            new Date().toISOString().slice(0, 10),
    weight_kg:       '',
    scale_reference: '',
    session_ref:     '',
    notes:           '',
})

// When lot changes in Panel A → load animals via axios (authenticated AJAX).
// fetch() sends no CSRF/session cookie reliably — Laravel returns 302 to /login.
// axios carries the XSRF-TOKEN + session automatically (configured in bootstrap.js).
watch(() => formA.lot_id, async (lotId) => {
    panelAAnimals.value = []
    formA.animal_id = ''
    if (!lotId) return
    try {
        const { data } = await axios.get(route('weights.lot-animals', lotId))
        panelAAnimals.value = data.animals ?? []
    } catch {
        panelAAnimals.value = []
    }
})

function openPanelA() {
    formA.reset()
    formA.date = new Date().toISOString().slice(0, 10)
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
const batchDate     = ref(new Date().toISOString().slice(0, 10))
const batchSession  = ref('')
const batchScale    = ref('')
const batchNotes    = ref('')
const batchReadings = ref([])   // [{ animal_id, tag, breed, weight_kg }]
const batchErrors   = ref({})
const batchLoading  = ref(false)

// Panel B — same axios fix for batch lot animal loading
watch(batchLotId, async (lotId) => {
    batchAnimals.value  = []
    batchReadings.value = []
    if (!lotId) return
    try {
        const { data } = await axios.get(route('weights.lot-animals', lotId))
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
    batchLotId.value   = ''
    batchDate.value    = new Date().toISOString().slice(0, 10)
    batchSession.value = ''
    batchScale.value   = ''
    batchNotes.value   = ''
    batchReadings.value = []
    batchErrors.value  = {}
    panelB.value = true
}

async function submitBatch() {
    batchErrors.value = {}
    const filled = batchReadings.value.filter(r => r.weight_kg !== '' && r.weight_kg != null)
    if (!filled.length) {
        batchErrors.value.readings = lang.value === 'ar'
            ? 'أدخل وزناً لماشية واحد على الأقل'
            : 'Enter weight for at least one animal'
        return
    }
    batchLoading.value = true
    try {
        await router.post(route('weights.batch'), {
            lot_id:          batchLotId.value,
            date:            batchDate.value,
            session_ref:     batchSession.value,
            scale_reference: batchScale.value,
            notes:           batchNotes.value,
            readings:        filled.map(r => ({ animal_id: r.animal_id, weight_kg: r.weight_kg })),
        }, {
            onSuccess: () => { panelB.value = false },
            onError:   (e) => { batchErrors.value = e },
        })
    } finally {
        batchLoading.value = false
    }
}

// ── Panel C — Edit ─────────────────────────────────────────────────
const panelC       = ref(false)
const editRecord   = ref(null)

const formC = useForm({
    date:            '',
    weight_kg:       '',
    scale_reference: '',
    session_ref:     '',
    notes:           '',
})

function openEdit(record) {
    editRecord.value = record
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

// ── Lot label helper ───────────────────────────────────────────────
function lotLabel(lot) {
    if (!lot) return '—'
    return `${lot.name} — ${n(lot.farm, 'name')}`
}

// ── Pagination ─────────────────────────────────────────────────────
function goToPage(url) {
    if (!url) return
    router.get(url, {}, { preserveState: true })
}

// ── Number formatter ───────────────────────────────────────────────
function fmt(val, decimals = 2) {
    if (val == null) return '—'
    const n = parseFloat(val)
    return isNaN(n) ? '—' : n.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">
    <!-- ══════════════════════════════════════════════════════════
         PAGE HERO
    ══════════════════════════════════════════════════════════ -->
    <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
            <!-- Scale / weight icon -->
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="5" r="3"/>
                <path d="M6.5 8a7 7 0 1 0 11 0"/>
                <line x1="12" y1="2" x2="12" y2="8"/>
                <line x1="4" y1="20" x2="20" y2="20"/>
                <line x1="8" y1="14" x2="8" y2="20"/>
                <line x1="16" y1="14" x2="16" y2="20"/>
            </svg>
            {{ lang === 'ar' ? 'الغذاء والوزن' : 'Feed & Weight' }}
        </div>
        <div class="cv-page-hero-title">
            {{ lang === 'ar' ? 'سجلات الوزن' : 'Weight Records' }}
        </div>
        <div class="cv-page-hero-sub">
            {{ lang === 'ar'
                ? 'تتبع أوزان الماشيةات الفردية ومؤشر معدل النمو اليومي'
                : 'Track individual animal weights and average daily gain metrics' }}
        </div>
        <div class="cv-page-hero-actions" v-if="canCreate">
            <button class="cv-btn cv-btn-ghost" @click="openPanelB">
                <!-- Clipboard list icon -->
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="8" y="2" width="8" height="4" rx="1"/>
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="13" y2="16"/>
                </svg>
                {{ lang === 'ar' ? 'تسجيل دفعي' : 'Batch Entry' }}
            </button>
            <button class="cv-btn cv-btn-amber" @click="openPanelA">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
        
        <!-- Total records -->
        <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">
                {{ lang === 'ar' ? 'إجمالي السجلات' : 'Total Records' }}
            </div>
            <div class="cv-stat-card-value">{{ kpis.count.toLocaleString() }}</div>
        </div>

        <!-- Avg weight -->
        <div class="cv-stat-card cv-stat-card-green">
            <div class="cv-stat-card-label">
                {{ lang === 'ar' ? 'متوسط الوزن (كجم)' : 'Avg Weight (kg)' }}
            </div>
            <div class="cv-stat-card-value">{{ kpis.avgWeight != null ? fmt(kpis.avgWeight) : '—' }}</div>
        </div>

        <!-- Avg ADG -->
        <div class="cv-stat-card cv-stat-card-amber">
            <div class="cv-stat-card-label">
                {{ lang === 'ar' ? 'متوسط النمو اليومي (كجم/يوم)' : 'Avg ADG (kg/day)' }}
            </div>
            <div class="cv-stat-card-value">{{ kpis.avgAdg != null ? fmt(kpis.avgAdg, 3) : '—' }}</div>
        </div>

        <!-- Last weigh date -->
        <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">
                {{ lang === 'ar' ? 'آخر تاريخ وزن' : 'Last Weigh Date' }}
            </div>
            <div class="cv-stat-card-value" style="font-size:1rem">{{ kpis.lastDate ?? '—' }}</div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         FILTERS
    ══════════════════════════════════════════════════════════ -->
    <div class="cv-card" style="margin-bottom:1.25rem;padding:1rem">
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">

            <!-- Search by tag -->
            <div class="cv-field" style="flex:1;min-width:160px">
                <label class="cv-label">
                    {{ lang === 'ar' ? 'بحث بالرقم' : 'Search Tag' }}
                </label>
                <div class="cv-input-icon-wrap">
                    <svg class="cv-input-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input v-model="searchTag" class="cv-input" type="text"
                           :placeholder="lang === 'ar' ? 'رقم الماشية أو السلالة...' : 'Tag or breed…'" />
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

            <!-- Actions -->
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
         RECORDS TABLE
    ══════════════════════════════════════════════════════════ -->
    <div class="cv-card" style="overflow:hidden">

        <!-- Empty state -->
        <div v-if="!displayRecords.length" style="padding:3rem;text-align:center">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"
                 style="color:var(--cv-text-muted);margin:0 auto 1rem" aria-hidden="true">
                <circle cx="12" cy="5" r="3"/>
                <path d="M6.5 8a7 7 0 1 0 11 0"/>
                <line x1="12" y1="2" x2="12" y2="8"/>
                <line x1="4" y1="20" x2="20" y2="20"/>
            </svg>
            <div style="color:var(--cv-text-muted);font-size:0.9rem">
                {{ lang === 'ar' ? 'لا توجد سجلات وزن' : 'No weight records found' }}
            </div>
        </div>

        <!-- Table -->
        <div v-else style="overflow-x:auto">
            <table class="cv-table" style="min-width:820px">
                <thead>
                    <tr>
                        <th class="text-start">{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'الماشية' : 'Animal' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'القطيع' : 'Lot' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'الوزن (كجم)' : 'Weight (kg)' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'معدل النمو اليومي' : 'ADG' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'إجمالي النمو' : 'Total Gain' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'أيام التسمين' : 'Days' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'المُسجِّل' : 'Recorder' }}</th>
                        <th class="text-start">{{ lang === 'ar' ? 'الإجراءات' : 'Actions' }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="record in displayRecords" :key="record.id">

                        <!-- Date -->
                        <td>
                            <span style="color:var(--cv-text-secondary);font-size:0.85rem">
                                {{ record.date }}
                            </span>
                        </td>

                        <!-- Animal -->
                        <td>
                            <div style="display:flex;align-items:center;gap:0.5rem">
                                <div class="cv-avatar cv-avatar-sm" style="background:linear-gradient(135deg,var(--cv-green-dim),var(--cv-green-bright) 80%);font-size:0.6rem;flex-shrink:0">
                                    {{ record.animal?.tag?.slice(0, 3) ?? '?' }}
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:0.85rem;color:var(--cv-text-primary)">
                                        {{ record.animal?.tag ?? '—' }}
                                    </div>
                                    <div style="font-size:0.75rem;color:var(--cv-text-muted)">
                                        {{ record.animal?.breed ?? '' }}
                                        <span v-if="record.animal?.sex" style="margin-inline-start:4px">
                                            · {{ record.animal.sex === 'male'
                                                ? (lang === 'ar' ? 'ذكر' : 'M')
                                                : (lang === 'ar' ? 'أنثى' : 'F') }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- Lot -->
                        <td>
                            <span style="font-size:0.83rem;color:var(--cv-text-secondary)">
                                {{ record.lot?.name ?? '—' }}
                            </span>
                        </td>

                        <!-- Weight -->
                        <td>
                            <span style="color:var(--cv-blue-text);font-weight:700;font-size:0.95rem">
                                {{ fmt(record.weight_kg) }}
                            </span>
                        </td>

                        <!-- ADG -->
                        <td>
                            <span v-if="record.adg_kg != null" :class="['cv-badge', adgClass(record.adg_kg)]">
                                {{ fmt(record.adg_kg, 3) }} {{ lang === 'ar' ? 'كجم/يوم' : 'kg/d' }}
                            </span>
                            <span v-else class="cv-badge cv-badge-neutral">—</span>
                        </td>

                        <!-- Total gain -->
                        <td>
                            <span v-if="record.total_gain_kg != null"
                                  style="color:var(--cv-blue-text);font-size:0.85rem">
                                +{{ fmt(record.total_gain_kg) }} {{ lang === 'ar' ? 'كجم' : 'kg' }}
                            </span>
                            <span v-else style="color:var(--cv-text-muted)">—</span>
                        </td>

                        <!-- Days on feed -->
                        <td>
                            <span v-if="record.days_on_feed != null"
                                  style="color:var(--cv-text-secondary);font-size:0.85rem">
                                {{ record.days_on_feed }}
                                {{ lang === 'ar' ? 'يوم' : 'd' }}
                            </span>
                            <span v-else style="color:var(--cv-text-muted)">—</span>
                        </td>

                        <!-- Recorder -->
                        <td>
                            <span style="font-size:0.8rem;color:var(--cv-text-muted)">
                                {{ record.recorder ?? '—' }}
                            </span>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div style="display:flex;gap:0.35rem">
                                <button v-if="canEdit"
                                        class="cv-action-btn cv-action-btn-blue"
                                        :title="lang === 'ar' ? 'تعديل' : 'Edit'"
                                        @click="openEdit(record)">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </button>
                                <button v-if="canDelete"
                                        class="cv-action-btn cv-action-btn-danger"
                                        :title="lang === 'ar' ? 'حذف' : 'Delete'"
                                        @click="confirmDelete(record)">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-1 14H6L5 6"/>
                                        <path d="M10 11v6"/><path d="M14 11v6"/>
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
        <!-- Pagination -->
        <div v-if="records.links?.length > 3"
             style="display:flex;justify-content:center;gap:0.35rem;padding:1rem;flex-wrap:wrap">
            <button v-for="link in records.links" :key="link.label"
                    :disabled="!link.url || link.active"
                    :class="['cv-btn', link.active ? 'cv-btn-amber' : 'cv-btn-ghost']"
                    style="min-width:2.25rem;padding:0.3rem 0.6rem;font-size:0.8rem"
                    v-html="link.label"
                    @click="goToPage(link.url)" />
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         PANEL A — SINGLE WEIGHT ENTRY
    ══════════════════════════════════════════════════════════ -->
    <Transition name="cv-panel">
        <div v-if="panelA" class="cv-modal-backdrop" @click.self="panelA = false">
            <aside class="cv-slide-panel" :dir="isRtl ? 'rtl' : 'ltr'"
                   style="width:min(480px,100vw)">

                <!-- Header -->
                <div class="cv-modal-header">
                    <div>
                        <div style="font-size:0.7rem;color:var(--cv-amber);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">
                            {{ lang === 'ar' ? 'سجل الوزن' : 'Weight Record' }}
                        </div>
                        <h2 style="margin:0;font-size:1.05rem;font-weight:700;color:var(--cv-text-primary)">
                            {{ lang === 'ar' ? 'تسجيل وزن ماشية' : 'Record Single Weight' }}
                        </h2>
                    </div>
                    <button class="cv-action-btn" @click="panelA = false" :title="lang === 'ar' ? 'إغلاق' : 'Close'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;overflow-y:auto">

                    <!-- Section 1 — Location -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">1</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'الموقع والماشية' : 'Location & Animal' }}
                            </span>
                        </div>

                        <!-- Lot -->
                        <div class="cv-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'القطيع *' : 'Lot *' }}
                            </label>
                            <select v-model="formA.lot_id" class="cv-select"
                                    :class="{ 'cv-input-error': formA.errors.lot_id }">
                                <option value="">{{ lang === 'ar' ? '— اختر القطيع —' : '— Select lot —' }}</option>
                                <option v-for="lot in panelALots" :key="lot.id" :value="lot.id">
                                    {{ lot.name }} — {{ n(lot.farm, 'name') }}
                                </option>
                            </select>
                            <div v-if="formA.errors.lot_id" class="cv-field-error">{{ formA.errors.lot_id }}</div>
                        </div>

                        <!-- Animal -->
                        <div class="cv-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'الماشية *' : 'Animal *' }}
                            </label>
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

                    <!-- Section 2 — Weight data -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">2</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'بيانات الوزن' : 'Weight Data' }}
                            </span>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <!-- Date -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'التاريخ *' : 'Date *' }}</label>
                                <input v-model="formA.date" class="cv-input" type="date"
                                       :class="{ 'cv-input-error': formA.errors.date }" />
                                <div v-if="formA.errors.date" class="cv-field-error">{{ formA.errors.date }}</div>
                            </div>

                            <!-- Weight -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'الوزن (كجم) *' : 'Weight (kg) *' }}</label>
                                <input v-model="formA.weight_kg" class="cv-input" type="number"
                                       min="0" max="2000" step="0.1"
                                       :placeholder="lang === 'ar' ? 'مثال: 350.5' : 'e.g. 350.5'"
                                       :class="{ 'cv-input-error': formA.errors.weight_kg }" />
                                <div v-if="formA.errors.weight_kg" class="cv-field-error">{{ formA.errors.weight_kg }}</div>
                            </div>
                        </div>

                        <!-- Scale reference -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'مرجع الميزان' : 'Scale Reference' }}</label>
                            <input v-model="formA.scale_reference" class="cv-input" type="text"
                                   :placeholder="lang === 'ar' ? 'رقم الجهاز أو الموقع...' : 'Scale ID or location…'" />
                        </div>

                        <!-- Session ref -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'رقم جلسة الوزن' : 'Session Ref' }}</label>
                            <input v-model="formA.session_ref" class="cv-input" type="text"
                                   :placeholder="lang === 'ar' ? 'معرّف الجلسة (اختياري)...' : 'Session identifier (optional)…'" />
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

                <!-- Footer -->
                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="panelA = false">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <button class="cv-btn cv-btn-amber" :disabled="formA.processing" @click="submitA">
                        <svg v-if="formA.processing" width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="animation:spin 1s linear infinite">
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
            <aside class="cv-slide-panel" :dir="isRtl ? 'rtl' : 'ltr'"
                   style="width:min(700px,100vw)">

                <!-- Header -->
                <div class="cv-modal-header">
                    <div>
                        <div style="font-size:0.7rem;color:var(--cv-amber);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">
                            {{ lang === 'ar' ? 'سجل الوزن' : 'Weight Record' }}
                        </div>
                        <h2 style="margin:0;font-size:1.05rem;font-weight:700;color:var(--cv-text-primary)">
                            {{ lang === 'ar' ? 'تسجيل دفعي للقطيع' : 'Batch Lot Weighing' }}
                        </h2>
                    </div>
                    <button class="cv-action-btn" @click="panelB = false" :title="lang === 'ar' ? 'إغلاق' : 'Close'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;overflow-y:auto">

                    <!-- Section 1 — Session header -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">1</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'بيانات الجلسة' : 'Session Details' }}
                            </span>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <!-- Lot -->
                            <div class="cv-field" style="grid-column:1/-1">
                                <label class="cv-label">{{ lang === 'ar' ? 'القطيع *' : 'Lot *' }}</label>
                                <select v-model="batchLotId" class="cv-select">
                                    <option value="">{{ lang === 'ar' ? '— اختر القطيع —' : '— Select lot —' }}</option>
                                    <option v-for="lot in lots" :key="lot.id" :value="lot.id">
                                        {{ lot.name }} — {{ n(lot.farm, 'name') }}
                                    </option>
                                </select>
                            </div>

                            <!-- Date -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'تاريخ الوزن *' : 'Weigh Date *' }}</label>
                                <input v-model="batchDate" class="cv-input" type="date" />
                            </div>

                            <!-- Scale ref -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'مرجع الميزان' : 'Scale Reference' }}</label>
                                <input v-model="batchScale" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'رقم الجهاز...' : 'Scale ID…'" />
                            </div>

                            <!-- Session ref -->
                            <div class="cv-field" style="grid-column:1/-1">
                                <label class="cv-label">{{ lang === 'ar' ? 'رقم الجلسة' : 'Session Ref' }}</label>
                                <input v-model="batchSession" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'معرّف الجلسة...' : 'Session identifier…'" />
                            </div>
                        </div>
                    </div>

                    <!-- Section 2 — Animals table -->
                    <div class="cv-card">
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem">
                            <div class="cv-section-badge">2</div>
                            <span style="font-weight:600;font-size:0.88rem;color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? 'أوزان الماشيةات' : 'Animal Weights' }}
                            </span>
                            <span v-if="batchReadings.length" class="cv-badge cv-badge-info" style="margin-inline-start:auto">
                                {{ batchReadings.length }}
                                {{ lang === 'ar' ? 'ماشية' : 'animals' }}
                            </span>
                        </div>

                        <!-- Placeholder if no lot selected -->
                        <div v-if="!batchLotId"
                             style="padding:1.5rem;text-align:center;color:var(--cv-text-muted);font-size:0.85rem">
                            {{ lang === 'ar' ? 'اختر القطيع أولاً لعرض الماشيةات' : 'Select a lot above to load animals' }}
                        </div>

                        <!-- Loading -->
                        <div v-else-if="batchLotId && !batchReadings.length && batchAnimals.length === 0"
                             style="padding:1.5rem;text-align:center;color:var(--cv-text-muted);font-size:0.85rem">
                            {{ lang === 'ar' ? 'لا توجد ماشيةات نشطة في هذا القطيع' : 'No active animals in this lot' }}
                        </div>

                        <!-- Readings table -->
                        <div v-else style="overflow-x:auto">
                            <table class="cv-table">
                                <thead>
                                    <tr>
                                        <th class="text-start">{{ lang === 'ar' ? 'الرقم' : 'Tag' }}</th>
                                        <th class="text-start">{{ lang === 'ar' ? 'السلالة' : 'Breed' }}</th>
                                        <th class="text-start" style="width:160px">
                                            {{ lang === 'ar' ? 'الوزن (كجم)' : 'Weight (kg)' }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in batchReadings" :key="row.animal_id">
                                        <td>
                                            <span style="font-weight:600;font-size:0.85rem;color:var(--cv-text-primary)">
                                                {{ row.tag }}
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-size:0.8rem;color:var(--cv-text-muted)">
                                                {{ row.breed || '—' }}
                                            </span>
                                        </td>
                                        <td>
                                            <input v-model="row.weight_kg" class="cv-input" type="number"
                                                   min="0" max="2000" step="0.1"
                                                   :placeholder="lang === 'ar' ? 'اتركه فارغاً للتخطي' : 'Leave blank to skip'"
                                                   style="font-size:0.85rem;padding:0.3rem 0.6rem" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="batchErrors.readings"
                             class="cv-field-error" style="margin-top:0.5rem">
                            {{ batchErrors.readings }}
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
                                      :placeholder="lang === 'ar' ? 'ملاحظات عامة على جلسة الوزن...' : 'General session notes…'" />
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="cv-modal-footer">
                    <button class="cv-btn cv-btn-ghost" @click="panelB = false">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <button class="cv-btn cv-btn-amber"
                            :disabled="batchLoading || !batchLotId || !batchDate"
                            @click="submitBatch">
                        <svg v-if="batchLoading" width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="animation:spin 1s linear infinite">
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
            <aside class="cv-slide-panel" :dir="isRtl ? 'rtl' : 'ltr'"
                   style="width:min(440px,100vw)">

                <!-- Header -->
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
                    <button class="cv-action-btn" @click="panelC = false" :title="lang === 'ar' ? 'إغلاق' : 'Close'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="cv-modal-body" style="display:flex;flex-direction:column;gap:1rem;overflow-y:auto">
                    <div class="cv-card">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <!-- Date -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'التاريخ *' : 'Date *' }}</label>
                                <input v-model="formC.date" class="cv-input" type="date"
                                       :class="{ 'cv-input-error': formC.errors.date }" />
                                <div v-if="formC.errors.date" class="cv-field-error">{{ formC.errors.date }}</div>
                            </div>

                            <!-- Weight -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'الوزن (كجم) *' : 'Weight (kg) *' }}</label>
                                <input v-model="formC.weight_kg" class="cv-input" type="number"
                                       min="0" max="2000" step="0.1"
                                       :class="{ 'cv-input-error': formC.errors.weight_kg }" />
                                <div v-if="formC.errors.weight_kg" class="cv-field-error">{{ formC.errors.weight_kg }}</div>
                            </div>

                            <!-- Scale ref -->
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'مرجع الميزان' : 'Scale Ref' }}</label>
                                <input v-model="formC.scale_reference" class="cv-input" type="text"
                                       :placeholder="lang === 'ar' ? 'رقم الجهاز...' : 'Scale ID…'" />
                            </div>

                            <!-- Session ref -->
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

                <!-- Footer -->
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

    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Slide panel ─────────────────────────────────────────────── */
/* Only genuinely new elements not in app.css go here */
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

/* ── Panel transition ──────────────────────────────────────── */
.cv-panel-enter-active,
.cv-panel-leave-active {
    transition: opacity 200ms ease;
}
.cv-panel-enter-active .cv-slide-panel,
.cv-panel-leave-active .cv-slide-panel {
    transition: transform 250ms cubic-bezier(.4,0,.2,1);
}
.cv-panel-enter-from,
.cv-panel-leave-to {
    opacity: 0;
}
.cv-panel-enter-from .cv-slide-panel {
    transform: translateX(100%);
}
.cv-panel-leave-to .cv-slide-panel {
    transform: translateX(100%);
}

/* ── Section badge ───────────────────────────────────────── */
.cv-section-badge {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--cv-amber-dim, rgba(245,158,11,.2)), var(--cv-amber));
    color: #000;
    font-size: 0.72rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* ── Spin animation for loading ──────────────────────────── */
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ── Modal fade transition ───────────────────────────────── */
.cv-modal-fade-enter-active,
.cv-modal-fade-leave-active {
    transition: opacity 200ms ease;
}
.cv-modal-fade-enter-from,
.cv-modal-fade-leave-to {
    opacity: 0;
}
</style>