<script setup>
/**
 * Feed/Rations/Index.vue — VERO Cattle Management
 * ─────────────────────────────────────────────────────────────────
 * Ration formulations per lot — index-only page.
 * All CRUD via slide-in panel with dynamic ingredient lines.
 *
 * Uses global cv- classes from app.css — no scoped overrides.
 * Scoped CSS only for slide-in panel and ration-specific elements.
 *
 * 📌 METHODOLOGY REFERENCE: RationNutritionalTotals
 *   Live client-side nutrition preview while building a ration.
 *   Server recalculates authoritatively via FeedNutritionService.
 * ─────────────────────────────────────────────────────────────────
 */
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'
defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    rations:   { type: Array,  default: () => [] },
    farms:     { type: Array,  default: () => [] },
    lots:      { type: Array,  default: () => [] },
    feedItems: { type: Array,  default: () => [] },
    filters:   { type: Object, default: () => ({}) },
})

// ── Permissions ───────────────────────────────────────────────────
const { can: canPermission } = usePermissions()
const can = computed(() => ({
    create: canPermission('create rations'),
    edit:   canPermission('edit rations'),
    delete: canPermission('delete rations'),
}))

// ── Filters ───────────────────────────────────────────────────────
const filtersOpen = ref(false)
const farmFilter  = ref(props.filters?.farm_id ?? '')
const lotFilter   = ref(props.filters?.lot_id  ?? '')

// Lots filtered by selected farm
const filteredLots = computed(() =>
    farmFilter.value
        ? props.lots.filter(l => String(l.farm_id) === String(farmFilter.value))
        : props.lots
)

// Reset lot when farm changes
watch(farmFilter, () => { lotFilter.value = '' })

function applyFilters() {
    router.get(route('feed.rations.index'), {
        farm_id: farmFilter.value || undefined,
        lot_id:  lotFilter.value  || undefined,
    }, { preserveState: true, replace: true })
}

function resetFilters() {
    farmFilter.value = ''
    lotFilter.value  = ''
    applyFilters()
}

// ── Card expand (show ingredient lines) ──────────────────────────
const expandedCards = ref(new Set())

function toggleExpand(id) {
    if (expandedCards.value.has(id)) {
        expandedCards.value.delete(id)
    } else {
        expandedCards.value.add(id)
    }
}

// ── Slide-in panel ────────────────────────────────────────────────
const panelOpen  = ref(false)
const editTarget = ref(null)
const isEditMode = computed(() => !!editTarget.value)

// Panel lot filter (farm → lot cascade inside panel)
const panelFarmId = ref('')
const panelLots   = computed(() =>
    panelFarmId.value
        ? props.lots.filter(l => String(l.farm_id) === String(panelFarmId.value))
        : props.lots
)

function openCreate() {
    editTarget.value   = null
    panelFarmId.value  = ''
    form.farm_id       = ''
    form.lot_id        = ''
    form.name          = ''
    form.effective_date= ''
    form.is_active     = true
    form.notes         = ''
    form.lines         = [{ feed_item_id: '', qty_per_head_kg: '' }]
    form.clearErrors()
    panelOpen.value    = true
}

function openEdit(ration) {
    editTarget.value    = ration
    panelFarmId.value   = String(ration.farm_id)
    form.farm_id        = ration.farm_id
    form.lot_id         = ration.lot_id
    form.name           = ration.name
    form.effective_date = ration.effective_date
    form.is_active      = ration.is_active
    form.notes          = ration.notes ?? ''
    form.lines          = ration.lines?.length
        ? ration.lines.map(l => ({
            feed_item_id:    l.feed_item_id,
            qty_per_head_kg: l.qty_per_head_kg,
        }))
        : [{ feed_item_id: '', qty_per_head_kg: '' }]
    form.clearErrors()
    panelOpen.value     = true
}

function closePanel() {
    panelOpen.value  = false
    editTarget.value = null
    form.reset()
    form.clearErrors()
}

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    farm_id:        '',
    lot_id:         '',
    name:           '',
    effective_date: '',
    is_active:      true,
    notes:          '',
    lines:          [{ feed_item_id: '', qty_per_head_kg: '' }],
})

// Sync farm_id into panelFarmId when changed in form
// Must be declared AFTER form to avoid "Cannot access before initialization"
watch(() => form.farm_id, (val) => {
    panelFarmId.value = String(val)
    form.lot_id = ''
})

function addLine() {
    form.lines.push({ feed_item_id: '', qty_per_head_kg: '' })
}

function removeLine(index) {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1)
    }
}

function submit() {
    if (isEditMode.value) {
        form.put(route('feed.rations.update', editTarget.value.id), { onSuccess: closePanel })
    } else {
        form.post(route('feed.rations.store'), { onSuccess: closePanel })
    }
}

