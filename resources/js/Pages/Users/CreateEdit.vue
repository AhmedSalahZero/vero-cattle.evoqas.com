<script setup>
/**
 * Users/CreateEdit.vue — VERO Cattle Management
 * Unified create / edit form.
 *
 * Session XX — Permission System Overhaul
 *
 * CHANGE: Section 3 now shows a full permission matrix instead of
 * a role-card picker. A "Load Template" dropdown pre-fills the
 * checkboxes with a preset, but every individual checkbox is
 * editable. The submitted form sends permissions[] — a flat array
 * of "action module" strings — plus an optional role label for
 * display purposes only.
 *
 * Props from UserController@create / edit:
 *   user             : User | null
 *   mode             : 'create'|'edit'
 *   companies        : Company[]
 *   farms            : Farm[]
 *   user.permissions : string
 *   myCompanyId      : number|null
 *   quota            : object|null
 *   permissionMatrix : [ { module, view, create, edit, delete } ]
 *   templates        : { 'Farm Manager': string[], ... }
 *   allModules       : string[]
 */
import { ref, computed, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    user:             { type: Object, default: null },
    mode:             { type: String, default: 'create' },
    companies:        { type: Array,  default: () => [] },
    farms:            { type: Array,  default: () => [] },
    myCompanyId:      { type: Number, default: null },
    quota:            { type: Object, default: null },
    permissionMatrix: { type: Array,  default: () => [] },
    templates:        { type: Object, default: () => ({}) },
    allModules:       { type: Array,  default: () => [] },
})

const isEdit       = computed(() => props.mode === 'edit')
const { isSuperAdmin, can } = usePermissions()

const quotaFull = computed(() =>
    !isEdit.value &&
    !isSuperAdmin.value &&
    props.quota?.max &&
    props.quota.remaining <= 0
)

// ── Build initial permissions map from matrix ─────────────────────
// perms is a reactive object: { 'view animals': true, 'create animals': false, ... }
function matrixToPermsMap(matrix) {
    const map = {}
    matrix.forEach(row => {
        ;['view', 'create', 'edit', 'delete'].forEach(action => {
            map[`${action} ${row.module}`] = row[action] ?? false
        })
    })
    return map
}

const perms = ref(matrixToPermsMap(props.permissionMatrix))

// Flat array of checked permission strings — this is what gets submitted
const permissionsArray = computed(() =>
    Object.entries(perms.value)
        .filter(([, checked]) => checked)
        .map(([name]) => name)
)

// ── Template loader ───────────────────────────────────────────────
const selectedTemplate = ref('')

function loadTemplate(templateName) {
    if (!templateName || !props.templates[templateName]) return
    const tplPermissions = props.templates[templateName]
    // Reset all to false first
    Object.keys(perms.value).forEach(k => { perms.value[k] = false })
    // Set template ones to true
    tplPermissions.forEach(p => {
        if (p in perms.value) perms.value[p] = true
    })
}

watch(selectedTemplate, (val) => { if (val) loadTemplate(val) })

function clearAll() {
    Object.keys(perms.value).forEach(k => { perms.value[k] = false })
    selectedTemplate.value = ''
}

function checkAll() {
    Object.keys(perms.value).forEach(k => { perms.value[k] = true })
    selectedTemplate.value = ''
}

// ── Module row helpers ────────────────────────────────────────────
// Module display names (bilingual)
const moduleLabels = {
    companies:        { en: 'Companies',        ar: 'الشركات' },
    farms:            { en: 'Farms',             ar: 'المزارع' },
    users:            { en: 'Users',             ar: 'المستخدمون' },
    animals:          { en: 'Animals',           ar: 'المواشي' },
    lots:             { en: 'Lots',              ar: 'الأقفاص' },
    health:           { en: 'Health Records',    ar: 'السجلات الصحية' },
    vaccinations:     { en: 'Vaccinations',      ar: 'التطعيمات' },
    breeding:         { en: 'Breeding',          ar: 'التزاوج' },
    calvings:         { en: 'Calvings',          ar: 'الولادات' },
    feed:             { en: 'Feed Items',        ar: 'مواد العلف' },
    rations:          { en: 'Rations',           ar: 'الحصص الغذائية' },
    weight:           { en: 'Weight Records',    ar: 'سجلات الوزن' },
    procurement:      { en: 'Procurement',       ar: 'المشتريات' },
    slaughter:        { en: 'Slaughter',         ar: 'الذبح' },
    sales:            { en: 'Sales',             ar: 'المبيعات' },
    inventory:        { en: 'Inventory',         ar: 'المخزون' },
    accounts:         { en: 'Chart of Accounts', ar: 'دليل الحسابات' },
    journal_entries:  { en: 'Journal Entries',   ar: 'قيود اليومية' },
    ap_invoices:      { en: 'AP Invoices',       ar: 'فواتير الموردين' },
    ar_invoices:      { en: 'AR Invoices',       ar: 'فواتير العملاء' },
    payments:         { en: 'Payments',          ar: 'المدفوعات' },
    bank_statements:  { en: 'Bank Statements',   ar: 'كشوف البنك' },
    fiscal_years:     { en: 'Fiscal Years',      ar: 'السنوات المالية' },
    budgets:          { en: 'Budgets',           ar: 'الميزانيات' },
    fx_rates:         { en: 'FX Rate',           ar: 'أسعار الصرف' },
    customers:        { en: 'Customers',         ar: 'العملاء' },
    suppliers:        { en: 'Suppliers',         ar: 'الموردون' },
    fixed_assets:     { en: 'Fixed Assets',      ar: 'الأصول الثابتة' },
    reports:          { en: 'Reports',           ar: 'التقارير' },
    settings:         { en: 'Settings',          ar: 'الإعدادات' },
    tasks:            { en: 'Tasks',             ar: 'المهام' },
}

