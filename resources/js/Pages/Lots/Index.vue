<script setup>
/**
 * Lots/Index.vue — VERO Cattle Management
 * ─────────────────────────────────────────────────────────────────
 * Props shape (from LotController::index):
 *   lots[]   → formatLot() objects
 *   farms[]  → [{id, name_en, name_ar, company_id}]
 *   filters  → {farm_id, status}
 *   user.permissions → string
 *
 * Design rules: Vue Page Design Standard (cv- tokens, card grid,
 * hero header, cv-badge-lot-* status, no hardcoded hex).
 * ─────────────────────────────────────────────────────────────────
 */
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const props = defineProps({
    lots:     { type: Array,  default: () => [] },
    farms:    { type: Array,  default: () => [] },
    filters:  { type: Object, default: () => ({}) },
})

const { locale, isRtl, n } = useLocale()

// ── Permission helpers ────────────────────────────────────────────
const { can } = usePermissions()
const canCreate     = computed(() => can('create lots'))
const canEdit       = computed(() => can('edit lots'))
const canDelete     = computed(() => can('delete lots'))

// ── Filters (local search is client-side, farm/status via Inertia) ─
const search       = ref('')
const farmFilter   = ref(props.filters?.farm_id  ?? '')
const statusFilter = ref(props.filters?.status   ?? '')

function applyServerFilters() {
    router.get(route('lots.index'), {
        farm_id: farmFilter.value  || undefined,
        status:  statusFilter.value || undefined,
    }, { preserveState: true, replace: true })
}

// Client-side search on top of server-filtered results
const filteredLots = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.lots
    return props.lots.filter(l =>
        (l.name        ?? '').toLowerCase().includes(q) ||
        (l.lot_number  ?? '').toLowerCase().includes(q) ||
        (l.pen         ?? '').toLowerCase().includes(q)
    )
})

// ── Stat counts ───────────────────────────────────────────────────
const stats = computed(() => ({
    total:     props.lots.length,
    active:    props.lots.filter(l => l.status === 'active').length,
    completed: props.lots.filter(l => l.status === 'completed').length,
    archived:  props.lots.filter(l => l.status === 'archived').length,
}))

// ── Delete modal ──────────────────────────────────────────────────
const deleteTarget   = ref(null)
const showDeleteModal = ref(false)

function confirmDelete(lot) {
    deleteTarget.value = lot
    showDeleteModal.value = true
}

function cancelDelete() {
    showDeleteModal.value = false
    deleteTarget.value    = null
}

function executeDelete() {
    router.delete(route('lots.destroy', deleteTarget.value.id), {
        onFinish: cancelDelete,
    })
}

// ── Status badge class mapping ────────────────────────────────────
function lotBadgeClass(status) {
    return {
        active:    'cv-badge cv-badge-lot-active',
        completed: 'cv-badge cv-badge-lot-completed',
        archived:  'cv-badge cv-badge-lot-archived',
    }[status] ?? 'cv-badge cv-badge-neutral'
}

function cardStripeClass(status) {
    return {
        active:    'cv-card cv-card-stripe-green',
        completed: 'cv-card cv-card-stripe-blue',
        archived:  'cv-card cv-card-stripe-muted',
    }[status] ?? 'cv-card cv-card-stripe-muted'
}

// ── Date helpers ──────────────────────────────────────────────────
function formatDate(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString(
        locale.value === 'ar' ? 'ar-EG' : 'en-GB',
        { day: '2-digit', month: 'short', year: 'numeric' }
    )
}

// Days remaining to target slaughter
function daysToTarget(target) {
    if (!target) return null
    const diff = Math.ceil((new Date(target) - new Date()) / 86400000)
    return diff
}

function daysLabel(lot) {
    const d = daysToTarget(lot.target_slaughter_date)
    if (d === null) return null
    if (d < 0)  return locale.value === 'ar' ? `تجاوز ${Math.abs(d)} يوم` : `${Math.abs(d)}d overdue`
    if (d === 0) return locale.value === 'ar' ? 'اليوم' : 'Today'
    return locale.value === 'ar' ? `${d} يوم متبقٍ` : `${d}d left`
}

function daysClass(lot) {
    const d = daysToTarget(lot.target_slaughter_date)
    if (d === null) return ''
    if (d < 0)  return 'days-overdue'
    if (d <= 14) return 'days-soon'
    return 'days-ok'
}

