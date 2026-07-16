<script setup>
/**
 * Inventory/Locations/CreateEdit.vue
 * Bilingual form for creating/editing a storage location.
 * farm_id = null → company-level central location.
 */
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    mode:     { type: String, default: 'create' },
    location: { type: Object, default: null },
    farms:    Array,
})

const isEdit = computed(() => props.mode === 'edit')

const form = useForm({
    farm_id:   props.location?.farm_id   ?? '',
    name_en:   props.location?.name_en   ?? '',
    name_ar:   props.location?.name_ar   ?? '',
    type:      props.location?.type      ?? 'warehouse',
    is_active: props.location?.is_active ?? true,
    notes:     props.location?.notes     ?? '',
})

function submit() {
    if (isEdit.value) {
        form.patch(route('inventory.locations.update', props.location.id), {
            onSuccess: () => {},
        })
    } else {
        form.post(route('inventory.locations.store'))
    }
}

const t = computed(() => lang.value === 'ar' ? {
    eyebrow:     'المخزون',
    createTitle: 'موقع تخزين جديد',
    editTitle:   'تعديل موقع التخزين',
    s1: 'تفاصيل الموقع',
    s2: 'الارتباط بالمزرعة',
    s3: 'الحالة والملاحظات',
    nameEn:      'الاسم بالعربية',
    nameAr:      'الاسم بالعربية',
    type:        'نوع الموقع',
    farm:        'المزرعة',
    farmHint:    'اتركه فارغاً لموقع مركزي على مستوى الشركة',
    isActive:    'نشط',
    notes:       'ملاحظات',
    save:        'حفظ',
    cancel:      'إلغاء',
    central:     'مركزي (بدون مزرعة)',
    types: {
        warehouse:    'مستودع',
        store_room:   'غرفة تخزين',
        cold_storage: 'تخزين بارد',
        cabinet:      'خزانة',
        shed:         'سقيفة',
        other:        'أخرى',
    },
} : {
    eyebrow:     'Inventory',
    createTitle: 'New Storage Location',
    editTitle:   'Edit Storage Location',
    s1: 'Location Details',
    s2: 'Farm Assignment',
    s3: 'Status & Notes',
    nameEn:      'Name (English)',
    nameAr:      'Name (Arabic)',
    type:        'Location Type',
    farm:        'Farm',
    farmHint:    'Leave empty for a company-level central location',
    isActive:    'Active',
    notes:       'Notes',
    save:        'Save',
    cancel:      'Cancel',
    central:     'Central (no farm)',
    types: {
        warehouse:    'Warehouse',
        store_room:   'Store Room',
        cold_storage: 'Cold Storage',
        cabinet:      'Cabinet',
        shed:         'Shed',
        other:        'Other',
    },
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ isEdit ? t.editTitle : t.createTitle }}</div>
            </div>

            <div class="cv-page">
                <div v-if="Object.keys(form.errors).length" class="cv-alert cv-alert-danger mb-4">
                    <ul class="list-disc ps-4 text-sm space-y-1">
                        <li v-for="(e,f) in form.errors" :key="f">{{ e }}</li>
                    </ul>
                </div>

                <form @submit.prevent="submit" class="space-y-4" style="max-width:640px">

                    <!-- Section 1 — Location Details -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">1</div>
                        <h3 class="cv-section-title">{{ t.s1 }}</h3>
                        <div class="cv-form-grid">

                            <!-- Name — bilingual §5 -->
                            <div class="cv-span-2">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? t.nameAr : t.nameEn }}
                                    <span class="cv-required">*</span>
                                </label>
                                <!-- AR mode: show name_ar, hide name_en -->
                                <input
                                    v-if="lang === 'ar'"
                                    v-model="form.name_ar"
                                    type="text"
                                    class="cv-input"
                                    :class="{'cv-input-error': form.errors.name_ar || form.errors.name_en}"
                                    :placeholder="lang === 'ar' ? 'مثال: مستودع الأدوية' : ''"
                                    dir="rtl"
                                />
                                <input
                                    v-else
                                    v-model="form.name_en"
                                    type="text"
                                    class="cv-input"
                                    :class="{'cv-input-error': form.errors.name_en}"
                                    placeholder="e.g. Medicine Store Room"
                                />
                                <!-- Hidden counterpart — always submitted -->
                                <input v-show="false" v-model="form.name_en" type="text" v-if="lang === 'ar'"/>
                                <input v-show="false" v-model="form.name_ar" type="text" v-if="lang !== 'ar'"/>
                                <p v-if="form.errors.name_en || form.errors.name_ar" class="cv-error">
                                    {{ form.errors.name_en || form.errors.name_ar }}
                                </p>
                            </div>

                            <!-- Type -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.type }} <span class="cv-required">*</span></label>
                                <select v-model="form.type" class="cv-select">
                                    <option v-for="(label, val) in t.types" :key="val" :value="val">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>

                        </div>
                    </div>

                    <!-- Section 2 — Farm Assignment -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">2</div>
                        <h3 class="cv-section-title">{{ t.s2 }}</h3>

                        <label class="cv-label">{{ t.farm }}</label>
                        <select v-model="form.farm_id" class="cv-select">
                            <option value="">{{ t.central }}</option>
                            <option v-for="f in farms" :key="f.id" :value="f.id">
                                {{ n(f,'name') }}
                            </option>
                        </select>
                        <p class="cv-hint mt-1">{{ t.farmHint }}</p>
                    </div>

                    <!-- Section 3 — Status & Notes -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">3</div>
                        <h3 class="cv-section-title">{{ t.s3 }}</h3>

                        <div class="flex items-center gap-3 mb-4">
                            <div
                                class="cv-toggle"
                                :class="{'cv-toggle-on': form.is_active}"
                                @click="form.is_active = !form.is_active"
                            ><span class="cv-toggle-knob"/></div>
                            <span class="cv-label mb-0">{{ t.isActive }}</span>
                        </div>

                        <label class="cv-label">{{ t.notes }}</label>
                        <textarea v-model="form.notes" class="cv-textarea" rows="2"/>
                    </div>

                    <div class="cv-form-actions">
                        <Link :href="route('inventory.locations.index')" class="cv-btn cv-btn-ghost">{{ t.cancel }}</Link>
                        <button type="submit" class="cv-btn cv-btn-amber" :disabled="form.processing">{{ t.save }}</button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.cv-section-card { position:relative; padding:1.25rem 1.25rem 1.25rem 3.25rem; }
