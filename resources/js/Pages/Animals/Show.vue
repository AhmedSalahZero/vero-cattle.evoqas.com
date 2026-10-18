<template>
  <AuthenticatedLayout>
    <div :dir="isRtl ? 'rtl' : 'ltr'">

      <!-- ── Hero ───────────────────────────────────────────────── -->
      <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
          <Link :href="route('animals.index')" class="back-link">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline :points="isRtl ? '9 18 15 12 9 6' : '15 18 9 12 15 6'"/>
            </svg>
            {{ isRtl ? 'الحيوانات' : 'Animals' }}
          </Link>
        </div>
        <div class="cv-page-hero-title hero-title-row">
          <span>{{ animal.tag }}</span>
          <span class="cv-badge" :class="statusBadge(animal.status)">{{ statusLabel(animal.status) }}</span>
        </div>
        <div class="cv-page-hero-sub">
          <span v-if="animal.breed">{{ animal.breed }} · </span>{{ sexLabel(animal.sex) }}
          <span v-if="animal.lot"> · {{ animal.lot.name }}</span>
          <span v-if="animal.farm"> · {{ n(animal.farm) }}</span>
        </div>
        <div class="cv-page-hero-actions">
          <Link v-if="canEdit" :href="route('animals.edit', animal.id)" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تعديل' : 'Edit' }}
          </Link>
          <Link :href="route('animals.index')" class="cv-btn cv-btn-ghost">
            {{ isRtl ? 'رجوع' : 'Back' }}
          </Link>
        </div>
      </div>

      <div class="cv-page">

        <!-- ── KPI strip ─────────────────────────────────────────── -->
        <div class="kpi-grid">
          <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ isRtl ? 'وزن الشراء (كجم)' : 'Purchase Weight (kg)' }}</div>
            <div class="cv-stat-card-value">{{ num(animal.purchase_weight_kg) }}</div>
          </div>
          <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">{{ isRtl ? 'سعر الشراء' : 'Purchase Price' }}</div>
            <div class="cv-stat-card-value">{{ num(animal.purchase_price) }}</div>
            <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
          </div>
          <div class="cv-stat-card cv-stat-card-green">
            <div class="cv-stat-card-label">{{ isRtl ? 'آخر وزن (كجم)' : 'Latest Weight (kg)' }}</div>
            <div class="cv-stat-card-value">{{ latestWeight ? num(latestWeight.weight_kg) : '—' }}</div>
            <div class="cv-stat-card-sub">{{ latestWeight?.date ?? '' }}</div>
          </div>
        </div>

        <div class="info-grid">

          <!-- ── Details ────────────────────────────────────────── -->
          <div class="cv-card">
            <div class="card-eyebrow">{{ isRtl ? 'البيانات' : 'Details' }}</div>
            <div class="detail-grid">
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'الرقم التعريفي RFID' : 'RFID' }}</span><span class="detail-value">{{ animal.rfid || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تاريخ الميلاد' : 'Date of Birth' }}</span><span class="detail-value">{{ animal.dob || '—' }}<span v-if="animal.dob && animal.dob_estimated"> ({{ isRtl ? 'تقديري' : 'estimated' }})</span></span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'اللون' : 'Color' }}</span><span class="detail-value">{{ animal.color || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'العلامات' : 'Markings' }}</span><span class="detail-value">{{ animal.markings || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'المصدر' : 'Source' }}</span><span class="detail-value">{{ animal.source === 'born_on_farm' ? (isRtl ? 'مولود بالمزرعة' : 'Born on farm') : (isRtl ? 'مشترى' : 'Purchased') }}</span></div>
              <div v-if="animal.supplier" class="detail-row"><span class="detail-label">{{ isRtl ? 'المورد' : 'Supplier' }}</span><span class="detail-value">{{ n(animal.supplier) }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تاريخ الشراء' : 'Purchase Date' }}</span><span class="detail-value">{{ animal.purchase_date || '—' }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تكلفة الشحن' : 'Freight Cost' }}</span><span class="detail-value">{{ num(animal.freight_cost) }}</span></div>
              <div class="detail-row"><span class="detail-label">{{ isRtl ? 'تاريخ آخر حالة' : 'Status Date' }}</span><span class="detail-value">{{ animal.status_date || '—' }}</span></div>
            </div>
            <p v-if="animal.notes" class="notes-text">{{ animal.notes }}</p>
          </div>

          <!-- ── Weight history ─────────────────────────────────── -->
          <div class="cv-card">
            <div class="card-eyebrow">{{ isRtl ? 'سجل الأوزان (آخر 10)' : 'Weight History (last 10)' }}</div>
            <table v-if="animal.weight_records && animal.weight_records.length" class="cv-table">
              <thead>
                <tr>
                  <th class="text-start">{{ isRtl ? 'التاريخ' : 'Date' }}</th>
                  <th class="text-start">{{ isRtl ? 'الوزن (كجم)' : 'Weight (kg)' }}</th>
                  <th class="text-start">{{ isRtl ? 'الزيادة اليومية' : 'ADG' }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="w in animal.weight_records" :key="w.id">
                  <td>{{ w.date }}</td>
                  <td class="num-cell">{{ num(w.weight_kg) }}</td>
                  <td class="num-cell">{{ w.adg_kg !== null && w.adg_kg !== undefined ? num(w.adg_kg, 3) : '—' }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="muted-text">{{ isRtl ? 'لا توجد أوزان مسجلة.' : 'No weights recorded yet.' }}</p>
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
  animal: { type: Object, required: true },
})

const canEdit = computed(() => can('edit animals'))

// Weight records arrive newest-first
const latestWeight = computed(() => props.animal.weight_records?.[0] ?? null)

function num(v, digits = 2) {
  if (v === null || v === undefined || v === '') return '—'
  return Number(v).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: digits,
  })
}

function sexLabel(s) {
  const en = { male: 'Male', female: 'Female', castrated: 'Castrated' }
  const ar = { male: 'ذكر', female: 'أنثى', castrated: 'مخصي' }
  return (isRtl.value ? ar : en)[s] ?? s
}

function statusLabel(s) {
  const en = { active: 'Active', sold: 'Sold', slaughtered: 'Slaughtered', dead: 'Dead', quarantine: 'Quarantine', transferred: 'Transferred', reserved: 'Reserved' }
  const ar = { active: 'نشط', sold: 'مباع', slaughtered: 'مذبوح', dead: 'نافق', quarantine: 'حجر صحي', transferred: 'منقول', reserved: 'محجوز' }
  return (isRtl.value ? ar : en)[s] ?? s
}

function statusBadge(s) {
  return {
    active: 'cv-badge-green',
    reserved: 'cv-badge-amber',
    quarantine: 'cv-badge-amber',
    dead: 'cv-badge-danger',
  }[s] ?? 'cv-badge-neutral'
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
.num-cell { color: var(--cv-blue-text); }
.notes-text { margin-top: 1rem; font-size: 0.8rem; color: var(--cv-text-secondary); white-space: pre-wrap; }
.muted-text { font-size: 0.8rem; color: var(--cv-text-muted); }
</style>
