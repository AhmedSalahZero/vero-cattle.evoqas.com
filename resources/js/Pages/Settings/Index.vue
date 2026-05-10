<template>
  

    <!-- ══ PAGE HERO ══════════════════════════════════════════════ -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ isRtl ? 'الإعدادات' : 'Configuration' }}</div>
      <div class="cv-page-hero-title">{{ isRtl ? 'إعدادات النظام' : 'System Settings' }}</div>
      <div class="cv-page-hero-sub">
        {{ isRtl
          ? 'إدارة قوائم البحث المستخدمة في النظام'
          : 'Manage lookup lists used across the application' }}
      </div>
    </div>

    <div class="cv-page">

      <!-- ══ FLASH ══════════════════════════════════════════════ -->
      <div v-if="$page.props.flash?.success" class="cv-flash-success mb-5">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="cv-flash-error mb-5">
        {{ $page.props.flash.error }}
      </div>

      <!-- ══ TAB BAR ════════════════════════════════════════════ -->
      <div class="tab-bar mb-6">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          @click="activeTab = tab.key"
          :class="['tab-btn', activeTab === tab.key ? 'tab-btn-active' : 'tab-btn-inactive']"
        >
          <span class="tab-icon" v-html="tab.icon"></span>
          <span>{{ isRtl ? tab.labelAr : tab.labelEn }}</span>
          <span class="tab-count">{{ tab.count }}</span>
        </button>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 1 — BREEDS
      ══════════════════════════════════════════════════════ -->
      <div v-if="activeTab === 'breeds'">

        <!-- Section header -->
        <div class="section-header mb-5">
          <div>
            <h2 class="section-title">{{ isRtl ? 'أسماء السلالات' : 'Breed Names' }}</h2>
            <p class="section-sub">
              {{ isRtl
                ? 'تظهر هذه السلالات كخيارات عند إضافة حيوان جديد'
                : 'These breeds appear as options when adding a new animal' }}
            </p>
          </div>
          <button
            @click="startAddBreed"
            class="cv-btn cv-btn-amber"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 4v16m8-8H4"/>
            </svg>
            {{ isRtl ? 'إضافة سلالة' : 'Add Breed' }}
          </button>
        </div>

        <!-- Add breed inline form -->
        <div v-if="addingBreed" class="cv-card inline-form-card mb-4">
          <div class="inline-form-title">
            {{ isRtl ? 'سلالة جديدة' : 'New Breed' }}
          </div>
          <div class="inline-form-row">
            <!-- EN field -->
            <div v-show="lang === 'en'" class="flex-1">
              <label class="cv-label">{{ isRtl ? 'الاسم بالإنجليزية' : 'Name (English)' }} <span class="cv-required">*</span></label>
              <input
                v-model="breedForm.name_en"
                class="cv-input"
                placeholder="e.g. Holstein Friesian"
                @keyup.enter="submitBreed"
              />
            </div>
            <!-- AR field -->
            <div v-show="lang === 'ar'" class="flex-1">
              <label class="cv-label">{{ isRtl ? 'الاسم بالعربية' : 'Name (Arabic)' }} <span class="cv-required">*</span></label>
              <input
                v-model="breedForm.name_ar"
                class="cv-input"
                dir="rtl"
                placeholder="مثال: هولشتاين فريزيان"
                @keyup.enter="submitBreed"
              />
            </div>
            <!-- Hidden counterpart — always submitted (Bilingual Form Rule §4.2) -->
            <input v-show="false" v-model="breedForm.name_en" />
            <input v-show="false" v-model="breedForm.name_ar" />

            <div class="inline-form-actions">
              <button
                @click="submitBreed"
                :disabled="!breedForm.name_en && !breedForm.name_ar"
                class="cv-btn cv-btn-amber btn-sm"
              >
                {{ isRtl ? 'حفظ' : 'Save' }}
              </button>
              <button @click="cancelBreed" class="cv-btn cv-btn-ghost btn-sm">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
            </div>
          </div>
          <p v-if="breedErrors.name_en" class="cv-error-text mt-1">{{ breedErrors.name_en }}</p>
        </div>

        <!-- Breeds table -->
        <div class="cv-card">
          <div v-if="localBreeds.length === 0" class="empty-state">
            <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4"
                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p>{{ isRtl ? 'لا توجد سلالات بعد. أضف واحدة للبدء.' : 'No breeds yet. Add one to get started.' }}</p>
          </div>

          <table v-else class="settings-table">
            <thead>
              <tr>
                <th class="text-start">{{ isRtl ? 'الاسم' : 'Name' }}</th>
                <th class="text-center w-28">{{ isRtl ? 'الحالة' : 'Status' }}</th>
                <th class="w-24"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="breed in localBreeds" :key="breed.id">
                <!-- Name — inline edit or display -->
                <td>
                  <div v-if="editingBreedId === breed.id" class="inline-edit-row">
                    <input
                      v-show="lang === 'en'"
                      v-model="breedEditForm.name_en"
                      class="cv-input input-sm"
                      placeholder="Name (English)"
                    />
                    <input
                      v-show="lang === 'ar'"
                      v-model="breedEditForm.name_ar"
                      class="cv-input input-sm"
                      dir="rtl"
                      placeholder="الاسم بالعربية"
                    />
                    <input v-show="false" v-model="breedEditForm.name_en" />
                    <input v-show="false" v-model="breedEditForm.name_ar" />
                  </div>
                  <span v-else class="item-name">
                    {{ n(breed, 'name') || '—' }}
                    <span v-if="n(breed, 'name') !== (lang === 'en' ? breed.name_ar : breed.name_en) && (lang === 'en' ? breed.name_ar : breed.name_en)" class="item-name-alt">
                      {{ lang === 'en' ? breed.name_ar : breed.name_en }}
                    </span>
                  </span>
                </td>

                <!-- Status badge -->
                <td class="text-center">
                  <span :class="['cv-badge', breed.is_active ? 'cv-badge-green' : 'cv-badge-muted']">
                    {{ breed.is_active ? (isRtl ? 'نشط' : 'Active') : (isRtl ? 'غير نشط' : 'Inactive') }}
                  </span>
                </td>

                <!-- Actions -->
                <td>
                  <div v-if="editingBreedId === breed.id" class="row-actions">
                    <button @click="saveBreed(breed)" class="cv-btn cv-btn-amber btn-xs">
                      {{ isRtl ? '✓' : '✓' }}
                    </button>
                    <button @click="cancelEditBreed" class="cv-btn cv-btn-ghost btn-xs">✕</button>
                  </div>
                  <div v-else class="row-actions">
                    <button @click="startEditBreed(breed)" class="action-icon-btn" :title="isRtl ? 'تعديل' : 'Edit'">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </button>
                    <button @click="toggleBreed(breed)" class="action-icon-btn" :title="breed.is_active ? (isRtl ? 'تعطيل' : 'Deactivate') : (isRtl ? 'تفعيل' : 'Activate')">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path v-if="breed.is_active" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                    </button>
                    <button @click="confirmDeleteBreed(breed)" class="action-icon-btn action-icon-danger" :title="isRtl ? 'حذف' : 'Delete'">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 2 — INVENTORY ITEM TEMPLATES
      ══════════════════════════════════════════════════════ -->
      <div v-if="activeTab === 'templates'">

        <!-- Section header -->
        <div class="section-header mb-5">
          <div>
            <h2 class="section-title">{{ isRtl ? ' أصناف المخزون' : 'Inventory Item ' }}</h2>
            <p class="section-sub">
              {{ isRtl
                ? 'عند إضافة صنف مخزون، يختار المستخدم  ويتم تعبئة الاسم والفئة تلقائياً'
                : 'When adding an inventory item, selecting an item auto-fills the name and category' }}
            </p>
          </div>
          <button @click="startAddTemplate" class="cv-btn cv-btn-amber">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 4v16m8-8H4"/>
            </svg>
            {{ isRtl ? 'إضافة صنف' : 'Add Item' }}
          </button>
        </div>

        <!-- Add template inline form -->
        <div v-if="addingTemplate" class="cv-card inline-form-card mb-4">
          <div class="inline-form-title">
            {{ isRtl ? 'صنف جديد' : 'New Item' }}
          </div>
          <div class="inline-form-row flex-wrap">
            <!-- EN field -->
            <div v-show="lang === 'en'" class="flex-1 min-w-48">
              <label class="cv-label">{{ isRtl ? 'الاسم بالإنجليزية' : 'Name (English)' }} <span class="cv-required">*</span></label>
              <input
                v-model="templateForm.name_en"
                class="cv-input"
                placeholder="e.g. Oxytetracycline"
              />
            </div>
            <!-- AR field -->
            <div v-show="lang === 'ar'" class="flex-1 min-w-48">
              <label class="cv-label">{{ isRtl ? 'الاسم بالعربية' : 'Name (Arabic)' }} <span class="cv-required">*</span></label>
              <input
                v-model="templateForm.name_ar"
                class="cv-input"
                dir="rtl"
                placeholder="مثال: أوكسي تتراسيكلين"
              />
            </div>
            <!-- Hidden counterparts -->
            <input v-show="false" v-model="templateForm.name_en" />
            <input v-show="false" v-model="templateForm.name_ar" />

            <!-- Category -->
            <div class="w-48">
              <label class="cv-label">{{ isRtl ? 'الفئة' : 'Category' }} <span class="cv-required">*</span></label>
              <select v-model="templateForm.category" class="cv-select">
                <option value="">{{ isRtl ? '— اختر —' : '— Select —' }}</option>
                <option v-for="cat in categories" :key="cat.value" :value="cat.value">
                  {{ isRtl ? cat.labelAr : cat.labelEn }}
                </option>
              </select>
            </div>

            <div class="inline-form-actions">
              <button
                @click="submitTemplate"
                :disabled="(!templateForm.name_en && !templateForm.name_ar) || !templateForm.category"
                class="cv-btn cv-btn-amber btn-sm"
              >
                {{ isRtl ? 'حفظ' : 'Save' }}
              </button>
              <button @click="cancelTemplate" class="cv-btn cv-btn-ghost btn-sm">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
            </div>
          </div>
          <p v-if="templateErrors.name_en" class="cv-error-text mt-1">{{ templateErrors.name_en }}</p>
          <p v-if="templateErrors.category" class="cv-error-text mt-1">{{ templateErrors.category }}</p>
        </div>

        <!-- Templates table -->
        <div class="cv-card">
          <div v-if="localTemplates.length === 0" class="empty-state">
            <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4"
                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <p>{{ isRtl ? 'لا توجد أصناف بعد. أضف واحداً للبدء.' : 'No items yet. Add one to get started.' }}</p>
          </div>

          <table v-else class="settings-table">
            <thead>
              <tr>
                <th class="text-start">{{ isRtl ? 'الاسم' : 'Name' }}</th>
                <th class="text-start w-36">{{ isRtl ? 'الفئة' : 'Category' }}</th>
                <th class="text-center w-28">{{ isRtl ? 'الحالة' : 'Status' }}</th>
                <th class="w-28"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tpl in localTemplates" :key="tpl.id">

                <!-- Name -->
                <td>
                  <div v-if="editingTemplateId === tpl.id" class="inline-edit-row">
                    <input
                      v-show="lang === 'en'"
                      v-model="templateEditForm.name_en"
                      class="cv-input input-sm"
                      placeholder="Name (English)"
                    />
                    <input
                      v-show="lang === 'ar'"
                      v-model="templateEditForm.name_ar"
                      class="cv-input input-sm"
                      dir="rtl"
                      placeholder="الاسم بالعربية"
                    />
                    <input v-show="false" v-model="templateEditForm.name_en" />
                    <input v-show="false" v-model="templateEditForm.name_ar" />
                  </div>
                  <span v-else class="item-name">
                    {{ n(tpl, 'name') || '—' }}
                    <span v-if="lang === 'en' && tpl.name_ar" class="item-name-alt">{{ tpl.name_ar }}</span>
                    <span v-if="lang === 'ar' && tpl.name_en" class="item-name-alt">{{ tpl.name_en }}</span>
                  </span>
                </td>

                <!-- Category -->
                <td>
                  <div v-if="editingTemplateId === tpl.id">
                    <select v-model="templateEditForm.category" class="cv-select input-sm">
                      <option v-for="cat in categories" :key="cat.value" :value="cat.value">
                        {{ isRtl ? cat.labelAr : cat.labelEn }}
                      </option>
                    </select>
                  </div>
                  <span v-else :class="['cv-badge', categoryBadgeClass(tpl.category)]">
                    {{ categoryLabel(tpl.category) }}
                  </span>
                </td>

                <!-- Status -->
                <td class="text-center">
                  <span :class="['cv-badge', tpl.is_active ? 'cv-badge-green' : 'cv-badge-muted']">
                    {{ tpl.is_active ? (isRtl ? 'نشط' : 'Active') : (isRtl ? 'غير نشط' : 'Inactive') }}
                  </span>
                </td>

                <!-- Actions -->
                <td>
                  <div v-if="editingTemplateId === tpl.id" class="row-actions">
                    <button @click="saveTemplate(tpl)" class="cv-btn cv-btn-amber btn-xs">✓</button>
                    <button @click="cancelEditTemplate" class="cv-btn cv-btn-ghost btn-xs">✕</button>
                  </div>
                  <div v-else class="row-actions">
                    <button @click="startEditTemplate(tpl)" class="action-icon-btn" :title="isRtl ? 'تعديل' : 'Edit'">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </button>
                    <button @click="toggleTemplate(tpl)" class="action-icon-btn" :title="tpl.is_active ? (isRtl ? 'تعطيل' : 'Deactivate') : (isRtl ? 'تفعيل' : 'Activate')">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path v-if="tpl.is_active" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                    </button>
                    <button @click="confirmDeleteTemplate(tpl)" class="action-icon-btn action-icon-danger" :title="isRtl ? 'حذف' : 'Delete'">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /cv-page -->

    <!-- ══ DELETE CONFIRMATION MODAL ═════════════════════════════ -->
    <div v-if="deleteConfirm.show" class="cv-modal-backdrop" @click.self="deleteConfirm.show = false">
      <div class="cv-modal" style="max-width:420px;">
        <div class="cv-modal-header">
          <div class="delete-modal-icon">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
          </div>
          <div>
            <div class="cv-modal-title">{{ isRtl ? 'تأكيد الحذف' : 'Confirm Delete' }}</div>
            <div class="cv-modal-sub">{{ deleteConfirm.message }}</div>
          </div>
        </div>
        <div class="cv-modal-footer">
          <button @click="deleteConfirm.show = false" class="cv-btn cv-btn-ghost">
            {{ isRtl ? 'إلغاء' : 'Cancel' }}
          </button>
          <button @click="executeDelete" class="cv-btn cv-btn-danger">
            {{ isRtl ? 'حذف' : 'Delete' }}
          </button>
        </div>
      </div>
    </div>

  
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
  breeds:    { type: Array, default: () => [] },
  templates: { type: Array, default: () => [] },
})

