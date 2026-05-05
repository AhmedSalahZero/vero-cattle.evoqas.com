<script setup>
/**
 * Calvings/Index.vue
 * ─────────────────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Calving Records
 *
 * Index-only pattern — all CRUD via slide-in panels. URL stays /calvings.
 *
 * Props (from CalvingController::index):
 *   records   — paginated(30): id, dam{id,tag}, calf{id,tag,sex}|null,
 *               farm_id, calving_date, calving_time, birth_weight_kg,
 *               condition, difficulty_score, colostrum_fed, notes,
 *               breeding_record_id
 *   farms     — [{id, name_en, name_ar}]
 *   filters   — {farm_id, condition, date_from, date_to}
 *   user.permissions  — string
 *
 * Bilingual:
 *   No _en/_ar field pairs on calving_records (all operational fields).
 *   All UI labels/badges/placeholders switch between AR ↔ EN via useLocale().
 *   Farm names resolved via n(farm, 'name').
 *   Arabic mode → ALL words in Arabic.
 */
import { ref, computed, watch } from 'vue'
import { useForm, usePage, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    records:  Object,
    farms:    Array,
    filters:  Object,
})

// ── Locale ────────────────────────────────────────────────────────
const { locale: lang, isRtl, n } = useLocale()

// ── Permission helpers ────────────────────────────────────────────
const page     = usePage()
const authUser = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const { can } = usePermissions()
const canCreate = computed(() => can('create calvings'))
const canEdit   = computed(() => can('edit calvings'))
const canDelete = computed(() => can('delete calvings'))

// ── Filters ───────────────────────────────────────────────────────
const filterFarm      = ref(props.filters?.farm_id   ?? '')
const filterCondition = ref(props.filters?.condition ?? '')
const filterDateFrom  = ref(props.filters?.date_from ?? '')
const filterDateTo    = ref(props.filters?.date_to   ?? '')

function applyFilters() {
    router.get(route('calvings.index'), {
        farm_id:   filterFarm.value      || undefined,
        condition: filterCondition.value || undefined,
        date_from: filterDateFrom.value  || undefined,
        date_to:   filterDateTo.value    || undefined,
    }, { preserveState: true, replace: true })
}

watch([filterFarm, filterCondition, filterDateFrom, filterDateTo], applyFilters)

// ── Stats chips ───────────────────────────────────────────────────
const totalRecords   = computed(() => props.records?.meta?.total ?? props.records?.data?.length ?? 0)
const healthyCount   = computed(() => props.records?.data?.filter(r => r.condition === 'healthy').length ?? 0)
const weakCount      = computed(() => props.records?.data?.filter(r => r.condition === 'weak').length ?? 0)
const stillbornCount = computed(() => props.records?.data?.filter(r => r.condition === 'stillborn').length ?? 0)

// ── Condition badge ───────────────────────────────────────────────
const conditionMap = {
    healthy:   { en: 'Healthy',   ar: 'سليم',         color: 'success' },
    weak:      { en: 'Weak',      ar: 'ضعيف',         color: 'warning' },
    stillborn: { en: 'Stillborn', ar: 'مولود ميت',    color: 'danger'  },
}
function conditionLabel(c) { return lang.value === 'ar' ? (conditionMap[c]?.ar ?? c) : (conditionMap[c]?.en ?? c) }
function conditionColor(c) { return conditionMap[c]?.color ?? 'muted' }

// ── Sex display ───────────────────────────────────────────────────
function sexLabel(s) {
    if (!s) return '—'
    if (s === 'male')      return lang.value === 'ar' ? 'ذكر'   : 'Male'
    if (s === 'female')    return lang.value === 'ar' ? 'أنثى'  : 'Female'
    if (s === 'castrated') return lang.value === 'ar' ? 'خصي'   : 'Castrated'
    return s
}
function sexColor(s) {
    if (s === 'male')   return 'info'
    if (s === 'female') return 'success'
    return 'muted'
}

// ── Difficulty dots display ───────────────────────────────────────
// Score 1 (easy) → 5 (very difficult)
function difficultyLabel(score) {
    if (!score) return '—'
    const labels = {
        en: ['', 'Easy', 'Minor assist', 'Needed help', 'Major help', 'Extreme'],
        ar: ['', 'سهلة', 'مساعدة بسيطة', 'تطلبت مساعدة', 'مساعدة كبيرة', 'صعوبة شديدة'],
    }
    return lang.value === 'ar' ? labels.ar[score] : labels.en[score]
}

// ── Animal options for dam/calf select ───────────────────────────
const damOptions  = ref([])
const calfOptions = ref([])

