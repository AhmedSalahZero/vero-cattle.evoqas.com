<script setup>
/**
 * Feed/Consumption/Index.vue — VERO Cattle Management
 * Daily feed consumption log — index-only, paginated table.
 * Batch entry panel: multiple inventory feed items for one lot in one session.
 * Edit panel: single record correction.
 *
 * 📌 METHODOLOGY REFERENCE: FeedConsumptionBatchStore (#17)
 * 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §5
 * 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
 *
 * SESSION 50 REWORK — Migration 059
 * Each batch row now binds directly to an inventory item (category=feed)
 * at a specific warehouse location. unit_cost is auto-filled from the
 * location stock row WAC. The old feed_item_id / ration_id architecture
 * is retired — legacy records still display via feed_item fallback.
 *
 * SESSION 53 CHANGES — Migration 060
 * head_count user input replaced with explicit animal multi-selector.
 * animal_ids[] submitted with every batch session. head_count derived
 * server-side as count(animal_ids). Pivot table feed_consumption_animals
 * enables per-animal COGS accuracy at sale time.
 */
import { ref, computed, watch } from 'vue'
import { useForm, router, Link, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    records:            { type: Object, default: () => ({ data: [], links: [], last_page: 1 }) },
    farms:              { type: Array,  default: () => [] },
    lots:               { type: Array,  default: () => [] },
    inventoryFeedItems: { type: Array,  default: () => [] },
    lotsAnimals:        { type: Object, default: () => ({}) },
    filters:            { type: Object, default: () => ({}) },
})

const can = computed(() => ({
    create: usePage().props.auth?.permissions?.includes('create feed') ?? true,
    edit:   usePage().props.auth?.permissions?.includes('edit feed')   ?? true,
    delete: usePage().props.auth?.permissions?.includes('delete feed') ?? true,
}))

// ── Filters ───────────────────────────────────────────────────────
const filtersOpen = ref(false)
const filterState = ref({
    farm_id:   props.filters?.farm_id   ?? '',
    lot_id:    props.filters?.lot_id    ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to:   props.filters?.date_to   ?? '',
})
const filterLots = computed(() =>
    filterState.value.farm_id
        ? props.lots.filter(l => String(l.farm_id) === String(filterState.value.farm_id))
        : props.lots
)
watch(() => filterState.value.farm_id, () => { filterState.value.lot_id = '' })
function applyFilters() {
    router.get(route('feed.consumption.index'), filterState.value, { preserveState: true, replace: true })
}
function resetFilters() {
    filterState.value = { farm_id: '', lot_id: '', date_from: '', date_to: '' }
    applyFilters()
}

// ── Batch form — declared BEFORE any watch that references it ─────
const batchForm = useForm({
    farm_id:    '',
    lot_id:     '',
    date:       '',
    animal_ids: [],
    notes:      '',
    rows:       [{ inventory_item_id: '', location_id: '', qty_kg: '', unit_cost: '' }],
})

// ── Animal loader for the batch panel ────────────────────────────
// 📌 METHODOLOGY REFERENCE: FeedConsumptionAnimalSelection
// Animals are passed as a prop keyed by lot_id — no API fetch needed.
const lotAnimals     = ref([])
const loadingAnimals = ref(false)

function loadLotAnimals(lotId) {
    if (!lotId) { lotAnimals.value = []; return }
    lotAnimals.value = props.lotsAnimals[lotId] ?? []
}

function toggleAnimal(id) {
    const idx = batchForm.animal_ids.indexOf(id)
    if (idx === -1) batchForm.animal_ids.push(id)
    else batchForm.animal_ids.splice(idx, 1)
}

function selectAllAnimals() { batchForm.animal_ids = lotAnimals.value.map(a => a.id) }
function clearAllAnimals()  { batchForm.animal_ids = [] }
function onMultiSelectChange(event) {
    batchForm.animal_ids = Array.from(event.target.selectedOptions).map(o => Number(o.value))
}

// ── Batch panel ───────────────────────────────────────────────────
const batchPanelOpen = ref(false)
const batchFarmId    = ref('')
const batchLots      = computed(() =>
    batchFarmId.value
        ? props.lots.filter(l => String(l.farm_id) === String(batchFarmId.value))
        : props.lots
)

watch(() => batchForm.farm_id, (val) => {
    batchFarmId.value = String(val)
    batchForm.lot_id  = ''
})
watch(() => batchForm.lot_id, (val) => {
    batchForm.animal_ids = []
    lotAnimals.value     = []
    if (val) loadLotAnimals(val)
})

function openBatch() {
    batchForm.reset()
    batchForm.clearErrors()
    batchFarmId.value    = ''
    batchForm.date       = new Date().toISOString().split('T')[0]
    batchForm.rows       = [{ inventory_item_id: '', location_id: '', qty_kg: '', unit_cost: '' }]
    batchForm.animal_ids = []
    lotAnimals.value     = []
    batchPanelOpen.value = true
}
function closeBatch() {
    batchPanelOpen.value = false
    batchForm.reset()
    batchForm.clearErrors()
    lotAnimals.value = []
}
function addBatchRow()       { batchForm.rows.push({ inventory_item_id: '', location_id: '', qty_kg: '', unit_cost: '' }) }
function removeBatchRow(idx) { if (batchForm.rows.length > 1) batchForm.rows.splice(idx, 1) }

