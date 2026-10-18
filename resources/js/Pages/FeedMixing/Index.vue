<script setup>
/**
 * FeedMixing/Index.vue — VERO Cattle Management
 * List of feed mixing batches (optional WIP stage between raw inventory and feeding).
 *
 * 📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
 *
 * COMPONENT PLAN
 *  1. Props: batches {data[], links[], total}, counts{}, farms[], filters{}, can{}
 *  2. Actions: new batch · open · edit (draft) · delete (draft)
 *  3. Columns: Reference · Start date · Farm/Location · Mix · Status · Ingredient kg ·
 *              Cost · Finished kg · Cost/kg · Actions
 *     Compact table (not cards): batches accumulate daily — data-dense module.
 *  4. Filters: status, farm (server-side)
 *  5. Permissions: can.create / can.edit / can.delete (from the "feed" module)
 *  6. Mobile/RTL: table scrolls horizontally; logical text-start / text-end; dir from useLocale
 */
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    batches: { type: Object, default: () => ({ data: [], links: [], total: 0 }) },
    counts:  { type: Object, default: () => ({ draft: 0, in_progress: 0, completed: 0, cancelled: 0 }) },
    farms:   { type: Array,  default: () => [] },
    filters: { type: Object, default: () => ({}) },
    can:     { type: Object, default: () => ({ create: false, edit: false, delete: false }) },
})

const dict = {
    en: {
        eyebrow: 'Feed', title: 'Feed Mixing',
        sub: 'Mix raw items into a finished feed, and keep it in stock for feeding',
        newBatch: 'New Mixing Batch',
        kpiProgress: 'In Progress', kpiDraft: 'Draft', kpiDone: 'Completed', kpiCancelled: 'Cancelled',
        status: 'Status', allStatus: 'All statuses', farm: 'Farm', allFarms: 'All farms',
        draft: 'Draft', in_progress: 'In Progress', completed: 'Completed', cancelled: 'Cancelled',
        ref: 'Reference', date: 'Start date', farmLoc: 'Farm / Location', mix: 'Finished mix',
        ingKg: 'Ingredients (kg)', cost: 'Batch cost', finKg: 'Finished (kg)', cpk: 'Cost / kg', actions: 'Actions',
        view: 'Open', edit: 'Edit', delete: 'Delete',
        egp: 'EGP',
        emptyTitle: 'No mixing batches yet',
        emptySub: 'Use this when you blend raw items into a mix. If you feed raw items directly, you do not need it.',
        delTitle: 'Delete this batch?', delMsg: 'You are about to delete draft batch', cancel: 'Cancel',
        confirmDelete: 'Delete', deleting: 'Deleting…',
    },
    ar: {
        eyebrow: 'الأعلاف', title: 'خلط الأعلاف',
        sub: 'اخلط الأصناف الخام في علف جاهز، واحتفظ به في المخزون للتغذية',
        newBatch: 'دفعة خلط جديدة',
        kpiProgress: 'قيد التنفيذ', kpiDraft: 'مسودة', kpiDone: 'مكتملة', kpiCancelled: 'ملغاة',
        status: 'الحالة', allStatus: 'كل الحالات', farm: 'المزرعة', allFarms: 'كل المزارع',
        draft: 'مسودة', in_progress: 'قيد التنفيذ', completed: 'مكتملة', cancelled: 'ملغاة',
        ref: 'المرجع', date: 'تاريخ البدء', farmLoc: 'المزرعة / الموقع', mix: 'العلف المخلوط',
        ingKg: 'المكونات (كجم)', cost: 'تكلفة الدفعة', finKg: 'الناتج (كجم)', cpk: 'التكلفة / كجم', actions: 'إجراءات',
        view: 'فتح', edit: 'تعديل', delete: 'حذف',
        egp: 'ج.م',
        emptyTitle: 'لا توجد دفعات خلط بعد',
        emptySub: 'استخدم هذه الشاشة عند خلط أصناف خام في علف جاهز. إذا كنت تُغذّي بالأصناف الخام مباشرة فلا حاجة لها.',
        delTitle: 'حذف هذه الدفعة؟', delMsg: 'أنت على وشك حذف مسودة الدفعة', cancel: 'إلغاء',
        confirmDelete: 'حذف', deleting: 'جارٍ الحذف…',
    },
}
const t = computed(() => dict[lang.value] ?? dict.en)