// Module groups for visual separation
const moduleGroups = [
    { label: 'Company & Users', labelAr: 'الشركة والمستخدمون', modules: ['companies', 'farms', 'users'] },
    { label: 'Livestock', labelAr: 'الثروة الحيوانية', modules: ['animals', 'lots', 'health', 'vaccinations', 'breeding', 'calvings', 'weight'] },
    { label: 'Feed & Inventory', labelAr: 'العلف والمخزون', modules: ['feed', 'rations', 'inventory'] },
    { label: 'CRM & Assets', labelAr: 'العملاء والموردون والأصول', modules: ['customers', 'suppliers', 'fixed_assets'] },
    { label: 'Operations', labelAr: 'العمليات', modules: ['procurement', 'slaughter', 'sales'] },
    { label: 'Accounting', labelAr: 'المحاسبة', modules: ['accounts', 'journal_entries', 'ap_invoices', 'ar_invoices', 'payments', 'bank_statements','fx_rates', 'fiscal_years', 'budgets'] },
    { label: 'Reports & Other', labelAr: 'التقارير وأخرى', modules: ['reports', 'settings', 'tasks'] },
]

function isRowAllChecked(module) {
    return ['view','create','edit','delete'].every(a => perms.value[`${a} ${module}`])
}

function toggleRow(module) {
    const allChecked = isRowAllChecked(module)
    ;['view','create','edit','delete'].forEach(a => {
        perms.value[`${a} ${module}`] = !allChecked
    })
}

function isGroupAllChecked(modules) {
    return modules.every(m => isRowAllChecked(m))
}

function toggleGroup(modules) {
    const allChecked = isGroupAllChecked(modules)
    modules.forEach(m => {
        ;['view','create','edit','delete'].forEach(a => {
            perms.value[`${a} ${m}`] = !allChecked
        })
    })
}

// Count checked permissions for display
const checkedCount = computed(() => permissionsArray.value.length)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    company_id:             props.user?.company_id ?? props.myCompanyId ?? '',
    name_en:                props.user?.name_en    ?? '',
    name_ar:                props.user?.name_ar    ?? '',
    email:                  props.user?.email      ?? '',
    phone:                  props.user?.phone      ?? '',
    job_title_en:           props.user?.job_title_en ?? '',
    job_title_ar:           props.user?.job_title_ar ?? '',
    role:                   props.user?.role       ?? '',
    farm_ids:               props.user?.farm_ids   ?? [],
    language:               props.user?.language   ?? 'en',
    theme:                  props.user?.theme      ?? 'dark',
    is_active:              props.user?.is_active  ?? true,
    password:               '',
    password_confirmation:  '',
    avatar:                 null,
    permissions:            [],   // filled on submit from permissionsArray
})

// ── Avatar preview ────────────────────────────────────────────────
const avatarPreview = ref(props.user?.avatar ? `/storage/${props.user.avatar}` : null)
function onAvatarChange(e) {
    const file = e.target.files[0]
    if (!file) return
    form.avatar = file
    avatarPreview.value = URL.createObjectURL(file)
}
function clearAvatar() { form.avatar = null; avatarPreview.value = null }

// ── Submit ────────────────────────────────────────────────────────
function submit() {
    if (quotaFull.value) return
    // Inject current permissions into form before submitting
    form.permissions = permissionsArray.value

    if (isEdit.value) {
        form.transform(data => ({ ...data, _method: 'PUT' }))
            .post(route('users.update', props.user.id), { forceFormData: true })
    } else {
        form.post(route('users.store'), { forceFormData: true })
    }
}

// ── Farm toggle ───────────────────────────────────────────────────
function toggleFarm(farmId) {
    const idx = form.farm_ids.indexOf(farmId)
    if (idx === -1) form.farm_ids = [...form.farm_ids, farmId]
    else            form.farm_ids = form.farm_ids.filter(id => id !== farmId)
}

// ── Password strength ─────────────────────────────────────────────
const pwStrength = computed(() => {
    const p = form.password
    if (!p) return null
    let score = 0
    if (p.length >= 8)         score++
    if (p.length >= 12)        score++
    if (/[A-Z]/.test(p))       score++
    if (/[0-9]/.test(p))       score++
    if (/[^A-Za-z0-9]/.test(p)) score++
    if (score <= 1) return { label: 'Weak',   type: 'weak',   width: '25%'  }
    if (score <= 2) return { label: 'Fair',   type: 'fair',   width: '50%'  }
    if (score <= 3) return { label: 'Good',   type: 'good',   width: '75%'  }
    return               { label: 'Strong', type: 'strong', width: '100%' }
})

// ── Initials ──────────────────────────────────────────────────────
const displayInitials = computed(() => {
    const name = form.name_en || ''
    return name.split(' ').slice(0,2).map(w => w[0] ?? '').join('').toUpperCase() || '?'
})

// ── Role colours (display only) ───────────────────────────────────
const roleColours = {
    'Company Admin': 'amber',
    'Farm Manager':  'blue',
    'Veterinarian':  'amber',
    'Feed Manager':  'amber',
    'Data Entry':    'muted',
    'Accountant':    'blue',
}
function roleColour(r) { return roleColours[r] ?? 'muted' }

const roleOptions = ['Company Admin', 'Farm Manager', 'Veterinarian', 'Feed Manager', 'Data Entry', 'Accountant']
</script>