// 📌 LivestockOperationalCostFlow (#43) §5 — WAC from location stock row
// Filters locations by selected farm: central locations (farm_id=null) always show
function itemLocations(idx) {
    const row  = batchForm.rows[idx]
    if (!row.inventory_item_id) return []
    const item = props.inventoryFeedItems.find(f => String(f.id) === String(row.inventory_item_id))
    if (!item || !item.location_stocks) return []
    return item.location_stocks.filter(l =>
        l.qty_on_hand > 0 &&
        (l.farm_id === null || l.farm_id === undefined || String(l.farm_id) === String(batchFarmId.value))
    )
}

function onInventoryItemChange(idx) {
    const row = batchForm.rows[idx]
    row.location_id = ''
    row.unit_cost   = ''
    const locs = itemLocations(idx)
    if (locs.length === 1) {
        row.location_id = locs[0].location_id
        row.unit_cost   = locs[0].unit_cost  // WAC from stock row
    }
}

function onLocationChange(idx) {
    const row  = batchForm.rows[idx]
    const locs = itemLocations(idx)
    const loc  = locs.find(l => String(l.location_id) === String(row.location_id))
    if (loc) row.unit_cost = loc.unit_cost  // WAC
}

function locLabelShort(l) {
    if (!l) return '—'
    const name = lang.value === 'ar' ? (l.location_name_ar || l.location_name_en) : (l.location_name_en || l.location_name_ar)
    return (l.farm_id === null || l.farm_id === undefined) ? `🏢 ${name}` : `🏠 ${name}`
}

function submitBatch() { batchForm.post(route('feed.consumption.batch'), { onSuccess: closeBatch }) }

// 📌 METHODOLOGY REFERENCE: FeedConsumptionBatchStore — live total
const batchTotalCost = computed(() =>
    batchForm.rows.reduce((sum, row) =>
        sum + (parseFloat(row.qty_kg) || 0) * (parseFloat(row.unit_cost) || 0), 0)
)

// ── Edit form — declared BEFORE openEdit uses it ──────────────────
const editForm = useForm({
    farm_id: '', lot_id: '', inventory_item_id: '', location_id: '',
    date: '', qty_kg: '', unit_cost: '', total_cost: '', notes: '',
    animal_ids: [],   // ← ADD THIS
})

// ── Edit panel ────────────────────────────────────────────────────
const editPanelOpen = ref(false)
const editTarget    = ref(null)

function openEdit(rec) {
    editTarget.value           = rec
    editForm.farm_id           = rec.farm_id
    editForm.lot_id            = rec.lot_id
    editForm.inventory_item_id = rec.inventory_item_id ?? ''
    editForm.location_id       = rec.location_id       ?? ''
    editForm.date              = rec.date
    editForm.qty_kg            = rec.qty_kg
    editForm.unit_cost         = rec.unit_cost
    editForm.total_cost        = rec.total_cost
    editForm.notes             = rec.notes ?? ''
    editForm.animal_ids        = rec.animal_ids ?? []   // ← ADD THIS LINE
    loadLotAnimals(rec.lot_id)                          // ← ADD THIS LINE
    editForm.clearErrors()
    editPanelOpen.value = true
}
function closeEdit()   { editPanelOpen.value = false; editTarget.value = null; editForm.reset(); editForm.clearErrors() }
function submitEdit()  { editForm.put(route('feed.consumption.update', editTarget.value.id), { onSuccess: closeEdit }) }

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal  = ref(false)
const deleteTarget = ref(null)
function confirmDelete(rec) { deleteTarget.value = rec; deleteModal.value = true }
function doDelete() {
    router.delete(route('feed.consumption.destroy', deleteTarget.value.id), {
        onSuccess: () => { deleteModal.value = false; deleteTarget.value = null },
    })
}

// ── Helpers ───────────────────────────────────────────────────────
function fmtNum(val, d = 2) {
    if (val == null || val === '') return '—'
    return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US',
        { minimumFractionDigits: d, maximumFractionDigits: d })
}
function varianceClass(val) {
    if (val == null) return ''
    const v = parseFloat(val)
    return v > 0 ? 'var-over' : v < 0 ? 'var-under' : 'var-exact'
}
function varianceSign(val) {
    if (val == null) return '—'
    const v = parseFloat(val)
    return v > 0 ? `+${fmtNum(val, 1)}` : fmtNum(val, 1)
}

