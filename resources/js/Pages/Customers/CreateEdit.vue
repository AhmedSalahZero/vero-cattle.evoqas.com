<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO — Customers/CreateEdit.vue
//
//  Sections:
//    1. Basic Identity   — name, code
//    2. Contact Details  — person, phone, email
//    3. Address          — address, city, country
//    4. Financial        — tax number, payment terms
//    5. Partner Link     — is_supplier toggle + supplier picker
//    6. Notes & Status   — notes, is_active
//
//  Partner link (Section 5):
//    Toggle "This customer is also a supplier"
//    When ON → show supplier dropdown (existing supplier records)
//    When OFF → supplier_id cleared
//    Server side: linked Supplier.is_customer gets synced automatically
// ══════════════════════════════════════════════════════════════════

import { computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    customer:  { type: Object, default: null },
    companies: { type: Array,  default: () => [] },
    suppliers: { type: Array,  default: () => [] },
    mode:      { type: String, default: 'create' },
})

const { locale: lang, isRtl, n } = useLocale()

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    company_id:          props.customer?.company_id          ?? '',
    name_en:             props.customer?.name_en             ?? '',
    name_ar:             props.customer?.name_ar             ?? '',
    code:                props.customer?.code                ?? '',
    contact_person:      props.customer?.contact_person      ?? '',
    phone:               props.customer?.phone               ?? '',
    email:               props.customer?.email               ?? '',
    address_en:          props.customer?.address_en          ?? '',
    address_ar:          props.customer?.address_ar          ?? '',
    city:                props.customer?.city                ?? '',
    country:             props.customer?.country             ?? 'EG',
    tax_number:          props.customer?.tax_number          ?? '',
    payment_terms_days:  props.customer?.payment_terms_days  ?? 30,
    is_active:           props.customer?.is_active           ?? true,
    is_supplier:         props.customer?.is_supplier         ?? false,
    supplier_id:         props.customer?.supplier_id         ?? '',
    notes:               props.customer?.notes               ?? '',
})

// When is_supplier is toggled off, clear supplier_id
watch(() => form.is_supplier, (val) => {
    if (!val) form.supplier_id = ''
})

// ── Submit ────────────────────────────────────────────────────────
function submit() {
    if (props.mode === 'edit') {
        form.patch(route('customers.update', props.customer.id))
    } else {
        form.post(route('customers.store'))
    }
}

// ── Preview ───────────────────────────────────────────────────────
const previewName = computed(() => {
    if (lang.value === 'ar') return form.name_ar || form.name_en || '—'
    return form.name_en || form.name_ar || '—'
})

const previewInitials = computed(() => {
    const name = previewName.value === '—' ? '' : previewName.value
    if (!name) return '?'
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
})

// Avatar gradient: blue if also supplier, green if pure customer
const avatarGradient = computed(() =>
    form.is_supplier
        ? 'linear-gradient(135deg,#0D1E3D,#1E3E7A)'
        : 'linear-gradient(135deg,#0D3D20,#1E7A3C)'
)

// ── Linked supplier name for preview ──────────────────────────────
const linkedSupplierName = computed(() => {
    if (!form.supplier_id) return null
    const s = props.suppliers.find(s => s.id == form.supplier_id)
    return s ? n(s, 'name') : null
})

// ── Helpers ───────────────────────────────────────────────────────
const { isSuperAdmin } = usePermissions()
const showCompanyPicker = computed(() => isSuperAdmin.value)

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