async function loadFarmAnimals(farmId) {
    if (!farmId) { damOptions.value = []; calfOptions.value = []; return }
    try {
        const res  = await fetch(`/api/lots-animals?farm_id=${farmId}&status=active`)
        const data = await res.json()
        damOptions.value  = data.filter(a => a.sex === 'female')
        calfOptions.value = data
    } catch {
        damOptions.value = []; calfOptions.value = []
    }
}

// ── Panel state ───────────────────────────────────────────────────
const panelOpen  = ref(false)
const panelMode  = ref('create')
const editRecord = ref(null)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    farm_id:            '',
    dam_id:             '',
    calf_id:            '',
    breeding_record_id: '',
    calving_date:       '',
    calving_time:       '',
    birth_weight_kg:    '',
    condition:          'healthy',
    difficulty_score:   1,
    colostrum_fed:      false,
    notes:              '',
})

function openCreate() {
    form.reset()
    form.clearErrors()
    editRecord.value = null
    panelMode.value  = 'create'
    panelOpen.value  = true
}

function openEdit(record) {
    form.farm_id            = record.farm_id            ?? ''
    form.dam_id             = record.dam_id             ?? ''
    form.calf_id            = record.calf_id            ?? ''
    form.breeding_record_id = record.breeding_record_id ?? ''
    form.calving_date       = record.calving_date       ?? ''
    form.calving_time       = record.calving_time       ?? ''
    form.birth_weight_kg    = record.birth_weight_kg    ?? ''
    form.condition          = record.condition          ?? 'healthy'
    form.difficulty_score   = record.difficulty_score   ?? 1
    form.colostrum_fed      = !!record.colostrum_fed
    form.notes              = record.notes              ?? ''
    form.clearErrors()
    editRecord.value = record
    panelMode.value  = 'edit'
    panelOpen.value  = true
    loadFarmAnimals(record.farm_id)
}

function closePanel() {
    panelOpen.value  = false
    editRecord.value = null
}

watch(() => form.farm_id, (fid) => {
    form.dam_id  = ''
    form.calf_id = ''
    loadFarmAnimals(fid)
})

function submitForm() {
    if (panelMode.value === 'create') {
        form.post(route('calvings.store'), {
            preserveScroll: true,
            onSuccess: () => closePanel(),
        })
    } else {
        form.put(route('calvings.update', editRecord.value.id), {
            preserveScroll: true,
            onSuccess: () => closePanel(),
        })
    }
}

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal  = ref(false)
const deleteTarget = ref(null)

function confirmDelete(record) {
    deleteTarget.value = record
    deleteModal.value  = true
}

function executeDelete() {
    router.delete(route('calvings.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { deleteModal.value = false; deleteTarget.value = null },
    })
}

// ── Farm name helper ──────────────────────────────────────────────
function farmName(farmId) {
    const f = props.farms?.find(x => x.id === farmId)
    return f ? n(f) : '—'
}
</script>

