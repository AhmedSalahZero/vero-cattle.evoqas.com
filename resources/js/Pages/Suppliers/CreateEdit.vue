<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO — Suppliers/CreateEdit.vue
//
//  One component handles both create and edit modes via `mode` prop.
//  Bilingual rule §5 applied fully:
//    - locale === 'en' → name_en visible, name_ar v-show="false"
//    - locale === 'ar' → name_ar visible, name_en v-show="false"
//    - Both fields always in useForm() so both always submitted
//    - address_en / address_ar follow the same pattern
//    - sanitizeNullableFields() runs server-side before validate()
//
//  Sections (numbered cards):
//    1. Basic Identity   — name, code, type
//    2. Contact Details  — person, phone, email, website
//    3. Address          — address, city, country
//    4. Financial        — tax number, bank, payment terms
//    5. Notes & Status   — notes, is_active
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    supplier:  { type: Object, default: null },   // null → create mode
    companies: { type: Array,  default: () => [] },
    mode:      { type: String, default: 'create' }, // 'create' | 'edit'
})

const { locale: lang, isRtl, n } = useLocale()

// ── Form ──────────────────────────────────────────────────────────
// Both _en and _ar always in the form so both are always submitted.
// §5.5 — never use <input type="hidden">; use v-show="false" + v-model.
const form = useForm({
    company_id:         props.supplier?.company_id  ?? '',
    name_en:            props.supplier?.name_en     ?? '',
    name_ar:            props.supplier?.name_ar     ?? '',
    code:               props.supplier?.code        ?? '',
    type:               props.supplier?.type        ?? 'other',
    contact_person:     props.supplier?.contact_person ?? '',
    phone:              props.supplier?.phone       ?? '',
    email:              props.supplier?.email       ?? '',
    website:            props.supplier?.website     ?? '',
    address_en:         props.supplier?.address_en  ?? '',
    address_ar:         props.supplier?.address_ar  ?? '',
    city:               props.supplier?.city        ?? '',
    country:            props.supplier?.country     ?? 'EG',
    tax_number:         props.supplier?.tax_number  ?? '',
    bank_name:          props.supplier?.bank_name   ?? '',
    bank_account:       props.supplier?.bank_account ?? '',
    payment_terms_days: props.supplier?.payment_terms_days ?? 30,
    is_active:          props.supplier?.is_active   ?? true,
    notes:              props.supplier?.notes       ?? '',
})

// ── Submit ────────────────────────────────────────────────────────
function submit() {
    if (props.mode === 'edit') {
        form.patch(route('suppliers.update', props.supplier.id))
    } else {
        form.post(route('suppliers.store'))
    }
}

// ── Live preview: best name for current locale ────────────────────
const previewName = computed(() => {
    if (lang.value === 'ar') return form.name_ar || form.name_en || '—'
    return form.name_en || form.name_ar || '—'
})

// ── Preview initials ──────────────────────────────────────────────
const previewInitials = computed(() => {
    const name = previewName.value === '—' ? '' : previewName.value
    if (!name) return '?'
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
})

// ── Stripe color by type ──────────────────────────────────────────
const typeStripe = computed(() => ({
    animal: 'linear-gradient(135deg,#0D3D20,#1E7A3C)',
    feed:   'linear-gradient(135deg,#3D2A0D,#7A581E)',
    medicine: 'linear-gradient(135deg,#3D0D0D,#7A1E1E)',
    equipment: 'linear-gradient(135deg,#0D1E3D,#1E3E7A)',
    other:  'linear-gradient(135deg,#1A2A1E,#2E4A38)',
})[form.type] ?? 'linear-gradient(135deg,#1A2A1E,#2E4A38)')

// ── Type options ──────────────────────────────────────────────────
const typeOptions = computed(() => lang.value === 'ar'
    ? [
        { value: 'animal',    label: 'مواشي' },
        { value: 'feed',      label: 'أعلاف' },
        { value: 'medicine',  label: 'أدوية' },
        { value: 'equipment', label: 'معدات' },
        { value: 'other',     label: 'أخرى' },
    ]
    : [
        { value: 'animal',    label: 'Animal Supplier' },
        { value: 'feed',      label: 'Feed Supplier' },
        { value: 'medicine',  label: 'Medicine / Vet Supplier' },
        { value: 'equipment', label: 'Equipment Supplier' },
        { value: 'other',     label: 'Other' },
    ]
)

