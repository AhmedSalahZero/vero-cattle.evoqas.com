<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Animals/BornCreate.vue
//
//  PURPOSE:
//  Single-purpose form for registering animals born on the farm.
//  Reached via Animals → Add Animal button.
//
//  COMPONENT PLAN:
//  ─────────────────────────────────────────────────────────────────
//  Props from AnimalController::create():
//    farms     → [{id, name_en, name_ar, company_id}]
//    lots      → [{id, name, farm_id}]
//
//  What this form does NOT have (by design):
//    ✗ Source selector     — always 'born' (hardcoded, never sent)
//    ✗ Supplier field      — born animals have no supplier
//    ✗ Purchase price      — born animals have no acquisition cost
//    ✗ Purchase date       — born animals have a DOB, not a purchase date
//    ✗ Freight cost        — not applicable
//    ✗ Mode tabs A/B       — bulk entry is for procurement only
//    ✗ PO fields           — not applicable
//
//  What this form DOES have:
//    ✓ Identity   — Tag, RFID, Sex, Breed, Color, Markings, DOB
//    ✓ Placement  — Farm, Lot
//    ✓ Lineage    — Dam ID, Sire ID (key for born animals)
//    ✓ Birth info — Birth weight (kg) — entry weight at birth
//    ✓ Photo      — optional
//    ✓ Status     — defaults to active
//    ✓ Notes
//
//  Submits to: animals.store (POST /animals)
//  source is hardcoded to 'born' server-side — not sent from form
//  (AnimalController::store() only accepts born animals on this route)
//
//  BILINGUAL: Arabic / English via useLocale()
//  PERMISSION: create animals
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    farms:     { type: Array, default: () => [] },
    lots:      { type: Array, default: () => [] },
    breeds:    { type: Array,  default: () => [] },
})

const { locale, isRtl, n } = useLocale()