// ── Farm label ────────────────────────────────────────────────────
function farmLabel(lot) {
    if (!lot.farm) return '—'
    return n(lot.farm)
}

// ── Status label ─────────────────────────────────────────────────
function statusLabel(status) {
    const labels = {
        en: { active: 'Active', completed: 'Completed', archived: 'Archived' },
        ar: { active: 'نشط',   completed: 'مكتمل',     archived: 'مؤرشف'  },
    }
    return (labels[locale.value] ?? labels.en)[status] ?? status
}
</script>

<template>
    
        <div :dir="isRtl ? 'rtl' : 'ltr'" class="lots-root">

            <!-- ═══ HERO HEADER ══════════════════════════════════════ -->
            <div class="cv-page-hero">
                <!-- dot-grid texture -->
                <div class="cv-page-hero-eyebrow" />

                <div class="hero-inner">
                    <div class="hero-left">
                        <!-- cattle/lot icon -->
                        <div class="hero-icon-wrap">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                                <polyline points="9 22 9 12 15 12 15 22"/>
                            </svg>
                        </div>

                        <div>
                            <div class="cv-page-hero-eyebrow">
                                {{ locale === 'ar' ? 'إدارة الماشية' : 'Livestock Management' }}
                            </div>
                            <div class="cv-page-hero-title">
                                {{ locale === 'ar' ? 'المجموعات' : 'Lots' }}
                            </div>
                            <div class="cv-page-hero-sub">
                                {{ locale === 'ar'
                                    ? 'إدارة مجموعات التسمين وتتبع التكاليف والأداء'
                                    : 'Manage fattening lots, track costs and performance' }}
                            </div>
                        </div>
                    </div>

                    <div class="cv-page-hero-actions" v-if="canCreate">
                        <Link :href="route('lots.create')" class="cv-btn cv-btn-amber">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                 class="btn-icon">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            {{ locale === 'ar' ? 'مجموعة جديدة' : 'New Lot' }}
                        </Link>
                    </div>
                </div>

                <!-- KPI stat pills -->
                <div class="hero-stats">
                    <div class="stat-pill">
                        <span class="stat-dot stat-dot-muted" />
                        <span class="stat-count">{{ stats.total }}</span>
                        <span class="stat-label">{{ locale === 'ar' ? 'الإجمالي' : 'Total' }}</span>
                    </div>
                    <div class="stat-pill">
                        <span class="stat-dot stat-dot-green" />
                        <span class="stat-count stat-count-green">{{ stats.active }}</span>
                        <span class="stat-label">{{ locale === 'ar' ? 'نشط' : 'Active' }}</span>
                    </div>
                    <div class="stat-pill">
                        <span class="stat-dot stat-dot-blue" />
                        <span class="stat-count stat-count-blue">{{ stats.completed }}</span>
                        <span class="stat-label">{{ locale === 'ar' ? 'مكتمل' : 'Completed' }}</span>
                    </div>
                    <div class="stat-pill">
                        <span class="stat-dot stat-dot-muted" />
                        <span class="stat-count">{{ stats.archived }}</span>
                        <span class="stat-label">{{ locale === 'ar' ? 'مؤرشف' : 'Archived' }}</span>
                    </div>
                </div>
            </div>

            <!-- ═══ CONTENT ═════════════════════════════════════════ -->
            <div class="cv-page">

                <!-- ── Filters bar ────────────────────────────────── -->
                <div class="filter-bar">
                    <!-- Search -->
                    <div class="cv-input-icon-wrap filter-search">
                        <svg class="cv-input-icon" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input
                            v-model="search"
                            type="text"
                            class="cv-input cv-input-with-icon"
                            :placeholder="locale === 'ar' ? 'بحث باسم المجموعة أو الرقم...' : 'Search lots...'"
                        />
                    </div>

                    <!-- Farm filter -->
                    <select v-model="farmFilter" @change="applyServerFilters" class="cv-select filter-select">
                        <option value="">{{ locale === 'ar' ? 'كل المزارع' : 'All Farms' }}</option>
                        <option v-for="farm in farms" :key="farm.id" :value="farm.id">
                            {{ n(farm) }}
                        </option>
                    </select>

                    <!-- Status filter -->
                    <select v-model="statusFilter" @change="applyServerFilters" class="cv-select filter-select">
                        <option value="">{{ locale === 'ar' ? 'كل الحالات' : 'All Statuses' }}</option>
                        <option value="active">{{ locale === 'ar' ? 'نشط' : 'Active' }}</option>
                        <option value="completed">{{ locale === 'ar' ? 'مكتمل' : 'Completed' }}</option>
                        <option value="archived">{{ locale === 'ar' ? 'مؤرشف' : 'Archived' }}</option>
                    </select>
                </div>

                <!-- ── Empty state ─────────────────────────────────── -->
                <div v-if="filteredLots.length === 0" class="empty-state">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                    </div>
                    <p class="empty-title">
                        {{ locale === 'ar' ? 'لا توجد مجموعات' : 'No lots found' }}
                    </p>
                    <p class="empty-sub">
                        {{ locale === 'ar'
                            ? 'قم بإنشاء مجموعتك الأولى لبدء تتبع التسمين'
                            : 'Create your first lot to start tracking fattening cycles' }}
                    </p>
                    <Link v-if="canCreate" :href="route('lots.create')" class="cv-btn cv-btn-amber mt-4">
                        {{ locale === 'ar' ? 'إنشاء مجموعة' : 'Create Lot' }}
                    </Link>
                </div>

                <!-- ── Card grid ───────────────────────────────────── -->
                <div v-else class="lots-grid">
                    <div
                        v-for="lot in filteredLots"
                        :key="lot.id"
                        :class="['cv-card', `cv-card-stripe-${lot.status === 'active' ? 'green' : lot.status === 'completed' ? 'blue' : 'muted'}`]"
                    >
                        <!-- Card header: name + status badge -->
                        <div class="card-head">
                            <div class="card-name-block">
                                <div class="card-lot-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                                        <polyline points="9 22 9 12 15 12 15 22"/>
                                    </svg>
                                </div>
                                <div>
                                    <Link :href="route('lots.show', lot.id)" class="card-name">
                                        {{ lot.name }}
                                    </Link>
                                    <div v-if="lot.lot_number" class="card-lot-number">
                                        #{{ lot.lot_number }}
                                    </div>
                                </div>
                            </div>
                            <span :class="lotBadgeClass(lot.status)">
                                {{ statusLabel(lot.status) }}
                            </span>
                        </div>

                        <!-- Farm + pen row -->
                        <div class="card-meta-row">
                            <div class="card-meta-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                     class="meta-icon">
                                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 010 7.75"/>
                                </svg>
                                <span>{{ farmLabel(lot) }}</span>
                            </div>
                            <div v-if="lot.pen" class="card-meta-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                     class="meta-icon">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M3 9h18M9 21V9"/>
                                </svg>
                                <span>{{ locale === 'ar' ? 'حظيرة: ' : 'Pen: ' }}{{ lot.pen }}</span>
                            </div>
                        </div>

                        <!-- Animal count + dates row -->
                        <div class="card-stats-row">
                            <!-- Animal count -->
                            <div class="card-stat">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                     class="stat-icon">
                                    <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
                                </svg>
                                <span class="stat-val-blue">{{ lot.animals_count }}</span>
                                <span class="stat-unit">{{ locale === 'ar' ? 'رأس' : 'head' }}</span>
                            </div>

                            <!-- Start date -->
                            <div class="card-stat">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                     class="stat-icon">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8"  y1="2" x2="8"  y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <span class="stat-val">{{ formatDate(lot.start_date) }}</span>
                            </div>
                        </div>

                        <!-- Target slaughter / days remaining -->
                        <div v-if="lot.target_slaughter_date" class="target-row">
                            <div class="target-label">
                                {{ locale === 'ar' ? 'هدف الذبح:' : 'Target slaughter:' }}
                                <span class="target-date">{{ formatDate(lot.target_slaughter_date) }}</span>
                            </div>
                            <span v-if="lot.status === 'active'" :class="['days-chip', daysClass(lot)]">
                                {{ daysLabel(lot) }}
                            </span>
                        </div>

                        <!-- Card footer: actions -->
                        <div class="card-footer">
                            <Link :href="route('lots.show', lot.id)" class="cv-btn cv-btn-ghost btn-sm">
                                {{ locale === 'ar' ? 'عرض' : 'View' }}
                            </Link>
                            <div class="card-actions-right">
                                <Link
                                    v-if="canEdit"
                                    :href="route('lots.edit', lot.id)"
                                    class="cv-action-btn cv-action-btn-amber"
                                    :title="locale === 'ar' ? 'تعديل' : 'Edit'"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </Link>
                                <button
                                    v-if="canDelete"
                                    @click="confirmDelete(lot)"
                                    class="cv-action-btn cv-action-btn-danger"
                                    :title="locale === 'ar' ? 'حذف' : 'Delete'"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                                        <path d="M10 11v6M14 11v6"/>
                                        <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ DELETE CONFIRMATION MODAL ═══════════════════════ -->
            <Teleport to="body">
                <div v-if="showDeleteModal" class="cv-modal-backdrop" @click.self="cancelDelete">
                    <div class="cv-modal cv-modal-sm" role="dialog" :aria-modal="true">
                        <div class="cv-modal-header">
                            <!-- danger icon -->
                            <div class="modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                    <line x1="12" y1="9"  x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <h3 class="modal-title">
                                {{ locale === 'ar' ? 'حذف المجموعة' : 'Delete Lot' }}
                            </h3>
                        </div>
                        <div class="cv-modal-body">
                            <div class="cv-alert-danger">
                                <p>
                                    {{ locale === 'ar'
                                        ? `هل أنت متأكد من حذف "${deleteTarget?.name}"؟ لا يمكن التراجع عن هذا الإجراء.`
                                        : `Are you sure you want to delete "${deleteTarget?.name}"? This action cannot be undone.` }}
                                </p>
                                <p v-if="deleteTarget?.animals_count > 0" class="modal-warning-note">
                                    {{ locale === 'ar'
                                        ? `⚠️ هذه المجموعة تحتوي على ${deleteTarget.animals_count} رأس — لا يمكن حذفها.`
                                        : `⚠️ This lot has ${deleteTarget.animals_count} animals — deletion will be blocked.` }}
                                </p>
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button @click="cancelDelete" class="cv-btn cv-btn-ghost">
                                {{ locale === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button @click="executeDelete" class="cv-btn cv-btn-danger">
                                {{ locale === 'ar' ? 'حذف نهائياً' : 'Delete' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

        </div>
   
</template>

<style scoped>
/* ── Root ─────────────────────────────────────────────────────── */
.lots-root {
    min-height: 100vh;
    background: var(--cv-bg-base);
    color: var(--cv-text-primary);
}

/* ── Hero ─────────────────────────────────────────────────────── */
.cv-page-hero {
    position: relative;
    overflow: hidden;
    padding: 2.5rem 2rem 1.5rem;
    background: linear-gradient(135deg, var(--cv-bg-card) 0%, var(--cv-bg-card-alt) 100%);
    border-bottom: 1px solid var(--cv-border);
}

.cv-page-hero-texture {
    position: absolute;
    inset: 0;
    background-image: radial-gradient(var(--cv-border) 1px, transparent 1px);
    background-size: 24px 24px;
    opacity: 0.35;
    pointer-events: none;
}

.hero-inner {
    position: relative;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.hero-left {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.hero-icon-wrap {
    width: 3rem;
    height: 3rem;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, var(--cv-green-bright), var(--cv-green-dim));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
}

.hero-icon-wrap svg {
    width: 1.5rem;
    height: 1.5rem;
}

.cv-page-hero-eyebrow {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--cv-amber);
    margin-bottom: 0.2rem;
}

.cv-page-hero-title {
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    line-height: 1.2;
}

.cv-page-hero-sub {
    font-size: 0.85rem;
    color: var(--cv-text-muted);
    margin-top: 0.25rem;
}

.cv-page-hero-actions {
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
    padding-top: 0.25rem;
}

/* ── Hero stats pills ────────────────────────────────────────── */
.hero-stats {
    position: relative;
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 1.25rem;
}

.stat-pill {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    background: var(--cv-bg-base);
    border: 1px solid var(--cv-border);
    border-radius: 999px;
    padding: 0.3rem 0.85rem;
    font-size: 0.8rem;
}

.stat-dot {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 50%;
    flex-shrink: 0;
}

.stat-dot-green  { background: var(--cv-green-bright); box-shadow: 0 0 5px var(--cv-green-bright); }
.stat-dot-blue   { background: var(--cv-blue-text); }
.stat-dot-muted  { background: var(--cv-text-muted); }

.stat-count       { font-weight: 700; color: var(--cv-text-primary); }
.stat-count-green { color: var(--cv-green-bright); }
.stat-count-blue  { color: var(--cv-blue-text); }
.stat-label       { color: var(--cv-text-muted); }

/* ── Page content ────────────────────────────────────────────── */
.cv-page {
    padding: 1.5rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* ── Filter bar ──────────────────────────────────────────────── */
.filter-bar {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
}

.filter-search { flex: 1; min-width: 200px; }

.filter-select {
    min-width: 160px;
}

/* ── Lots grid ───────────────────────────────────────────────── */
.lots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}

/* ── Card ────────────────────────────────────────────────────── */
.cv-card {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 0.75rem;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    position: relative;
}

.cv-card:hover {
    border-color: var(--cv-green-dim);
    box-shadow: 0 4px 20px rgba(0,0,0,0.25);
}

.cv-card-stripe-green  { border-left: 3px solid var(--cv-green-bright); }
.cv-card-stripe-blue   { border-left: 3px solid var(--cv-blue-text); }
.cv-card-stripe-muted  { border-left: 3px solid var(--cv-text-muted); }

/* rtl stripe flip */
[dir="rtl"] .cv-card-stripe-green,
[dir="rtl"] .cv-card-stripe-blue,
[dir="rtl"] .cv-card-stripe-muted {
    border-left: none;
    border-right: 3px solid var(--cv-green-bright);
}
[dir="rtl"] .cv-card-stripe-blue  { border-right-color: var(--cv-blue-text); }
[dir="rtl"] .cv-card-stripe-muted { border-right-color: var(--cv-text-muted); }

/* ── Card head ───────────────────────────────────────────────── */
.card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
}

.card-name-block {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
}

.card-lot-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.5rem;
    background: linear-gradient(135deg, var(--cv-green-bright), var(--cv-green-dim));
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #fff;
}

.card-lot-icon svg {
    width: 1rem;
    height: 1rem;
}

.card-name {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--cv-text-primary);
    text-decoration: none;
    display: block;
}

.card-name:hover { color: var(--cv-green-bright); }

.card-lot-number {
    font-size: 0.75rem;
    color: var(--cv-text-muted);
    margin-top: 0.1rem;
}

/* ── Card meta ───────────────────────────────────────────────── */
.card-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.8rem;
    color: var(--cv-text-muted);
}

