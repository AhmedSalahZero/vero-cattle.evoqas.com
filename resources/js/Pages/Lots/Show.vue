<template>
  <AuthenticatedLayout>
    <div :dir="isRtl ? 'rtl' : 'ltr'">

      <!-- ── Hero ───────────────────────────────────────────────── -->
      <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
          <Link :href="route('lots.index')" class="back-link">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline :points="isRtl ? '9 18 15 12 9 6' : '15 18 9 12 15 6'"/>
            </svg>
            {{ isRtl ? 'الأقسام' : 'Lots' }}
          </Link>
        </div>
        <div class="cv-page-hero-title hero-title-row">
          <span>{{ lot.name }}</span>
          <span class="cv-badge" :class="statusBadge(lot.status)">{{ statusLabel(lot.status) }}</span>
        </div>
        <div class="cv-page-hero-sub">
          <span v-if="lot.lot_number">{{ lot.lot_number }} · </span>
          <span v-if="lot.farm">{{ n(lot.farm) }}</span>
          <span v-if="lot.pen"> · {{ isRtl ? 'العنبر' : 'Pen' }} {{ lot.pen }}</span>
        </div>
        <div class="cv-page-hero-actions">
          <Link v-if="canEdit" :href="route('lots.edit', lot.id)" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تعديل' : 'Edit' }}
          </Link>
          <Link :href="route('lots.index')" class="cv-btn cv-btn-ghost">
            {{ isRtl ? 'رجوع' : 'Back' }}
          </Link>
        </div>
      </div>

      <div class="cv-page">

        <div class="kpi-grid">
          <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ isRtl ? 'عدد الحيوانات' : 'Animals' }}</div>
            <div class="cv-stat-card-value">{{ lot.animals?.length ?? 0 }}</div>
          </div>
          <div class="cv-stat-card cv-stat-card-green">
            <div class="cv-stat-card-label">{{ isRtl ? 'النشطة' : 'Active' }}</div>
            <div class="cv-stat-card-value">{{ activeCount }}</div>
          </div>
        </div>

        <div class="info-grid">

          <div class="cv-card">
            <div class="card-eyebrow">{{ isRtl ? 'البيانات' : 'Details' }}</div>
            <div class="detail-grid">
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تاريخ البدء' : 'Start Date' }}</span><span class="detail-value">{{ lot.start_date || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تاريخ البيع المستهدف' : 'Target Sale Date' }}</span><span class="detail-value">{{ lot.target_sale_date || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تاريخ الإغلاق' : 'Closed Date' }}</span><span class="detail-value">{{ lot.closed_date || '—' }}</span></div>
            </div>
            <p v-if="note" class="notes-text">{{ note }}</p>
          </div>

          <div class="cv-card">
            <div class="card-eyebrow">{{ isRtl ? 'الحيوانات في القسم' : 'Animals in this lot' }}</div>
            <table v-if="lot.animals && lot.animals.length" class="cv-table">
              <thead>
                <tr>
                  <th class="text-start">{{ isRtl ? 'الرقم' : 'Tag' }}</th>
                  <th class="text-start">{{ isRtl ? 'السلالة' : 'Breed' }}</th>
                  <th class="text-start">{{ isRtl ? 'الحالة' : 'Status' }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in lot.animals" :key="a.id">
                  <td><Link :href="route('animals.show', a.id)" class="tag-link">{{ a.tag }}</Link></td>
                  <td>{{ a.breed || '—' }}</td>
                  <td><span class="cv-badge" :class="animalBadge(a.status)">{{ animalStatus(a.status) }}</span></td>
                </tr>
              </tbody>
            </table>
            <p v-else class="muted-text">{{ isRtl ? 'لا توجد حيوانات في هذا القسم.' : 'No animals in this lot.' }}</p>
          </div>

        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const { isRtl, n } = useLocale()
const { can } = usePermissions()

const props = defineProps({
  lot: { type: Object, required: true },
})

const canEdit = computed(() => can('edit lots'))
const activeCount = computed(() => (props.lot.animals ?? []).filter(a => a.status === 'active').length)
const note = computed(() => (isRtl.value ? props.lot.notes_ar || props.lot.notes_en : props.lot.notes_en || props.lot.notes_ar))

function statusLabel(s) {
  const en = { active: 'Active', completed: 'Completed', archived: 'Archived' }
  const ar = { active: 'نشط', completed: 'مكتمل', archived: 'مؤرشف' }
  return (isRtl.value ? ar : en)[s] ?? s
}
function statusBadge(s) {
  return { active: 'cv-badge-green', completed: 'cv-badge-info' }[s] ?? 'cv-badge-neutral'
}
function animalStatus(s) {
  const en = { active: 'Active', sold: 'Sold', slaughtered: 'Slaughtered', dead: 'Dead', quarantine: 'Quarantine', transferred: 'Transferred', reserved: 'Reserved' }
  const ar = { active: 'نشط', sold: 'مباع', slaughtered: 'مذبوح', dead: 'نافق', quarantine: 'حجر صحي', transferred: 'منقول', reserved: 'محجوز' }
  return (isRtl.value ? ar : en)[s] ?? s
}
function animalBadge(s) {
  return { active: 'cv-badge-green', reserved: 'cv-badge-amber', quarantine: 'cv-badge-amber', dead: 'cv-badge-danger' }[s] ?? 'cv-badge-neutral'
}
</script>

<style scoped>
.back-link { color: var(--cv-amber-text); text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; }
.hero-title-row { display: flex; align-items: center; gap: 0.75rem; }
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.875rem; margin-bottom: 1.5rem; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
@media (max-width: 768px) { .info-grid { grid-template-columns: 1fr; } }
.card-eyebrow { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--cv-text-muted); margin-bottom: 1rem; }
.detail-grid { display: flex; flex-direction: column; gap: 0.625rem; }
.detail-row { display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; font-size: 0.8rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--cv-border); }
.detail-row:last-child { border-bottom: none; padding-bottom: 0; }
.detail-label { color: var(--cv-text-muted); flex-shrink: 0; }
.detail-value { color: var(--cv-text-secondary); text-align: end; }
.tag-link { color: var(--cv-blue-text); text-decoration: none; }
.notes-text { margin-top: 1rem; font-size: 0.8rem; color: var(--cv-text-secondary); white-space: pre-wrap; }
.muted-text { font-size: 0.8rem; color: var(--cv-text-muted); }
</style>
