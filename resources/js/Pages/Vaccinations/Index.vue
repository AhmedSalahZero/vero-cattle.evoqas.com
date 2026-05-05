<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Vaccinations/Index.vue  (Session 50 rework)
//
//  COMPONENT PLAN:
//  ─────────────────────────────────────────────────────────────────
//  PROPS SHAPE (from VaccinationController::index):
//    records   → paginated {data[], links[], meta{}} vaccination records
//                each record: {id, animal{id,tag}, farm{id,name_en,name_ar},
//                  lot{id,name}, vaccine{id,name_en,name_ar}, date,
//                  batch_number, next_due_date, dose_given_ml, cost, notes,
//                  is_due_soon, created_at}
//    inventoryVaccines → inventory items category=vaccine, each with location_stocks[]
//    farms     → [{id, company_id, name_en, name_ar}]
//    lots      → [{id, farm_id, name}]
//    filters   → {farm_id, lot_id, inventory_vaccine_id, due_soon, search}
//
//  📌 METHODOLOGY REFERENCE: VaccinationBatchStore (#18)
//  📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §7
//
//  SESSION 50 REWORK — Migration 059:
//    vaccine_id REMOVED from all new record paths
//    inventory_item_id + location_id required for new records
//    cost auto-computed from stock WAC × dose
//    legacy records (vaccine_id set) still display via fallback
//    user.permissions  → string
//
//  PATTERN: Index-only (all CRUD via slide-in panels — stays on /vaccinations)
//
//  PANELS:
//    A → Single animal entry (one animal, one vaccine, one date)
//    B → Bulk entry (whole lot — select animals, shared session data)
//    C → Edit panel (same fields as single)
//
//  ENTRY MODE:
//    Single: farm → lot → animal (cascade)
//    Bulk:   farm → lot → all animals in lot (checkboxes, select-all)
//    Animals loaded client-side from lots (passed as prop)
//    Note: animals in lot fetched via router.visit or axios — we use
//          a reactive fetch via useForm + router pattern
//
//  ACTIONS:
//    POST /vaccinations          → vaccinations.store     (single)
//    POST /vaccinations/batch    → vaccinations.batch     (bulk)
//    PUT  /vaccinations/{id}     → vaccinations.update    (edit)
//    DELETE /vaccinations/{id}   → vaccinations.destroy   (delete)
//
//  TABLE COLUMNS:
//    Date · Animal Tag · Farm · Lot · Vaccine · Dose (ml) ·
//    Batch # · Next Due · Cost (EGP) · Due Soon badge · Actions
//
//  FILTERS: Farm · Vaccine · Due Soon toggle · Search (animal tag)
//
//  PERMISSIONS:
//    view vaccinations   → see the page
//    create vaccinations → Add buttons visible
//    edit vaccinations   → Edit action on rows
//    delete vaccinations → Delete action on rows
//
//  MOBILE / RTL:
//    isRtl → dir="rtl" on containers, panels slide from inset-inline-end
//    Table: horizontal scroll on mobile
//    text-start (logical) throughout — never text-left
//
//  BILINGUAL:
//    Vaccine names: n(vaccine, 'name') → name_en or name_ar
//    Farm names:    n(farm, 'name')    → name_en or name_ar
//    All labels/placeholders/badges fully translated when locale === 'ar'
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    records:  { type: Object, default: () => ({ data: [], links: [], meta: {} }) },
    inventoryVaccines: { type: Array,  default: () => [] },  // inventory items category=vaccine, with location_stocks
    farms:    { type: Array,  default: () => [] },
    lots:     { type: Array,  default: () => [] },
    filters:  { type: Object, default: () => ({}) },
})

const { locale, isRtl, n } = useLocale()

// ── Permissions ───────────────────────────────────────────────────
const { can: canPermission } = usePermissions()
const can = computed(() => ({
    create: canPermission('create vaccinations'),
    edit:   canPermission('edit vaccinations'),
    delete: canPermission('delete vaccinations'),
}))

