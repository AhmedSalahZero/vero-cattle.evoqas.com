<script setup>
/**
 * Lots/CreateEdit.vue — VERO Cattle Management
 * ─────────────────────────────────────────────────────────────────
 * BILINGUAL FORM RULE — FULLY APPLIED:
 *   - Every label, title, subtitle, placeholder switches on locale
 *   - notes_en visible in EN mode  (v-show, v-model, always in useForm)
 *   - notes_ar visible in AR mode  (v-show, v-model, always in useForm)
 *   - NEVER type="hidden" — v-show="false" keeps v-model active
 *   - Both always submitted regardless of active locale
 * ─────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    lot:      { type: Object, default: null },
    farms:    { type: Array,  default: () => [] },
})

const { locale, isRtl, n } = useLocale()
const isEdit = computed(() => !!props.lot)

// ── All labels / text driven by locale ───────────────────────────
const txt = computed(() => ({
    // Hero
    eyebrow:  locale.value === 'ar' ? 'إدارة الماشية'       : 'Livestock Management',
    heroTitle: isEdit.value
        ? (locale.value === 'ar' ? 'تعديل المجموعة'  : 'Edit Lot')
        : (locale.value === 'ar' ? 'مجموعة جديدة'    : 'New Lot'),
    back:     locale.value === 'ar' ? 'عودة'               : 'Back',

    // Preview pill
    previewFallback: locale.value === 'ar' ? 'مجموعة جديدة' : 'New Lot',

    // Section 1
    s1Title:  locale.value === 'ar' ? 'بيانات المجموعة'     : 'Lot Identity',
    s1Sub:    locale.value === 'ar' ? 'الاسم والرقم المرجعي': 'Name and reference number',
    nameLabel:locale.value === 'ar' ? 'اسم المجموعة *'      : 'Lot Name *',
    namePh:   locale.value === 'ar' ? 'مثال: دورة صيف 2025' : 'e.g. Summer 2025 Batch',
    numLabel: locale.value === 'ar' ? 'الرقم المرجعي'       : 'Lot Number',
    numPh:    locale.value === 'ar' ? 'مثال: LOT-2025-001'  : 'e.g. LOT-2025-001',

    // Section 2
    s2Title:  locale.value === 'ar' ? 'الموقع'              : 'Location',
    s2Sub:    locale.value === 'ar' ? 'المزرعة والحظيرة'    : 'Farm and pen assignment',
    farmLabel:locale.value === 'ar' ? 'المزرعة *'           : 'Farm *',
    farmPh:   locale.value === 'ar' ? '— اختر المزرعة —'   : '— Select Farm —',
    penLabel: locale.value === 'ar' ? 'الحظيرة / القسم'     : 'Pen / Enclosure',
    penPh:    locale.value === 'ar' ? 'مثال: حظيرة أ - 3'  : 'e.g. Pen A-3',

    // Section 3
    s3Title:  locale.value === 'ar' ? 'الجدول الزمني'       : 'Schedule',
    s3Sub:    locale.value === 'ar' ? 'تاريخ البدء وهدف الذبح' : 'Start date and slaughter target',
    startLabel: locale.value === 'ar' ? 'تاريخ البدء *'     : 'Start Date *',
    targetLabel:locale.value === 'ar' ? 'هدف الذبح (اختياري)': 'Target Slaughter Date',
    targetHint: locale.value === 'ar'
        ? 'يُستخدم لحساب الأيام المتبقية في لوحة المتابعة'
        : 'Used to calculate remaining days on the dashboard',
    statusLabel:locale.value === 'ar' ? 'الحالة'            : 'Status',

    // Section 4
    s4Title:  locale.value === 'ar' ? 'ملاحظات'             : 'Notes',
    s4Sub:    locale.value === 'ar' ? 'ملاحظات إضافية عن هذه المجموعة' : 'Additional notes about this lot',
    notesEnLabel: 'Notes (English)',
    notesEnPh:    'Any relevant notes about this lot...',
    notesArLabel: 'ملاحظات (عربي)',
    notesArPh:    'أي ملاحظات ذات صلة بهذه المجموعة...',
    bilingualNote: locale.value === 'ar'
        ? 'يتم حفظ الملاحظات بكلتا اللغتين تلقائياً'
        : 'Notes are saved in both languages automatically',

    // Status options
    statusActive:    locale.value === 'ar' ? 'نشط'    : 'Active',
    statusCompleted: locale.value === 'ar' ? 'مكتمل'  : 'Completed',
    statusArchived:  locale.value === 'ar' ? 'مؤرشف'  : 'Archived',

    // Footer
    cancel:  locale.value === 'ar' ? 'إلغاء'           : 'Cancel',
    saving:  locale.value === 'ar' ? 'جارٍ الحفظ...'   : 'Saving...',
    submit:  isEdit.value
        ? (locale.value === 'ar' ? 'حفظ التعديلات' : 'Save Changes')
        : (locale.value === 'ar' ? 'إنشاء المجموعة': 'Create Lot'),
}))

// ── Form state ────────────────────────────────────────────────────
// Both notes_en AND notes_ar always present — bilingual rule §Vue
const form = useForm({
    farm_id:               props.lot?.farm_id               ?? '',
    name:                  props.lot?.name                   ?? '',
    lot_number:            props.lot?.lot_number             ?? '',
    start_date:            props.lot?.start_date             ?? '',
    target_slaughter_date: props.lot?.target_slaughter_date  ?? '',
    pen:                   props.lot?.pen                    ?? '',
    status:                props.lot?.status                 ?? 'active',
    notes_en:              props.lot?.notes_en               ?? '',
    notes_ar:              props.lot?.notes_ar               ?? '',
})

// ── Live preview ──────────────────────────────────────────────────
const previewName   = computed(() => form.name      || txt.value.previewFallback)
const previewNumber = computed(() => form.lot_number ? `#${form.lot_number}` : '')

// ── Submit ────────────────────────────────────────────────────────
function submit() {
    if (isEdit.value) {
        form.put(route('lots.update', props.lot.id))
    } else {
        form.post(route('lots.store'))
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'" class="createedit-root">

            <!-- ═══ HERO ══════════════════════════════════════════ -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow" aria-hidden="true" />
                <div class="hero-inner">
                    <div class="hero-left">
                        <div class="hero-icon-wrap">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                                <polyline points="9 22 9 12 15 12 15 22"/>
                            </svg>
                        </div>
                        <div>
                            <div class="cv-page-hero-eyebrow">{{ txt.eyebrow }}</div>
                            <div class="cv-page-hero-title">{{ txt.heroTitle }}</div>
                            <!-- Live preview pill -->
                            <div class="hero-preview-pill">
                                <span class="preview-name">{{ previewName }}</span>
                                <span v-if="previewNumber" class="preview-number">{{ previewNumber }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="cv-page-hero-actions">
                        <Link :href="route('lots.index')" class="cv-btn cv-btn-ghost">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                 class="btn-icon">
                                <line x1="19" y1="12" x2="5" y2="12"/>
                                <polyline points="12 19 5 12 12 5"/>
                            </svg>
                            {{ txt.back }}
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ═══ FORM ══════════════════════════════════════════ -->
            <div class="cv-page">
                <form @submit.prevent="submit" class="form-layout">

                    <!-- ─── SECTION 1: Identity ──────────────────── -->
                    <div class="cv-card section-card">
                        <div class="section-header">
                            <div class="section-badge badge-1">1</div>
                            <div>
                                <div class="section-title">{{ txt.s1Title }}</div>
                                <div class="section-sub">{{ txt.s1Sub }}</div>
                            </div>
                        </div>
                        <div class="fields-grid">
                            <!-- Lot name -->
                            <div class="cv-field">
                                <label class="cv-label" for="lot-name">{{ txt.nameLabel }}</label>
                                <input
                                    id="lot-name"
                                    v-model="form.name"
                                    type="text"
                                    class="cv-input"
                                    :class="{ 'cv-input-error': form.errors.name }"
                                    :placeholder="txt.namePh"
                                    required
                                />
                                <p v-if="form.errors.name" class="cv-field-error">{{ form.errors.name }}</p>
                            </div>
                            <!-- Lot number -->
                            <div class="cv-field">
                                <label class="cv-label" for="lot-number">{{ txt.numLabel }}</label>
                                <input
                                    id="lot-number"
                                    v-model="form.lot_number"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="txt.numPh"
                                />
                                <p v-if="form.errors.lot_number" class="cv-field-error">{{ form.errors.lot_number }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- ─── SECTION 2: Location ───────────────────── -->
                    <div class="cv-card section-card">
                        <div class="section-header">
                            <div class="section-badge badge-2">2</div>
                            <div>
                                <div class="section-title">{{ txt.s2Title }}</div>
                                <div class="section-sub">{{ txt.s2Sub }}</div>
                            </div>
                        </div>
                        <div class="fields-grid">
                            <!-- Farm -->
                            <div class="cv-field">
                                <label class="cv-label" for="lot-farm">{{ txt.farmLabel }}</label>
                                <select
                                    id="lot-farm"
                                    v-model="form.farm_id"
                                    class="cv-select"
                                    :class="{ 'cv-input-error': form.errors.farm_id }"
                                    required
                                >
                                    <option value="" disabled>{{ txt.farmPh }}</option>
                                    <option v-for="farm in farms" :key="farm.id" :value="farm.id">
                                        {{ n(farm) }}
                                    </option>
                                </select>
                                <p v-if="form.errors.farm_id" class="cv-field-error">{{ form.errors.farm_id }}</p>
                            </div>
                            <!-- Pen -->
                            <div class="cv-field">
                                <label class="cv-label" for="lot-pen">{{ txt.penLabel }}</label>
                                <input
                                    id="lot-pen"
                                    v-model="form.pen"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="txt.penPh"
                                />
                                <p v-if="form.errors.pen" class="cv-field-error">{{ form.errors.pen }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- ─── SECTION 3: Schedule ───────────────────── -->
                    <div class="cv-card section-card">
                        <div class="section-header">
                            <div class="section-badge badge-3">3</div>
                            <div>
                                <div class="section-title">{{ txt.s3Title }}</div>
                                <div class="section-sub">{{ txt.s3Sub }}</div>
                            </div>
                        </div>
                        <div class="fields-grid">
                            <!-- Start date -->
                            <div class="cv-field">
                                <label class="cv-label" for="lot-start">{{ txt.startLabel }}</label>
                                <input
                                    id="lot-start"
                                    v-model="form.start_date"
                                    type="date"
                                    class="cv-input"
                                    :class="{ 'cv-input-error': form.errors.start_date }"
                                    required
                                />
                                <p v-if="form.errors.start_date" class="cv-field-error">{{ form.errors.start_date }}</p>
                            </div>
                            <!-- Target slaughter date -->
                            <div class="cv-field">
                                <label class="cv-label" for="lot-target">{{ txt.targetLabel }}</label>
                                <input
                                    id="lot-target"
                                    v-model="form.target_slaughter_date"
                                    type="date"
                                    class="cv-input"
                                    :class="{ 'cv-input-error': form.errors.target_slaughter_date }"
                                />
                                <p class="cv-field-hint">{{ txt.targetHint }}</p>
                                <p v-if="form.errors.target_slaughter_date" class="cv-field-error">{{ form.errors.target_slaughter_date }}</p>
                            </div>
                            <!-- Status — edit mode only -->
                            <div class="cv-field" v-if="isEdit">
                                <label class="cv-label" for="lot-status">{{ txt.statusLabel }}</label>
                                <select id="lot-status" v-model="form.status" class="cv-select">
                                    <option value="active">{{ txt.statusActive }}</option>
                                    <option value="completed">{{ txt.statusCompleted }}</option>
                                    <option value="archived">{{ txt.statusArchived }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ─── SECTION 4: Notes (BILINGUAL FORM RULE) ── -->
                    <!--
                        BILINGUAL FORM RULE IMPLEMENTATION:
                        ─────────────────────────────────────────────
                        notes_en → v-show="locale === 'en'"  +  v-model  (always in useForm)
                        notes_ar → v-show="locale === 'ar'"  +  v-model  (always in useForm)
                        Both fields always submitted. v-show keeps DOM + v-model active.
                        NEVER type="hidden" — Vue 3 does not sync v-model on type="hidden".
                    -->
                    <div class="cv-card section-card">
                        <div class="section-header">
                            <div class="section-badge badge-4">4</div>
                            <div>
                                <div class="section-title">{{ txt.s4Title }}</div>
                                <div class="section-sub">{{ txt.s4Sub }}</div>
                            </div>
                        </div>
                        <div class="fields-grid fields-grid-full">

                            <!-- English notes — visible in EN mode only -->
                            <div class="cv-field" v-show="locale === 'en'">
                                <label class="cv-label" for="notes-en">{{ txt.notesEnLabel }}</label>
                                <textarea
                                    id="notes-en"
                                    v-model="form.notes_en"
                                    class="cv-textarea"
                                    rows="4"
                                    :placeholder="txt.notesEnPh"
                                    dir="ltr"
                                />
                                <p v-if="form.errors.notes_en" class="cv-field-error">{{ form.errors.notes_en }}</p>
                            </div>

                            <!-- Arabic notes — visible in AR mode only -->
                            <div class="cv-field" v-show="locale === 'ar'">
                                <label class="cv-label" for="notes-ar">{{ txt.notesArLabel }}</label>
                                <textarea
                                    id="notes-ar"
                                    v-model="form.notes_ar"
                                    class="cv-textarea"
                                    rows="4"
                                    :placeholder="txt.notesArPh"
                                    dir="rtl"
                                />
                                <p v-if="form.errors.notes_ar" class="cv-field-error">{{ form.errors.notes_ar }}</p>
                            </div>

                            <!-- Bilingual indicator -->
                            <div class="bilingual-indicator">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="2" y1="12" x2="22" y2="12"/>
                                    <path d="M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/>
                                </svg>
                                <span>{{ txt.bilingualNote }}</span>
                            </div>

                        </div>
                    </div>

                    <!-- ─── FORM FOOTER ───────────────────────────── -->
                    <div class="form-footer">
                        <Link :href="route('lots.index')" class="cv-btn cv-btn-ghost">
                            {{ txt.cancel }}
                        </Link>
                        <button type="submit" class="cv-btn cv-btn-amber" :disabled="form.processing">
                            <svg v-if="form.processing" class="btn-spinner" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                 class="btn-icon">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            {{ form.processing ? txt.saving : txt.submit }}
                        </button>
                    </div>

                </form>
            </div>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Root ─────────────────────────────────────────────────────── */
