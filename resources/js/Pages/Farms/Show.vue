<template>
  <AuthenticatedLayout>
    <div :dir="isRtl ? 'rtl' : 'ltr'">

      <!-- ── Hero ───────────────────────────────────────────────── -->
      <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
          <Link :href="route('farms.index')" class="back-link">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline :points="isRtl ? '9 18 15 12 9 6' : '15 18 9 12 15 6'"/>
            </svg>
            {{ isRtl ? 'المزارع' : 'Farms' }}
          </Link>
        </div>
        <div class="cv-page-hero-title hero-title-row">
          <span>{{ n(farm) }}</span>
          <span class="cv-badge" :class="farm.is_active ? 'cv-badge-green' : 'cv-badge-neutral'">
            {{ farm.is_active ? (isRtl ? 'نشطة' : 'Active') : (isRtl ? 'غير نشطة' : 'Inactive') }}
          </span>
        </div>
        <div class="cv-page-hero-sub">
          <span v-if="farm.company">{{ n(farm.company) }}</span>
          <span v-if="farm.city"> · {{ farm.city }}</span>
        </div>
        <div class="cv-page-hero-actions">
          <Link v-if="canEdit" :href="route('farms.edit', farm.id)" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تعديل' : 'Edit' }}
          </Link>
          <Link :href="route('farms.index')" class="cv-btn cv-btn-ghost">
            {{ isRtl ? 'رجوع' : 'Back' }}
          </Link>
        </div>
      </div>

      <div class="cv-page">

        <div class="kpi-grid">
          <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ isRtl ? 'المساحة (فدان)' : 'Area (acres)' }}</div>
            <div class="cv-stat-card-value">{{ num(farm.area_acres) }}</div>
          </div>
          <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ isRtl ? 'السعة (رأس)' : 'Capacity (head)' }}</div>
            <div class="cv-stat-card-value">{{ num(farm.capacity_head, 0) }}</div>
          </div>
          <div class="cv-stat-card cv-stat-card-green">
            <div class="cv-stat-card-label">{{ isRtl ? 'المستخدمون' : 'Users' }}</div>
            <div class="cv-stat-card-value">{{ farm.users?.length ?? 0 }}</div>
          </div>
        </div>

        <div class="info-grid">

          <div class="cv-card">
            <div class="card-eyebrow">{{ isRtl ? 'البيانات' : 'Details' }}</div>
            <div class="detail-grid">
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'العنوان' : 'Address' }}</span><span class="detail-value">{{ n(farm, 'address') || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'المدينة' : 'City' }}</span><span class="detail-value">{{ farm.city || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'المنطقة' : 'Region' }}</span><span class="detail-value">{{ farm.region || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'الهاتف' : 'Phone' }}</span><span class="detail-value">{{ farm.phone || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'البريد الإلكتروني' : 'Email' }}</span><span class="detail-value">{{ farm.email || '—' }}</span></div>
            </div>
            <p v-if="note" class="notes-text">{{ note }}</p>
          </div>

          <div class="cv-card">
            <div class="card-eyebrow">{{ isRtl ? 'المستخدمون المرتبطون' : 'Assigned users' }}</div>
            <table v-if="farm.users && farm.users.length" class="cv-table">
              <thead>
                <tr>
                  <th class="text-start">{{ isRtl ? 'الاسم' : 'Name' }}</th>
                  <th class="text-start">{{ isRtl ? 'الوظيفة' : 'Job title' }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in farm.users" :key="u.id">
                  <td>
                    {{ userName(u) }}
                    <span v-if="u.is_primary" class="cv-badge cv-badge-amber">{{ isRtl ? 'رئيسي' : 'Primary' }}</span>
                  </td>
                  <td>{{ n(u, 'job_title') || '—' }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="muted-text">{{ isRtl ? 'لا يوجد مستخدمون مرتبطون بهذه المزرعة.' : 'No users are assigned to this farm.' }}</p>
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
  farm: { type: Object, required: true },
})

const canEdit = computed(() => can('edit farms'))
const note = computed(() => (isRtl.value ? props.farm.notes_ar || props.farm.notes_en : props.farm.notes_en || props.farm.notes_ar))

function userName(u) {
  return (isRtl.value ? u.name_ar || u.name_en : u.name_en || u.name_ar) || u.name || u.email
}

function num(v, digits = 2) {
  if (v === null || v === undefined || v === '') return '—'
  return Number(v).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: digits,
  })
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
.notes-text { margin-top: 1rem; font-size: 0.8rem; color: var(--cv-text-secondary); white-space: pre-wrap; }
.muted-text { font-size: 0.8rem; color: var(--cv-text-muted); }
</style>