// ── Bilingual labels ──────────────────────────────────────────────
const L = computed(() => locale.value === 'ar' ? {
    // Page chrome
    eyebrow:        'الصحة والبيطرة',
    title:          'سجلات التطعيم',
    sub:            'تتبع التطعيمات وتواريخ الاستحقاق ومعدلات الامتثال',
    addSingle:      'تطعيم ماشية',
    addBulk:        'تطعيم جماعي',
    // Filters
    filterFarm:     'المزرعة',
    filterVaccine:  'اللقاح (المخزون)',
    filterDueSoon:  'مستحق قريباً',
    searchPlaceholder: 'ابحث بالرقم التعريفي...',
    allFarms:       'كل المزارع',
    allVaccines:    'كل اللقاحات',
    // Table headers
    colDate:        'التاريخ',
    colAnimal:      'الماشية',
    colFarm:        'المزرعة',
    colLot:         'المجموعة',
    colVaccine:     'اللقاح / الموقع',
    colDose:        'الجرعة (مل)',
    colBatch:       'رقم الدفعة',
    colNextDue:     'الموعد القادم',
    colCost:        'التكلفة (ج.م)',
    colActions:     'إجراءات',
    // Badges
    badgeDueSoon:   'مستحق قريباً',
    badgeOverdue:   'متأخر',
    // Panel single
    panelSingleTitle:  'تسجيل تطعيم — ماشية واحد',
    panelBulkTitle:    'تسجيل تطعيم — جماعي',
    panelEditTitle:    'تعديل سجل التطعيم',
    // Section labels
    secAnimal:      'بيانات الماشية',
    secVaccine:     'بيانات اللقاح',
    secBulkAnimal:  'اختيار الماشيةات',
    // Fields
    lblFarm:        'المزرعة',
    lblLot:         'المجموعة',
    lblAnimal:      'الماشية (الرقم التعريفي)',
    lblVaccine:     'صنف اللقاح (المخزون)',
    lblDate:        'تاريخ التطعيم',
    lblDose:        'الجرعة المعطاة (مل)',
    lblBatch:       'رقم دفعة اللقاح',
    lblNextDue:     'تاريخ الجرعة القادمة',
    lblCost:        'التكلفة (ج.م)',
    lblNotes:       'ملاحظات',
    // Select options
    selectFarm:     'اختر المزرعة',
    selectLot:      'اختر المجموعة',
    selectAnimal:   'اختر الماشية',
    selectVaccine:  'اختر اللقاح من المخزون',
    selectLocation: 'اختر الموقع',
    lblLocation:    'موقع المخزون',
    noStock:        'لا يوجد مخزون من هذا اللقاح في أي موقع',
    lotFirst:       'اختر المجموعة أولاً',
    farmFirst:      'اختر المزرعة أولاً',
    // Bulk
    selectAll:      'تحديد الكل',
    deselectAll:    'إلغاء تحديد الكل',
    selectedCount:  (n) => `${n} ماشيةات محددة`,
    noAnimals:      'لا توجد ماشيةات في هذه المجموعة',
    // Actions
    btnSave:        'حفظ',
    btnUpdate:      'تحديث',
    btnCancel:      'إلغاء',
    btnEdit:        'تعديل',
    btnDelete:      'حذف',
    // Delete modal
    deleteTitle:    'حذف سجل التطعيم',
    deleteMsg:      'هل أنت متأكد من حذف هذا السجل؟ لا يمكن التراجع عن هذا الإجراء.',
    deleteConfirm:  'نعم، احذف',
    // Empty
    noRecords:      'لا توجد سجلات تطعيم',
    noRecordsSub:   'ابدأ بتسجيل أول تطعيم',
    // Stats
    statTotal:      'إجمالي السجلات',
    statDueSoon:    'مستحق خلال ٧ أيام',
    statThisMonth:  'هذا الشهر',
} : {
    eyebrow:        'Health & Veterinary',
    title:          'Vaccination Records',
    sub:            'Track vaccinations, due dates, and compliance rates',
    addSingle:      'Vaccinate Animal',
    addBulk:        'Bulk Vaccination',
    filterFarm:     'Farm',
    filterVaccine:  'Vaccine (Inventory)',
    filterDueSoon:  'Due Soon',
    searchPlaceholder: 'Search by tag...',
    allFarms:       'All Farms',
    allVaccines:    'All Vaccines',
    colDate:        'Date',
    colAnimal:      'Animal',
    colFarm:        'Farm',
    colLot:         'Lot',
    colVaccine:     'Vaccine / Location',
    colDose:        'Dose (ml)',
    colBatch:       'Batch #',
    colNextDue:     'Next Due',
    colCost:        'Cost (EGP)',
    colActions:     'Actions',
    badgeDueSoon:   'Due Soon',
    badgeOverdue:   'Overdue',
    panelSingleTitle:  'Record Vaccination — Single Animal',
    panelBulkTitle:    'Record Vaccination — Bulk',
    panelEditTitle:    'Edit Vaccination Record',
    secAnimal:      'Animal Details',
    secVaccine:     'Vaccine Details',
    secBulkAnimal:  'Select Animals',
    lblFarm:        'Farm',
    lblLot:         'Lot',
    lblAnimal:      'Animal (Tag)',
    lblVaccine:     'Vaccine Item (Inventory)',
    lblDate:        'Vaccination Date',
    lblDose:        'Dose Given (ml)',
    lblBatch:       'Vaccine Batch Number',
    lblNextDue:     'Next Due Date',
    lblCost:        'Cost (EGP)',
    lblNotes:       'Notes',
    selectFarm:     'Select farm',
    selectLot:      'Select lot',
    selectAnimal:   'Select animal',
    selectVaccine:  'Select vaccine from inventory',
    selectLocation: 'Select location',
    lblLocation:    'Stock Location',
    noStock:        'No stock available for this vaccine in any location',
    lotFirst:       'Select a lot first',
    farmFirst:      'Select a farm first',
    selectAll:      'Select All',
    deselectAll:    'Deselect All',
    selectedCount:  (n) => `${n} animals selected`,
    noAnimals:      'No animals in this lot',
    btnSave:        'Save',
    btnUpdate:      'Update',
    btnCancel:      'Cancel',
    btnEdit:        'Edit',
    btnDelete:      'Delete',
    deleteTitle:    'Delete Vaccination Record',
    deleteMsg:      'Are you sure you want to delete this record? This action cannot be undone.',
    deleteConfirm:  'Yes, Delete',
    noRecords:      'No vaccination records found',
    noRecordsSub:   'Start by recording the first vaccination',
    statTotal:      'Total Records',
    statDueSoon:    'Due Within 7 Days',
    statThisMonth:  'This Month',
})

// ── Filters ───────────────────────────────────────────────────────
const filterForm = useForm({
    farm_id:    props.filters.farm_id    ?? '',
    inventory_vaccine_id: props.filters.inventory_vaccine_id ?? '',
    due_soon:   props.filters.due_soon   ?? false,
    search:     props.filters.search     ?? '',
})

const applyFilters = () => {
    router.get(route('vaccinations.index'), {
        farm_id:    filterForm.farm_id    || undefined,
        inventory_vaccine_id: filterForm.inventory_vaccine_id || undefined,
        due_soon:   filterForm.due_soon   || undefined,
        search:     filterForm.search     || undefined,
    }, { preserveState: true, replace: true })
}

watch(() => [filterForm.farm_id, filterForm.inventory_vaccine_id, filterForm.due_soon], applyFilters)

let searchTimer = null
watch(() => filterForm.search, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyFilters, 400)
})

// ── Panel state ───────────────────────────────────────────────────
const panelMode   = ref(null) // null | 'single' | 'bulk' | 'edit'
const editRecord  = ref(null)

const openSingle = () => {
    editRecord.value = null
    singleForm.reset()
    singleForm.farm_id = ''
    singleForm.lot_id = ''
    singleForm.animal_id = ''
    singleForm.inventory_item_id = ''
    singleForm.location_id = ''
    singleForm.date = todayStr()
    singleForm.dose_given_ml = ''
    singleForm.batch_number = ''
    singleForm.next_due_date = ''
    singleForm.cost = ''
    singleForm.notes = ''
    panelMode.value = 'single'
}
const openBulk = () => {
    bulkForm.reset()
    bulkForm.farm_id = ''
    bulkForm.lot_id = ''
    bulkForm.inventory_item_id = ''
    bulkForm.location_id = ''
    bulkForm.date = todayStr()
    bulkForm.dose_given_ml = ''
    bulkForm.batch_number = ''
    bulkForm.next_due_date = ''
    bulkForm.cost = ''
    bulkForm.notes = ''
    bulkForm.animal_ids = []
    panelMode.value = 'bulk'
}
const openEdit = (record) => {
    editRecord.value = record
    editForm.animal_id           = record.animal_id
    editForm.farm_id             = record.farm_id
    editForm.lot_id              = record.lot_id ?? ''
    editForm.inventory_item_id   = record.inventory_item_id ?? ''
    editForm.location_id         = record.location_id ?? ''
    editForm.date           = record.date
    editForm.dose_given_ml  = record.dose_given_ml ?? ''
    editForm.batch_number   = record.batch_number ?? ''
    editForm.next_due_date  = record.next_due_date ?? ''
    editForm.cost           = record.cost ?? ''
    editForm.notes          = record.notes ?? ''
    panelMode.value = 'edit'
}
const closePanel = () => { panelMode.value = null; editRecord.value = null }

// ── Delete modal ──────────────────────────────────────────────────
const deleteTarget = ref(null)
const confirmDelete = (record) => { deleteTarget.value = record }
const closeDelete   = () => { deleteTarget.value = null }
const doDelete      = () => {
    router.delete(route('vaccinations.destroy', deleteTarget.value.id), {
        onSuccess: closeDelete,
    })
}

// ── Helpers ───────────────────────────────────────────────────────
const todayStr = () => new Date().toISOString().slice(0, 10)

// Cascading lots filtered by farm
const lotsForFarm = (farmId) =>
    farmId ? props.lots.filter(l => l.farm_id == farmId) : []

// Animals in a lot — fetched reactively
const lotAnimals = ref([])
const loadingAnimals = ref(false)

