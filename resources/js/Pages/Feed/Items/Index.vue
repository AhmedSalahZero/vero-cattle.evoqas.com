<script setup>
/**
 * Feed/Items/Index.vue — VERO Cattle Management
 * ─────────────────────────────────────────────────────────────────
 * Feed Items catalog — index-only page (no CreateEdit.vue).
 * All CRUD via slide-in panel on this same page.
 *
 * Follows the SAME pattern as Health/Index.vue:
 *   - Uses global cv- classes from app.css directly
 *   - No scoped CSS redefining hero/card/btn/badge
 *   - Scoped CSS only for slide-in panel
 *
 * Bilingual rules §5 fully applied:
 *   - name_en visible in EN mode (v-show + v-model)
 *   - name_ar visible in AR mode (v-show + v-model)
 *   - Both always in useForm() — always submitted
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
    items:     { type: Array,  default: () => [] },
    suppliers: { type: Array,  default: () => [] },
    filters:   { type: Object, default: () => ({}) },
})

// ── Permissions ───────────────────────────────────────────────────
const { can: canPermission } = usePermissions()
const can = computed(() => ({
    create: canPermission('create feed'),
    edit:   canPermission('edit feed'),
    delete: canPermission('delete feed'),
}))

// ── Filters ───────────────────────────────────────────────────────
const filtersOpen = ref(false)
const typeFilter  = ref(props.filters?.type ?? '')
const search      = ref('')

function applyTypeFilter() {
    router.get(route('feed.items.index'), {
        type: typeFilter.value || undefined,
    }, { preserveState: true, replace: true })
}

const filteredItems = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.items
    return props.items.filter(i =>
        (i.name_en ?? '').toLowerCase().includes(q) ||
        (i.name_ar ?? '').toLowerCase().includes(q)
    )
})

// ── Slide-in panel ────────────────────────────────────────────────
const panelOpen  = ref(false)
const editTarget = ref(null)
const isEditMode = computed(() => !!editTarget.value)

function openCreate() {
    editTarget.value = null
    form.reset()
    form.clearErrors()
    panelOpen.value = true
}

function openEdit(item) {
    editTarget.value = item
    form.name_en     = item.name_en    ?? ''
    form.name_ar     = item.name_ar    ?? ''
    form.type        = item.type       ?? 'roughage'
    form.unit        = item.unit       ?? 'kg'
    form.unit_cost   = item.unit_cost  ?? ''
    form.cp_pct      = item.cp_pct     ?? ''
    form.tdn_pct     = item.tdn_pct    ?? ''
    form.dm_pct      = item.dm_pct     ?? ''
    form.ndf_pct     = item.ndf_pct    ?? ''
    form.me_mj       = item.me_mj      ?? ''
    form.supplier_id = item.supplier_id ?? ''
    form.notes       = item.notes      ?? ''
    form.clearErrors()
    panelOpen.value  = true
}

function closePanel() {
    panelOpen.value  = false
    editTarget.value = null
    form.reset()
    form.clearErrors()
}

// ── Form — both name fields always present §5.5 ───────────────────
const form = useForm({
    name_en:     '',
    name_ar:     '',
    type:        'roughage',
    unit:        'kg',
    unit_cost:   '',
    cp_pct:      '',
    tdn_pct:     '',
    dm_pct:      '',
    ndf_pct:     '',
    me_mj:       '',
    supplier_id: '',
    notes:       '',
    
})

function submit() {
    if (isEditMode.value) {
        form.put(route('feed.items.update', editTarget.value.id), { onSuccess: closePanel })
    } else {
        form.post(route('feed.items.store'), { onSuccess: closePanel })
    }
}

// ── Smart unit presets ────────────────────────────────────────────
const unitPresets = computed(() => ({
    roughage:    ['kg', 'ton', 'bale', 'bundle'],
    concentrate: ['kg', 'ton', 'bag'],
    supplement:  ['kg', 'g', 'l', 'ml'],
    mineral:     ['kg', 'g', 'bag'],
    other:       ['kg', 'l', 'unit'],
}[form.type] ?? ['kg']))

watch(() => form.type, () => {
    if (!unitPresets.value.includes(form.unit)) form.unit = unitPresets.value[0]
})

// ── Deactivate modal ──────────────────────────────────────────────
const deactivateModal  = ref(false)
const deactivateTarget = ref(null)

function confirmDeactivate(item) {
    deactivateTarget.value = item
    deactivateModal.value  = true
}

function doDeactivate() {
    router.delete(route('feed.items.destroy', deactivateTarget.value.id), {
        onSuccess: () => { deactivateModal.value = false; deactivateTarget.value = null },
    })
}

// ── Type config ───────────────────────────────────────────────────
const typeConfig = computed(() => ({
    roughage:    { stripe: 'var(--cv-green-bright)', bg: 'rgba(92,196,106,0.12)',   label: lang.value === 'ar' ? 'أعلاف خشنة'  : 'Roughage'    },
    concentrate: { stripe: 'var(--cv-amber)',         bg: 'rgba(232,160,48,0.12)',  label: lang.value === 'ar' ? 'أعلاف مركزة' : 'Concentrate' },
    supplement:  { stripe: 'var(--cv-blue-text)',     bg: 'rgba(96,168,224,0.12)',  label: lang.value === 'ar' ? 'مكملات'       : 'Supplement'  },
    mineral:     { stripe: '#a78bfa',                 bg: 'rgba(167,139,250,0.12)', label: lang.value === 'ar' ? 'معادن'         : 'Mineral'     },
    other:       { stripe: 'var(--cv-text-muted)',    bg: 'rgba(85,112,96,0.12)',   label: lang.value === 'ar' ? 'أخرى'          : 'Other'       },
}))

const typeOptions = computed(() => [
    { value: 'roughage',    label: lang.value === 'ar' ? 'أعلاف خشنة'  : 'Roughage'    },
    { value: 'concentrate', label: lang.value === 'ar' ? 'أعلاف مركزة' : 'Concentrate' },
    { value: 'supplement',  label: lang.value === 'ar' ? 'مكملات'       : 'Supplement'  },
    { value: 'mineral',     label: lang.value === 'ar' ? 'معادن'         : 'Mineral'     },
    { value: 'other',       label: lang.value === 'ar' ? 'أخرى'          : 'Other'       },
])

// ── Helpers ───────────────────────────────────────────────────────
function fmt(val, suffix = '') {
    if (val === null || val === undefined || val === '') return null
    return `${parseFloat(val).toFixed(1)}${suffix}`
}

function fmtCost(val) {
    if (val == null) return '—'
    return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    })
}

// ── i18n ──────────────────────────────────────────────────────────
const t = computed(() => lang.value === 'ar' ? {
    eyebrow:         'الغذاء والوزن',
    title:           'المواد العلفية',
    sub:             'إدارة كتالوج المواد العلفية وقيمها الغذائية',
    addItem:         'مادة جديدة',
    filtersBtn:      'تصفية',
    searchPh:        'بحث...',
    allTypes:        'كل الأنواع',
    typeLabel:       'النوع',
    noItems:         'لا توجد مواد علفية',
    noItemsSub:      'أضف أول مادة علفية لبدء بناء خطط التغذية',
    panelCreate:     'مادة علفية جديدة',
    panelEdit:       'تعديل المادة العلفية',
    s1Title:         'الهوية والتصنيف',
    s1Sub:           'الاسم والنوع والوحدة',
    nameEnLabel:     'الاسم (إنجليزي)',
    nameEnPh:        'e.g. Berseem Hay',
    nameArLabel:     'الاسم (عربي)',
    nameArPh:        'مثال: برسيم جاف',
    typeFieldLabel:  'النوع *',
    unitLabel:       'الوحدة *',
    s2Title:         'القيم الغذائية',
    s2Sub:           'اختياري — تُستخدم في حساب الحصص وكفاءة التحويل',
    nutritionHint:   'اتركها فارغة إذا لم تكن معروفة. CP = بروتين خام، TDN = مواد غذائية قابلة للهضم، DM = مادة جافة',
    s3Title:         'التسعير والتوريد',
    s3Sub:           'التكلفة والمورد',
    costLabel:       'التكلفة / الوحدة (جنيه) *',
    supplierLabel:   'المورد (اختياري)',
    supplierPh:      '— بدون مورد —',
    notesLabel:      'ملاحظات',
    notesPh:         'أي ملاحظات إضافية...',
    cancel:          'إلغاء',
    saving:          'جارٍ الحفظ...',
    save:            'حفظ',
    edit:            'تعديل',
    deactivate:      'تعطيل',
    deactivateTitle: 'تعطيل المادة العلفية',
    deactivateMsg:   'لن يتم حذف هذه المادة — ستظل بيانات الاستهلاك محفوظة. هل تريد المتابعة؟',
    confirm:         'تعطيل',
    supplierPfx:     'المورد: ',
    egp:             'ج.م',
    per:             '/',
    reset:           'إعادة تعيين',
    apply:           'تطبيق',
} : {
    eyebrow:         'Feed & Weight',
    title:           'Feed Items',
    sub:             'Manage your feed catalog and nutritional profiles',
    addItem:         'New Feed Item',
    filtersBtn:      'Filters',
    searchPh:        'Search feed items...',
    allTypes:        'All Types',
    typeLabel:       'Type',
    noItems:         'No feed items found',
    noItemsSub:      'Add your first feed item to start building ration plans',
    panelCreate:     'New Feed Item',
    panelEdit:       'Edit Feed Item',
    s1Title:         'Identity & Classification',
    s1Sub:           'Name, type and unit of measure',
    nameEnLabel:     'Feed Item Name (English)',
    nameEnPh:        'e.g. Berseem Hay',
    nameArLabel:     'Feed Item Name (Arabic)',
    nameArPh:        'e.g. برسيم جاف',
    typeFieldLabel:  'Type *',
    unitLabel:       'Unit *',
    s2Title:         'Nutritional Values',
    s2Sub:           'Optional — used in ration calculations and FCR analysis',
    nutritionHint:   'Leave blank if unknown. CP = Crude Protein, TDN = Total Digestible Nutrients, DM = Dry Matter',
    s3Title:         'Pricing & Supply',
    s3Sub:           'Unit cost and supplier',
    costLabel:       'Cost per Unit (EGP) *',
    supplierLabel:   'Supplier (optional)',
    supplierPh:      '— No supplier —',
    notesLabel:      'Notes',
    notesPh:         'Any additional notes...',
    cancel:          'Cancel',
    saving:          'Saving...',
    save:            'Save',
    edit:            'Edit',
    deactivate:      'Deactivate',
    deactivateTitle: 'Deactivate Feed Item',
    deactivateMsg:   'This item will not be deleted — consumption history is preserved. Continue?',
    confirm:         'Deactivate',
    supplierPfx:     'Supplier: ',
    egp:             'EGP',
    per:             '/',
    reset:           'Reset',
    apply:           'Apply',
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
                        {{ t.addItem }}
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

                <!-- ── Filter panel ───────────────────────────────── -->
                <Transition name="slide-down">
                    <div v-if="filtersOpen" class="cv-card mb-6 p-4">
                        <div class="grid gap-3"
                             style="grid-template-columns: repeat(auto-fill, minmax(180px,1fr))">
                            <div>
                                <label class="cv-label">{{ t.searchPh }}</label>
                                <input v-model="search" type="text" class="cv-input"
                                       :placeholder="t.searchPh"/>
                            </div>
                            <div>
                                <label class="cv-label">{{ t.typeLabel }}</label>
                                <select v-model="typeFilter" @change="applyTypeFilter"
                                        class="cv-select">
                                    <option value="">{{ t.allTypes }}</option>
                                    <option v-for="opt in typeOptions"
                                            :key="opt.value" :value="opt.value">
                                        {{ opt.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-4">
                            <button class="cv-btn cv-btn-amber" @click="applyTypeFilter">{{ t.apply }}</button>
                            <button class="cv-btn cv-btn-ghost" @click="typeFilter = ''; search = ''; applyTypeFilter()">{{ t.reset }}</button>
                        </div>
                    </div>
                </Transition>

                <!-- ── Card grid ──────────────────────────────────── -->
                <div v-if="filteredItems.length"
                     class="cv-grid"
                     style="grid-template-columns: repeat(auto-fill, minmax(300px,1fr)); gap:1rem;">

                    <div v-for="item in filteredItems" :key="item.id"
                         class="cv-card cv-feed-card"
                         :style="`border-inline-start: 4px solid ${typeConfig[item.type]?.stripe ?? 'var(--cv-green-bright)'}`">

                        <!-- Top row: type badge + cost -->
                        <div class="flex items-center justify-between mb-3">
                            <span class="cv-badge"
                                  :style="`background:${typeConfig[item.type]?.bg};color:${typeConfig[item.type]?.stripe};`">
                                {{ typeConfig[item.type]?.label }}
                            </span>
                            <span class="font-bold text-sm" style="color:var(--cv-blue-text)">
                                {{ fmtCost(item.unit_cost) }}
                                <span style="color:var(--cv-text-muted);font-weight:400;font-size:0.75rem">
                                    {{ t.egp }}{{ t.per }}{{ item.unit }}
                                </span>
                            </span>
                        </div>

                        <!-- Name + avatar -->
                        <div class="flex items-center gap-2 mb-2">
                            <div class="cv-avatar-sm"
                                 :style="`background:linear-gradient(135deg,${typeConfig[item.type]?.stripe},${typeConfig[item.type]?.stripe}88)`">
                                <svg viewBox="0 0 24 24" fill="none" stroke="white"
                                     stroke-width="1.7" width="14" height="14">
                                    <path d="M12 22V12"/>
                                    <path d="M12 12C12 7 8 4 8 4s0 5 4 8z"/>
                                    <path d="M12 12c0-5 4-8 4-8s0 5-4 8z"/>
                                </svg>
                            </div>
                            <div class="font-semibold text-sm" style="color:var(--cv-text-primary)">
                                {{ n(item, 'name') }}
                            </div>
                        </div>

                        <!-- Nutritional chips -->
                        <div v-if="fmt(item.cp_pct) || fmt(item.tdn_pct) || fmt(item.dm_pct)"
                             class="flex flex-wrap gap-1 mb-3">
                            <span v-if="fmt(item.cp_pct)"  class="nutr-chip chip-green">CP {{ fmt(item.cp_pct, '%') }}</span>
                            <span v-if="fmt(item.tdn_pct)" class="nutr-chip chip-amber">TDN {{ fmt(item.tdn_pct, '%') }}</span>
                            <span v-if="fmt(item.dm_pct)"  class="nutr-chip chip-blue">DM {{ fmt(item.dm_pct, '%') }}</span>
                            <span v-if="fmt(item.ndf_pct)" class="nutr-chip chip-muted">NDF {{ fmt(item.ndf_pct, '%') }}</span>
                        </div>

                        <!-- Supplier -->
                        <div v-if="item.supplier" class="text-xs mb-3"
                             style="color:var(--cv-text-muted)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" width="11" height="11"
                                 style="display:inline;margin-inline-end:3px">
                                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                            </svg>
                            {{ t.supplierPfx }}{{ n(item.supplier, 'name') }}
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2 mt-auto pt-3"
                             style="border-top:1px solid var(--cv-border-muted)">
                            <button v-if="can.edit"
                                    class="cv-btn cv-btn-ghost cv-btn-sm"
                                    @click="openEdit(item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="13" height="13">
                                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                {{ t.edit }}
                            </button>
                            <button v-if="can.delete"
                                    class="cv-btn cv-btn-danger-ghost cv-btn-sm"
                                    @click="confirmDeactivate(item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="13" height="13">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                                </svg>
                                {{ t.deactivate }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Empty state ─────────────────────────────────── -->
                <div v-else class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.3" width="48" height="48">
                            <path d="M12 22V12"/>
                            <path d="M12 12C12 7 8 4 8 4s0 5 4 8z"/>
                            <path d="M12 12c0-5 4-8 4-8s0 5-4 8z"/>
                            <path d="M12 17c-2.5 0-4-2-4-2s1.5-2 4-2 4 2 4 2-1.5 2-4 2z"/>
                        </svg>
                    </div>
                    <div class="cv-empty-title">{{ t.noItems }}</div>
                    <div class="cv-empty-sub">{{ t.noItemsSub }}</div>
                    <button v-if="can.create" class="cv-btn cv-btn-amber mt-4" @click="openCreate">
                        {{ t.addItem }}
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
                                        <path d="M12 22V12"/>
                                        <path d="M12 12C12 7 8 4 8 4s0 5 4 8z"/>
                                        <path d="M12 12c0-5 4-8 4-8s0 5-4 8z"/>
                                    </svg>
                                </div>
                                <div>
                                    <div class="panel-title">
                                        {{ isEditMode ? t.panelEdit : t.panelCreate }}
                                    </div>
                                    <div v-if="isEditMode" class="panel-subtitle">
                                        {{ n(editTarget, 'name') }}
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

                                <!-- Section 1: Identity -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head">
                                        <div class="sec-badge sec-1">1</div>
                                        <div>
                                            <div style="font-weight:600;font-size:0.875rem;color:var(--cv-text-primary)">{{ t.s1Title }}</div>
                                            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s1Sub }}</div>
                                        </div>
                                    </div>

                                    <!-- Name EN §5.5 -->
                                    <div class="cv-field mt-3" v-show="lang === 'en'">
                                        <label class="cv-label">{{ t.nameEnLabel }}</label>
                                        <input v-model="form.name_en" type="text" class="cv-input"
                                               :class="{ 'cv-input-error': form.errors.name_en }"
                                               :placeholder="t.nameEnPh"/>
                                        <p v-if="form.errors.name_en" class="cv-field-error">{{ form.errors.name_en }}</p>
                                    </div>

                                    <!-- Name AR §5.5 -->
                                    <div class="cv-field mt-3" v-show="lang === 'ar'">
                                        <label class="cv-label">{{ t.nameArLabel }}</label>
                                        <input v-model="form.name_ar" type="text" class="cv-input"
                                               :class="{ 'cv-input-error': form.errors.name_ar }"
                                               :placeholder="t.nameArPh" dir="rtl"/>
                                        <p v-if="form.errors.name_ar" class="cv-field-error">{{ form.errors.name_ar }}</p>
                                    </div>

                                    <div class="grid gap-3 mt-3"
                                         style="grid-template-columns:1fr 1fr">
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.typeFieldLabel }}</label>
                                            <select v-model="form.type" class="cv-select">
                                                <option v-for="opt in typeOptions"
                                                        :key="opt.value" :value="opt.value">
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">{{ t.unitLabel }}</label>
                                            <select v-model="form.unit" class="cv-select">
                                                <option v-for="u in unitPresets" :key="u" :value="u">{{ u }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Section 2: Nutrition -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head">
                                        <div class="sec-badge sec-2">2</div>
                                        <div>
                                            <div style="font-weight:600;font-size:0.875rem;color:var(--cv-text-primary)">{{ t.s2Title }}</div>
                                            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s2Sub }}</div>
                                        </div>
                                    </div>
                                    <div class="nutr-hint mt-3">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="1.7" width="13" height="13"
                                             style="color:var(--cv-blue-text);flex-shrink:0;margin-top:1px">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        <span>{{ t.nutritionHint }}</span>
                                    </div>
                                    <div class="grid gap-3 mt-3"
                                         style="grid-template-columns:1fr 1fr 1fr">
                                        <div class="cv-field">
                                            <label class="cv-label">CP %</label>
                                            <input v-model="form.cp_pct" type="number"
                                                   step="0.1" min="0" max="100"
                                                   class="cv-input" placeholder="0.0"/>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">TDN %</label>
                                            <input v-model="form.tdn_pct" type="number"
                                                   step="0.1" min="0" max="100"
                                                   class="cv-input" placeholder="0.0"/>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">DM %</label>
                                            <input v-model="form.dm_pct" type="number"
                                                   step="0.1" min="0" max="100"
                                                   class="cv-input" placeholder="0.0"/>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">NDF %</label>
                                            <input v-model="form.ndf_pct" type="number"
                                                   step="0.1" min="0" max="100"
                                                   class="cv-input" placeholder="0.0"/>
                                        </div>
                                        <div class="cv-field">
                                            <label class="cv-label">ME (MJ/kg)</label>
                                            <input v-model="form.me_mj" type="number"
                                                   step="0.01" min="0"
                                                   class="cv-input" placeholder="0.00"/>
                                        </div>
                                    </div>
                                </div>

                                <!-- Section 3: Pricing -->
                                <div class="cv-card p-4 mb-4">
                                    <div class="section-head">
                                        <div class="sec-badge sec-3">3</div>
                                        <div>
                                            <div style="font-weight:600;font-size:0.875rem;color:var(--cv-text-primary)">{{ t.s3Title }}</div>
                                            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s3Sub }}</div>
                                        </div>
                                    </div>
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">{{ t.costLabel }}</label>
                                        <div class="input-prefix-wrap">
                                            <span class="input-prefix">EGP</span>
                                            <input v-model="form.unit_cost" type="number"
                                                   step="0.01" min="0"
                                                   class="cv-input input-prefixed"
                                                   :class="{ 'cv-input-error': form.errors.unit_cost }"
                                                   placeholder="0.00"/>
                                        </div>
                                        <p v-if="form.errors.unit_cost" class="cv-field-error">{{ form.errors.unit_cost }}</p>
                                    </div>
                                    <div class="cv-field mt-3">
                                        <label class="cv-label">{{ t.supplierLabel }}</label>
                                        <select v-model="form.supplier_id" class="cv-select">
                                            <option value="">{{ t.supplierPh }}</option>
                                            <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                                {{ n(s, 'name') }}
                                            </option>
                                        </select>
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
                                {{ t.cancel }}
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

            <!-- ═══ DEACTIVATE MODAL ══════════════════════════════ -->
            <Transition name="fade">
                <div v-if="deactivateModal"
                     class="cv-modal-backdrop"
                     @click.self="deactivateModal = false">
                    <div class="cv-modal">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon"
                                 style="background:rgba(232,160,48,0.15);color:var(--cv-amber)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" width="28" height="28">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <h3 class="cv-modal-title">{{ t.deactivateTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <p class="font-semibold mb-2" style="color:var(--cv-text-primary)">
                                {{ n(deactivateTarget, 'name') }}
                            </p>
                            <p>{{ t.deactivateMsg }}</p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deactivateModal = false">
                                {{ t.cancel }}
                            </button>
                            <button class="cv-btn cv-btn-danger" @click="doDeactivate">
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

.cv-feed-card {
    display: flex;
    flex-direction: column;
    padding: 1rem;
    transition: box-shadow 0.2s, transform 0.15s;
}
.cv-feed-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}

.cv-avatar-sm {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.nutr-chip {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
}
.chip-green { background: rgba(92,196,106,0.12);  color: var(--cv-green-bright); }
.chip-amber { background: rgba(232,160,48,0.12);  color: var(--cv-amber); }
.chip-blue  { background: rgba(96,168,224,0.12);  color: var(--cv-blue-text); }
.chip-muted { background: rgba(85,112,96,0.12);   color: var(--cv-text-muted); }

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
    width: 100%; max-width: 480px;
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
    background: linear-gradient(135deg, var(--cv-green-bright), #2e9048);
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

.input-prefix-wrap { display: flex; align-items: stretch; }
.input-prefix {
    display: flex; align-items: center;
    padding: 0 0.7rem;
    background: var(--cv-bg-card-alt, #132218);
    border: 1px solid var(--cv-border);
    border-inline-end: none;
    border-radius: 0.45rem 0 0 0.45rem;
    font-size: 0.75rem; font-weight: 600;
    color: var(--cv-text-muted);
    white-space: nowrap; flex-shrink: 0;
}
[dir="rtl"] .input-prefix {
    border-inline-end: 1px solid var(--cv-border);
    border-inline-start: none;
    border-radius: 0 0.45rem 0.45rem 0;
}
.input-prefixed { border-radius: 0 0.45rem 0.45rem 0; }
[dir="rtl"] .input-prefixed { border-radius: 0.45rem 0 0 0.45rem; }

.nutr-hint {
    display: flex; align-items: flex-start; gap: 0.5rem;
    font-size: 0.75rem; color: var(--cv-text-muted);
    background: rgba(96,168,224,0.08);
    border: 1px solid rgba(96,168,224,0.18);
    border-radius: 0.45rem;
    padding: 0.5rem 0.75rem;
    line-height: 1.4;
}

/* Transitions */
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
}
</style>