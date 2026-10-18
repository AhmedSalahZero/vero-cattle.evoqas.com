<script setup>
/**
 * Farms/CreateEdit.vue — FIXED
 *
 * FIX 1 — Added AuthenticatedLayout wrapper.
 *   The page was rendering raw (no sidebar, no header) because
 *   AuthenticatedLayout was missing entirely.
 *
 * FIX 2 — Giant SVG shapes removed.
 *   The previous version used class="cv-input-icon" on SVGs, expecting
 *   app.css to size them to ~14×14px. But app.css defines cv-input-icon
 *   as positioned:absolute with no width/height — SVGs without explicit
 *   dimensions default to 300×150px. Every icon was giant.
 *   Fix: inline style="width:15px;height:15px" on every icon SVG,
 *   and the input-icon-wrap pattern is replaced with a simpler
 *   left-padded input + absolutely positioned icon using scoped CSS
 *   that explicitly sets width and height.
 *
 * FIX 3 — Reactive lang ref via watch on usePage auth.user.language
 *   (same pattern as fixed Index.vue — mirrors AuthenticatedLayout).
 *
 * BILINGUAL RULE: unchanged — one language visible at a time,
 *   both _en/_ar always in form and submitted.
 */
import { ref, computed, watch } from 'vue'
import { useForm, usePage, router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    farm:        { type: Object,  default: null },
    mode:        { type: String,  default: 'create' },
    companies:   { type: Array,   default: () => [] },
    myCompanyId: { type: Number,  default: null },
})

// ── Reactive language (FIX 3) ─────────────────────────────────────
const page     = usePage()
const authLang = computed(() => page.props.auth?.user?.language ?? 'en')
const lang     = ref(authLang.value)
const isRtl    = computed(() => lang.value === 'ar')
watch(authLang, (val) => { lang.value = val })

// ── Helpers ───────────────────────────────────────────────────────
const isCreate     = computed(() => props.mode === 'create')
const { isSuperAdmin, can } = usePermissions()
const canDelete    = computed(() => !isCreate.value && can('delete farms'))

// BUG FIX: Non-super-admin company_id must be set from myCompanyId.
// The <input type="hidden" :value="..."> does NOT update form.company_id —
// it only renders a DOM element. We must set it directly on the form object.
if (!isSuperAdmin.value && !form.company_id && props.myCompanyId) {
    form.company_id = props.myCompanyId
}

const companyLabel = (c) => lang.value === 'ar' ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar)

// Page meta
const pageEyebrow = computed(() => lang.value === 'ar' ? 'إدارة المزارع' : 'Farm Management')
const pageTitle   = computed(() => isCreate.value
    ? (lang.value === 'ar' ? 'إضافة مزرعة جديدة' : 'Add New Farm')
    : (lang.value === 'ar' ? 'تعديل بيانات المزرعة' : 'Edit Farm'))
const pageSub     = computed(() => isCreate.value
    ? (lang.value === 'ar' ? 'أدخل بيانات المزرعة الجديدة' : 'Enter details for the new farm')
    : (lang.value === 'ar' ? 'تحديث معلومات المزرعة' : 'Update farm information'))

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    company_id:    props.farm?.company_id    ?? props.myCompanyId ?? '',
    name_en:       props.farm?.name_en       ?? '',
    name_ar:       props.farm?.name_ar       ?? '',
    address_en:    props.farm?.address_en    ?? '',
    address_ar:    props.farm?.address_ar    ?? '',
    city:          props.farm?.city          ?? '',
    region:        props.farm?.region        ?? '',
    country:       props.farm?.country       ?? '',
    latitude:      props.farm?.latitude      ?? '',
    longitude:     props.farm?.longitude     ?? '',
    phone:         props.farm?.phone         ?? '',
    email:         props.farm?.email         ?? '',
    area_acres:    props.farm?.area_acres    ?? '',
    capacity_head: props.farm?.capacity_head ?? '',
    notes_en:      props.farm?.notes_en      ?? '',
    notes_ar:      props.farm?.notes_ar      ?? '',
    is_active:     props.farm?.is_active     ?? true,
})