// ── 📌 METHODOLOGY REFERENCE: RationNutritionalTotals ────────────
// Live client-side preview of ration totals as user builds lines.
// Server recalculates authoritatively on save via FeedNutritionService.
const liveNutrition = computed(() => {
    let totalCost = 0
    let totalDmKg = 0
    let totalCpKg = 0
    let totalQty  = 0

    for (const line of form.lines) {
        const qty = parseFloat(line.qty_per_head_kg) || 0
        if (!line.feed_item_id || qty <= 0) continue

        const item = props.feedItems.find(f => String(f.id) === String(line.feed_item_id))
        if (!item) continue

        totalCost += qty * (parseFloat(item.unit_cost) || 0)
        totalDmKg += qty * ((parseFloat(item.dm_pct)   || 0) / 100)
        totalCpKg += qty * ((parseFloat(item.cp_pct)   || 0) / 100)
        totalQty  += qty
    }

    const totalCpPct = totalQty > 0 ? (totalCpKg / totalQty) * 100 : 0

    return {
        cost:  totalCost,
        dmKg:  totalDmKg,
        cpPct: totalCpPct,
    }
})

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal  = ref(false)
const deleteTarget = ref(null)

function confirmDelete(ration) {
    deleteTarget.value = ration
    deleteModal.value  = true
}

function doDelete() {
    router.delete(route('feed.rations.destroy', deleteTarget.value.id), {
        onSuccess: () => { deleteModal.value = false; deleteTarget.value = null },
    })
}

// ── Helpers ───────────────────────────────────────────────────────
function fmtNum(val, decimals = 2) {
    if (val == null || val === '') return '—'
    return Number(val).toLocaleString(
        lang.value === 'ar' ? 'ar-EG' : 'en-US',
        { minimumFractionDigits: decimals, maximumFractionDigits: decimals }
    )
}

function feedItemName(id) {
    const item = props.feedItems.find(f => String(f.id) === String(id))
    if (!item) return '—'
    return lang.value === 'ar'
        ? (item.name_ar || item.name_en)
        : (item.name_en || item.name_ar)
}

function feedItemUnit(id) {
    return props.feedItems.find(f => String(f.id) === String(id))?.unit ?? 'kg'
}