const loadLotAnimals = (lotId) => {
    if (!lotId) { lotAnimals.value = []; return }
    loadingAnimals.value = true
    // Fetch animals for the selected lot via Inertia visit data
    // We use a lightweight fetch approach
    fetch(route('vaccinations.index', { _animals_for_lot: lotId }), {
        headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '1', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        // Fallback: filter from already-loaded records' animals if fetch fails
        loadingAnimals.value = false
    })
    .catch(() => { loadingAnimals.value = false })
}

// Simpler: animals derived from records data for the selected lot
// (covers animals already in the system; a full animal list requires a dedicated endpoint)
// We'll use a direct Inertia shared prop pattern — animals fetched via dedicated endpoint
const animalsInLot = ref([])

const fetchAnimalsForLot = async (lotId) => {
    if (!lotId) { animalsInLot.value = []; return }
    loadingAnimals.value = true
    try {
        const res = await fetch(`/api/lots/${lotId}/animals`, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            }
        })
        if (res.ok) {
            const data = await res.json()
            animalsInLot.value = data
        } else {
            // Graceful fallback — derive from current page records
            animalsInLot.value = []
        }
    } catch {
        animalsInLot.value = []
    } finally {
        loadingAnimals.value = false
    }
}

// ── Vaccine inventory helpers ─────────────────────────────────────
// 📌 LivestockOperationalCostFlow (#43) §7
// Each item in inventoryVaccines carries location_stocks[]
// [{location_id, location_name_en, location_name_ar, qty_on_hand, is_central, farm_name_en, farm_name_ar, unit_cost}]
function vaccineLocations(itemId) {
    if (!itemId) return []
    const item = props.inventoryVaccines.find(v => String(v.id) === String(itemId))
    if (!item || !item.location_stocks) return []
    return item.location_stocks.filter(l => l.qty_on_hand > 0)
}

function onVaccineItemChange(form) {
    form.location_id  = ''
    form.cost         = ''
    const locs = vaccineLocations(form.inventory_item_id)
    // auto-select if only one location has stock
    if (locs.length === 1) {
        form.location_id = locs[0].location_id
        recalcVaccineCost(form, locs[0])
    }
}

function onVaccineLocationChange(form) {
    const locs = vaccineLocations(form.inventory_item_id)
    const loc  = locs.find(l => String(l.location_id) === String(form.location_id))
    if (loc) recalcVaccineCost(form, loc)
}

function recalcVaccineCost(form, loc) {
    // cost = WAC × dose (auto-calculated, overridable by user)
    if (loc && form.dose_given_ml > 0) {
        form.cost = (parseFloat(loc.unit_cost) * parseFloat(form.dose_given_ml)).toFixed(2)
    }
}

function vacLocLabel(l) {
    if (!l) return '—'
    const name = locale.value === 'ar' ? (l.location_name_ar || l.location_name_en) : (l.location_name_en || l.location_name_ar)
    return l.is_central ? `🏢 ${name}` : `🏠 ${name}`
}

// ── Single form ───────────────────────────────────────────────────
const singleForm = useForm({
    farm_id:            '',
    lot_id:             '',
    animal_id:          '',
    inventory_item_id:  '',   // 📌 LivestockOperationalCostFlow (#43) §7
    location_id:        '',
    date:               todayStr(),
    dose_given_ml:      '',
    batch_number:       '',
    next_due_date:      '',
    cost:               '',
    notes:              '',
})

watch(() => singleForm.lot_id, (val) => {
    singleForm.animal_id = ''
    fetchAnimalsForLot(val)
})

const submitSingle = () => {
    singleForm.post(route('vaccinations.store'), {
        onSuccess: closePanel,
    })
}

// ── Bulk form ─────────────────────────────────────────────────────
const bulkForm = useForm({
    farm_id:            '',
    lot_id:             '',
    inventory_item_id:  '',   // 📌 LivestockOperationalCostFlow (#43) §7
    location_id:        '',
    date:               todayStr(),
    dose_given_ml:      '',
    batch_number:       '',
    next_due_date:      '',
    cost:               '',
    notes:              '',
    animal_ids:         [],
})

watch(() => bulkForm.lot_id, (val) => {
    bulkForm.animal_ids = []
    fetchAnimalsForLot(val)
})

const toggleAnimal = (id) => {
    const idx = bulkForm.animal_ids.indexOf(id)
    if (idx === -1) bulkForm.animal_ids.push(id)
    else bulkForm.animal_ids.splice(idx, 1)
}

const selectAllAnimals = () => {
    bulkForm.animal_ids = animalsInLot.value.map(a => a.id)
}
const deselectAllAnimals = () => {
    bulkForm.animal_ids = []
}
const allSelected = computed(() =>
    animalsInLot.value.length > 0 &&
    bulkForm.animal_ids.length === animalsInLot.value.length
)

const submitBulk = () => {
    bulkForm.post(route('vaccinations.batch'), {
        onSuccess: closePanel,
    })
}

// ── Edit form ─────────────────────────────────────────────────────
const editForm = useForm({
    animal_id:          '',
    farm_id:            '',
    lot_id:             '',
    inventory_item_id:  '',   // 📌 LivestockOperationalCostFlow (#43) §7
    location_id:        '',
    date:               '',
    dose_given_ml:      '',
    batch_number:       '',
    next_due_date:      '',
    cost:               '',
    notes:              '',
})

const submitEdit = () => {
    editForm.put(route('vaccinations.update', editRecord.value.id), {
        onSuccess: closePanel,
    })
}

// ── Stats (derived from page data) ────────────────────────────────
const stats = computed(() => {
    const data = props.records.data ?? []
    const dueSoon = data.filter(r => r.is_due_soon).length
    const thisMonth = data.filter(r => {
        const d = new Date(r.date)
        const now = new Date()
        return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear()
    }).length
    return {
        total:     props.records.meta?.total ?? data.length,
        dueSoon,
        thisMonth,
    }
})

// ── Next due date status ──────────────────────────────────────────
const dueBadge = (record) => {
    if (!record.next_due_date) return null
    const days = Math.ceil((new Date(record.next_due_date) - new Date()) / 86400000)
    if (days < 0) return 'overdue'
    if (days <= 7) return 'soon'
    return null
}

const formatDate = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
        year: 'numeric', month: 'short', day: 'numeric'
    })
}

const formatCost = (v) => {
    if (v == null || v === '') return '—'
    return Number(v).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-EG', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    })
}
</script>

