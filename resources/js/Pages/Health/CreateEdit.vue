<script setup>
/**
 * Health/CreateEdit.vue
 * ─────────────────────────────────────────────────────────────────
 * VERO Cattle Management — Health Record Create / Edit
 *
 * Mode prop: 'create' | 'edit' — one component, both operations.
 *
 * Key behaviours:
 *  1. Cascading selects: Farm → Lot → Animal (client-side filter)
 *  2. Medicine from inventory only — hard blocked, no free text
 *  3. Dose unit auto-fills from selected inventory item
 *  4. Cost auto-calculates: unit_cost × dose (overridable)
 *  5. Withdrawal date auto-suggests date + 14 days for treatment
 *  6. Quick Repeat after save — pre-fills next form
 *  7. Fully bilingual — all text Arabic when locale = 'ar'
 *
 * 📌 METHODOLOGY REFERENCE: HealthRecordMedicineDecrement
 * ─────────────────────────────────────────────────────────────────
 */
import { ref, computed, watch } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'

const { locale: lang, isRtl, n ,today} = useLocale()

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    mode:        { type: String, default: 'create' },  // 'create' | 'edit'
    record:      { type: Object, default: null },
    farms:       Array,
    lots:        Array,
    animals:     Array,   // flat list with lot_id, filtered client-side
    medicines:   Array,   // inventory items category=medicine
    vets:        Array,
    preAnimalId: { type: Number, default: null },
})

const isEdit = computed(() => props.mode === 'edit')

// ── Form state ────────────────────────────────────────────────────
const form = useForm({
    farm_id:              props.record?.farm_id               ?? '',
    lot_id:               props.record?.lot_id                ?? '',
    animal_id:            props.record?.animal_id             ?? props.preAnimalId ?? '',
    inventory_item_id:    props.record?.inventory_item_id     ?? '',
    type:                 props.record?.type                  ?? 'treatment',
    date:                 props.record?.date                  ?? localToday(),
    condition:            props.record?.condition             ?? '',
    dose:                 props.record?.dose                  ?? '',
    dose_unit:            props.record?.dose_unit             ?? '',
    route:                props.record?.route                 ?? '',
    cost:                 props.record?.cost                  ?? '',
    currency:             props.record?.currency              ?? 'EGP',
    vet_id:               props.record?.vet_id                ?? '',
    vet_name:             props.record?.vet_name              ?? '',
    outcome:              props.record?.outcome               ?? '',
    withdrawal_clear_date: props.record?.withdrawal_clear_date ?? '',
    is_quarantine:        props.record?.is_quarantine         ?? false,
    location_id:          props.record?.location_id           ?? '',
    notes:                props.record?.notes                 ?? '',
})

// ── Cascading selects ─────────────────────────────────────────────
// When farm changes → reset lot and animal
watch(() => form.farm_id, () => {
    form.lot_id    = ''
    form.animal_id = ''
})

// When lot changes → reset animal
watch(() => form.lot_id, () => {
    if (!isEdit.value) form.animal_id = ''
})

// Lots filtered by selected farm
const filteredLots = computed(() =>
    form.farm_id
        ? props.lots.filter(l => String(l.farm_id) === String(form.farm_id))
        : props.lots
)

// Animals filtered by selected lot (or farm if no lot)
const filteredAnimals = computed(() => {
    if (!props.animals) return []
    if (form.lot_id) {
        return props.animals.filter(a => String(a.lot_id) === String(form.lot_id))
    }
    if (form.farm_id) {
        return props.animals.filter(a => String(a.farm_id) === String(form.farm_id))
    }
    return props.animals
})

// Pre-select farm/lot when preAnimalId is set
if (props.preAnimalId && props.animals) {
    const pre = props.animals.find(a => a.id === props.preAnimalId)
    if (pre) {
        form.farm_id  = pre.farm_id ?? ''
        form.lot_id   = pre.lot_id  ?? ''
        form.animal_id = pre.id
    }
}

// ── Medicine selection ────────────────────────────────────────────
// Medicines filtered by selected farm (medicine inventory is farm-scoped)
const filteredMedicines = computed(() =>
    form.farm_id
        ? props.medicines.filter(m => String(m.farm_id) === String(form.farm_id))
        : props.medicines
)

const selectedMedicine = computed(() =>
    props.medicines.find(m => m.id === Number(form.inventory_item_id)) ?? null
)

// When medicine changes → auto-fill dose_unit and recalculate cost
watch(() => form.inventory_item_id, () => {
    const med = selectedMedicine.value
    if (med) {
        form.dose_unit = med.unit
        recalcCost()
    } else {
        form.dose_unit = ''
    }
})

// ── Location selection (cascades from selected medicine) ─────────
// 📌 METHODOLOGY REFERENCE: LivestockOperationalCostFlow (#43) §6
// Each medicine in props.medicines carries a location_stocks array
// [{location_id, location_name_en, location_name_ar, qty_on_hand, is_central, farm_name_en, farm_name_ar}]
// Only locations with qty_on_hand > 0 are offered.
// If exactly one location has stock → auto-select it.
const medicineLocations = computed(() => {
    const med = selectedMedicine.value
    if (!med || !med.location_stocks) return []
    return med.location_stocks.filter(l => l.qty_on_hand > 0)
})