// ── Country options (short list — EG default) ─────────────────────
const countryOptions = [
    { value: 'EG', label: lang.value === 'ar' ? 'مصر' : 'Egypt' },
    { value: 'SA', label: lang.value === 'ar' ? 'السعودية' : 'Saudi Arabia' },
    { value: 'AE', label: lang.value === 'ar' ? 'الإمارات' : 'UAE' },
    { value: 'JO', label: lang.value === 'ar' ? 'الأردن' : 'Jordan' },
    { value: 'KW', label: lang.value === 'ar' ? 'الكويت' : 'Kuwait' },
    { value: 'QA', label: lang.value === 'ar' ? 'قطر' : 'Qatar' },
    { value: 'US', label: lang.value === 'ar' ? 'الولايات المتحدة' : 'United States' },
    { value: 'GB', label: lang.value === 'ar' ? 'المملكة المتحدة' : 'United Kingdom' },
    { value: 'DE', label: lang.value === 'ar' ? 'ألمانيا' : 'Germany' },
    { value: 'NL', label: lang.value === 'ar' ? 'هولندا' : 'Netherlands' },
    { value: 'TR', label: lang.value === 'ar' ? 'تركيا' : 'Turkey' },
    { value: 'BR', label: lang.value === 'ar' ? 'البرازيل' : 'Brazil' },
    { value: 'AU', label: lang.value === 'ar' ? 'أستراليا' : 'Australia' },
]

// ── Show super admin company picker ──────────────────────────────
const { isSuperAdmin } = usePermissions()
const showCompanyPicker = computed(() => isSuperAdmin.value)