// ── i18n ──────────────────────────────────────────────────────────
const t = computed(() => lang.value === 'ar' ? {
    eyebrow:'الغذاء والوزن', title:'استهلاك العلف', sub:'سجل التغذية اليومي لكل مجموعة',
    addBatch:'تسجيل وجبة', filtersBtn:'تصفية', reset:'إعادة تعيين', apply:'تطبيق',
    allFarms:'كل المزارع', allLots:'كل المجموعات',
    farmLabel:'المزرعة', lotLabel:'المجموعة', dateFrom:'من تاريخ', dateTo:'إلى تاريخ',
    date:'التاريخ', lot:'المجموعة', feedItem:'العلف / الموقع',
    headCount:'عدد الرؤوس', qtyKg:'الكمية (كجم)', unitCost:'سعر الوحدة',
    totalCost:'التكلفة الإجمالية', variance:'الفارق', actions:'إجراءات',
    noRecords:'لا توجد سجلات استهلاك', noRecordsSub:'ابدأ بتسجيل وجبة علف يومية',
    edit:'تعديل', delete:'حذف',
    deleteTitle:'حذف سجل الاستهلاك', deleteMsg:'هل أنت متأكد؟ لا يمكن التراجع.',
    cancel:'إلغاء', confirm:'حذف', egp:'ج.م',
    batchTitle:'تسجيل وجبة علف', batchSub:'سجل جميع المواد العلفية لمجموعة واحدة في جلسة واحدة',
    s1Title:'بيانات الجلسة', s1Sub:'المزرعة والمجموعة والتاريخ والحيوانات',
    farmPh:'— اختر المزرعة —', lotPh:'— اختر المجموعة —',
    dateLabel:'التاريخ *',
    notesLabel:'ملاحظات', notesPh:'أي ملاحظات...',
    s2Title:'المواد العلفية', s2Sub:'الكمية الفعلية المُعطاة لكل مادة علفية',
    feedItemCol:'صنف العلف (المخزون)', locationCol:'الموقع', qtyCol:'الكمية (كجم)', costCol:'سعر الوحدة (WAC)',
    addRow:'+ إضافة صنف',
    selFeedItem:'— اختر الصنف —', selLocation:'— اختر الموقع —',
    totalLabel:'إجمالي تكلفة الوجبة:', saving:'جارٍ الحفظ...',
    saveBatch:'حفظ الوجبة', cancel2:'إلغاء',
    editTitle:'تعديل السجل', saveEdit:'حفظ التعديلات',
    unitCost:'سعر الوحدة', totalCost:'التكلفة الإجمالية',
} : {
    eyebrow:'Feed & Weight', title:'Feed Consumption', sub:'Daily feed log per lot',
    addBatch:'Record Feed Session', filtersBtn:'Filters', reset:'Reset', apply:'Apply',
    allFarms:'All Farms', allLots:'All Lots',
    farmLabel:'Farm', lotLabel:'Lot', dateFrom:'Date From', dateTo:'Date To',
    date:'Date', lot:'Lot', feedItem:'Feed Item / Location',
    headCount:'Head Count', qtyKg:'Qty (kg)', unitCost:'Unit Cost',
    totalCost:'Total Cost', variance:'Variance', actions:'Actions',
    noRecords:'No consumption records found', noRecordsSub:'Start by recording a daily feed session',
    edit:'Edit', delete:'Delete',
    deleteTitle:'Delete Consumption Record', deleteMsg:'Are you sure? This cannot be undone.',
    cancel:'Cancel', confirm:'Delete', egp:'EGP',
    batchTitle:'Record Feed Session', batchSub:'Log all feed materials for one lot in a single submission',
    s1Title:'Session Details', s1Sub:'Farm, lot, date and animals fed',
    farmPh:'— Select Farm —', lotPh:'— Select Lot —',
    dateLabel:'Date *',
    notesLabel:'Notes', notesPh:'Any additional notes...',
    s2Title:'Feed Materials', s2Sub:'Actual quantities given per feed item',
    feedItemCol:'Feed Item (Inventory)', locationCol:'Location', qtyCol:'Qty (kg)', costCol:'Unit Cost (WAC)',
    addRow:'+ Add Feed Item',
    selFeedItem:'— Select Feed Item —', selLocation:'— Select Location —',
    totalLabel:'Session Total Cost:', saving:'Saving...',
    saveBatch:'Save Session', cancel2:'Cancel',
    editTitle:'Edit Record', saveEdit:'Save Changes',
    unitCost:'Unit Cost', totalCost:'Total Cost',
})
</script>

