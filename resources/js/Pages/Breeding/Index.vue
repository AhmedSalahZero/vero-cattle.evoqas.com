<script setup>
/**
 * Breeding/Index.vue
 * ─────────────────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Breeding Records
 *
 * Index-only pattern — all CRUD via slide-in panels. URL stays /breeding.
 *
 * Props (from BreedingController::index):
 *   records   — paginated(30): id, dam, sire, farm_id, method, breeding_date,
 *               expected_calving_date, pregnancy_status, confirmation_date, notes
 *   farms     — [{id, name_en, name_ar}]
 *   filters   — {farm_id, pregnancy_status, calving_due_soon}
 *   user.permissions  — string
 *
 * Bilingual:
 *   No _en/_ar field pairs on breeding_records (all operational fields).
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
const canCreate = computed(() => can('create breeding'))
const canEdit   = computed(() => can('edit breeding'))
const canDelete = computed(() => can('delete breeding'))

// ── Filters ───────────────────────────────────────────────────────
const filterFarm     = ref(props.filters?.farm_id ?? '')
const filterStatus   = ref(props.filters?.pregnancy_status ?? '')
const filterDueSoon  = ref(props.filters?.calving_due_soon ?? false)
const searchQuery    = ref('')
let   searchTimer    = null

function applyFilters() {
    router.get(route('breeding.index'), {
        farm_id:           filterFarm.value   || undefined,
        pregnancy_status:  filterStatus.value || undefined,
        calving_due_soon:  filterDueSoon.value || undefined,
    }, { preserveState: true, replace: true })
}

watch([filterFarm, filterStatus, filterDueSoon], applyFilters)

// ── Stats chips ───────────────────────────────────────────────────
const totalRecords   = computed(() => props.records?.meta?.total ?? props.records?.data?.length ?? 0)
const confirmedCount = computed(() => props.records?.data?.filter(r => r.pregnancy_status === 'confirmed').length ?? 0)
const dueSoonCount   = computed(() => {
    const today = new Date()
    const limit = new Date(today); limit.setDate(limit.getDate() + 30)
    return props.records?.data?.filter(r => {
        if (!r.expected_calving_date || r.pregnancy_status !== 'confirmed') return false
        const d = new Date(r.expected_calving_date)
        return d >= today && d <= limit
    }).length ?? 0
})

// ── Countdown helper ──────────────────────────────────────────────
function countdownLabel(dateStr) {
    if (!dateStr) return null
    const today = new Date(); today.setHours(0,0,0,0)
    const d     = new Date(dateStr); d.setHours(0,0,0,0)
    const diff  = Math.round((d - today) / 86400000)
    if (diff < 0)  return lang.value === 'ar' ? `تأخر ${Math.abs(diff)} يوم`  : `${Math.abs(diff)}d overdue`
    if (diff === 0) return lang.value === 'ar' ? 'اليوم'  : 'Today'
    if (diff <= 30) return lang.value === 'ar' ? `خلال ${diff} يوم` : `in ${diff}d`
    return null
}

function countdownColor(dateStr) {
    if (!dateStr) return ''
    const today = new Date(); today.setHours(0,0,0,0)
    const d     = new Date(dateStr); d.setHours(0,0,0,0)
    const diff  = Math.round((d - today) / 86400000)
    if (diff < 0)   return 'danger'
    if (diff <= 7)  return 'warning'
    if (diff <= 30) return 'info'
    return ''
}

// ── Pregnancy status badge ────────────────────────────────────────
const statusMap = {
    open:      { en: 'Open',      ar: 'مفتوح',    color: 'muted'   },
    confirmed: { en: 'Confirmed', ar: 'مؤكد',     color: 'success' },
    lost:      { en: 'Lost',      ar: 'فُقد',     color: 'danger'  },
    calved:    { en: 'Calved',    ar: 'ولدت',     color: 'info'    },
}
function statusLabel(s) { return lang.value === 'ar' ? (statusMap[s]?.ar ?? s) : (statusMap[s]?.en ?? s) }
function statusColor(s) { return statusMap[s]?.color ?? 'muted' }

// ── Method badge ──────────────────────────────────────────────────
function methodLabel(m) {
    if (m === 'natural') return lang.value === 'ar' ? 'طبيعي' : 'Natural'
    if (m === 'ai')      return lang.value === 'ar' ? 'تلقيح اصطناعي' : 'Artificial'
    return m
}

// ── Panel state ───────────────────────────────────────────────────
const panelOpen   = ref(false)
const panelMode   = ref('create')  // 'create' | 'edit'
const editRecord  = ref(null)

// ── Animal search for dam/sire ────────────────────────────────────
// Animals are loaded lazily on farm select via API
const damOptions  = ref([])
const sireOptions = ref([])

async function loadAnimals(farmId, sex, target) {
    if (!farmId) { target.value = []; return }
    try {
        const res = await fetch(`/api/lots-animals?farm_id=${farmId}&status=active`)
        const data = await res.json()
        if (sex === 'female') target.value = data.filter(a => a.sex === 'female' || a.sex === 'castrated' ? false : a.sex === 'female')
        target.value = data
    } catch {
        target.value = []
    }
}

// ── Create / Edit form ────────────────────────────────────────────
const form = useForm({
    farm_id:           '',
    dam_id:            '',
    sire_id:           '',
    method:            'natural',
    breeding_date:     '',
    pregnancy_status:  'open',
    confirmation_date: '',
    notes:             '',
})

function openCreate() {
    form.reset()
    form.clearErrors()
    editRecord.value = null
    panelMode.value  = 'create'
    panelOpen.value  = true
}

function openEdit(record) {
    form.farm_id           = record.farm_id ?? ''
    form.dam_id            = record.dam_id  ?? ''
    form.sire_id           = record.sire_id ?? ''
    form.method            = record.method  ?? 'natural'
    form.breeding_date     = record.breeding_date ?? ''
    form.pregnancy_status  = record.pregnancy_status ?? 'open'
    form.confirmation_date = record.confirmation_date ?? ''
    form.notes             = record.notes ?? ''
    form.clearErrors()
    editRecord.value = record
    panelMode.value  = 'edit'
    panelOpen.value  = true
    loadAnimals(record.farm_id, 'all', damOptions)
    sireOptions.value = damOptions.value
}

function closePanel() {
    panelOpen.value = false
    editRecord.value = null
}

watch(() => form.farm_id, (fid) => {
    form.dam_id  = ''
    form.sire_id = ''
    loadAnimals(fid, 'all', damOptions)
    sireOptions.value = []
    loadAnimals(fid, 'all', sireOptions)
})

function submitForm() {
    if (panelMode.value === 'create') {
        form.post(route('breeding.store'), {
            preserveScroll: true,
            onSuccess: () => closePanel(),
        })
    } else {
        form.put(route('breeding.update', editRecord.value.id), {
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
    router.delete(route('breeding.destroy', deleteTarget.value.id), {
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
                {{ lang === 'ar' ? 'سجلات التلقيح' : 'Breeding Records' }}
            </div>
            <div class="cv-page-hero-sub">
                {{ lang === 'ar'
                    ? 'تتبع أحداث التلقيح والحمل وتواريخ الولادة المتوقعة'
                    : 'Track breeding events, pregnancy status and expected calving dates' }}
            </div>
            <div class="cv-page-hero-actions">
                <button v-if="canCreate" class="cv-btn cv-btn-amber" @click="openCreate">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:16px;height:16px">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    {{ lang === 'ar' ? 'تسجيل تلقيح' : 'Record Breeding' }}
                </button>
            </div>
        </div>

        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- Stats row -->
            <div class="stats-row">
                <div class="stat-chip stat-chip--blue">
                    <span class="stat-chip__value">{{ totalRecords }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'إجمالي السجلات' : 'Total Records' }}</span>
                </div>
                <div class="stat-chip stat-chip--amber">
                    <span class="stat-chip__value">{{ dueSoonCount }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'ولادة خلال ٣٠ يوم' : 'Due Within 30 Days' }}</span>
                </div>
                <div class="stat-chip stat-chip--green">
                    <span class="stat-chip__value">{{ confirmedCount }}</span>
                    <span class="stat-chip__label">{{ lang === 'ar' ? 'حمل مؤكد' : 'Confirmed Pregnancies' }}</span>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="filter-bar">
                <!-- Farm -->
                <select v-model="filterFarm" class="cv-select filter-select">
                    <option value="">{{ lang === 'ar' ? 'كل المزارع' : 'All Farms' }}</option>
                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                </select>

                <!-- Pregnancy status -->
                <select v-model="filterStatus" class="cv-select filter-select">
                    <option value="">{{ lang === 'ar' ? 'كل الحالات' : 'All Statuses' }}</option>
                    <option value="open">{{ lang === 'ar' ? 'مفتوح' : 'Open' }}</option>
                    <option value="confirmed">{{ lang === 'ar' ? 'مؤكد' : 'Confirmed' }}</option>
                    <option value="lost">{{ lang === 'ar' ? 'فُقد' : 'Lost' }}</option>
                    <option value="calved">{{ lang === 'ar' ? 'ولدت' : 'Calved' }}</option>
                </select>

                <!-- Due soon toggle -->
                <button
                    class="cv-btn filter-toggle"
                    :class="filterDueSoon ? 'filter-toggle--active' : 'cv-btn-ghost'"
                    @click="filterDueSoon = !filterDueSoon"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:14px;height:14px">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    {{ lang === 'ar' ? 'ولادة قريبة' : 'Due Soon' }}
                </button>
            </div>

            <!-- Table card -->
            <div class="cv-card table-card">
                <div class="table-wrap">
                    <table class="cv-table">
                        <thead>
                            <tr>
                                <th class="text-start">{{ lang === 'ar' ? 'تاريخ التلقيح' : 'Breeding Date' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الأم' : 'Dam' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الأب' : 'Sire' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الطريقة' : 'Method' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الولادة المتوقعة' : 'Expected Calving' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'حالة الحمل' : 'Pregnancy Status' }}</th>
                                <th class="text-start">{{ lang === 'ar' ? 'الإجراءات' : 'Actions' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!records?.data?.length">
                                <td colspan="8" class="empty-cell">
                                    {{ lang === 'ar' ? 'لا توجد سجلات تلقيح' : 'No breeding records found' }}
                                </td>
                            </tr>
                            <tr v-for="record in records?.data" :key="record.id" class="table-row">

                                <!-- Breeding date -->
                                <td>
                                    <span class="date-val">{{ record.breeding_date }}</span>
                                </td>

                                <!-- Dam -->
                                <td>
                                    <div class="animal-cell">
                                        <span class="animal-tag">{{ record.dam?.tag ?? '—' }}</span>
                                        <span v-if="record.dam?.breed" class="animal-breed">{{ record.dam.breed }}</span>
                                    </div>
                                </td>

                                <!-- Sire -->
                                <td>
                                    <div v-if="record.sire" class="animal-cell">
                                        <span class="animal-tag">{{ record.sire.tag }}</span>
                                        <span v-if="record.sire.breed" class="animal-breed">{{ record.sire.breed }}</span>
                                    </div>
                                    <span v-else class="text-muted">—</span>
                                </td>

                                <!-- Farm -->
                                <td>
                                    <span class="farm-name">{{ farmName(record.farm_id) }}</span>
                                </td>

                                <!-- Method -->
                                <td>
                                    <span class="cv-badge" :class="record.method === 'ai' ? 'cv-badge-info' : 'cv-badge-success'">
                                        {{ methodLabel(record.method) }}
                                    </span>
                                </td>

                                <!-- Expected calving -->
                                <td>
                                    <div class="calving-cell">
                                        <span class="date-val">{{ record.expected_calving_date ?? '—' }}</span>
                                        <span
                                            v-if="record.expected_calving_date && record.pregnancy_status === 'confirmed'"
                                            class="cv-badge countdown-badge"
                                            :class="`cv-badge-${countdownColor(record.expected_calving_date) || 'muted'}`"
                                        >
                                            {{ countdownLabel(record.expected_calving_date) }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Pregnancy status -->
                                <td>
                                    <span class="cv-badge" :class="`cv-badge-${statusColor(record.pregnancy_status)}`">
                                        {{ statusLabel(record.pregnancy_status) }}
                                    </span>
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
                            <div class="panel-icon panel-icon--green">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:18px;height:18px">
                                    <circle cx="8" cy="8" r="4"/><circle cx="16" cy="16" r="4"/>
                                    <line x1="12" y1="8" x2="12" y2="16"/>
                                </svg>
                            </div>
                            <div>
                                <div class="panel-header__title">
                                    {{ panelMode === 'create'
                                        ? (lang === 'ar' ? 'تسجيل تلقيح جديد' : 'Record Breeding Event')
                                        : (lang === 'ar' ? 'تعديل سجل التلقيح'  : 'Edit Breeding Record') }}
                                </div>
                                <div class="panel-header__sub">
                                    {{ lang === 'ar' ? 'تاريخ الولادة المتوقع يُحسب تلقائياً (٢٨٣ يوم)' : 'Expected calving date is auto-calculated (+283 days)' }}
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
                                {{ lang === 'ar' ? 'الأم (البقرة)' : 'Dam (Cow/Heifer)' }}
                                <span class="required-star">*</span>
                            </label>
                            <select v-model="form.dam_id" class="cv-select" :disabled="!form.farm_id">
                                <option value="">{{ lang === 'ar' ? 'اختر الأم' : 'Select dam' }}</option>
                                <option v-for="a in damOptions.filter(x => x.sex === 'female')" :key="a.id" :value="a.id">
                                    {{ a.tag }}{{ a.breed ? ` — ${a.breed}` : '' }}
                                </option>
                            </select>
                            <div v-if="form.errors.dam_id" class="cv-error">{{ form.errors.dam_id }}</div>
                            <div v-if="!form.farm_id" class="cv-hint">
                                {{ lang === 'ar' ? 'اختر المزرعة أولاً' : 'Select a farm first' }}
                            </div>
                        </div>

                        <!-- Sire -->
                        <div class="form-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'الأب (الثور)' : 'Sire (Bull)' }}</label>
                            <select v-model="form.sire_id" class="cv-select" :disabled="!form.farm_id">
                                <option value="">{{ lang === 'ar' ? 'غير محدد' : 'Not specified' }}</option>
                                <option v-for="a in sireOptions.filter(x => x.sex === 'male')" :key="a.id" :value="a.id">
                                    {{ a.tag }}{{ a.breed ? ` — ${a.breed}` : '' }}
                                </option>
                            </select>
                            <div v-if="form.errors.sire_id" class="cv-error">{{ form.errors.sire_id }}</div>
                        </div>

                        <!-- Method -->
                        <div class="form-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'طريقة التلقيح' : 'Breeding Method' }}
                                <span class="required-star">*</span>
                            </label>
                            <div class="method-toggle">
                                <button
                                    type="button"
                                    class="method-btn"
                                    :class="{ 'method-btn--active': form.method === 'natural' }"
                                    @click="form.method = 'natural'"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:14px;height:14px">
                                        <path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2z"/>
                                        <path d="M12 8v4l3 3"/>
                                    </svg>
                                    {{ lang === 'ar' ? 'طبيعي' : 'Natural' }}
                                </button>
                                <button
                                    type="button"
                                    class="method-btn"
                                    :class="{ 'method-btn--active': form.method === 'ai' }"
                                    @click="form.method = 'ai'"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:14px;height:14px">
                                        <path d="M18 2l4 4m-3-3l3 3"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/>
                                        <path d="m9 11 4 4"/>
                                    </svg>
                                    {{ lang === 'ar' ? 'تلقيح اصطناعي' : 'Artificial Insemination' }}
                                </button>
                            </div>
                            <div v-if="form.errors.method" class="cv-error">{{ form.errors.method }}</div>
                        </div>

                        <!-- Breeding date -->
                        <div class="form-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'تاريخ التلقيح' : 'Breeding Date' }}
                                <span class="required-star">*</span>
                            </label>
                            <input v-model="form.breeding_date" type="date" class="cv-input" />
                            <div v-if="form.errors.breeding_date" class="cv-error">{{ form.errors.breeding_date }}</div>
                            <!-- Auto-calculated expected calving preview -->
                            <div v-if="form.breeding_date" class="cv-hint hint--green">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:12px;height:12px">
                                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                </svg>
                                {{ lang === 'ar' ? 'الولادة المتوقعة:' : 'Expected calving:' }}
                                {{ (() => { try { const d = new Date(form.breeding_date); d.setDate(d.getDate()+283); return d.toISOString().split('T')[0] } catch { return '—' } })() }}
                            </div>
                        </div>

                        <!-- Pregnancy status (edit mode) -->
                        <div v-if="panelMode === 'edit'" class="form-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'حالة الحمل' : 'Pregnancy Status' }}</label>
                            <select v-model="form.pregnancy_status" class="cv-select">
                                <option value="open">{{ lang === 'ar' ? 'مفتوح' : 'Open' }}</option>
                                <option value="confirmed">{{ lang === 'ar' ? 'مؤكد' : 'Confirmed' }}</option>
                                <option value="lost">{{ lang === 'ar' ? 'فُقد' : 'Lost' }}</option>
                                <option value="calved">{{ lang === 'ar' ? 'ولدت' : 'Calved' }}</option>
                            </select>
                        </div>

                        <!-- Confirmation date (appears when confirmed) -->
                        <div v-if="form.pregnancy_status === 'confirmed'" class="form-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'تاريخ التأكيد' : 'Confirmation Date' }}</label>
                            <input v-model="form.confirmation_date" type="date" class="cv-input" />
                            <div v-if="form.errors.confirmation_date" class="cv-error">{{ form.errors.confirmation_date }}</div>
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
                            <h3>{{ lang === 'ar' ? 'حذف سجل التلقيح' : 'Delete Breeding Record' }}</h3>
                            <p>
                                {{ lang === 'ar' ? 'هل تريد حذف سجل التلقيح للأم' : 'Delete breeding record for dam' }}
                                <strong>{{ deleteTarget?.dam?.tag }}</strong>
                                {{ lang === 'ar' ? 'بتاريخ' : 'dated' }}
                                <strong>{{ deleteTarget?.breeding_date }}</strong>؟
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
    padding: 14px 24px;
    border-radius: var(--cv-radius-md);
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-md);
    min-width: 130px;
    gap: 2px;
}
.stat-chip--blue  { border-color: var(--cv-blue-border);  background: var(--cv-blue-light); }
.stat-chip--amber { border-color: var(--cv-amber-border); background: var(--cv-amber-light); }
.stat-chip--green { border-color: var(--cv-green-border); background: var(--cv-green-dim); }
.stat-chip__value { font-size: 1.6rem; font-weight: 700; color: var(--cv-blue-text); line-height: 1; }
.stat-chip--amber .stat-chip__value { color: var(--cv-amber-text); }
.stat-chip--green .stat-chip__value { color: var(--cv-green-bright); }
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
.filter-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.82rem;
}
.filter-toggle--active {
    background: var(--cv-amber-light);
    border-color: var(--cv-amber-border);
    color: var(--cv-amber-text);
}

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
.date-val   { color: var(--cv-text-secondary); font-size: 0.83rem; }
.farm-name  { color: var(--cv-text-secondary); font-size: 0.83rem; }
.animal-cell { display: flex; flex-direction: column; gap: 1px; }
.animal-tag  { color: var(--cv-green-bright); font-weight: 600; font-size: 0.85rem; font-family: monospace; }
.animal-breed { color: var(--cv-text-muted); font-size: 0.73rem; }
.calving-cell { display: flex; flex-direction: column; gap: 4px; }
.countdown-badge { font-size: 0.7rem; width: fit-content; }

/* ── Actions ───────────────────────────────────────────────────── */
.action-row { display: flex; gap: 6px; flex-wrap: wrap; }
.action-btn { font-size: 0.78rem; padding: 5px 10px; display: flex; align-items: center; gap: 4px; }
.action-btn--danger {
    background: var(--cv-danger-bg);
    border: 1px solid var(--cv-danger-border);
    color: var(--cv-danger-text);
    border-radius: var(--cv-radius-sm);
    cursor: pointer;
    font-size: 0.78rem;
    padding: 5px 10px;
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
    cursor: pointer;
    font-size: 0.8rem;
    min-width: 34px;
    transition: all 0.15s;
}
.page-btn:hover:not(.page-btn--disabled) { background: var(--cv-bg-hover); }
.page-btn--active { background: var(--cv-green-dim); border-color: var(--cv-green-border); color: var(--cv-green-bright); }
.page-btn--disabled { opacity: 0.3; cursor: not-allowed; }