<template>
    <AuthenticatedLayout>

        <!-- ═══════════════════════════════════════════════════════════
             PAGE HERO
        ═══════════════════════════════════════════════════════════════ -->
        <div class="cv-page-hero" :dir="isRtl ? 'rtl' : 'ltr'">
            <div class="cv-page-hero-eyebrow">
                {{ lang === 'ar' ? 'صحة القطيع' : 'Herd Health' }}
            </div>
            <div class="cv-page-hero-title">
                {{ lang === 'ar' ? 'سجلات الولادة' : 'Calving Records' }}
            </div>
            <div class="cv-page-hero-sub">
                {{ lang === 'ar'
                    ? 'تسجيل أحداث الولادة وبيانات المولود الجديد'
                    : 'Record calving events, calf details and birth conditions' }}
            </div>
            <div class="cv-page-hero-actions">
                <button v-if="canCreate" class="cv-btn cv-btn-amber" @click="openCreate">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:16px;height:16px">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    {{ lang === 'ar' ? 'تسجيل ولادة' : 'Record Calving' }}
                </button>
            </div>
        </div>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- Stats row -->
            <div class="stats-row">
                <div class="stat-chip stat-chip--blue">
                    <span class="stat-chip__value">{{ totalRecords }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'إجمالي الولادات' : 'Total Calvings' }}</span>
                </div>
                <div class="stat-chip stat-chip--green">
                    <span class="stat-chip__value">{{ healthyCount }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'مواليد سليمة' : 'Healthy Calves' }}</span>
                </div>
                <div class="stat-chip stat-chip--amber">
                    <span class="stat-chip__value">{{ weakCount }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'مواليد ضعيفة' : 'Weak Calves' }}</span>
                </div>
                <div class="stat-chip stat-chip--danger">
                    <span class="stat-chip__value">{{ stillbornCount }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'مواليد ميتة' : 'Stillborn' }}</span>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <select v-model="filterFarm" class="cv-select filter-select">
                    <option value="">{{ lang === 'ar' ? 'كل المزارع' : 'All Farms' }}</option>
                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                </select>

                <select v-model="filterCondition" class="cv-select filter-select">
                    <option value="">{{ lang === 'ar' ? 'كل الحالات' : 'All Conditions' }}</option>
                    <option value="healthy">{{ lang === 'ar' ? 'سليم' : 'Healthy' }}</option>
                    <option value="weak">{{ lang === 'ar' ? 'ضعيف' : 'Weak' }}</option>
                    <option value="stillborn">{{ lang === 'ar' ? 'مولود ميت' : 'Stillborn' }}</option>
                </select>

                <input v-model="filterDateFrom" type="date" class="cv-input filter-date"
                    :placeholder="lang === 'ar' ? 'من تاريخ' : 'Date from'" />
                <input v-model="filterDateTo"   type="date" class="cv-input filter-date"
                    :placeholder="lang === 'ar' ? 'إلى تاريخ' : 'Date to'" />
            </div>

            <!-- Table card -->
            <div class="cv-card table-card">
                <div class="table-wrap">
                    <table class="cv-table">
                        <thead>
                            <tr>
                                <th class="text-start">{{ lang === 'ar' ? 'تاريخ الولادة' : 'Calving Date' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الأم' : 'Dam' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'العجل' : 'Calf' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الحالة' : 'Condition' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'درجة الصعوبة' : 'Difficulty' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'وزن الميلاد' : 'Birth Weight' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'اللبأ' : 'Colostrum' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الإجراءات' : 'Actions' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!records?.data?.length">
                                <td colspan="9" class="empty-cell">
                                    {{ lang === 'ar' ? 'لا توجد سجلات ولادة' : 'No calving records found' }}
                                </td>
                            </tr>
                            <tr v-for="record in records?.data" :key="record.id" class="table-row">

                                <!-- Calving date + time -->
                                <td>
                                    <div class="date-cell">
                                        <span class="date-val">{{ record.calving_date }}</span>
                                        <span v-if="record.calving_time" class="time-val">{{ record.calving_time }}</span>
                                    </div>
                                </td>

                                <!-- Dam -->
                                <td>
                                    <div class="animal-cell">
                                        <span class="animal-tag">{{ record.dam?.tag ?? '—' }}</span>
                                    </div>
                                </td>

                                <!-- Calf -->
                                <td>
                                    <div v-if="record.calf" class="animal-cell">
                                        <span class="animal-tag">{{ record.calf.tag }}</span>
                                        <span class="cv-badge" :class="`cv-badge-${sexColor(record.calf.sex)}`" style="font-size:0.68rem">
                                            {{ sexLabel(record.calf.sex) }}
                                        </span>
                                    </div>
                                    <span v-else class="text-muted">—</span>
                                </td>

                                <!-- Farm -->
                                <td>
                                    <span class="farm-name">{{ farmName(record.farm_id) }}</span>
                                </td>

                                <!-- Condition -->
                                <td>
                                    <span class="cv-badge" :class="`cv-badge-${conditionColor(record.condition)}`">
                                        {{ conditionLabel(record.condition) }}
                                    </span>
                                </td>

                                <!-- Difficulty score -->
                                <td>
                                    <div class="difficulty-cell">
                                        <div class="difficulty-dots">
                                            <span
                                                v-for="i in 5" :key="i"
                                                class="difficulty-dot"
                                                :class="i <= (record.difficulty_score ?? 1) ? `dot--${record.difficulty_score <= 2 ? 'green' : record.difficulty_score <= 3 ? 'amber' : 'red'}` : 'dot--empty'"
                                            />
                                        </div>
                                        <span class="difficulty-label">{{ difficultyLabel(record.difficulty_score) }}</span>
                                    </div>
                                </td>

                                <!-- Birth weight -->
                                <td>
                                    <span v-if="record.birth_weight_kg" class="weight-val">
                                        {{ record.birth_weight_kg }}
                                        <span class="weight-unit">{{ lang === 'ar' ? 'كجم' : 'kg' }}</span>
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>

                                <!-- Colostrum -->
                                <td>
                                    <span v-if="record.colostrum_fed" class="colostrum-yes">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        {{ lang === 'ar' ? 'تم' : 'Fed' }}
                                    </span>
                                    <span v-else class="colostrum-no">{{ lang === 'ar' ? 'لا' : 'No' }}</span>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="action-row">
                                        <button v-if="canEdit" class="cv-btn cv-btn-ghost action-btn" @click="openEdit(record)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:14px;height:14px">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                            {{ lang === 'ar' ? 'تعديل' : 'Edit' }}
                                        </button>
                                        <button v-if="canDelete" class="cv-btn action-btn action-btn--danger" @click="confirmDelete(record)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:14px;height:14px">
                                                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                                <path d="M9 6V4h6v2"/>
                                            </svg>
                                            {{ lang === 'ar' ? 'حذف' : 'Delete' }}
                                        </button>
                                    </div>
                                </td>

                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="records?.links?.length > 3" class="pagination-row">
                    <template v-for="link in records.links" :key="link.label">
                        <button
                            class="page-btn"
                            :class="{ 'page-btn--active': link.active, 'page-btn--disabled': !link.url }"
                            :disabled="!link.url"
                            @click="link.url && router.get(link.url, {}, { preserveScroll: true })"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>

        </div><!-- /cv-page -->

        <!-- ═══════════════════════════════════════════════════════════
             SLIDE-IN PANEL — Create / Edit
        ═══════════════════════════════════════════════════════════════ -->
        <Transition name="panel">
            <div v-if="panelOpen" class="panel-backdrop" @click.self="closePanel">
                <div class="panel" :dir="isRtl ? 'rtl' : 'ltr'">

                    <!-- Panel header -->
                    <div class="panel-header">
                        <div class="panel-header__left">
                            <div class="panel-icon panel-icon--blue">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:18px;height:18px">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            </div>
                            <div>
                                <div class="panel-header__title">
                                    {{ panelMode === 'create'
                                        ? (lang === 'ar' ? 'تسجيل ولادة جديدة' : 'Record Calving Event')
                                        : (lang === 'ar' ? 'تعديل سجل الولادة'  : 'Edit Calving Record') }}
                                </div>
                                <div class="panel-header__sub">
                                    {{ lang === 'ar' ? 'أدخل بيانات الولادة والمولود الجديد' : 'Enter calving event and newborn calf details' }}
                                </div>
                            </div>
                        </div>
                        <button class="panel-close" @click="closePanel">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:18px;height:18px">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Panel body -->
                    <div class="panel-body">

                        <!-- Section: Event Details -->
                        <div class="form-section">
                            <div class="form-section__title">
                                <span class="section-num">١</span>
                                {{ lang === 'ar' ? 'تفاصيل الحدث' : 'Event Details' }}
                            </div>

                            <!-- Farm -->
                            <div class="form-field">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'المزرعة' : 'Farm' }}
                                    <span class="required-star">*</span>
                                </label>
                                <select v-model="form.farm_id" class="cv-select">
                                    <option value="">{{ lang === 'ar' ? 'اختر المزرعة' : 'Select farm' }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                                </select>
                                <div v-if="form.errors.farm_id" class="cv-error">{{ form.errors.farm_id }}</div>
                            </div>

                            <!-- Dam -->
                            <div class="form-field">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'الأم' : 'Dam' }}
                                    <span class="required-star">*</span>
                                </label>
                                <select v-model="form.dam_id" class="cv-select" :disabled="!form.farm_id">
                                    <option value="">{{ lang === 'ar' ? 'اختر الأم' : 'Select dam' }}</option>
                                    <option v-for="a in damOptions" :key="a.id" :value="a.id">
                                        {{ a.tag }}{{ a.breed ? ` — ${a.breed}` : '' }}
                                    </option>
                                </select>
                                <div v-if="form.errors.dam_id" class="cv-error">{{ form.errors.dam_id }}</div>
                                <div v-if="!form.farm_id" class="cv-hint">
                                    {{ lang === 'ar' ? 'اختر المزرعة أولاً' : 'Select a farm first' }}
                                </div>
                            </div>

                            <!-- Date + Time (two-column) -->
                            <div class="form-row-2">
                                <div class="form-field">
                                    <label class="cv-label">
                                        {{ lang === 'ar' ? 'تاريخ الولادة' : 'Calving Date' }}
                                        <span class="required-star">*</span>
                                    </label>
                                    <input v-model="form.calving_date" type="date" class="cv-input" />
                                    <div v-if="form.errors.calving_date" class="cv-error">{{ form.errors.calving_date }}</div>
                                </div>
                                <div class="form-field">
                                    <label class="cv-label">{{ lang === 'ar' ? 'وقت الولادة' : 'Calving Time' }}</label>
                                    <input v-model="form.calving_time" type="time" class="cv-input" />
                                    <div v-if="form.errors.calving_time" class="cv-error">{{ form.errors.calving_time }}</div>
                                </div>
                            </div>

                            <!-- Condition -->
                            <div class="form-field">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? 'حالة المولود' : 'Calf Condition' }}
                                    <span class="required-star">*</span>
                                </label>
                                <div class="condition-toggle">
                                    <button v-for="cond in ['healthy','weak','stillborn']" :key="cond"
                                        type="button"
                                        class="condition-btn"
                                        :class="[`condition-btn--${cond}`, { 'condition-btn--active': form.condition === cond }]"
                                        @click="form.condition = cond"
                                    >
                                        <svg v-if="cond === 'healthy'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:13px;height:13px">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        <svg v-else-if="cond === 'weak'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:13px;height:13px">
                                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:13px;height:13px">
                                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                        </svg>
                                        {{ conditionLabel(cond) }}
                                    </button>
                                </div>
                                <div v-if="form.errors.condition" class="cv-error">{{ form.errors.condition }}</div>
                            </div>

                            <!-- Difficulty score (1–5) -->
                            <div class="form-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'درجة صعوبة الولادة' : 'Calving Difficulty Score' }}</label>
                                <div class="difficulty-selector">
                                    <button
                                        v-for="i in 5" :key="i"
                                        type="button"
                                        class="diff-btn"
                                        :class="{ 'diff-btn--active': form.difficulty_score === i, [`diff-btn--${i <= 2 ? 'green' : i <= 3 ? 'amber' : 'red'}`]: form.difficulty_score === i }"
                                        @click="form.difficulty_score = i"
                                    >{{ i }}</button>
                                </div>
                                <div class="difficulty-desc">
                                    {{ difficultyLabel(form.difficulty_score) }}
                                </div>
                                <div v-if="form.errors.difficulty_score" class="cv-error">{{ form.errors.difficulty_score }}</div>
                            </div>
                        </div>

                        <!-- Section: Calf Details -->
                        <div class="form-section">
                            <div class="form-section__title">
                                <span class="section-num">٢</span>
                                {{ lang === 'ar' ? 'بيانات المولود' : 'Calf Details' }}
                            </div>

                            <!-- Calf (if already registered as animal) -->
                            <div class="form-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'العجل (إذا كان مسجلاً)' : 'Calf Animal (if already registered)' }}</label>
                                <select v-model="form.calf_id" class="cv-select" :disabled="!form.farm_id">
                                    <option value="">{{ lang === 'ar' ? 'اختر أو اتركه فارغاً' : 'Select or leave blank' }}</option>
                                    <option v-for="a in calfOptions" :key="a.id" :value="a.id">
                                        {{ a.tag }}{{ a.breed ? ` — ${a.breed}` : '' }}
                                    </option>
                                </select>
                                <div class="cv-hint">
                                    {{ lang === 'ar' ? 'يمكن ربط العجل لاحقاً بعد تسجيله في وحدة الماشيةات' : 'Can be linked later after registering in Animals module' }}
                                </div>
                                <div v-if="form.errors.calf_id" class="cv-error">{{ form.errors.calf_id }}</div>
                            </div>

                            <!-- Birth weight -->
                            <div class="form-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'وزن الميلاد (كجم)' : 'Birth Weight (kg)' }}</label>
                                <input v-model="form.birth_weight_kg" type="number" step="0.1" min="0" max="200" class="cv-input"
                                    :placeholder="lang === 'ar' ? 'مثال: ٣٢.٥' : 'e.g. 32.5'" />
                                <div v-if="form.errors.birth_weight_kg" class="cv-error">{{ form.errors.birth_weight_kg }}</div>
                            </div>

                            <!-- Colostrum fed -->
                            <div class="form-field">
                                <div class="colostrum-toggle">
                                    <button
                                        type="button"
                                        class="toggle-switch"
                                        :class="{ 'toggle-switch--on': form.colostrum_fed }"
                                        @click="form.colostrum_fed = !form.colostrum_fed"
                                    >
                                        <span class="toggle-knob"></span>
                                    </button>
                                    <span class="cv-label" style="margin-bottom:0">
                                        {{ lang === 'ar' ? 'تم تقديم اللبأ (الحليب الأول)' : 'Colostrum fed to calf' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Linked Breeding Record -->
                        <div class="form-section">
                            <div class="form-section__title">
                                <span class="section-num">٣</span>
                                {{ lang === 'ar' ? 'ربط بسجل التلقيح' : 'Link to Breeding Record' }}
                            </div>

                            <div class="form-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'رقم سجل التلقيح' : 'Breeding Record ID' }}</label>
                                <input v-model="form.breeding_record_id" type="number" class="cv-input"
                                    :placeholder="lang === 'ar' ? 'رقم سجل التلقيح (اختياري)' : 'Breeding record ID (optional)'" />
                                <div class="cv-hint">
                                    {{ lang === 'ar'
                                        ? 'عند الربط سيُحدَّث سجل التلقيح تلقائياً إلى «ولدت»'
                                        : 'When linked, the breeding record will auto-update to «Calved»' }}
                                </div>
                                <div v-if="form.errors.breeding_record_id" class="cv-error">{{ form.errors.breeding_record_id }}</div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="form-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</label>
                            <textarea v-model="form.notes" class="cv-textarea" rows="3"
                                :placeholder="lang === 'ar' ? 'أي ملاحظات إضافية...' : 'Any additional notes...'" />
                            <div v-if="form.errors.notes" class="cv-error">{{ form.errors.notes }}</div>
                        </div>

                    </div>

                    <!-- Panel footer -->
                    <div class="panel-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closePanel" :disabled="form.processing">
                            {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                        </button>
                        <button class="cv-btn cv-btn-amber" @click="submitForm" :disabled="form.processing">
                            <svg v-if="form.processing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:14px;height:14px" class="spin">
                                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83"/>
                            </svg>
                            {{ panelMode === 'create'
                                ? (lang === 'ar' ? 'حفظ السجل' : 'Save Record')
                                : (lang === 'ar' ? 'حفظ التعديلات' : 'Save Changes') }}
                        </button>
                    </div>

                </div>
            </div>
        </Transition>

        <!-- ═══════════════════════════════════════════════════════════
             DELETE CONFIRMATION MODAL
        ═══════════════════════════════════════════════════════════════ -->
        <Transition name="modal">
            <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
                <div class="cv-modal" :dir="isRtl ? 'rtl' : 'ltr'">
                    <div class="cv-modal-header">
                        <div class="modal-danger-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:22px;height:22px">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                        <div class="cv-modal-header__text">
                            <h3>{{ lang === 'ar' ? 'حذف سجل الولادة' : 'Delete Calving Record' }}</h3>
                            <p>
                                {{ lang === 'ar' ? 'هل تريد حذف سجل ولادة الأم' : 'Delete calving record for dam' }}
                                <strong>{{ deleteTarget?.dam?.tag }}</strong>
                                {{ lang === 'ar' ? 'بتاريخ' : 'dated' }}
                                <strong>{{ deleteTarget?.calving_date }}</strong>؟
                            </p>
                            <p class="delete-warning">
                                {{ lang === 'ar' ? 'هذا الإجراء لا يمكن التراجع عنه.' : 'This action cannot be undone.' }}
                            </p>
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">
                            {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                        </button>
                        <button class="cv-btn cv-btn-danger" @click="executeDelete">
                            {{ lang === 'ar' ? 'نعم، احذف السجل' : 'Yes, Delete Record' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Stats row ─────────────────────────────────────────────────── */
.stats-row {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.stat-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 14px 20px;
    border-radius: var(--cv-radius-md);
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-md);
    min-width: 120px;
    gap: 2px;
}
.stat-chip--blue   { border-color: var(--cv-blue-border);    background: var(--cv-blue-light); }
.stat-chip--green  { border-color: var(--cv-green-border);   background: var(--cv-green-dim); }
.stat-chip--amber  { border-color: var(--cv-amber-border);   background: var(--cv-amber-light); }
.stat-chip--danger { border-color: var(--cv-danger-border);  background: var(--cv-danger-bg); }
.stat-chip__value { font-size: 1.6rem; font-weight: 700; color: var(--cv-blue-text); line-height: 1; }
.stat-chip--green  .stat-chip__value { color: var(--cv-green-bright); }
.stat-chip--amber  .stat-chip__value { color: var(--cv-amber-text); }
.stat-chip--danger .stat-chip__value { color: var(--cv-danger-text); }
.stat-chip__label { font-size: 0.72rem; color: var(--cv-text-muted); text-align: center; }

/* ── Filter bar ────────────────────────────────────────────────── */
.filter-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-select { min-width: 160px; }
.filter-date   { max-width: 160px; }

/* ── Table card ────────────────────────────────────────────────── */
.table-card { padding: 0; overflow: hidden; }
.table-wrap { overflow-x: auto; }
.cv-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.cv-table thead tr { border-bottom: 1px solid var(--cv-border-md); }
.cv-table th {
    padding: 12px 16px;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--cv-text-muted);
    white-space: nowrap;
}
.cv-table td { padding: 13px 16px; border-bottom: 1px solid rgba(255,255,255,0.03); }
.table-row:hover { background: var(--cv-bg-hover); }
.empty-cell { text-align: center; color: var(--cv-text-muted); padding: 48px; }

/* ── Cell content ──────────────────────────────────────────────── */
.date-cell  { display: flex; flex-direction: column; gap: 2px; }
.date-val   { color: var(--cv-text-secondary); font-size: 0.83rem; }
.time-val   { color: var(--cv-text-muted); font-size: 0.72rem; }
.farm-name  { color: var(--cv-text-secondary); font-size: 0.83rem; }
.animal-cell { display: flex; flex-direction: column; gap: 3px; }
.animal-tag  { color: var(--cv-green-bright); font-weight: 600; font-size: 0.85rem; font-family: monospace; }

/* ── Difficulty ────────────────────────────────────────────────── */
.difficulty-cell   { display: flex; flex-direction: column; gap: 3px; }
.difficulty-dots   { display: flex; gap: 3px; }
.difficulty-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--cv-border-md);
}
.dot--green  { background: var(--cv-green-bright); }
.dot--amber  { background: var(--cv-amber); }
.dot--red    { background: var(--cv-danger); }
.dot--empty  { background: var(--cv-border-md); }
.difficulty-label { font-size: 0.7rem; color: var(--cv-text-muted); }

/* ── Weight ────────────────────────────────────────────────────── */
.weight-val  { color: var(--cv-blue-text); font-weight: 600; font-size: 0.85rem; }
.weight-unit { font-size: 0.72rem; color: var(--cv-text-muted); margin-inline-start: 2px; }

/* ── Colostrum ─────────────────────────────────────────────────── */
.colostrum-yes { color: var(--cv-green-bright); display: flex; align-items: center; gap: 3px; font-size: 0.8rem; font-weight: 600; }
.colostrum-no  { color: var(--cv-text-muted); font-size: 0.8rem; }
.text-muted    { color: var(--cv-text-muted); }

/* ── Actions ───────────────────────────────────────────────────── */
.action-row { display: flex; gap: 6px; flex-wrap: wrap; }
.action-btn { font-size: 0.78rem; padding: 5px 10px; display: flex; align-items: center; gap: 4px; }
.action-btn--danger {
    background: var(--cv-danger-bg);
    border: 1px solid var(--cv-danger-border);
    color: var(--cv-danger-text);
    border-radius: var(--cv-radius-sm);
    cursor: pointer; font-size: 0.78rem; padding: 5px 10px;
    display: flex; align-items: center; gap: 4px;
    transition: background 0.15s;
}
.action-btn--danger:hover { background: rgba(224,112,112,0.18); }

/* ── Pagination ────────────────────────────────────────────────── */
.pagination-row { display: flex; gap: 4px; padding: 16px; justify-content: center; flex-wrap: wrap; }
.page-btn {
    padding: 5px 10px;
    border-radius: var(--cv-radius-sm);
    background: transparent;
    border: 1px solid var(--cv-border-md);
    color: var(--cv-text-secondary);
    cursor: pointer; font-size: 0.8rem; min-width: 34px;
    transition: all 0.15s;
}
.page-btn:hover:not(.page-btn--disabled) { background: var(--cv-bg-hover); }
.page-btn--active { background: var(--cv-green-dim); border-color: var(--cv-green-border); color: var(--cv-green-bright); }
.page-btn--disabled { opacity: 0.3; cursor: not-allowed; }

/* ── Panel ─────────────────────────────────────────────────────── */
.panel-backdrop {
    position: fixed; inset: 0; z-index: 400;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    display: flex; justify-content: flex-end;
}
[dir="rtl"] .panel-backdrop { justify-content: flex-start; }
.panel {
    width: 520px; max-width: 100%;
    height: 100%;
    background: var(--cv-bg-modal);
    border-inline-start: 1px solid var(--cv-border-md);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.panel-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--cv-border-md);
    gap: 12px;
}
.panel-header__left { display: flex; align-items: flex-start; gap: 12px; }
.panel-icon {
    width: 40px; height: 40px; border-radius: var(--cv-radius);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.panel-icon--blue { background: var(--cv-blue-light); color: var(--cv-blue-text); border: 1px solid var(--cv-blue-border); }
.panel-header__title { font-weight: 600; color: var(--cv-text-primary); font-size: 0.95rem; }
.panel-header__sub   { font-size: 0.73rem; color: var(--cv-text-muted); margin-top: 2px; }
.panel-close {
    background: transparent; border: none; cursor: pointer;
    color: var(--cv-text-muted); padding: 4px; border-radius: var(--cv-radius-sm);
}
.panel-close:hover { color: var(--cv-text-primary); background: var(--cv-bg-hover); }
.panel-body { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 20px; }
.panel-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--cv-border-md);
    display: flex; gap: 10px; justify-content: flex-end;
}
[dir="rtl"] .panel-footer { justify-content: flex-start; }