// ── Locale ────────────────────────────────────────────────────────
const { locale: lang, isRtl, n } = useLocale()

// ── Permissions ───────────────────────────────────────────────────
const { isSuperAdmin, can } = usePermissions()
const canView = computed(() => isSuperAdmin.value || can('view settings'))

// ── Active Tab ────────────────────────────────────────────────────
const activeTab = ref('breeds')

const tabs = computed(() => [
  {
    key:     'breeds',
    labelEn: 'Breed Names',
    labelAr: 'أسماء السلالات',
    count:   localBreeds.value.length,
    icon:    `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                  d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
              </svg>`,
  },
  {
    key:     'templates',
    labelEn: 'Inventory Items',
    labelAr: 'أصناف المخزون',
    count:   localTemplates.value.length,
    icon:    `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"
                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>`,
  },
])

// ── Category definitions ──────────────────────────────────────────
const categories = [
  { value: 'medicine',  labelEn: 'Medicine',   labelAr: 'دواء',       badge: 'cv-badge-red'   },
  { value: 'vaccine',   labelEn: 'Vaccine',    labelAr: 'لقاح',       badge: 'cv-badge-blue'  },
  { value: 'feed',      labelEn: 'Feed',       labelAr: 'علف',        badge: 'cv-badge-green' },
  { value: 'equipment', labelEn: 'Equipment',  labelAr: 'معدات',      badge: 'cv-badge-amber' },
  { value: 'supply',    labelEn: 'Supply',     labelAr: 'مستلزمات',   badge: 'cv-badge-muted' },
  { value: 'other',     labelEn: 'Other',      labelAr: 'أخرى',       badge: 'cv-badge-muted' },
]

