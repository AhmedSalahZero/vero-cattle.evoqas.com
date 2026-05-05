<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Animals/Index.vue  (v3)
//
//  COMPONENT PLAN:
//  ─────────────────────────────────────────────────────────────────
//  DATA FROM CONTROLLER (props shape):
//    animals   → paginated collection of formatAnimal() objects
//    farms     → [{id, name_en, name_ar, company_id}]
//    lots      → [{id, name, farm_id}]
//    filters   → {farm_id, lot_id, status, sex, breed, tag}
//    user.permissions  → 'super_admin' | 'Company Admin' | ...
//
//  LAYOUT: Grouped collapsible table — grouped by purchase_date,
//    newest first. Each group header shows mini summary.
//    All groups collapsed by default except the first (newest).
//
//  TABLE COLUMNS:
//    # | Avatar+Tag | RFID | Sex | Breed | Farm/Lot |
//    Entry Wt. | Status | Age | Actions
//
//  FILTERS: tag, breed, farm_id, lot_id, status, sex
//
//  PERMISSION GUARDS:
//    create animals  → Add Animal CTA visible
//    edit animals    → Edit button visible
//    delete animals  → Delete button visible
//    super_admin     → sees all companies' animals
//
//  MOBILE / RTL:
//    isRtl → dir="rtl", table scrolls horizontally
//    Sticky first column on mobile
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    animals:  Object,
    farms:    Array,
    lots:     Array,
    filters:  Object,
})

const { locale, isRtl, n } = useLocale()

// ── Permission helpers ─────────────────────────────────────────
const { can: canPermission } = usePermissions()
const can = {
    create: computed(() => canPermission('create animals')),
    edit: computed(() => canPermission('edit animals')),
    delete: computed(() => canPermission('delete animals')),
}

// ── Filters ────────────────────────────────────────────────────
const filterForm = ref({
    tag:     props.filters?.tag     ?? '',
    breed:   props.filters?.breed   ?? '',
    farm_id: props.filters?.farm_id ?? '',
    lot_id:  props.filters?.lot_id  ?? '',
    status:  props.filters?.status  ?? '',
    sex:     props.filters?.sex     ?? '',
})

const filteredLots = computed(() => {
    if (!filterForm.value.farm_id) return props.lots ?? []
    return (props.lots ?? []).filter(l => String(l.farm_id) === String(filterForm.value.farm_id))
})

watch(() => filterForm.value.farm_id, () => {
    filterForm.value.lot_id = ''
})

let searchTimer = null
function applyFilters() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        router.get(route('animals.index'), filterForm.value, {
            preserveState: true,
            replace: true,
        })
    }, 350)
}

function clearFilters() {
    Object.assign(filterForm.value, { tag:'', breed:'', farm_id:'', lot_id:'', status:'', sex:'' })
    router.get(route('animals.index'), {}, { replace: true })
}

const hasActiveFilters = computed(() =>
    Object.values(filterForm.value).some(v => v !== '')
)

// ── Group animals by purchase_date, newest first ───────────────
const groupedAnimals = computed(() => {
    const data = props.animals?.data ?? []

    // Build map: date → animals[]
    const map = {}
    for (const a of data) {
        const key = a.purchase_date ?? '__no_date__'
        if (!map[key]) map[key] = []
        map[key].push(a)
    }

    // Sort dates descending (newest first); no-date group goes last
    const sorted = Object.entries(map).sort(([a], [b]) => {
        if (a === '__no_date__') return 1
        if (b === '__no_date__') return -1
        return b.localeCompare(a)   // ISO date strings sort correctly as strings
    })

    return sorted.map(([date, animals]) => ({
        date,
        animals,
        summary: buildSummary(animals),
    }))
})

// Build mini summary for group header
function buildSummary(animals) {
    const total   = animals.length
    const males   = animals.filter(a => a.sex === 'male').length
    const females = animals.filter(a => a.sex === 'female').length
    const cast    = animals.filter(a => a.sex === 'castrated').length
    const active  = animals.filter(a => a.status === 'active').length
    const farms   = [...new Set(animals.map(a => a.farm?.name_en ?? a.farm?.name_ar).filter(Boolean))]
    const lots    = [...new Set(animals.map(a => a.lot?.name).filter(Boolean))]
    const totalWt = animals.reduce((s, a) => s + (parseFloat(a.purchase_weight_kg) || 0), 0)

    return { total, males, females, cast, active, farms, lots, totalWt: totalWt.toFixed(0) }
}

// ── Collapsed state: first group open, rest collapsed ──────────
// Key: date string → boolean (true = collapsed)
const collapsed = ref({})

function initCollapsed() {
    const groups = groupedAnimals.value
    const next = {}
    groups.forEach((g, i) => {
        // Preserve existing state if already set, else: first = open, rest = collapsed
        next[g.date] = g.date in collapsed.value ? collapsed.value[g.date] : i !== 0
    })
    collapsed.value = next
}

watch(groupedAnimals, initCollapsed, { immediate: true })

function toggleGroup(date) {
    collapsed.value[date] = !collapsed.value[date]
}

function expandAll()   { Object.keys(collapsed.value).forEach(k => collapsed.value[k] = false) }
function collapseAll() { Object.keys(collapsed.value).forEach(k => collapsed.value[k] = true) }

const allExpanded  = computed(() => Object.values(collapsed.value).every(v => !v))
const allCollapsed = computed(() => Object.values(collapsed.value).every(v => v))

// ── Delete modal ───────────────────────────────────────────────
const deleteTarget    = ref(null)
const showDeleteModal = ref(false)

