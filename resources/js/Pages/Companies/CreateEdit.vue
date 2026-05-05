<script setup>
/**
 * Companies/CreateEdit.vue — VERO Cattle Management
 * Unified create / edit form (super-admin only)
 *
 * Props:
 *   company : Company | null   — null = create mode
 *   mode    : 'create'|'edit'
 *
 * Changes vs original:
 *   • max_users field added to Section 4 (Subscription)
 *   • usedQuota computed shows live "X / Y used" feedback in edit mode
 *   • formatCompany() in CompanyController now passes users_count + max_users
 */
import { ref, computed } from 'vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    company: { type: Object, default: null },
    mode:    { type: String, default: 'create' },
})

const isEdit = computed(() => props.mode === 'edit')

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    name_en:                 props.company?.name_en                 ?? '',
    name_ar:                 props.company?.name_ar                 ?? '',
    logo:                    null,
    address_en:              props.company?.address_en              ?? '',
    address_ar:              props.company?.address_ar              ?? '',
    country:                 props.company?.country                 ?? 'EG',
    phone:                   props.company?.phone                   ?? '',
    email:                   props.company?.email                   ?? '',
    website:                 props.company?.website                 ?? '',
    tax_number:              props.company?.tax_number              ?? '',
    commercial_register:     props.company?.commercial_register     ?? '',
    currency:                props.company?.currency                ?? 'EGP',
    fiscal_year_start_month: props.company?.fiscal_year_start_month ?? 1,
    accounting_mode:         props.company?.accounting_mode         ?? 'standalone',
    is_active:               props.company?.is_active               ?? true,
    subscription_starts_at:  props.company?.subscription_starts_at ?? '',
    subscription_ends_at:    props.company?.subscription_ends_at   ?? '',
    // ── NEW — user quota ─────────────────────────────────────────
    // null = unlimited; integer 1-999 = hard cap
    max_users:               props.company?.max_users               ?? null,
})

// ── Logo preview ──────────────────────────────────────────────────
const logoPreview = ref(props.company?.logo ? `/storage/${props.company.logo}` : null)

function onLogoChange(e) {
    const file = e.target.files[0]
    if (!file) return
    form.logo     = file
    logoPreview.value = URL.createObjectURL(file)
}
function clearLogo() { form.logo = null; logoPreview.value = null }

// ── Submit ────────────────────────────────────────────────────────
// The correct Inertia pattern for PUT/PATCH with file uploads:
// Use form.post() (not form.put()) because multipart/form-data
// cannot be sent as PUT by browsers. Laravel method spoofing is
// handled by including _method='PUT' in the request body.
//
// form.transform() injects _method into the data payload before
// serialization — this is the ONLY reliable way to do it with
// Inertia's useForm. Setting form._method directly or passing
// _method as an option to form.post() are both unreliable.
//
// The enctype attribute must NOT be on the <form> tag — Inertia
// manages its own XHR submission and enctype on the DOM element
// interferes with forceFormData serialization.
function submit() {
    if (isEdit.value) {
        form
            .transform(data => ({ ...data, _method: 'PUT' }))
            .post(route('companies.update', props.company.id), {
                forceFormData: true,
            })
    } else {
        form.post(route('companies.store'), {
            forceFormData: true,
        })
    }
}

// ── Subscription expiry preview ───────────────────────────────────
const expiryInfo = computed(() => {
    if (!form.subscription_ends_at) return null
    const today = new Date(); today.setHours(0,0,0,0)
    const end   = new Date(form.subscription_ends_at)
    const days  = Math.ceil((end - today) / 86400000)
    if (days < 0)   return { label: `Expired ${Math.abs(days)} days ago`, type: 'danger' }
    if (days === 0)  return { label: 'Expires today', type: 'danger' }
    if (days <= 14)  return { label: `${days} days remaining`, type: 'warning' }
    return { label: `${days} days remaining`, type: 'success' }
})