/* ── Slide-in panel ────────────────────────────────────────────── */
.panel-backdrop {
    position: fixed; inset: 0; z-index: 400;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    display: flex; justify-content: flex-end;
}
[dir="rtl"] .panel-backdrop { justify-content: flex-start; }
.panel {
    width: 480px; max-width: 100%;
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
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.panel-icon--green { background: var(--cv-green-dim); color: var(--cv-green-bright); border: 1px solid var(--cv-green-border); }
.panel-header__title { font-weight: 600; color: var(--cv-text-primary); font-size: 0.95rem; }
.panel-header__sub   { font-size: 0.73rem; color: var(--cv-text-muted); margin-top: 2px; }
.panel-close {
    background: transparent; border: none; cursor: pointer;
    color: var(--cv-text-muted); padding: 4px; border-radius: var(--cv-radius-sm);
}
.panel-close:hover { color: var(--cv-text-primary); background: var(--cv-bg-hover); }
.panel-body { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.panel-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--cv-border-md);
    display: flex; gap: 10px; justify-content: flex-end;
}
[dir="rtl"] .panel-footer { justify-content: flex-start; }

/* ── Form elements ─────────────────────────────────────────────── */
.form-field { display: flex; flex-direction: column; gap: 6px; }
.cv-hint { font-size: 0.72rem; color: var(--cv-text-muted); display: flex; align-items: center; gap: 4px; }
.hint--green { color: var(--cv-green-bright); }
.required-star { color: var(--cv-danger-text); margin-inline-start: 2px; }

/* ── Method toggle ─────────────────────────────────────────────── */
.method-toggle { display: flex; gap: 8px; }
.method-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 9px 12px;
    border-radius: var(--cv-radius-sm);
    border: 1px solid var(--cv-border-md);
    background: transparent;
    color: var(--cv-text-muted);
    cursor: pointer; font-size: 0.83rem;
    transition: all 0.15s;
}
.method-btn:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }
.method-btn--active {
    background: var(--cv-green-dim);
    border-color: var(--cv-green-border);
    color: var(--cv-green-bright);
}

/* ── Delete modal ──────────────────────────────────────────────── */
.modal-danger-icon {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--cv-danger-bg);
    border: 1px solid var(--cv-danger-border);
    color: var(--cv-danger-text);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
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

/* ── RTL text alignment ────────────────────────────────────────── */
.text-muted { color: var(--cv-text-muted); }
</style>