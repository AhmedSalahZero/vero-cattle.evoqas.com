<template>
  <AuthenticatedLayout>
    <div :dir="isRtl ? 'rtl' : 'ltr'">

      <!-- ── Hero ─────────────────────────────────────────────── -->
      <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
          {{ isRtl ? 'تخصيص التكاليف' : 'Cost Allocation' }}
        </div>
        <div class="cv-page-hero-title">
          {{ isRtl ? 'أنواع التكاليف العامة' : 'Overhead Types' }}
        </div>
        <div class="cv-page-hero-sub">
          {{ isRtl
            ? 'تعريف فئات التكاليف العامة للمزرعة — الكهرباء والمياه والوقود وغيرها'
            : 'Define farm overhead categories — electricity, water, fuel and others' }}
        </div>
        <div class="cv-page-hero-actions">
          <button class="cv-btn cv-btn-amber" @click="openCreate">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            {{ isRtl ? 'نوع جديد' : 'New Type' }}
          </button>
          <Link :href="route('farm-cost-allocations.index')" class="cv-btn cv-btn-ghost">
            {{ isRtl ? 'العودة' : 'Back' }}
          </Link>
        </div>
      </div>

      <div class="cv-page">

        <!-- Flash success -->
        <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               style="width:16px;height:16px;flex-shrink:0">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          {{ $page.props.flash.success }}
        </div>

        <!-- Info banner -->
        <div class="cv-alert cv-alert-info mb-4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               style="width:16px;height:16px;flex-shrink:0">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span>
            {{ isRtl
              ? 'كل نوع يُربط بحساب مستحقات (2271، 2272...) وحساب فروق (6321، 6322...). تأكد من إنشاء الحسابات أولاً في دليل الحسابات.'
              : 'Each type maps to an accrual account (2271, 2272…) and a variance account (6321, 6322…). Make sure those accounts exist in the Chart of Accounts first.' }}
          </span>
        </div>

        <!-- Table -->
        <div class="cv-card" style="padding:0;overflow:hidden">
          <table class="cv-table">
            <thead>
              <tr>
                <th>{{ isRtl ? 'الاسم' : 'Name' }}</th>
                <th>{{ isRtl ? 'حساب المستحقات' : 'Accrual Account' }}</th>
                <th>{{ isRtl ? 'حساب الفروق' : 'Variance Account' }}</th>
                <th>{{ isRtl ? 'الحالة' : 'Status' }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="types.length === 0">
                <td colspan="5">
                  <div class="cv-empty">
                    <div class="cv-empty-icon">⚡</div>
                    <div class="cv-empty-title">
                      {{ isRtl ? 'لا توجد أنواع تكاليف عامة بعد' : 'No overhead types yet' }}
                    </div>
                    <div class="cv-empty-sub">
                      {{ isRtl ? 'أضف كهرباء، مياه، وقود...' : 'Add electricity, water, fuel…' }}
                    </div>
                  </div>
                </td>
              </tr>
              <tr v-for="t in types" :key="t.id">
                <td>
                  <div style="font-weight:600;color:var(--cv-text-primary)">
                    {{ isRtl ? (t.name_ar || t.name_en) : (t.name_en || t.name_ar) }}
                  </div>
                  <div v-if="isRtl && t.name_en" style="font-size:0.7rem;color:var(--cv-text-muted)">
                    {{ t.name_en }}
                  </div>
                  <div v-if="!isRtl && t.name_ar" style="font-size:0.7rem;color:var(--cv-text-muted);direction:rtl">
                    {{ t.name_ar }}
                  </div>
                </td>
                <td>
                  <span class="cv-tag-blue" style="font-size:0.7rem;background:var(--cv-blue-light);color:var(--cv-blue-text);border:1px solid var(--cv-blue-border);padding:0.15rem 0.5rem;border-radius:4px;font-family:monospace">
                    {{ t.accrued_account_code }}
                  </span>
                  <span style="font-size:0.75rem;color:var(--cv-text-muted);margin-inline-start:0.4rem">
                    {{ t.accrued_account_name }}
                  </span>
                </td>
                <td>
                  <span class="cv-tag-blue" style="font-size:0.7rem;background:var(--cv-amber-light);color:var(--cv-amber-text);border:1px solid var(--cv-amber-border);padding:0.15rem 0.5rem;border-radius:4px;font-family:monospace">
                    {{ t.variance_account_code }}
                  </span>
                  <span style="font-size:0.75rem;color:var(--cv-text-muted);margin-inline-start:0.4rem">
                    {{ t.variance_account_name }}
                  </span>
                </td>
                <td>
                  <span class="cv-badge" :class="t.is_active ? 'cv-badge-green' : 'cv-badge-neutral'">
                    {{ t.is_active ? (isRtl ? 'نشط' : 'Active') : (isRtl ? 'غير نشط' : 'Inactive') }}
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:0.25rem;justify-content:flex-end">
                    <button class="cv-action-btn cv-action-btn-amber" @click="openEdit(t)"
                            :title="isRtl ? 'تعديل' : 'Edit'">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                           style="width:14px;height:14px">
                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                    </button>
                    <button class="cv-action-btn cv-action-btn-danger" @click="openDelete(t)"
                            :title="isRtl ? 'حذف' : 'Delete'">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                           style="width:14px;height:14px">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14H6L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                        <path d="M9 6V4h6v2"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── Create / Edit Panel ──────────────────────────────── -->
      <Transition name="modal">
        <div v-if="showPanel" class="cv-modal-backdrop" @click.self="closePanel">
          <div class="cv-modal cv-modal-md">
            <div class="cv-modal-header">
              <div class="cv-modal-title">
                {{ editingType
                    ? (isRtl ? 'تعديل النوع' : 'Edit Overhead Type')
                    : (isRtl ? 'نوع جديد' : 'New Overhead Type') }}
              </div>
              <button class="cv-action-btn" @click="closePanel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:16px;height:16px">
                  <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>
            <div class="cv-modal-body">
              <!-- Name EN -->
              <div class="cv-field" v-show="!isRtl">
                <label class="cv-label">
                  {{ isRtl ? 'الاسم (إنجليزي)' : 'Name (English)' }}
                </label>
                <input v-model="form.name_en" class="cv-input"
                       :class="{'cv-input--error': errors.name_en}"
                       placeholder="e.g. Electricity" />
                <div v-if="errors.name_en" class="cv-field-error">{{ errors.name_en }}</div>
              </div>
              <!-- Name AR (always submitted, shown in AR mode) -->
              <div class="cv-field" v-show="isRtl">
                <label class="cv-label">الاسم (عربي)</label>
                <input v-model="form.name_ar" class="cv-input"
                       :class="{'cv-input--error': errors.name_ar}"
                       placeholder="مثال: كهرباء" dir="rtl" />
                <div v-if="errors.name_ar" class="cv-field-error">{{ errors.name_ar }}</div>
              </div>
              <!-- Hidden counterpart (bilingual rule §5.5) -->
              <div v-show="false">
                <input v-model="form.name_en" />
                <input v-model="form.name_ar" />
              </div>

              <!-- Accrual Account -->
              <div class="cv-field">
                <label class="cv-label">
                  {{ isRtl ? 'حساب المستحقات (تحت مجموعة 2270)' : 'Accrual Account (under 2270 group)' }}
                </label>
                <select v-model="form.accrued_account_id" class="cv-select"
                        :class="{'cv-select--error': errors.accrued_account_id}">
                  <option value="">{{ isRtl ? '— اختر الحساب —' : '— Select account —' }}</option>
                  <option v-for="a in accrualAccounts" :key="a.id" :value="a.id">
                    {{ a.code }} — {{ isRtl ? (a.name_ar || a.name_en) : (a.name_en || a.name_ar) }}
                  </option>
                </select>
                <div v-if="errors.accrued_account_id" class="cv-field-error">
                  {{ errors.accrued_account_id }}
                </div>
              </div>

              <!-- Variance Account -->
              <div class="cv-field">
                <label class="cv-label">
                  {{ isRtl ? 'حساب الفروق (تحت مجموعة 6320)' : 'Variance Account (under 6320 group)' }}
                </label>
                <select v-model="form.variance_account_id" class="cv-select"
                        :class="{'cv-select--error': errors.variance_account_id}">
                  <option value="">{{ isRtl ? '— اختر الحساب —' : '— Select account —' }}</option>
                  <option v-for="a in varianceAccounts" :key="a.id" :value="a.id">
                    {{ a.code }} — {{ isRtl ? (a.name_ar || a.name_en) : (a.name_en || a.name_ar) }}
                  </option>
                </select>
                <div v-if="errors.variance_account_id" class="cv-field-error">
                  {{ errors.variance_account_id }}
                </div>
              </div>

              <!-- Active toggle (edit only) -->
              <div v-if="editingType" class="cv-field">
                <label class="cv-label">{{ isRtl ? 'الحالة' : 'Status' }}</label>
                <div style="display:flex;gap:0.5rem">
                  <button type="button"
                          class="cv-btn cv-btn-sm"
                          :class="form.is_active ? 'cv-btn-primary' : 'cv-btn-ghost'"
                          @click="form.is_active = true">
                    {{ isRtl ? 'نشط' : 'Active' }}
                  </button>
                  <button type="button"
                          class="cv-btn cv-btn-sm"
                          :class="!form.is_active ? 'cv-btn-danger' : 'cv-btn-ghost'"
                          @click="form.is_active = false">
                    {{ isRtl ? 'غير نشط' : 'Inactive' }}
                  </button>
                </div>
              </div>
            </div>
            <div class="cv-modal-footer">
              <button class="cv-btn cv-btn-ghost" @click="closePanel">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
              <button class="cv-btn cv-btn-amber" :disabled="saving" @click="submitPanel">
                {{ saving
                    ? (isRtl ? 'جارٍ الحفظ...' : 'Saving…')
                    : (isRtl ? 'حفظ' : 'Save') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- ── Delete Modal ─────────────────────────────────────── -->
      <Transition name="modal">
        <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="deleteTarget = null">
          <div class="cv-modal cv-modal-sm">
            <div class="cv-modal-header">
              <div class="cv-modal-title" style="color:var(--cv-danger-text)">
                {{ isRtl ? 'حذف النوع' : 'Delete Type' }}
              </div>
            </div>
            <div class="cv-modal-body" style="text-align:center">
              <div style="width:3rem;height:3rem;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:20px;height:20px;color:var(--cv-danger-text)">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/>
                </svg>
              </div>
              <div class="cv-alert cv-alert-danger" style="text-align:start">
                {{ isRtl
                    ? `هل أنت متأكد من حذف "${deleteTarget.name_ar || deleteTarget.name_en}"؟ لا يمكن التراجع عن هذا الإجراء.`
                    : `Are you sure you want to delete "${deleteTarget.name_en || deleteTarget.name_ar}"? This cannot be undone.` }}
              </div>
            </div>
            <div class="cv-modal-footer">
              <button class="cv-btn cv-btn-ghost" @click="deleteTarget = null">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
              <button class="cv-btn cv-btn-danger" :disabled="saving" @click="confirmDelete">
                {{ saving ? (isRtl ? 'جارٍ الحذف...' : 'Deleting…') : (isRtl ? 'حذف' : 'Delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>

    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const { isRtl } = useLocale()
const page = usePage()

const props = defineProps({
  types:            { type: Array,  default: () => [] },
  accrualAccounts:  { type: Array,  default: () => [] },
  varianceAccounts: { type: Array,  default: () => [] },
})

// ── Panel state ──────────────────────────────────────────────────
const showPanel   = ref(false)
const editingType = ref(null)
const saving      = ref(false)
const deleteTarget = ref(null)
const errors      = ref({})

const form = reactive({
  name_en:             '',
  name_ar:             '',
  accrued_account_id:  '',
  variance_account_id: '',
  is_active:           true,
})

function resetForm() {
  form.name_en             = ''
  form.name_ar             = ''
  form.accrued_account_id  = ''
  form.variance_account_id = ''
  form.is_active           = true
  errors.value             = {}
}

function openCreate() {
  editingType.value = null
  resetForm()
  showPanel.value = true
}

function openEdit(t) {
  editingType.value        = t
  form.name_en             = t.name_en || ''
  form.name_ar             = t.name_ar || ''
  form.accrued_account_id  = t.accrued_account_id || ''
  form.variance_account_id = t.variance_account_id || ''
  form.is_active           = t.is_active
  errors.value             = {}
  showPanel.value          = true
}

function closePanel() {
  showPanel.value = false
  editingType.value = null
}

function openDelete(t) {
  deleteTarget.value = t
}

function submitPanel() {
  saving.value = true
  errors.value = {}

  const payload = {
    name_en:             form.name_en  || null,
    name_ar:             form.name_ar  || null,
    accrued_account_id:  form.accrued_account_id,
    variance_account_id: form.variance_account_id,
    is_active:           form.is_active,
  }

  if (editingType.value) {
    router.put(
      route('farm-cost-allocations.overhead-types.update', editingType.value.id),
      payload,
      {
        preserveScroll: true,
        onSuccess: () => { saving.value = false; closePanel() },
        onError: (e) => { saving.value = false; errors.value = e },
      }
    )
  } else {
    router.post(
      route('farm-cost-allocations.overhead-types.store'),
      payload,
      {
        preserveScroll: true,
        onSuccess: () => { saving.value = false; closePanel() },
        onError: (e) => { saving.value = false; errors.value = e },
      }
    )
  }
}

function confirmDelete() {
  if (!deleteTarget.value) return
  saving.value = true
  router.delete(
    route('farm-cost-allocations.overhead-types.destroy', deleteTarget.value.id),
    {
      preserveScroll: true,
      onSuccess: () => { saving.value = false; deleteTarget.value = null },
      onError: (e) => {
        saving.value = false
        // Show server error inline
        alert(Object.values(e)[0] || (isRtl.value ? 'حدث خطأ' : 'An error occurred'))
        deleteTarget.value = null
      },
    }
  )
}
</script>
