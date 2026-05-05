<template>
  <AuthenticatedLayout>
    <div :dir="isRtl ? 'rtl' : 'ltr'">

      <!-- ── Hero ─────────────────────────────────────────────── -->
      <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
          {{ isRtl ? 'تخصيص تكاليف المزرعة' : 'Farm Cost Allocation' }}
        </div>
        <div class="cv-page-hero-title">
          {{ isCreate
              ? (isRtl ? 'تسجيل شهر جديد' : 'New Monthly Record')
              : (isRtl ? 'تعديل السجل' : 'Edit Record') }}
        </div>
        <div class="cv-page-hero-sub">
          {{ isRtl
            ? 'أدخل تكاليف العمالة والتكاليف العامة الشهرية — سيُوزَّعها النظام تلقائياً على الماشية'
            : 'Enter monthly labor and overhead costs — system allocates automatically across livestock' }}
        </div>
      </div>

      <div class="cv-page" style="max-width:860px">

        <!-- Validation errors summary -->
        <div v-if="Object.keys(form.errors).length > 0" class="cv-alert cv-alert-danger mb-4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               style="width:16px;height:16px;flex-shrink:0">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <div>
            <div style="font-weight:600;margin-bottom:4px">
              {{ isRtl ? 'يرجى مراجعة الأخطاء أدناه' : 'Please review the errors below' }}
            </div>
            <ul style="margin:0;padding-inline-start:1.25rem;font-size:0.8rem">
              <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
            </ul>
          </div>
        </div>

        <!-- ── Section 1 — Farm & Period ──────────────────────── -->
        <div class="cv-card mb-4">
          <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem">
            <div style="width:2rem;height:2rem;border-radius:50%;background:linear-gradient(135deg,var(--cv-amber-hover),var(--cv-amber));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#0A1808;flex-shrink:0">
              1
            </div>
            <div>
              <div style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary)">
                {{ isRtl ? 'المزرعة والفترة' : 'Farm & Period' }}
              </div>
              <div style="font-size:0.75rem;color:var(--cv-text-muted)">
                {{ isRtl ? 'اختر المزرعة والشهر الذي تسجّل تكاليفه' : 'Select the farm and month for this cost record' }}
              </div>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <!-- Farm -->
            <div class="cv-field">
              <label class="cv-label">
                {{ isRtl ? 'المزرعة' : 'Farm' }}
                <span style="color:var(--cv-danger-text)"> *</span>
              </label>
              <select v-model="form.farm_id" class="cv-select"
                      :class="{'cv-select--error': form.errors.farm_id}"
                      :disabled="!isCreate"
                      @change="onFarmOrMonthChange">
                <option value="">{{ isRtl ? '— اختر المزرعة —' : '— Select farm —' }}</option>
                 <option v-for="f in farms" :key="f.id" :value="f.id">
                  {{ isRtl ? (f.name_ar || f.name_en) : (f.name_en || f.name_ar) }}
                </option>
              </select>
              <div v-if="form.errors.farm_id" class="cv-field-error">{{ form.errors.farm_id }}</div>
              <div v-if="!isCreate" style="font-size:0.7rem;color:var(--cv-text-muted);margin-top:4px">
                {{ isRtl ? 'لا يمكن تغيير المزرعة بعد الإنشاء' : 'Farm cannot be changed after creation' }}
              </div>
            </div>

            <!-- Fiscal Year -->
            <div class="cv-field">
              <label class="cv-label">
                {{ isRtl ? 'السنة المالية' : 'Fiscal Year' }}
                <span style="color:var(--cv-danger-text)"> *</span>
              </label>
              <select v-model="form.fiscal_year_id" class="cv-select"
                      :class="{'cv-select--error': form.errors.fiscal_year_id}"
                      :disabled="!isCreate">
                <option value="">{{ isRtl ? '— اختر السنة —' : '— Select year —' }}</option>
                <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">
                  {{ fy.year }} ({{ isRtl ? 'مفتوح' : 'Open' }})
                </option>
              </select>
              <div v-if="form.errors.fiscal_year_id" class="cv-field-error">{{ form.errors.fiscal_year_id }}</div>
            </div>

            <!-- Month -->
            <div class="cv-field">
              <label class="cv-label">
                {{ isRtl ? 'الشهر' : 'Month' }}
                <span style="color:var(--cv-danger-text)"> *</span>
              </label>
              <select v-model="form.month" class="cv-select"
                      :class="{'cv-select--error': form.errors.month}"
                      :disabled="!isCreate"
                      @change="onFarmOrMonthChange">
                <option value="">{{ isRtl ? '— اختر الشهر —' : '— Select month —' }}</option>
                <option v-for="m in monthOptions" :key="m.value" :value="m.value">
                  {{ isRtl ? m.ar : m.en }}
                </option>
              </select>
              <div v-if="form.errors.month" class="cv-field-error">{{ form.errors.month }}</div>
            </div>

            <!-- Year -->
            <div class="cv-field">
              <label class="cv-label">
                {{ isRtl ? 'السنة' : 'Year' }}
                <span style="color:var(--cv-danger-text)"> *</span>
              </label>
              <select v-model="form.year" class="cv-select"
                      :class="{'cv-select--error': form.errors.year}"
                      :disabled="!isCreate"
                      @change="onFarmOrMonthChange">
                <option value="">{{ isRtl ? '— اختر السنة —' : '— Select year —' }}</option>
                <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
              </select>
              <div v-if="form.errors.year" class="cv-field-error">{{ form.errors.year }}</div>
            </div>
          </div>
        </div>

        <!-- ── Section 2 — Tabs: Labor / Overheads ────────────── -->
        <div class="cv-card mb-4">
          <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem">
            <div style="width:2rem;height:2rem;border-radius:50%;background:linear-gradient(135deg,var(--cv-blue-hover),var(--cv-blue));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#fff;flex-shrink:0">
              2
            </div>
            <div>
              <div style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary)">
                {{ isRtl ? 'التكاليف المقدّرة' : 'Estimated Costs' }}
              </div>
              <div style="font-size:0.75rem;color:var(--cv-text-muted)">
                {{ isRtl ? 'أدخل تكلفة العمالة والتكاليف العامة لهذا الشهر' : 'Enter labor cost and overheads for this month' }}
              </div>
            </div>
          </div>

          <!-- Tab switcher -->
          <div class="cv-tabs" style="margin-bottom:1.25rem">
            <button class="cv-tab" :class="{ active: activeTab === 'labor' }" @click="activeTab = 'labor'">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                   style="width:14px;height:14px;display:inline;margin-inline-end:4px">
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
              </svg>
              {{ isRtl ? 'العمالة' : 'Labor' }}
            </button>
            <button class="cv-tab" :class="{ active: activeTab === 'overhead' }" @click="activeTab = 'overhead'">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                   style="width:14px;height:14px;display:inline;margin-inline-end:4px">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
              </svg>
              {{ isRtl ? 'التكاليف العامة' : 'Overheads' }}
            </button>
          </div>

          <!-- Labor Tab -->
          <div v-show="activeTab === 'labor'">
            <div class="cv-field">
              <label class="cv-label">
                {{ isRtl ? 'إجمالي تكلفة العمالة المقدّرة لهذا الشهر (EGP)' : 'Total estimated labor cost for this month (EGP)' }}
                <span style="color:var(--cv-danger-text)"> *</span>
              </label>
              <div class="cv-input-group">
                <div class="cv-input-prefix">EGP</div>
                <input v-model="form.labor_estimated" type="number" min="0" step="0.01"
                       class="cv-input"
                       :class="{'cv-input--error': form.errors.labor_estimated}"
                       :placeholder="isRtl ? '0.00' : '0.00'"
                       @input="onLaborChange" />
              </div>
              <div v-if="form.errors.labor_estimated" class="cv-field-error">
                {{ form.errors.labor_estimated }}
              </div>
            </div>

            <!-- Live preview -->
            <div v-if="preview" class="cv-card cv-card-stripe-blue" style="margin-top:1rem">
              <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:var(--cv-blue-text);margin-bottom:0.75rem">
                {{ isRtl ? 'معاينة التوزيع' : 'Allocation Preview' }}
              </div>
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:0.75rem">
                <div>
                  <div style="font-size:0.65rem;color:var(--cv-text-muted)">
                    {{ isRtl ? 'إجمالي أيام الماشية' : 'Total Animal-Days' }}
                  </div>
                  <div style="font-size:1.1rem;font-weight:700;color:var(--cv-blue-text)">
                    {{ preview.total_animal_days.toLocaleString() }}
                  </div>
                </div>
                <div>
                  <div style="font-size:0.65rem;color:var(--cv-text-muted)">
                    {{ isRtl ? 'عدد الحيوانات النشطة' : 'Active Animals' }}
                  </div>
                  <div style="font-size:1.1rem;font-weight:700;color:var(--cv-green-bright)">
                    {{ preview.active_animal_count }}
                  </div>
                </div>
                <div>
                  <div style="font-size:0.65rem;color:var(--cv-text-muted)">
                    {{ isRtl ? 'التكلفة اليومية الإجمالية' : 'Daily Total Rate' }}
                  </div>
                  <div style="font-size:1.1rem;font-weight:700;color:var(--cv-amber-text)">
                    {{ formatMoney(preview.daily_rate) }} EGP
                  </div>
                </div>
                <div>
                  <div style="font-size:0.65rem;color:var(--cv-text-muted)">
                    {{ isRtl ? 'التكلفة / يوم / رأس' : 'Rate / Animal-Day' }}
                  </div>
                  <div style="font-size:1.1rem;font-weight:700;color:var(--cv-amber-text)">
                    {{ formatMoney(preview.rate_per_animal_day) }} EGP
                  </div>
                </div>
                <div>
                  <div style="font-size:0.65rem;color:var(--cv-text-muted)">
                    {{ isRtl ? 'حصة الرأس (شهر كامل)' : 'Per-Animal (Full Month)' }}
                  </div>
                  <div style="font-size:1.1rem;font-weight:700;color:var(--cv-text-primary)">
                    {{ formatMoney(preview.projected_per_animal_month) }} EGP
                  </div>
                </div>
              </div>
              <div style="font-size:0.7rem;color:var(--cv-text-muted);margin-top:0.75rem">
                {{ isRtl
                  ? '* أرقام تقديرية بناءً على بيانات الماشية الحالية. قد تتغير عند الترحيل الفعلي في نهاية الشهر.'
                  : '* Provisional figures based on current animal data. May differ at month-end posting.' }}
              </div>
            </div>

            <div v-if="loadingPreview"
                 style="text-align:center;padding:1.5rem;color:var(--cv-text-muted);font-size:0.8rem">
              {{ isRtl ? 'جارٍ حساب المعاينة...' : 'Calculating preview…' }}
            </div>

            <div v-if="previewError" class="cv-alert cv-alert-warning mt-3">
              {{ isRtl ? 'تعذّر تحميل المعاينة. تأكد من اختيار المزرعة والشهر والسنة.' : 'Preview unavailable. Select farm, month, and year first.' }}
            </div>
          </div>

          <!-- Overhead Tab -->
          <div v-show="activeTab === 'overhead'">
            <div v-if="overheadTypes.length === 0" class="cv-alert cv-alert-info mb-4">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                   style="width:16px;height:16px;flex-shrink:0">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              <span>
                {{ isRtl
                  ? 'لا توجد أنواع تكاليف عامة. '
                  : 'No overhead types configured. ' }}
                <Link :href="route('farm-cost-allocations.overhead-types.index')"
                      style="color:var(--cv-blue-text);text-decoration:underline">
                  {{ isRtl ? 'أضف أنواعاً الآن' : 'Add types now' }}
                </Link>
              </span>
            </div>

            <div v-for="(line, idx) in form.overhead_lines" :key="line.overhead_type_id"
                 class="cv-field" style="border-bottom:1px solid var(--cv-border);padding-bottom:0.75rem;margin-bottom:0.75rem">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.375rem">
                <label class="cv-label" style="margin-bottom:0">
                  {{ isRtl
                    ? (overheadTypeNameAr(line.overhead_type_id) || overheadTypeNameEn(line.overhead_type_id))
                    : (overheadTypeNameEn(line.overhead_type_id) || overheadTypeNameAr(line.overhead_type_id)) }}
                </label>
                <span style="font-size:0.65rem;color:var(--cv-text-muted)">
                  {{ overheadAccrualCode(line.overhead_type_id) }}
                </span>
              </div>
              <div class="cv-input-group">
                <div class="cv-input-prefix">EGP</div>
                <input v-model="form.overhead_lines[idx].estimated_amount"
                       type="number" min="0" step="0.01"
                       class="cv-input"
                       :placeholder="isRtl ? '0.00' : '0.00'" />
              </div>
            </div>

            <!-- Total -->
            <div v-if="form.overhead_lines.length > 0"
                 style="display:flex;justify-content:flex-end;align-items:center;gap:0.75rem;padding-top:0.5rem">
              <span style="font-size:0.75rem;color:var(--cv-text-muted)">
                {{ isRtl ? 'إجمالي التكاليف العامة:' : 'Total overheads:' }}
              </span>
              <span style="font-size:1rem;font-weight:700;color:var(--cv-blue-text)">
                {{ formatMoney(totalOverhead) }} EGP
              </span>
            </div>
          </div>
        </div>

        <!-- ── Section 3 — Notes ───────────────────────────────── -->
        <div class="cv-card mb-4">
          <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem">
            <div style="width:2rem;height:2rem;border-radius:50%;background:linear-gradient(135deg,var(--cv-green-deep),var(--cv-green));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#fff;flex-shrink:0">
              3
            </div>
            <div style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary)">
              {{ isRtl ? 'ملاحظات' : 'Notes' }}
            </div>
          </div>
          <textarea v-model="form.notes" rows="3" class="cv-textarea"
                    :placeholder="isRtl ? 'ملاحظات اختيارية...' : 'Optional notes…'" />
        </div>

        <!-- ── Actions ─────────────────────────────────────────── -->
        <div style="display:flex;gap:0.75rem;justify-content:flex-end">
          <Link :href="route('farm-cost-allocations.index')" class="cv-btn cv-btn-ghost">
            {{ isRtl ? 'إلغاء' : 'Cancel' }}
          </Link>
          <button class="cv-btn cv-btn-amber" :disabled="form.processing" @click="submit">
            <span v-if="form.processing">{{ isRtl ? 'جارٍ الحفظ...' : 'Saving…' }}</span>
            <span v-else>{{ isCreate ? (isRtl ? 'إنشاء السجل' : 'Create Record') : (isRtl ? 'حفظ التعديلات' : 'Save Changes') }}</span>
          </button>
        </div>

      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const { isRtl } = useLocale()