// When medicine changes → reset location (then auto-select if only one)
watch(() => form.inventory_item_id, () => {
    form.location_id = ''
    // auto-select if only one location has stock
    const locs = medicineLocations.value
    if (locs.length === 1) form.location_id = locs[0].location_id
}, { flush: 'post' })

const selectedLocation = computed(() =>
    medicineLocations.value.find(l => l.location_id == form.location_id) ?? null
)

function locLabel(l) {
    if (!l) return '—'
    const name = lang.value === 'ar' ? (l.location_name_ar || l.location_name_en) : (l.location_name_en || l.location_name_ar)
    const farm = lang.value === 'ar' ? (l.farm_name_ar || l.farm_name_en) : (l.farm_name_en || l.farm_name_ar)
    return l.is_central ? `🏢 ${name}` : `🏠 ${farm ? farm + ' — ' : ''}${name}`
}

// When dose changes → recalculate cost
watch(() => form.dose, () => recalcCost())

// Cost auto-calculation: unit_cost × dose
// User can override — we track if cost was manually touched
// QA-021: the server ALWAYS calculates the cost (stock average cost x dose) and ignores a typed cost,
// so the box is read-only and always follows the dose.
const costManuallySet = ref(false)

function recalcCost() {
    if (costManuallySet.value) return
    const med = selectedMedicine.value
    if (med && form.dose > 0) {
        form.cost = (Number(med.unit_cost) * Number(form.dose)).toFixed(2)
    }
}

function onCostInput() {
    costManuallySet.value = true
}

function resetCostOverride() {
    costManuallySet.value = false
    recalcCost()
}

// ── Withdrawal date auto-suggest ──────────────────────────────────
// When type = treatment and date changes → suggest date + 14 days
// QA-022: the suggestion (treatment date + 14 days) now FOLLOWS the treatment date until the user
// types a withdrawal date of their own; before it stayed on the first date it was calculated for.
const withdrawalTouched = ref(isEdit.value)
function onWithdrawalInput() { withdrawalTouched.value = true }
watch([() => form.type, () => form.date], () => {
    if (form.type === 'treatment' && form.date && !isEdit.value && !withdrawalTouched.value) {
        const [y, m, dd] = String(form.date).slice(0, 10).split('-').map(Number)
        const d = new Date(y, m - 1, dd + 14)
        form.withdrawal_clear_date = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
    }
    if (form.type !== 'treatment') {
        // Non-treatment: clear withdrawal unless explicitly set in edit
        if (!isEdit.value) form.withdrawal_clear_date = ''
    }
})

// ── Stock display ─────────────────────────────────────────────────
const stockStatus = computed(() => {
    const med = selectedMedicine.value
    if (!med) return null
    const qty  = Number(med.qty_on_hand)
    const dose = Number(form.dose)
    if (qty <= 0)       return 'empty'
    if (dose > qty)     return 'insufficient'
    if (qty < dose * 3) return 'low'
    return 'ok'
})

// ── Live preview ──────────────────────────────────────────────────
const previewAnimal = computed(() =>
    props.animals?.find(a => a.id === Number(form.animal_id)) ?? null
)
const previewMed = computed(() => selectedMedicine.value)

// ── Vet selection mode ────────────────────────────────────────────
// Either pick from system users OR enter free-text vet name
const vetMode = ref(props.record?.vet_id ? 'select' : 'text')
watch(vetMode, (m) => {
    if (m === 'select') form.vet_name = ''
    else                form.vet_id   = ''
})

// ── Submit ────────────────────────────────────────────────────────
const quickRepeat = ref(false)

function submit() {
    if (isEdit.value) {
        form.put(route('health.update', props.record.id), {
            onSuccess: () => router.visit(route('health.index')),
        })
    } else {
        form.post(route('health.store'), {
            onSuccess: () => {
                if (quickRepeat.value) {
                    // Pre-fill next form with same farm/lot/type/date/medicine/vet
                    router.visit(route('health.create'), {
                        data: {
                            _quick_repeat: 1,
                            farm_id:           form.farm_id,
                            lot_id:            form.lot_id,
                            type:              form.type,
                            date:              form.date,
                            inventory_item_id: form.inventory_item_id,
                            vet_id:            form.vet_id,
                            vet_name:          form.vet_name,
                        },
                    })
                } else {
                    router.visit(route('health.index'))
                }
            },
        })
    }
}