.createedit-root {
    min-height: 100vh;
    background: var(--cv-bg-base);
    color: var(--cv-text-primary);
}

/* ── Hero ─────────────────────────────────────────────────────── */
.cv-page-hero {
    position: relative;
    overflow: hidden;
    padding: 2.5rem 2rem 1.75rem;
    background: linear-gradient(135deg, var(--cv-bg-card) 0%, var(--cv-bg-card-alt) 100%);
    border-bottom: 1px solid var(--cv-border);
}

.cv-page-hero-texture {
    position: absolute;
    inset: 0;
    background-image: radial-gradient(var(--cv-border) 1px, transparent 1px);
    background-size: 24px 24px;
    opacity: 0.35;
    pointer-events: none;
}

.hero-inner {
    position: relative;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.hero-left {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.hero-icon-wrap {
    width: 3rem;
    height: 3rem;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, var(--cv-green-bright), var(--cv-green-dim));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
}

.hero-icon-wrap svg { width: 1.5rem; height: 1.5rem; }

.cv-page-hero-eyebrow {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--cv-amber);
    margin-bottom: 0.2rem;
}

.cv-page-hero-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cv-text-primary);
    line-height: 1.2;
}

.hero-preview-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
    background: var(--cv-bg-base);
    border: 1px solid var(--cv-border);
    border-radius: 999px;
    padding: 0.25rem 0.75rem;
    font-size: 0.8rem;
}