const props = defineProps({
  mode:          { type: String, default: 'create' },
  allocation:    { type: Object, default: null },
  farms:         { type: Array,  default: () => [] },
  fiscalYears:   { type: Array,  default: () => [] },
  overheadTypes: { type: Array,  default: () => [] },
  paymentAccounts: { type: Array, default: () => [] },
})

const isCreate = computed(() => props.mode === 'create')

// ── Month options ────────────────────────────────────────────────
const monthOptions = [
  { value: 1,  en: 'January',   ar: 'يناير' },
  { value: 2,  en: 'February',  ar: 'فبراير' },
  { value: 3,  en: 'March',     ar: 'مارس' },
  { value: 4,  en: 'April',     ar: 'أبريل' },
  { value: 5,  en: 'May',       ar: 'مايو' },
  { value: 6,  en: 'June',      ar: 'يونيو' },
  { value: 7,  en: 'July',      ar: 'يوليو' },
  { value: 8,  en: 'August',    ar: 'أغسطس' },
  { value: 9,  en: 'September', ar: 'سبتمبر' },
  { value: 10, en: 'October',   ar: 'أكتوبر' },
  { value: 11, en: 'November',  ar: 'نوفمبر' },
  { value: 12, en: 'December',  ar: 'ديسمبر' },
]