// ── i18n ──────────────────────────────────────────────────────────
const t = computed(() => lang.value === 'ar' ? {
    eyebrow:       'صحة القطيع',
    createTitle:   'سجل صحي جديد',
    editTitle:     'تعديل السجل الصحي',
    createSub:     'تسجيل علاج أو زيارة بيطرية أو جراحة',
    editSub:       'تعديل بيانات السجل الصحي',
    // Section titles
    s1: 'الماشية والموقع',
    s2: 'تفاصيل الحدث',
    s3: 'تفاصيل العلاج',
    s4: 'الطبيب البيطري والتكلفة',
    s5: 'النتيجة والتنبيهات',
    s6: 'ملاحظات',
    // Fields
    farm:          'المزرعة',
    lot:           'المجموعة',
    animal:        'الماشية',
    type:          'نوع السجل',
    date:          'التاريخ',
    condition:     'الحالة / التشخيص',
    medicine:      'الدواء',
    location:      'موقع المخزون',
    selLocation:   'اختر الموقع',
    locationAvail: 'متاح في هذا الموقع:',
    dose:          'الجرعة',
    doseUnit:      'وحدة الجرعة',
    route:         'طريقة الإعطاء',
    cost:          'التكلفة',
    currency:      'العملة',
    vetSource:     'طريقة تحديد الطبيب',
    vetSelect:     'اختر من النظام',
    vetFreeText:   'اكتب الاسم يدوياً',
    vetUser:       'الطبيب البيطري',
    vetName:       'اسم الطبيب',
    outcome:       'النتيجة',
    withdrawal:    'تاريخ انتهاء فترة السحب',
    quarantine:    'تحت الحجر الصحي',
    notes:         'ملاحظات',
    // Placeholders
    selFarm:       'اختر المزرعة',
    selLot:        'اختر المجموعة (اختياري)',
    selAnimal:     'اختر الماشية',
    selMedicine:   'اختر الدواء من المخزون',
    selVet:        'اختر الطبيب',
    selOutcome:    'اختر النتيجة',
    condPlh:       'مثال: التهاب رئوي، عرج، مغص...',
    routePlh:      'مثال: عن طريق الفم، حقن عضلي...',
    vetNamePlh:    'اسم الطبيب البيطري',
    notesPlh:      'أي تفاصيل إضافية...',
    // Types
    treatment:     'علاج',
    vet_visit:     'زيارة بيطرية',
    surgery:       'جراحة',
    other:         'أخرى',
    // Outcomes
    recovered:     'تعافى',
    ongoing:       'جاري العلاج',
    died:          'نفق',
    slaughtered:   'ذُبح',
    unknown:       'غير معروف',
    // Stock
    stockOk:       'المخزون متاح',
    stockLow:      'تحذير: مخزون منخفض',
    stockInsuff:   'خطأ: المخزون غير كافٍ',
    stockEmpty:    'خطأ: الدواء غير متوفر في المخزون',
    stockUnit:     'متاح:',
    autoCost:      'حُسبت تلقائياً',
    resetCost:     'إعادة حساب',
    // Buttons
    save:          'حفظ',
    saveRepeat:    'حفظ وإضافة أخرى',
    cancel:        'إلغاء',
    // Preview
    previewTitle:  'معاينة السجل',
    noAnimal:      'لم يُختر ماشية',
    noMed:         'لم يُختر دواء',
} : {
    eyebrow:       'Herd Health',
    createTitle:   'New Health Record',
    editTitle:     'Edit Health Record',
    createSub:     'Record a treatment, vet visit, or surgery',
    editSub:       'Update health record details',
    s1: 'Animal & Location',
    s2: 'Event Details',
    s3: 'Treatment Details',
    s4: 'Veterinarian & Cost',
    s5: 'Outcome & Flags',
    s6: 'Notes',
    farm:          'Farm',
    lot:           'Lot',
    animal:        'Animal',
    type:          'Record Type',
    date:          'Date',
    condition:     'Condition / Diagnosis',
    medicine:      'Medicine',
    location:      'Stock Location',
    selLocation:   'Select location',
    locationAvail: 'Available at this location:',
    dose:          'Dose',
    doseUnit:      'Dose Unit',
    route:         'Route of Administration',
    cost:          'Cost',
    currency:      'Currency',
    vetSource:     'Vet Source',
    vetSelect:     'Pick from system',
    vetFreeText:   'Enter name manually',
    vetUser:       'Veterinarian',
    vetName:       'Vet Name',
    outcome:       'Outcome',
    withdrawal:    'Withdrawal Clear Date',
    quarantine:    'Under Quarantine',
    notes:         'Notes',
    selFarm:       'Select farm',
    selLot:        'Select lot (optional)',
    selAnimal:     'Select animal',
    selMedicine:   'Select medicine from inventory',
    selVet:        'Select veterinarian',
    selOutcome:    'Select outcome',
    condPlh:       'e.g. Pneumonia, lameness, colic...',
    routePlh:      'e.g. Oral, IM injection, IV...',
    vetNamePlh:    'Veterinarian name',
    notesPlh:      'Any additional details...',
    treatment:     'Treatment',
    vet_visit:     'Vet Visit',
    surgery:       'Surgery',
    other:         'Other',
    recovered:     'Recovered',
    ongoing:       'Ongoing',
    died:          'Died',
    slaughtered:   'Slaughtered',
    unknown:       'Unknown',
    stockOk:       'Stock available',
    stockLow:      'Warning: Low stock',
    stockInsuff:   'Error: Insufficient stock',
    stockEmpty:    'Error: Medicine out of stock',
    stockUnit:     'Available:',
    autoCost:      'Auto-calculated',
    resetCost:     'Recalculate',
    save:          'Save',
    saveRepeat:    'Save & Add Another',
    cancel:        'Cancel',
    previewTitle:  'Record Preview',
    noAnimal:      'No animal selected',
    noMed:         'No medicine selected',
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero with live preview ──────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">
                    {{ isEdit ? t.editTitle : t.createTitle }}
                </div>
                <div class="cv-page-hero-sub">{{ isEdit ? t.editSub : t.createSub }}</div>

                <!-- Live preview pill -->
                <div class="hero-preview mt-3">
                    <!-- Animal -->
                    <div class="preview-chip preview-animal">
                        <div class="preview-avatar" :style="previewAnimal ? 'background:linear-gradient(135deg,var(--cv-green-bright),var(--cv-blue-text))' : 'background:var(--cv-bg-input)'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.7" width="13" height="13">
                                <path d="M4 9c0-1.1.9-2 2-2h2L9 5h6l1 2h2a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9z"/>
                                <circle cx="9" cy="12" r="1" fill="white"/>
                                <circle cx="15" cy="12" r="1" fill="white"/>
                            </svg>
                        </div>
                        <span>
                            {{ previewAnimal ? `# ${previewAnimal.tag}` : t.noAnimal }}
                        </span>
                    </div>

                    <!-- Type badge -->
                    <div class="preview-chip preview-type" :class="`type-${form.type}`">
                        {{ t[form.type] ?? form.type }}
                    </div>

                    <!-- Medicine -->
                    <div class="preview-chip preview-med">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7" width="13" height="13">
                            <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                            <path d="M8 12h8M12 8v8"/>
                        </svg>
                        <span>
                            {{ previewMed
                                ? (lang === 'ar' ? (previewMed.name_ar || previewMed.name_en) : (previewMed.name_en || previewMed.name_ar))
                                : t.noMed }}
                        </span>
                        <span v-if="form.dose" class="preview-dose">
                            {{ form.dose }} {{ form.dose_unit }}
                        </span>
                    </div>

                    <!-- Date -->
                    <div v-if="form.date"  :max="today" class="preview-chip preview-date">
                        📅 {{ form.date }}
                    </div>
                </div>
            </div>

            <div class="cv-page">

                <!-- ── Error summary ──────────────────────────────── -->
                <div v-if="Object.keys(form.errors).length" class="cv-alert cv-alert-danger mb-4">
                    <ul class="list-disc ps-4 space-y-1 text-sm">
                        <li v-for="(err, field) in form.errors" :key="field">{{ err }}</li>
                    </ul>
                </div>

                <form @submit.prevent="submit" class="space-y-4">

                    <!-- ══════════════════════════════════════════════
                         SECTION 1 — Animal & Location
                    ══════════════════════════════════════════════ -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">1</div>
                        <h3 class="cv-section-title">{{ t.s1 }}</h3>

                        <div class="cv-form-grid">

                            <!-- Farm -->
                            <div>
                                <label class="cv-label">{{ t.farm }} <span class="cv-required">*</span></label>
                                <select v-model="form.farm_id" class="cv-select" :class="{ 'cv-input-error': form.errors.farm_id }">
                                    <option value="">{{ t.selFarm }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
                                </select>
                                <p v-if="form.errors.farm_id" class="cv-error">{{ form.errors.farm_id }}</p>
                            </div>

                            <!-- Lot -->
                            <div>
                                <label class="cv-label">{{ t.lot }}</label>
                                <select v-model="form.lot_id" class="cv-select" :disabled="!form.farm_id">
                                    <option value="">{{ t.selLot }}</option>
                                    <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                </select>
                            </div>

                            <!-- Animal -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.animal }} <span class="cv-required">*</span></label>
                                <select
                                    v-model="form.animal_id"
                                    class="cv-select"
                                    :class="{ 'cv-input-error': form.errors.animal_id }"
                                    :disabled="!form.farm_id || (!!preAnimalId && !isEdit)"
                                >
                                    <option value="">{{ t.selAnimal }}</option>
                                    <option v-for="a in filteredAnimals" :key="a.id" :value="a.id">
                                        # {{ a.tag }} {{ a.breed ? `— ${a.breed}` : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.animal_id" class="cv-error">{{ form.errors.animal_id }}</p>
                                <p v-if="filteredAnimals.length === 0 && form.lot_id" class="cv-hint text-amber">
                                    {{ lang === 'ar' ? 'لا توجد ماشيةات في هذه المجموعة' : 'No animals in this lot' }}
                                </p>
                            </div>

                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════
                         SECTION 2 — Event Details
                    ══════════════════════════════════════════════ -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">2</div>
                        <h3 class="cv-section-title">{{ t.s2 }}</h3>

                        <div class="cv-form-grid">

                            <!-- Type -->
                            <div>
                                <label class="cv-label">{{ t.type }} <span class="cv-required">*</span></label>
                                <select v-model="form.type" class="cv-select" :class="{ 'cv-input-error': form.errors.type }">
                                    <option value="treatment">{{ t.treatment }}</option>
                                    <option value="vet_visit">{{ t.vet_visit }}</option>
                                    <option value="surgery">{{ t.surgery }}</option>
                                    <option value="other">{{ t.other }}</option>
                                </select>
                                <p v-if="form.errors.type" class="cv-error">{{ form.errors.type }}</p>
                            </div>

                            <!-- Date -->
                            <div>
                                <label class="cv-label">{{ t.date }} <span class="cv-required">*</span></label>
                                <input
                                    v-model="form.date"
                                    type="date"
                                    class="cv-input" 
                                    :max="today" 
                                    :class="{ 'cv-input-error': form.errors.date }"
                                />
                                <p v-if="form.errors.date" class="cv-error">{{ form.errors.date }}</p>
                            </div>

                            <!-- Condition -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.condition }}</label>
                                <input
                                    v-model="form.condition"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="t.condPlh"
                                />
                            </div>

                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════
                         SECTION 3 — Treatment Details (Medicine)
                    ══════════════════════════════════════════════ -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">3</div>
                        <h3 class="cv-section-title">{{ t.s3 }}</h3>

                        <!-- No farm selected warning -->
                        <div v-if="!form.farm_id" class="cv-info-box mb-4">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            {{ lang === 'ar' ? 'اختر المزرعة أولاً لعرض الأدوية المتاحة' : 'Select a farm first to see available medicines' }}
                        </div>

                        <div class="cv-form-grid">

                            <!-- Medicine (inventory only) -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.medicine }} <span class="cv-required">*</span></label>
                                <select
                                    v-model="form.inventory_item_id"
                                    class="cv-select"
                                    :class="{ 'cv-input-error': form.errors.inventory_item_id }"
                                    :disabled="!form.farm_id"
                                >
                                    <option value="">{{ t.selMedicine }}</option>
                                    <option
                                        v-for="m in filteredMedicines"
                                        :key="m.id"
                                        :value="m.id"
                                        :disabled="m.qty_on_hand <= 0"
                                    >
                                        {{ lang === 'ar' ? (m.name_ar || m.name_en) : (m.name_en || m.name_ar) }}
                                        — {{ lang === 'ar' ? 'متاح:' : 'Stock:' }} {{ m.qty_on_hand }} {{ m.unit }}
                                        {{ m.qty_on_hand <= 0 ? (lang === 'ar' ? '(نفد)' : '(out of stock)') : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.inventory_item_id" class="cv-error">{{ form.errors.inventory_item_id }}</p>

                                <!-- Stock status indicator -->
                                <div v-if="selectedMedicine && form.inventory_item_id" class="stock-indicator mt-2" :class="`stock-${stockStatus}`">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="14" height="14">
                                        <path v-if="stockStatus === 'ok'"      d="M20 6L9 17l-5-5"/>
                                        <path v-else                           d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01"/>
                                    </svg>
                                    <span v-if="stockStatus === 'ok'">{{ t.stockOk }}</span>
                                    <span v-else-if="stockStatus === 'low'">{{ t.stockLow }}</span>
                                    <span v-else-if="stockStatus === 'insufficient'">{{ t.stockInsuff }}</span>
                                    <span v-else>{{ t.stockEmpty }}</span>
                                    <strong class="ms-1">{{ t.stockUnit }} {{ selectedMedicine.qty_on_hand }} {{ selectedMedicine.unit }}</strong>
                                </div>
                            </div>

                            <!-- Location (cascades from medicine) -->
                            <!-- 📌 LivestockOperationalCostFlow (#43) §6 — location_id required for stock decrement -->
                            <div v-if="form.inventory_item_id" class="cv-span-2">
                                <label class="cv-label">{{ t.location }} <span class="cv-required">*</span></label>
                                <select
                                    v-model="form.location_id"
                                    class="cv-select"
                                    :class="{ 'cv-input-error': form.errors.location_id }"
                                >
                                    <option value="">{{ t.selLocation }}</option>
                                    <option
                                        v-for="l in medicineLocations"
                                        :key="l.location_id"
                                        :value="l.location_id"
                                    >
                                        {{ locLabel(l) }}
                                        — {{ lang === 'ar' ? 'متاح:' : 'Stock:' }} {{ l.qty_on_hand }} {{ selectedMedicine?.unit }}
                                    </option>
                                </select>
                                <p v-if="form.errors.location_id" class="cv-error">{{ form.errors.location_id }}</p>
                                <!-- Stock at selected location -->
                                <div v-if="selectedLocation" class="stock-avail-badge mt-1" style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.65rem;border-radius:6px;background:rgba(16,185,129,0.1);color:var(--cv-green-bright);font-size:0.73rem;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="12" height="12"><path d="M20 6L9 17l-5-5"/></svg>
                                    {{ t.locationAvail }} <strong>{{ selectedLocation.qty_on_hand }} {{ selectedMedicine?.unit }}</strong>
                                </div>
                                <!-- Warning if no locations have stock -->
                                <div v-if="medicineLocations.length === 0" class="cv-error mt-1">
                                    {{ lang === 'ar' ? 'لا يوجد مخزون من هذا الدواء في أي موقع' : 'No stock available for this medicine in any location' }}
                                </div>
                            </div>

                            <!-- Dose -->
                            <div>
                                <label class="cv-label">{{ t.dose }} <span class="cv-required">*</span></label>
                                <input
                                    v-model="form.dose"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    class="cv-input"
                                    :class="{ 'cv-input-error': form.errors.dose }"
                                    :placeholder="lang === 'ar' ? 'أدخل الجرعة' : 'Enter dose amount'"
                                />
                                <p v-if="form.errors.dose" class="cv-error">{{ form.errors.dose }}</p>
                            </div>

                            <!-- Dose unit (auto-filled, overridable) -->
                            <div>
                                <label class="cv-label">{{ t.doseUnit }}</label>
                                <input
                                    v-model="form.dose_unit"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="lang === 'ar' ? 'مثال: مل، حبة...' : 'e.g. ml, tablet...'"
                                />
                                <p class="cv-hint">
                                    {{ lang === 'ar' ? 'يُملأ تلقائياً من الدواء المختار' : 'Auto-filled from selected medicine' }}
                                </p>
                            </div>

                            <!-- Route -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.route }}</label>
                                <input
                                    v-model="form.route"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="t.routePlh"
                                />
                            </div>

                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════
                         SECTION 4 — Veterinarian & Cost
                    ══════════════════════════════════════════════ -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">4</div>
                        <h3 class="cv-section-title">{{ t.s4 }}</h3>

                        <div class="cv-form-grid">

                            <!-- Vet mode toggle -->
                            <div class="cv-span-2">
                                <label class="cv-label">{{ t.vetSource }}</label>
                                <div class="cv-btn-group">
                                    <button
                                        type="button"
                                        class="cv-btn-seg"
                                        :class="{ active: vetMode === 'select' }"
                                        @click="vetMode = 'select'"
                                    >{{ t.vetSelect }}</button>
                                    <button
                                        type="button"
                                        class="cv-btn-seg"
                                        :class="{ active: vetMode === 'text' }"
                                        @click="vetMode = 'text'"
                                    >{{ t.vetFreeText }}</button>
                                </div>
                            </div>

                            <!-- Vet from system users -->
                            <div v-if="vetMode === 'select'">
                                <label class="cv-label">{{ t.vetUser }}</label>
                                <select v-model="form.vet_id" class="cv-select">
                                    <option value="">{{ t.selVet }}</option>
                                    <option v-for="v in vets" :key="v.id" :value="v.id">{{ v.name }}</option>
                                </select>
                            </div>

                            <!-- Vet free text -->
                            <div v-else>
                                <label class="cv-label">{{ t.vetName }}</label>
                                <input
                                    v-model="form.vet_name"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="t.vetNamePlh"
                                />
                            </div>

                            <!-- Cost -->
                            <div>
                                <label class="cv-label">{{ t.cost }}</label>
                                <div class="cv-input-with-badge">
                                    <input
                                        v-model="form.cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="cv-input"
                                        readonly
                                        @input="onCostInput"
                                    />
                                    <span v-if="!costManuallySet" class="cv-input-badge">{{ t.autoCost }}</span>
                                    <button v-else type="button" class="cv-input-badge cv-input-badge-btn" @click="resetCostOverride">
                                        {{ t.resetCost }}
                                    </button>
                                </div>
                                <p v-if="form.errors.cost" class="cv-error">{{ form.errors.cost }}</p>
                            </div>

                            <!-- Currency -->
                            <div>
                                <label class="cv-label">{{ t.currency }}</label>
                                <select v-model="form.currency" class="cv-select">
                                    <option value="EGP">EGP — {{ lang === 'ar' ? 'جنيه مصري' : 'Egyptian Pound' }}</option>
                                    <option value="USD">USD — {{ lang === 'ar' ? 'دولار أمريكي' : 'US Dollar' }}</option>
                                    <option value="EUR">EUR — {{ lang === 'ar' ? 'يورو' : 'Euro' }}</option>
                                </select>
                            </div>

                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════
                         SECTION 5 — Outcome & Flags
                    ══════════════════════════════════════════════ -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">5</div>
                        <h3 class="cv-section-title">{{ t.s5 }}</h3>

                        <div class="cv-form-grid">

                            <!-- Outcome -->
                            <div>
                                <label class="cv-label">{{ t.outcome }}</label>
                                <select v-model="form.outcome" class="cv-select">
                                    <option value="">{{ t.selOutcome }}</option>
                                    <option value="recovered">{{ t.recovered }}</option>
                                    <option value="ongoing">{{ t.ongoing }}</option>
                                    <option value="died">{{ t.died }}</option>
                                    <option value="slaughtered">{{ t.slaughtered }}</option>
                                    <option value="unknown">{{ t.unknown }}</option>
                                </select>
                            </div>

                            <!-- Withdrawal clear date -->
                            <div>
                                <label class="cv-label">{{ t.withdrawal }}</label>
                                <input
                                    v-model="form.withdrawal_clear_date"
                                    @input="onWithdrawalInput"
                                    type="date"
                                    class="cv-input"
                                    :class="{ 'cv-input-error': form.errors.withdrawal_clear_date }"
                                />
                                <p v-if="form.type === 'treatment' && form.withdrawal_clear_date" class="cv-hint">
                                    {{ lang === 'ar' ? 'اقتُرح تلقائياً (تاريخ + 14 يوماً) — يمكنك التعديل' : 'Auto-suggested (date + 14 days) — you can override' }}
                                </p>
                                <p v-if="form.errors.withdrawal_clear_date" class="cv-error">{{ form.errors.withdrawal_clear_date }}</p>
                            </div>

                            <!-- Quarantine toggle -->
                            <div class="cv-span-2">
                                <div
                                    class="cv-quarantine-box"
                                    :class="{ 'cv-quarantine-active': form.is_quarantine }"
                                    @click="form.is_quarantine = !form.is_quarantine"
                                >
                                    <div class="cv-toggle" :class="{ 'cv-toggle-danger': form.is_quarantine }">
                                        <span class="cv-toggle-knob"/>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-sm">{{ t.quarantine }}</div>
                                        <div class="text-xs" style="color:var(--cv-text-muted)">
                                            {{ lang === 'ar'
                                                ? 'سيتم تمييز الماشية بأنه في حجر صحي ولن يكون مؤهلاً للذبح'
                                                : 'Animal will be flagged as quarantined and ineligible for slaughter' }}
                                        </div>
                                    </div>
                                    <span v-if="form.is_quarantine" class="cv-badge badge-danger ms-auto">
                                        🔴 {{ lang === 'ar' ? 'نشط' : 'Active' }}
                                    </span>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════
                         SECTION 6 — Notes
                    ══════════════════════════════════════════════ -->
                    <div class="cv-card cv-section-card">
                        <div class="cv-section-num">6</div>
                        <h3 class="cv-section-title">{{ t.s6 }}</h3>
                        <textarea
                            v-model="form.notes"
                            class="cv-textarea"
                            rows="3"
                            :placeholder="t.notesPlh"
                        />
                    </div>

                    <!-- ── Action buttons ──────────────────────────── -->
                    <div class="cv-form-actions">
                        <Link :href="route('health.index')" class="cv-btn cv-btn-ghost">
                            {{ t.cancel }}
                        </Link>

                        <!-- Quick repeat (create mode only) -->
                        <button
                            v-if="!isEdit"
                            type="button"
                            class="cv-btn cv-btn-ghost"
                            :disabled="form.processing"
                            @click="quickRepeat = true; submit()"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="14" height="14">
                                <path d="M17 1l4 4-4 4"/>
                                <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                                <path d="M7 23l-4-4 4-4"/>
                                <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                            </svg>
                            {{ t.saveRepeat }}
                        </button>

                        <button
                            type="submit"
                            class="cv-btn cv-btn-amber"
                            :disabled="form.processing || stockStatus === 'insufficient' || stockStatus === 'empty'"
                            @click="quickRepeat = false"
                        >
                            <svg v-if="form.processing" class="cv-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
                                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                            </svg>
                            {{ t.save }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Section cards ────────────────────────────────────────────── */
.cv-section-card {
    position: relative;
    padding: 1.5rem 1.5rem 1.5rem 3.5rem;
}
.cv-section-num {
    position: absolute;
    inset-inline-start: -14px;
    top: 1.25rem;
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--cv-amber), #d97706);
    color: #000;
    font-weight: 700;
    font-size: 0.85rem;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(245,158,11,0.4);
}
.cv-section-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--cv-text-primary);
    margin-bottom: 1.25rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--cv-border-muted);
}