.preview-name   { color: var(--cv-text-primary); font-weight: 500; }
.preview-number { color: var(--cv-blue-text); font-weight: 600; }

.cv-page-hero-actions {
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
    padding-top: 0.25rem;
}

/* ── Page / form ─────────────────────────────────────────────── */
.cv-page {
    padding: 1.5rem 2rem;
}

.form-layout {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    width: 100%;
    max-width: 860px;
}

/* ── Section card ────────────────────────────────────────────── */
.cv-card {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 0.75rem;
    padding: 1.5rem;
}

.section-card {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.section-header {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
}

.section-badge {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}

.badge-1 { background: linear-gradient(135deg, var(--cv-green-bright), var(--cv-green-dim)); }
.badge-2 { background: linear-gradient(135deg, var(--cv-amber), #c97d10); }
.badge-3 { background: linear-gradient(135deg, var(--cv-blue-text), #1a6fb5); }
.badge-4 { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }

.section-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--cv-text-primary);
}

.section-sub {
    font-size: 0.8rem;
    color: var(--cv-text-muted);
    margin-top: 0.15rem;
}

/* ── Fields grid ─────────────────────────────────────────────── */
.fields-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.fields-grid-full {
    grid-template-columns: 1fr;
}

/* ── Form fields ─────────────────────────────────────────────── */
.cv-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.cv-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--cv-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.cv-input,.cv-textarea {
    background: var(--cv-bg-base);
    border: 1px solid var(--cv-border);
    border-radius: 0.5rem;
    padding: 0.55rem 0.85rem;
    font-size: 0.875rem;
    color: var(--cv-text-primary);
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    width: 100%;
}

.cv-select{
width: 100%; padding: 0.5rem 0.75rem;
    background: var(--cv-bg-base);
    border: 1px solid var(--cv-border);
    border-radius: 7px;
    color: var(--cv-text-primary);
    font-size: 0.875rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    font-family: inherit;

}

.cv-input:focus,
.cv-select:focus,
.cv-textarea:focus {
    border-color: var(--cv-green-bright);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--cv-green-bright) 20%, transparent);
}

.cv-input-error {
    border-color: var(--cv-danger-text) !important;
}

.cv-textarea {
    resize: vertical;
    min-height: 100px;
}

.cv-field-error {
    font-size: 0.78rem;
    color: var(--cv-danger-text);
}

.cv-field-hint {
    font-size: 0.75rem;
    color: var(--cv-text-muted);
}

/* ── Bilingual indicator ─────────────────────────────────────── */
.bilingual-indicator {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--cv-text-muted);
    padding: 0.4rem 0.75rem;
    background: color-mix(in srgb, var(--cv-blue-text) 8%, transparent);
    border: 1px solid color-mix(in srgb, var(--cv-blue-text) 20%, transparent);
    border-radius: 0.5rem;
}