const categoryLabel = (value) => {
  const cat = categories.find(c => c.value === value)
  if (!cat) return value
  return isRtl.value ? cat.labelAr : cat.labelEn
}

const categoryBadgeClass = (value) => {
  return categories.find(c => c.value === value)?.badge ?? 'cv-badge-muted'
}

// ══════════════════════════════════════════════════════════════════
//  BREEDS STATE
// ══════════════════════════════════════════════════════════════════
const localBreeds     = ref([...props.breeds])
const addingBreed     = ref(false)
const editingBreedId  = ref(null)
const breedErrors     = ref({})

const breedForm = ref({ name_en: '', name_ar: '' })
const breedEditForm = ref({ name_en: '', name_ar: '' })

const startAddBreed = () => {
  breedForm.value = { name_en: '', name_ar: '' }
  breedErrors.value = {}
  addingBreed.value = true
  editingBreedId.value = null
}

const cancelBreed = () => {
  addingBreed.value = false
  breedErrors.value = {}
}

const submitBreed = () => {
  breedErrors.value = {}
  router.post(route('settings.breeds.store'), {
    name_en: breedForm.value.name_en,
    name_ar: breedForm.value.name_ar,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      addingBreed.value = false
      breedForm.value = { name_en: '', name_ar: '' }
    },
    onError: (errors) => { breedErrors.value = errors },
  })
}

