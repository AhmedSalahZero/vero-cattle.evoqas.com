<template>
  <AuthenticatedLayout>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- ── Hero ─────────────────────────────────────────────────── -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">
        {{ isRtl ? 'المحاسبة' : 'Accounting' }}
      </div>
      <div class="cv-page-hero-title">
        {{ isRtl ? 'الأصول الثابتة' : 'Fixed Assets' }}
      </div>
      <div class="cv-page-hero-sub">
        {{ isRtl
          ? `${summary.active_count} أصل نشط · إجمالي القيمة الدفترية الصافية ${fmt(summary.total_nbv)} ج.م`
          : `${summary.active_count} active assets · Total NBV ${fmt(summary.total_nbv)} EGP`
        }}
      </div>
      <div class="cv-page-hero-actions">
        <Link
          v-if="canCreate"
          :href="route('fixed-assets.create')"
          class="cv-btn cv-btn-amber"
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14M5 12h14"/>
          </svg>
          {{ isRtl ? 'إضافة أصل' : 'Add Asset' }}
        </Link>
      </div>
    </div>

    <div class="cv-page">

      <!-- ── Summary KPI Strip ──────────────────────────────────── -->
      <div class="grid grid-cols-2 gap-3 mb-6" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr))">
        <div class="cv-stat-card cv-stat-card-blue">
          <div class="cv-stat-card-label">{{ isRtl ? 'إجمالي التكلفة' : 'Total Cost' }}</div>
          <div class="cv-stat-card-value">{{ fmt(summary.total_cost) }}</div>
          <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-amber">
          <div class="cv-stat-card-label">{{ isRtl ? 'إجمالي الإهلاك المتراكم' : 'Total Accum. Dep.' }}</div>
          <div class="cv-stat-card-value">{{ fmt(summary.total_accum_dep) }}</div>
          <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-blue">
          <div class="cv-stat-card-label">{{ isRtl ? 'إجمالي القيمة الدفترية الصافية' : 'Total NBV' }}</div>
          <div class="cv-stat-card-value">{{ fmt(summary.total_nbv) }}</div>
          <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-green">
          <div class="cv-stat-card-label">{{ isRtl ? 'نشط / مستهلك بالكامل / مُتصرف فيه' : 'Active / Fully Dep. / Disposed' }}</div>
          <div class="cv-stat-card-value">{{ summary.active_count }}</div>
          <div class="cv-stat-card-sub" style="color: var(--cv-text-muted)">
            <span style="color: var(--cv-blue-text)">{{ summary.fully_dep_count }}</span>
            &nbsp;/&nbsp;
            <span style="color: var(--cv-text-muted)">{{ summary.disposed_count }}</span>
          </div>
        </div>
      </div>

      <!-- ── Filters ────────────────────────────────────────────── -->
      <div class="cv-card mb-5" style="padding: 1rem 1.25rem">
        <div class="flex flex-wrap gap-3 items-end">
          <!-- Farm -->
          <div class="cv-field" style="margin-bottom:0; min-width: 160px">
            <label class="cv-label">{{ isRtl ? 'المزرعة' : 'Farm' }}</label>
            <select class="cv-select" v-model="filters.farm_id" @change="applyFilters">
              <option value="">{{ isRtl ? 'جميع المزارع' : 'All Farms' }}</option>
              <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
            </select>
          </div>
          <!-- Category -->
          <div class="cv-field" style="margin-bottom:0; min-width: 160px">
            <label class="cv-label">{{ isRtl ? 'الفئة' : 'Category' }}</label>
            <select class="cv-select" v-model="filters.category" @change="applyFilters">
              <option value="">{{ isRtl ? 'جميع الفئات' : 'All Categories' }}</option>
              <option v-for="c in categories" :key="c.value" :value="c.value">{{ isRtl ? c.ar : c.en }}</option>
            </select>
          </div>
          <!-- Status -->
          <div class="cv-field" style="margin-bottom:0; min-width: 160px">
            <label class="cv-label">{{ isRtl ? 'الحالة' : 'Status' }}</label>
            <select class="cv-select" v-model="filters.status" @change="applyFilters">
              <option value="">{{ isRtl ? 'جميع الحالات' : 'All Statuses' }}</option>
              <option value="active">{{ isRtl ? 'نشط' : 'Active' }}</option>
              <option value="fully_depreciated">{{ isRtl ? 'مستهلك بالكامل' : 'Fully Depreciated' }}</option>
              <option value="impaired">{{ isRtl ? 'مُخفَّض القيمة' : 'Impaired' }}</option>
              <option value="disposed">{{ isRtl ? 'مُتصرف فيه' : 'Disposed' }}</option>
            </select>
          </div>
          <!-- Reset -->
          <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="resetFilters" style="margin-bottom:0">
            {{ isRtl ? 'إعادة تعيين' : 'Reset' }}
          </button>
        </div>
      </div>

      <!-- ── Card Grid ──────────────────────────────────────────── -->
      <div v-if="assets.data.length === 0" class="cv-card" style="text-align:center; padding: 3rem; color: var(--cv-text-muted)">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 1rem; opacity: 0.35">
          <rect x="2" y="7" width="20" height="14" rx="2"/>
          <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
          <line x1="12" y1="12" x2="12" y2="16"/>
          <line x1="10" y1="14" x2="14" y2="14"/>
        </svg>
        <p style="font-size: 0.9rem">{{ isRtl ? 'لا توجد أصول ثابتة' : 'No fixed assets found' }}</p>
      </div>

      <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem;">
        <div
          v-for="asset in assets.data"
          :key="asset.id"
          class="cv-card"
          :class="stripeClass(asset.status)"
          style="display:flex; flex-direction:column; gap: 0.875rem"
        >
          <!-- Card Header -->
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2">
              <!-- Category avatar -->
              <div class="asset-avatar" :style="{ background: categoryGradient(asset.category) }">
                <span v-html="categoryIcon(asset.category)" style="display:flex; align-items:center; justify-content:center; width:20px; height:20px"></span>
              </div>
              <div>
                <div style="font-weight: 600; font-size: 0.875rem; color: var(--cv-text-primary); line-height: 1.3">
                  {{ n(asset) }}
                </div>
                <div style="font-size: 0.7rem; color: var(--cv-text-muted); margin-top: 1px">{{ asset.asset_code }}</div>
              </div>
            </div>
            <div class="flex flex-col items-end gap-1">
              <span class="cv-badge" :class="statusBadgeClass(asset.status)">
                {{ statusLabel(asset.status) }}
              </span>
              <span class="cv-badge cv-badge-neutral" style="font-size:0.55rem">
                {{ categoryLabel(asset.category) }}
              </span>
            </div>
          </div>

          <!-- Farm -->
          <div v-if="asset.farm" style="font-size: 0.75rem; color: var(--cv-text-muted); display: flex; align-items: center; gap: 0.375rem">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            {{ n(asset.farm) }}
          </div>

          <!-- Financials Row -->
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem">
            <div style="text-align: center; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); padding: 0.5rem 0.25rem">
              <div style="font-size: 0.55rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2px">{{ isRtl ? 'التكلفة' : 'Cost' }}</div>
              <div style="font-size: 0.8rem; font-weight: 700; color: var(--cv-blue-text)">{{ fmtShort(asset.purchase_cost) }}</div>
            </div>
            <div style="text-align: center; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); padding: 0.5rem 0.25rem">
              <div style="font-size: 0.55rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2px">{{ isRtl ? 'إهلاك متراكم' : 'Accum. Dep.' }}</div>
              <div style="font-size: 0.8rem; font-weight: 700; color: var(--cv-amber-text)">{{ fmtShort(asset.accumulated_depreciation) }}</div>
            </div>
            <div style="text-align: center; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); padding: 0.5rem 0.25rem">
              <div style="font-size: 0.55rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2px">{{ isRtl ? 'ق.د.ص' : 'NBV' }}</div>
              <div style="font-size: 0.8rem; font-weight: 700; color: var(--cv-blue-text)">{{ fmtShort(asset.net_book_value) }}</div>
            </div>
          </div>

          <!-- Depreciation Progress -->
          <div v-if="asset.depreciation_method !== 'none'" style="display: flex; flex-direction: column; gap: 4px">
            <div class="flex items-center justify-between" style="font-size: 0.7rem; color: var(--cv-text-muted)">
              <span>{{ isRtl ? 'نسبة الإهلاك' : 'Depreciation' }}</span>
              <span style="color: var(--cv-amber-text); font-weight: 600">{{ depPct(asset) }}%</span>
            </div>
            <div class="dep-bar-track">
              <div class="dep-bar-fill" :style="{ width: depPct(asset) + '%', background: depBarColor(depPct(asset)) }"></div>
            </div>
            <div style="font-size: 0.65rem; color: var(--cv-text-muted)">
              {{ isRtl ? 'طريقة الإهلاك:' : 'Method:' }}
              <span style="color: var(--cv-text-secondary)">{{ methodLabel(asset.depreciation_method) }}</span>
            </div>
          </div>
          <div v-else style="font-size: 0.7rem; color: var(--cv-text-muted)">
            {{ isRtl ? 'لا يوجد إهلاك (أرض)' : 'No depreciation (land/non-dep.)' }}
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2" style="padding-top: 0.25rem; border-top: 1px solid var(--cv-border)">
            <Link :href="route('fixed-assets.show', asset.id)" class="cv-btn cv-btn-secondary cv-btn-sm" style="flex:1; justify-content:center">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
              </svg>
              {{ isRtl ? 'عرض' : 'View' }}
            </Link>
            <Link
              v-if="canEdit && asset.status !== 'disposed'"
              :href="route('fixed-assets.edit', asset.id)"
              class="cv-action-btn cv-action-btn-amber"
              title="Edit"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
            </Link>
            <button
              v-if="canDelete"
              class="cv-action-btn cv-action-btn-danger"
              :title="isRtl ? 'حذف' : 'Delete'"
              @click="confirmDelete(asset)"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- ── Pagination ──────────────────────────────────────────── -->
      <div v-if="assets.links && assets.links.length > 3" class="flex justify-center gap-1 mt-6">
        <template v-for="link in assets.links" :key="link.label">
          <Link
            v-if="link.url"
            :href="link.url"
            class="cv-btn cv-btn-sm"
            :class="link.active ? 'cv-btn-secondary' : 'cv-btn-ghost'"
            v-html="link.label"
          />
          <span v-else class="cv-btn cv-btn-ghost cv-btn-sm" style="opacity:0.3; cursor:default" v-html="link.label"/>
        </template>
      </div>

    </div><!-- /cv-page -->

    <!-- ── Delete Confirmation Modal ─────────────────────────────── -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="deleteTarget = null">
        <div class="cv-modal cv-modal-sm" role="dialog">
          <div class="cv-modal-header">
            <div style="display:flex; align-items:center; gap: 0.625rem">
              <div style="width:2rem; height:2rem; border-radius:50%; background: var(--cv-danger-bg); border: 1px solid var(--cv-danger-border); display:flex; align-items:center; justify-content:center">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                </svg>
              </div>
              <span style="font-weight:600; color: var(--cv-text-primary)">{{ isRtl ? 'حذف الأصل' : 'Delete Asset' }}</span>
            </div>
            <button class="cv-action-btn" @click="deleteTarget = null">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </div>
          <div class="cv-modal-body">
            <div style="background: var(--cv-danger-bg); border: 1px solid var(--cv-danger-border); border-radius: var(--cv-radius); padding: 0.75rem 1rem; font-size: 0.8125rem; color: var(--cv-danger-text); margin-bottom: 0.75rem">
              {{ isRtl ? 'هذا الإجراء لا يمكن التراجع عنه.' : 'This action cannot be undone.' }}
            </div>
            <p style="font-size: 0.8125rem; color: var(--cv-text-secondary)">
              {{ isRtl ? 'هل أنت متأكد من حذف' : 'Are you sure you want to delete' }}
              <strong style="color: var(--cv-text-primary)">{{ n(deleteTarget) }}</strong>
              {{ isRtl ? '؟' : '?' }}
            </p>
            <p style="font-size: 0.75rem; color: var(--cv-text-muted); margin-top: 0.5rem">
              {{ isRtl ? 'الكود:' : 'Code:' }} {{ deleteTarget?.asset_code }}
            </p>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="deleteTarget = null">{{ isRtl ? 'إلغاء' : 'Cancel' }}</button>
            <button class="cv-btn cv-btn-danger cv-btn-sm" :class="{ 'cv-btn-loading': deleting }" @click="doDelete">
              {{ isRtl ? 'حذف' : 'Delete' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const { locale, isRtl, n } = useLocale()

const props = defineProps({
  assets:   { type: Object,  required: true },
  farms:    { type: Array,   default: () => [] },
  filters:  { type: Object,  default: () => ({}) },
  summary:  { type: Object,  default: () => ({}) },
})

// ── Permissions ───────────────────────────────────────────────────
const { can } = usePermissions()
const canCreate = computed(() => can('create fixed_assets'))
const canEdit   = computed(() => can('edit fixed_assets'))
const canDelete = computed(() => can('delete fixed_assets'))

// ── Filters ───────────────────────────────────────────────────────
const filters = reactive({
  farm_id:  props.filters?.farm_id  ?? '',
  category: props.filters?.category ?? '',
  status:   props.filters?.status   ?? '',
})

function applyFilters() {
  router.get(route('fixed-assets.index'), {
    farm_id:  filters.farm_id  || undefined,
    category: filters.category || undefined,
    status:   filters.status   || undefined,
  }, { preserveState: true, replace: true })
}

function resetFilters() {
  filters.farm_id  = ''
  filters.category = ''
  filters.status   = ''
  applyFilters()
}

// ── Delete ────────────────────────────────────────────────────────
const deleteTarget = ref(null)
const deleting     = ref(false)

function confirmDelete(asset) { deleteTarget.value = asset }

function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  router.delete(route('fixed-assets.destroy', deleteTarget.value.id), {
    onFinish: () => { deleting.value = false; deleteTarget.value = null },
  })
}

// ── Formatting ────────────────────────────────────────────────────
function fmt(v)      { return Number(v ?? 0).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }
function fmtShort(v) {
  const n = Number(v ?? 0)
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + (isRtl.value ? ' م' : 'M')
  if (n >= 1_000)     return (n / 1_000).toFixed(1)     + (isRtl.value ? ' ك' : 'K')
  return n.toFixed(0)
}

function depPct(asset) {
  const base = Number(asset.depreciable_base ?? 0)
  if (base <= 0) return 100
  return Math.min(100, Math.round((Number(asset.accumulated_depreciation ?? 0) / base) * 100))
}

function depBarColor(pct) {
  if (pct >= 100) return 'var(--cv-blue)'
  if (pct >= 70)  return 'var(--cv-amber)'
  return 'var(--cv-green)'
}

// ── Category helpers ──────────────────────────────────────────────
const categories = [
  { value: 'land',      en: 'Land',      ar: 'أرض' },
  { value: 'building',  en: 'Building',  ar: 'مبنى' },
  { value: 'vehicle',   en: 'Vehicle',   ar: 'مركبة' },
  { value: 'equipment', en: 'Equipment', ar: 'معدات' },
  { value: 'furniture', en: 'Furniture', ar: 'أثاث' },
  { value: 'other',     en: 'Other',     ar: 'أخرى' },
]

function categoryLabel(cat) {
  const c = categories.find(x => x.value === cat)
  return c ? (isRtl.value ? c.ar : c.en) : cat
}

function categoryGradient(cat) {
  const map = {
    land:      'linear-gradient(135deg, #1a3a20, #2d5a35)',
    building:  'linear-gradient(135deg, #1e2a40, #2a3f60)',
    vehicle:   'linear-gradient(135deg, #2a1e18, #4a3020)',
    equipment: 'linear-gradient(135deg, #2a2218, #4a3c20)',
    furniture: 'linear-gradient(135deg, #28182a, #452040)',
    other:     'linear-gradient(135deg, #1a2228, #263040)',
  }
  return map[cat] ?? map.other
}

function categoryIcon(cat) {
  const icons = {
    land: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-success-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>`,
    building: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-info-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>`,
    vehicle: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-warning-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    equipment: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 5.34 3.93 6.75M19.07 19.07l-1.41-1.41M5.34 18.66l-1.41 1.41M22 12h-2M4 12H2M12 22v-2M12 4V2"/></svg>`,
    furniture: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C070E0" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v3"/><path d="M2 11v5a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H6v-2a2 2 0 0 0-4 0z"/><path d="M4 18v2M20 18v2"/></svg>`,
    other: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-text-secondary)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>`,
  }
  return icons[cat] ?? icons.other
}

// ── Status helpers ────────────────────────────────────────────────
function stripeClass(status) {
  return {
    active:            'cv-card-stripe-green',
    fully_depreciated: 'cv-card-stripe-blue',
    impaired:          'cv-card-stripe-amber',
    disposed:          'cv-card-stripe-muted',
  }[status] ?? 'cv-card-stripe-muted'
}

function statusBadgeClass(status) {
  return {
    active:            'cv-badge-green',
    fully_depreciated: 'cv-badge-info',
    impaired:          'cv-badge-amber',
    disposed:          'cv-badge-neutral',
  }[status] ?? 'cv-badge-neutral'
}

function statusLabel(status) {
  if (isRtl.value) {
    return { active: 'نشط', fully_depreciated: 'مستهلك بالكامل', impaired: 'مُخفَّض القيمة', disposed: 'مُتصرف فيه' }[status] ?? status
  }
  return { active: 'Active', fully_depreciated: 'Fully Dep.', impaired: 'Impaired', disposed: 'Disposed' }[status] ?? status
}

function methodLabel(m) {
  if (isRtl.value) return { straight_line: 'القسط الثابت', declining_balance: 'الرصيد المتناقص', none: 'لا يوجد' }[m] ?? m
  return { straight_line: 'Straight-Line', declining_balance: 'Declining Balance', none: 'None' }[m] ?? m
}
</script>

<style scoped>
.asset-avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--cv-radius);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid rgba(255,255,255,0.06);
}
.dep-bar-track {
  height: 5px;
  background: var(--cv-bg-card-alt);
  border-radius: 9999px;
  overflow: hidden;
  border: 1px solid var(--cv-border);
}
.dep-bar-fill {
  height: 100%;
  border-radius: 9999px;
  transition: width 0.4s ease;
}
</style>