// ── Bilingual labels ───────────────────────────────────────────
const L = computed(() => locale.value === 'ar' ? {
    eyebrow:        'إدارة القطيع',
    title:          'تسجيل مولود جديد',
    sub:            'أدخل بيانات الماشية المولودة في المزرعة',
    back:           'رجوع',
    save:           'حفظ',

    s1:             'الهوية',
    s2:             'الموقع',
    s3:             'النسب ووزن الميلاد',
    s4:             'الصورة',
    s5:             'الحالة والملاحظات',

    tag:            'رقم التعريف *',
    tagPh:          'مثال: TAG-001',
    rfid:           'رقم RFID',
    rfidPh:         'رقم الرقاقة الإلكترونية',
    sex:            'الجنس *',
    sexSelect:      '-- اختر الجنس --',
    sexMale:        'ذكر',
    sexFemale:      'أنثى',
    sexCastrated:   'مخصي',
    breed:          'السلالة',
    breedPh:        'مثال: فريزيان، عربي...',
    breedSelect:    '-- اختر السلالة --',
    breedFallback:  'أو أدخل يدوياً',
    color:          'اللون',
    colorPh:        'مثال: بني، أبلق...',
    markings:       'علامات مميزة',
    markingsPh:     'أي علامات أو وسوم مميزة',
    dob:            'تاريخ الميلاد',
    dobEstimated:   'تاريخ تقريبي',

    farm:           'المزرعة *',
    farmSelect:     '-- اختر المزرعة --',
    lot:            'المجموعة',
    lotSelect:      '-- اختر المجموعة --',
    lotEmpty:       'اختر مزرعة أولاً',

    damId:          'رقم الأم (Dam)',
    damPh:          'رقم تعريف الأم — اختياري',
    sireId:         'رقم الأب (Sire)',
    sirePh:         'رقم تعريف الأب — اختياري',
    birthWeight:    'وزن الميلاد (كجم)',
    birthWeightPh:  '0.0',

    photoUpload:    'رفع صورة',
    photoChange:    'تغيير الصورة',
    photoClear:     'إزالة',
    photoHint:      'JPG أو PNG — الحد الأقصى 2 ميجابايت',

    status:         'الحالة',
    statusActive:   'نشط',
    statusQuarantine:'حجر صحي',
    statusDate:     'تاريخ الحالة',
    notes:          'ملاحظات',
    notesPh:        'أي ملاحظات إضافية...',

    bornBadge:      'مولود بالمزرعة',
} : {
    eyebrow:        'Herd Management',
    title:          'Register Born Animal',
    sub:            'Record an animal born on the farm',
    back:           'Back',
    save:           'Save Animal',

    s1:             'Identity',
    s2:             'Placement',
    s3:             'Lineage & Birth Weight',
    s4:             'Photo',
    s5:             'Status & Notes',

    tag:            'Tag Number *',
    tagPh:          'e.g. TAG-001',
    rfid:           'RFID Number',
    rfidPh:         'Electronic chip ID',
    sex:            'Sex *',
    sexSelect:      '-- Select Sex --',
    sexMale:        'Male',
    sexFemale:      'Female',
    sexCastrated:   'Castrated',
    breed:          'Breed',
    breedPh:        'e.g. Friesian, Angus...',
    breedSelect:    '-- Select Breed --',
    breedFallback:  'or type manually',
    color:          'Color',
    colorPh:        'e.g. Brown, Pied...',
    markings:       'Distinguishing Markings',
    markingsPh:     'Any notable marks or brands',
    dob:            'Date of Birth',
    dobEstimated:   'Date is estimated',

    farm:           'Farm *',
    farmSelect:     '-- Select Farm --',
    lot:            'Lot',
    lotSelect:      '-- Select Lot --',
    lotEmpty:       'Select a farm first',

    damId:          'Dam ID (Mother)',
    damPh:          'Mother\'s tag — optional',
    sireId:         'Sire ID (Father)',
    sirePh:         'Father\'s tag — optional',
    birthWeight:    'Birth Weight (kg)',
    birthWeightPh:  '0.0',

    photoUpload:    'Upload Photo',
    photoChange:    'Change Photo',
    photoClear:     'Remove',
    photoHint:      'JPG or PNG — Max 2 MB',

    status:         'Status',
    statusActive:   'Active',
    statusQuarantine:'Quarantine',
    statusDate:     'Status Date',
    notes:          'Notes',
    notesPh:        'Any additional notes...',

    bornBadge:      'Born on Farm',
})

// ── Form ──────────────────────────────────────────────────────
const form = useForm({
    // source is NOT in the form — AnimalController::store() hardcodes 'born'
    source:             'born_on_farm',   // ← ADD THIS LINE (hidden, never shown in UI)
    farm_id:            '',
    lot_id:             '',
    tag:                '',
    rfid:               '',
    sex:                '',
    breed:              '',
    color:              '',
    markings:           '',
    dob:                '',
    dob_estimated:      false,
    dam_id:             '',
    sire_id:            '',
    purchase_weight_kg: '',   // used as birth weight — same column, born context
    status:             'active',
    status_date:        '',
    notes:              '',
    photo:              null,
})

// ── Filtered lots by farm ─────────────────────────────────────
const filteredLots = computed(() =>
    form.farm_id
        ? props.lots.filter(l => String(l.farm_id) === String(form.farm_id))
        : []
)
watch(() => form.farm_id, () => { form.lot_id = '' })

// ── Photo preview ─────────────────────────────────────────────
const photoPreview = ref(null)
function onPhotoChange(e) {
    const file = e.target.files?.[0]
    if (!file) return
    form.photo = file
    const reader = new FileReader()
    reader.onload = ev => { photoPreview.value = ev.target.result }
    reader.readAsDataURL(file)
}
function clearPhoto() {
    form.photo = null
    photoPreview.value = null
}