.bilingual-indicator svg {
    width: 0.9rem;
    height: 0.9rem;
    color: var(--cv-blue-text);
    flex-shrink: 0;
}

/* ── Form footer ─────────────────────────────────────────────── */
.form-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1rem 0;
}

/* ── Buttons ─────────────────────────────────────────────────── */
.cv-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.55rem 1.25rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all 0.15s;
    white-space: nowrap;
}

.cv-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-icon { width: 1rem; height: 1rem; }

.btn-spinner {
    width: 1rem;
    height: 1rem;
    animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.cv-btn-amber {
    background: var(--cv-amber);
    color: #111;
    box-shadow: 0 0 12px color-mix(in srgb, var(--cv-amber) 40%, transparent);
}
.cv-btn-amber:hover:not(:disabled) { filter: brightness(1.1); transform: translateY(-1px); }

.cv-btn-ghost {
    background: transparent;
    color: var(--cv-text-muted);
    border: 1px solid var(--cv-border);
}
.cv-btn-ghost:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 640px) {
    .cv-page-hero  { padding: 1.5rem 1rem 1.25rem; }
    .cv-page       { padding: 1rem; }
    .fields-grid   { grid-template-columns: 1fr; }
    .hero-inner    { flex-direction: column; }
    .cv-page-hero-title { font-size: 1.25rem; }
    .form-footer   { flex-direction: column-reverse; align-items: stretch; }
    .form-footer .cv-btn { justify-content: center; }
}
</style>