// ── i18n ──────────────────────────────────────────────────────────
const t = computed(() => lang.value === 'ar' ? {
    eyebrow:       'الغذاء والوزن',
    title:         'الحصص الغذائية',
    sub:           'وصفات التغذية اليومية لكل مجموعة',
    addRation:     'حصة جديدة',
    filtersBtn:    'تصفية',
    reset:         'إعادة تعيين',
    apply:         'تطبيق',
    allFarms:      'كل المزارع',
    allLots:       'كل المجموعات',
    farmLabel:     'المزرعة',
    lotLabel:      'المجموعة',
    noRations:     'لا توجد حصص غذائية',
    noRationsSub:  'أنشئ أول حصة غذائية لبدء تتبع التغذية',
    active:        'نشطة',
    inactive:      'غير نشطة',
    effectiveDate: 'تاريخ التطبيق:',
    costPerHead:   'تكلفة/رأس/يوم',
    cpPct:         'بروتين خام',
    dmKg:          'مادة جافة',
    lines:         'مكونات',
    showLines:     'عرض المكونات',
    hideLines:     'إخفاء المكونات',
    edit:          'تعديل',
    delete:        'حذف',
    deleteTitle:   'حذف الحصة الغذائية',
    deleteMsg:     'هل أنت متأكد من حذف هذه الحصة الغذائية وجميع مكوناتها؟',
    cancel:        'إلغاء',
    confirm:       'حذف',
    // panel
    panelCreate:   'حصة غذائية جديدة',
    panelEdit:     'تعديل الحصة الغذائية',
    s1Title:       'البيانات الأساسية',
    s1Sub:         'الاسم والمجموعة وتاريخ التطبيق',
    nameLabel:     'اسم الحصة *',
    namePh:        'مثال: حصة النمو - صيف 2025',
    farmFieldLabel:'المزرعة *',
    farmPh:        '— اختر المزرعة —',
    lotFieldLabel: 'المجموعة *',
    lotPh:         '— اختر المجموعة —',
    dateLabel:     'تاريخ التطبيق *',
    activeLabel:   'نشطة',
    s2Title:       'المكونات',
    s2Sub:         'المواد العلفية والكميات لكل رأس يومياً',
    feedItemLabel: 'المادة العلفية',
    qtyLabel:      'الكمية (كجم/رأس/يوم)',
    addLine:       '+ إضافة مكون',
    removeLine:    'حذف',
    liveTitle:     'الملخص التغذوي المحسوب',
    liveCost:      'التكلفة / رأس / يوم',
    liveDm:        'المادة الجافة',
    liveCp:        'البروتين الخام',
    liveNote:      'القيم تقديرية — يُعيد النظام حسابها عند الحفظ',
    s3Title:       'ملاحظات',
    s3Sub:         'معلومات إضافية',
    notesLabel:    'ملاحظات',
    notesPh:       'أي ملاحظات إضافية...',
    cancel2:       'إلغاء',
    saving:        'جارٍ الحفظ...',
    save:          'حفظ الحصة',
    egp:           'ج.م',
    kgPerHead:     'كجم/رأس',
} : {
    eyebrow:       'Feed & Weight',
    title:         'Rations',
    sub:           'Daily feeding recipes per lot',
    addRation:     'New Ration',
    filtersBtn:    'Filters',
    reset:         'Reset',
    apply:         'Apply',
    allFarms:      'All Farms',
    allLots:       'All Lots',
    farmLabel:     'Farm',
    lotLabel:      'Lot',
    noRations:     'No rations found',
    noRationsSub:  'Create your first ration to start tracking feed consumption',
    active:        'Active',
    inactive:      'Inactive',
    effectiveDate: 'Effective:',
    costPerHead:   'Cost/head/day',
    cpPct:         'Crude Protein',
    dmKg:          'Dry Matter',
    lines:         'ingredients',
    showLines:     'Show ingredients',
    hideLines:     'Hide ingredients',
    edit:          'Edit',
    delete:        'Delete',
    deleteTitle:   'Delete Ration',
    deleteMsg:     'Are you sure you want to delete this ration and all its ingredient lines?',
    cancel:        'Cancel',
    confirm:       'Delete',
    // panel
    panelCreate:   'New Ration',
    panelEdit:     'Edit Ration',
    s1Title:       'Basic Details',
    s1Sub:         'Name, lot assignment and effective date',
    nameLabel:     'Ration Name *',
    namePh:        'e.g. Growth Ration — Summer 2025',
    farmFieldLabel:'Farm *',
    farmPh:        '— Select Farm —',
    lotFieldLabel: 'Lot *',
    lotPh:         '— Select Lot —',
    dateLabel:     'Effective Date *',
    activeLabel:   'Active',
    s2Title:       'Ingredients',
    s2Sub:         'Feed materials and quantities per head per day',
    feedItemLabel: 'Feed Item',
    qtyLabel:      'Qty (kg/head/day)',
    addLine:       '+ Add Ingredient',
    removeLine:    'Remove',
    liveTitle:     'Live Nutritional Summary',
    liveCost:      'Cost / head / day',
    liveDm:        'Dry Matter',
    liveCp:        'Crude Protein',
    liveNote:      'Estimates only — server recalculates on save',
    s3Title:       'Notes',
    s3Sub:         'Additional information',
    notesLabel:    'Notes',
    notesPh:       'Any additional notes...',
    cancel2:       'Cancel',
    saving:        'Saving...',
    save:          'Save Ration',
    egp:           'EGP',
    kgPerHead:     'kg/head',
})
</script>