const startEditBreed = (breed) => {
  editingBreedId.value = breed.id
  breedEditForm.value = { name_en: breed.name_en ?? '', name_ar: breed.name_ar ?? '' }
  addingBreed.value = false
}

const cancelEditBreed = () => { editingBreedId.value = null }

const saveBreed = (breed) => {
  router.patch(route('settings.breeds.update', breed.id), {
    name_en: breedEditForm.value.name_en,
    name_ar: breedEditForm.value.name_ar,
  }, {
    preserveScroll: true,
    onSuccess: () => { editingBreedId.value = null },
  })
}

const toggleBreed = (breed) => {
  router.patch(route('settings.breeds.toggle-active', breed.id), {}, {
    preserveScroll: true,
  })
}

// ── Delete confirm ────────────────────────────────────────────────
const deleteConfirm = ref({ show: false, type: null, id: null, message: '' })

const confirmDeleteBreed = (breed) => {
  deleteConfirm.value = {
    show:    true,
    type:    'breed',
    id:      breed.id,
    message: isRtl.value
      ? `هل أنت متأكد من حذف سلالة "${n(breed, 'name')}"؟`
      : `Are you sure you want to delete breed "${n(breed, 'name')}"?`,
  }
}

const confirmDeleteTemplate = (tpl) => {
  deleteConfirm.value = {
    show:    true,
    type:    'template',
    id:      tpl.id,
    message: isRtl.value
      ? `هل أنت متأكد من حذف قالب "${n(tpl, 'name')}"؟`
      : `Are you sure you want to delete template "${n(tpl, 'name')}"?`,
  }
}