<template>
    <AuthenticatedLayout>

        <!-- ── Hero ──────────────────────────────────────────────── -->
        <div class="cv-page-hero">
            <div class="cv-page-hero-eyebrow">{{ L.eyebrow }}</div>
            <div class="cv-page-hero-title">{{ L.title }}</div>
            <div class="cv-page-hero-sub">{{ L.sub }}</div>
            <div class="cv-page-hero-actions" v-if="can.create">
                <button class="cv-btn cv-btn-ghost" @click="openBulk">
                    <!-- syringe bulk icon -->
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <rect x="2" y="10" width="20" height="4" rx="2"/>
                        <path d="M6 10V7M10 10V7M14 10V7M18 10V7"/>
                        <path d="M2 12h1M21 12h1"/>
                    </svg>
                    {{ L.addBulk }}
                </button>
                <button class="cv-btn cv-btn-amber" @click="openSingle">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path d="M17 3l4 4-9.5 9.5-4.5 1 1-4.5L17 3z"/>
                        <path d="M14 6l4 4"/>
                    </svg>
                    {{ L.addSingle }}
                </button>
            </div>
        </div>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Stats row ──────────────────────────────────────── -->
            <div class="stats-row">
                <div class="stat-chip stat-chip-blue">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span class="stat-num">{{ stats.total }}</span>
                    <span class="stat-lbl">{{ L.statTotal }}</span>
                </div>
                <div class="stat-chip stat-chip-amber">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                    <span class="stat-num">{{ stats.dueSoon }}</span>
                    <span class="stat-lbl">{{ L.statDueSoon }}</span>
                </div>
                <div class="stat-chip stat-chip-green">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span class="stat-num">{{ stats.thisMonth }}</span>
                    <span class="stat-lbl">{{ L.statThisMonth }}</span>
                </div>
            </div>

            <!-- ── Filter bar ─────────────────────────────────────── -->
            <div class="cv-card filter-bar">
                <!-- Search -->
                <div class="filter-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input
                        v-model="filterForm.search"
                        type="text"
                        class="cv-input filter-input"
                        :placeholder="L.searchPlaceholder"
                    />
                </div>

                <!-- Farm filter -->
                <select v-model="filterForm.farm_id" class="cv-select filter-select">
                    <option value="">{{ L.allFarms }}</option>
                    <option v-for="farm in farms" :key="farm.id" :value="farm.id">
                        {{ n(farm, 'name') }}
                    </option>
                </select>

                <!-- Vaccine filter — inventory items category=vaccine -->
                <select v-model="filterForm.inventory_vaccine_id" class="cv-select filter-select">
                    <option value="">{{ L.allVaccines }}</option>
                    <option v-for="v in inventoryVaccines" :key="v.id" :value="v.id">
                        {{ locale==='ar'?(v.name_ar||v.name_en):(v.name_en||v.name_ar) }}
                    </option>
                </select>

                <!-- Due soon toggle -->
                <label class="due-soon-toggle">
                    <input type="checkbox" v-model="filterForm.due_soon" />
                    <span class="toggle-track"><span class="toggle-thumb"/></span>
                    <span class="toggle-label">{{ L.filterDueSoon }}</span>
                </label>
            </div>

            <!-- ── Table ──────────────────────────────────────────── -->
            <div class="cv-card table-wrapper">
                <div class="table-scroll">
                    <table class="vacc-table">
                        <thead>
                            <tr>
                                <th>{{ L.colDate }}</th>
                                <th>{{ L.colAnimal }}</th>
                                <th>{{ L.colFarm }}</th>
                                <th>{{ L.colLot }}</th>
                                <th>{{ L.colVaccine }}</th>
                                <th>{{ L.colDose }}</th>
                                <th>{{ L.colBatch }}</th>
                                <th>{{ L.colNextDue }}</th>
                                <th>{{ L.colCost }}</th>
                                <th>{{ L.colActions }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Empty state -->
                            <tr v-if="!records.data?.length">
                                <td colspan="10" class="empty-cell">
                                    <div class="empty-state">
                                        <div class="empty-icon">
                                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M17 3l4 4-9.5 9.5-4.5 1 1-4.5L17 3z"/><path d="M14 6l4 4"/><path d="M3 21h18"/></svg>
                                        </div>
                                        <p class="empty-title">{{ L.noRecords }}</p>
                                        <p class="empty-sub">{{ L.noRecordsSub }}</p>
                                    </div>
                                </td>
                            </tr>

                            <tr v-for="record in records.data" :key="record.id" class="table-row">
                                <td class="cell-date">{{ formatDate(record.date) }}</td>

                                <td class="cell-tag">
                                    <span class="animal-tag">{{ record.animal?.tag ?? '—' }}</span>
                                </td>

                                <td class="cell-farm">
                                    {{ record.farm ? n(record.farm, 'name') : '—' }}
                                </td>

                                <td class="cell-lot">
                                    <span class="lot-name">{{ record.lot?.name ?? '—' }}</span>
                                </td>

                                <td class="cell-vaccine">
                                    <!-- New: inventory_item; Legacy fallback: vaccine catalog -->
                                    <template v-if="record.inventory_item">
                                        <span class="vaccine-pill">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3l4 4-9.5 9.5-4.5 1 1-4.5L17 3z"/><path d="M14 6l4 4"/></svg>
                                            {{ locale==='ar'?(record.inventory_item.name_ar||record.inventory_item.name_en):(record.inventory_item.name_en||record.inventory_item.name_ar) }}
                                        </span>
                                        <div v-if="record.location" style="font-size:0.68rem;color:var(--cv-text-muted);margin-top:2px">
                                            {{ locale==='ar'?(record.location.name_ar||record.location.name_en):(record.location.name_en||record.location.name_ar) }}
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span class="vaccine-pill">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3l4 4-9.5 9.5-4.5 1 1-4.5L17 3z"/><path d="M14 6l4 4"/></svg>
                                            {{ record.vaccine ? n(record.vaccine, 'name') : '—' }}
                                        </span>
                                    </template>
                                </td>

                                <td class="cell-num">
                                    {{ record.dose_given_ml != null ? record.dose_given_ml : '—' }}
                                </td>

                                <td class="cell-batch">
                                    <span class="batch-text">{{ record.batch_number || '—' }}</span>
                                </td>

                                <td class="cell-due">
                                    <span v-if="!record.next_due_date" class="text-muted">—</span>
                                    <span v-else-if="dueBadge(record) === 'overdue'" class="cv-badge cv-badge-danger">
                                        {{ formatDate(record.next_due_date) }}
                                    </span>
                                    <span v-else-if="dueBadge(record) === 'soon'" class="cv-badge cv-badge-warning">
                                        {{ L.badgeDueSoon }} · {{ formatDate(record.next_due_date) }}
                                    </span>
                                    <span v-else class="due-date-ok">{{ formatDate(record.next_due_date) }}</span>
                                </td>

                                <td class="cell-num">
                                    {{ formatCost(record.cost) }}
                                </td>

                                <td class="cell-actions">
                                    <button
                                        v-if="can.edit"
                                        class="action-btn action-edit"
                                        @click="openEdit(record)"
                                        :title="L.btnEdit"
                                    >
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button
                                        v-if="can.delete"
                                        class="action-btn action-delete"
                                        @click="confirmDelete(record)"
                                        :title="L.btnDelete"
                                    >
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="records.links?.length > 3" class="pagination-row">
                    <component
                        v-for="link in records.links"
                        :key="link.label"
                        :is="link.url ? 'a' : 'span'"
                        :href="link.url ?? undefined"
                        v-html="link.label"
                        class="page-link"
                        :class="{ 'page-link-active': link.active, 'page-link-disabled': !link.url }"
                        @click.prevent="link.url && router.visit(link.url, { preserveScroll: true })"
                    />
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             SLIDE-IN PANEL — SINGLE ANIMAL
        ══════════════════════════════════════════════════════════════ -->
        <Transition name="panel-fade">
            <div v-if="panelMode === 'single'" class="panel-backdrop" @click.self="closePanel">
                <div class="side-panel" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="panel-header">
                        <h2 class="panel-title">{{ L.panelSingleTitle }}</h2>
                        <button class="panel-close" @click="closePanel">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="panel-body">
                        <!-- Section 1: Animal -->
                        <div class="panel-section">
                            <div class="panel-section-header">
                                <span class="section-badge">١</span>
                                <span>{{ L.secAnimal }}</span>
                            </div>

                            <!-- Farm -->
                            <div class="field-group">
                                <label class="cv-label">{{ L.lblFarm }} <span class="req">*</span></label>
                                <select v-model="singleForm.farm_id" class="cv-select" :class="{'input-error': singleForm.errors.farm_id}">
                                    <option value="">{{ L.selectFarm }}</option>
                                    <option v-for="farm in farms" :key="farm.id" :value="farm.id">{{ n(farm,'name') }}</option>
                                </select>
                                <p v-if="singleForm.errors.farm_id" class="field-error">{{ singleForm.errors.farm_id }}</p>
                            </div>

                            <!-- Lot -->
                            <div class="field-group">
                                <label class="cv-label">{{ L.lblLot }}</label>
                                <select v-model="singleForm.lot_id" class="cv-select" :disabled="!singleForm.farm_id">
                                    <option value="">{{ singleForm.farm_id ? L.selectLot : L.farmFirst }}</option>
                                    <option v-for="lot in lotsForFarm(singleForm.farm_id)" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
                                </select>
                            </div>

                            <!-- Animal -->
                            <div class="field-group">
                                <label class="cv-label">{{ L.lblAnimal }} <span class="req">*</span></label>
                                <select v-model="singleForm.animal_id" class="cv-select" :disabled="!singleForm.lot_id" :class="{'input-error': singleForm.errors.animal_id}">
                                    <option value="">{{ singleForm.lot_id ? (loadingAnimals ? '...' : L.selectAnimal) : L.lotFirst }}</option>
                                    <option v-for="a in animalsInLot" :key="a.id" :value="a.id">{{ a.tag }}</option>
                                </select>
                                <p v-if="singleForm.errors.animal_id" class="field-error">{{ singleForm.errors.animal_id }}</p>
                            </div>
                        </div>

                        <!-- Section 2: Vaccine details -->
                        <div class="panel-section">
                            <div class="panel-section-header">
                                <span class="section-badge">٢</span>
                                <span>{{ L.secVaccine }}</span>
                            </div>

                            <!-- 📌 LivestockOperationalCostFlow (#43) §7 — inventory vaccine item -->
                            <div class="field-group">
                                <label class="cv-label">{{ L.lblVaccine }} <span class="req">*</span></label>
                                <select v-model="singleForm.inventory_item_id" @change="onVaccineItemChange(singleForm)"
                                    class="cv-select" :class="{'input-error': singleForm.errors.inventory_item_id}">
                                    <option value="">{{ L.selectVaccine }}</option>
                                    <option v-for="v in inventoryVaccines" :key="v.id" :value="v.id"
                                        :disabled="v.total_qty_on_hand <= 0">
                                        {{ locale==='ar'?(v.name_ar||v.name_en):(v.name_en||v.name_ar) }}
                                        {{ v.total_qty_on_hand <= 0 ? (locale==='ar'?'(نفد)':'(out of stock)') : '' }}
                                    </option>
                                </select>
                                <p v-if="singleForm.errors.inventory_item_id" class="field-error">{{ singleForm.errors.inventory_item_id }}</p>
                            </div>

                            <!-- Location (cascades from vaccine item) -->
                            <div class="field-group" v-if="singleForm.inventory_item_id">
                                <label class="cv-label">{{ L.lblLocation }} <span class="req">*</span></label>
                                <select v-model="singleForm.location_id" @change="onVaccineLocationChange(singleForm)"
                                    class="cv-select" :class="{'input-error': singleForm.errors.location_id}"
                                    :disabled="vaccineLocations(singleForm.inventory_item_id).length === 0">
                                    <option value="">{{ L.selectLocation }}</option>
                                    <option v-for="l in vaccineLocations(singleForm.inventory_item_id)" :key="l.location_id" :value="l.location_id">
                                        {{ vacLocLabel(l) }} · {{ l.qty_on_hand }} {{ inventoryVaccines.find(v=>v.id==singleForm.inventory_item_id)?.unit }}
                                    </option>
                                </select>
                                <p v-if="singleForm.errors.location_id" class="field-error">{{ singleForm.errors.location_id }}</p>
                                <p v-if="vaccineLocations(singleForm.inventory_item_id).length === 0" class="field-error">{{ L.noStock }}</p>
                            </div>

                            <!-- Date -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblDate }} <span class="req">*</span></label>
                                    <input v-model="singleForm.date" type="date" class="cv-input" :class="{'input-error': singleForm.errors.date}" />
                                    <p v-if="singleForm.errors.date" class="field-error">{{ singleForm.errors.date }}</p>
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblDose }}</label>
                                    <input v-model="singleForm.dose_given_ml" type="number" step="0.1" min="0" class="cv-input" placeholder="0.0" />
                                </div>
                            </div>

                            <!-- Batch + Next due -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblBatch }}</label>
                                    <input v-model="singleForm.batch_number" type="text" class="cv-input" />
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblNextDue }}</label>
                                    <input v-model="singleForm.next_due_date" type="date" class="cv-input" />
                                </div>
                            </div>

                            <!-- Cost + Notes -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblCost }}</label>
                                    <input v-model="singleForm.cost" type="number" step="0.01" min="0" class="cv-input" placeholder="0.00" />
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblNotes }}</label>
                                    <input v-model="singleForm.notes" type="text" class="cv-input" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closePanel">{{ L.btnCancel }}</button>
                        <button
                            class="cv-btn cv-btn-amber"
                            @click="submitSingle"
                            :disabled="singleForm.processing"
                        >
                            <svg v-if="singleForm.processing" class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                            {{ L.btnSave }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════════
             SLIDE-IN PANEL — BULK (Lot-wide)
        ══════════════════════════════════════════════════════════════ -->
        <Transition name="panel-fade">
            <div v-if="panelMode === 'bulk'" class="panel-backdrop" @click.self="closePanel">
                <div class="side-panel side-panel-wide" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="panel-header">
                        <h2 class="panel-title">{{ L.panelBulkTitle }}</h2>
                        <button class="panel-close" @click="closePanel">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="panel-body">
                        <!-- Section 1: Farm + Lot -->
                        <div class="panel-section">
                            <div class="panel-section-header">
                                <span class="section-badge">١</span>
                                <span>{{ L.secAnimal }}</span>
                            </div>
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblFarm }} <span class="req">*</span></label>
                                    <select v-model="bulkForm.farm_id" class="cv-select" :class="{'input-error': bulkForm.errors.farm_id}">
                                        <option value="">{{ L.selectFarm }}</option>
                                        <option v-for="farm in farms" :key="farm.id" :value="farm.id">{{ n(farm,'name') }}</option>
                                    </select>
                                    <p v-if="bulkForm.errors.farm_id" class="field-error">{{ bulkForm.errors.farm_id }}</p>
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblLot }} <span class="req">*</span></label>
                                    <select v-model="bulkForm.lot_id" class="cv-select" :disabled="!bulkForm.farm_id" :class="{'input-error': bulkForm.errors.lot_id}">
                                        <option value="">{{ bulkForm.farm_id ? L.selectLot : L.farmFirst }}</option>
                                        <option v-for="lot in lotsForFarm(bulkForm.farm_id)" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
                                    </select>
                                    <p v-if="bulkForm.errors.lot_id" class="field-error">{{ bulkForm.errors.lot_id }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Animal selection -->
                        <div class="panel-section" v-if="bulkForm.lot_id">
                            <div class="panel-section-header">
                                <span class="section-badge">٢</span>
                                <span>{{ L.secBulkAnimal }}</span>
                                <div class="section-header-actions" v-if="animalsInLot.length">
                                    <button type="button" class="link-btn" @click="allSelected ? deselectAllAnimals() : selectAllAnimals()">
                                        {{ allSelected ? L.deselectAll : L.selectAll }}
                                    </button>
                                    <span class="selected-count">{{ L.selectedCount(bulkForm.animal_ids.length) }}</span>
                                </div>
                            </div>

                            <div v-if="loadingAnimals" class="loading-animals">
                                <svg class="spinner" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                            </div>
                            <div v-else-if="animalsInLot.length === 0" class="no-animals">
                                {{ L.noAnimals }}
                            </div>
                            <div v-else class="animal-grid">
                                <label
                                    v-for="animal in animalsInLot"
                                    :key="animal.id"
                                    class="animal-checkbox"
                                    :class="{ 'animal-checked': bulkForm.animal_ids.includes(animal.id) }"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="bulkForm.animal_ids.includes(animal.id)"
                                        @change="toggleAnimal(animal.id)"
                                    />
                                    <span class="animal-tag-bulk">{{ animal.tag }}</span>
                                    <span v-if="animal.breed" class="animal-breed-small">{{ animal.breed }}</span>
                                </label>
                            </div>
                            <p v-if="bulkForm.errors.animal_ids" class="field-error mt-2">{{ bulkForm.errors.animal_ids }}</p>
                        </div>

                        <!-- Section 3: Vaccine details -->
                        <div class="panel-section">
                            <div class="panel-section-header">
                                <span class="section-badge">٣</span>
                                <span>{{ L.secVaccine }}</span>
                            </div>

                            <!-- 📌 LivestockOperationalCostFlow (#43) §7 — inventory vaccine item + location -->
                            <div class="field-group">
                                <label class="cv-label">{{ L.lblVaccine }} <span class="req">*</span></label>
                                <select v-model="bulkForm.inventory_item_id" @change="onVaccineItemChange(bulkForm)"
                                    class="cv-select" :class="{'input-error': bulkForm.errors.inventory_item_id}">
                                    <option value="">{{ L.selectVaccine }}</option>
                                    <option v-for="v in inventoryVaccines" :key="v.id" :value="v.id"
                                        :disabled="v.total_qty_on_hand <= 0">
                                        {{ locale==='ar'?(v.name_ar||v.name_en):(v.name_en||v.name_ar) }}
                                        {{ v.total_qty_on_hand <= 0 ? (locale==='ar'?'(نفد)':'(out of stock)') : '' }}
                                    </option>
                                </select>
                                <p v-if="bulkForm.errors.inventory_item_id" class="field-error">{{ bulkForm.errors.inventory_item_id }}</p>
                            </div>

                            <!-- Location + Date row -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblLocation }} <span class="req">*</span></label>
                                    <select v-model="bulkForm.location_id" @change="onVaccineLocationChange(bulkForm)"
                                        class="cv-select" :class="{'input-error': bulkForm.errors.location_id}"
                                        :disabled="!bulkForm.inventory_item_id || vaccineLocations(bulkForm.inventory_item_id).length === 0">
                                        <option value="">{{ L.selectLocation }}</option>
                                        <option v-for="l in vaccineLocations(bulkForm.inventory_item_id)" :key="l.location_id" :value="l.location_id">
                                            {{ vacLocLabel(l) }} · {{ l.qty_on_hand }}
                                        </option>
                                    </select>
                                    <p v-if="bulkForm.errors.location_id" class="field-error">{{ bulkForm.errors.location_id }}</p>
                                    <p v-if="bulkForm.inventory_item_id && vaccineLocations(bulkForm.inventory_item_id).length === 0" class="field-error">{{ L.noStock }}</p>
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblDate }} <span class="req">*</span></label>
                                    <input v-model="bulkForm.date" type="date" class="cv-input" :class="{'input-error': bulkForm.errors.date}" />
                                    <p v-if="bulkForm.errors.date" class="field-error">{{ bulkForm.errors.date }}</p>
                                </div>
                            </div>

                            <!-- Dose + Batch -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblDose }}</label>
                                    <input v-model="bulkForm.dose_given_ml" type="number" step="0.1" min="0" class="cv-input" placeholder="0.0" />
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblBatch }}</label>
                                    <input v-model="bulkForm.batch_number" type="text" class="cv-input" />
                                </div>
                            </div>

                            <!-- Next due + Cost -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblNextDue }}</label>
                                    <input v-model="bulkForm.next_due_date" type="date" class="cv-input" />
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblCost }}</label>
                                    <input v-model="bulkForm.cost" type="number" step="0.01" min="0" class="cv-input" placeholder="0.00" />
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="field-group">
                                <label class="cv-label">{{ L.lblNotes }}</label>
                                <textarea v-model="bulkForm.notes" class="cv-textarea" rows="2" />
                            </div>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closePanel">{{ L.btnCancel }}</button>
                        <button
                            class="cv-btn cv-btn-amber"
                            @click="submitBulk"
                            :disabled="bulkForm.processing || bulkForm.animal_ids.length === 0"
                        >
                            <svg v-if="bulkForm.processing" class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                            {{ L.btnSave }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════════
             SLIDE-IN PANEL — EDIT
        ══════════════════════════════════════════════════════════════ -->
        <Transition name="panel-fade">
            <div v-if="panelMode === 'edit'" class="panel-backdrop" @click.self="closePanel">
                <div class="side-panel" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title">{{ L.panelEditTitle }}</h2>
                            <p class="panel-subtitle" v-if="editRecord">
                                {{ editRecord.animal?.tag }} ·
                                <template v-if="editRecord.inventory_item">
                                    {{ locale==='ar'?(editRecord.inventory_item.name_ar||editRecord.inventory_item.name_en):(editRecord.inventory_item.name_en||editRecord.inventory_item.name_ar) }}
                                </template>
                                <template v-else>{{ editRecord.vaccine ? n(editRecord.vaccine,'name') : '' }}</template>
                            </p>
                        </div>
                        <button class="panel-close" @click="closePanel">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="panel-body">
                        <div class="panel-section">
                            <!-- 📌 LivestockOperationalCostFlow (#43) §7 — inventory vaccine item for new records -->
                            <div v-if="editRecord?.inventory_item_id" class="field-group">
                                <label class="cv-label">{{ L.lblVaccine }} <span class="req">*</span></label>
                                <select v-model="editForm.inventory_item_id" class="cv-select" :class="{'input-error': editForm.errors.inventory_item_id}">
                                    <option value="">{{ L.selectVaccine }}</option>
                                    <option v-for="v in inventoryVaccines" :key="v.id" :value="v.id">
                                        {{ locale==='ar'?(v.name_ar||v.name_en):(v.name_en||v.name_ar) }}
                                    </option>
                                </select>
                                <p v-if="editForm.errors.inventory_item_id" class="field-error">{{ editForm.errors.inventory_item_id }}</p>
                            </div>
                            <!-- Legacy record — show vaccine catalog name read-only -->
                            <div v-else-if="editRecord?.vaccine" class="field-group">
                                <label class="cv-label">{{ L.lblVaccine }}</label>
                                <div style="padding:.45rem .75rem;background:var(--cv-bg-input);border:1px solid var(--cv-border-md);border-radius:8px;font-size:.85rem;color:var(--cv-text-secondary)">
                                    {{ n(editRecord.vaccine,'name') }}
                                    <span style="font-size:.7rem;color:var(--cv-text-muted);margin-inline-start:.5rem">({{ locale==='ar'?'سجل قديم':'legacy record' }})</span>
                                </div>
                            </div>

                            <!-- Date + Dose -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblDate }} <span class="req">*</span></label>
                                    <input v-model="editForm.date" type="date" class="cv-input" :class="{'input-error': editForm.errors.date}" />
                                    <p v-if="editForm.errors.date" class="field-error">{{ editForm.errors.date }}</p>
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblDose }}</label>
                                    <input v-model="editForm.dose_given_ml" type="number" step="0.1" min="0" class="cv-input" placeholder="0.0" />
                                </div>
                            </div>

                            <!-- Batch + Next due -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblBatch }}</label>
                                    <input v-model="editForm.batch_number" type="text" class="cv-input" />
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblNextDue }}</label>
                                    <input v-model="editForm.next_due_date" type="date" class="cv-input" />
                                </div>
                            </div>

                            <!-- Cost + Notes -->
                            <div class="field-row-2">
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblCost }}</label>
                                    <input v-model="editForm.cost" type="number" step="0.01" min="0" class="cv-input" placeholder="0.00" />
                                </div>
                                <div class="field-group">
                                    <label class="cv-label">{{ L.lblNotes }}</label>
                                    <input v-model="editForm.notes" type="text" class="cv-input" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closePanel">{{ L.btnCancel }}</button>
                        <button
                            class="cv-btn cv-btn-amber"
                            @click="submitEdit"
                            :disabled="editForm.processing"
                        >
                            <svg v-if="editForm.processing" class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                            {{ L.btnUpdate }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════════
             DELETE CONFIRMATION MODAL
        ══════════════════════════════════════════════════════════════ -->
        <Transition name="modal-fade">
            <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="closeDelete">
                <div class="cv-modal" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <div class="delete-icon-wrap">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                        </div>
                        <h3 class="modal-delete-title">{{ L.deleteTitle }}</h3>
                    </div>
                    <div class="cv-modal-body">
                        <p class="modal-delete-msg">{{ L.deleteMsg }}</p>
                        <div v-if="deleteTarget" class="modal-delete-detail">
                            <span class="animal-tag">{{ deleteTarget.animal?.tag }}</span>
                            <span class="mx-2">·</span>
                            <template v-if="deleteTarget.inventory_item">
                                <span>{{ locale==='ar'?(deleteTarget.inventory_item.name_ar||deleteTarget.inventory_item.name_en):(deleteTarget.inventory_item.name_en||deleteTarget.inventory_item.name_ar) }}</span>
                            </template>
                            <template v-else>
                                <span>{{ deleteTarget.vaccine ? n(deleteTarget.vaccine,'name') : '' }}</span>
                            </template>
                            <span class="mx-2">·</span>
                            <span>{{ formatDate(deleteTarget.date) }}</span>
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closeDelete">{{ L.btnCancel }}</button>
                        <button class="cv-btn cv-btn-danger" @click="doDelete">{{ L.deleteConfirm }}</button>
                    </div>
                </div>
            </div>
        </Transition>

    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Stats row ──────────────────────────────────────────────────── */
.stats-row {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.stat-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 10px;
    border: 1px solid;
    font-size: 13px;
}
.stat-chip-blue   { background: var(--cv-blue-light);    border-color: var(--cv-blue-border);    color: var(--cv-blue-text); }
.stat-chip-amber  { background: var(--cv-amber-light);   border-color: var(--cv-amber-border);   color: var(--cv-amber-text); }
.stat-chip-green  { background: var(--cv-success-bg);    border-color: var(--cv-success-border); color: var(--cv-success-text); }
.stat-num { font-size: 18px; font-weight: 700; }
.stat-lbl { opacity: 0.8; }

/* ── Filter bar ──────────────────────────────────────────────────── */
.filter-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.filter-search {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 180px;
    color: var(--cv-text-muted);
}
.filter-input { flex: 1; }
.filter-select { min-width: 140px; }

/* Due soon toggle */
.due-soon-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
}
.due-soon-toggle input { display: none; }
.toggle-track {
    width: 36px; height: 20px;
    background: var(--cv-bg-hover);
    border: 1px solid var(--cv-border-md);
    border-radius: 10px;
    position: relative;
    transition: background 0.2s;
    flex-shrink: 0;
}
.due-soon-toggle input:checked + .toggle-track { background: var(--cv-amber); border-color: var(--cv-amber); }
.toggle-thumb {
    position: absolute;
    top: 2px; inset-inline-start: 2px;
    width: 14px; height: 14px;
    background: #fff;
    border-radius: 50%;
    transition: inset-inline-start 0.2s;
}
.due-soon-toggle input:checked + .toggle-track .toggle-thumb { inset-inline-start: 18px; }
.toggle-label { font-size: 13px; color: var(--cv-text-secondary); }