/* ── Form sections ─────────────────────────────────────────────── */
.form-section { display: flex; flex-direction: column; gap: 14px; }
.form-section__title {
    display: flex; align-items: center; gap: 10px;
    font-size: 0.8rem; font-weight: 600; color: var(--cv-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--cv-border-md);
}
.section-num {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--cv-blue-light);
    border: 1px solid var(--cv-blue-border);
    color: var(--cv-blue-text);
    font-size: 0.72rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.form-field { display: flex; flex-direction: column; gap: 6px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cv-hint { font-size: 0.72rem; color: var(--cv-text-muted); }
.required-star { color: var(--cv-danger-text); margin-inline-start: 2px; }

/* ── Condition toggle ──────────────────────────────────────────── */
.condition-toggle { display: flex; gap: 8px; }
.condition-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 5px;
    padding: 8px 10px;
    border-radius: var(--cv-radius-sm);
    border: 1px solid var(--cv-border-md);
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer; font-size: 0.8rem;
    transition: all 0.15s;
}
.condition-btn--healthy.condition-btn--active { background: var(--cv-green-dim); border-color: var(--cv-green-border); color: var(--cv-green-bright); }
.condition-btn--weak.condition-btn--active    { background: var(--cv-amber-light); border-color: var(--cv-amber-border); color: var(--cv-amber-text); }
.condition-btn--stillborn.condition-btn--active { background: var(--cv-danger-bg); border-color: var(--cv-danger-border); color: var(--cv-danger-text); }