const executeDelete = () => {
  const { type, id } = deleteConfirm.value
  deleteConfirm.value.show = false

  if (type === 'breed') {
    router.delete(route('settings.breeds.destroy', id), { preserveScroll: true })
  } else {
    router.delete(route('settings.item-templates.destroy', id), { preserveScroll: true })
  }
}

// ══════════════════════════════════════════════════════════════════
//  INVENTORY ITEM TEMPLATES STATE
// ══════════════════════════════════════════════════════════════════
const localTemplates     = ref([...props.templates])
const addingTemplate     = ref(false)
const editingTemplateId  = ref(null)
const templateErrors     = ref({})

const templateForm = ref({ name_en: '', name_ar: '', category: '' })
const templateEditForm = ref({ name_en: '', name_ar: '', category: '' })

const startAddTemplate = () => {
  templateForm.value = { name_en: '', name_ar: '', category: '' }
  templateErrors.value = {}
  addingTemplate.value = true
  editingTemplateId.value = null
}

const cancelTemplate = () => {
  addingTemplate.value = false
  templateErrors.value = {}
}

const submitTemplate = () => {
  templateErrors.value = {}
  router.post(route('settings.item-templates.store'), {
    name_en:  templateForm.value.name_en,
    name_ar:  templateForm.value.name_ar,
    category: templateForm.value.category,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      addingTemplate.value = false
      templateForm.value = { name_en: '', name_ar: '', category: '' }
    },
    onError: (errors) => { templateErrors.value = errors },
  })
}