// ── UI strings ────────────────────────────────────────────────────
const ui = computed(() => lang.value === 'ar' ? {
    eyebrow:        'إدارة العملاء',
    title:          props.mode === 'edit' ? 'تعديل العميل' : 'إضافة عميل جديد',
    sub:            props.mode === 'edit' ? 'تحديث بيانات العميل' : 'تسجيل عميل جديد في النظام',
    saveBtn:        props.mode === 'edit' ? 'حفظ التغييرات' : 'إضافة العميل',
    cancelBtn:      'إلغاء',
    saving:         'جارٍ الحفظ...',

    sec1Title:      'الهوية الأساسية',
    companyLabel:   'الشركة',
    companyPh:      'اختر الشركة',
    nameLabel:      'اسم العميل',
    namePh:         'اسم العميل بالعربية',
    codeLabel:      'كود العميل',
    codePh:         'مثال: CUS-001',

    sec2Title:      'بيانات التواصل',
    contactLabel:   'جهة الاتصال',
    contactPh:      'اسم الشخص المسؤول',
    phoneLabel:     'رقم الهاتف',
    phonePh:        '+20 xxx xxx xxxx',
    emailLabel:     'البريد الإلكتروني',
    emailPh:        'customer@example.com',

    sec3Title:      'العنوان',
    addressLabel:   'العنوان',
    addressPh:      'العنوان بالتفصيل',
    cityLabel:      'المدينة',
    cityPh:         'المدينة',
    countryLabel:   'الدولة',

    sec4Title:      'البيانات المالية',
    taxLabel:       'الرقم الضريبي',
    taxPh:          'الرقم الضريبي',
    payTermsLabel:  'شروط الدفع (أيام)',
    payTermsPh:     '30',

    sec5Title:      'ربط المورد',
    isSupplierLabel:'هذا العميل مورد أيضاً',
    isSupplierHint: 'فعّل هذا الخيار إذا كان العميل يبيع لك أيضاً',
    supplierLabel:  'اختر السجل كمورد',
    supplierPh:     'اختر المورد المرتبط',
    partnerAlert:   'سيتم تحديث سجل المورد تلقائياً بعلامة "عميل أيضاً"',

    sec6Title:      'ملاحظات والحالة',
    notesLabel:     'ملاحظات',
    notesPh:        'أي ملاحظات إضافية...',
    activeLabel:    'عميل نشط',
    activeHint:     'العميل النشط يظهر في قوائم المبيعات والفواتير',

    previewLabel:   'معاينة البطاقة',
    alsoSupplier:   'مورد أيضاً',
} : {
    eyebrow:        'Customer Management',
    title:          props.mode === 'edit' ? 'Edit Customer' : 'New Customer',
    sub:            props.mode === 'edit' ? 'Update customer details' : 'Register a new customer in the system',
    saveBtn:        props.mode === 'edit' ? 'Save Changes' : 'Add Customer',
    cancelBtn:      'Cancel',
    saving:         'Saving…',

    sec1Title:      'Basic Identity',
    companyLabel:   'Company',
    companyPh:      'Select company',
    nameLabel:      'Customer Name',
    namePh:         'Customer name in English',
    codeLabel:      'Customer Code',
    codePh:         'e.g. CUS-001',

    sec2Title:      'Contact Details',
    contactLabel:   'Contact Person',
    contactPh:      'Name of responsible person',
    phoneLabel:     'Phone Number',
    phonePh:        '+20 xxx xxx xxxx',
    emailLabel:     'Email Address',
    emailPh:        'customer@example.com',

    sec3Title:      'Address',
    addressLabel:   'Address',
    addressPh:      'Full address',
    cityLabel:      'City',
    cityPh:         'City',
    countryLabel:   'Country',

    sec4Title:      'Financial Details',
    taxLabel:       'Tax Number',
    taxPh:          'Tax registration number',
    payTermsLabel:  'Payment Terms (days)',
    payTermsPh:     '30',

    sec5Title:      'Supplier Link',
    isSupplierLabel:'This customer is also a supplier',
    isSupplierHint: 'Enable if this customer also sells to you',
    supplierLabel:  'Link to existing supplier record',
    supplierPh:     'Select linked supplier',
    partnerAlert:   'The linked supplier record will be automatically flagged as "Also a Customer"',

    sec6Title:      'Notes & Status',
    notesLabel:     'Notes',
    notesPh:        'Any additional notes…',
    activeLabel:    'Active Customer',
    activeHint:     'Active customers appear in sales and invoice lists',

    previewLabel:   'Card Preview',
    alsoSupplier:   'Also a Supplier',
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

                    <!-- ── LEFT COLUMN ────────────────────────────── -->
                    <div style="display:flex; flex-direction:column; gap:1rem;">

                        <!-- SECTION 1: Basic Identity -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,var(--cv-green-deep),var(--cv-green)); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">1</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec1Title }}</span>
                            </div>

                            <!-- Company picker (super admin only) -->
                            <div v-if="showCompanyPicker" class="cv-field">
                                <label class="cv-label">{{ ui.companyLabel }}</label>
                                <select v-model="form.company_id" class="cv-select" :class="{ 'cv-select--error': form.errors.company_id }">
                                    <option value="">{{ ui.companyPh }}</option>
                                    <option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c, 'name') }}</option>
                                </select>
                                <p v-if="form.errors.company_id" class="cv-field-error">{{ form.errors.company_id }}</p>
                            </div>

                            <!-- Bilingual name — EN visible when locale = en -->
                            <div class="cv-field" v-show="lang === 'en'">
                                <label class="cv-label">{{ ui.nameLabel }} *</label>
                                <input v-model="form.name_en" type="text" class="cv-input"
                                       :class="{ 'cv-input--error': form.errors.name_en }"
                                       :placeholder="ui.namePh" />
                                <p v-if="form.errors.name_en" class="cv-field-error">{{ form.errors.name_en }}</p>
                            </div>

                            <!-- Bilingual name — AR visible when locale = ar (v-show, not type="hidden") -->
                            <div class="cv-field" v-show="lang === 'ar'">
                                <label class="cv-label">{{ ui.nameLabel }} *</label>
                                <input v-model="form.name_ar" type="text" class="cv-input"
                                       :class="{ 'cv-input--error': form.errors.name_en }"
                                       :placeholder="ui.namePh" dir="rtl" />
                                <p v-if="form.errors.name_en" class="cv-field-error">{{ form.errors.name_en }}</p>
                            </div>

                            <div class="cv-field" style="margin-bottom:0;">
                                <label class="cv-label">{{ ui.codeLabel }}</label>
                                <input v-model="form.code" type="text" class="cv-input"
                                       :class="{ 'cv-input--error': form.errors.code }"
                                       :placeholder="ui.codePh" />
                                <p v-if="form.errors.code" class="cv-field-error">{{ form.errors.code }}</p>
                            </div>
                        </div>

                        <!-- SECTION 2: Contact Details -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#3D2A0D,#7A581E); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">2</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec2Title }}</span>
                            </div>

                            <div class="cv-field">
                                <label class="cv-label">{{ ui.contactLabel }}</label>
                                <input v-model="form.contact_person" type="text" class="cv-input" :placeholder="ui.contactPh" />
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.phoneLabel }}</label>
                                    <input v-model="form.phone" type="tel" class="cv-input"
                                           :class="{ 'cv-input--error': form.errors.phone }"
                                           :placeholder="ui.phonePh" dir="ltr" />
                                    <p v-if="form.errors.phone" class="cv-field-error">{{ form.errors.phone }}</p>
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.emailLabel }}</label>
                                    <input v-model="form.email" type="email" class="cv-input"
                                           :class="{ 'cv-input--error': form.errors.email }"
                                           :placeholder="ui.emailPh" dir="ltr" />
                                    <p v-if="form.errors.email" class="cv-field-error">{{ form.errors.email }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 3: Address -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#0D1E3D,#1E3E7A); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">3</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec3Title }}</span>
                            </div>

                            <!-- EN address -->
                            <div class="cv-field" v-show="lang === 'en'">
                                <label class="cv-label">{{ ui.addressLabel }}</label>
                                <input v-model="form.address_en" type="text" class="cv-input" :placeholder="ui.addressPh" />
                            </div>
                            <!-- AR address -->
                            <div class="cv-field" v-show="lang === 'ar'">
                                <label class="cv-label">{{ ui.addressLabel }}</label>
                                <input v-model="form.address_ar" type="text" class="cv-input" :placeholder="ui.addressPh" dir="rtl" />
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.cityLabel }}</label>
                                    <input v-model="form.city" type="text" class="cv-input" :placeholder="ui.cityPh" />
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.countryLabel }}</label>
                                    <select v-model="form.country" class="cv-select">
                                        <option v-for="c in countryOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 4: Financial -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#1A0D3D,#3D1E7A); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">4</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec4Title }}</span>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.taxLabel }}</label>
                                    <input v-model="form.tax_number" type="text" class="cv-input" :placeholder="ui.taxPh" dir="ltr" />
                                </div>
                                <div class="cv-field" style="margin-bottom:0;">
                                    <label class="cv-label">{{ ui.payTermsLabel }}</label>
                                    <div class="cv-input-group">
                                        <span class="cv-input-prefix">{{ lang === 'ar' ? 'يوم' : 'Days' }}</span>
                                        <input v-model.number="form.payment_terms_days" type="number" class="cv-input"
                                               :class="{ 'cv-input--error': form.errors.payment_terms_days }"
                                               :placeholder="ui.payTermsPh" min="0" max="365" dir="ltr" />
                                    </div>
                                    <p v-if="form.errors.payment_terms_days" class="cv-field-error">{{ form.errors.payment_terms_days }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 5: Partner Link ── KEY SECTION ── -->
                        <div class="cv-card" style="border-color: var(--cv-blue-border);">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#0D1E3D,#1E3E7A); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">5</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec5Title }}</span>
                                <!-- Info badge -->
                                <span class="cv-badge cv-badge-info" style="margin-inline-start:auto;">
                                    {{ lang === 'ar' ? 'اختياري' : 'Optional' }}
                                </span>
                            </div>

                            <!-- is_supplier toggle -->
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:0.875rem; background:var(--cv-bg-card-alt); border-radius:var(--cv-radius); border:1px solid var(--cv-blue-border); margin-bottom:0.875rem;">
                                <div>
                                    <div style="font-size:0.8125rem; font-weight:600; color:var(--cv-text-primary);">{{ ui.isSupplierLabel }}</div>
                                    <div style="font-size:0.75rem; color:var(--cv-text-muted); margin-top:2px;">{{ ui.isSupplierHint }}</div>
                                </div>
                                <button
                                    type="button"
                                    @click="form.is_supplier = !form.is_supplier"
                                    style="width:2.75rem; height:1.5rem; border-radius:9999px; border:none; cursor:pointer; transition:background-color 0.2s ease; position:relative; flex-shrink:0;"
                                    :style="{ backgroundColor: form.is_supplier ? 'var(--cv-blue)' : 'var(--cv-border-md)' }"
                                >
                                    <span
                                        style="position:absolute; top:2px; width:1.125rem; height:1.125rem; background:#fff; border-radius:50%; transition:inset-inline-start 0.2s ease; box-shadow:0 1px 3px rgba(0,0,0,0.3);"
                                        :style="{ insetInlineStart: form.is_supplier ? 'calc(100% - 1.25rem)' : '2px' }"
                                    />
                                </button>
                            </div>

                            <!-- Supplier picker — only shown when is_supplier is ON -->
                            <Transition name="modal">
                                <div v-if="form.is_supplier">
                                    <div class="cv-field">
                                        <label class="cv-label">{{ ui.supplierLabel }}</label>
                                        <select v-model="form.supplier_id" class="cv-select"
                                                :class="{ 'cv-select--error': form.errors.supplier_id }">
                                            <option value="">{{ ui.supplierPh }}</option>
                                            <option
                                                v-for="s in suppliers"
                                                :key="s.id"
                                                :value="s.id"
                                            >
                                                {{ n(s, 'name') }}
                                                {{ s.code ? `(${s.code})` : '' }}
                                                {{ s.is_customer ? (lang === 'ar' ? ' — مرتبط' : ' — linked') : '' }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors.supplier_id" class="cv-field-error">{{ form.errors.supplier_id }}</p>
                                    </div>

                                    <!-- Info alert about auto-sync -->
                                    <div class="cv-alert cv-alert-info" style="font-size:0.75rem;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round"
                                             style="flex-shrink:0; margin-top:1px;">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        {{ ui.partnerAlert }}
                                    </div>
                                </div>
                            </Transition>
                        </div>

                        <!-- SECTION 6: Notes & Status -->
                        <div class="cv-card">
                            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.125rem;">
                                <div style="width:1.875rem; height:1.875rem; border-radius:var(--cv-radius-sm); background:linear-gradient(135deg,#1A2A1E,#2E4A38); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0;">6</div>
                                <span style="font-size:0.875rem; font-weight:700; color:var(--cv-text-primary);">{{ ui.sec6Title }}</span>
                            </div>

                            <div class="cv-field">
                                <label class="cv-label">{{ ui.notesLabel }}</label>
                                <textarea v-model="form.notes" class="cv-textarea" rows="3" :placeholder="ui.notesPh" />
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
                                        style="position:absolute; top:2px; width:1.125rem; height:1.125rem; background:#fff; border-radius:50%; transition:inset-inline-start 0.2s ease; box-shadow:0 1px 3px rgba(0,0,0,0.3);"
                                        :style="{ insetInlineStart: form.is_active ? 'calc(100% - 1.25rem)' : '2px' }"
                                    />
                                </button>
                            </div>
                        </div>

                        <!-- Action buttons -->
                        <div style="display:flex; gap:0.75rem; justify-content:flex-end; padding-bottom:2rem;">
                            <Link :href="route('customers.index')" class="cv-btn cv-btn-ghost">{{ ui.cancelBtn }}</Link>
                            <button
                                class="cv-btn cv-btn-amber"
                                :class="{ 'cv-btn-loading': form.processing }"
                                :disabled="form.processing"
                                @click="submit"
                            >
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

                    <!-- ── RIGHT COLUMN: Preview ───────────────────── -->
                    <div style="position:sticky; top:calc(var(--cv-header-height) + 1.5rem);">
                        <div class="cv-card" style="padding:0; overflow:hidden;">
                            <div style="padding:0.625rem 1rem; background:var(--cv-bg-card-alt); border-bottom:1px solid var(--cv-border); font-size:0.65rem; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:var(--cv-text-muted);">
                                {{ ui.previewLabel }}
                            </div>
                            <div style="padding:1.125rem;">
                                <!-- Avatar + name -->
                                <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.875rem;">
                                    <div
                                        style="width:3rem; height:3rem; border-radius:var(--cv-radius); display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:800; color:#fff; flex-shrink:0; border:1px solid rgba(255,255,255,0.1);"
                                        :style="{ background: avatarGradient }"
                                    >{{ previewInitials }}</div>
                                    <div>
                                        <div style="font-size:0.9375rem; font-weight:700; color:var(--cv-text-primary); line-height:1.2;">
                                            {{ previewName }}
                                        </div>
                                        <div style="display:flex; gap:0.375rem; margin-top:0.25rem; flex-wrap:wrap;">
                                            <span v-if="form.code" class="cv-tag cv-tag-blue">#{{ form.code }}</span>
                                            <span class="cv-badge" :class="form.is_active ? 'cv-badge-green' : 'cv-badge-neutral'">
                                                {{ form.is_active ? (lang === 'ar' ? 'نشط' : 'Active') : (lang === 'ar' ? 'غير نشط' : 'Inactive') }}
                                            </span>
                                            <!-- Partner badge in preview -->
                                            <span v-if="form.is_supplier" class="cv-badge cv-badge-info">
                                                {{ ui.alsoSupplier }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Mini details -->
                                <div style="display:flex; flex-direction:column; gap:0.375rem; font-size:0.75rem; color:var(--cv-text-secondary);">
                                    <div v-if="form.contact_person">
                                        <span style="color:var(--cv-text-muted);">{{ lang === 'ar' ? 'جهة الاتصال:' : 'Contact:' }}</span>
                                        {{ form.contact_person }}
                                    </div>
                                    <div v-if="form.phone" dir="ltr" style="unicode-bidi:plaintext;">
                                        <span style="color:var(--cv-text-muted);">{{ lang === 'ar' ? 'الهاتف:' : 'Phone:' }}</span>
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
                                    <!-- Linked supplier in preview -->
                                    <div v-if="form.is_supplier && linkedSupplierName" style="margin-top:0.375rem; padding:0.5rem; background:var(--cv-blue-light); border-radius:var(--cv-radius-sm); border:1px solid var(--cv-blue-border);">
                                        <span style="font-size:0.65rem; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:var(--cv-blue-text);">
                                            {{ lang === 'ar' ? 'مرتبط كمورد' : 'Linked Supplier' }}
                                        </span>
                                        <div style="font-weight:600; color:var(--cv-blue-text); margin-top:2px;">{{ linkedSupplierName }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- end grid -->
            </div><!-- .cv-page -->
        </div>
    </AuthenticatedLayout>
</template>