.card-meta-item {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.meta-icon {
    width: 0.95rem;
    height: 0.95rem;
    flex-shrink: 0;
}

/* ── Card stats ──────────────────────────────────────────────── */
.card-stats-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--cv-border);
}

.card-stat {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.8rem;
    color: var(--cv-text-muted);
}

.stat-icon {
    width: 0.9rem;
    height: 0.9rem;
}

.stat-val      { color: var(--cv-text-primary); font-weight: 500; }
.stat-val-blue { color: var(--cv-blue-text); font-weight: 700; font-size: 0.95rem; }
.stat-unit     { color: var(--cv-text-muted); }

/* ── Target row ──────────────────────────────────────────────── */
.target-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.78rem;
    color: var(--cv-text-muted);
}

.target-date { color: var(--cv-text-primary); margin-inline-start: 0.25rem; }

.days-chip {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
}

.days-ok      { background: color-mix(in srgb, var(--cv-green-bright) 15%, transparent); color: var(--cv-green-bright); }
.days-soon    { background: color-mix(in srgb, var(--cv-amber) 15%, transparent); color: var(--cv-amber); }
.days-overdue { background: color-mix(in srgb, var(--cv-danger-text) 15%, transparent); color: var(--cv-danger-text); }

/* ── Card footer ─────────────────────────────────────────────── */
.card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 0.5rem;
    border-top: 1px solid var(--cv-border);
}