// ── User quota live preview (edit mode only) ──────────────────────
// Shows how many users are already created vs the new cap being set.
const quotaInfo = computed(() => {
    const used  = props.company?.users_count ?? 0
    const cap   = form.max_users             // null = unlimited
    if (!cap) return null                    // no cap set — nothing to show
    const pct   = Math.min(100, Math.round((used / cap) * 100))
    const over  = used > cap
    return { used, cap, pct, over }
})

// ── Static data ───────────────────────────────────────────────────
const months = [
    'January','February','March','April','May','June',
    'July','August','September','October','November','December',
]
const currencies = ['EGP','USD','EUR','SAR','AED','GBP','KWD','QAR','BHD','OMR']
const countries  = [
    { code:'EG',name:'Egypt' }, { code:'SA',name:'Saudi Arabia' },
    { code:'AE',name:'UAE' },   { code:'KW',name:'Kuwait' },
    { code:'QA',name:'Qatar' }, { code:'BH',name:'Bahrain' },
    { code:'OM',name:'Oman' },  { code:'JO',name:'Jordan' },
    { code:'LB',name:'Lebanon'},{ code:'IQ',name:'Iraq' },
    { code:'SD',name:'Sudan' }, { code:'LY',name:'Libya' },
    { code:'MA',name:'Morocco'},{ code:'TN',name:'Tunisia' },
    { code:'DZ',name:'Algeria'},{ code:'US',name:'United States' },
    { code:'GB',name:'United Kingdom' },
]

function initials(c) {
    if (!c?.name_en) return '?'
    return c.name_en.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase()
}
</script>