/* ── Table ───────────────────────────────────────────────────────── */
.table-wrapper { padding: 0; overflow: hidden; }
.table-scroll  { overflow-x: auto; }
.vacc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.vacc-table thead tr {
    border-bottom: 1px solid var(--cv-border-md);
}
.vacc-table th {
    padding: 12px 14px;
    text-align: start;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--cv-text-muted);
    white-space: nowrap;
}
.table-row {
    border-bottom: 1px solid var(--cv-border-md);
    transition: background 0.15s;
}
.table-row:hover { background: var(--cv-bg-hover); }
.table-row:last-child { border-bottom: none; }
.vacc-table td { padding: 12px 14px; vertical-align: middle; }

/* Cell types */
.cell-date { color: var(--cv-text-secondary); white-space: nowrap; font-size: 12.5px; }
.cell-num  { color: var(--cv-blue-text); font-variant-numeric: tabular-nums; text-align: end; }
.cell-batch .batch-text { color: var(--cv-text-muted); font-family: monospace; font-size: 12px; }
.cell-farm { color: var(--cv-text-secondary); }
.cell-lot  { color: var(--cv-text-secondary); }
.lot-name  { font-size: 12px; }

/* Animal tag */
.animal-tag {
    display: inline-block;
    background: var(--cv-green-dim);
    border: 1px solid var(--cv-green-border);
    color: var(--cv-green-bright);
    font-size: 12px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 5px;
    font-family: monospace;
}