const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i + 1)

// ── Build initial overhead lines from overhead types ──────────────
function buildOverheadLines() {
  if (props.allocation?.overhead_lines?.length) {
    return props.allocation.overhead_lines.map(l => ({
      overhead_type_id: l.overhead_type_id,
      estimated_amount: l.estimated_amount ?? 0,
    }))
  }
  // Create: one line per active overhead type
  return props.overheadTypes.map(t => ({
    overhead_type_id: t.id,
    estimated_amount: 0,
  }))
}

// ── Form ─────────────────────────────────────────────────────────
const form = useForm({
  farm_id:          props.allocation?.farm_id          ?? '',
  fiscal_year_id:   props.allocation?.fiscal_year_id   ?? '',
  month:            props.allocation?.month            ?? '',
  year:             props.allocation?.year             ?? currentYear,
  labor_estimated:  props.allocation?.labor_estimated  ?? '',
  notes:            props.allocation?.notes            ?? '',
  overhead_lines:   buildOverheadLines(),
})

// ── Active tab ───────────────────────────────────────────────────
const activeTab = ref('labor')

// ── Live preview ─────────────────────────────────────────────────
const preview       = ref(null)
const loadingPreview = ref(false)
const previewError  = ref(false)
let previewTimer    = null

function onFarmOrMonthChange() {
  fetchPreview()
}