// ── UI strings — full bilingual ───────────────────────────────────
const ui = computed(() => lang.value === 'ar' ? {
    eyebrow:       'إدارة الموردين',
    title:         props.mode === 'edit' ? 'تعديل المورد' : 'إضافة مورد جديد',
    sub:           props.mode === 'edit' ? 'تحديث بيانات المورد' : 'تسجيل مورد جديد في النظام',
    saveBtn:       props.mode === 'edit' ? 'حفظ التغييرات' : 'إضافة المورد',
    cancelBtn:     'إلغاء',
    saving:        'جارٍ الحفظ...',

    // Section 1
    sec1Title:     'الهوية الأساسية',
    companyLabel:  'الشركة',
    companyPh:     'اختر الشركة',
    nameLabel:     'اسم المورد',
    namePh:        'اسم المورد بالعربية',
    codeLabel:     'كود المورد',
    codePh:        'مثال: SUP-001',
    typeLabel:     'نوع المورد',

    // Section 2
    sec2Title:     'بيانات التواصل',
    contactLabel:  'جهة الاتصال',
    contactPh:     'اسم الشخص المسؤول',
    phoneLabel:    'رقم الهاتف',
    phonePh:       '+20 xxx xxx xxxx',
    emailLabel:    'البريد الإلكتروني',
    emailPh:       'supplier@example.com',
    websiteLabel:  'الموقع الإلكتروني',
    websitePh:     'https://...',

    // Section 3
    sec3Title:     'العنوان',
    addressLabel:  'العنوان',
    addressPh:     'العنوان بالتفصيل',
    cityLabel:     'المدينة',
    cityPh:        'المدينة',
    countryLabel:  'الدولة',

    // Section 4
    sec4Title:     'البيانات المالية',
    taxLabel:      'الرقم الضريبي',
    taxPh:         'الرقم الضريبي',
    bankLabel:     'اسم البنك',
    bankPh:        'اسم البنك',
    bankAccLabel:  'رقم الحساب البنكي',
    bankAccPh:     'رقم الحساب',
    payTermsLabel: 'شروط الدفع (أيام)',
    payTermsPh:    '30',

    // Section 5
    sec5Title:     'ملاحظات والحالة',
    notesLabel:    'ملاحظات',
    notesPh:       'أي ملاحظات إضافية...',
    activeLabel:   'مورد نشط',
    activeHint:    'المورد النشط يظهر في قوائم الشراء والفواتير',

    // Preview
    previewLabel:  'معاينة البطاقة',
} : {
    eyebrow:       'Supplier Management',
    title:         props.mode === 'edit' ? 'Edit Supplier' : 'New Supplier',
    sub:           props.mode === 'edit' ? 'Update supplier details' : 'Register a new supplier in the system',
    saveBtn:       props.mode === 'edit' ? 'Save Changes' : 'Add Supplier',
    cancelBtn:     'Cancel',
    saving:        'Saving…',

    sec1Title:     'Basic Identity',
    companyLabel:  'Company',
    companyPh:     'Select company',
    nameLabel:     'Supplier Name',
    namePh:        'Supplier name in English',
    codeLabel:     'Supplier Code',
    codePh:        'e.g. SUP-001',
    typeLabel:     'Supplier Type',

    sec2Title:     'Contact Details',
    contactLabel:  'Contact Person',
    contactPh:     'Name of responsible person',
    phoneLabel:    'Phone Number',
    phonePh:       '+20 xxx xxx xxxx',
    emailLabel:    'Email Address',
    emailPh:       'supplier@example.com',
    websiteLabel:  'Website',
    websitePh:     'https://…',

    sec3Title:     'Address',
    addressLabel:  'Address',
    addressPh:     'Full address',
    cityLabel:     'City',
    cityPh:        'City',
    countryLabel:  'Country',

    sec4Title:     'Financial Details',
    taxLabel:      'Tax Number',
    taxPh:         'Tax registration number',
    bankLabel:     'Bank Name',
    bankPh:        'Bank name',
    bankAccLabel:  'Bank Account Number',
    bankAccPh:     'Account number',
    payTermsLabel: 'Payment Terms (days)',
    payTermsPh:    '30',

    sec5Title:     'Notes & Status',
    notesLabel:    'Notes',
    notesPh:       'Any additional notes…',
    activeLabel:   'Active Supplier',
    activeHint:    'Active suppliers appear in procurement and invoice lists',

    previewLabel:  'Card Preview',
})
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── PAGE HERO ─────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ ui.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ ui.title }}</div>
                <div class="cv-page-hero-sub">{{ ui.sub }}</div>
            </div>

            <!-- ── PAGE CONTENT ──────────────────────────────────── -->
            <div class="cv-page">
                <div style="display:grid; grid-template-columns:1fr 300px; gap:1.25rem; align-items:start;">

                    <!-- ── LEFT COLUMN: Form sections ──────────────── -->
                    <div style="display:flex; flex-direction:column; gap:1rem;">

                        <!-- ── SECTION 1: Basic Identity ────────────── -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <!-- Section number badge -->
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,var(--cv-green-deep),var(--cv-green)); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">1</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec1Title }}</span>
                            </div>

                            <!-- Company picker (super admin only) -->
                            <div v-if="showCompanyPicker" class="cv-field">
                                <label class="cv-label">{{ ui.companyLabel }}</label>
                                <select v-model="form.company_id" class="cv-select" :class="{ 'cv-select--error': form.errors.company_id }">
                                    <option value="">{{ ui.companyPh }}</option>
                                    <option v-for="c in companies" :key="c.id" :value="c.id">
                                        {{ n(c, 'name') }}
                                    </option>
                                </select>
                                <p v-if="form.errors.company_id" class="cv-field-error">{{ form.errors.company_id }}</p>
                            </div>

                            <!-- ── BILINGUAL NAME ───────────────────── -->
                            <!-- §5.5 — EN field: visible when locale = en -->
                            <div class="cv-field" v-show="lang === 'en'">
                                <label class="cv-label">{{ ui.nameLabel }} *</label>
                                <input
                                    v-model="form.name_en"
                                    type="text"
                                    class="cv-input"
                                    :class="{ 'cv-input--error': form.errors.name_en }"
                                    :placeholder="ui.namePh"
                                />
                                <p v-if="form.errors.name_en" class="cv-field-error">{{ form.errors.name_en }}</p>
                            </div>

                            <!-- §5.5 — AR field: visible when locale = ar, v-show not type="hidden" -->
                            <div class="cv-field" v-show="lang === 'ar'">
                                <label class="cv-label">{{ ui.nameLabel }} *</label>
                                <input
                                    v-model="form.name_ar"
                                    type="text"
                                    class="cv-input"
                                    :class="{ 'cv-input--error': form.errors.name_en }"
                                    :placeholder="ui.namePh"
                                    dir="rtl"
                                />
                                <p v-if="form.errors.name_en" class="cv-field-error">{{ form.errors.name_en }}</p>
                            </div>

                            <!-- Code + Type in a row -->
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.codeLabel }}</label>
                                    <input
                                        v-model="form.code"
                                        type="text"
                                        class="cv-input"
                                        :class="{ 'cv-input--error': form.errors.code }"
                                        :placeholder="ui.codePh"
                                    />
                                    <p v-if="form.errors.code" class="cv-field-error">{{ form.errors.code }}</p>
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.typeLabel }}</label>
                                    <select v-model="form.type" class="cv-select">
                                        <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ── SECTION 2: Contact Details ────────────── -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#3D2A0D,#7A581E); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">2</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec2Title }}</span>
                            </div>

                            <div class="cv-field">
                                <label class="cv-label">{{ ui.contactLabel }}</label>
                                <div class="cv-input-icon-wrap">
                                    <input
                                        v-model="form.contact_person"
                                        type="text"
                                        class="cv-input"
                                        :placeholder="ui.contactPh"
                                    />
                                    <span class="cv-input-icon">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="1.8"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                            <circle cx="12" cy="7" r="4"/>
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.phoneLabel }}</label>
                                    <div class="cv-input-icon-wrap">
                                        <input
                                            v-model="form.phone"
                                            type="tel"
                                            class="cv-input"
                                            :class="{ 'cv-input--error': form.errors.phone }"
                                            :placeholder="ui.phonePh"
                                            dir="ltr"
                                        />
                                        <span class="cv-input-icon">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="1.8"
                                                 stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91A16 16 0 0 0 16 17l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <p v-if="form.errors.phone" class="cv-field-error">{{ form.errors.phone }}</p>
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.emailLabel }}</label>
                                    <div class="cv-input-icon-wrap">
                                        <input
                                            v-model="form.email"
                                            type="email"
                                            class="cv-input"
                                            :class="{ 'cv-input--error': form.errors.email }"
                                            :placeholder="ui.emailPh"
                                            dir="ltr"
                                        />
                                        <span class="cv-input-icon">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="1.8"
                                                 stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                                <polyline points="22,6 12,13 2,6"/>
                                            </svg>
                                        </span>
                                    </div>
                                    <p v-if="form.errors.email" class="cv-field-error">{{ form.errors.email }}</p>
                                </div>
                            </div>

                            <div class="cv-field" style="margin-top:0.75rem; margin-bottom:0;">
                                <label class="cv-label">{{ ui.websiteLabel }}</label>
                                <div class="cv-input-icon-wrap">
                                    <input
                                        v-model="form.website"
                                        type="url"
                                        class="cv-input"
                                        :class="{ 'cv-input--error': form.errors.website }"
                                        :placeholder="ui.websitePh"
                                        dir="ltr"
                                    />
                                    <span class="cv-input-icon">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="1.8"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="2" y1="12" x2="22" y2="12"/>
                                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                        </svg>
                                    </span>
                                </div>
                                <p v-if="form.errors.website" class="cv-field-error">{{ form.errors.website }}</p>
                            </div>
                        </div>

                        <!-- ── SECTION 3: Address ─────────────────────── -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#0D1E3D,#1E3E7A); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">3</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec3Title }}</span>
                            </div>

                            <!-- ── BILINGUAL ADDRESS ─────────────────── -->
                            <!-- EN address: visible when locale = en -->
                            <div class="cv-field" v-show="lang === 'en'">
                                <label class="cv-label">{{ ui.addressLabel }}</label>
                                <input
                                    v-model="form.address_en"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="ui.addressPh"
                                />
                            </div>

                            <!-- AR address: visible when locale = ar -->
                            <div class="cv-field" v-show="lang === 'ar'">
                                <label class="cv-label">{{ ui.addressLabel }}</label>
                                <input
                                    v-model="form.address_ar"
                                    type="text"
                                    class="cv-input"
                                    :placeholder="ui.addressPh"
                                    dir="rtl"
                                />
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.cityLabel }}</label>
                                    <input
                                        v-model="form.city"
                                        type="text"
                                        class="cv-input"
                                        :placeholder="ui.cityPh"
                                    />
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.countryLabel }}</label>
                                    <select v-model="form.country" class="cv-select">
                                        <option v-for="c in countryOptions" :key="c.value" :value="c.value">
                                            {{ c.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ── SECTION 4: Financial ────────────────────── -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#1A0D3D,#3D1E7A); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">4</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec4Title }}</span>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.taxLabel }}</label>
                                    <input
                                        v-model="form.tax_number"
                                        type="text"
                                        class="cv-input"
                                        :placeholder="ui.taxPh"
                                        dir="ltr"
                                    />
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.payTermsLabel }}</label>
                                    <div class="cv-input-group">
                                        <span class="cv-input-prefix">
                                            {{ lang === 'ar' ? 'يوم' : 'Days' }}
                                        </span>
                                        <input
                                            v-model.number="form.payment_terms_days"
                                            type="number"
                                            class="cv-input"
                                            :class="{ 'cv-input--error': form.errors.payment_terms_days }"
                                            :placeholder="ui.payTermsPh"
                                            min="0"
                                            max="365"
                                            dir="ltr"
                                        />
                                    </div>
                                    <p v-if="form.errors.payment_terms_days" class="cv-field-error">{{ form.errors.payment_terms_days }}</p>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.bankLabel }}</label>
                                    <input
                                        v-model="form.bank_name"
                                        type="text"
                                        class="cv-input"
                                        :placeholder="ui.bankPh"
                                    />
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.bankAccLabel }}</label>
                                    <input
                                        v-model="form.bank_account"
                                        type="text"
                                        class="cv-input"
                                        :placeholder="ui.bankAccPh"
                                        dir="ltr"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- ── SECTION 5: Notes & Status ──────────────── -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#1A2A1E,#2E4A38); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">5</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec5Title }}</span>
                            </div>

                            <div class="cv-field">
                                <label class="cv-label">{{ ui.notesLabel }}</label>
                                <textarea
                                    v-model="form.notes"
                                    class="cv-textarea"
                                    rows="3"
                                    :placeholder="ui.notesPh"
                                />
                            </div>

                            <!-- Active toggle -->
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:0.875rem; background:var(--cv-bg-card-alt); border-radius:var(--cv-radius); border:1px solid var(--cv-border);">
                                <div>
                                    <div style="font-size:0.8125rem; font-weight:600; color:var(--cv-text-primary);">{{ ui.activeLabel }}</div>
                                    <div style="font-size:0.75rem; color:var(--cv-text-muted); margin-top:2px;">{{ ui.activeHint }}</div>
                                </div>
                                <button
                                    type="button"
                                    @click="form.is_active = !form.is_active"
                                    style="width:2.75rem; height:1.5rem; border-radius:9999px; border:none; cursor:pointer; transition:background-color 0.2s ease; position:relative; flex-shrink:0;"
                                    :style="{ backgroundColor: form.is_active ? 'var(--cv-green)' : 'var(--cv-border-md)' }"
                                >
                                    <span
                                        style="position:absolute; top:2px; width:1.125rem; height:1.125rem; background:#fff; border-radius:50%; transition:transform 0.2s ease, inset-inline-start 0.2s ease; box-shadow:0 1px 3px rgba(0,0,0,0.3);"
                                        :style="{ insetInlineStart: form.is_active ? 'calc(100% - 1.25rem)' : '2px' }"
                                    />
                                </button>
                            </div>
                        </div>

                        <!-- ── ACTION BUTTONS ─────────────────────────── -->
                        <div style="display:flex; gap:0.75rem; justify-content:flex-end; padding-bottom:2rem;">
                            <Link
                                :href="route('suppliers.index')"
                                class="cv-btn cv-btn-ghost"
                            >{{ ui.cancelBtn }}</Link>
                            <button
                                class="cv-btn cv-btn-amber"
                                :class="{ 'cv-btn-loading': form.processing }"
                                :disabled="form.processing"
                                @click="submit"
                            >
                                <!-- Save icon -->
                                <svg v-if="!form.processing" width="15" height="15" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                </svg>
                                {{ form.processing ? ui.saving : ui.saveBtn }}
                            </button>
                        </div>

                    </div><!-- end left column -->

                    <!-- ── RIGHT COLUMN: Live preview ─────────────── -->
                    <div style="position:sticky; top:calc(var(--cv-header-height) + 1.5rem);">
                        <div class="cv-card" style="padding:0; overflow:hidden;">
                            <!-- Preview header label -->
                            <div style="padding:0.625rem 1rem; background:var(--cv-bg-card-alt); border-bottom:1px solid var(--cv-border); font-size:0.65rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--cv-text-muted);">
                                {{ ui.previewLabel }}
                            </div>

                            <!-- Preview card body -->
                            <div style="padding:1.125rem;">
                                <!-- Avatar + name -->
                                <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.875rem;">
                                    <div
                                        style="width:3rem; height:3rem; border-radius:var(--cv-radius); display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:800; color:#fff; flex-shrink:0; border:1px solid rgba(255,255,255,0.1);"
                                        :style="{ background: typeStripe }"
                                    >{{ previewInitials }}</div>
                                    <div>
                                        <div style="font-size:0.9375rem; font-weight:700; color:var(--cv-text-primary); line-height:1.2;">
                                            {{ previewName }}
                                        </div>
                                        <div style="display:flex; gap:0.375rem; margin-top:0.25rem; flex-wrap:wrap;">
                                            <span v-if="form.code" class="cv-tag cv-tag-blue">#{{ form.code }}</span>
                                            <span
                                                class="cv-badge"
                                                :class="form.is_active ? 'cv-badge-green' : 'cv-badge-neutral'"
                                            >
                                                {{ form.is_active
                                                    ? (lang === 'ar' ? 'نشط' : 'Active')
                                                    : (lang === 'ar' ? 'غير نشط' : 'Inactive') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Mini details -->
                                <div style="display:flex; flex-direction:column; gap:0.375rem; font-size:0.75rem; color:var(--cv-text-secondary);">
                                    <div v-if="form.type">
                                        <span style="color:var(--cv-text-muted);">{{ lang === 'ar' ? 'النوع:' : 'Type:' }}</span>
                                        {{ typeOptions.find(t => t.value === form.type)?.label }}
                                    </div>
                                    <div v-if="form.contact_person">
                                        <span style="color:var(--cv-text-muted);">{{ lang === 'ar' ? 'جهة الاتصال:' : 'Contact:' }}</span>
                                        {{ form.contact_person }}
                                    </div>
                                    <div v-if="form.phone" dir="ltr" style="unicode-bidi:plaintext;">
                                        <span style="color:var(--cv-text-muted);" dir="rtl">{{ lang === 'ar' ? 'الهاتف:' : 'Phone:' }}</span>
                                        {{ form.phone }}
                                    </div>
                                    <div v-if="form.city">
                                        <span style="color:var(--cv-text-muted);">{{ lang === 'ar' ? 'المدينة:' : 'City:' }}</span>
                                        {{ form.city }}
                                    </div>
                                    <div v-if="form.payment_terms_days">
                                        <span style="color:var(--cv-text-muted);">{{ lang === 'ar' ? 'شروط الدفع:' : 'Payment Terms:' }}</span>
                                        {{ form.payment_terms_days }} {{ lang === 'ar' ? 'يوم' : 'days' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- end right column -->

                </div><!-- end grid -->
            </div><!-- .cv-page -->
        </div>
    </AuthenticatedLayout>
</template>