const startEditTemplate = (tpl) => {
  editingTemplateId.value = tpl.id
  templateEditForm.value = {
    name_en:  tpl.name_en ?? '',
    name_ar:  tpl.name_ar ?? '',
    category: tpl.category,
  }
  addingTemplate.value = false
}

const cancelEditTemplate = () => { editingTemplateId.value = null }

const saveTemplate = (tpl) => {
  router.patch(route('settings.item-templates.update', tpl.id), {
    name_en:  templateEditForm.value.name_en,
    name_ar:  templateEditForm.value.name_ar,
    category: templateEditForm.value.category,
  }, {
    preserveScroll: true,
    onSuccess: () => { editingTemplateId.value = null },
  })
}

const toggleTemplate = (tpl) => {
  router.patch(route('settings.item-templates.toggle-active', tpl.id), {}, {
    preserveScroll: true,
  })
}
</script>

<style scoped>
/* ── Tab Bar ─────────────────────────────────────────────────────── */
.tab-bar {
  display: flex;
  gap: 0.25rem;
  padding: 0.25rem;
  border-radius: 0.75rem;
  background: rgba(16, 30, 20, 0.8);
  border: 1px solid var(--cv-border-md);
  overflow-x: auto;
}

.tab-btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1.25rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  transition: all 0.15s;
  cursor: pointer;
  border: none;
  background: transparent;
}

.tab-btn-active {
  background: var(--cv-green-deep);
  color: var(--cv-green-bright);
  border: 1px solid var(--cv-green-border);
}

.tab-btn-inactive {
  color: var(--cv-text-muted);
}

.tab-btn-inactive:hover {
  color: var(--cv-text-secondary);
  background: var(--cv-bg-hover);
}