function confirmDelete(animal) {
    deleteTarget.value = animal
    showDeleteModal.value = true
}
function cancelDelete() {
    showDeleteModal.value = false
    deleteTarget.value = null
}
function executeDelete() {
    router.delete(route('animals.destroy', deleteTarget.value.id), {
        onSuccess: () => cancelDelete(),
    })
}

// ── Label helpers ──────────────────────────────────────────────
function sexLabel(sex) {
    if (locale.value === 'ar')
        return { male: 'ذكر', female: 'أنثى', castrated: 'مخصي' }[sex] ?? sex
    return { male: 'M', female: 'F', castrated: 'C' }[sex] ?? sex
}
function sexLabelFull(sex) {
    if (locale.value === 'ar')
        return { male: 'ذكر', female: 'أنثى', castrated: 'مخصي' }[sex] ?? sex
    return { male: 'Male', female: 'Female', castrated: 'Castrated' }[sex] ?? sex
}
function statusLabel(s) {
    if (locale.value === 'ar')
        return { active:'نشط', sold:'مباع', slaughtered:'مذبوح', dead:'نافق', quarantine:'حجر صحي', transferred:'محوَّل' }[s] ?? s
    return { active:'Active', sold:'Sold', slaughtered:'Slaughtered', dead:'Dead', quarantine:'Quarantine', transferred:'Transferred' }[s] ?? s
}
function sourceLabel(src) {
    if (locale.value === 'ar') return src === 'born_on_farm' ? 'مولود' : 'مشترى'
    return src === 'born_on_farm' ? 'Born' : 'Purchased'
}
function sexBadgeClass(sex) {
    return { male:'cv-badge cv-badge-info', female:'cv-badge cv-badge-green', castrated:'cv-badge cv-badge-neutral' }[sex] ?? 'cv-badge cv-badge-neutral'
}
function statusBadgeClass(s) {
    return {
        active:'cv-badge cv-badge-green', sold:'cv-badge cv-badge-info',
        slaughtered:'cv-badge cv-badge-neutral', dead:'cv-badge cv-badge-danger',
        quarantine:'cv-badge cv-badge-amber', transferred:'cv-badge cv-badge-neutral',
    }[s] ?? 'cv-badge cv-badge-neutral'
}

// Avatar
const PALETTES = [
    ['#1a6b3a','#4DBF7A'], ['#7c4a00','#f5a623'],
    ['#003d6b','#4da6ff'], ['#5c1a1a','#e05252'],
    ['#1a3a5c','#6bb0e8'], ['#3a1a5c','#a06be8'],
]
function avatarPalette(id) { return PALETTES[id % PALETTES.length] }
function animalInitials(tag) { return tag ? tag.slice(0,2).toUpperCase() : '??' }

function ageLabel(dob) {
    if (!dob) return '—'
    const months = Math.floor((Date.now() - new Date(dob).getTime()) / (1000*60*60*24*30.44))
    if (months < 24) return locale.value === 'ar' ? `${months}ش` : `${months}mo`
    return locale.value === 'ar' ? `${Math.floor(months/12)}س` : `${Math.floor(months/12)}yr`
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === '__no_date__') return locale.value === 'ar' ? 'بدون تاريخ شراء' : 'No Purchase Date'
    try {
        const d = new Date(dateStr)
        return d.toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
            day: 'numeric', month: 'long', year: 'numeric'
        })
    } catch { return dateStr }
}

// ── Stats for hero ─────────────────────────────────────────────
const stats = computed(() => {
    const data = props.animals?.data ?? []
    return {
        active:     data.filter(a => a.status === 'active').length,
        quarantine: data.filter(a => a.status === 'quarantine').length,
        total:      props.animals?.total ?? 0,
        groups:     groupedAnimals.value.length,
    }
})

// Labels
const L = computed(() => locale.value === 'ar' ? {
    moduleEyebrow: 'إدارة القطيع',
    pageTitle:     'سجل المواشي',
    subtitle:      'مجمّع حسب تاريخ الشراء — الأحدث أولاً',
    btnNew:        'إضافة مواشي',
    search:        'بحث بالرقم',
    breed:         'السلالة',
    farm:          'المزرعة',
    lot:           'المجموعة',
    status:        'الحالة',
    sex:           'الجنس',
    allFarms:      'كل المزارع',
    allLots:       'كل المجموعات',
    allStatuses:   'كل الحالات',
    allSexes:      'كل الأجناس',
    clear:         'مسح الفلاتر',
    colTag:        'الرقم',
    colRfid:       'RFID',
    colSex:        'الجنس',
    colBreed:      'السلالة',
    colFarmLot:    'المزرعة / المجموعة',
    colWeight:     'وزن الدخول',
    colStatus:     'الحالة',
    colAge:        'العمر',
    colActions:    'إجراءات',
    noAnimals:     'لا توجد مواشي ات تطابق هذه الفلاتر',
    statActive:    'نشط',
    statQuarantine:'حجر صحي',
    statTotal:     'الإجمالي',
    statGroups:    'مجموعة',
    editBtn:       'تعديل',
    viewBtn:       'عرض',
    deleteBtn:     'حذف',
    deleteTitle:   'تأكيد الحذف',
    deleteMsg:     (tag) => `هل أنت متأكد من حذف الماشية ذو الرقم "${tag}"؟ لا يمكن التراجع عن هذا الإجراء.`,
    cancelBtn:     'إلغاء',
    confirmDelete: 'حذف',
    sexMale:       'ذكر',
    sexFemale:     'أنثى',
    sexCastrated:  'مخصي',
    expandAll:     'فتح الكل',
    collapseAll:   'طي الكل',
    head:          'رأس',
    kg:            'كجم',
    summaryActive: 'نشط',
    purchased:     'مشترى',
    noDate:        'بدون تاريخ شراء',
    pagination:    (from, to, total) => `عرض ${from}–${to} من ${total}`,
} : {
    moduleEyebrow: 'Herd Management',
    pageTitle:     'Cattle Register',
    subtitle:      'Grouped by purchase date — newest first',
    btnNew:        'Add Cattle',
    search:        'Search by tag',
    breed:         'Breed',
    farm:          'Farm',
    lot:           'Lot',
    status:        'Status',
    sex:           'Sex',
    allFarms:      'All Farms',
    allLots:       'All Lots',
    allStatuses:   'All Statuses',
    allSexes:      'All Sexes',
    clear:         'Clear Filters',
    colTag:        'Tag',
    colRfid:       'RFID',
    colSex:        'Sex',
    colBreed:      'Breed',
    colFarmLot:    'Farm / Lot',
    colWeight:     'Entry Wt.',
    colStatus:     'Status',
    colAge:        'Age',
    colActions:    'Actions',
    noAnimals:     'No animals match the current filters',
    statActive:    'Active',
    statQuarantine:'Quarantine',
    statTotal:     'Total',
    statGroups:    'batches',
    editBtn:       'Edit',
    viewBtn:       'View',
    deleteBtn:     'Delete',
    deleteTitle:   'Confirm Delete',
    deleteMsg:     (tag) => `Are you sure you want to delete cattle "${tag}"? This cannot be undone.`,
    cancelBtn:     'Cancel',
    confirmDelete: 'Delete',
    sexMale:       'Male',
    sexFemale:     'Female',
    sexCastrated:  'Castrated',
    expandAll:     'Expand All',
    collapseAll:   'Collapse All',
    head:          'head',
    kg:            'kg',
    summaryActive: 'active',
    purchased:     'Purchased',
    noDate:        'No Purchase Date',
    pagination:    (from, to, total) => `Showing ${from}–${to} of ${total}`,
})