<template>
    <Head :title="isEdit ? `Edit — ${company?.name_en}` : 'New Company'" />
    <AuthenticatedLayout>
        <template #header>
            <span class="eyebrow-crumb">Super Admin</span>
            <svg class="w-3 h-3 mx-1.5 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <Link :href="route('companies.index')" class="crumb-link">Companies</Link>
            <svg class="w-3 h-3 mx-1.5 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <span style="font-size:0.8125rem;font-weight:500;color:var(--cv-text-secondary)">
                {{ isEdit ? 'Edit Company' : 'New Company' }}
            </span>
        </template>

        <div class="pg-shell">

            <!-- ══ HERO HEADER ══════════════════════════════════════ -->
            <div class="pg-hero">
                <div class="pg-hero-glow"></div>
                <div class="pg-hero-dots"></div>
                <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-7 relative z-10">
                    <div class="flex items-center gap-4">
                        <div v-if="logoPreview" class="hero-logo">
                            <img :src="logoPreview" alt="Logo" class="w-full h-full object-cover"/>
                        </div>
                        <div v-else class="hero-avatar"
                             :style="form.name_en ? 'background:linear-gradient(135deg,#1A6B3A,#4DBF7A)' : 'background:rgba(255,255,255,0.1)'">
                            {{ form.name_en ? initials({name_en: form.name_en}) : '?' }}
                        </div>
                        <div>
                            <p class="hero-eyebrow">{{ isEdit ? 'Editing Company' : 'New Company' }}</p>
                            <h1 class="hero-title">
                                {{ form.name_en || (isEdit ? company?.name_en : 'New Company') }}
                            </h1>
                            <p v-if="form.name_ar || company?.name_ar" class="hero-sub" dir="rtl">
                                {{ form.name_ar || company?.name_ar }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ FORM ════════════════════════════════════════════ -->
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <form @submit.prevent="submit" class="form-stack">

                    <!-- ╔══ SECTION 1 — Identity ══════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">1</div>
                            <div>
                                <h2 class="section-title">Identity</h2>
                                <p class="section-sub">Company names and logo</p>
                            </div>
                        </div>

                        <!-- Logo upload -->
                        <div class="field">
                            <label class="field-label">Company Logo</label>
                            <div class="logo-upload-row">
                                <div class="logo-preview-box">
                                    <img v-if="logoPreview" :src="logoPreview" alt="Logo preview"
                                         class="w-full h-full object-cover"/>
                                    <svg v-else class="w-7 h-7 opacity-25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                                    </svg>
                                </div>
                                <div>
                                    <label class="btn-upload">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                        </svg>
                                        Upload Logo
                                        <input type="file" class="sr-only" accept="image/*" @change="onLogoChange"/>
                                    </label>
                                    <button v-if="logoPreview" type="button" class="btn-remove-logo" @click="clearLogo">
                                        Remove
                                    </button>
                                    <p class="field-hint">PNG, JPG, WebP — max 2 MB</p>
                                    <p v-if="form.errors.logo" class="field-error">{{ form.errors.logo }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="field">
                                <label class="field-label" for="name_en">
                                    Company Name (English) <span class="req">*</span>
                                </label>
                                <input id="name_en" v-model="form.name_en" type="text"
                                       class="finput" :class="{'finput--err': form.errors.name_en}"
                                       placeholder="e.g. VERO Cattle Co."/>
                                <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="name_ar">
                                    اسم الشركة بالعربية <span class="req">*</span>
                                </label>
                                <input id="name_ar" v-model="form.name_ar" type="text" dir="rtl"
                                       class="finput text-right" :class="{'finput--err': form.errors.name_ar}"
                                       placeholder="مثال: شركة فيرو للماشية"/>
                                <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 2 — Contact ═══════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">2</div>
                            <div>
                                <h2 class="section-title">Contact &amp; Location</h2>
                                <p class="section-sub">Email, phone, address and country</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="field">
                                <label class="field-label" for="email">Email Address</label>
                                <div class="finput-icon-wrap">
                                    <svg class="finput-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    <input id="email" v-model="form.email" type="email"
                                           class="finput finput-iconed" :class="{'finput--err': form.errors.email}"
                                           placeholder="info@company.com"/>
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

                            <div class="field">
                                <label class="field-label" for="website">Website</label>
                                <div class="finput-icon-wrap">
                                    <svg class="finput-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/>
                                    </svg>
                                    <input id="website" v-model="form.website" type="url"
                                           class="finput finput-iconed" :class="{'finput--err': form.errors.website}"
                                           placeholder="https://example.com"/>
                                </div>
                                <p v-if="form.errors.website" class="field-error">{{ form.errors.website }}</p>
                            </div>

                            <div class="field">
                                <label class="field-label" for="country">Country</label>
                                <select id="country" v-model="form.country" class="fselect">
                                    <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                                </select>
                            </div>

                            <div class="field sm:col-span-2">
                                <label class="field-label" for="address_en">Address (English)</label>
                                <textarea id="address_en" v-model="form.address_en"
                                          class="ftextarea" rows="2"
                                          placeholder="Street, City, Postal Code"></textarea>
                            </div>

                            <div class="field sm:col-span-2">
                                <label class="field-label" for="address_ar">العنوان بالعربية</label>
                                <textarea id="address_ar" v-model="form.address_ar" dir="rtl"
                                          class="ftextarea text-right" rows="2"
                                          placeholder="الشارع، المدينة، الرمز البريدي"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 3 — Legal & Finance ══════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">3</div>
                            <div>
                                <h2 class="section-title">Legal &amp; Finance</h2>
                                <p class="section-sub">Registration numbers, currency and fiscal year</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="field">
                                <label class="field-label" for="tax_number">Tax / VAT Number</label>
                                <input id="tax_number" v-model="form.tax_number" type="text"
                                       class="finput" placeholder="e.g. 123456789"/>
                            </div>
                            <div class="field">
                                <label class="field-label" for="commercial_register">Commercial Register</label>
                                <input id="commercial_register" v-model="form.commercial_register" type="text"
                                       class="finput" placeholder="e.g. 7890"/>
                            </div>
                            <div class="field">
                                <label class="field-label" for="currency">Default Currency</label>
                                <select id="currency" v-model="form.currency" class="fselect">
                                    <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                                </select>
                                <p v-if="form.errors.currency" class="field-error">{{ form.errors.currency }}</p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="fy_month">Fiscal Year Starts</label>
                                <select id="fy_month" v-model.number="form.fiscal_year_start_month" class="fselect">
                                    <option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ╔══ SECTION 4 — Subscription & Quota ═════╗ -->
                    <!-- Amber-tinted section — super admin controls only -->
                    <div class="form-section section-amber">
                        <div class="section-head">
                            <div class="section-num section-num-amber">4</div>
                            <div>
                                <h2 class="section-title">Subscription &amp; User Quota</h2>
                                <p class="section-sub">
                                    Access period and maximum number of users for this company.
                                    Leave dates empty for unrestricted access. Leave user quota empty for unlimited users.
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <!-- Subscription Start -->
                            <div class="field">
                                <label class="field-label" for="sub_start">
                                    <svg class="w-3.5 h-3.5 inline mr-1 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                    </svg>
                                    Subscription Start
                                </label>
                                <input id="sub_start" v-model="form.subscription_starts_at" type="date"
                                       class="finput" :class="{'finput--err': form.errors.subscription_starts_at}"/>
                                <p v-if="form.errors.subscription_starts_at" class="field-error">{{ form.errors.subscription_starts_at }}</p>
                            </div>

                            <!-- Subscription End -->
                            <div class="field">
                                <label class="field-label" for="sub_end">
                                    <svg class="w-3.5 h-3.5 inline mr-1 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                    </svg>
                                    Subscription End
                                </label>
                                <input id="sub_end" v-model="form.subscription_ends_at" type="date"
                                       class="finput" :class="{'finput--err': form.errors.subscription_ends_at}"
                                       :min="form.subscription_starts_at || undefined"/>
                                <p v-if="form.errors.subscription_ends_at" class="field-error">{{ form.errors.subscription_ends_at }}</p>
                            </div>

                            <!-- ── NEW: Max Users ──────────────────────────────── -->
                            <div class="field sm:col-span-2">
                                <label class="field-label" for="max_users">
                                    <svg class="w-3.5 h-3.5 inline mr-1 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                                    </svg>
                                    Maximum Users Allowed
                                </label>
                                <div class="quota-input-row">
                                    <input id="max_users"
                                           v-model.number="form.max_users"
                                           type="number"
                                           min="1" max="999"
                                           class="finput quota-input"
                                           :class="{'finput--err': form.errors.max_users}"
                                           placeholder="Leave empty for unlimited"/>
                                    <!-- Unlimited badge shown when input is empty -->
                                    <div v-if="!form.max_users" class="quota-unlimited-badge">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0015 0m-15 0a7.5 7.5 0 1115 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077l1.41-.513m14.095-5.13l1.41-.513M5.106 17.785l1.15-.964m11.49-9.642l1.149-.964M7.501 19.795l.75-1.3m7.5-12.99l.75-1.3m-6.063 16.658l.26-1.477m2.605-14.772l.26-1.477m0 17.726l-.26-1.477M10.698 4.614l-.26-1.477M16.5 19.794l-.75-1.299M7.5 4.205L12 12m6.894 5.785l-1.149-.964M6.256 7.178l-1.15-.964m15.352 8.864l-1.41-.513M4.954 9.435l-1.41-.514M12.002 12l-3.75 6.495"/>
                                        </svg>
                                        Unlimited
                                    </div>
                                </div>
                                <p class="field-hint">
                                    The company admin (Farm Owner) can create users up to this number.
                                    You can change this at any time from here.
                                </p>
                                <p class="field-hint" dir="rtl" style="text-align:right">
                                    يمكن لمسؤول الشركة إنشاء مستخدمين حتى هذا الحد. يمكنك تغيير هذا في أي وقت.
                                </p>
                                <p v-if="form.errors.max_users" class="field-error">{{ form.errors.max_users }}</p>
                            </div>
                        </div>

                        <!-- Live expiry pill -->
                        <Transition name="fade">
                            <div v-if="expiryInfo" class="expiry-pill" :class="`expiry-${expiryInfo.type}`">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path v-if="expiryInfo.type==='success'" stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
                                {{ expiryInfo.label }}
                            </div>
                        </Transition>

                        <!-- ── Live quota bar (edit mode, when cap is set) ──── -->
                        <Transition name="fade">
                            <div v-if="isEdit && quotaInfo" class="quota-bar-wrap">
                                <div class="quota-bar-header">
                                    <span class="quota-bar-label">Current Usage</span>
                                    <span class="quota-bar-count" :class="quotaInfo.over ? 'quota-over' : ''">
                                        {{ quotaInfo.used }} / {{ quotaInfo.cap }} users
                                        <span v-if="quotaInfo.over" class="quota-over-tag">OVER LIMIT</span>
                                    </span>
                                </div>
                                <div class="quota-bar-track">
                                    <div class="quota-bar-fill"
                                         :class="quotaInfo.over ? 'quota-fill-red' : quotaInfo.pct >= 80 ? 'quota-fill-amber' : 'quota-fill-green'"
                                         :style="{ width: quotaInfo.pct + '%' }">
                                    </div>
                                </div>
                                <p v-if="quotaInfo.over" class="field-hint" style="color:var(--cv-danger-text)">
                                    This company already exceeds the new quota you are setting.
                                    Existing users are not removed — but no new users can be added until the count drops below the limit.
                                </p>
                            </div>
                        </Transition>
                    </div>

                    <!-- ╔══ SECTION 5 — Settings ══════════════════╗ -->
                    <div class="form-section">
                        <div class="section-head">
                            <div class="section-num">5</div>
                            <div>
                                <h2 class="section-title">Settings</h2>
                                <p class="section-sub">Accounting mode and activation status</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <!-- Accounting mode -->
                            <div class="field">
                                <label class="field-label">Accounting Mode</label>
                                <div class="mode-toggle">
                                    <label class="mode-opt" :class="{'mode-opt-active': form.accounting_mode==='standalone'}">
                                        <input type="radio" v-model="form.accounting_mode" value="standalone" class="sr-only"/>
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z"/>
                                        </svg>
                                        Standalone
                                    </label>
                                    <label class="mode-opt" :class="{'mode-opt-active-odoo': form.accounting_mode==='odoo'}">
                                        <input type="radio" v-model="form.accounting_mode" value="odoo" class="sr-only"/>
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
                                        </svg>
                                        Odoo Integration
                                    </label>
                                </div>
                            </div>

                            <!-- Is active toggle -->
                            <div class="field">
                                <label class="field-label">Company Status</label>
                                <div class="active-toggle-row">
                                    <button type="button"
                                            role="switch"
                                            :aria-checked="form.is_active"
                                            @click="form.is_active = !form.is_active"
                                            class="toggle-switch"
                                            :class="form.is_active ? 'toggle-on' : 'toggle-off'">
                                        <span class="toggle-thumb"
                                              :class="form.is_active ? 'thumb-on' : 'thumb-off'"></span>
                                    </button>
                                    <div>
                                        <p class="toggle-label" :class="form.is_active ? 'tl-active':'tl-inactive'">
                                            {{ form.is_active ? 'Active' : 'Inactive' }}
                                        </p>
                                        <p class="toggle-hint">
                                            {{ form.is_active ? 'Company is accessible to users' : 'Users cannot access this company' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Form actions ─────────────────────────── -->
                    <div class="form-footer">
                        <Link :href="route('companies.index')" class="btn-cancel">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                            </svg>
                            Back to Companies
                        </Link>
                        <button type="submit"
                                class="btn-submit"
                                :class="{'btn-loading': form.processing}"
                                :disabled="form.processing">
                            <svg v-if="!form.processing" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                            {{ isEdit ? 'Save Changes' : 'Create Company' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>

/* ── Page shell ─────────────────────────────────────────────────── */
.pg-shell { min-height: 100vh; background-color: var(--cv-bg); }

/* ── Hero ───────────────────────────────────────────────────────── */
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
.hero-logo {
    width: 3.5rem; height: 3.5rem; border-radius: 12px;
    overflow: hidden; border: 2px solid rgba(255,255,255,0.2); flex-shrink: 0;
}
.hero-avatar {
    width: 3.5rem; height: 3.5rem; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; font-weight: 800; color: white; flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.15);
}
.hero-eyebrow {
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.16em; text-transform: uppercase;
    color: #4DBF7A; margin-bottom: 0.25rem;
}
.hero-title {
    font-size: 1.5rem; font-weight: 800; color: #ffffff;
    line-height: 1.15; letter-spacing: -0.015em;
}
.hero-sub { font-size: 0.8125rem; color: rgba(255,255,255,0.5); margin-top: 0.2rem; }

/* ── Breadcrumb ─────────────────────────────────────────────────── */
.eyebrow-crumb {
    font-size: 0.625rem; font-weight: 700;
    letter-spacing: 0.12em; text-transform: uppercase; color: var(--cv-amber);
}
.crumb-link {
    font-size: 0.8125rem; color: var(--cv-text-muted); text-decoration: none;
    transition: color 0.15s;
}
.crumb-link:hover { color: var(--cv-text-secondary); }

/* ── Form stack ─────────────────────────────────────────────────── */
.form-stack { display: flex; flex-direction: column; gap: 1.25rem; }

/* ── Form section card ──────────────────────────────────────────── */
.form-section {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 14px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(10,58,30,0.05);
}
.section-amber {
    border-color: rgba(232,131,10,0.25);
    background: linear-gradient(to bottom right, var(--cv-bg-card), rgba(232,131,10,0.02));
}
.section-head {
    display: flex; align-items: flex-start; gap: 0.875rem;
    margin-bottom: 1.375rem;
    padding-bottom: 1.125rem;
    border-bottom: 1px solid var(--cv-border);
}
.section-num {
    width: 2rem; height: 2rem; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8125rem; font-weight: 800; color: white;
    background: linear-gradient(135deg, #1A6B3A, #2E9E5A);
    box-shadow: 0 2px 8px rgba(26,107,58,0.35);
}
.section-num-amber {
    background: linear-gradient(135deg, #C46D08, #F5A623);
    box-shadow: 0 2px 8px rgba(232,131,10,0.35);
}
.section-title { font-size: 0.9375rem; font-weight: 700; color: var(--cv-text-primary); line-height: 1.2; }
.section-sub   { font-size: 0.8125rem; color: var(--cv-text-muted); margin-top: 0.1875rem; }

/* ── Fields ─────────────────────────────────────────────────────── */
.field { display: flex; flex-direction: column; gap: 0; }
.field-label {
    display: block; font-size: 0.75rem; font-weight: 600;
    color: var(--cv-text-secondary); margin-bottom: 0.375rem;
}
.req { color: #C0392B; margin-left: 2px; }
.field-hint  { font-size: 0.6875rem; color: var(--cv-text-muted); margin-top: 0.3rem; }
.field-error { font-size: 0.6875rem; color: #C0392B; margin-top: 0.3rem;
               display: flex; align-items: center; gap: 0.25rem; }

/* ── Inputs ─────────────────────────────────────────────────────── */
.finput,
.fselect,
.ftextarea {
    background: var(--cv-bg-card-alt);
    border: 1.5px solid var(--cv-border-input);
    border-radius: 10px;
    color: var(--cv-text-primary);
    font-size: 0.875rem; padding: 0.625rem 0.875rem;
    width: 100%; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    font-family: inherit;
}
.finput:focus, .fselect:focus, .ftextarea:focus,
.finput:focus-visible, .fselect:focus-visible {
    outline: none;
    border-color: #1A6B3A;
    background: var(--cv-bg-white);
    box-shadow: 0 0 0 3px rgba(26,107,58,0.12);
}
.finput::placeholder, .ftextarea::placeholder { color: var(--cv-text-placeholder); }
.finput--err {
    border-color: #C0392B !important;
    background: rgba(192,57,43,0.04);
}

/* Input with icon */
.finput-icon-wrap { position: relative; }
.finput-icon {
    position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%);
    width: 1rem; height: 1rem; color: var(--cv-text-muted); pointer-events: none;
}
.finput-iconed { padding-left: 2.5rem; }

/* ── Logo upload ────────────────────────────────────────────────── */
.logo-upload-row  { display: flex; align-items: center; gap: 1.125rem; }
.logo-preview-box {
    width: 4.5rem; height: 4.5rem; border-radius: 12px; flex-shrink: 0;
    border: 1.5px dashed var(--cv-border-md);
    background: var(--cv-bg-card-alt);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; color: var(--cv-text-muted);
    transition: border-color 0.15s;
}
.btn-upload {
    display: inline-flex; align-items: center; gap: 0.4rem;
    font-size: 0.8125rem; font-weight: 600; cursor: pointer;
    background: rgba(26,107,58,0.08); color: #1A6B3A;
    border: 1px solid rgba(26,107,58,0.22);
    padding: 0.5rem 0.875rem; border-radius: 8px;
    transition: all 0.15s;
}
.btn-upload:hover { background: rgba(26,107,58,0.16); border-color: rgba(26,107,58,0.4); }
.btn-remove-logo {
    display: inline-flex; align-items: center;
    font-size: 0.8125rem; font-weight: 600; cursor: pointer;
    color: #C0392B; background: transparent; border: none;
    padding: 0.5rem 0.625rem; margin-left: 0.375rem;
    transition: opacity 0.15s;
}
.btn-remove-logo:hover { opacity: 0.7; }

/* ── Mode toggle ────────────────────────────────────────────────── */
.mode-toggle { display: flex; gap: 0.625rem; flex-wrap: wrap; }
.mode-opt {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.875rem; font-weight: 600; cursor: pointer;
    padding: 0.625rem 1rem;
    border: 1.5px solid var(--cv-border);
    border-radius: 10px;
    color: var(--cv-text-muted); background: var(--cv-bg-card-alt);
    transition: all 0.15s ease; flex: 1; justify-content: center;
}
.mode-opt:hover { border-color: var(--cv-border-md); color: var(--cv-text-secondary); }
.mode-opt-active {
    background: rgba(26,107,58,0.08) !important;
    border-color: rgba(26,107,58,0.4) !important;
    color: #1A6B3A !important;
    box-shadow: 0 0 0 3px rgba(26,107,58,0.1);
}
.mode-opt-active-odoo {
    background: rgba(29,111,164,0.08) !important;
    border-color: rgba(29,111,164,0.4) !important;
    color: #1D6FA4 !important;
    box-shadow: 0 0 0 3px rgba(29,111,164,0.1);
}

/* ── Active toggle ──────────────────────────────────────────────── */
.active-toggle-row  { display: flex; align-items: center; gap: 0.875rem; }
.toggle-switch {
    position: relative; display: inline-flex;
    width: 2.625rem; height: 1.5rem; border-radius: 9999px;
    cursor: pointer; border: none; flex-shrink: 0;
    transition: background 0.2s ease;
}
.toggle-on  { background: #1A6B3A; box-shadow: 0 0 0 3px rgba(26,107,58,0.15); }
.toggle-off { background: var(--cv-border-md); }
.toggle-thumb {
    position: absolute; top: 3px;
    width: 1.125rem; height: 1.125rem; border-radius: 50%;
    background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    transition: left 0.2s ease;
}
.thumb-on  { left: calc(100% - 1.125rem - 3px); }
.thumb-off { left: 3px; }
.toggle-label { font-size: 0.875rem; font-weight: 700; line-height: 1.2; }
.tl-active  { color: #1A6B3A; }
.tl-inactive{ color: var(--cv-text-muted); }
.toggle-hint { font-size: 0.75rem; color: var(--cv-text-muted); margin-top: 1px; }

/* ── Expiry pill ────────────────────────────────────────────────── */
.expiry-pill {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 0.875rem; border-radius: 9999px;
    font-size: 0.8125rem; font-weight: 700; margin-top: 0.75rem;
    border: 1px solid;
}
.expiry-success { background: rgba(26,107,58,0.08); color: #1A6B3A; border-color: rgba(26,107,58,0.25); }
.expiry-warning { background: rgba(232,131,10,0.08); color: #C46D08; border-color: rgba(232,131,10,0.3); }
.expiry-danger  { background: rgba(192,57,43,0.08); color: #C0392B; border-color: rgba(192,57,43,0.3); }

/* ── Quota input row ────────────────────────────────────────────── */
.quota-input-row {
    display: flex; align-items: center; gap: 0.75rem;
}
.quota-input {
    max-width: 14rem; /* keeps the number field tight */
}
.quota-unlimited-badge {
    display: inline-flex; align-items: center; gap: 0.375rem;
    font-size: 0.75rem; font-weight: 700;
    color: var(--cv-text-muted);
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border);
    padding: 0.3125rem 0.625rem; border-radius: 6px;
}

/* ── Quota progress bar ─────────────────────────────────────────── */
.quota-bar-wrap {
    margin-top: 1.125rem;
    padding: 1rem;
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border);
    border-radius: 10px;
}
.quota-bar-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 0.5rem;
}
.quota-bar-label {
    font-size: 0.75rem; font-weight: 600; color: var(--cv-text-secondary);
}
.quota-bar-count {
    font-size: 0.8125rem; font-weight: 700;
    color: var(--cv-blue-text);
}
.quota-over { color: var(--cv-danger-text) !important; }
.quota-over-tag {
    font-size: 0.625rem; font-weight: 800; letter-spacing: 0.1em;
    background: rgba(192,57,43,0.12); color: var(--cv-danger-text);
    padding: 0.15rem 0.375rem; border-radius: 4px; margin-left: 0.375rem;
}
.quota-bar-track {
    height: 6px; border-radius: 9999px;
    background: var(--cv-border);
    overflow: hidden;
}
.quota-bar-fill {
    height: 100%; border-radius: 9999px;
    transition: width 0.4s ease;
}
.quota-fill-green { background: linear-gradient(90deg, #1A6B3A, #4DBF7A); }
.quota-fill-amber { background: linear-gradient(90deg, #C46D08, #F5A623); }
.quota-fill-red   { background: linear-gradient(90deg, #C0392B, #E74C3C); }

/* ── Form footer ────────────────────────────────────────────────── */
.form-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(10,58,30,0.05);
}
.btn-cancel {
    display: inline-flex; align-items: center; gap: 0.5rem;
    font-size: 0.875rem; font-weight: 600;
    padding: 0.625rem 1.125rem; border-radius: 10px;
    background: var(--cv-bg-card-alt);
    border: 1.5px solid var(--cv-border);
    color: var(--cv-text-secondary);
    text-decoration: none; cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
}
.btn-cancel:hover { border-color: var(--cv-border-md); color: var(--cv-text-primary); }
.btn-submit {
    display: inline-flex; align-items: center; gap: 0.5rem;
    font-size: 0.9375rem; font-weight: 800;
    padding: 0.6875rem 1.75rem; border-radius: 10px;
    background: linear-gradient(135deg, #1A6B3A, #2E9E5A);
    border: none; color: white; cursor: pointer;
    transition: all 0.15s ease; font-family: inherit;
    box-shadow: 0 4px 14px rgba(26,107,58,0.3);
    position: relative;
}
.btn-submit:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(26,107,58,0.4);
}
.btn-submit:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

/* ── Transitions ────────────────────────────────────────────────── */
.fade-enter-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from   { opacity: 0; transform: translateY(-6px); }
.fade-leave-to     { opacity: 0; }
</style>