// ── Hero avatar color driven by tag ──────────────────────────
const avatarColor = computed(() => {
    if (!form.tag) return ['#0D3D20', '#3AAA58']
    const h = [...form.tag].reduce((a, c) => a + c.charCodeAt(0), 0)
    const palettes = [
        ['#1a6b3a','#5CC46A'],
        ['#1a3a5c','#60A8E0'],
        ['#5c1a1a','#E07070'],
        ['#3a2a00','#E8A030'],
    ]
    return palettes[h % palettes.length]
})

// ── Submit ────────────────────────────────────────────────────
function submit() {
    form.post(route('animals.store'), { forceFormData: true })
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="bcv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ───────────────────────────────────────── -->
            <div class="bcv-hero">
                <div class="bcv-hero-inner">

                    <!-- Avatar -->
                    <div
                        class="bcv-avatar"
                        :style="{ background: `linear-gradient(135deg, ${avatarColor[0]}, ${avatarColor[1]})` }"
                    >
                        <span v-if="form.tag">{{ form.tag.slice(0,2).toUpperCase() }}</span>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.5" style="width:28px;height:28px;opacity:.7">
                            <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
                        </svg>
                    </div>

                    <!-- Text -->
                    <div class="bcv-hero-text">
                        <div class="bcv-eyebrow">{{ L.eyebrow }}</div>
                        <div class="bcv-title">
                            {{ L.title }}
                            <span v-if="form.tag" class="bcv-tag-chip">{{ form.tag }}</span>
                        </div>
                        <div class="bcv-sub">{{ L.sub }}</div>
                    </div>

                    <!-- Born badge -->
                    <div class="bcv-born-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" style="width:14px;height:14px">
                            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        {{ L.bornBadge }}
                    </div>
                </div>
            </div>

            <!-- ── Form ───────────────────────────────────────── -->
            <form @submit.prevent="submit" class="bcv-form" enctype="multipart/form-data">
                <div class="bcv-body">

                    <!-- ═══ Section 1 — Identity ════════════════ -->
                    <div class="bcv-card">
                        <div class="bcv-section-head">
                            <div class="bcv-num" style="background:linear-gradient(135deg,#1a6b3a,#5CC46A)">1</div>
                            <h2 class="bcv-section-title">{{ L.s1 }}</h2>
                        </div>
                        <div class="bcv-grid">

                            <!-- Tag -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.tag }}</label>
                                <input
                                    v-model="form.tag"
                                    class="bcv-input"
                                    :class="{ 'bcv-input-error': form.errors.tag }"
                                    :placeholder="L.tagPh"
                                />
                                <div v-if="form.errors.tag" class="bcv-error">{{ form.errors.tag }}</div>
                            </div>

                            <!-- RFID -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.rfid }}</label>
                                <input v-model="form.rfid" class="bcv-input" :placeholder="L.rfidPh" />
                            </div>

                            <!-- Sex -->
                            <div class="bcv-field bcv-f-sm">
                                <label class="bcv-label">{{ L.sex }}</label>
                                <select
                                    v-model="form.sex"
                                    class="bcv-select"
                                    :class="{ 'bcv-input-error': form.errors.sex }"
                                >
                                    <option value="">{{ L.sexSelect }}</option>
                                    <option value="male">{{ L.sexMale }}</option>
                                    <option value="female">{{ L.sexFemale }}</option>
                                    <option value="castrated">{{ L.sexCastrated }}</option>
                                </select>
                                <div v-if="form.errors.sex" class="bcv-error">{{ form.errors.sex }}</div>
                            </div>

                            <!-- Breed -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.breed }}</label>
                                <select v-if="breeds && breeds.length > 0" v-model="form.breed" class="bcv-select">
                                    <option value="">{{ L.breedSelect }}</option>
                                    <option v-for="b in breeds" :key="b.id"
                                        :value="locale === 'ar' ? (b.name_ar || b.name_en) : (b.name_en || b.name_ar)">
                                        {{ locale === 'ar' ? (b.name_ar || b.name_en) : (b.name_en || b.name_ar) }}
                                    </option>
                                </select>
                                <input v-else v-model="form.breed" class="bcv-input" :placeholder="L.breedPh" />
                            </div>
                            
                            <!-- Color -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.color }}</label>
                                <input v-model="form.color" class="bcv-input" :placeholder="L.colorPh" />
                            </div>

                            <!-- Markings -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.markings }}</label>
                                <input v-model="form.markings" class="bcv-input" :placeholder="L.markingsPh" />
                            </div>

                            <!-- DOB -->
                            <div class="bcv-field bcv-f-sm">
                                <label class="bcv-label">{{ L.dob }}</label>
                                <input type="date" v-model="form.dob" class="bcv-input" />
                            </div>

                            <!-- DOB Estimated -->
                            <div class="bcv-field bcv-f-sm bcv-field-checkbox">
                                <label class="bcv-checkbox-label">
                                    <span
                                        class="bcv-checkbox"
                                        :class="{ 'bcv-checkbox--checked': form.dob_estimated }"
                                        @click="form.dob_estimated = !form.dob_estimated"
                                    >
                                        <svg v-if="form.dob_estimated" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="3"
                                             style="width:12px;height:12px">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    </span>
                                    {{ L.dobEstimated }}
                                </label>
                            </div>

                        </div>
                    </div>

                    <!-- ═══ Section 2 — Placement ════════════════ -->
                    <div class="bcv-card">
                        <div class="bcv-section-head">
                            <div class="bcv-num" style="background:linear-gradient(135deg,#7c4a00,#E8A030)">2</div>
                            <h2 class="bcv-section-title">{{ L.s2 }}</h2>
                        </div>
                        <div class="bcv-grid">

                            <!-- Farm -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.farm }}</label>
                                <select
                                    v-model="form.farm_id"
                                    class="bcv-select"
                                    :class="{ 'bcv-input-error': form.errors.farm_id }"
                                >
                                    <option value="">{{ L.farmSelect }}</option>
                                    <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
                                </select>
                                <div v-if="form.errors.farm_id" class="bcv-error">{{ form.errors.farm_id }}</div>
                            </div>

                            <!-- Lot -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.lot }}</label>
                                <select
                                    v-model="form.lot_id"
                                    class="bcv-select"
                                    :disabled="!form.farm_id"
                                >
                                    <option value="">{{ form.farm_id ? L.lotSelect : L.lotEmpty }}</option>
                                    <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                </select>
                            </div>

                        </div>
                    </div>

                    <!-- ═══ Section 3 — Lineage & Birth Weight ═══ -->
                    <div class="bcv-card">
                        <div class="bcv-section-head">
                            <div class="bcv-num" style="background:linear-gradient(135deg,#2d1a5c,#8b5cf6)">3</div>
                            <h2 class="bcv-section-title">{{ L.s3 }}</h2>
                        </div>
                        <div class="bcv-grid">

                            <!-- Dam -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.damId }}</label>
                                <input v-model="form.dam_id" class="bcv-input" :placeholder="L.damPh" />
                                <div v-if="form.errors.dam_id" class="bcv-error">{{ form.errors.dam_id }}</div>
                            </div>

                            <!-- Sire -->
                            <div class="bcv-field bcv-f-md">
                                <label class="bcv-label">{{ L.sireId }}</label>
                                <input v-model="form.sire_id" class="bcv-input" :placeholder="L.sirePh" />
                                <div v-if="form.errors.sire_id" class="bcv-error">{{ form.errors.sire_id }}</div>
                            </div>

                            <!-- Birth Weight -->
                            <div class="bcv-field bcv-f-sm">
                                <label class="bcv-label">{{ L.birthWeight }}</label>
                                <div class="bcv-input-group">
                                    <input
                                        v-model="form.purchase_weight_kg"
                                        type="number" min="0" step="0.1"
                                        class="bcv-input bcv-input-suffixed"
                                        :placeholder="L.birthWeightPh"
                                    />
                                    <span class="bcv-input-suffix">kg</span>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══ Section 4 — Photo ════════════════════ -->
                    <div class="bcv-card">
                        <div class="bcv-section-head">
                            <div class="bcv-num" style="background:linear-gradient(135deg,#1a3a5c,#60A8E0)">4</div>
                            <h2 class="bcv-section-title">{{ L.s4 }}</h2>
                        </div>
                        <div class="bcv-photo-row">

                            <!-- Preview -->
                            <div class="bcv-photo-preview-wrap">
                                <img v-if="photoPreview" :src="photoPreview"
                                     class="bcv-photo-preview" alt="preview" />
                                <div v-else class="bcv-photo-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.5" style="width:32px;height:32px">
                                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                                        <circle cx="8.5" cy="8.5" r="1.5"/>
                                        <polyline points="21 15 16 10 5 21"/>
                                    </svg>
                                </div>
                            </div>

                            <!-- Controls -->
                            <div class="bcv-photo-controls">
                                <label class="bcv-btn bcv-btn-ghost bcv-upload-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.7" style="width:15px;height:15px">
                                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    {{ photoPreview ? L.photoChange : L.photoUpload }}
                                    <input type="file" accept="image/*"
                                           class="bcv-file-hidden" @change="onPhotoChange" />
                                </label>
                                <button v-if="photoPreview" type="button"
                                        class="bcv-btn bcv-btn-ghost bcv-btn-sm" @click="clearPhoto">
                                    {{ L.photoClear }}
                                </button>
                                <span class="bcv-hint">{{ L.photoHint }}</span>
                            </div>
                        </div>
                        <div v-if="form.errors.photo" class="bcv-error" style="margin-top:.5rem">
                            {{ form.errors.photo }}
                        </div>
                    </div>

                    <!-- ═══ Section 5 — Status & Notes ══════════ -->
                    <div class="bcv-card">
                        <div class="bcv-section-head">
                            <div class="bcv-num" style="background:linear-gradient(135deg,#1a3a5c,#60A8E0)">5</div>
                            <h2 class="bcv-section-title">{{ L.s5 }}</h2>
                        </div>
                        <div class="bcv-grid">

                            <!-- Status -->
                            <div class="bcv-field bcv-f-sm">
                                <label class="bcv-label">{{ L.status }}</label>
                                <select v-model="form.status" class="bcv-select">
                                    <option value="active">{{ L.statusActive }}</option>
                                    <option value="quarantine">{{ L.statusQuarantine }}</option>
                                </select>
                            </div>

                            <!-- Status Date -->
                            <div class="bcv-field bcv-f-sm">
                                <label class="bcv-label">{{ L.statusDate }}</label>
                                <input type="date" v-model="form.status_date" class="bcv-input" />
                            </div>

                            <!-- Notes -->
                            <div class="bcv-field bcv-f-full">
                                <label class="bcv-label">{{ L.notes }}</label>
                                <textarea
                                    v-model="form.notes"
                                    class="bcv-textarea"
                                    rows="3"
                                    :placeholder="L.notesPh"
                                ></textarea>
                            </div>

                        </div>
                    </div>

                </div><!-- /bcv-body -->

                <!-- ── Footer ─────────────────────────────────── -->
                <div class="bcv-footer">
                    <Link :href="route('animals.index')" class="bcv-btn bcv-btn-ghost">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" style="width:15px;height:15px">
                            <polyline :points="isRtl ? '9 18 15 12 9 6' : '15 18 9 12 15 6'"/>
                        </svg>
                        {{ L.back }}
                    </Link>

                    <button
                        type="submit"
                        class="bcv-btn bcv-btn-amber bcv-btn-submit"
                        :disabled="form.processing"
                    >
                        <svg v-if="!form.processing" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7" style="width:15px;height:15px">
                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" style="width:15px;height:15px;animation:bcv-spin .8s linear infinite">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                        {{ L.save }}
                    </button>
                </div>

            </form>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Page ────────────────────────────────────────── */