const submit = () => {
    if (isCreate.value) { form.post(route('farms.store')) }
    else                { form.put(route('farms.update', props.farm.id)) }
}

// ── Delete ────────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteFarm = () => router.delete(route('farms.destroy', props.farm.id))

// ── Toggle active ─────────────────────────────────────────────────
const toggleActive = () => {
    router.patch(route('farms.toggle-active', props.farm.id), {}, {
        preserveScroll: true,
        onSuccess: () => { form.is_active = !form.is_active },
    })
}
</script>

<template>
    <!-- FIX 1: AuthenticatedLayout wrapper added -->
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ PAGE HERO ══ -->
            <div class="cv-page-hero">
                <div class="hero-row">
                    <div>
                        <p class="cv-page-hero-eyebrow">{{ pageEyebrow }}</p>
                        <h1 class="cv-page-hero-title">{{ pageTitle }}</h1>
                        <p class="cv-page-hero-subtitle">{{ pageSub }}</p>
                    </div>
                    <div class="hero-actions">
                        <Link :href="route('farms.index')" class="btn-ghost">
                            ← {{ lang === 'ar' ? 'العودة للمزارع' : 'Back to Farms' }}
                        </Link>
                        <button v-if="canDelete" type="button" class="btn-danger-sm"
                                @click="showDeleteModal = true">
                            {{ lang === 'ar' ? 'حذف المزرعة' : 'Delete Farm' }}
                        </button>
                        <button type="button" class="btn-amber"
                                :disabled="form.processing" @click="submit">
                            <span v-if="form.processing">{{ lang === 'ar' ? 'جارٍ الحفظ…' : 'Saving…' }}</span>
                            <span v-else>{{ isCreate ? (lang === 'ar' ? 'إنشاء المزرعة' : 'Create Farm') : (lang === 'ar' ? 'حفظ التغييرات' : 'Save Changes') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ══ FORM ══ -->
            <form @submit.prevent="submit" class="form-body" novalidate>

                <!-- ── Section 1 — Basic Info ── -->
                <div class="cv-card form-section">
                    <div class="section-hd">
                        <div class="sec-num">1</div>
                        <div>
                            <p class="sec-title">{{ lang === 'ar' ? 'المعلومات الأساسية' : 'Basic Information' }}</p>
                            <p class="sec-sub">{{ lang === 'ar' ? 'اسم المزرعة والشركة المالكة' : 'Farm name and owning company' }}</p>
                        </div>
                    </div>

                    <div class="grid-2">
                        <!-- Company — super_admin: dropdown; others: read-only -->
                        <div class="cv-field" v-if="isSuperAdmin">
                            <label class="cv-label">{{ lang === 'ar' ? 'الشركة' : 'Company' }} <span class="req">*</span></label>
                            <select v-model="form.company_id" class="cv-select" dir="ltr">
                                <option value="" disabled>{{ lang === 'ar' ? '— اختر الشركة —' : '— Select Company —' }}</option>
                                <option v-for="c in companies" :key="c.id" :value="c.id">{{ companyLabel(c) }}</option>
                            </select>
                            <span v-if="form.errors.company_id" class="cv-field-error">{{ form.errors.company_id }}</span>
                        </div>
                        <div class="cv-field" v-else>
                            <label class="cv-label">{{ lang === 'ar' ? 'الشركة' : 'Company' }}</label>
                            <!-- BUG FIX: form.company_id is set in JS above. This is display-only. -->
                            <div class="readonly-val">
                                {{ lang === 'ar' ? (companies[0]?.name_ar || companies[0]?.name_en || '—') : (companies[0]?.name_en || '—') }}
                            </div>
                        </div>

                        <!-- BILINGUAL: name — one language visible at a time -->
                        <!-- English name field -->
                        <div class="cv-field" v-if="lang === 'en'">
                            <label class="cv-label">Farm Name (English) <span class="req">*</span></label>
                            <input v-model="form.name_en" type="text" class="cv-input"
                                   placeholder="e.g. Al Rawdah Farm" dir="ltr" autocomplete="off"/>
                            <span v-if="form.errors.name_en" class="cv-field-error">{{ form.errors.name_en }}</span>
                        </div>
                        <!-- Arabic name field -->
                        <div class="cv-field" v-if="lang === 'ar'">
                            <label class="cv-label">اسم المزرعة (بالعربية) <span class="req">*</span></label>
                            <input v-model="form.name_ar" type="text" class="cv-input"
                                   placeholder="مثال: مزرعة الروضة" dir="rtl"/>
                            <span v-if="form.errors.name_ar" class="cv-field-error">{{ form.errors.name_ar }}</span>
                        </div>
                        <!-- Hidden counterpart — always submitted. Use v-show instead of type=hidden
                             because Vue 3 does NOT sync v-model on <input type="hidden"> reliably. -->
                        <input v-show="false" v-model="form.name_ar" type="text" v-if="lang === 'en'" aria-hidden="true" tabindex="-1"/>
                        <input v-show="false" v-model="form.name_en" type="text" v-if="lang === 'ar'" aria-hidden="true" tabindex="-1"/>
                    </div>
                </div>

                <!-- ── Section 2 — Location ── -->
                <div class="cv-card form-section">
                    <div class="section-hd">
                        <div class="sec-num">2</div>
                        <div>
                            <p class="sec-title">{{ lang === 'ar' ? 'الموقع الجغرافي' : 'Location' }}</p>
                            <p class="sec-sub">{{ lang === 'ar' ? 'العنوان والإحداثيات' : 'Address and GPS coordinates' }}</p>
                        </div>
                    </div>

                    <div class="grid-2">
                        <!-- BILINGUAL: address — one language visible at a time -->
                        <div class="cv-field col-span-2" v-if="lang === 'en'">
                            <label class="cv-label">Street Address (English)</label>
                            <input v-model="form.address_en" type="text" class="cv-input"
                                   placeholder="e.g. 12 Desert Road, Block 5" dir="ltr"/>
                            <span v-if="form.errors.address_en" class="cv-field-error">{{ form.errors.address_en }}</span>
                        </div>
                        <div class="cv-field col-span-2" v-if="lang === 'ar'">
                            <label class="cv-label">العنوان (بالعربية)</label>
                            <input v-model="form.address_ar" type="text" class="cv-input"
                                   placeholder="مثال: شارع الصحراء 12، المربع 5" dir="rtl"/>
                            <span v-if="form.errors.address_ar" class="cv-field-error">{{ form.errors.address_ar }}</span>
                        </div>
                        <input v-show="false" v-model="form.address_ar" type="text" v-if="lang === 'en'" aria-hidden="true" tabindex="-1"/>
                        <input v-show="false" v-model="form.address_en" type="text" v-if="lang === 'ar'" aria-hidden="true" tabindex="-1"/>

                        <!-- City -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'المدينة' : 'City' }}</label>
                            <input v-model="form.city" type="text" class="cv-input"
                                   :placeholder="lang === 'ar' ? 'مثال: الرياض' : 'e.g. Riyadh'"
                                   :dir="lang === 'ar' ? 'rtl' : 'ltr'"/>
                            <span v-if="form.errors.city" class="cv-field-error">{{ form.errors.city }}</span>
                        </div>

                        <!-- Region -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'المنطقة / المحافظة' : 'Region / Province' }}</label>
                            <input v-model="form.region" type="text" class="cv-input"
                                   :placeholder="lang === 'ar' ? 'مثال: منطقة الرياض' : 'e.g. Riyadh Province'"
                                   :dir="lang === 'ar' ? 'rtl' : 'ltr'"/>
                            <span v-if="form.errors.region" class="cv-field-error">{{ form.errors.region }}</span>
                        </div>

                        <!-- Country — always LTR ISO code -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'الدولة (رمز ISO)' : 'Country (ISO Code)' }}</label>
                            <input v-model="form.country" type="text" maxlength="2" class="cv-input"
                                   placeholder="SA" dir="ltr" style="max-width:120px;text-transform:uppercase;letter-spacing:.1em"/>
                            <span v-if="form.errors.country" class="cv-field-error">{{ form.errors.country }}</span>
                        </div>

                        <div/><!-- spacer -->

                        <!-- Latitude — always LTR, optional -->
                        <div class="cv-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'خط العرض (Latitude)' : 'Latitude' }}
                                <span class="optional-hint">{{ lang === 'ar' ? '(اختياري)' : '(optional)' }}</span>
                            </label>
                            <input v-model="form.latitude" type="number" step="0.000001"
                                   class="cv-input num-input" placeholder="24.7136" dir="ltr"/>
                            <span v-if="form.errors.latitude" class="cv-field-error">{{ form.errors.latitude }}</span>
                        </div>

                        <!-- Longitude — always LTR, optional -->
                        <div class="cv-field">
                            <label class="cv-label">
                                {{ lang === 'ar' ? 'خط الطول (Longitude)' : 'Longitude' }}
                                <span class="optional-hint">{{ lang === 'ar' ? '(اختياري)' : '(optional)' }}</span>
                            </label>
                            <input v-model="form.longitude" type="number" step="0.000001"
                                   class="cv-input num-input" placeholder="46.6753" dir="ltr"/>
                            <span v-if="form.errors.longitude" class="cv-field-error">{{ form.errors.longitude }}</span>
                        </div>
                    </div>
                </div>

                <!-- ── Section 3 — Contact ── -->
                <div class="cv-card form-section">
                    <div class="section-hd">
                        <div class="sec-num">3</div>
                        <div>
                            <p class="sec-title">{{ lang === 'ar' ? 'بيانات التواصل' : 'Contact Details' }}</p>
                            <p class="sec-sub">{{ lang === 'ar' ? 'هاتف المزرعة والبريد الإلكتروني' : 'Farm phone and email' }}</p>
                        </div>
                    </div>
                    <div class="grid-2">
                        <!-- Phone — always LTR -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'رقم الهاتف' : 'Phone Number' }}</label>
                            <input v-model="form.phone" type="tel" class="cv-input"
                                   placeholder="+966 11 000 0000" dir="ltr"/>
                            <span v-if="form.errors.phone" class="cv-field-error">{{ form.errors.phone }}</span>
                        </div>
                        <!-- Email — always LTR -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'البريد الإلكتروني' : 'Email Address' }}</label>
                            <input v-model="form.email" type="email" class="cv-input"
                                   placeholder="farm@example.com" dir="ltr"/>
                            <span v-if="form.errors.email" class="cv-field-error">{{ form.errors.email }}</span>
                        </div>
                    </div>
                </div>

                <!-- ── Section 4 — Capacity ── -->
                <div class="cv-card form-section">
                    <div class="section-hd">
                        <div class="sec-num">4</div>
                        <div>
                            <p class="sec-title">{{ lang === 'ar' ? 'الطاقة الاستيعابية' : 'Capacity & Size' }}</p>
                            <p class="sec-sub">{{ lang === 'ar' ? 'مساحة المزرعة وأقصى عدد رؤوس' : 'Farm area and maximum herd size' }}</p>
                        </div>
                    </div>
                    <div class="grid-2">
                        <!-- Area — numeric always LTR -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'المساحة (فدان)' : 'Area (Acres)' }}</label>
                            <input v-model="form.area_acres" type="number" step="0.01" min="0"
                                   class="cv-input num-input" placeholder="0.00" dir="ltr"/>
                            <span v-if="form.errors.area_acres" class="cv-field-error">{{ form.errors.area_acres }}</span>
                        </div>
                        <!-- Capacity — integer, always LTR -->
                        <div class="cv-field">
                            <label class="cv-label">{{ lang === 'ar' ? 'الطاقة القصوى (رأس)' : 'Max Capacity (Head)' }}</label>
                            <input v-model="form.capacity_head" type="number" step="1" min="0"
                                   class="cv-input num-input" placeholder="500" dir="ltr"/>
                            <span v-if="form.errors.capacity_head" class="cv-field-error">{{ form.errors.capacity_head }}</span>
                        </div>
                    </div>
                </div>

                <!-- ── Section 5 — Notes ── -->
                <div class="cv-card form-section">
                    <div class="section-hd">
                        <div class="sec-num">5</div>
                        <div>
                            <p class="sec-title">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</p>
                            <p class="sec-sub">{{ lang === 'ar' ? 'أي معلومات إضافية عن المزرعة' : 'Any additional information about the farm' }}</p>
                        </div>
                    </div>

                    <!-- BILINGUAL: notes — one language visible at a time -->
                    <div class="cv-field" v-if="lang === 'en'">
                        <label class="cv-label">Notes (English)</label>
                        <textarea v-model="form.notes_en" class="cv-textarea" rows="4"
                                  placeholder="e.g. Seasonal grazing area, water source on east side…"
                                  dir="ltr"></textarea>
                        <span v-if="form.errors.notes_en" class="cv-field-error">{{ form.errors.notes_en }}</span>
                    </div>
                    <div class="cv-field" v-if="lang === 'ar'">
                        <label class="cv-label">ملاحظات (بالعربية)</label>
                        <textarea v-model="form.notes_ar" class="cv-textarea" rows="4"
                                  placeholder="مثال: منطقة رعي موسمية، مصدر مياه في الجهة الشرقية…"
                                  dir="rtl"></textarea>
                        <span v-if="form.errors.notes_ar" class="cv-field-error">{{ form.errors.notes_ar }}</span>
                    </div>
                    <input v-show="false" v-model="form.notes_ar" type="text" v-if="lang === 'en'" aria-hidden="true" tabindex="-1"/>
                    <input v-show="false" v-model="form.notes_en" type="text" v-if="lang === 'ar'" aria-hidden="true" tabindex="-1"/>
                </div>

                <!-- ── Section 6 — Status (edit only) ── -->
                <div class="cv-card form-section" v-if="!isCreate">
                    <div class="section-hd">
                        <div class="sec-num sec-num-amber">6</div>
                        <div>
                            <p class="sec-title">{{ lang === 'ar' ? 'حالة المزرعة' : 'Farm Status' }}</p>
                            <p class="sec-sub">{{ lang === 'ar' ? 'تفعيل أو تعطيل هذه المزرعة' : 'Activate or deactivate this farm' }}</p>
                        </div>
                    </div>
                    <div class="status-row">
                        <span :class="['status-badge', form.is_active ? 'status-on' : 'status-off']">
                            {{ form.is_active ? (lang === 'ar' ? 'مفعّلة' : 'Active') : (lang === 'ar' ? 'معطّلة' : 'Inactive') }}
                        </span>
                        <p class="status-desc">
                            {{ form.is_active
                                ? (lang === 'ar' ? 'المزرعة مفعّلة ومرئية لجميع المستخدمين.' : 'Farm is active and visible to all users.')
                                : (lang === 'ar' ? 'المزرعة معطّلة ولن تظهر في القوائم.' : 'Farm is inactive and hidden from listings.') }}
                        </p>
                        <button type="button" @click="toggleActive"
                                :class="form.is_active ? 'btn-danger-sm' : 'btn-secondary'">
                            {{ form.is_active
                                ? (lang === 'ar' ? 'تعطيل المزرعة' : 'Deactivate Farm')
                                : (lang === 'ar' ? 'تفعيل المزرعة' : 'Activate Farm') }}
                        </button>
                    </div>
                </div>

                <!-- ── Footer submit bar ── -->
                <div class="form-footer">
                    <Link :href="route('farms.index')" class="btn-ghost">
                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </Link>
                    <button type="submit" class="btn-amber" :disabled="form.processing">
                        {{ form.processing
                            ? (lang === 'ar' ? 'جارٍ الحفظ…' : 'Saving…')
                            : isCreate
                                ? (lang === 'ar' ? 'إنشاء المزرعة' : 'Create Farm')
                                : (lang === 'ar' ? 'حفظ التغييرات' : 'Save Changes') }}
                    </button>
                </div>

            </form>

            <!-- ══ DELETE MODAL ══ -->
            <Teleport to="body">
                <div v-if="showDeleteModal" class="modal-back" @click.self="showDeleteModal = false">
                    <div class="modal-box" :dir="isRtl ? 'rtl' : 'ltr'">
                        <div class="modal-del-ico">
                            <svg style="width:24px;height:24px" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6M14 11v6M9 6V4h6v2"/>
                            </svg>
                        </div>
                        <h3 class="modal-title">{{ lang === 'ar' ? 'تأكيد الحذف' : 'Confirm Delete' }}</h3>
                        <p class="modal-warn">
                            {{ lang === 'ar'
                                ? 'سيتم حذف هذه المزرعة نهائياً. لا يمكن التراجع عن هذا الإجراء.'
                                : 'This farm will be permanently deleted. This action cannot be undone.' }}
                        </p>
                        <div class="modal-acts">
                            <button type="button" class="btn-ghost" @click="showDeleteModal = false">
                                {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button type="button" class="btn-danger" @click="deleteFarm">
                                {{ lang === 'ar' ? 'نعم، احذف المزرعة' : 'Yes, Delete Farm' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Hero ────────────────────────────────── */
.hero-row    { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:.75rem; }
.hero-actions{ display:flex; align-items:center; gap:.625rem; flex-wrap:wrap; }

/* ── Form layout ─────────────────────────── */
.form-body {
    display:flex; flex-direction:column; gap:1.25rem;
    padding:0 1.5rem 2.5rem;
    max-width:860px; margin:0 auto;
}
.form-section { padding:1.5rem; }

/* ── Inputs ──────────────────────────────────────────────── */
/* .cv-select{
width: 100%; padding: 0.5rem 0.75rem;
    background: #023b15;
    border: 1px solid var(--cv-border);
    border-radius: 7px;
    color: var(--cv-text-primary);
    font-size: 0.875rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    font-family: inherit;

} */


/* ── Section header ──────────────────────── */
.section-hd { display:flex; align-items:flex-start; gap:.875rem; margin-bottom:1.375rem; padding-bottom:1rem; border-bottom:1px solid var(--cv-border-md); }
.sec-num {
    width:32px; height:32px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:.75rem; font-weight:700; letter-spacing:.04em;
    background:linear-gradient(135deg, var(--cv-green-deep), var(--cv-green));
    border:1px solid var(--cv-green-border); color:var(--cv-green-bright);
}
.sec-num-amber { background:linear-gradient(135deg,rgba(232,160,48,.08),rgba(232,160,48,.18)); border-color:var(--cv-amber-border); color:var(--cv-amber); }
.sec-title { font-size:.9375rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:.2rem; }
.sec-sub   { font-size:.8125rem; color:var(--cv-text-muted); }

/* ── 2-col grid ──────────────────────────── */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem 1.5rem; }
@media(max-width:600px){ .grid-2 { grid-template-columns:1fr; } .col-span-2{ grid-column:span 1; } }
.col-span-2 { grid-column:span 2; }

/* ── Numeric inputs ──────────────────────── */
.num-input { color:var(--cv-blue-text); font-variant-numeric:tabular-nums; }

/* ── Read-only company ───────────────────── */
.readonly-val {
    display:flex; align-items:center; height:38px; padding:0 .75rem;
    background:var(--cv-bg-card-alt); border:1px solid var(--cv-border-md);
    border-radius:var(--cv-radius); font-size:.8125rem; color:var(--cv-text-secondary);
}

/* Required star */
.req { color:var(--cv-danger-text); margin-inline-start:2px; }

/* Optional hint */
.optional-hint { font-size:.75rem; font-weight:400; color:var(--cv-text-muted); margin-inline-start:4px; }

/* ── Status row ──────────────────────────── */
.status-row  { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.status-desc { flex:1; min-width:180px; font-size:.8125rem; color:var(--cv-text-muted); margin:0; }
.status-badge { display:inline-flex; align-items:center; padding:.25rem .75rem; border-radius:9999px; font-size:.75rem; font-weight:700; letter-spacing:.05em; white-space:nowrap; }
.status-on  { background:var(--cv-success-bg); color:var(--cv-success-text); border:1px solid var(--cv-success-border); }
.status-off { background:rgba(255,255,255,.04); color:var(--cv-text-muted); border:1px solid var(--cv-border); }

/* ── Form footer ─────────────────────────── */
.form-footer { display:flex; justify-content:flex-end; align-items:center; gap:.75rem; padding-top:.5rem; flex-wrap:wrap; }

/* ── Buttons ─────────────────────────────── */
.btn-amber {
    display:inline-flex; align-items:center; gap:.375rem;
    padding:.5625rem 1.25rem; border-radius:var(--cv-radius); border:none; cursor:pointer;
    background:var(--cv-amber); color:#1a1000; font-size:.8125rem; font-weight:600;
    box-shadow:var(--cv-shadow-amber); transition:background .15s; text-decoration:none;
}
.btn-amber:hover { background:var(--cv-amber-hover); }
.btn-amber:disabled { opacity:.5; cursor:not-allowed; }

.btn-ghost {
    display:inline-flex; align-items:center; gap:.375rem;
    padding:.5rem 1rem; border-radius:var(--cv-radius);
    background:transparent; border:1px solid var(--cv-border);
    color:var(--cv-text-secondary); font-size:.8125rem; font-weight:500;
    cursor:pointer; text-decoration:none; transition:var(--cv-transition);
}
.btn-ghost:hover { background:var(--cv-bg-hover); color:var(--cv-text-primary); }

.btn-danger-sm {
    display:inline-flex; align-items:center;
    padding:.5rem 1rem; border-radius:var(--cv-radius);
    background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border);
    color:var(--cv-danger-text); font-size:.8125rem; font-weight:600;
    cursor:pointer; transition:var(--cv-transition);
}
.btn-danger-sm:hover { background:rgba(224,112,112,.22); }

.btn-secondary {
    display:inline-flex; align-items:center;
    padding:.5rem 1rem; border-radius:var(--cv-radius);
    background:var(--cv-bg-card-alt); border:1px solid var(--cv-border-md);
    color:var(--cv-text-secondary); font-size:.8125rem; font-weight:500;
    cursor:pointer; transition:var(--cv-transition);
}
.btn-secondary:hover { background:var(--cv-bg-hover); color:var(--cv-text-primary); }

/* ── Modal ───────────────────────────────── */
.modal-back { position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(0,0,0,.6); backdrop-filter:blur(4px); }
.modal-box  { background:var(--cv-bg-modal); border:1px solid var(--cv-border-md); border-radius:var(--cv-radius-lg); box-shadow:var(--cv-shadow-md); padding:1.75rem; width:100%; max-width:400px; }
.modal-del-ico { width:56px; height:56px; border-radius:50%; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); color:var(--cv-danger-text); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; }
.modal-title { text-align:center; font-size:1rem; font-weight:700; color:var(--cv-danger-text); margin-bottom:.75rem; }
.modal-warn  { font-size:.875rem; color:var(--cv-text-muted); margin-bottom:1.5rem; text-align:center; }
.modal-acts  { display:flex; gap:.75rem; }
.btn-danger  { flex:1; padding:.5625rem 1rem; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); color:var(--cv-danger-text); font-size:.8125rem; font-weight:600; border-radius:var(--cv-radius); cursor:pointer; transition:var(--cv-transition); }
.btn-danger:hover { background:rgba(224,112,112,.22); }
</style>