<template>
   
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- Hero -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ t.title }}</div>
                <div class="cv-page-hero-sub">{{ t.sub }}</div>
                <div class="cv-page-hero-actions">
                    <button v-if="can.create" class="cv-btn cv-btn-amber" @click="openBatch">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path d="M12 5v14M5 12h14"/></svg>
                        {{ t.addBatch }}
                    </button>
                    <button class="cv-btn cv-btn-ghost" @click="filtersOpen = !filtersOpen">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                        {{ t.filtersBtn }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">
                    {{ $page.props.flash.success }}
                </div>

                <!-- Filters -->
                <Transition name="slide-down">
                    <div v-if="filtersOpen" class="cv-card mb-6 p-4">
                        <div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
                            <div>
                                <label class="cv-label">{{ t.farmLabel }}</label>
                                <select v-model="filterState.farm_id" class="cv-select">
                                    <option value="">{{ t.allFarms }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="cv-label">{{ t.lotLabel }}</label>
                                <select v-model="filterState.lot_id" class="cv-select">
                                    <option value="">{{ t.allLots }}</option>
                                    <option v-for="l in filterLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="cv-label">{{ t.dateFrom }}</label>
                                <input v-model="filterState.date_from" type="date" class="cv-input"/>
                            </div>
                            <div>
                                <label class="cv-label">{{ t.dateTo }}</label>
                                <input v-model="filterState.date_to" type="date" class="cv-input"/>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-4">
                            <button class="cv-btn cv-btn-amber" @click="applyFilters">{{ t.apply }}</button>
                            <button class="cv-btn cv-btn-ghost" @click="resetFilters">{{ t.reset }}</button>
                        </div>
                    </div>
                </Transition>

                <!-- Table -->
                <div v-if="records.data.length" class="cv-card" style="overflow-x:auto">
                    <table class="cv-table">
                        <thead>
                            <tr>
                                <th>{{ t.date }}</th>
                                <th>{{ t.lot }}</th>
                                <th>{{ t.feedItem }}</th>
                                <th class="text-end">{{ t.headCount }}</th>
                                <th class="text-end">{{ t.qtyKg }}</th>
                                <th class="text-end">{{ t.totalCost }}</th>
                                <th class="text-end">{{ t.variance }}</th>
                                <th>{{ t.actions }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rec in records.data" :key="rec.id">
                                <td style="white-space:nowrap;color:var(--cv-text-muted)">{{ rec.date }}</td>
                                <td>
                                    <div style="font-weight:600;color:var(--cv-text-primary)">{{ rec.lot?.name ?? '—' }}</div>
                                    <div style="font-size:0.72rem;color:var(--cv-text-muted)">{{ rec.farm ? n(rec.farm,'name') : '' }}</div>
                                </td>
                                <td>
                                    <!-- New: inventory_item; Legacy fallback: feed_item -->
                                    <div style="font-weight:500;color:var(--cv-text-primary)">
                                        <template v-if="rec.inventory_item">
                                            {{ lang==='ar'?(rec.inventory_item.name_ar||rec.inventory_item.name_en):(rec.inventory_item.name_en||rec.inventory_item.name_ar) }}
                                        </template>
                                        <template v-else-if="rec.feed_item">
                                            {{ lang==='ar'?(rec.feed_item.name_ar||rec.feed_item.name_en):(rec.feed_item.name_en||rec.feed_item.name_ar) }}
                                        </template>
                                        <template v-else>—</template>
                                    </div>
                                    <!-- Location (new records only) -->
                                    <div v-if="rec.location" style="font-size:0.72rem;color:var(--cv-text-muted)">
                                        {{ lang==='ar'?(rec.location.name_ar||rec.location.name_en):(rec.location.name_en||rec.location.name_ar) }}
                                    </div>
                                    <div v-else-if="rec.feed_item?.unit" style="font-size:0.72rem;color:var(--cv-text-muted)">{{ rec.feed_item.unit }}</div>
                                </td>
                                <td class="text-end" style="color:var(--cv-text-muted)">{{ rec.head_count }}</td>
                                <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">{{ fmtNum(rec.qty_kg,1) }}</td>
                                <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">
                                    {{ fmtNum(rec.total_cost) }}
                                    <span style="font-size:0.7rem;color:var(--cv-text-muted);font-weight:400"> {{ t.egp }}</span>
                                </td>
                                <td class="text-end">
                                    <span v-if="rec.variance_kg!=null" :class="['variance-chip', varianceClass(rec.variance_kg)]">{{ varianceSign(rec.variance_kg) }}</span>
                                    <span v-else style="color:var(--cv-text-muted)">—</span>
                                </td>
                                <td>
                                    <div class="flex gap-1">
                                        <button v-if="can.edit" class="cv-action-btn cv-action-btn-amber" @click="openEdit(rec)" :title="t.edit">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button v-if="can.delete" class="cv-action-btn cv-action-btn-danger" @click="confirmDelete(rec)" :title="t.delete">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div v-else class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" width="48" height="48">
                            <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                            <line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
                        </svg>
                    </div>
                    <div class="cv-empty-title">{{ t.noRecords }}</div>
                    <div class="cv-empty-sub">{{ t.noRecordsSub }}</div>
                    <button v-if="can.create" class="cv-btn cv-btn-amber mt-4" @click="openBatch">{{ t.addBatch }}</button>
                </div>

                <!-- Pagination -->
                <div v-if="records.last_page > 1" class="cv-pagination mt-6">
                    <Link v-for="link in records.links" :key="link.label" :href="link.url??'#'"
                          class="cv-page-link" :class="{active:link.active,disabled:!link.url}"
                          v-html="link.label" preserve-scroll/>
                </div>

            </div>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- BATCH PANEL                                         -->
            <!-- ═══════════════════════════════════════════════════ -->
            <Teleport to="body">
                <Transition name="backdrop-fade">
                    <div v-if="batchPanelOpen" class="panel-backdrop" @click="closeBatch"/>
                </Transition>
                <Transition name="panel-slide">
                    <div v-if="batchPanelOpen" class="slide-panel panel-wide" :dir="isRtl?'rtl':'ltr'" role="dialog">
                        <div class="panel-header">
                            <div class="flex items-center gap-3">
                                <div class="panel-icon" style="background:linear-gradient(135deg,var(--cv-green-bright),#2e9048)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.7" width="16" height="16">
                                        <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                                        <line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
                                    </svg>
                                </div>
                                <div>
                                    <div class="panel-title">{{ t.batchTitle }}</div>
                                    <div class="panel-subtitle">{{ t.batchSub }}</div>
                                </div>
                            </div>
                            <button @click="closeBatch" class="panel-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div class="panel-body">
                            <form @submit.prevent="submitBatch">

                                <!-- ── Section 1: Session Details ── -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head mb-3">
                                        <div class="sec-badge sec-1">1</div>
                                        <div>
                                            <div style="font-weight:600;font-size:.875rem;color:var(--cv-text-primary)">{{ t.s1Title }}</div>
                                            <div style="font-size:.75rem;color:var(--cv-text-muted)">{{ t.s1Sub }}</div>
                                        </div>
                                    </div>

                                    <!-- Row 1: Farm + Lot -->
                                    <div class="grid gap-3" style="grid-template-columns:1fr 1fr">
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.farmLabel }} *</label>
                                            <select v-model="batchForm.farm_id" class="cv-select" :class="{'cv-input-error':batchForm.errors.farm_id}">
                                                <option value="">{{ t.farmPh }}</option>
                                                <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                                            </select>
                                            <p v-if="batchForm.errors.farm_id" class="cv-field-error">{{ batchForm.errors.farm_id }}</p>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.lotLabel }} *</label>
                                            <select v-model="batchForm.lot_id" class="cv-select" :class="{'cv-input-error':batchForm.errors.lot_id}" :disabled="!batchForm.farm_id">
                                                <option value="">{{ t.lotPh }}</option>
                                                <option v-for="l in batchLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                            </select>
                                            <p v-if="batchForm.errors.lot_id" class="cv-field-error">{{ batchForm.errors.lot_id }}</p>
                                        </div>
                                    </div>

                                    <!-- Row 2: Date (full width) -->
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">{{ t.dateLabel }}</label>
                                        <input v-model="batchForm.date" type="date" class="cv-input" :class="{'cv-input-error':batchForm.errors.date}" style="max-width:220px"/>
                                        <p v-if="batchForm.errors.date" class="cv-field-error">{{ batchForm.errors.date }}</p>
                                    </div>

                                    <!-- Row 3: Animal selector (full width) -->
                                    <!-- 📌 FeedConsumptionAnimalSelection — replaces head_count input -->
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">
                                            {{ lang==='ar' ? 'الحيوانات المُغذَّاة *' : 'Animals Fed *' }}
                                            <span v-if="batchForm.animal_ids.length" style="margin-inline-start:.5rem;font-weight:700;color:var(--cv-green-bright)">
                                                ({{ batchForm.animal_ids.length }} {{ lang==='ar'?'محدد':'selected' }})
                                            </span>
                                        </label>

                                        <!-- No lot selected yet -->
                                        <div v-if="!batchForm.lot_id" style="font-size:.78rem;color:var(--cv-text-muted);padding:.4rem 0">
                                            {{ lang==='ar' ? '— اختر المجموعة أولاً —' : '— Select a lot first —' }}
                                        </div>

                                        <!-- Loading animals -->
                                        <div v-else-if="loadingAnimals" style="font-size:.78rem;color:var(--cv-text-muted);padding:.4rem 0">
                                            <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="display:inline;margin-inline-end:.3rem"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/></svg>
                                            {{ lang==='ar' ? 'جارٍ تحميل الحيوانات...' : 'Loading animals...' }}
                                        </div>

                                        <!-- No active animals in lot -->
                                        <div v-else-if="lotAnimals.length === 0" style="font-size:.78rem;color:var(--cv-danger-text);padding:.4rem 0">
                                            {{ lang==='ar' ? 'لا توجد حيوانات نشطة في هذه المجموعة' : 'No active animals in this lot' }}
                                        </div>
                                        <!-- Animal picker — chip grid -->
                                        <div v-else>
                                            <div style="display:flex;gap:.5rem;margin-bottom:.6rem;align-items:center">
                                                <button type="button" @click="selectAllAnimals" class="cv-btn cv-btn-ghost" style="font-size:.72rem;padding:.2rem .65rem">
                                                    {{ lang==='ar' ? 'تحديد الكل' : 'Select All' }}
                                                </button>
                                                <button type="button" @click="clearAllAnimals" class="cv-btn cv-btn-ghost" style="font-size:.72rem;padding:.2rem .65rem">
                                                    {{ lang==='ar' ? 'إلغاء الكل' : 'Clear All' }}
                                                </button>
                                            </div>
                                            <div class="animal-chip-grid">
                                                <div
                                                    v-for="animal in lotAnimals"
                                                    :key="animal.id"
                                                    class="animal-chip"
                                                    :class="{ 'animal-chip-selected': batchForm.animal_ids.includes(animal.id) }"
                                                    @click="toggleAnimal(animal.id)"
                                                >
                                                    <div class="animal-chip-check">
                                                        <svg v-if="batchForm.animal_ids.includes(animal.id)" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" width="8" height="8"><polyline points="20 6 9 17 4 12"/></svg>
                                                    </div>
                                                    <div class="animal-chip-info">
                                                        <span class="animal-chip-tag">{{ animal.tag }}</span>
                                                        <span class="animal-chip-breed">{{ animal.breed }}</span>
                                                        <span v-if="animal.weight" class="animal-chip-weight">{{ animal.weight }} kg</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p v-if="batchForm.errors.animal_ids" class="cv-field-error mt-1">{{ batchForm.errors.animal_ids }}</p>
                                    </div>

                                    <!-- Row 4: Notes -->
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">{{ t.notesLabel }}</label>
                                        <input v-model="batchForm.notes" type="text" class="cv-input" :placeholder="t.notesPh"/>
                                    </div>
                                </div>

                                <!-- ── Section 2: Feed Materials ── -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head mb-3">
                                        <div class="sec-badge sec-2">2</div>
                                        <div>
                                            <div style="font-weight:600;font-size:.875rem;color:var(--cv-text-primary)">{{ t.s2Title }}</div>
                                            <div style="font-size:.75rem;color:var(--cv-text-muted)">{{ t.s2Sub }}</div>
                                        </div>
                                    </div>

                                    <div class="batch-table mt-3">
                                        <div class="batch-header">
                                            <span>{{ t.feedItemCol }}</span>
                                            <span>{{ t.locationCol }}</span>
                                            <span>{{ t.qtyCol }}</span>
                                            <span>{{ t.costCol }}</span>
                                            <span></span>
                                        </div>
                                        <!-- 📌 LivestockOperationalCostFlow (#43) §5 -->
                                        <div v-for="(row, idx) in batchForm.rows" :key="idx" class="batch-row">
                                            <!-- Feed inventory item -->
                                            <div class="flex flex-col gap-1">
                                                <select v-model="row.inventory_item_id" @change="onInventoryItemChange(idx)" class="cv-select cv-select-sm">
                                                    <option value="">{{ t.selFeedItem }}</option>
                                                    <option v-for="fi in inventoryFeedItems" :key="fi.id" :value="fi.id"
                                                        :disabled="fi.total_qty_on_hand <= 0">
                                                        {{ lang==='ar'?(fi.name_ar||fi.name_en):(fi.name_en||fi.name_ar) }}
                                                        {{ fi.total_qty_on_hand <= 0 ? (lang==='ar'?'(نفد)':'(out)') : '' }}
                                                    </option>
                                                </select>
                                                <p v-if="batchForm.errors[`rows.${idx}.inventory_item_id`]" style="font-size:.68rem;color:var(--cv-danger-text)">
                                                    {{ batchForm.errors[`rows.${idx}.inventory_item_id`] }}
                                                </p>
                                            </div>
                                            <!-- Location (cascades from item, filtered to selected farm) -->
                                            <div class="flex flex-col gap-1">
                                                <select v-model="row.location_id" @change="onLocationChange(idx)"
                                                    class="cv-select cv-select-sm"
                                                    :disabled="!row.inventory_item_id || itemLocations(idx).length === 0">
                                                    <option value="">{{ t.selLocation }}</option>
                                                    <option v-for="l in itemLocations(idx)" :key="l.location_id" :value="l.location_id">
                                                        {{ locLabelShort(l) }} · {{ l.qty_on_hand }} kg
                                                    </option>
                                                </select>
                                                <p v-if="batchForm.errors[`rows.${idx}.location_id`]" style="font-size:.68rem;color:var(--cv-danger-text)">
                                                    {{ batchForm.errors[`rows.${idx}.location_id`] }}
                                                </p>
                                            </div>
                                            <!-- Qty -->
                                            <input v-model="row.qty_kg" type="number" step="0.001" min="0" class="cv-input cv-input-sm" placeholder="0.000"/>
                                            <!-- WAC (auto-filled, read intent via title) -->
                                            <div class="flex flex-col gap-1">
                                                <input v-model="row.unit_cost" type="number" step="0.01" min="0" class="cv-input cv-input-sm" placeholder="0.00"
                                                    :title="lang==='ar'?'يُملأ تلقائياً من WAC':'Auto-filled from WAC'"/>
                                                <span v-if="row.unit_cost && row.qty_kg" class="row-total">
                                                    = {{ fmtNum(parseFloat(row.qty_kg) * parseFloat(row.unit_cost)) }}
                                                </span>
                                            </div>
                                            <!-- Remove row -->
                                            <div class="batch-row-actions">
                                                <button type="button" @click="removeBatchRow(idx)" :disabled="batchForm.rows.length===1" class="row-remove">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="12" height="12"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <p v-if="batchForm.errors.rows" class="cv-field-error mt-2">{{ batchForm.errors.rows }}</p>
                                    <button type="button" @click="addBatchRow" class="add-row-btn mt-2">{{ t.addRow }}</button>

                                    <div v-if="batchTotalCost > 0" class="batch-total mt-3">
                                        <span>{{ t.totalLabel }}</span>
                                        <span class="batch-total-val">{{ fmtNum(batchTotalCost) }} {{ t.egp }}</span>
                                    </div>
                                </div>

                            </form>
                        </div>

                        <div class="panel-footer">
                            <button type="button" @click="closeBatch" class="cv-btn cv-btn-ghost">{{ t.cancel2 }}</button>
                            <button type="button" @click="submitBatch" :disabled="batchForm.processing" class="cv-btn cv-btn-amber">
                                <svg v-if="batchForm.processing" class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/></svg>
                                {{ batchForm.processing ? t.saving : t.saveBatch }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- EDIT PANEL                                          -->
            <!-- ═══════════════════════════════════════════════════ -->
            <Teleport to="body">
                <Transition name="backdrop-fade">
                    <div v-if="editPanelOpen" class="panel-backdrop" @click="closeEdit"/>
                </Transition>
                <Transition name="panel-slide">
                    <div v-if="editPanelOpen" class="slide-panel" :dir="isRtl?'rtl':'ltr'" role="dialog">
                        <div class="panel-header">
                            <div class="flex items-center gap-3">
                                <div class="panel-icon" style="background:linear-gradient(135deg,var(--cv-amber),#c97d10)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.7" width="16" height="16"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </div>
                                <div>
                                    <div class="panel-title">{{ t.editTitle }}</div>
                                    <div class="panel-subtitle">{{ editTarget?.date }} · {{ editTarget?.lot?.name }}</div>
                                </div>
                            </div>
                            <button @click="closeEdit" class="panel-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div class="panel-body">
                            <div class="cv-card p-4">

                                <!-- New record: show inventory item + read-only location -->
                                <div v-if="editTarget?.inventory_item_id" class="grid gap-3 mb-3" style="grid-template-columns:1fr 1fr">
                                    <div class="cv-field">
                                        <label class="cv-label">{{ t.feedItemCol }}</label>
                                        <select v-model="editForm.inventory_item_id" class="cv-select">
                                            <option value="">{{ t.selFeedItem }}</option>
                                            <option v-for="fi in inventoryFeedItems" :key="fi.id" :value="fi.id">
                                                {{ lang==='ar'?(fi.name_ar||fi.name_en):(fi.name_en||fi.name_ar) }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="cv-field">
                                        <label class="cv-label">{{ t.locationCol }}</label>
                                        <div class="cv-input" style="cursor:default;opacity:.7">
                                            {{
                                                (() => {
                                                    const item = props.inventoryFeedItems.find(f => String(f.id) === String(editForm.inventory_item_id))
                                                    const loc  = item?.location_stocks?.find(l => String(l.location_id) === String(editForm.location_id))
                                                    if (!loc) return editTarget?.location
                                                        ? (lang==='ar' ? (editTarget.location.name_ar || editTarget.location.name_en) : (editTarget.location.name_en || editTarget.location.name_ar))
                                                        : '—'
                                                    return lang==='ar' ? (loc.location_name_ar || loc.location_name_en) : (loc.location_name_en || loc.location_name_ar)
                                                })()
                                            }}
                                        </div>
                                        <p style="font-size:.7rem;color:var(--cv-text-muted);margin-top:.2rem">
                                            {{ lang==='ar'?'لتغيير الموقع، احذف هذا السجل وأنشئ آخر':'To change location, delete and re-enter' }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Legacy record: show feed_item name read-only -->
                                <div v-else-if="editTarget?.feed_item" class="cv-info-note mb-3"
                                    style="padding:.5rem .75rem;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:6px;font-size:.78rem;color:var(--cv-blue-text)">
                                    {{ lang==='ar'?'سجل قديم — المادة العلفية:':'Legacy record — Feed item:' }}
                                    <strong>{{ lang==='ar'?(editTarget.feed_item.name_ar||editTarget.feed_item.name_en):(editTarget.feed_item.name_en||editTarget.feed_item.name_ar) }}</strong>
                                </div>

                                <!-- Editable fields -->
                                <div class="grid gap-3" style="grid-template-columns:1fr 1fr">
                                    <div class="cv-field">
                                        <label class="cv-label">{{ t.dateLabel }}</label>
                                        <input v-model="editForm.date" type="date" class="cv-input"/>
                                    </div>
                                    <div class="cv-field">
                                        <label class="cv-label">{{ t.qtyKg }}</label>
                                        <input v-model="editForm.qty_kg" type="number" step="0.001" min="0" class="cv-input"/>
                                    </div>
                                    <div class="cv-field">
                                        <label class="cv-label">{{ t.unitCost }}</label>
                                        <input v-model="editForm.unit_cost" type="number" step="0.01" min="0" class="cv-input"
                                            readonly style="opacity:.7;cursor:default"
                                            :title="lang==='ar'?'يُملأ تلقائياً من WAC':'Auto-filled from WAC — cannot be changed'"/>
                                    </div>
                                    <div class="cv-field">
                                        <label class="cv-label">{{ t.totalCost }}</label>
                                        <input v-model="editForm.total_cost" type="number" step="0.01" min="0" class="cv-input"
                                            readonly style="opacity:.7;cursor:default"
                                            :title="lang==='ar'?'يُحسب تلقائياً':'Server-computed — cannot be changed'"/>
                                    </div>
                                </div>

                                <div class="cv-field mt-3">
                                    <label class="cv-label">{{ t.notesLabel }}</label>
                                    <textarea v-model="editForm.notes" class="cv-textarea" rows="2" :placeholder="t.notesPh"/>
                                </div>

                            </div>
                        </div>

                        <div class="panel-footer">
                            <button type="button" @click="closeEdit" class="cv-btn cv-btn-ghost">{{ t.cancel2 }}</button>
                            <button type="button" @click="submitEdit" :disabled="editForm.processing" class="cv-btn cv-btn-amber">
                                {{ editForm.processing ? t.saving : t.saveEdit }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- DELETE MODAL -->
            <Transition name="fade">
                <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal=false">
                    <div class="cv-modal">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </div>
                            <h3 class="cv-modal-title">{{ t.deleteTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <p class="font-semibold mb-1" style="color:var(--cv-text-primary)">{{ deleteTarget?.date }} · {{ deleteTarget?.lot?.name }}</p>
                            <p>{{ t.deleteMsg }}</p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deleteModal=false">{{ t.cancel }}</button>
                            <button class="cv-btn cv-btn-danger" @click="doDelete">{{ t.confirm }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
   
</template>

<style scoped>
.cv-action-btn{width:28px;height:28px;border-radius:.35rem;border:1px solid var(--cv-border);background:transparent;color:var(--cv-text-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s}
.cv-action-btn-amber:hover{background:color-mix(in srgb,var(--cv-amber) 15%,transparent);color:var(--cv-amber);border-color:var(--cv-amber)}
.cv-action-btn-danger:hover{background:color-mix(in srgb,var(--cv-danger-text) 15%,transparent);color:var(--cv-danger-text);border-color:var(--cv-danger-text)}
.variance-chip{font-size:.72rem;font-weight:600;padding:.15rem .5rem;border-radius:999px}
.var-over{background:rgba(239,68,68,.12);color:var(--cv-danger-text)}
.var-under{background:rgba(96,168,224,.12);color:var(--cv-blue-text)}
.var-exact{background:rgba(92,196,106,.12);color:var(--cv-green-bright)}
.cv-pagination{display:flex;gap:.25rem;flex-wrap:wrap}
.cv-page-link{padding:.35rem .75rem;border-radius:6px;font-size:.82rem;color:var(--cv-text-secondary);background:var(--cv-bg-card);border:1px solid var(--cv-border);text-decoration:none;transition:all .15s}
.cv-page-link.active{background:var(--cv-amber);color:#000;border-color:var(--cv-amber);font-weight:600}
.cv-page-link.disabled{opacity:.4;pointer-events:none}
.mt-4{margin-top:1rem}.mt-6{margin-top:1.5rem}
.panel-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);z-index:50}
.slide-panel{position:fixed;inset-block:0;inset-inline-end:0;width:100%;max-width:520px;background:var(--cv-bg-card);border-inline-start:1px solid var(--cv-border);z-index:51;display:flex;flex-direction:column;box-shadow:-8px 0 40px rgba(0,0,0,.4)}
.panel-wide{max-width:860px}
.panel-header{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;border-bottom:1px solid var(--cv-border);flex-shrink:0}
.panel-icon{width:2.25rem;height:2.25rem;border-radius:.5rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.panel-title{font-size:1rem;font-weight:600;color:var(--cv-text-primary)}
.panel-subtitle{font-size:.78rem;color:var(--cv-text-muted);margin-top:.1rem}
.panel-close{width:2rem;height:2rem;border-radius:.4rem;border:1px solid var(--cv-border);background:transparent;color:var(--cv-text-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s}
.panel-close:hover{background:var(--cv-bg-hover);color:var(--cv-text-primary)}
.panel-body{flex:1;overflow-y:auto;padding:1.25rem 1.5rem}
.panel-footer{display:flex;align-items:center;justify-content:flex-end;gap:.75rem;padding:1rem 1.5rem;border-top:1px solid var(--cv-border);flex-shrink:0}
.section-head{display:flex;align-items:flex-start;gap:.75rem}
.sec-badge{width:1.75rem;height:1.75rem;border-radius:.4rem;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0}
.sec-1{background:linear-gradient(135deg,var(--cv-green-bright),#2e9048)}
.sec-2{background:linear-gradient(135deg,var(--cv-amber),#c97d10)}
.batch-table{border:1px solid var(--cv-border);border-radius:.5rem;overflow:hidden}
.batch-header{display:grid;grid-template-columns:2fr 1.5fr 1fr 1fr 40px;gap:.4rem;padding:.5rem .75rem;background:var(--cv-bg-card-alt,#132218);font-size:.7rem;font-weight:600;color:var(--cv-text-muted);text-transform:uppercase;letter-spacing:.05em}
.batch-row{display:grid;grid-template-columns:2fr 1.5fr 1fr 1fr 40px;gap:.4rem;align-items:center;padding:.4rem .75rem;border-top:1px solid var(--cv-border)}
.batch-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:.3rem}
.row-total{font-size:.75rem;font-weight:600;color:var(--cv-blue-text);white-space:nowrap}
.row-remove{width:22px;height:22px;border-radius:.3rem;border:1px solid var(--cv-border);background:transparent;color:var(--cv-text-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;flex-shrink:0}
.row-remove:hover:not(:disabled){background:rgba(239,68,68,.1);color:var(--cv-danger-text);border-color:var(--cv-danger-text)}
.row-remove:disabled{opacity:.3;cursor:not-allowed}
.cv-select-sm,.cv-input-sm{padding:.35rem .5rem;font-size:.8rem}
.add-row-btn{display:flex;align-items:center;justify-content:center;width:100%;font-size:.8rem;font-weight:600;color:var(--cv-amber);background:color-mix(in srgb,var(--cv-amber) 8%,transparent);border:1px dashed color-mix(in srgb,var(--cv-amber) 30%,transparent);border-radius:.45rem;padding:.4rem .85rem;cursor:pointer;transition:all .15s}
.add-row-btn:hover{background:color-mix(in srgb,var(--cv-amber) 15%,transparent);border-color:var(--cv-amber)}
.batch-total{display:flex;align-items:center;justify-content:space-between;padding:.6rem .75rem;background:color-mix(in srgb,var(--cv-blue-text) 8%,transparent);border:1px solid color-mix(in srgb,var(--cv-blue-text) 20%,transparent);border-radius:.45rem;font-size:.8rem;color:var(--cv-text-muted)}
.batch-total-val{font-size:1rem;font-weight:700;color:var(--cv-blue-text)}
.animal-chip-grid{display:flex;flex-wrap:wrap;gap:7px;max-height:280px;overflow-y:auto;padding:.25rem .1rem}
.animal-chip{display:flex;align-items:center;gap:7px;padding:6px 12px 6px 8px;border-radius:999px;border:1px solid var(--cv-border);background:var(--cv-bg-card);cursor:pointer;transition:opacity .15s,border-color .15s,background .15s;opacity:.38;user-select:none}
.animal-chip:hover{opacity:.65}
.animal-chip-selected{opacity:1;border-color:var(--cv-green-bright);background:color-mix(in srgb,var(--cv-green-bright) 10%,transparent)}
.animal-chip-check{width:15px;height:15px;border-radius:50%;border:1.5px solid var(--cv-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,border-color .15s}
.animal-chip-selected .animal-chip-check{background:var(--cv-green-bright);border-color:var(--cv-green-bright)}
.animal-chip-info{display:flex;flex-direction:column;gap:1px}
.animal-chip-tag{font-size:.75rem;font-weight:600;color:var(--cv-text-primary);line-height:1.3}
.animal-chip-breed{font-size:.68rem;color:var(--cv-text-muted);line-height:1.3}
.animal-chip-weight{font-size:.68rem;font-weight:600;color:var(--cv-blue-text);line-height:1.3}

.backdrop-fade-enter-from,.backdrop-fade-leave-to{opacity:0}
.panel-slide-enter-active,.panel-slide-leave-active{transition:transform .25s ease}
.panel-slide-enter-from,.panel-slide-leave-to{transform:translateX(100%)}
[dir="rtl"] .panel-slide-enter-from,[dir="rtl"] .panel-slide-leave-to{transform:translateX(-100%)}
.slide-down-enter-active,.slide-down-leave-active{transition:all .25s ease}
.slide-down-enter-from,.slide-down-leave-to{opacity:0;transform:translateY(-8px)}
.fade-enter-active,.fade-leave-active{transition:opacity .2s}
.fade-enter-from,.fade-leave-to{opacity:0}
@keyframes spin-anim{to{transform:rotate(360deg)}}
.spin{animation:spin-anim .8s linear infinite}
@media(max-width:768px){
    .slide-panel,.panel-wide{max-width:100%}
    .batch-header,.batch-row{grid-template-columns:2fr 1fr 1fr 40px}
    .batch-header span:nth-child(2),.batch-row>div:nth-child(2){display:none}
}

</style>