<template>
   
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ─────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ t.title }}</div>
                <div class="cv-page-hero-sub">{{ t.sub }}</div>
                <div class="cv-page-hero-actions">
                    <button v-if="can.create" class="cv-btn cv-btn-amber" @click="openCreate">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" width="16" height="16">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        {{ t.addRation }}
                    </button>
                    <button class="cv-btn cv-btn-ghost" @click="filtersOpen = !filtersOpen">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" width="16" height="16">
                            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
                        </svg>
                        {{ t.filtersBtn }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- Flash -->
                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">
                    {{ $page.props.flash.success }}
                </div>

                <!-- ── Filters ────────────────────────────────────── -->
                <Transition name="slide-down">
                    <div v-if="filtersOpen" class="cv-card mb-6 p-4">
                        <div class="grid gap-3"
                             style="grid-template-columns: repeat(auto-fill, minmax(180px,1fr))">
                            <div>
                                <label class="cv-label">{{ t.farmLabel }}</label>
                                <select v-model="farmFilter" class="cv-select">
                                    <option value="">{{ t.allFarms }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">
                                        {{ n(f, 'name') }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="cv-label">{{ t.lotLabel }}</label>
                                <select v-model="lotFilter" class="cv-select">
                                    <option value="">{{ t.allLots }}</option>
                                    <option v-for="l in filteredLots" :key="l.id" :value="l.id">
                                        {{ l.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-4">
                            <button class="cv-btn cv-btn-amber" @click="applyFilters">{{ t.apply }}</button>
                            <button class="cv-btn cv-btn-ghost" @click="resetFilters">{{ t.reset }}</button>
                        </div>
                    </div>
                </Transition>

                <!-- ── Card grid ──────────────────────────────────── -->
                <div v-if="rations.length"
                     class="cv-grid"
                     style="grid-template-columns: repeat(auto-fill, minmax(320px,1fr)); gap:1rem;">

                    <div v-for="ration in rations" :key="ration.id"
                         class="cv-card ration-card"
                         :style="`border-inline-start: 4px solid ${ration.is_active ? 'var(--cv-green-bright)' : 'var(--cv-text-muted)'}`">

                        <!-- Top row: status + date -->
                        <div class="flex items-center justify-between mb-3">
                            <span class="cv-badge"
                                  :style="ration.is_active
                                      ? 'background:rgba(92,196,106,0.12);color:var(--cv-green-bright)'
                                      : 'background:rgba(85,112,96,0.12);color:var(--cv-text-muted)'">
                                {{ ration.is_active ? t.active : t.inactive }}
                            </span>
                            <span class="text-xs" style="color:var(--cv-text-muted)">
                                {{ t.effectiveDate }} {{ ration.effective_date }}
                            </span>
                        </div>

                        <!-- Ration name -->
                        <div class="flex items-center gap-2 mb-2">
                            <div class="cv-avatar-sm"
                                 style="background:linear-gradient(135deg,var(--cv-amber),#c97d10)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="white"
                                     stroke-width="1.7" width="14" height="14">
                                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                                    <rect x="9" y="3" width="6" height="4" rx="1"/>
                                    <path d="M9 12h6M9 16h4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold text-sm" style="color:var(--cv-text-primary)">
                                    {{ ration.name }}
                                </div>
                                <div class="text-xs" style="color:var(--cv-text-muted)">
                                    {{ ration.lot?.name ?? '—' }}
                                    <span v-if="ration.farm"> · {{ n(ration.farm, 'name') }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- KPI row: cost + nutrition -->
                        <div class="ration-kpis mb-3">
                            <div class="ration-kpi">
                                <span class="kpi-val">{{ fmtNum(ration.cost_per_head_day) }}</span>
                                <span class="kpi-lbl">{{ t.costPerHead }} ({{ t.egp }})</span>
                            </div>
                            <div v-if="ration.total_cp_pct" class="ration-kpi">
                                <span class="kpi-val kpi-green">{{ fmtNum(ration.total_cp_pct, 1) }}%</span>
                                <span class="kpi-lbl">{{ t.cpPct }}</span>
                            </div>
                            <div v-if="ration.total_dm_kg" class="ration-kpi">
                                <span class="kpi-val kpi-blue">{{ fmtNum(ration.total_dm_kg, 2) }}</span>
                                <span class="kpi-lbl">{{ t.dmKg }} ({{ t.kgPerHead }})</span>
                            </div>
                        </div>

                        <!-- Lines count + expand toggle -->
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs" style="color:var(--cv-text-muted)">
                                {{ ration.lines_count }} {{ t.lines }}
                            </span>
                            <button v-if="ration.lines?.length"
                                    class="expand-btn"
                                    @click="toggleExpand(ration.id)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="12" height="12"
                                     :style="expandedCards.has(ration.id) ? 'transform:rotate(180deg)' : ''">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                                {{ expandedCards.has(ration.id) ? t.hideLines : t.showLines }}
                            </button>
                        </div>

                        <!-- Ingredient lines (expandable) -->
                        <Transition name="slide-down">
                            <div v-if="expandedCards.has(ration.id) && ration.lines?.length"
                                 class="lines-table mb-3">
                                <div v-for="line in ration.lines" :key="line.id"
                                     class="line-row">
                                    <span class="line-name">
                                        {{ lang === 'ar'
                                            ? (line.feed_item_name_ar || line.feed_item_name_en)
                                            : (line.feed_item_name_en || line.feed_item_name_ar) }}
                                    </span>
                                    <span class="line-qty">
                                        {{ fmtNum(line.qty_per_head_kg, 3) }} kg
                                    </span>
                                    <span v-if="line.cost_per_head" class="line-cost">
                                        {{ fmtNum(line.cost_per_head) }} {{ t.egp }}
                                    </span>
                                </div>
                            </div>
                        </Transition>

                        <!-- Actions -->
                        <div class="flex gap-2 mt-auto pt-3"
                             style="border-top:1px solid var(--cv-border-muted)">
                            <button v-if="can.edit"
                                    class="cv-btn cv-btn-ghost cv-btn-sm"
                                    @click="openEdit(ration)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="13" height="13">
                                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                {{ t.edit }}
                            </button>
                            <button v-if="can.delete"
                                    class="cv-btn cv-btn-danger-ghost cv-btn-sm"
                                    @click="confirmDelete(ration)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="13" height="13">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                                    <path d="M10 11v6M14 11v6"/>
                                    <path d="M9 6V4h6v2"/>
                                </svg>
                                {{ t.delete }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.3" width="48" height="48">
                            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                            <rect x="9" y="3" width="6" height="4" rx="1"/>
                            <path d="M9 12h6M9 16h4"/>
                        </svg>
                    </div>
                    <div class="cv-empty-title">{{ t.noRations }}</div>
                    <div class="cv-empty-sub">{{ t.noRationsSub }}</div>
                    <button v-if="can.create" class="cv-btn cv-btn-amber mt-4" @click="openCreate">
                        {{ t.addRation }}
                    </button>
                </div>

            </div><!-- /cv-page -->

            <!-- ═══ SLIDE-IN PANEL ════════════════════════════════ -->
            <Teleport to="body">
                <Transition name="backdrop-fade">
                    <div v-if="panelOpen" class="panel-backdrop" @click="closePanel"/>
                </Transition>

                <Transition name="panel-slide">
                    <div v-if="panelOpen"
                         class="slide-panel"
                         :dir="isRtl ? 'rtl' : 'ltr'"
                         role="dialog" :aria-modal="true">

                        <!-- Header -->
                        <div class="panel-header">
                            <div class="flex items-center gap-3">
                                <div class="panel-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="white"
                                         stroke-width="1.7" width="16" height="16">
                                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                                        <rect x="9" y="3" width="6" height="4" rx="1"/>
                                        <path d="M9 12h6M9 16h4"/>
                                    </svg>
                                </div>
                                <div>
                                    <div class="panel-title">
                                        {{ isEditMode ? t.panelEdit : t.panelCreate }}
                                    </div>
                                    <div v-if="isEditMode" class="panel-subtitle">
                                        {{ editTarget.name }}
                                    </div>
                                </div>
                            </div>
                            <button @click="closePanel" class="panel-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="16" height="16">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="panel-body">
                            <form @submit.prevent="submit">

                                <!-- Section 1: Basic Details -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head">
                                        <div class="sec-badge sec-1">1</div>
                                        <div>
                                            <div style="font-weight:600;font-size:0.875rem;color:var(--cv-text-primary)">{{ t.s1Title }}</div>
                                            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s1Sub }}</div>
                                        </div>
                                    </div>

                                    <!-- Ration name -->
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">{{ t.nameLabel }}</label>
                                        <input v-model="form.name" type="text"
                                               class="cv-input"
                                               :class="{ 'cv-input-error': form.errors.name }"
                                               :placeholder="t.namePh"/>
                                        <p v-if="form.errors.name" class="cv-field-error">{{ form.errors.name }}</p>
                                    </div>

                                    <!-- Farm → Lot cascade -->
                                    <div class="grid gap-3 mt-3"
                                         style="grid-template-columns:1fr 1fr">
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.farmFieldLabel }}</label>
                                            <select v-model="form.farm_id" class="cv-select"
                                                    :class="{ 'cv-input-error': form.errors.farm_id }">
                                                <option value="">{{ t.farmPh }}</option>
                                                <option v-for="f in farms" :key="f.id" :value="f.id">
                                                    {{ n(f, 'name') }}
                                                </option>
                                            </select>
                                            <p v-if="form.errors.farm_id" class="cv-field-error">{{ form.errors.farm_id }}</p>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.lotFieldLabel }}</label>
                                            <select v-model="form.lot_id" class="cv-select"
                                                    :class="{ 'cv-input-error': form.errors.lot_id }"
                                                    :disabled="!form.farm_id">
                                                <option value="">{{ t.lotPh }}</option>
                                                <option v-for="l in panelLots" :key="l.id" :value="l.id">
                                                    {{ l.name }}
                                                </option>
                                            </select>
                                            <p v-if="form.errors.lot_id" class="cv-field-error">{{ form.errors.lot_id }}</p>
                                        </div>
                                    </div>

                                    <!-- Date + active toggle -->
                                    <div class="grid gap-3 mt-3"
                                         style="grid-template-columns:1fr auto">
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.dateLabel }}</label>
                                            <input v-model="form.effective_date" type="date"
                                                   class="cv-input"
                                                   :class="{ 'cv-input-error': form.errors.effective_date }"/>
                                            <p v-if="form.errors.effective_date" class="cv-field-error">{{ form.errors.effective_date }}</p>
                                        </div>
                                        <div class="cv-field" style="justify-content:flex-end;padding-top:1.5rem">
                                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                                <div class="cv-toggle"
                                                     :class="{ 'cv-toggle-on': form.is_active }"
                                                     @click="form.is_active = !form.is_active">
                                                    <span class="cv-toggle-knob"/>
                                                </div>
                                                <span class="cv-label mb-0">{{ t.activeLabel }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Section 2: Ingredients -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head">
                                        <div class="sec-badge sec-2">2</div>
                                        <div>
                                            <div style="font-weight:600;font-size:0.875rem;color:var(--cv-text-primary)">{{ t.s2Title }}</div>
                                            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s2Sub }}</div>
                                        </div>
                                    </div>

                                    <!-- Ingredient lines -->
                                    <div class="lines-editor mt-3">
                                        <div v-for="(line, idx) in form.lines" :key="idx"
                                             class="line-editor-row">
                                            <!-- Feed item select -->
                                            <div class="cv-field line-item-field">
                                                <label v-if="idx === 0" class="cv-label">{{ t.feedItemLabel }}</label>
                                                <select v-model="line.feed_item_id" class="cv-select"
                                                        :class="{ 'cv-input-error': form.errors[`lines.${idx}.feed_item_id`] }">
                                                    <option value="">—</option>
                                                    <option v-for="fi in feedItems"
                                                            :key="fi.id" :value="fi.id">
                                                        {{ lang === 'ar' ? (fi.name_ar || fi.name_en) : (fi.name_en || fi.name_ar) }}
                                                    </option>
                                                </select>
                                            </div>
                                            <!-- Qty input -->
                                            <div class="cv-field line-qty-field">
                                                <label v-if="idx === 0" class="cv-label">{{ t.qtyLabel }}</label>
                                                <div class="input-suffix-wrap">
                                                    <input v-model="line.qty_per_head_kg"
                                                           type="number" step="0.001" min="0"
                                                           class="cv-input input-with-suffix"
                                                           :class="{ 'cv-input-error': form.errors[`lines.${idx}.qty_per_head_kg`] }"
                                                           placeholder="0.000"/>
                                                    <span class="input-suffix">kg</span>
                                                </div>
                                            </div>
                                            <!-- Remove button -->
                                            <div class="line-remove-wrap"
                                                 :style="idx === 0 ? 'padding-top:1.5rem' : ''">
                                                <button type="button"
                                                        @click="removeLine(idx)"
                                                        :disabled="form.lines.length === 1"
                                                        class="line-remove-btn"
                                                        :title="t.removeLine">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                         stroke-width="1.7" width="13" height="13">
                                                        <line x1="18" y1="6" x2="6" y2="18"/>
                                                        <line x1="6" y1="6" x2="18" y2="18"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Validation error for lines array -->
                                        <p v-if="form.errors.lines" class="cv-field-error mt-1">
                                            {{ form.errors.lines }}
                                        </p>

                                        <!-- Add line button -->
                                        <button type="button" @click="addLine"
                                                class="add-line-btn mt-2">
                                            {{ t.addLine }}
                                        </button>
                                    </div>

                                    <!-- 📌 METHODOLOGY REFERENCE: RationNutritionalTotals -->
                                    <!-- Live preview summary — client-side estimate -->
                                    <div v-if="form.lines.some(l => l.feed_item_id && l.qty_per_head_kg)"
                                         class="live-summary mt-4">
                                        <div class="live-summary-title">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="1.7" width="13" height="13"
                                                 style="color:var(--cv-amber)">
                                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                            </svg>
                                            {{ t.liveTitle }}
                                        </div>
                                        <div class="live-kpis">
                                            <div class="live-kpi">
                                                <span class="live-val" style="color:var(--cv-blue-text)">
                                                    {{ fmtNum(liveNutrition.cost) }}
                                                </span>
                                                <span class="live-lbl">{{ t.liveCost }} ({{ t.egp }})</span>
                                            </div>
                                            <div class="live-kpi">
                                                <span class="live-val" style="color:var(--cv-amber)">
                                                    {{ fmtNum(liveNutrition.dmKg, 3) }}
                                                </span>
                                                <span class="live-lbl">{{ t.liveDm }} (kg)</span>
                                            </div>
                                            <div class="live-kpi">
                                                <span class="live-val" style="color:var(--cv-green-bright)">
                                                    {{ fmtNum(liveNutrition.cpPct, 1) }}%
                                                </span>
                                                <span class="live-lbl">{{ t.liveCp }}</span>
                                            </div>
                                        </div>
                                        <p class="live-note">{{ t.liveNote }}</p>
                                    </div>
                                </div>

                                <!-- Section 3: Notes -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head">
                                        <div class="sec-badge sec-3">3</div>
                                        <div>
                                            <div style="font-weight:600;font-size:0.875rem;color:var(--cv-text-primary)">{{ t.s3Title }}</div>
                                            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s3Sub }}</div>
                                        </div>
                                    </div>
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">{{ t.notesLabel }}</label>
                                        <textarea v-model="form.notes" class="cv-textarea"
                                                  rows="3" :placeholder="t.notesPh"/>
                                    </div>
                                </div>

                            </form>
                        </div>

                        <!-- Footer -->
                        <div class="panel-footer">
                            <button type="button" @click="closePanel" class="cv-btn cv-btn-ghost">
                                {{ t.cancel2 }}
                            </button>
                            <button type="button" @click="submit"
                                    :disabled="form.processing" class="cv-btn cv-btn-amber">
                                <svg v-if="form.processing" class="spin"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" width="14" height="14">
                                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/>
                                </svg>
                                {{ form.processing ? t.saving : t.save }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- ═══ DELETE MODAL ══════════════════════════════════ -->
            <Transition name="fade">
                <div v-if="deleteModal"
                     class="cv-modal-backdrop"
                     @click.self="deleteModal = false">
                    <div class="cv-modal">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="28" height="28">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <h3 class="cv-modal-title">{{ t.deleteTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <p class="font-semibold mb-2" style="color:var(--cv-text-primary)">
                                {{ deleteTarget?.name }}
                            </p>
                            <p>{{ t.deleteMsg }}</p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">
                                {{ t.cancel }}
                            </button>
                            <button class="cv-btn cv-btn-danger" @click="doDelete">
                                {{ t.confirm }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    
</template>

<style scoped>
/* Only styles NOT already in app.css */

.ration-card {
    display: flex;
    flex-direction: column;
    padding: 1rem;
    transition: box-shadow 0.2s, transform 0.15s;
}
.ration-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}

.cv-avatar-sm {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* KPI row inside card */
.ration-kpis {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 0.6rem 0.75rem;
    background: var(--cv-bg-card-alt, #132218);
    border-radius: 0.5rem;
    border: 1px solid var(--cv-border);
}

.ration-kpi {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-width: 0;
}

.kpi-val {
    font-size: 1rem;
    font-weight: 700;
    color: var(--cv-blue-text);
    white-space: nowrap;
}
.kpi-green { color: var(--cv-green-bright) !important; }
.kpi-blue  { color: var(--cv-blue-text) !important; }
.kpi-lbl {
    font-size: 0.68rem;
    color: var(--cv-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

/* Expand button */
.expand-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.72rem;
    color: var(--cv-text-muted);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    transition: color 0.15s;
}
.expand-btn:hover { color: var(--cv-text-primary); }
.expand-btn svg { transition: transform 0.2s; }

/* Ingredient lines table inside card */
.lines-table {
    border: 1px solid var(--cv-border);
    border-radius: 0.5rem;
    overflow: hidden;
}

.line-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.75rem;
    font-size: 0.78rem;
    border-bottom: 1px solid var(--cv-border);
}
.line-row:last-child { border-bottom: none; }
.line-row:nth-child(even) { background: var(--cv-bg-card-alt, #132218); }

.line-name { flex: 1; color: var(--cv-text-primary); }
.line-qty  { color: var(--cv-blue-text); font-weight: 600; white-space: nowrap; }
.line-cost { color: var(--cv-text-muted); font-size: 0.72rem; white-space: nowrap; }

/* Button helpers */
.cv-btn-sm { padding: 0.3rem 0.7rem; font-size: 0.78rem; }
.cv-btn-danger-ghost {
    background: transparent;
    color: var(--cv-danger-text);
    border: 1px solid transparent;
}
.cv-btn-danger-ghost:hover {
    background: rgba(239,68,68,0.1);
    border-color: var(--cv-danger-text);
}
.mt-4 { margin-top: 1rem; }

/* ── Toggle ──────────────────────────────────────────────────────── */
.cv-toggle {
    width: 38px; height: 20px;
    background: var(--cv-bg);
    border: 1px solid var(--cv-border);
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.2s;
    position: relative;
    flex-shrink: 0;
}
.cv-toggle-on { background: var(--cv-green-bright); border-color: var(--cv-green-bright); }
.cv-toggle-knob {
    position: absolute;
    top: 2px; inset-inline-start: 2px;
    width: 14px; height: 14px;
    background: #fff;
    border-radius: 50%;
    transition: inset-inline-start 0.2s;
}
.cv-toggle-on .cv-toggle-knob { inset-inline-start: calc(100% - 16px); }

/* ── Slide-in panel ──────────────────────────────────────────────── */
.panel-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(3px);
    z-index: 50;
}

.slide-panel {
    position: fixed;
    inset-block: 0;
    inset-inline-end: 0;
    width: 100%; max-width: 560px;
    background: var(--cv-bg-card);
    border-inline-start: 1px solid var(--cv-border);
    z-index: 51;
    display: flex; flex-direction: column;
    box-shadow: -8px 0 40px rgba(0,0,0,0.4);
}

.panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--cv-border);
    flex-shrink: 0;
}

.panel-icon {
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.5rem;
    background: linear-gradient(135deg, var(--cv-amber), #c97d10);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.panel-title   { font-size: 1rem; font-weight: 600; color: var(--cv-text-primary); }
.panel-subtitle{ font-size: 0.78rem; color: var(--cv-text-muted); margin-top: 0.1rem; }

.panel-close {
    width: 2rem; height: 2rem;
    border-radius: 0.4rem;
    border: 1px solid var(--cv-border);
    background: transparent;
    color: var(--cv-text-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.15s;
}
.panel-close:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }

.panel-body {
    flex: 1; overflow-y: auto;
    padding: 1.25rem 1.5rem;
}

.panel-footer {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--cv-border);
    flex-shrink: 0;
}

/* Section headers */
.section-head { display: flex; align-items: flex-start; gap: 0.75rem; }

.sec-badge {
    width: 1.75rem; height: 1.75rem;
    border-radius: 0.4rem;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
.sec-1 { background: linear-gradient(135deg, var(--cv-green-bright), #2e9048); }
.sec-2 { background: linear-gradient(135deg, var(--cv-amber), #c97d10); }
.sec-3 { background: linear-gradient(135deg, var(--cv-blue-text), #4a90c8); }

/* ── Ingredient line editor ───────────────────────────────────────── */
.lines-editor {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.line-editor-row {
    display: grid;
    grid-template-columns: 1fr 160px 28px;
    gap: 0.5rem;
    align-items: end;
}

.line-item-field { min-width: 0; }
.line-qty-field  { min-width: 0; }

.input-suffix-wrap { display: flex; align-items: stretch; }
.input-with-suffix {
    border-radius: 0.45rem 0 0 0.45rem;
    border-inline-end: none;
}
[dir="rtl"] .input-with-suffix {
    border-radius: 0 0.45rem 0.45rem 0;
    border-inline-end: 1px solid var(--cv-border);
    border-inline-start: none;
}
.input-suffix {
    display: flex; align-items: center;
    padding: 0 0.6rem;
    background: var(--cv-bg-card-alt, #132218);
    border: 1px solid var(--cv-border);
    border-inline-start: none;
    border-radius: 0 0.45rem 0.45rem 0;
    font-size: 0.75rem; font-weight: 600;
    color: var(--cv-text-muted);
    white-space: nowrap; flex-shrink: 0;
}
[dir="rtl"] .input-suffix {
    border-inline-start: none;
    border-inline-end: 1px solid var(--cv-border);
    border-radius: 0.45rem 0 0 0.45rem;
}

.line-remove-wrap { display: flex; align-items: center; justify-content: center; }
.line-remove-btn {
    width: 26px; height: 26px;
    border-radius: 0.35rem;
    border: 1px solid var(--cv-border);
    background: transparent;
    color: var(--cv-text-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.15s;
}
.line-remove-btn:hover:not(:disabled) {
    background: rgba(239,68,68,0.1);
    color: var(--cv-danger-text);
    border-color: var(--cv-danger-text);
}
.line-remove-btn:disabled { opacity: 0.3; cursor: not-allowed; }

.add-line-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--cv-amber);
    background: color-mix(in srgb, var(--cv-amber) 8%, transparent);
    border: 1px dashed var(--cv-amber-border, rgba(232,160,48,0.3));
    border-radius: 0.45rem;
    padding: 0.4rem 0.85rem;
    cursor: pointer;
    transition: all 0.15s;
    width: 100%;
    justify-content: center;
}
.add-line-btn:hover {
    background: color-mix(in srgb, var(--cv-amber) 15%, transparent);
    border-color: var(--cv-amber);
}

/* ── Live nutrition summary ───────────────────────────────────────── */
.live-summary {
    background: color-mix(in srgb, var(--cv-amber) 6%, transparent);
    border: 1px solid color-mix(in srgb, var(--cv-amber) 20%, transparent);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
}

.live-summary-title {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--cv-amber);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 0.6rem;
}

.live-kpis {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.live-kpi { display: flex; flex-direction: column; gap: 0.1rem; }
.live-val  { font-size: 1.05rem; font-weight: 700; }
.live-lbl  { font-size: 0.68rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }

.live-note {
    font-size: 0.7rem;
    color: var(--cv-text-muted);
    margin-top: 0.5rem;
    font-style: italic;
}

/* Animations */
.backdrop-fade-enter-active, .backdrop-fade-leave-active { transition: opacity 0.2s ease; }
.backdrop-fade-enter-from,   .backdrop-fade-leave-to     { opacity: 0; }

.panel-slide-enter-active, .panel-slide-leave-active { transition: transform 0.25s ease; }
.panel-slide-enter-from,   .panel-slide-leave-to     { transform: translateX(100%); }
[dir="rtl"] .panel-slide-enter-from,
[dir="rtl"] .panel-slide-leave-to { transform: translateX(-100%); }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.25s ease; }
.slide-down-enter-from,   .slide-down-leave-to     { opacity:0; transform:translateY(-8px); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from,   .fade-leave-to     { opacity: 0; }

@keyframes spin-anim { to { transform: rotate(360deg); } }
.spin { animation: spin-anim 0.8s linear infinite; }

@media (max-width: 640px) {
    .slide-panel { max-width: 100%; }
    .line-editor-row { grid-template-columns: 1fr 120px 28px; }
}
</style>