.card-actions-right {
    display: flex;
    gap: 0.4rem;
}

.btn-sm { padding: 0.3rem 0.75rem; font-size: 0.8rem; }

/* ── Badges ──────────────────────────────────────────────────── */
.cv-badge { font-size: 0.72rem; font-weight: 600; padding: 0.2rem 0.6rem; border-radius: 999px; }
.cv-badge-lot-active    { background: color-mix(in srgb, var(--cv-green-bright) 15%, transparent); color: var(--cv-green-bright); border: 1px solid var(--cv-green-bright); }
.cv-badge-lot-completed { background: color-mix(in srgb, var(--cv-blue-text) 15%, transparent); color: var(--cv-blue-text); border: 1px solid var(--cv-blue-text); }
.cv-badge-lot-archived  { background: color-mix(in srgb, var(--cv-text-muted) 15%, transparent); color: var(--cv-text-muted); border: 1px solid var(--cv-text-muted); }
.cv-badge-neutral       { background: color-mix(in srgb, var(--cv-text-muted) 10%, transparent); color: var(--cv-text-muted); border: 1px solid var(--cv-border); }

/* ── Empty state ─────────────────────────────────────────────── */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 4rem 1rem;
    text-align: center;
}

.empty-icon {
    width: 4rem;
    height: 4rem;
    border-radius: 50%;
    background: var(--cv-bg-card);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    color: var(--cv-text-muted);
}