/* ── Difficulty selector ───────────────────────────────────────── */
.difficulty-selector { display: flex; gap: 8px; }
.diff-btn {
    width: 36px; height: 36px; border-radius: var(--cv-radius-sm);
    border: 1px solid var(--cv-border-md);
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer; font-size: 0.85rem; font-weight: 600;
    transition: all 0.15s;
    display: flex; align-items: center; justify-content: center;
}
.diff-btn:hover { background: var(--cv-bg-hover); }
.diff-btn--green.diff-btn--active { background: var(--cv-green-dim);    border-color: var(--cv-green-border); color: var(--cv-green-bright); }
.diff-btn--amber.diff-btn--active { background: var(--cv-amber-light);  border-color: var(--cv-amber-border); color: var(--cv-amber-text); }
.diff-btn--red.diff-btn--active   { background: var(--cv-danger-bg);    border-color: var(--cv-danger-border); color: var(--cv-danger-text); }
.difficulty-desc { font-size: 0.75rem; color: var(--cv-text-muted); }

/* ── Colostrum toggle ──────────────────────────────────────────── */
.colostrum-toggle { display: flex; align-items: center; gap: 12px; }
.toggle-switch {
    width: 44px; height: 24px;
    border-radius: 12px;
    background: var(--cv-border-md);
    border: none; cursor: pointer; padding: 2px;
    position: relative; transition: background 0.2s;
    display: flex; align-items: center;
}
.toggle-switch--on { background: var(--cv-green); }
.toggle-knob {
    width: 20px; height: 20px; border-radius: 50%;
    background: white;
    position: absolute;
    inset-inline-start: 2px;
    transition: inset-inline-start 0.2s;
}
.toggle-switch--on .toggle-knob { inset-inline-start: calc(100% - 22px); }