/* ── Form grid ────────────────────────────────────────────────── */
.cv-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.cv-span-2 { grid-column: span 2; }
@media (max-width: 600px) {
    .cv-form-grid { grid-template-columns: 1fr; }
    .cv-span-2 { grid-column: span 1; }
}

/* ── Labels / errors / hints ──────────────────────────────────── */
.cv-label    { display: block; font-size: 0.8rem; font-weight: 500; color: var(--cv-text-secondary); margin-bottom: 0.3rem; }
.cv-required { color: var(--cv-danger-text); }
.cv-error    { font-size: 0.75rem; color: var(--cv-danger-text); margin-top: 0.25rem; }
.cv-hint     { font-size: 0.72rem; color: var(--cv-text-muted); margin-top: 0.25rem; }
.text-amber  { color: var(--cv-amber) !important; }

/* ── Input with badge overlay ─────────────────────────────────── */
.cv-input-with-badge { position: relative; }
.cv-input-with-badge .cv-input { padding-inline-end: 8rem; }
.cv-input-badge {
    position: absolute;
    inset-inline-end: 0.5rem;
    top: 50%; transform: translateY(-50%);
    font-size: 0.65rem;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    background: rgba(16,185,129,0.15);
    color: var(--cv-green-bright);
    white-space: nowrap;
    pointer-events: none;
}
.cv-input-badge-btn {
    pointer-events: all;
    cursor: pointer;
    background: rgba(245,158,11,0.15);
    color: var(--cv-amber);
    border: none;
}