.tab-icon {
  display: flex;
  align-items: center;
}

.tab-count {
  font-size: 0.7rem;
  padding: 0.1rem 0.45rem;
  border-radius: 9999px;
  background: var(--cv-green-dim);
  color: var(--cv-green-bright);
  border: 1px solid var(--cv-green-border);
  font-weight: 600;
  min-width: 1.5rem;
  text-align: center;
}

/* ── Section header ──────────────────────────────────────────────── */
.section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.section-title {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--cv-text-primary);
}

.section-sub {
  font-size: 0.8125rem;
  color: var(--cv-text-muted);
  margin-top: 0.25rem;
}

/* ── Inline add form ─────────────────────────────────────────────── */
.inline-form-card {
  border: 1px solid var(--cv-amber-border) !important;
}

.inline-form-title {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--cv-amber-text);
  margin-bottom: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.inline-form-row {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
}

.inline-form-actions {
  display: flex;
  gap: 0.5rem;
  padding-bottom: 0.1rem;
}

/* ── Settings table ──────────────────────────────────────────────── */
.settings-table {
  width: 100%;
  font-size: 0.875rem;
  border-collapse: collapse;
}

.settings-table thead tr {
  border-bottom: 1px solid var(--cv-border-md);
}

.settings-table thead th {
  padding: 0 0 0.625rem;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--cv-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.settings-table tbody tr {
  border-bottom: 1px solid rgba(36, 69, 53, 0.35);
  transition: background 0.1s;
}

.settings-table tbody tr:last-child {
  border-bottom: none;
}

.settings-table tbody tr:hover {
  background: var(--cv-bg-hover);
}

.settings-table td {
  padding: 0.625rem 0.5rem 0.625rem 0;
  color: var(--cv-text-secondary);
  vertical-align: middle;
}

/* ── Row display ─────────────────────────────────────────────────── */
.item-name {
  font-weight: 500;
  color: var(--cv-text-primary);
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.item-name-alt {
  font-size: 0.75rem;
  color: var(--cv-text-muted);
  font-weight: 400;
}

.inline-edit-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* ── Row actions ─────────────────────────────────────────────────── */
.row-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.375rem;
}

.action-icon-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.875rem;
  height: 1.875rem;
  border-radius: 0.375rem;
  background: transparent;
  color: var(--cv-text-muted);
  border: 1px solid transparent;
  cursor: pointer;
  transition: all 0.15s;
}

.action-icon-btn:hover {
  background: var(--cv-bg-hover);
  color: var(--cv-text-secondary);
  border-color: var(--cv-border-md);
}

.action-icon-danger:hover {
  background: var(--cv-danger-bg);
  color: var(--cv-danger-text);
  border-color: var(--cv-danger-border);
}

/* ── Empty state ─────────────────────────────────────────────────── */
.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--cv-text-muted);
  font-size: 0.875rem;
}

.empty-icon {
  width: 2.5rem;
  height: 2.5rem;
  margin: 0 auto 0.75rem;
  color: var(--cv-text-muted);
  opacity: 0.5;
}

/* ── Delete modal icon ───────────────────────────────────────────── */
.delete-modal-icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  background: var(--cv-danger-bg);
  border: 1px solid var(--cv-danger-border);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--cv-danger-text);
  flex-shrink: 0;
}

/* ── Button size helpers ─────────────────────────────────────────── */
.btn-sm { padding: 0.375rem 0.875rem; font-size: 0.875rem; }
.btn-xs { padding: 0.25rem 0.625rem;  font-size: 0.8125rem; }

/* ── Input size helper ───────────────────────────────────────────── */
.input-sm { padding: 0.375rem 0.625rem; font-size: 0.875rem; }

/* ── Error text ──────────────────────────────────────────────────── */
.cv-error-text {
  font-size: 0.75rem;
  color: var(--cv-danger-text);
}
</style>