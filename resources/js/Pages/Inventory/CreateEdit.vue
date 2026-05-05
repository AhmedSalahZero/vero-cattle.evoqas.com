<script setup>
/**
 * Inventory/CreateEdit.vue
 * Creates/edits a master inventory item at company level.
 * No farm selector — system fans out to all locations automatically.
 * Feed item link appears only when category = 'feed'.
 */
import { ref, computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    mode:      { type: String, default: 'create' },
    item:      { type: Object, default: null },
    feedItems: Array,
    companies: { type: Array, default: () => [] },  // super admin only
})

const isEdit       = computed(() => props.mode === 'edit')
const { isSuperAdmin, can } = usePermissions()

const form = useForm({
    company_id:    props.item?.company_id    ?? '',  // super admin only
    name_en:       props.item?.name_en       ?? '',
    name_ar:       props.item?.name_ar       ?? '',
    sku:           props.item?.sku           ?? '',
    category:      props.item?.category      ?? 'medicine',
    unit:          props.item?.unit          ?? '',
    unit_cost:     props.item?.unit_cost     ?? '',
    reorder_point: props.item?.reorder_point ?? '',
    feed_item_id:  props.item?.feed_item_id  ?? '',
    is_active:     props.item?.is_active     ?? true,
    notes:         props.item?.notes         ?? '',
})

// Unit presets per category
const unitPresets = computed(() => {
    const p = {
        medicine:  ['ml','mg','vial','tablet','g','litre'],
        vaccine:   ['ml','vial','dose'],
        feed:      ['kg','ton','bale','bag','litre'],
        equipment: ['unit','set','piece'],
        supply:    ['unit','box','roll','bag','kg'],
        other:     ['unit','kg','litre'],
    }
    return p[form.category] ?? p.other
})

// Reset unit when category changes (only on create)
watch(() => form.category, () => {
    if (!isEdit.value) {
        form.unit = unitPresets.value[0] ?? ''
        if (form.category !== 'feed') form.feed_item_id = ''
    }
})

function submit() {
    if (isEdit.value) {
        form.put(route('inventory.update', props.item.id))
    } else {
        form.post(route('inventory.store'))
    }
}

const categoryOptions = computed(() => lang.value === 'ar' ? [
    {val:'medicine',  label:'دواء'},
    {val:'vaccine',   label:'لقاح'},
    {val:'feed',      label:'علف'},
    {val:'equipment', label:'معدات'},
    {val:'supply',    label:'مستلزمات'},
    {val:'other',     label:'أخرى'},
] : [
    {val:'medicine',  label:'Medicine'},
    {val:'vaccine',   label:'Vaccine'},
    {val:'feed',      label:'Feed'},
    {val:'equipment', label:'Equipment'},
    {val:'supply',    label:'Supply'},
    {val:'other',     label:'Other'},
])