/* ── Stock status ─────────────────────────────────────────────── */
.stock-indicator {
    display: flex; align-items: center; gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 500;
}
.stock-ok           { background: rgba(16,185,129,0.12); color: var(--cv-green-bright); }
.stock-low          { background: rgba(245,158,11,0.12); color: var(--cv-amber); }
.stock-insufficient { background: rgba(239,68,68,0.12);  color: var(--cv-danger-text); }
.stock-empty        { background: rgba(239,68,68,0.12);  color: var(--cv-danger-text); }

/* ── Vet segmented button ─────────────────────────────────────── */
.cv-btn-group {
    display: flex;
    border: 1px solid var(--cv-border);
    border-radius: 8px;
    overflow: hidden;
}
.cv-btn-seg {
    flex: 1;
    padding: 0.45rem 0.75rem;
    font-size: 0.82rem;
    background: transparent;
    color: var(--cv-text-secondary);
    border: none;
    cursor: pointer;
    transition: all 0.15s;
}
.cv-btn-seg + .cv-btn-seg { border-inline-start: 1px solid var(--cv-border); }
.cv-btn-seg.active {
    background: var(--cv-amber);
    color: #000;
    font-weight: 600;
}

/* ── Quarantine box ───────────────────────────────────────────── */
.cv-quarantine-box {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    border: 1px solid var(--cv-border-muted);
    cursor: pointer;
    transition: all 0.2s;
    background: var(--cv-bg-input);
}
.cv-quarantine-active {
    border-color: var(--cv-danger-text);
    background: rgba(239,68,68,0.06);
}