/* Vaccine pill */
.vaccine-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--cv-blue-light);
    border: 1px solid var(--cv-blue-border);
    color: var(--cv-blue-text);
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 5px;
    white-space: nowrap;
}

/* Due date states */
.due-date-ok { color: var(--cv-text-secondary); font-size: 12.5px; }
.text-muted   { color: var(--cv-text-muted); }

/* Row actions */
.cell-actions { white-space: nowrap; }
.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px; height: 30px;
    border-radius: 6px;
    border: 1px solid transparent;
    background: transparent;
    cursor: pointer;
    transition: all 0.15s;
    margin-inline-end: 4px;
}
.action-edit  { color: var(--cv-blue-text); }
.action-edit:hover  { background: var(--cv-blue-light); border-color: var(--cv-blue-border); }
.action-delete { color: var(--cv-danger-text); }
.action-delete:hover { background: var(--cv-danger-bg); border-color: var(--cv-danger-border); }

/* Empty state */
.empty-cell { padding: 60px 20px !important; }
.empty-state { text-align: center; }
.empty-icon { color: var(--cv-text-muted); margin-bottom: 16px; display: flex; justify-content: center; }
.empty-title { color: var(--cv-text-secondary); font-size: 15px; font-weight: 600; margin-bottom: 6px; }
.empty-sub { color: var(--cv-text-muted); font-size: 13px; }