/* ── Delete modal ──────────────────────────────────────────────── */
.modal-danger-icon {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--cv-danger-bg);
    border: 1px solid var(--cv-danger-border);
    color: var(--cv-danger-text);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.cv-modal-header { display: flex; gap: 16px; padding: 24px; align-items: flex-start; }
.cv-modal-header__text h3 { font-size: 1rem; font-weight: 600; color: var(--cv-text-primary); margin-bottom: 6px; }
.cv-modal-header__text p  { font-size: 0.85rem; color: var(--cv-text-secondary); margin-bottom: 4px; }
.delete-warning { color: var(--cv-danger-text) !important; font-size: 0.78rem !important; }
.cv-modal-footer { display: flex; gap: 10px; justify-content: flex-end; padding: 16px 24px; border-top: 1px solid var(--cv-border-md); }
[dir="rtl"] .cv-modal-footer { justify-content: flex-start; }

/* ── Transitions ───────────────────────────────────────────────── */
.panel-enter-active, .panel-leave-active { transition: transform 0.28s cubic-bezier(.4,0,.2,1), opacity 0.28s; }
.panel-enter-from, .panel-leave-to { transform: translateX(100%); opacity: 0; }
[dir="rtl"] .panel-enter-from,
[dir="rtl"] .panel-leave-to { transform: translateX(-100%); }
.modal-enter-active, .modal-leave-active { transition: opacity 0.2s; }
.modal-enter-from, .modal-leave-to { opacity: 0; }

/* ── Spinner ───────────────────────────────────────────────────── */
@keyframes spin { to { transform: rotate(360deg); } }
.spin { animation: spin 0.8s linear infinite; }
</style>