/* ── Toggle ───────────────────────────────────────────────────── */
.cv-toggle {
    width: 40px; height: 22px;
    border-radius: 999px;
    border: 1px solid var(--cv-border);
    background: var(--cv-bg-input);
    position: relative;
    flex-shrink: 0;
    transition: background 0.2s;
}
.cv-toggle-danger { background: var(--cv-danger-text); border-color: var(--cv-danger-text); }
.cv-toggle-knob {
    position: absolute;
    top: 2px; inset-inline-start: 2px;
    width: 16px; height: 16px;
    background: #fff;
    border-radius: 50%;
    transition: inset-inline-start 0.2s;
}
.cv-toggle-danger .cv-toggle-knob { inset-inline-start: calc(100% - 18px); }

/* ── Hero preview ─────────────────────────────────────────────── */
.hero-preview { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.preview-chip {
    display: flex; align-items: center; gap: 0.4rem;
    padding: 0.3rem 0.75rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 500;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--cv-text-secondary);
}
.preview-avatar {
    width: 22px; height: 22px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.preview-dose { color: var(--cv-blue-text); font-weight: 700; margin-inline-start: 0.25rem; }
.type-treatment { border-color: var(--cv-danger-text); color: var(--cv-danger-text); background: rgba(239,68,68,0.1); }
.type-vet_visit { border-color: var(--cv-blue-text);   color: var(--cv-blue-text);   background: rgba(59,130,246,0.1); }
.type-surgery   { border-color: var(--cv-amber);       color: var(--cv-amber);       background: rgba(245,158,11,0.1); }
.type-other     { border-color: var(--cv-green-bright);color: var(--cv-green-bright);background: rgba(16,185,129,0.1); }

/* ── Info box ─────────────────────────────────────────────────── */
.cv-info-box {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.6rem 0.9rem;
    border-radius: 8px;
    background: rgba(59,130,246,0.08);
    border: 1px solid rgba(59,130,246,0.2);
    color: var(--cv-blue-text);
    font-size: 0.82rem;
}

/* ── Actions row ──────────────────────────────────────────────── */
.cv-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 0.5rem;
    flex-wrap: wrap;
}

/* ── Badges ───────────────────────────────────────────────────── */
.badge-green  { background: rgba(16,185,129,0.15); color: var(--cv-green-bright); }
.badge-amber  { background: rgba(245,158,11,0.15); color: var(--cv-amber); }
.badge-danger { background: rgba(239,68,68,0.15);  color: var(--cv-danger-text); }

/* ── Spinner ──────────────────────────────────────────────────── */
.cv-spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>