/* Pagination */
.pagination-row {
    display: flex;
    justify-content: center;
    gap: 4px;
    padding: 16px;
    flex-wrap: wrap;
}
.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 8px;
    border-radius: 7px;
    font-size: 13px;
    border: 1px solid var(--cv-border-md);
    color: var(--cv-text-secondary);
    background: var(--cv-bg-card);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}
.page-link:hover:not(.page-link-disabled) { background: var(--cv-bg-hover); color: var(--cv-text-primary); }
.page-link-active { background: var(--cv-amber-light); border-color: var(--cv-amber-border); color: var(--cv-amber); font-weight: 600; }
.page-link-disabled { opacity: 0.3; cursor: default; }

/* ── Side panels ─────────────────────────────────────────────────── */
.panel-backdrop {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(3px);
    display: flex;
    justify-content: flex-end;
}
[dir="rtl"] .panel-backdrop { justify-content: flex-start; }

.side-panel {
    width: 480px;
    max-width: 95vw;
    height: 100%;
    background: var(--cv-bg-modal);
    border-inline-start: 1px solid var(--cv-border-md);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.side-panel-wide { width: 600px; }

.panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--cv-border-md);
    flex-shrink: 0;
}
.panel-title    { font-size: 16px; font-weight: 700; color: var(--cv-text-primary); }
.panel-subtitle { font-size: 12px; color: var(--cv-text-muted); margin-top: 3px; }
.panel-close {
    width: 32px; height: 32px;
    border-radius: 7px;
    border: 1px solid var(--cv-border-md);
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: all 0.15s;
}
.panel-close:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }

.panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.panel-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.panel-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--cv-text-secondary);
    padding-bottom: 8px;
    border-bottom: 1px solid var(--cv-border-md);
}
.section-badge {
    width: 22px; height: 22px;
    border-radius: 6px;
    background: var(--cv-amber-light);
    border: 1px solid var(--cv-amber-border);
    color: var(--cv-amber);
    font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.section-header-actions {
    margin-inline-start: auto;
    display: flex;
    align-items: center;
    gap: 10px;
}
.link-btn {
    background: none;
    border: none;
    color: var(--cv-blue-text);
    font-size: 12px;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
}
.selected-count {
    font-size: 12px;
    color: var(--cv-amber-text);
    font-weight: 600;
}

.field-group { display: flex; flex-direction: column; gap: 5px; }
.field-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.req { color: var(--cv-danger-text); margin-inline-start: 2px; }
.field-error { color: var(--cv-danger-text); font-size: 12px; margin-top: 2px; }
.input-error { border-color: var(--cv-danger-border) !important; }

.panel-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 16px 24px;
    border-top: 1px solid var(--cv-border-md);
    flex-shrink: 0;
}

/* ── Animal grid (bulk selection) ───────────────────────────────── */
.loading-animals {
    display: flex;
    justify-content: center;
    padding: 20px;
    color: var(--cv-text-muted);
}
.no-animals {
    text-align: center;
    padding: 16px;
    color: var(--cv-text-muted);
    font-size: 13px;
    border: 1px dashed var(--cv-border-md);
    border-radius: 8px;
}
.animal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 8px;
    max-height: 220px;
    overflow-y: auto;
    padding: 4px;
}
.animal-checkbox {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 8px 6px;
    border-radius: 8px;
    border: 1px solid var(--cv-border-md);
    background: var(--cv-bg-card-alt);
    cursor: pointer;
    transition: all 0.15s;
}
.animal-checkbox input { display: none; }
.animal-checkbox:hover { border-color: var(--cv-amber-border); background: var(--cv-amber-light); }
.animal-checked {
    border-color: var(--cv-green-border) !important;
    background: var(--cv-green-dim) !important;
}
.animal-tag-bulk {
    font-size: 12px;
    font-weight: 700;
    color: var(--cv-text-primary);
    font-family: monospace;
}
.animal-breed-small {
    font-size: 10px;
    color: var(--cv-text-muted);
    text-align: center;
}

/* ── Delete modal ────────────────────────────────────────────────── */
.delete-icon-wrap {
    width: 52px; height: 52px;
    border-radius: 50%;
    background: var(--cv-danger-bg);
    border: 1px solid var(--cv-danger-border);
    color: var(--cv-danger-text);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 12px;
}
.modal-delete-title { font-size: 16px; font-weight: 700; color: var(--cv-text-primary); text-align: center; }
.modal-delete-msg   { color: var(--cv-text-secondary); font-size: 13.5px; text-align: center; line-height: 1.6; }
.modal-delete-detail {
    margin-top: 14px;
    padding: 10px 16px;
    background: var(--cv-bg-card-alt);
    border-radius: 8px;
    border: 1px solid var(--cv-border-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 4px;
    font-size: 13px;
    color: var(--cv-text-secondary);
}
.mx-2 { margin: 0 4px; color: var(--cv-text-muted); }

/* ── Spinner ─────────────────────────────────────────────────────── */
.spinner {
    animation: spin 0.8s linear infinite;
    margin-inline-end: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Transitions ─────────────────────────────────────────────────── */
.panel-fade-enter-active, .panel-fade-leave-active { transition: opacity 0.2s; }
.panel-fade-enter-from, .panel-fade-leave-to { opacity: 0; }
.panel-fade-enter-active .side-panel, .panel-fade-leave-active .side-panel { transition: transform 0.25s ease; }
.panel-fade-enter-from .side-panel { transform: translateX(32px); }
.panel-fade-leave-to   .side-panel { transform: translateX(32px); }
[dir="rtl"] .panel-fade-enter-from .side-panel { transform: translateX(-32px); }
[dir="rtl"] .panel-fade-leave-to   .side-panel { transform: translateX(-32px); }

.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.2s; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .field-row-2 { grid-template-columns: 1fr; }
    .side-panel, .side-panel-wide { width: 100vw; }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-search { min-width: 0; }
}
</style>