.bcv-page {
    min-height: 100vh;
    background: var(--cv-bg);
    display: flex;
    flex-direction: column;
}

/* ── Hero ────────────────────────────────────────── */
.bcv-hero {
    background: linear-gradient(135deg, #0a1f11 0%, #0d2b18 60%, #0e1f13 100%);
    border-bottom: 1px solid var(--cv-green-border);
    padding: 1.75rem 2rem 1.5rem;
    position: relative;
    overflow: hidden;
}
.bcv-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(var(--cv-green-dim) 1px, transparent 1px);
    background-size: 24px 24px;
    pointer-events: none;
}
.bcv-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
}
.bcv-avatar {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    color: #fff;
    border: 2px solid var(--cv-green-border);
    flex-shrink: 0;
    transition: background 0.3s;
}
.bcv-hero-text { flex: 1; min-width: 0; }
.bcv-eyebrow {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--cv-amber);
    margin-bottom: .25rem;
}
.bcv-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
}
.bcv-tag-chip {
    font-size: .82rem;
    font-weight: 600;
    background: var(--cv-green-dim);
    color: var(--cv-green-bright);
    border: 1px solid var(--cv-green-border);
    border-radius: 20px;
    padding: .15em .65em;
}
.bcv-sub {
    font-size: .875rem;
    color: var(--cv-text-muted);
    margin-top: .2rem;
}
.bcv-born-badge {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .4rem .85rem;
    background: var(--cv-green-dim);
    border: 1px solid var(--cv-green-border);
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--cv-green-bright);
    flex-shrink: 0;
}