function goToPage(url) {
    if (url) router.visit(url, { preserveState: true })
}
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr' ">

            <!-- ── Hero ────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow" />
                <div class="cv-hero-inner">
                    <!-- SVG watermark -->
                    <div class="cv-hero-watermark" aria-hidden="true">
                        <svg viewBox="0 0 120 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M20 60 C20 60 10 55 8 42 C6 30 12 20 22 18 C28 16 30 10 36 8 C44 5 52 10 58 16 C64 22 70 22 76 20 C84 17 92 22 94 32 C96 42 90 52 82 56 C74 60 66 58 60 54 C54 50 46 52 40 56 C32 62 24 64 20 60Z" stroke="currentColor" stroke-width="1.5" opacity="0.15"/>
                            <circle cx="30" cy="28" r="4" stroke="currentColor" stroke-width="1.5" opacity="0.15"/>
                            <circle cx="72" cy="26" r="4" stroke="currentColor" stroke-width="1.5" opacity="0.15"/>
                            <path d="M20 60 L16 72 M24 62 L22 74 M80 56 L82 70 M84 54 L88 68" stroke="currentColor" stroke-width="1.5" opacity="0.1"/>
                        </svg>
                    </div>

                    <div class="cv-hero-content">
                        <div class="cv-page-hero-eyebrow">{{ L.moduleEyebrow }}</div>
                        <div class="cv-page-hero-title">{{ L.pageTitle }}</div>
                        <div class="cv-page-hero-sub">{{ L.subtitle }}</div>
                    </div>

                    <!-- Stat pills -->
                    <div class="cv-hero-stats">
                        <div class="cv-hero-stat">
                            <span class="cv-hero-stat-dot dot-green"></span>
                            <span class="cv-hero-stat-val">{{ stats.active }}</span>
                            <span class="cv-hero-stat-label">{{ L.statActive }}</span>
                        </div>
                        <div class="cv-hero-stat" v-if="stats.quarantine > 0">
                            <span class="cv-hero-stat-dot dot-amber"></span>
                            <span class="cv-hero-stat-val" style="color:var(--cv-amber)">{{ stats.quarantine }}</span>
                            <span class="cv-hero-stat-label">{{ L.statQuarantine }}</span>
                        </div>
                        <div class="cv-hero-stat">
                            <span class="cv-hero-stat-dot dot-blue"></span>
                            <span class="cv-hero-stat-val" style="color:var(--cv-blue-text)">{{ stats.total }}</span>
                            <span class="cv-hero-stat-label">{{ L.statTotal }}</span>
                        </div>
                        <div class="cv-hero-stat" v-if="stats.groups > 1">
                            <span class="cv-hero-stat-dot" style="background:#a06be8;box-shadow:0 0 6px #a06be8"></span>
                            <span class="cv-hero-stat-val" style="color:#a06be8">{{ stats.groups }}</span>
                            <span class="cv-hero-stat-label">{{ L.statGroups }}</span>
                        </div>
                    </div>

                    <div class="cv-page-hero-actions">
                        <Link
                            v-if="can.create.value"
                            :href="route('animals.create')"
                            class="cv-btn cv-btn-amber"
                        >
                            <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            {{ L.btnNew }}
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ── Filter Bar ───────────────────────────────────── -->
            <div class="cv-filter-bar">
                <div class="cv-filter-search">
                    <div class="cv-input-icon-wrap">
                        <span class="cv-input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                            </svg>
                        </span>
                        <input v-model="filterForm.tag" @input="applyFilters" class="cv-input cv-input-with-icon" :placeholder="L.search" />
                    </div>
                </div>
                <div class="cv-filter-field">
                    <input v-model="filterForm.breed" @input="applyFilters" class="cv-input" :placeholder="L.breed" />
                </div>
                <div class="cv-filter-field">
                    <select v-model="filterForm.farm_id" @change="applyFilters" class="cv-select">
                        <option value="">{{ L.allFarms }}</option>
                        <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                    </select>
                </div>
                <div class="cv-filter-field">
                    <select v-model="filterForm.lot_id" @change="applyFilters" class="cv-select">
                        <option value="">{{ L.allLots }}</option>
                        <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                    </select>
                </div>
                <div class="cv-filter-field">
                    <select v-model="filterForm.status" @change="applyFilters" class="cv-select">
                        <option value="">{{ L.allStatuses }}</option>
                        <option value="active">{{ L.statActive }}</option>
                        <option value="quarantine">{{ L.statQuarantine }}</option>
                        <option value="sold">{{ locale === 'ar' ? 'مباع' : 'Sold' }}</option>
                        <option value="slaughtered">{{ locale === 'ar' ? 'مذبوح' : 'Slaughtered' }}</option>
                        <option value="dead">{{ locale === 'ar' ? 'نافق' : 'Dead' }}</option>
                        <option value="transferred">{{ locale === 'ar' ? 'محوَّل' : 'Transferred' }}</option>
                    </select>
                </div>
                <div class="cv-filter-field">
                    <select v-model="filterForm.sex" @change="applyFilters" class="cv-select">
                        <option value="">{{ L.allSexes }}</option>
                        <option value="male">{{ L.sexMale }}</option>
                        <option value="female">{{ L.sexFemale }}</option>
                        <option value="castrated">{{ L.sexCastrated }}</option>
                    </select>
                </div>
                <button v-if="hasActiveFilters" class="cv-btn cv-btn-ghost cv-btn-sm" @click="clearFilters">
                    <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                    {{ L.clear }}
                </button>
            </div>

            <!-- ── Page Body ─────────────────────────────────────── -->
            <div class="cv-page-body">

                <!-- Empty state -->
                <div v-if="!animals?.data?.length" class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 50 C15 50 8 46 6 36 C4 26 10 17 20 15 C26 13 28 8 34 6 C42 3 50 8 56 14 C62 20 68 20 74 18" stroke="currentColor" stroke-width="2" opacity="0.3" stroke-linecap="round"/>
                            <circle cx="26" cy="24" r="4" stroke="currentColor" stroke-width="2" opacity="0.3"/>
                            <path d="M35 55 L45 45 M45 55 L35 45" stroke="currentColor" stroke-width="2.5" opacity="0.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="cv-empty-text">{{ L.noAnimals }}</div>
                </div>

                <!-- Expand / Collapse all controls -->
                <div v-else class="cv-table-controls">
                    <button v-if="!allExpanded"  class="cv-btn cv-btn-ghost cv-btn-xs" @click="expandAll">
                        <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/><path d="M6 3h12"/><path d="M6 21h12"/></svg>
                        {{ L.expandAll }}
                    </button>
                    <button v-if="!allCollapsed" class="cv-btn cv-btn-ghost cv-btn-xs" @click="collapseAll">
                        <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/><path d="M6 3h12"/><path d="M6 21h12"/></svg>
                        {{ L.collapseAll }}
                    </button>
                </div>

                <!-- ── Grouped Tables ──────────────────────────── -->
                <div v-for="group in groupedAnimals" :key="group.date" class="cv-group-block">

                    <!-- Group Header (always visible) -->
                    <button
                        class="cv-group-header"
                        :class="{ 'cv-group-header--open': !collapsed[group.date] }"
                        @click="toggleGroup(group.date)"
                        type="button"
                    >
                        <!-- Left: chevron + date -->
                        <div class="cv-group-header-left">
                            <span class="cv-group-chevron" :class="{ 'cv-group-chevron--open': !collapsed[group.date] }">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 18 15 12 9 6"/>
                                </svg>
                            </span>
                            <div class="cv-group-date-block">
                                <span class="cv-group-date">{{ formatDate(group.date) }}</span>
                                <span class="cv-group-count-pill">{{ group.summary.total }} {{ L.head }}</span>
                            </div>
                        </div>

                        <!-- Right: mini summary chips -->
                        <div class="cv-group-summary">
                            <!-- Sex breakdown -->
                            <span v-if="group.summary.males > 0"    class="cv-summary-chip cv-chip-blue">
                                ♂ {{ group.summary.males }}
                            </span>
                            <span v-if="group.summary.females > 0"  class="cv-summary-chip cv-chip-green">
                                ♀ {{ group.summary.females }}
                            </span>
                            <span v-if="group.summary.cast > 0"     class="cv-summary-chip cv-chip-muted">
                                ⚬ {{ group.summary.cast }}
                            </span>
                            <!-- Active count -->
                            <span v-if="group.summary.active > 0" class="cv-summary-chip cv-chip-active">
                                ● {{ group.summary.active }} {{ L.summaryActive }}
                            </span>
                            <!-- Total weight -->
                            <span v-if="group.summary.totalWt > 0" class="cv-summary-chip cv-chip-weight">
                                ⚖ {{ Number(group.summary.totalWt).toLocaleString() }} {{ L.kg }}
                            </span>
                            <!-- Farms -->
                            <span
                                v-for="farm in group.summary.farms.slice(0,2)"
                                :key="farm"
                                class="cv-summary-chip cv-chip-farm"
                            >
                                🏠 {{ farm }}
                            </span>
                            <span v-if="group.summary.farms.length > 2" class="cv-summary-chip cv-chip-muted">
                                +{{ group.summary.farms.length - 2 }}
                            </span>
                        </div>
                    </button>

                    <!-- Collapsible Table Body -->
                    <div class="cv-group-body" :class="{ 'cv-group-body--collapsed': collapsed[group.date] }">
                        <div class="cv-table-scroll">
                            <table class="cv-animal-table">
                                <thead>
                                    <tr>
                                        <th class="cv-th cv-th-num">#</th>
                                        <th class="cv-th cv-th-tag">{{ L.colTag }}</th>
                                        <th class="cv-th">{{ L.colRfid }}</th>
                                        <th class="cv-th">{{ L.colSex }}</th>
                                        <th class="cv-th">{{ L.colBreed }}</th>
                                        <th class="cv-th">{{ L.colFarmLot }}</th>
                                        <th class="cv-th cv-th-right">{{ L.colWeight }}</th>
                                        <th class="cv-th">{{ L.colStatus }}</th>
                                        <th class="cv-th cv-th-right">{{ L.colAge }}</th>
                                        <th class="cv-th cv-th-actions">{{ L.colActions }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(animal, idx) in group.animals"
                                        :key="animal.id"
                                        class="cv-tr"
                                        :class="{ 'cv-tr-even': idx % 2 === 1 }"
                                    >
                                        <!-- # -->
                                        <td class="cv-td cv-td-num">{{ idx + 1 }}</td>

                                        <!-- Tag + Avatar -->
                                        <td class="cv-td cv-td-tag">
                                            <div class="cv-tag-cell">
                                                <div class="cv-avatar-sm" :style="{ background: `linear-gradient(135deg, ${avatarPalette(animal.id)[0]}, ${avatarPalette(animal.id)[1]})` }">
                                                    <img v-if="animal.photo" :src="animal.photo" :alt="animal.tag" class="cv-avatar-img" />
                                                    <span v-else>{{ animalInitials(animal.tag) }}</span>
                                                </div>
                                                <div class="cv-tag-text-block">
                                                    <Link :href="route('animals.show', animal.id)" class="cv-tag-link">
                                                        {{ animal.tag }}
                                                    </Link>
                                                    <span v-if="animal.source === 'born_on_farm'" class="cv-born-badge">
                                                        {{ sourceLabel(animal.source) }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- RFID -->
                                        <td class="cv-td cv-td-mono">
                                            <span v-if="animal.rfid" class="cv-rfid-text">{{ animal.rfid }}</span>
                                            <span v-else class="cv-td-empty">—</span>
                                        </td>

                                        <!-- Sex -->
                                        <td class="cv-td">
                                            <span :class="sexBadgeClass(animal.sex)">{{ sexLabel(animal.sex) }}</span>
                                        </td>

                                        <!-- Breed -->
                                        <td class="cv-td cv-td-breed">
                                            <span v-if="animal.breed">{{ animal.breed }}</span>
                                            <span v-else class="cv-td-empty">—</span>
                                        </td>

                                        <!-- Farm / Lot -->
                                        <td class="cv-td">
                                            <div class="cv-farm-lot-cell">
                                                <span class="cv-farm-name">{{ animal.farm ? n(animal.farm) : '—' }}</span>
                                                <span v-if="animal.lot" class="cv-lot-name">{{ animal.lot.name }}</span>
                                            </div>
                                        </td>

                                        <!-- Entry Weight -->
                                        <td class="cv-td cv-td-num">
                                            <span v-if="animal.purchase_weight_kg" class="cv-weight-val">
                                                {{ animal.purchase_weight_kg }}<small> {{ L.kg }}</small>
                                            </span>
                                            <span v-else class="cv-td-empty">—</span>
                                        </td>

                                        <!-- Status -->
                                        <td class="cv-td">
                                            <span :class="statusBadgeClass(animal.status)">{{ statusLabel(animal.status) }}</span>
                                        </td>

                                        <!-- Age -->
                                        <td class="cv-td cv-td-num">
                                            <span class="cv-age-val">
                                                {{ ageLabel(animal.dob) }}
                                                <em v-if="animal.dob_estimated" class="cv-est-mark">~</em>
                                            </span>
                                        </td>

                                        <!-- Actions -->
                                        <td class="cv-td cv-td-actions">
                                            <div class="cv-action-row">
                                                <Link :href="route('animals.show', animal.id)" class="cv-action-btn cv-action-btn-blue" :title="L.viewBtn">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                                    </svg>
                                                </Link>
                                                <Link v-if="can.edit.value" :href="route('animals.edit', animal.id)" class="cv-action-btn" :title="L.editBtn">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                    </svg>
                                                </Link>
                                                <button v-if="can.delete.value" class="cv-action-btn cv-action-btn-danger" :title="L.deleteBtn" @click="confirmDelete(animal)">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div><!-- /group -->

                <!-- ── Pagination ─────────────────────────────── -->
                <div v-if="animals?.last_page > 1" class="cv-pagination">
                    <button class="cv-btn cv-btn-ghost cv-btn-sm" :disabled="!animals.prev_page_url" @click="goToPage(animals.prev_page_url)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>
                    </button>
                    <span class="cv-pagination-info">
                        {{ L.pagination(animals.from, animals.to, animals.total) }}
                    </span>
                    <button class="cv-btn cv-btn-ghost cv-btn-sm" :disabled="!animals.next_page_url" @click="goToPage(animals.next_page_url)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </button>
                </div>

            </div><!-- /cv-page-body -->

        </div><!-- /cv-page -->

        <!-- ── Delete Confirmation Modal ─────────────────────── -->
        <Teleport to="body">
            <Transition name="cv-modal-fade">
                <div v-if="showDeleteModal" class="cv-modal-backdrop" @click.self="cancelDelete">
                    <div class="cv-modal" role="dialog" :aria-label="L.deleteTitle">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <h3 class="cv-modal-title">{{ L.deleteTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <div class="cv-alert-danger">
                                {{ L.deleteMsg(deleteTarget?.tag) }}
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="cancelDelete">{{ L.cancelBtn }}</button>
                            <button class="cv-btn cv-btn-danger" @click="executeDelete">{{ L.confirmDelete }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

    </AuthenticatedLayout>
</template>

<style scoped>
/* ── CSS Variables (inherits from global theme) ──────────── */
/* --cv-bg-base, --cv-bg-card, --cv-bg-hover                 */
/* --cv-border, --cv-text-primary, --cv-text-muted           */
/* --cv-green-bright, --cv-amber, --cv-blue-text             */
/* --cv-danger-text                                           */



/* ── Page layout ─────────────────────────────────────────── */
.cv-page { min-height: 100vh; background: var(--cv-bg-base); display: flex; flex-direction: column; }
.cv-page-body { padding: 1.25rem 1.5rem 3rem; display: flex; flex-direction: column; gap: 0.75rem; }

/* ── Hero ────────────────────────────────────────────────── */
.cv-page-hero {
    background: linear-gradient(135deg, #0a1f11 0%, #0d2b18 60%, #0e1f13 100%);
    border-bottom: 1px solid rgba(77,191,122,0.12);
    padding: 2rem 2rem 1.5rem;
    position: relative; overflow: hidden;
}
.cv-page-hero::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(ellipse 60% 80% at 10% 50%, rgba(77,191,122,0.08), transparent);
    pointer-events: none;
}
.cv-page-hero::after {
    content: ''; position: absolute; inset: 0;
    background-image: radial-gradient(rgba(77,191,122,0.06) 1px, transparent 1px);
    background-size: 24px 24px; pointer-events: none;
}
.cv-hero-inner { position: relative; z-index: 1; display: flex; align-items: flex-start; flex-wrap: wrap; gap: 1.25rem; }
.cv-hero-content { flex: 1; min-width: 200px; }
.cv-hero-watermark {
    position: absolute; right: 2rem; top: 50%; transform: translateY(-50%);
    width: 160px; height: auto; color: var(--cv-green-bright); opacity: 0.6; pointer-events: none;
}
[dir="rtl"] .cv-hero-watermark { right: auto; left: 2rem; transform: translateY(-50%) scaleX(-1); }
.cv-page-hero-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--cv-amber); margin-bottom: 0.35rem; }
.cv-page-hero-title { font-size: 1.75rem; font-weight: 800; color: var(--cv-text-primary); line-height: 1.15; margin-bottom: 0.4rem; }
.cv-page-hero-sub { font-size: 0.875rem; color: var(--cv-text-muted); }
.cv-page-hero-actions { display: flex; align-items: center; gap: 0.75rem; margin-top: 0.25rem; }

.cv-hero-stats {
    display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;
    margin-top: 0.5rem; padding-top: 0.75rem;
    border-top: 1px solid rgba(77,191,122,0.1); width: 100%;
}
.cv-hero-stat { display: flex; align-items: center; gap: 0.5rem; }
.cv-hero-stat-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dot-green { background: var(--cv-green-bright); box-shadow: 0 0 6px var(--cv-green-bright); }
.dot-amber { background: var(--cv-amber); box-shadow: 0 0 6px var(--cv-amber); }
.dot-blue  { background: var(--cv-blue-text); box-shadow: 0 0 6px var(--cv-blue-text); }
.cv-hero-stat-val { font-size: 1rem; font-weight: 700; color: var(--cv-green-bright); }
.cv-hero-stat-label { font-size: 0.75rem; color: var(--cv-text-muted); }

/* ── Filter bar ──────────────────────────────────────────── */
.cv-filter-bar {
    display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;
    padding: 1rem 1.5rem;
    background: var(--cv-bg-card);
    border-bottom: 1px solid var(--cv-border);
}
.cv-filter-search { flex: 1; min-width: 160px; max-width: 220px; }
.cv-filter-field  { flex: 1; min-width: 120px; max-width: 170px; }
.cv-input-icon-wrap { position: relative; }
.cv-input-icon { position: absolute; inset-inline-start: 0.65rem; top: 50%; transform: translateY(-50%); color: var(--cv-text-muted); pointer-events: none; }
.cv-input-icon svg { width: 15px; height: 15px; }
.cv-input-with-icon { padding-inline-start: 2rem; }

/* ── Table controls bar ──────────────────────────────────── */
.cv-table-controls {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0 0.25rem;
}

/* ── Group block ─────────────────────────────────────────── */
.cv-group-block {
    border: 1px solid var(--cv-border);
    border-radius: 10px;
    overflow: hidden;
    background: var(--cv-bg-card);
}

/* Group Header */
.cv-group-header {
    width: 100%;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 0.75rem;
    padding: 0.85rem 1.25rem;
    background: var(--cv-bg-card);
    border: none; cursor: pointer;
    border-bottom: 1px solid transparent;
    transition: background 0.15s, border-color 0.15s;
    text-align: start;
}
.cv-group-header:hover { background: var(--cv-bg-hover); }
.cv-group-header--open {
    background: linear-gradient(90deg, rgba(77,191,122,0.06), transparent);
    border-bottom-color: var(--cv-border);
}

.cv-group-header-left { display: flex; align-items: center; gap: 0.75rem; }

.cv-group-chevron {
    width: 20px; height: 20px; flex-shrink: 0;
    color: var(--cv-text-muted); transition: transform 0.22s cubic-bezier(.4,0,.2,1);
    display: flex; align-items: center; justify-content: center;
}
.cv-group-chevron svg { width: 16px; height: 16px; }
.cv-group-chevron--open { transform: rotate(90deg); color: var(--cv-green-bright); }

.cv-group-date-block { display: flex; align-items: center; gap: 0.65rem; }
.cv-group-date { font-size: 0.9rem; font-weight: 700; color: var(--cv-text-primary); }
.cv-group-count-pill {
    font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em;
    padding: 0.15rem 0.55rem; border-radius: 20px;
    background: rgba(77,191,122,0.12); color: var(--cv-green-bright);
    border: 1px solid rgba(77,191,122,0.2);
}

/* Summary chips */
.cv-group-summary { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; }
.cv-summary-chip {
    font-size: 0.72rem; font-weight: 600; padding: 0.2rem 0.55rem;
    border-radius: 20px; border: 1px solid;
    white-space: nowrap; letter-spacing: 0.02em;
}
.cv-chip-blue   { color: var(--cv-blue-text);    background: rgba(77,150,255,0.08);   border-color: rgba(77,150,255,0.2); }
.cv-chip-green  { color: var(--cv-green-bright); background: rgba(77,191,122,0.08);  border-color: rgba(77,191,122,0.2); }
.cv-chip-muted  { color: var(--cv-text-muted);   background: rgba(128,128,128,0.08); border-color: rgba(128,128,128,0.2); }
.cv-chip-active { color: #4DBF7A;                background: rgba(77,191,122,0.1);   border-color: rgba(77,191,122,0.25); }
.cv-chip-weight { color: var(--cv-amber);        background: rgba(245,166,35,0.08);  border-color: rgba(245,166,35,0.2); }
.cv-chip-farm   { color: #a06be8;                background: rgba(160,107,232,0.08); border-color: rgba(160,107,232,0.2); }

/* Group body / collapse */
.cv-group-body {
    max-height: 9999px;
    overflow: hidden;
    transition: max-height 0.3s cubic-bezier(.4,0,.2,1), opacity 0.2s;
    opacity: 1;
}
.cv-group-body--collapsed {
    max-height: 0;
    opacity: 0;
}

/* ── Table ───────────────────────────────────────────────── */
.cv-table-scroll { overflow-x: auto; }

.cv-animal-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.835rem; min-width: 820px;
}

.cv-th {
    padding: 0.6rem 0.9rem;
    text-align: start;
    font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--cv-text-muted);
    background: var(--cv-bg-base);
    border-bottom: 2px solid var(--cv-border);
    white-space: nowrap;
}
.cv-th-num     { width: 44px; text-align: center; }
.cv-th-tag     { min-width: 160px; }
.cv-th-right   { text-align: end; }
.cv-th-actions { width: 110px; text-align: center; }

.cv-tr { border-bottom: 1px solid var(--cv-border); transition: background 0.1s; }
.cv-tr:last-child { border-bottom: none; }
.cv-tr:hover { background: var(--cv-bg-hover, rgba(77,191,122,0.04)); }
.cv-tr-even { background: rgba(0,0,0,0.03); }
.cv-tr-even:hover { background: var(--cv-bg-hover); }

.cv-td { padding: 0.6rem 0.9rem; vertical-align: middle; color: var(--cv-text-primary); }
.cv-td-num     { text-align: center; color: var(--cv-text-muted); font-size: 0.78rem; font-weight: 600; }
.cv-td-mono    { font-family: 'Courier New', monospace; font-size: 0.78rem; color: var(--cv-text-muted); }
.cv-td-breed   { color: var(--cv-text-muted); max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cv-td-actions { text-align: center; }
.cv-td-empty   { color: var(--cv-text-muted); opacity: 0.4; }

/* Tag cell */
.cv-tag-cell { display: flex; align-items: center; gap: 0.6rem; }
.cv-avatar-sm {
    width: 34px; height: 34px; border-radius: 6px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; color: #fff; letter-spacing: 0.3px;
    position: relative; overflow: hidden;
}
.cv-avatar-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.cv-tag-text-block { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.cv-tag-link {
    font-weight: 700; color: var(--cv-text-primary); text-decoration: none;
    font-size: 0.875rem;
    transition: color 0.15s;
}
.cv-tag-link:hover { color: var(--cv-green-bright); }
.cv-born-badge {
    font-size: 0.65rem; font-weight: 700; letter-spacing: 0.04em;
    color: var(--cv-amber); text-transform: uppercase;
}

/* RFID */
.cv-rfid-text { font-size: 0.75rem; opacity: 0.75; }

/* Farm/Lot cell */
.cv-farm-lot-cell { display: flex; flex-direction: column; gap: 2px; }
.cv-farm-name { font-size: 0.82rem; font-weight: 600; color: var(--cv-text-primary); }
.cv-lot-name  { font-size: 0.72rem; color: var(--cv-text-muted); }

/* Weight + Age */
.cv-weight-val { font-weight: 600; color: var(--cv-blue-text); }
.cv-weight-val small { font-weight: 400; opacity: 0.6; font-size: 0.72rem; }
.cv-age-val { font-weight: 600; color: var(--cv-text-muted); font-size: 0.82rem; }
.cv-est-mark { font-size: 0.7rem; opacity: 0.5; font-style: normal; }

/* ── Action buttons ──────────────────────────────────────── */
.cv-action-row { display: flex; align-items: center; justify-content: center; gap: 0.35rem; }
.cv-action-btn {
    width: 30px; height: 30px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; border: 1px solid var(--cv-border);
    background: transparent; color: var(--cv-text-muted);
    transition: all 0.15s; text-decoration: none;
}
.cv-action-btn svg { width: 14px; height: 14px; }
.cv-action-btn:hover { background: var(--cv-bg-hover); border-color: var(--cv-green-bright); color: var(--cv-green-bright); }
.cv-action-btn-blue:hover   { border-color: var(--cv-blue-text); color: var(--cv-blue-text); }
.cv-action-btn-danger:hover { border-color: var(--cv-danger-text); color: var(--cv-danger-text); }

/* ── Badges ──────────────────────────────────────────────── */
.cv-badge { display: inline-block; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; padding: 0.18rem 0.55rem; border-radius: 20px; border: 1px solid; text-transform: uppercase; white-space: nowrap; }
.cv-badge-green   { color: #4DBF7A; background: rgba(77,191,122,0.1);  border-color: rgba(77,191,122,0.25); }
.cv-badge-info    { color: var(--cv-blue-text); background: rgba(77,150,255,0.1); border-color: rgba(77,150,255,0.25); }
.cv-badge-amber   { color: var(--cv-amber);    background: rgba(245,166,35,0.1); border-color: rgba(245,166,35,0.25); }
.cv-badge-danger  { color: var(--cv-danger-text); background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.25); }
.cv-badge-neutral { color: var(--cv-text-muted); background: rgba(128,128,128,0.1); border-color: rgba(128,128,128,0.2); }

/* ── Buttons ─────────────────────────────────────────────── */
.cv-btn {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.5rem 1rem; border-radius: 8px;
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; border: none; transition: all 0.15s;
    text-decoration: none; white-space: nowrap;
}
.cv-btn-icon { width: 15px; height: 15px; flex-shrink: 0; }
.cv-btn-amber   { background: #f5a623; color: #1a0e00; box-shadow: 0 0 10px rgba(245,166,35,0.25); }
.cv-btn-amber:hover:not(:disabled) { filter: brightness(1.1); transform: translateY(-1px); }
.cv-btn-ghost   { background: transparent; color: var(--cv-text-muted); border: 1px solid var(--cv-border); }
.cv-btn-ghost:hover:not(:disabled) { background: var(--cv-bg-hover); color: var(--cv-text-primary); }
.cv-btn-ghost:disabled { opacity: 0.35; cursor: not-allowed; }
.cv-btn-danger  { background: rgba(239,68,68,0.12); color: var(--cv-danger-text); border: 1px solid rgba(239,68,68,0.3); }
.cv-btn-danger:hover { background: rgba(239,68,68,0.2); }
.cv-btn-sm  { padding: 0.4rem 0.85rem; font-size: 0.8rem; }
.cv-btn-xs  { padding: 0.3rem 0.7rem; font-size: 0.75rem; }

/* ── Inputs / Selects ────────────────────────────────────── */
.cv-input, .cv-select {
    width: 100%; padding: 0.45rem 0.7rem;
    background: var(--cv-bg-base); border: 1px solid var(--cv-border);
    border-radius: 7px; color: var(--cv-text-primary);
    font-size: 0.85rem; outline: none; font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.cv-input:focus, .cv-select:focus { border-color: #4DBF7A; box-shadow: 0 0 0 3px rgba(77,191,122,0.12); }
.cv-select { background: var(--cv-bg-base); }

/* ── Empty state ─────────────────────────────────────────── */
.cv-empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 4rem 2rem; gap: 1rem; }
.cv-empty-icon { width: 80px; height: 80px; color: var(--cv-text-muted); opacity: 0.4; }
.cv-empty-text { font-size: 0.95rem; color: var(--cv-text-muted); text-align: center; }

/* ── Pagination ──────────────────────────────────────────── */
.cv-pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 1rem 0; }
.cv-pagination-info { font-size: 0.8rem; color: var(--cv-text-muted); }

/* ── Modal ───────────────────────────────────────────────── */
.cv-modal-backdrop {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.cv-modal { background: var(--cv-bg-card); border: 1px solid var(--cv-border); border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
.cv-modal-header { padding: 1.5rem 1.5rem 0; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; }
.cv-modal-danger-icon { width: 48px; height: 48px; border-radius: 50%; background: rgba(239,68,68,0.12); display: flex; align-items: center; justify-content: center; color: var(--cv-danger-text); }
.cv-modal-danger-icon svg { width: 24px; height: 24px; }
.cv-modal-title { font-size: 1rem; font-weight: 700; color: var(--cv-text-primary); }
.cv-modal-body { padding: 1rem 1.5rem; }
.cv-alert-danger { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); border-radius: 8px; padding: 0.85rem 1rem; font-size: 0.875rem; color: var(--cv-text-primary); line-height: 1.5; }
.cv-modal-footer { padding: 0.75rem 1.5rem 1.5rem; display: flex; justify-content: flex-end; gap: 0.75rem; }

/* ── Modal transition ────────────────────────────────────── */
.cv-modal-fade-enter-active, .cv-modal-fade-leave-active { transition: opacity 0.2s; }
.cv-modal-fade-enter-from, .cv-modal-fade-leave-to { opacity: 0; }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
    .cv-page-body    { padding: 0.75rem 0.75rem 3rem; }
    .cv-filter-bar   { padding: 0.75rem 1rem; }
    .cv-page-hero    { padding: 1.25rem 1rem 1rem; }
    .cv-hero-watermark { display: none; }
    .cv-page-hero-title { font-size: 1.35rem; }
    .cv-group-header { padding: 0.75rem 1rem; }
    .cv-group-summary { display: none; }   /* hidden on small screens — header shows date+count only */
    .cv-th, .cv-td   { padding: 0.5rem 0.65rem; }
}
@media (max-width: 480px) {
    .cv-filter-search, .cv-filter-field { max-width: 100%; }
}
</style>