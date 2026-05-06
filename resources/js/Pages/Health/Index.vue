<script setup>
/**
 * Health/Index.vue
 * ─────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Health Records Index
 *
 * Card grid (§7.4) — no tables.
 * Each card: type-coloured stripe · animal tag · medicine · cost ·
 *            outcome badge · quarantine/withdrawal alerts.
 *
 * Fully bilingual: all labels, badges, buttons in Arabic when AR.
 * ─────────────────────────────────────────────────────────────────
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    records:  Object,
    farms:    Array,
    lots:     Array,
    filters:  Object,
})

// ── Permissions ───────────────────────────────────────────────────
// Uses the centralised composable so logic stays in one place.
const { can: canPermission } = usePermissions()
const can = computed(() => ({
    create: canPermission('create health'),
    edit:   canPermission('edit health'),
    delete: canPermission('delete health'),
}))

// ── Filter state ──────────────────────────────────────────────────
const filters = ref({
    farm_id:           props.filters?.farm_id           ?? '',
    lot_id:            props.filters?.lot_id            ?? '',
    animal_id:         props.filters?.animal_id         ?? '',
    type:              props.filters?.type              ?? '',
    date_from:         props.filters?.date_from         ?? '',
    date_to:           props.filters?.date_to           ?? '',
    withdrawal_active: props.filters?.withdrawal_active ?? false,
})

const filtersOpen = ref(false)

const filteredLots = computed(() =>
    filters.value.farm_id
        ? props.lots.filter(l => String(l.farm_id) === String(filters.value.farm_id))
        : props.lots
)

function applyFilters() {
    router.get(route('health.index'), filters.value, {
        preserveState: true, replace: true,
    })
}

function resetFilters() {
    filters.value = { farm_id:'', lot_id:'', animal_id:'', type:'', date_from:'', date_to:'', withdrawal_active: false }
    applyFilters()
}

// ── Delete confirm ────────────────────────────────────────────────
const deleteModal   = ref(false)
const deleteTarget  = ref(null)

function confirmDelete(record) {
    deleteTarget.value = record
    deleteModal.value  = true
}
function doDelete() {
    router.delete(route('health.destroy', deleteTarget.value.id), {
        onSuccess: () => { deleteModal.value = false; deleteTarget.value = null },
    })
}

// ── Helpers ───────────────────────────────────────────────────────
// Type stripe colour + label
const typeConfig = computed(() => ({
    treatment: {
        stripe: 'var(--cv-danger-text)',
        label:  lang.value === 'ar' ? 'علاج'    : 'Treatment',
        bg:     'rgba(239,68,68,0.12)',
    },
    vet_visit: {
        stripe: 'var(--cv-blue-text)',
        label:  lang.value === 'ar' ? 'زيارة بيطري' : 'Vet Visit',
        bg:     'rgba(59,130,246,0.12)',
    },
    surgery: {
        stripe: 'var(--cv-amber)',
        label:  lang.value === 'ar' ? 'جراحة'   : 'Surgery',
        bg:     'rgba(245,158,11,0.12)',
    },
    other: {
        stripe: 'var(--cv-green-bright)',
        label:  lang.value === 'ar' ? 'أخرى'    : 'Other',
        bg:     'rgba(16,185,129,0.12)',
    },
}))

const outcomeConfig = computed(() => ({
    recovered:  { label: lang.value === 'ar' ? 'تعافى'      : 'Recovered',  cls: 'badge-green'  },
    ongoing:    { label: lang.value === 'ar' ? 'جاري'       : 'Ongoing',    cls: 'badge-amber'  },
    died:       { label: lang.value === 'ar' ? 'نفق'        : 'Died',       cls: 'badge-danger' },
    slaughtered:{ label: lang.value === 'ar' ? 'ذُبح'       : 'Slaughtered',cls: 'badge-muted'  },
    unknown:    { label: lang.value === 'ar' ? 'غير معروف'  : 'Unknown',    cls: 'badge-muted'  },
}))

function isInWithdrawal(record) {
    if (!record.withdrawal_clear_date) return false
    return new Date(record.withdrawal_clear_date) >= new Date()
}

function farmName(farmId) {
    const f = props.farms.find(x => x.id === farmId)
    return f ? n(f, 'name') : '—'
}

function medName(record) {
    if (!record.inventory_item) return record.medicine_name ?? '—'
    return lang.value === 'ar'
        ? (record.inventory_item.name_ar || record.inventory_item.name_en)
        : (record.inventory_item.name_en || record.inventory_item.name_ar)
}

function fmt(num) {
    if (num == null) return '—'
    return Number(num).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    })
}

// ── i18n strings ──────────────────────────────────────────────────
const t = computed(() => lang.value === 'ar' ? {
    title:        'السجلات الصحية',
    sub:          'تسجيل العلاجات والزيارات البيطرية والجراحات',
    eyebrow:      'صحة القطيع',
    addRecord:    'إضافة سجل',
    filters:      'تصفية',
    reset:        'إعادة تعيين',
    apply:        'تطبيق',
    farm:         'المزرعة',
    lot:          'المجموعة',
    animalTag:    'رقم الماشية',
    type:         'النوع',
    allTypes:     'كل الأنواع',
    dateFrom:     'من تاريخ',
    dateTo:       'إلى تاريخ',
    withdrawal:   'قيد فترة السحب فقط',
    noRecords:    'لا توجد سجلات صحية',
    noRecordsSub: 'ابدأ بإضافة سجل علاج أو زيارة بيطرية',
    animal:       'الماشية',
    medicine:     'الدواء',
    dose:         'الجرعة',
    cost:         'التكلفة',
    outcome:      'النتيجة',
    quarantine:   'حجر صحي',
    withdrawalBadge: 'فترة السحب',
    edit:         'تعديل',
    delete:       'حذف',
    deleteTitle:  'حذف السجل الصحي',
    deleteMsg:    'هل أنت متأكد من حذف هذا السجل؟ سيتم استعادة كمية الدواء إلى المخزون.',
    cancel:       'إلغاء',
    confirm:      'حذف',
    allFarms:     'كل المزارع',
    allLots:      'كل المجموعات',
    egp:          'ج.م',
} : {
    title:        'Health Records',
    sub:          'Treatments, vet visits, and surgeries',
    eyebrow:      'Herd Health',
    addRecord:    'Add Record',
    filters:      'Filters',
    reset:        'Reset',
    apply:        'Apply',
    farm:         'Farm',
    lot:          'Lot',
    animalTag:    'Animal Tag',
    type:         'Type',
    allTypes:     'All Types',
    dateFrom:     'Date From',
    dateTo:       'Date To',
    withdrawal:   'In withdrawal period only',
    noRecords:    'No health records found',
    noRecordsSub: 'Start by adding a treatment or vet visit record',
    animal:       'Animal',
    medicine:     'Medicine',
    dose:         'Dose',
    cost:         'Cost',
    outcome:      'Outcome',
    quarantine:   'Quarantine',
    withdrawalBadge: 'Withdrawal',
    edit:         'Edit',
    delete:       'Delete',
    deleteTitle:  'Delete Health Record',
    deleteMsg:    'Are you sure? The medicine dose will be restored to inventory.',
    cancel:       'Cancel',
    confirm:      'Delete',
    allFarms:     'All Farms',
    allLots:      'All Lots',
    egp:          'EGP',
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
                    <Link
                        v-if="can.create"
                        :href="route('health.create')"
                        class="cv-btn cv-btn-amber"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        {{ t.addRecord }}
                    </Link>
                    <button class="cv-btn cv-btn-ghost" @click="filtersOpen = !filtersOpen">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16">
                            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
                        </svg>
                        {{ t.filters }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- ── Flash ──────────────────────────────────────── -->
                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">
                    {{ $page.props.flash.success }}
                </div>

                <!-- ── Filter Panel ───────────────────────────────── -->
                <Transition name="slide-down">
                    <div v-if="filtersOpen" class="cv-card mb-6 p-4">
                        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr))">

                            <!-- Farm -->
                            <div>
                                <label class="cv-label">{{ t.farm }}</label>
                                <select v-model="filters.farm_id" class="cv-select">
                                    <option value="">{{ t.allFarms }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                                </select>
                            </div>

                            <!-- Lot -->
                            <div>
                                <label class="cv-label">{{ t.lot }}</label>
                                <select v-model="filters.lot_id" class="cv-select">
                                    <option value="">{{ t.allLots }}</option>
                                    <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                </select>
                            </div>

                            <!-- Type -->
                            <div>
                                <label class="cv-label">{{ t.type }}</label>
                                <select v-model="filters.type" class="cv-select">
                                    <option value="">{{ t.allTypes }}</option>
                                    <option value="treatment">{{ typeConfig['treatment'].label }}</option>
                                    <option value="vet_visit">{{ typeConfig['vet_visit'].label }}</option>
                                    <option value="surgery">{{ typeConfig['surgery'].label }}</option>
                                    <option value="other">{{ typeConfig['other'].label }}</option>
                                </select>
                            </div>

                            <!-- Date From -->
                            <div>
                                <label class="cv-label">{{ t.dateFrom }}</label>
                                <input v-model="filters.date_from" type="date" class="cv-input"/>
                            </div>

                            <!-- Date To -->
                            <div>
                                <label class="cv-label">{{ t.dateTo }}</label>
                                <input v-model="filters.date_to" type="date" class="cv-input"/>
                            </div>

                            <!-- Withdrawal toggle -->
                            <div class="flex items-end pb-1">
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <div
                                        class="cv-toggle"
                                        :class="{ 'cv-toggle-on': filters.withdrawal_active }"
                                        @click="filters.withdrawal_active = !filters.withdrawal_active"
                                    >
                                        <span class="cv-toggle-knob"/>
                                    </div>
                                    <span class="cv-label mb-0">{{ t.withdrawal }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex gap-2 mt-4">
                            <button class="cv-btn cv-btn-amber" @click="applyFilters">{{ t.apply }}</button>
                            <button class="cv-btn cv-btn-ghost" @click="resetFilters">{{ t.reset }}</button>
                        </div>
                    </div>
                </Transition>

                <!-- ── Card Grid ───────────────────────────────────── -->
                <div
                    v-if="records.data.length"
                    class="cv-grid"
                    style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem;"
                >
                    <div
                        v-for="rec in records.data"
                        :key="rec.id"
                        class="cv-card cv-health-card"
                        :style="`border-inline-start: 4px solid ${typeConfig[rec.type]?.stripe ?? 'var(--cv-green-bright)'}`"
                    >
                        <!-- Top row: type badge + date -->
                        <div class="flex items-center justify-between mb-3">
                            <span
                                class="cv-badge"
                                :style="`background:${typeConfig[rec.type]?.bg};color:${typeConfig[rec.type]?.stripe};`"
                            >
                                {{ typeConfig[rec.type]?.label }}
                            </span>
                            <span class="text-sm" style="color:var(--cv-text-muted)">{{ rec.date }}</span>
                        </div>

                        <!-- Animal tag + lot -->
                        <div class="flex items-center gap-2 mb-2">
                            <!-- Animal avatar -->
                            <div class="cv-avatar-sm" style="background:linear-gradient(135deg,var(--cv-green-bright),var(--cv-blue-text))">
                                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.7" width="14" height="14">
                                    <path d="M4 9c0-1.1.9-2 2-2h2L9 5h6l1 2h2a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9z"/>
                                    <circle cx="9" cy="12" r="1" fill="white"/>
                                    <circle cx="15" cy="12" r="1" fill="white"/>
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold text-sm" style="color:var(--cv-text-primary)">
                                    # {{ rec.animal?.tag ?? '—' }}
                                </div>
                                <div class="text-xs" style="color:var(--cv-text-muted)">
                                    {{ rec.lot?.name ?? farmName(rec.farm_id) }}
                                </div>
                            </div>
                        </div>

                        <!-- Condition -->
                        <div v-if="rec.condition" class="text-sm mb-2" style="color:var(--cv-text-secondary)">
                            {{ rec.condition }}
                        </div>

                        <!-- Medicine + dose row -->
                        <div class="flex items-center gap-2 mb-3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7" width="14" height="14">
                                <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                                <path d="M8 12h8M12 8v8"/>
                            </svg>
                            <span class="text-sm font-medium" style="color:var(--cv-text-primary)">
                                {{ medName(rec) }}
                            </span>
                            <span v-if="rec.dose" class="text-xs" style="color:var(--cv-blue-text)">
                                {{ rec.dose }} {{ rec.dose_unit ?? '' }}
                            </span>
                        </div>

                        <!-- Cost + outcome row -->
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-sm" style="color:var(--cv-blue-text)">
                                {{ fmt(rec.cost) }} {{ rec.currency ?? t.egp }}
                            </span>
                            <span
                                v-if="rec.outcome"
                                class="cv-badge"
                                :class="outcomeConfig[rec.outcome]?.cls"
                            >
                                {{ outcomeConfig[rec.outcome]?.label }}
                            </span>
                        </div>

                        <!-- Alert badges row -->
                        <div class="flex flex-wrap gap-1 mb-3">
                            <span v-if="rec.is_quarantine" class="cv-badge badge-danger">
                                🔴 {{ t.quarantine }}
                            </span>
                            <span v-if="isInWithdrawal(rec)" class="cv-badge badge-amber">
                                ⏳ {{ t.withdrawalBadge }} — {{ rec.withdrawal_clear_date }}
                            </span>
                        </div>

                        <!-- Vet -->
                        <div v-if="rec.vet_name" class="text-xs mb-3" style="color:var(--cv-text-muted)">
                            👨‍⚕️ {{ rec.vet_name }}
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2 mt-auto pt-3" style="border-top:1px solid var(--cv-border-muted)">
                            <Link
                                v-if="can.edit"
                                :href="route('health.edit', rec.id)"
                                class="cv-btn cv-btn-ghost cv-btn-sm"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                {{ t.edit }}
                            </Link>
                            <button
                                v-if="can.delete"
                                class="cv-btn cv-btn-danger-ghost cv-btn-sm"
                                @click="confirmDelete(rec)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13">
                                    <polyline points="3,6 5,6 21,6"/>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                    <path d="M10 11v6M14 11v6"/>
                                    <path d="M9 6V4h6v2"/>
                                </svg>
                                {{ t.delete }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Empty state ─────────────────────────────────── -->
                <div v-else class="cv-empty-state">
                    <div class="cv-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" width="48" height="48">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                    </div>
                    <div class="cv-empty-title">{{ t.noRecords }}</div>
                    <div class="cv-empty-sub">{{ t.noRecordsSub }}</div>
                    <Link v-if="can.create" :href="route('health.create')" class="cv-btn cv-btn-amber mt-4">
                        {{ t.addRecord }}
                    </Link>
                </div>

                <!-- ── Pagination ──────────────────────────────────── -->
                <div v-if="records.last_page > 1" class="cv-pagination mt-6">
                    <Link
                        v-for="link in records.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="cv-page-link"
                        :class="{ active: link.active, disabled: !link.url }"
                        v-html="link.label"
                        preserve-scroll
                    />
                </div>

            </div><!-- /cv-page -->

            <!-- ── Delete Confirm Modal ────────────────────────── -->
            <Transition name="fade">
                <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
                    <div class="cv-modal">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="28" height="28">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <h3 class="cv-modal-title">{{ t.deleteTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <p>{{ t.deleteMsg }}</p>
                            <p class="mt-2 font-semibold" style="color:var(--cv-text-primary)">
                                # {{ deleteTarget?.animal?.tag }} — {{ deleteTarget?.date }}
                            </p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">{{ t.cancel }}</button>
                            <button class="cv-btn cv-btn-danger" @click="doDelete">{{ t.confirm }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    
</template>

<style scoped>
.cv-health-card {
    display: flex;
    flex-direction: column;
    padding: 1rem;
    transition: box-shadow 0.2s, transform 0.15s;
}
.cv-health-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}
.cv-avatar-sm {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.badge-green  { background: rgba(16,185,129,0.15); color: var(--cv-green-bright); }
.badge-amber  { background: rgba(245,158,11,0.15); color: var(--cv-amber); }
.badge-danger { background: rgba(239,68,68,0.15);  color: var(--cv-danger-text); }
.badge-muted  { background: rgba(148,163,184,0.15); color: var(--cv-text-muted); }

.cv-toggle {
    width: 40px; height: 22px;
    background: var(--cv-bg-input);
    border: 1px solid var(--cv-border);
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.2s;
    position: relative;
}
.cv-toggle-on { background: var(--cv-danger-text); border-color: var(--cv-danger-text); }
.cv-toggle-knob {
    position: absolute;
    top: 2px; inset-inline-start: 2px;
    width: 16px; height: 16px;
    background: #fff;
    border-radius: 50%;
    transition: inset-inline-start 0.2s;
}
.cv-toggle-on .cv-toggle-knob { inset-inline-start: calc(100% - 18px); }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.25s ease; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.cv-btn-sm { padding: 0.3rem 0.7rem; font-size: 0.78rem; }
.cv-btn-danger-ghost {
    background: transparent;
    color: var(--cv-danger-text);
    border: 1px solid transparent;
}
.cv-btn-danger-ghost:hover { background: rgba(239,68,68,0.1); border-color: var(--cv-danger-text); }

.cv-pagination { display: flex; gap: 0.25rem; flex-wrap: wrap; }
.cv-page-link {
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.82rem;
    color: var(--cv-text-secondary);
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-muted);
    text-decoration: none;
    transition: all 0.15s;
}
.cv-page-link.active {
    background: var(--cv-amber);
    color: #000;
    border-color: var(--cv-amber);
    font-weight: 600;
}
.cv-page-link.disabled { opacity: 0.4; pointer-events: none; }

.cv-empty-state { text-align: center; padding: 4rem 2rem; }
.cv-empty-icon { opacity: 0.25; margin-bottom: 1rem; display: flex; justify-content: center; }
.cv-empty-title { font-size: 1.1rem; font-weight: 600; color: var(--cv-text-primary); margin-bottom: 0.4rem; }
.cv-empty-sub   { color: var(--cv-text-muted); font-size: 0.9rem; }
</style>