/* ── Form layout ─────────────────────────────────── */
.bcv-form { display: flex; flex-direction: column; flex: 1; }
.bcv-body  { padding: 2rem 2rem 1rem; display: flex; flex-direction: column; gap: 1.5rem; }

/* ── Cards ───────────────────────────────────────── */
.bcv-card {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 10px;
    padding: 1.5rem;
}

/* ── Section heading ─────────────────────────────── */
.bcv-section-head {
    display: flex;
    align-items: center;
    gap: .85rem;
    margin-bottom: 1.25rem;
}
.bcv-num {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .82rem;
    font-weight: 800;
    color: #fff;
}
.bcv-section-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    margin: 0;
}

/* ── Grid ────────────────────────────────────────── */
.bcv-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem 1.25rem;
    align-items: start;
}
.bcv-f-sm   { grid-column: span 1; }
.bcv-f-md   { grid-column: span 2; }
.bcv-f-full { grid-column: 1 / -1; }
.bcv-field  { display: flex; flex-direction: column; gap: .4rem; }
.bcv-field-checkbox { justify-content: flex-end; padding-bottom: .25rem; }

/* ── Labels & errors ─────────────────────────────── */
.bcv-label {
    font-size: .75rem;
    font-weight: 600;
    color: var(--cv-text-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.bcv-error { font-size: .78rem; color: var(--cv-danger-text, #E07070); margin-top: 2px; }
.bcv-hint  { font-size: .75rem; color: var(--cv-text-muted); }

/* ── Inputs ──────────────────────────────────────── */
.bcv-input, .bcv-textarea {
    width: 100%;
    padding: .5rem .75rem;
    background: var(--cv-bg);
    border: 1px solid var(--cv-border);
    border-radius: 7px;
    color: var(--cv-text-primary);
    font-size: .875rem;
    outline: none;
    font-family: inherit;
    transition: border-color .15s, box-shadow .15s;
}
.bcv-input:focus, .bcv-textarea:focus {
    border-color: var(--cv-green-bright);
    box-shadow: 0 0 0 3px var(--cv-green-dim);
}
.bcv-input-error { border-color: var(--cv-danger-text, #E07070) !important; }
.bcv-textarea { resize: vertical; min-height: 80px; }

.bcv-select {
    width: 100%;
    padding: .5rem .75rem;
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border);
    border-radius: 7px;
    color: var(--cv-text-primary);
    font-size: .875rem;
    outline: none;
    font-family: inherit;
    transition: border-color .15s;
}
.bcv-select:focus  { border-color: var(--cv-green-bright); box-shadow: 0 0 0 3px var(--cv-green-dim); }
.bcv-select:disabled { opacity: .45; cursor: not-allowed; }

/* Input group (suffix) */
.bcv-input-group   { display: flex; align-items: stretch; }
.bcv-input-suffix  {
    padding: .5rem .65rem;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-left: none;
    border-radius: 0 7px 7px 0;
    font-size: .78rem;
    font-weight: 600;
    color: var(--cv-text-muted);
    display: flex;
    align-items: center;
    white-space: nowrap;
}
.bcv-input-suffixed { border-radius: 7px 0 0 7px; }
[dir="rtl"] .bcv-input-suffix  { border-left: 1px solid var(--cv-border); border-right: none; border-radius: 7px 0 0 7px; }
[dir="rtl"] .bcv-input-suffixed { border-radius: 0 7px 7px 0; }

/* ── Checkbox ────────────────────────────────────── */
.bcv-checkbox-label {
    display: flex;
    align-items: center;
    gap: .6rem;
    cursor: pointer;
    font-size: .875rem;
    color: var(--cv-text-primary);
    user-select: none;
}
.bcv-checkbox {
    width: 20px;
    height: 20px;
    border-radius: 5px;
    border: 2px solid var(--cv-border);
    background: var(--cv-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
    flex-shrink: 0;
}
.bcv-checkbox--checked {
    background: var(--cv-green-bright);
    border-color: var(--cv-green-bright);
    color: #fff;
}

/* ── Photo ───────────────────────────────────────── */
.bcv-photo-row {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.bcv-photo-preview {
    width: 96px;
    height: 96px;
    border-radius: 10px;
    object-fit: cover;
    border: 2px solid var(--cv-border);
}
.bcv-photo-placeholder {
    width: 96px;
    height: 96px;
    border-radius: 10px;
    background: var(--cv-bg);
    border: 2px dashed var(--cv-border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--cv-text-muted);
    flex-shrink: 0;
}
.bcv-photo-controls {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}

/* ── Buttons ─────────────────────────────────────── */
.bcv-btn {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem 1.1rem;
    border-radius: 8px;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .15s;
    text-decoration: none;
    white-space: nowrap;
    font-family: inherit;
}
.bcv-btn-amber {
    background: var(--cv-amber);
    color: #1a0e00;
    box-shadow: 0 0 10px rgba(232,160,48,.25);
}
.bcv-btn-amber:hover:not(:disabled) {
    filter: brightness(1.1);
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(232,160,48,.35);
}
.bcv-btn-amber:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.bcv-btn-ghost {
    background: transparent;
    color: var(--cv-text-muted);
    border: 1px solid var(--cv-border);
}
.bcv-btn-ghost:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }
.bcv-btn-sm   { padding: .4rem .8rem; font-size: .8rem; }
.bcv-btn-submit { min-width: 140px; justify-content: center; }
.bcv-upload-btn { cursor: pointer; position: relative; }
.bcv-file-hidden {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}

/* ── Footer ──────────────────────────────────────── */
.bcv-footer {
    background: var(--cv-bg-card);
    border-top: 1px solid var(--cv-border);
    padding: 1rem 2rem;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .75rem;
}

/* ── Spinner ─────────────────────────────────────── */
@keyframes bcv-spin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────── */
@media (max-width: 768px) {
    .bcv-body   { padding: 1rem; }
    .bcv-footer { padding: 1rem; }
    .bcv-grid   { grid-template-columns: 1fr; }
    .bcv-f-sm, .bcv-f-md, .bcv-f-full { grid-column: span 1; }
    .bcv-hero   { padding: 1.25rem 1rem; }
    .bcv-title  { font-size: 1.25rem; }
}
@media (max-width: 480px) {
    .bcv-photo-row { flex-direction: column; align-items: flex-start; }
}
</style>