// ── Filters ───────────────────────────────────────────────────────
const filterState = ref({
    status:  props.filters?.status  ?? '',
    farm_id: props.filters?.farm_id ?? '',
})
function applyFilters() {
    router.get(route('feed.mixing.index'), filterState.value, { preserveState: true, replace: true })
}

// ── Helpers ───────────────────────────────────────────────────────
function fmtNum(v, dec = 2) {
    if (v === null || v === undefined || v === '') return '—'
    return Number(v).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: dec, maximumFractionDigits: dec,
    })
}
function pick(en, ar) {
    return lang.value === 'ar' ? (ar || en) : (en || ar)
}
function badgeClass(s) {
    return {
        draft: 'cv-badge-neutral',
        in_progress: 'cv-badge-amber',
        completed: 'cv-badge-green',
        cancelled: 'cv-badge-danger',
    }[s] ?? 'cv-badge-neutral'
}

// ── Delete (draft only) ───────────────────────────────────────────
const deleteTarget = ref(null)
const deleting = ref(false)
function doDelete() {
    if (!deleteTarget.value) return
    deleting.value = true
    router.delete(route('feed.mixing.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => { deleting.value = false; deleteTarget.value = null },
    })
}
</script>

<template>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- Hero -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ t.title }}</div>
      <div class="cv-page-hero-sub">{{ t.sub }}</div>
      <div class="cv-page-hero-actions">
        <Link v-if="can.create" :href="route('feed.mixing.create')" class="cv-btn cv-btn-amber">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path d="M12 5v14M5 12h14"/></svg>
          {{ t.newBatch }}
        </Link>
      </div>
    </div>

    <div class="cv-page">

      <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.warning" class="cv-alert cv-alert-warning mb-4">{{ $page.props.flash.warning }}</div>

      <!-- KPI strip -->
      <div class="grid gap-3 mb-5" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="cv-stat-card cv-stat-card-amber">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiProgress }}</div>
          <div style="font-size:1.35rem;font-weight:700;color:var(--cv-amber)">{{ fmtNum(counts.in_progress, 0) }}</div>
        </div>
        <div class="cv-stat-card">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiDraft }}</div>
          <div style="font-size:1.35rem;font-weight:700;color:var(--cv-blue-text)">{{ fmtNum(counts.draft, 0) }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-green">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiDone }}</div>
          <div style="font-size:1.35rem;font-weight:700;color:var(--cv-green-bright)">{{ fmtNum(counts.completed, 0) }}</div>
        </div>
        <div class="cv-stat-card">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiCancelled }}</div>
          <div style="font-size:1.35rem;font-weight:700;color:var(--cv-text-muted)">{{ fmtNum(counts.cancelled, 0) }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="cv-card mb-5 p-4">
        <div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
          <div>
            <label class="cv-label">{{ t.status }}</label>
            <select v-model="filterState.status" class="cv-select" @change="applyFilters">
              <option value="">{{ t.allStatus }}</option>
              <option v-for="s in ['draft','in_progress','completed','cancelled']" :key="s" :value="s">{{ t[s] }}</option>
            </select>
          </div>
          <div>
            <label class="cv-label">{{ t.farm }}</label>
            <select v-model="filterState.farm_id" class="cv-select" @change="applyFilters">
              <option value="">{{ t.allFarms }}</option>
              <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div v-if="batches.data.length" class="cv-card" style="overflow-x:auto">
        <table class="cv-table">
          <thead>
            <tr>
              <th class="text-start">{{ t.ref }}</th>
              <th class="text-start">{{ t.date }}</th>
              <th class="text-start">{{ t.farmLoc }}</th>
              <th class="text-start">{{ t.mix }}</th>
              <th class="text-start">{{ t.status }}</th>
              <th class="text-end">{{ t.ingKg }}</th>
              <th class="text-end">{{ t.cost }}</th>
              <th class="text-end">{{ t.finKg }}</th>
              <th class="text-end">{{ t.cpk }}</th>
              <th class="text-start">{{ t.actions }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in batches.data" :key="b.id">
              <td class="text-start">
                <Link :href="route('feed.mixing.show', b.id)"
                      style="font-weight:700;color:var(--cv-amber);font-family:monospace">{{ b.reference }}</Link>
              </td>
              <td class="text-start" style="white-space:nowrap;color:var(--cv-text-muted)">{{ b.start_date }}</td>
              <td class="text-start">
                <div style="font-weight:600;color:var(--cv-text-primary)">{{ pick(b.farm_name_en, b.farm_name_ar) }}</div>
                <div style="font-size:0.72rem;color:var(--cv-text-muted)">{{ pick(b.location_name_en, b.location_name_ar) }}</div>
              </td>
              <td class="text-start" style="font-weight:500;color:var(--cv-text-primary)">{{ pick(b.output_name_en, b.output_name_ar) }}</td>
              <td class="text-start"><span class="cv-badge" :class="badgeClass(b.status)">{{ t[b.status] }}</span></td>
              <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">{{ b.status === 'draft' ? '—' : fmtNum(b.total_ingredient_kg, 1) }}</td>
              <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">
                <template v-if="b.status === 'draft'">—</template>
                <template v-else>{{ fmtNum(b.total_cost) }} <span style="font-size:0.7rem;color:var(--cv-text-muted);font-weight:400">{{ t.egp }}</span></template>
              </td>
              <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">{{ fmtNum(b.finished_qty_kg, 1) }}</td>
              <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">{{ fmtNum(b.cost_per_kg, 4) }}</td>
              <td class="text-start">
                <div class="flex gap-1">
                  <Link :href="route('feed.mixing.show', b.id)" class="cv-action-btn cv-action-btn-blue" :title="t.view">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </Link>
                  <Link v-if="can.edit && b.status === 'draft'" :href="route('feed.mixing.edit', b.id)" class="cv-action-btn cv-action-btn-amber" :title="t.edit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </Link>
                  <button v-if="can.delete && b.status === 'draft'" class="cv-action-btn cv-action-btn-danger" :title="t.delete" @click="deleteTarget = b">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Empty state -->
      <div v-else class="cv-empty">
        <div class="cv-empty-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" width="48" height="48">
            <path d="M4 7h16l-1.5 11a2 2 0 0 1-2 1.7H7.5a2 2 0 0 1-2-1.7L4 7z"/>
            <path d="M8 7V5a4 4 0 0 1 8 0v2"/>
            <path d="M9 12c1 1 2 1 3 0s2-1 3 0"/>
            <path d="M9 15.5c1 1 2 1 3 0s2-1 3 0"/>
          </svg>
        </div>
        <div class="cv-empty-title">{{ t.emptyTitle }}</div>
        <div class="cv-empty-sub">{{ t.emptySub }}</div>
        <Link v-if="can.create" :href="route('feed.mixing.create')" class="cv-btn cv-btn-amber mt-4">{{ t.newBatch }}</Link>
      </div>

      <!-- Pagination -->
      <div v-if="batches.links && batches.links.length > 3" class="cv-pagination mt-6">
        <Link v-for="(link, i) in batches.links" :key="i" :href="link.url ?? '#'"
              class="cv-page-btn" :class="{ active: link.active, disabled: !link.url }"
              v-html="link.label" preserve-scroll />
      </div>

    </div>

    <!-- Delete modal -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="deleteTarget = null">
        <div class="cv-modal" style="max-width:420px">
          <div class="cv-modal-header">
            <div class="flex items-center gap-2">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--cv-danger-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              </div>
              <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">{{ t.delTitle }}</div>
            </div>
          </div>
          <div class="cv-modal-body">
            <div class="cv-alert-danger" style="font-size:0.82rem;text-align:center">
              {{ t.delMsg }} <strong>{{ deleteTarget.reference }}</strong>?
            </div>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="deleteTarget = null">{{ t.cancel }}</button>
            <button class="cv-btn cv-btn-danger cv-btn-sm" :disabled="deleting" @click="doDelete">
              {{ deleting ? t.deleting : t.confirmDelete }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>