.cv-section-num { position:absolute; inset-inline-start:-14px; top:1rem; width:30px; height:30px; border-radius:50%; background:linear-gradient(135deg,var(--cv-amber),#d97706); color:#000; font-weight:700; font-size:0.8rem; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(245,158,11,0.4); }
.cv-section-title { font-size:0.9rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:1rem; padding-bottom:0.4rem; border-bottom:1px solid var(--cv-border-muted); }
.cv-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.9rem; }
.cv-span-2 { grid-column:span 2; }
.cv-label { display:block; font-size:0.78rem; font-weight:500; color:var(--cv-text-secondary); margin-bottom:0.25rem; }
.cv-required { color:var(--cv-danger-text); }
.cv-error { font-size:0.73rem; color:var(--cv-danger-text); margin-top:0.2rem; }
.cv-hint  { font-size:0.72rem; color:var(--cv-text-muted); }
.cv-form-actions { display:flex; justify-content:flex-end; gap:0.75rem; }
.cv-toggle { width:38px; height:20px; border-radius:999px; border:1px solid var(--cv-border); background:var(--cv-bg-input); position:relative; cursor:pointer; transition:background 0.2s; }
.cv-toggle-on { background:var(--cv-green-bright); border-color:var(--cv-green-bright); }
.cv-toggle-knob { position:absolute; top:2px; inset-inline-start:2px; width:14px; height:14px; background:#fff; border-radius:50%; transition:inset-inline-start 0.2s; }
.cv-toggle-on .cv-toggle-knob { inset-inline-start:calc(100% - 16px); }
@media(max-width:600px) { .cv-form-grid { grid-template-columns:1fr; } .cv-span-2 { grid-column:span 1; } }
</style>