.empty-icon svg { width: 2rem; height: 2rem; }
.empty-title    { font-size: 1.1rem; font-weight: 600; color: var(--cv-text-primary); }
.empty-sub      { font-size: 0.85rem; color: var(--cv-text-muted); margin-top: 0.35rem; }

/* ── Modal ───────────────────────────────────────────────────── */
.cv-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.cv-modal {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 1rem;
    width: 100%;
    overflow: hidden;
}

.cv-modal-sm { max-width: 420px; }

.cv-modal-header {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    border-bottom: 1px solid var(--cv-border);
}

.modal-danger-icon {
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 50%;
    background: color-mix(in srgb, var(--cv-danger-text) 15%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--cv-danger-text);
}

.modal-danger-icon svg { width: 1.5rem; height: 1.5rem; }

.modal-title {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--cv-text-primary);
    margin: 0;
}

.cv-modal-body    { padding: 1.25rem 1.5rem; }
.cv-modal-footer  { padding: 1rem 1.5rem; display: flex; gap: 0.75rem; justify-content: flex-end; border-top: 1px solid var(--cv-border); }

.cv-alert-danger {
    background: color-mix(in srgb, var(--cv-danger-text) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--cv-danger-text) 30%, transparent);
    border-radius: 0.5rem;
    padding: 0.85rem 1rem;
    font-size: 0.85rem;
    color: var(--cv-text-primary);
}