const t = computed(() => lang.value === 'ar' ? {
    eyebrow:'المخزون', createTitle:'صنف مخزون جديد', editTitle:'تعديل الصنف',
    createSub:'يُنشأ الصنف تلقائياً في كل مواقع التخزين برصيد صفر',
    editSub:'تعديل بيانات الصنف — يسري على كل المواقع',
    s1:'هوية الصنف', s2:'الوحدة والتكلفة', s3:'الربط والملاحظات',
    nameEn:'الاسم بالعربية', nameAr:'الاسم بالعربية',
    sku:'رمز الصنف (SKU)', category:'الفئة',
    unit:'وحدة القياس', unitPreset:'اختر سريع:',
    unitCost:'تكلفة الوحدة (EGP)', reorderPoint:'نقطة إعادة الطلب',
    feedItem:'ربط بصنف العلف', feedHint:'يظهر فقط عند اختيار فئة علف',
    isActive:'نشط', notes:'ملاحظات',
    save:'حفظ', cancel:'إلغاء',
    fanOutNote:'سيُنشأ هذا الصنف في جميع مواقع التخزين المسجّلة برصيد صفر تلقائياً.',
} : {
    eyebrow:'Inventory', createTitle:'New Inventory Item', editTitle:'Edit Inventory Item',
    createSub:'Item is auto-created at all storage locations with zero stock',
    editSub:'Edit item definition — applies across all locations',
    s1:'Item Identity', s2:'Unit & Cost', s3:'Links & Notes',
    nameEn:'Name (English)', nameAr:'Name (Arabic)',
    sku:'SKU / Item Code', category:'Category',
    unit:'Unit of Measure', unitPreset:'Quick select:',
    unitCost:'Unit Cost (EGP)', reorderPoint:'Reorder Point',
    feedItem:'Link to Feed Item', feedHint:'Only for Feed category',
    isActive:'Active', notes:'Notes',
    save:'Save', cancel:'Cancel',
    fanOutNote:'This item will be automatically created at all registered storage locations with zero stock.',
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ isEdit ? t.editTitle : t.createTitle }}</div>
                <div class="cv-page-hero-sub">{{ isEdit ? t.editSub : t.createSub }}</div>
            </div>

            <div class="cv-page">

                <div v-if="Object.keys(form.errors).length" class="cv-alert cv-alert-danger mb-4">
                    <ul class="list-disc ps-4 text-sm space-y-1">
                        <li v-for="(e,f) in form.errors" :key="f">{{ e }}</li>
                    </ul>
                </div>

                <!-- Fan-out info box (create mode, non-super-admin only) -->
                <div v-if="!isEdit && !isSuperAdmin" class="cv-info-box mb-4">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    {{ t.fanOutNote }}
                </div>

                <form @submit.prevent="submit" class="space-y-4" style="max-width:680px">

                <!-- Section 0 — Company (super admin only) -->
                    <div v-if="isSuperAdmin" class="cv-card cv-section-card">
                        <div class="cv-section-num" style="background:linear-gradient(135deg,var(--cv-blue-text),#1d4ed8)">0</div>
                        <h3 class="cv-section-title">
                            {{ lang === 'ar' ? 'الشركة' : 'Company' }}
                        </h3>
                        <label class="cv-label">
                            {{ lang === 'ar' ? 'اختر الشركة' : 'Select Company' }}
                            <span class="cv-required">*</span>
                        </label>
                        <select
                            v-model="form.company_id"
                            class="cv-select"
                            :class="{'cv-input-error': form.errors.company_id}"
                        >
                            <option value="">{{ lang === 'ar' ? '— اختر الشركة —' : '— Select company —' }}</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">
                                {{ lang === 'ar' ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar) }}
                            </option>
                        </select>
                        <p v-if="form.errors.company_id" class="cv-error">{{ form.errors.company_id }}</p>
                        <p class="cv-hint mt-1">
                            {{ lang === 'ar'
                                ? 'سيُنشأ الصنف تلقائياً في كل مواقع تخزين هذه الشركة'
                                : 'Item will be auto-created at all storage locations for this company' }}
                        </p>
                    </div>

                    <!-- Section 1 — Identity -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">1</div>
                        <h3 class="cv-section-title">{{ t.s1 }}</h3>
                        <div class="cv-form-grid">

                            <!-- Bilingual name §5 -->
                            <div class="cv-span-2">
                                <label class="cv-label">
                                    {{ lang === 'ar' ? t.nameAr : t.nameEn }}
                                    <span class="cv-required">*</span>
                                </label>
                                <input v-if="lang==='ar'" v-model="form.name_ar" type="text" class="cv-input"
                                    :class="{'cv-input-error': form.errors.name_ar || form.errors.name_en}"
                                    :placeholder="lang==='ar'?'مثال: أوكسي تيتراسايكلين':''"  dir="rtl"/>
                                <input v-else v-model="form.name_en" type="text" class="cv-input"
                                    :class="{'cv-input-error': form.errors.name_en}"
                                    placeholder="e.g. Oxytetracycline"/>
                                <!-- Hidden counterpart always submitted -->
                                <input v-show="false" v-model="form.name_en" type="text" v-if="lang==='ar'"/>
                                <input v-show="false" v-model="form.name_ar" type="text" v-if="lang!=='ar'"/>
                                <p v-if="form.errors.name_en||form.errors.name_ar" class="cv-error">{{ form.errors.name_en||form.errors.name_ar }}</p>
                            </div>

                            <!-- SKU -->
                            <div>
                                <label class="cv-label">{{ t.sku }}</label>
                                <input v-model="form.sku" type="text" class="cv-input" placeholder="MED-001"/>
                            </div>

                            <!-- Category -->
                            <div>
                                <label class="cv-label">{{ t.category }} <span class="cv-required">*</span></label>
                                <select v-model="form.category" class="cv-select" :class="{'cv-input-error': form.errors.category}">
                                    <option v-for="c in categoryOptions" :key="c.val" :value="c.val">{{ c.label }}</option>
                                </select>
                            </div>

                        </div>
                    </div>

                    <!-- Section 2 — Unit & Cost -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">2</div>
                        <h3 class="cv-section-title">{{ t.s2 }}</h3>
                        <div class="cv-form-grid">

                            <!-- Unit with quick presets -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.unit }} <span class="cv-required">*</span></label>
                                <input v-model="form.unit" type="text" class="cv-input"
                                    :class="{'cv-input-error': form.errors.unit}"
                                    :placeholder="lang==='ar'?'مثال: مل، كجم':'e.g. ml, kg'"/>
                                <!-- Quick preset chips -->
                                <div class="unit-presets mt-2">
                                    <span class="preset-label">{{ t.unitPreset }}</span>
                                    <button v-for="u in unitPresets" :key="u" type="button"
                                        class="preset-chip" :class="{'preset-active': form.unit===u}"
                                        @click="form.unit = u">{{ u }}</button>
                                </div>
                                <p v-if="form.errors.unit" class="cv-error">{{ form.errors.unit }}</p>
                            </div>

                            <!-- Unit cost -->
                            <div>
                                <label class="cv-label">{{ t.unitCost }}</label>
                                <input v-model="form.unit_cost" type="number" step="0.0001" min="0" class="cv-input"
                                    :placeholder="lang==='ar'?'0.00':'0.00'"/>
                            </div>

                            <!-- Reorder point -->
                            <div>
                                <label class="cv-label">{{ t.reorderPoint }}</label>
                                <input v-model="form.reorder_point" type="number" step="0.001" min="0" class="cv-input"
                                    :placeholder="lang==='ar'?'مثال: 50':'e.g. 50'"/>
                            </div>

                        </div>
                    </div>

                    <!-- Section 3 — Links & Notes -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">3</div>
                        <h3 class="cv-section-title">{{ t.s3 }}</h3>

                        <!-- Feed item link (feed category only) -->
                        <!-- <div v-if="form.category === 'feed'" class="mb-4">
                            <label class="cv-label">{{ t.feedItem }}</label>
                            <select v-model="form.feed_item_id" class="cv-select">
                                <option value="">—</option>
                                <option v-for="fi in feedItems" :key="fi.id" :value="fi.id">
                                    {{ lang==='ar'?(fi.name_ar||fi.name_en):(fi.name_en||fi.name_ar) }}
                                </option>
                            </select>
                            <p class="cv-hint">{{ t.feedHint }}</p>
                        </div> -->

                        <!-- Active toggle -->
                        <div class="flex items-center gap-3 mb-4">
                            <div class="cv-toggle" :class="{'cv-toggle-on': form.is_active}" @click="form.is_active = !form.is_active">
                                <span class="cv-toggle-knob"/>
                            </div>
                            <span class="cv-label mb-0">{{ t.isActive }}</span>
                        </div>

                        <label class="cv-label">{{ t.notes }}</label>
                        <textarea v-model="form.notes" class="cv-textarea" rows="2"/>
                    </div>

                    <div class="cv-form-actions">
                        <Link :href="route('inventory.index')" class="cv-btn cv-btn-ghost">{{ t.cancel }}</Link>
                        <button type="submit" class="cv-btn cv-btn-amber" :disabled="form.processing">
                            <svg v-if="form.processing" class="cv-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                            {{ t.save }}
                        </button>
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
.cv-hint { font-size:0.72rem; color:var(--cv-text-muted); margin-top:0.2rem; }
.cv-form-actions { display:flex; justify-content:flex-end; gap:0.75rem; }
.cv-toggle { width:36px; height:20px; border-radius:999px; border:1px solid var(--cv-border); background:var(--cv-bg-input); position:relative; cursor:pointer; transition:background 0.2s; }
.cv-toggle-on { background:var(--cv-green-bright); border-color:var(--cv-green-bright); }
.cv-toggle-knob { position:absolute; top:2px; inset-inline-start:2px; width:14px; height:14px; background:#fff; border-radius:50%; transition:inset-inline-start 0.2s; }
.cv-toggle-on .cv-toggle-knob { inset-inline-start:calc(100% - 16px); }
.unit-presets { display:flex; flex-wrap:wrap; align-items:center; gap:0.4rem; }
.preset-label { font-size:0.72rem; color:var(--cv-text-muted); }
.preset-chip { padding:0.15rem 0.55rem; border-radius:999px; font-size:0.72rem; border:1px solid var(--cv-border); background:transparent; color:var(--cv-text-secondary); cursor:pointer; transition:all 0.15s; }
.preset-chip:hover { border-color:var(--cv-amber); color:var(--cv-amber); }
.preset-active { background:var(--cv-amber); color:#000; border-color:var(--cv-amber); font-weight:600; }
.cv-info-box { display:flex; align-items:center; gap:0.5rem; padding:0.6rem 0.9rem; border-radius:8px; background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); color:var(--cv-blue-text); font-size:0.82rem; }
.cv-spin { animation:spin 0.8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
@media(max-width:600px) { .cv-form-grid { grid-template-columns:1fr; } .cv-span-2 { grid-column:span 1; } }
</style>