<template>
    <Head :title="isEdit ? `Edit User — ${user?.name_en}` : 'New User'" />
    <AuthenticatedLayout>
        <template #header>
            <span class="eyebrow-crumb">
                {{ isSuperAdmin ? 'Super Admin' : 'Settings' }}
            </span>
            <svg class="w-3 h-3 mx-1.5 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <Link :href="route('users.index')" class="crumb-link">Users</Link>
            <svg class="w-3 h-3 mx-1.5 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <span style="font-size:0.8125rem;font-weight:500;color:var(--cv-text-secondary)">
                {{ isEdit ? 'Edit User' : 'New User' }}
            </span>
        </template>

        <div class="pg-shell">

            <!-- ══ HERO ════════════════════════════════════════════ -->
            <div class="pg-hero">
                <div class="pg-hero-glow"></div>
                <div class="pg-hero-dots"></div>
                <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-7 relative z-10">
                    <div class="flex items-center gap-4">
                        <div class="hero-avatar avatar-default">
                            <img v-if="avatarPreview" :src="avatarPreview" alt="Avatar" class="w-full h-full object-cover"/>
                            <span v-else class="avatar-initials-hero">{{ displayInitials }}</span>
                        </div>
                        <div>
                            <p class="hero-eyebrow">{{ isEdit ? 'Editing User' : 'New User' }}</p>
                            <h1 class="hero-title">
                                {{ form.name_en || (isEdit ? user?.name_en : 'New User') }}
                            </h1>
                            <p v-if="form.name_ar" class="hero-sub" dir="rtl">{{ form.name_ar }}</p>
                            <div class="flex items-center gap-2 mt-1 flex-wrap">
                                <span v-if="form.role" class="hero-role-chip" :class="`role-chip-${roleColour(form.role)}`">
                                    {{ form.role }}
                                </span>
                                <span v-if="checkedCount > 0" class="hero-perm-chip">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                                    </svg>
                                    {{ checkedCount }} permissions
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ FORM ════════════════════════════════════════════ -->
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

                <!-- Quota alert -->
                <div v-if="quotaFull" class="quota-full-alert">
                    <div class="quota-alert-icon">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="quota-alert-title">User Quota Reached / تم الوصول إلى حد المستخدمين</p>
                        <p class="quota-alert-sub">Your company has reached the maximum of <strong>{{ quota.max }}</strong> users.</p>
                    </div>
                </div>

                <form @submit.prevent="submit" class="form-stack">

                    <!-- ╔══ SECTION 1 — Identity ══════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">1</div>
                            <div>
                                <h2 class="section-title">Identity</h2>
                                <p class="section-sub">Full name in both languages and profile photo</p>
                            </div>
                        </div>

                        <!-- Avatar -->
                        <div class="field">
                            <label class="field-label">Profile Photo</label>
                            <div class="avatar-upload-row">
                                <div class="avatar-preview-box">
                                    <img v-if="avatarPreview" :src="avatarPreview" alt="" class="w-full h-full object-cover"/>
                                    <svg v-else class="w-6 h-6 opacity-25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                    </svg>
                                </div>
                                <div>
                                    <label class="btn-upload">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                        </svg>
                                        Upload Photo
                                        <input type="file" class="sr-only" accept="image/*" @change="onAvatarChange"/>
                                    </label>
                                    <button v-if="avatarPreview" type="button" class="btn-remove-avatar" @click="clearAvatar">Remove</button>
                                    <p class="field-hint">PNG, JPG, WebP — max 2 MB</p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="field">
                                <label class="field-label" for="name_en">Full Name (English) <span class="req">*</span></label>
                                <input id="name_en" v-model="form.name_en" type="text" class="finput"
                                       :class="{'finput--err': form.errors.name_en}" placeholder="e.g. Ahmed Hassan"/>
                                <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="name_ar">الاسم الكامل بالعربية</label>
                                <input id="name_ar" v-model="form.name_ar" type="text" dir="rtl"
                                       class="finput text-right" placeholder="مثال: أحمد حسن"/>
                            </div>
                            <div class="field">
                                <label class="field-label" for="job_en">Job Title (English)</label>
                                <input id="job_en" v-model="form.job_title_en" type="text"
                                       class="finput" placeholder="e.g. Farm Operations Manager"/>
                            </div>
                            <div class="field">
                                <label class="field-label" for="job_ar">المسمى الوظيفي بالعربية</label>
                                <input id="job_ar" v-model="form.job_title_ar" type="text" dir="rtl"
                                       class="finput text-right" placeholder="مثال: مدير عمليات المزرعة"/>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 2 — Contact ═══════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">2</div>
                            <div>
                                <h2 class="section-title">Contact</h2>
                                <p class="section-sub">Email address and phone number</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="field">
                                <label class="field-label" for="email">Email Address <span class="req">*</span></label>
                                <div class="finput-icon-wrap">
                                    <svg class="finput-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    <input id="email" v-model="form.email" type="email"
                                           class="finput finput-iconed" :class="{'finput--err': form.errors.email}"
                                           placeholder="user@company.com"/>
                                </div>
                                <p v-if="form.errors.email" class="field-error">{{ form.errors.email }}</p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="phone">Phone Number</label>
                                <div class="finput-icon-wrap">
                                    <svg class="finput-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                    </svg>
                                    <input id="phone" v-model="form.phone" type="tel"
                                           class="finput finput-iconed" placeholder="+20 10 0000 0000"/>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 3 — Company & Role Label ════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">3</div>
                            <div>
                                <h2 class="section-title">Company &amp; Role Label</h2>
                                <p class="section-sub">Assign to a company and optionally tag with a role label for display purposes</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <!-- Company — super admin only -->
                            <div v-if="isSuperAdmin" class="field">
                                <label class="field-label" for="company_id">Company <span class="req">*</span></label>
                                <select id="company_id" v-model="form.company_id" class="fselect"
                                        :class="{'finput--err': form.errors.company_id}">
                                    <option value="" disabled>Select a company…</option>
                                    <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name_en }}</option>
                                </select>
                                <p v-if="form.errors.company_id" class="field-error">{{ form.errors.company_id }}</p>
                            </div>

                            <!-- Role label (display only) -->
                            <div class="field" :class="isSuperAdmin ? '' : 'sm:col-span-2'">
                                <label class="field-label" for="role_label">
                                    Role Label
                                    <span class="field-label-note">Display only — actual access is set below</span>
                                </label>
                                <select id="role_label" v-model="form.role" class="fselect">
                                    <option value="">No label</option>
                                    <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
                                </select>
                                <p class="field-hint">This label appears on the user card for reference only. It does not grant or restrict any access.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 4 — Permissions ════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">4</div>
                            <div class="flex-1">
                                <div class="flex items-center gap-3 flex-wrap">
                                    <div>
                                        <h2 class="section-title">Permissions</h2>
                                        <p class="section-sub">Check exactly what this user can do. Use a template to start, then adjust.</p>
                                    </div>
                                    <!-- Permission count badge -->
                                    <span class="perm-count-badge" :class="checkedCount > 0 ? 'perm-count-active' : 'perm-count-empty'">
                                        {{ checkedCount }} / {{ Object.keys(perms).length }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Template bar -->
                        <div class="template-bar">
                            <span class="template-bar-label">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z"/>
                                </svg>
                                Load Template:
                            </span>
                            <div class="template-btns">
                                <button v-for="(_, name) in templates" :key="name"
                                        type="button"
                                        class="template-btn"
                                        :class="selectedTemplate === name ? 'template-btn-active' : ''"
                                        @click="selectedTemplate = name">
                                    {{ name }}
                                </button>
                                <button type="button" class="template-btn template-btn-clear" @click="clearAll">Clear All</button>
                                <button type="button" class="template-btn template-btn-all" @click="checkAll">Check All</button>
                            </div>
                        </div>

                        <!-- Permission matrix -->
                        <div class="perm-matrix">
                            <!-- Column headers -->
                            <div class="perm-header-row">
                                <div class="perm-module-col">Module / الوحدة</div>
                                <div class="perm-action-col">View / عرض</div>
                                <div class="perm-action-col">Create / إنشاء</div>
                                <div class="perm-action-col">Edit / تعديل</div>
                                <div class="perm-action-col">Delete / حذف</div>
                                <div class="perm-all-col">All</div>
                            </div>

                            <!-- Module groups -->
                            <template v-for="group in moduleGroups" :key="group.label">
                                <!-- Group header -->
                                <div class="perm-group-header">
                                    <div class="perm-group-label">
                                        <span>{{ group.label }}</span>
                                        <span class="perm-group-ar" dir="rtl">{{ group.labelAr }}</span>
                                    </div>
                                    <div style="flex:1"></div>
                                    <!-- Group toggle -->
                                    <button type="button"
                                            class="perm-group-toggle"
                                            :class="isGroupAllChecked(group.modules) ? 'group-toggle-on' : ''"
                                            @click="toggleGroup(group.modules)">
                                        {{ isGroupAllChecked(group.modules) ? 'Deselect All' : 'Select All' }}
                                    </button>
                                </div>

                                <!-- Module rows -->
                                <div v-for="module in group.modules.filter(m => isSuperAdmin || m !== 'companies')" :key="module"
                                     class="perm-row"
                                     :class="isRowAllChecked(module) ? 'perm-row-full' : ''">
                                    <!-- Module name -->
                                    <div class="perm-module-col">
                                        <span class="perm-module-en">{{ moduleLabels[module]?.en ?? module }}</span>
                                        <span class="perm-module-ar" dir="rtl">{{ moduleLabels[module]?.ar }}</span>
                                    </div>
                                    <!-- Action checkboxes -->
                                    <div v-for="action in ['view','create','edit','delete']" :key="action" class="perm-action-col">
                                        <label class="perm-check-wrap">
                                            <input type="checkbox"
                                                   v-model="perms[`${action} ${module}`]"
                                                   class="sr-only"/>
                                            <span class="perm-check"
                                                  :class="{
                                                      'perm-check-view':   perms[`${action} ${module}`] && action === 'view',
                                                      'perm-check-create': perms[`${action} ${module}`] && action === 'create',
                                                      'perm-check-edit':   perms[`${action} ${module}`] && action === 'edit',
                                                      'perm-check-delete': perms[`${action} ${module}`] && action === 'delete',
                                                  }">
                                                <svg v-if="perms[`${action} ${module}`]" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                            </span>
                                        </label>
                                    </div>
                                    <!-- Row toggle -->
                                    <div class="perm-all-col">
                                        <button type="button"
                                                class="perm-row-toggle"
                                                :class="isRowAllChecked(module) ? 'row-toggle-on' : ''"
                                                @click="toggleRow(module)">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path v-if="isRowAllChecked(module)" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                <path v-else stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Summary footer -->
                        <div class="perm-summary">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                            </svg>
                            <span v-if="checkedCount === 0" class="perm-summary-text perm-summary-none">
                                No permissions selected — this user will not be able to access anything.
                                / لم يتم تحديد أي صلاحيات — لن يتمكن هذا المستخدم من الوصول لأي شيء.
                            </span>
                            <span v-else class="perm-summary-text perm-summary-ok">
                                {{ checkedCount }} permissions granted.
                                <span dir="rtl">تم منح {{ checkedCount }} صلاحية.</span>
                            </span>
                        </div>
                    </div>

                   <!-- ╔══ SECTION 5 — Farm Access ════════════════╗ -->
                    <div v-if="farms.length > 0" class="form-section">
                        <div class="section-head">
                            <div class="section-num">5</div>
                            <div>
                                <h2 class="section-title">Farm Access</h2>
                                <p class="section-sub">Select which farms this user can access. Select none for company-wide access.</p>
                            </div>
                        </div>
                        <div class="farm-grid">
                            <div v-for="farm in farms" :key="farm.id"
                                class="farm-check-card"
                                :class="form.farm_ids.includes(farm.id) ? 'farm-card-active' : ''"
                                @click="toggleFarm(farm.id)">
                                <div class="farm-check-icon" :class="form.farm_ids.includes(farm.id) ? 'fci-active' : ''">
                                    <svg v-if="form.farm_ids.includes(farm.id)" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </div>
                                <div>
                                    <p class="farm-check-name">{{ farm.name_en }}</p>
                                    <p v-if="farm.name_ar" class="farm-check-ar" dir="rtl">{{ farm.name_ar }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 6 — Security ══════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">{{ farms.length > 0 ? 6 : 5 }}</div>
                            <div>
                                <h2 class="section-title">Security</h2>
                                <p class="section-sub">{{ isEdit ? 'Leave blank to keep current password' : 'Set a strong password' }}</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="field">
                                <label class="field-label" for="pw">
                                    {{ isEdit ? 'New Password' : 'Password' }}
                                    <span v-if="!isEdit" class="req">*</span>
                                </label>
                                <div class="finput-icon-wrap">
                                    <svg class="finput-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                                    </svg>
                                    <input id="pw" v-model="form.password" type="password"
                                           class="finput finput-iconed"
                                           :class="{'finput--err': form.errors.password}"
                                           placeholder="Min. 8 characters"/>
                                </div>
                                <!-- Strength meter -->
                                <div v-if="pwStrength" class="pw-meter">
                                    <div class="pw-meter-track">
                                        <div class="pw-meter-fill" :class="`pw-${pwStrength.type}`" :style="{ width: pwStrength.width }"></div>
                                    </div>
                                    <span class="pw-label" :class="`pw-label-${pwStrength.type}`">{{ pwStrength.label }}</span>
                                </div>
                                <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="pw_confirm">Confirm Password</label>
                                <div class="finput-icon-wrap">
                                    <svg class="finput-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                                    </svg>
                                    <input id="pw_confirm" v-model="form.password_confirmation" type="password"
                                           class="finput finput-iconed" placeholder="Repeat password"/>
                                </div>
                                <p v-if="form.errors.password_confirmation" class="field-error">{{ form.errors.password_confirmation }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 7 — Preferences & Status ════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">{{ farms.length > 0 ? 7 : 6 }}</div>
                            <div>
                                <h2 class="section-title">Preferences &amp; Status</h2>
                                <p class="section-sub">Language, theme and activation</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <!-- Language -->
                            <div class="field">
                                <label class="field-label">Preferred Language / اللغة المفضلة</label>
                                <div class="mode-toggle">
                                    <label class="mode-opt" :class="{'mode-opt-active': form.language === 'en'}">
                                        <input type="radio" v-model="form.language" value="en" class="sr-only"/>
                                        English
                                    </label>
                                    <label class="mode-opt" :class="{'mode-opt-active-blue': form.language === 'ar'}">
                                        <input type="radio" v-model="form.language" value="ar" class="sr-only"/>
                                        العربية
                                    </label>
                                </div>
                            </div>
                            <!-- Theme -->
                            <div class="field">
                                <label class="field-label">Theme Preference</label>
                                <div class="mode-toggle">
                                    <label class="mode-opt" :class="{'mode-opt-active': form.theme === 'dark'}">
                                        <input type="radio" v-model="form.theme" value="dark" class="sr-only"/>
                                        Dark
                                    </label>
                                    <label class="mode-opt" :class="{'mode-opt-active': form.theme === 'light'}">
                                        <input type="radio" v-model="form.theme" value="light" class="sr-only"/>
                                        Light
                                    </label>
                                </div>
                            </div>
                            <!-- Active -->
                            <div class="field sm:col-span-2">
                                <label class="field-label">User Status</label>
                                <div class="active-toggle-row">
                                    <button type="button" role="switch" :aria-checked="form.is_active"
                                            @click="form.is_active = !form.is_active"
                                            class="toggle-switch" :class="form.is_active ? 'toggle-on' : 'toggle-off'">
                                        <span class="toggle-thumb" :class="form.is_active ? 'thumb-on' : 'thumb-off'"></span>
                                    </button>
                                    <div>
                                        <p class="toggle-label" :class="form.is_active ? 'tl-active' : 'tl-inactive'">
                                            {{ form.is_active ? 'Active' : 'Inactive' }}
                                        </p>
                                        <p class="toggle-hint">
                                            {{ form.is_active ? 'User can log in to the application' : 'User is blocked from logging in' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Form actions ─────────────────────────── -->
                    <div class="form-footer">
                        <Link :href="route('users.index')" class="btn-cancel">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                            </svg>
                            Back to Users
                        </Link>
                        <button type="submit" class="btn-submit"
                                :class="{'btn-loading': form.processing}"
                                :disabled="form.processing || quotaFull">
                            <svg v-if="!form.processing" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                            <span v-if="quotaFull">Quota Reached / الحصة ممتلئة</span>
                            <span v-else>{{ isEdit ? 'Save Changes' : 'Create User' }}</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────────────── */
.pg-shell { min-height: 100vh; background-color: var(--cv-bg); }
.eyebrow-crumb { font-size: 0.625rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--cv-amber); }
.crumb-link { font-size: 0.8125rem; color: var(--cv-text-muted); text-decoration: none; transition: color 0.15s; }
.crumb-link:hover { color: var(--cv-text-secondary); }

/* ── Hero ────────────────────────────────────────────────────── */
.pg-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #0A3A1E 0%, #155C30 55%, #1A6B3A 100%);
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.pg-hero-glow {
    position: absolute; top: -60px; right: -60px;
    width: 380px; height: 380px; border-radius: 50%;
    background: radial-gradient(circle, rgba(77,191,122,0.18) 0%, transparent 65%);
    pointer-events: none;
}
.pg-hero-dots {
    position: absolute; inset: 0; pointer-events: none;
    background-image: radial-gradient(circle, rgba(255,255,255,0.055) 1px, transparent 1px);
    background-size: 22px 22px;
    mask-image: linear-gradient(to bottom, rgba(0,0,0,0.5), transparent);
}
.hero-avatar { width: 3.5rem; height: 3.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; border: 2px solid rgba(255,255,255,0.2); }
.avatar-default { background: rgba(255,255,255,0.12); }
.avatar-initials-hero { font-size: 1rem; font-weight: 800; color: white; }
.hero-eyebrow { font-size: 0.625rem; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: #4DBF7A; margin-bottom: 0.25rem; }
.hero-title { font-size: 1.5rem; font-weight: 800; color: #ffffff; line-height: 1.15; letter-spacing: -0.015em; }
.hero-sub { font-size: 0.8125rem; color: rgba(255,255,255,0.5); margin-top: 0.2rem; }

.hero-role-chip { display: inline-flex; align-items: center; font-size: 0.6875rem; font-weight: 700; padding: 0.175rem 0.5rem; border-radius: 5px; border: 1px solid; margin-top: 0.3rem; }
.role-chip-green { background: rgba(26,107,58,0.25); color: #4DBF7A; border-color: rgba(77,191,122,0.3); }
.role-chip-blue  { background: rgba(29,111,164,0.2);  color: #6BAED6; border-color: rgba(107,174,214,0.3); }
.role-chip-amber { background: rgba(232,131,10,0.2);  color: #F5A623; border-color: rgba(245,166,35,0.3); }
.role-chip-muted { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.5); border-color: rgba(255,255,255,0.15); }

.hero-perm-chip {
    display: inline-flex; align-items: center; gap: 0.3rem;
    font-size: 0.6875rem; font-weight: 700;
    padding: 0.175rem 0.5rem; border-radius: 5px;
    background: rgba(29,111,164,0.2); color: #6BAED6;
    border: 1px solid rgba(107,174,214,0.3);
}

/* ── Quota ───────────────────────────────────────────────────── */
.quota-full-alert { display: flex; align-items: flex-start; gap: 1rem; padding: 1.125rem 1.25rem; margin-bottom: 1.25rem; background: rgba(192,57,43,0.06); border: 1.5px solid rgba(192,57,43,0.25); border-radius: 12px; }
.quota-alert-icon { width: 2.5rem; height: 2.5rem; border-radius: 10px; flex-shrink: 0; background: rgba(192,57,43,0.1); border: 1px solid rgba(192,57,43,0.25); display: flex; align-items: center; justify-content: center; color: #C0392B; }
.quota-alert-title { font-size: 0.9375rem; font-weight: 800; color: #C0392B; margin-bottom: 0.25rem; }
.quota-alert-sub { font-size: 0.8125rem; color: var(--cv-text-secondary); line-height: 1.5; }

/* ── Form stack ──────────────────────────────────────────────── */
.form-stack { display: flex; flex-direction: column; gap: 1.25rem; }
.form-section { background: var(--cv-bg-card); border: 1px solid var(--cv-border); border-radius: 14px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(10,58,30,0.05); }
.section-head { display: flex; align-items: flex-start; gap: 0.875rem; margin-bottom: 1.375rem; padding-bottom: 1.125rem; border-bottom: 1px solid var(--cv-border); }
.section-num { width: 2rem; height: 2rem; border-radius: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 0.8125rem; font-weight: 800; color: white; background: linear-gradient(135deg, #1A6B3A, #2E9E5A); box-shadow: 0 2px 8px rgba(26,107,58,0.35); }
.section-title { font-size: 0.9375rem; font-weight: 700; color: var(--cv-text-primary); }
.section-sub { font-size: 0.8125rem; color: var(--cv-text-muted); margin-top: 0.1875rem; }

/* ── Fields ──────────────────────────────────────────────────── */
.field { display: flex; flex-direction: column; gap: 0; }
.field-label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--cv-text-secondary); margin-bottom: 0.375rem; }
.field-label-note { font-size: 0.6875rem; font-weight: 500; color: var(--cv-text-muted); margin-left: 0.5rem; }
.req { color: #C0392B; margin-left: 2px; }
.field-hint { font-size: 0.6875rem; color: var(--cv-text-muted); margin-top: 0.3rem; }
.field-error { font-size: 0.6875rem; color: #C0392B; margin-top: 0.3rem; display: flex; align-items: center; gap: 0.25rem; }

.finput, .fselect {
    background: var(--cv-bg-card-alt); border: 1.5px solid var(--cv-border-input);
    border-radius: 10px; color: var(--cv-text-primary); font-size: 0.875rem;
    padding: 0.625rem 0.875rem; width: 100%;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; font-family: inherit;
}
.finput:focus, .fselect:focus { outline: none; border-color: #1A6B3A; background: var(--cv-bg-white); box-shadow: 0 0 0 3px rgba(26,107,58,0.12); }
.finput::placeholder { color: var(--cv-text-placeholder); }
.finput--err { border-color: #C0392B !important; background: rgba(192,57,43,0.04); }
.finput-icon-wrap { position: relative; }
.finput-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 1rem; height: 1rem; color: var(--cv-text-muted); pointer-events: none; }
.finput-iconed { padding-left: 2.5rem; }

/* ── Avatar upload ───────────────────────────────────────────── */
.avatar-upload-row { display: flex; align-items: center; gap: 1.125rem; margin-bottom: 1.25rem; }
.avatar-preview-box { width: 4rem; height: 4rem; border-radius: 50%; flex-shrink: 0; border: 1.5px dashed var(--cv-border-md); background: var(--cv-bg-card-alt); display: flex; align-items: center; justify-content: center; overflow: hidden; color: var(--cv-text-muted); }
.btn-upload { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8125rem; font-weight: 600; cursor: pointer; background: rgba(26,107,58,0.08); color: #1A6B3A; border: 1px solid rgba(26,107,58,0.22); padding: 0.5rem 0.875rem; border-radius: 8px; transition: all 0.15s; }
.btn-upload:hover { background: rgba(26,107,58,0.16); }
.btn-remove-avatar { display: inline-flex; font-size: 0.8125rem; font-weight: 600; cursor: pointer; color: #C0392B; background: transparent; border: none; padding: 0.5rem 0.625rem; margin-left: 0.375rem; transition: opacity 0.15s; }
.btn-remove-avatar:hover { opacity: 0.7; }

/* ── Permission matrix ───────────────────────────────────────── */
.template-bar {
    display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
    padding: 0.875rem 1rem; margin-bottom: 1rem;
    background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border);
    border-radius: 10px;
}
.template-bar-label { font-size: 0.75rem; font-weight: 700; color: var(--cv-text-muted); display: flex; align-items: center; gap: 0.35rem; white-space: nowrap; }
.template-btns { display: flex; gap: 0.375rem; flex-wrap: wrap; flex: 1; }
.template-btn {
    font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 7px;
    border: 1.5px solid var(--cv-border); background: var(--cv-bg-card);
    color: var(--cv-text-secondary); cursor: pointer; transition: all 0.15s; font-family: inherit;
}
.template-btn:hover { border-color: var(--cv-border-md); color: var(--cv-text-primary); }
.template-btn-active { background: rgba(26,107,58,0.1); border-color: rgba(26,107,58,0.4); color: #1A6B3A; }
.template-btn-clear { border-color: rgba(192,57,43,0.25); color: #C0392B; }
.template-btn-clear:hover { background: rgba(192,57,43,0.08); }
.template-btn-all { border-color: rgba(29,111,164,0.25); color: var(--cv-blue-text); }
.template-btn-all:hover { background: rgba(29,111,164,0.08); }

.perm-count-badge {
    display: inline-flex; align-items: center;
    font-size: 0.75rem; font-weight: 800;
    padding: 0.2rem 0.625rem; border-radius: 9999px; border: 1.5px solid;
}
.perm-count-active { background: rgba(29,111,164,0.1); color: var(--cv-blue-text); border-color: rgba(29,111,164,0.25); }
.perm-count-empty  { background: var(--cv-bg-card-alt); color: var(--cv-text-muted); border-color: var(--cv-border); }

.perm-matrix { border: 1px solid var(--cv-border); border-radius: 10px; overflow: hidden; }

.perm-header-row {
    display: grid;
    grid-template-columns: 1fr 80px 80px 80px 80px 44px;
    gap: 0; padding: 0.625rem 0.875rem;
    background: var(--cv-bg-card-alt);
    border-bottom: 1px solid var(--cv-border);
    font-size: 0.6875rem; font-weight: 700; color: var(--cv-text-muted);
}

.perm-group-header {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 0.875rem;
    background: rgba(26,107,58,0.04);
    border-bottom: 1px solid var(--cv-border);
    border-top: 1px solid var(--cv-border);
}
.perm-group-label { display: flex; gap: 0.5rem; align-items: center; font-size: 0.6875rem; font-weight: 800; color: var(--cv-text-secondary); text-transform: uppercase; letter-spacing: 0.08em; }
.perm-group-ar { color: var(--cv-text-muted); font-weight: 600; }
.perm-group-toggle {
    font-size: 0.6875rem; font-weight: 700; padding: 0.25rem 0.625rem; border-radius: 6px;
    border: 1px solid var(--cv-border); background: var(--cv-bg-card);
    color: var(--cv-text-muted); cursor: pointer; transition: all 0.15s; font-family: inherit;
}
.perm-group-toggle:hover { border-color: rgba(26,107,58,0.4); color: #1A6B3A; }
.group-toggle-on { background: rgba(26,107,58,0.08); border-color: rgba(26,107,58,0.4); color: #1A6B3A; }

.perm-row {
    display: grid;
    grid-template-columns: 1fr 80px 80px 80px 80px 44px;
    gap: 0; padding: 0.5rem 0.875rem;
    border-bottom: 1px solid var(--cv-border);
    align-items: center; transition: background 0.1s;
}
.perm-row:last-child { border-bottom: none; }
.perm-row:hover { background: rgba(255,255,255,0.02); }
.perm-row-full { background: rgba(26,107,58,0.03); }

.perm-module-col { display: flex; flex-direction: column; gap: 1px; }
.perm-module-en { font-size: 0.8125rem; font-weight: 600; color: var(--cv-text-primary); }
.perm-module-ar { font-size: 0.6875rem; color: var(--cv-text-muted); }

.perm-action-col { display: flex; align-items: center; justify-content: center; }

.perm-check-wrap { cursor: pointer; display: flex; align-items: center; justify-content: center; }
.perm-check {
    width: 1.25rem; height: 1.25rem; border-radius: 5px;
    border: 1.5px solid var(--cv-border-md);
    background: var(--cv-bg-card-alt);
    display: flex; align-items: center; justify-content: center;
    transition: all 0.12s; flex-shrink: 0;
}
.perm-check:hover { border-color: var(--cv-border-md); }
.perm-check-view   { background: rgba(26,107,58,0.12);  border-color: rgba(26,107,58,0.45);  color: #1A6B3A; }
.perm-check-create { background: rgba(29,111,164,0.12); border-color: rgba(29,111,164,0.45); color: #2B8FC5; }
.perm-check-edit   { background: rgba(232,131,10,0.12); border-color: rgba(232,131,10,0.45); color: #C46D08; }
.perm-check-delete { background: rgba(192,57,43,0.12);  border-color: rgba(192,57,43,0.45);  color: #C0392B; }

.perm-all-col { display: flex; align-items: center; justify-content: center; }
.perm-row-toggle {
    width: 1.625rem; height: 1.625rem; border-radius: 6px;
    border: 1px solid var(--cv-border); background: transparent;
    color: var(--cv-text-muted); cursor: pointer; display: flex;
    align-items: center; justify-content: center; transition: all 0.12s;
}
.perm-row-toggle:hover { border-color: rgba(26,107,58,0.4); color: #1A6B3A; }
.row-toggle-on { background: rgba(192,57,43,0.08); border-color: rgba(192,57,43,0.35); color: #C0392B; }

.perm-summary {
    display: flex; align-items: center; gap: 0.625rem;
    margin-top: 0.875rem; padding: 0.75rem 1rem;
    border-radius: 8px; border: 1px solid;
}
.perm-summary-none { color: var(--cv-text-muted); }
.perm-summary-ok { color: #2E9E5A; }
.perm-summary[class*="none"] { border-color: rgba(192,57,43,0.2); background: rgba(192,57,43,0.04); }
.perm-summary-text { font-size: 0.8125rem; font-weight: 600; }

/* Fallback: border color for summary based on child class */
.perm-summary:has(.perm-summary-none) { border-color: rgba(192,57,43,0.2); background: rgba(192,57,43,0.04); color: var(--cv-text-muted); }
.perm-summary:has(.perm-summary-ok) { border-color: rgba(26,107,58,0.25); background: rgba(26,107,58,0.05); color: #2E9E5A; }

/* ── Farm checkbox cards ─────────────────────────────────────── */
.farm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.625rem; }
.farm-check-card { display: flex; align-items: center; gap: 0.625rem; padding: 0.75rem 0.875rem; border-radius: 10px; border: 1.5px solid var(--cv-border); background: var(--cv-bg-card-alt); cursor: pointer; transition: all 0.15s; }
.farm-check-card:hover { border-color: var(--cv-border-md); }
.farm-card-active { background: rgba(26,107,58,0.06); border-color: rgba(26,107,58,0.4); box-shadow: 0 0 0 2px rgba(26,107,58,0.1); }
.farm-check-icon { width: 1.375rem; height: 1.375rem; border-radius: 5px; flex-shrink: 0; border: 1.5px solid var(--cv-border-md); display: flex; align-items: center; justify-content: center; background: var(--cv-bg-card); transition: all 0.15s; }
.fci-active { background: #1A6B3A; border-color: #1A6B3A; color: white; }
.farm-check-name { font-size: 0.875rem; font-weight: 600; color: var(--cv-text-primary); }
.farm-check-ar { font-size: 0.75rem; color: var(--cv-text-muted); margin-top: 1px; }

/* ── Password strength ───────────────────────────────────────── */
.pw-meter { display: flex; align-items: center; gap: 0.625rem; margin-top: 0.5rem; }
.pw-meter-track { flex: 1; height: 5px; background: var(--cv-border); border-radius: 9999px; overflow: hidden; }
.pw-meter-fill { height: 100%; border-radius: 9999px; transition: width 0.3s ease; }
.pw-weak { background: #E74C3C; } .pw-fair { background: #F5A623; } .pw-good { background: #3498DB; } .pw-strong { background: #1A6B3A; }
.pw-label { font-size: 0.6875rem; font-weight: 700; min-width: 3rem; text-align: end; }
.pw-label-weak { color: #C0392B; } .pw-label-fair { color: #C46D08; } .pw-label-good { color: #2980B9; } .pw-label-strong { color: #1A6B3A; }

/* ── Mode toggle ─────────────────────────────────────────────── */
.mode-toggle { display: flex; gap: 0.625rem; flex-wrap: wrap; }
.mode-opt { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; padding: 0.625rem 1rem; border: 1.5px solid var(--cv-border); border-radius: 10px; color: var(--cv-text-muted); background: var(--cv-bg-card-alt); transition: all 0.15s; flex: 1; justify-content: center; }
.mode-opt:hover { border-color: var(--cv-border-md); color: var(--cv-text-secondary); }
.mode-opt-active { background: rgba(26,107,58,0.08) !important; border-color: rgba(26,107,58,0.4) !important; color: #1A6B3A !important; box-shadow: 0 0 0 3px rgba(26,107,58,0.1); }
.mode-opt-active-blue { background: rgba(29,111,164,0.08) !important; border-color: rgba(29,111,164,0.4) !important; color: #2B8FC5 !important; box-shadow: 0 0 0 3px rgba(29,111,164,0.1); }

/* ── Active toggle ───────────────────────────────────────────── */
.active-toggle-row { display: flex; align-items: center; gap: 0.875rem; }
.toggle-switch { position: relative; display: inline-flex; width: 2.625rem; height: 1.5rem; border-radius: 9999px; cursor: pointer; border: none; flex-shrink: 0; transition: background 0.2s; }
.toggle-on { background: #1A6B3A; box-shadow: 0 0 0 3px rgba(26,107,58,0.15); }
.toggle-off { background: var(--cv-border-md); }
.toggle-thumb { position: absolute; top: 3px; width: 1.125rem; height: 1.125rem; border-radius: 50%; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: left 0.2s; }
.thumb-on { left: calc(100% - 1.125rem - 3px); }
.thumb-off { left: 3px; }
.toggle-label { font-size: 0.875rem; font-weight: 700; }
.tl-active { color: #1A6B3A; } .tl-inactive { color: var(--cv-text-muted); }
.toggle-hint { font-size: 0.75rem; color: var(--cv-text-muted); margin-top: 1px; }

/* ── Form footer ─────────────────────────────────────────────── */
.form-footer { display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; background: var(--cv-bg-card); border: 1px solid var(--cv-border); border-radius: 14px; box-shadow: 0 2px 8px rgba(10,58,30,0.05); }
.btn-cancel { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; padding: 0.625rem 1.125rem; border-radius: 10px; background: var(--cv-bg-card-alt); border: 1.5px solid var(--cv-border); color: var(--cv-text-secondary); text-decoration: none; cursor: pointer; transition: border-color 0.15s, color 0.15s; }
.btn-cancel:hover { border-color: var(--cv-border-md); color: var(--cv-text-primary); }
.btn-submit { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9375rem; font-weight: 800; padding: 0.6875rem 1.75rem; border-radius: 10px; background: linear-gradient(135deg, #1A6B3A, #2E9E5A); border: none; color: white; cursor: pointer; font-family: inherit; box-shadow: 0 4px 14px rgba(26,107,58,0.3); transition: all 0.15s; }
.btn-submit:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(26,107,58,0.4); }
.btn-submit:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
</style>