.modal-warning-note {
    margin-top: 0.5rem;
    color: var(--cv-amber);
    font-size: 0.8rem;
}

/* ── Buttons ─────────────────────────────────────────────────── */
.cv-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 1.1rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all 0.15s;
    white-space: nowrap;
}

.btn-icon { width: 1rem; height: 1rem; }

.cv-btn-amber {
    background: var(--cv-amber);
    color: #111;
    box-shadow: 0 0 12px color-mix(in srgb, var(--cv-amber) 40%, transparent);
}
.cv-btn-amber:hover { filter: brightness(1.1); transform: translateY(-1px); }

.cv-btn-ghost {
    background: transparent;
    color: var(--cv-text-muted);
    border: 1px solid var(--cv-border);
}
.cv-btn-ghost:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }

.cv-btn-danger {
    background: var(--cv-danger-text);
    color: #fff;
}
.cv-btn-danger:hover { filter: brightness(1.1); }

.cv-action-btn {
    width: 2rem;
    height: 2rem;
    border-radius: 0.4rem;
    border: 1px solid var(--cv-border);
    background: var(--cv-bg-card);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s;
    color: var(--cv-text-muted);
    text-decoration: none;
}

.cv-action-btn svg { width: 0.9rem; height: 0.9rem; }

.cv-action-btn-amber:hover { background: color-mix(in srgb, var(--cv-amber) 15%, transparent); color: var(--cv-amber); border-color: var(--cv-amber); }
.cv-action-btn-danger:hover { background: color-mix(in srgb, var(--cv-danger-text) 15%, transparent); color: var(--cv-danger-text); border-color: var(--cv-danger-text); }

/* ── Inputs ──────────────────────────────────────────────────── */
.cv-input, .cv-select {
    width: 100%;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 0.5rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: var(--cv-text-primary);
    transition: border-color 0.15s;
    outline: none;
}

.cv-input:focus, .cv-select:focus { border-color: var(--cv-green-bright); }

.cv-input-icon-wrap { position: relative; }
.cv-input-icon {
    position: absolute;
    inset-inline-start: 0.65rem;
    top: 50%;
    transform: translateY(-50%);
    width: 1rem;
    height: 1rem;
    color: var(--cv-text-muted);
    pointer-events: none;
}

.cv-input-with-icon { padding-inline-start: 2.25rem; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 640px) {
    .cv-page-hero { padding: 1.5rem 1rem 1rem; }
    .cv-page { padding: 1rem; }
    .lots-grid { grid-template-columns: 1fr; }
    .filter-bar { flex-direction: column; }
    .filter-search, .filter-select { width: 100%; }
    .hero-inner { flex-direction: column; }
    .cv-page-hero-title { font-size: 1.3rem; }
}
</style>