function onLaborChange() {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(fetchPreview, 600)
}

async function fetchPreview() {
  if (!form.farm_id || !form.month || !form.year || !form.labor_estimated) {
    preview.value = null
    return
  }
  loadingPreview.value = true
  previewError.value   = false
  try {
    const res = await fetch(
      route('farm-cost-allocations.preview-rates') +
      `?farm_id=${form.farm_id}&month=${form.month}&year=${form.year}&labor_estimated=${form.labor_estimated}`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    )
    if (!res.ok) throw new Error()
    preview.value = await res.json()
  } catch {
    previewError.value = true
    preview.value = null
  } finally {
    loadingPreview.value = false
  }
}

// ── Overhead helpers ─────────────────────────────────────────────
function overheadTypeNameEn(id) {
  return props.overheadTypes.find(t => t.id === id)?.name_en || ''
}
function overheadTypeNameAr(id) {
  return props.overheadTypes.find(t => t.id === id)?.name_ar || ''
}
function overheadAccrualCode(id) {
  return props.overheadTypes.find(t => t.id === id)?.accrued_account_code || ''
}

const totalOverhead = computed(() =>
  form.overhead_lines.reduce((sum, l) => sum + parseFloat(l.estimated_amount || 0), 0)
)

// ── Money format ─────────────────────────────────────────────────
function formatMoney(val) {
  if (val == null || val === '') return '0.00'
  return Number(val).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  })
}

// ── Submit ───────────────────────────────────────────────────────
function submit() {
  if (isCreate.value) {
    form.post(route('farm-cost-allocations.store'))
  } else {
    form.put(route('farm-cost-allocations.update', props.allocation.id))
  }
}
</script>
