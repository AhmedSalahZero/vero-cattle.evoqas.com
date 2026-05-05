<script setup>
/**
 * Farms/Index.vue — FIXED
 *
 * FIX A — Bilingual reactivity:
 *   lang ref now watches usePage().props.auth.user.language (computed).
 *   When AuthenticatedLayout posts to /language, Inertia re-shares the prop
 *   with preserveState:true — that is the reactive signal. Watching the DOM
 *   attribute document.documentElement.lang is not reactive in Vue.
 *
 * FIX B — :title removed from <AuthenticatedLayout> (fragment root warning).
 *
 * FIX C — route('farms.toggleActive') → route('farms.toggle-active').
 *
 * FIX D — useLocale() composable removed; inline t() map used instead.
 */
import { ref, computed, watch } from 'vue'
import { router, Link, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
    farms:       { type: Array,  default: () => [] },
    companies:   { type: Array,  default: () => [] },
    myCompanyId: { type: Number, default: null },
})

// ── Reactive language ─────────────────────────────────────────────
const page     = usePage()
const authLang = computed(() => page.props.auth?.user?.language ?? 'en')
const lang     = ref(authLang.value)
const isRtl    = computed(() => lang.value === 'ar')
watch(authLang, (val) => { lang.value = val })

// ── Translation map ───────────────────────────────────────────────
const T = {
    'farms.module':            { en: 'Farm Management',                         ar: 'إدارة المزارع' },
    'farms.title':             { en: 'Farms',                                   ar: 'المزارع' },
    'farms.subtitle':          { en: 'Manage all farms within your organization',ar: 'إدارة جميع المزارع في مؤسستك' },
    'farms.create':            { en: 'New Farm',                                ar: 'مزرعة جديدة' },
    'farms.total':             { en: 'Total Farms',                             ar: 'إجمالي المزارع' },
    'farms.active':            { en: 'Active',                                  ar: 'مفعّلة' },
    'farms.inactive':          { en: 'Inactive',                                ar: 'معطّلة' },
    'farms.total_capacity':    { en: 'Total Capacity (head)',                   ar: 'الطاقة الإجمالية (رأس)' },
    'farms.search_placeholder':{ en: 'Search farms…',                           ar: 'ابحث عن مزرعة…' },
    'farms.all_companies':     { en: 'All Companies',                           ar: 'جميع الشركات' },
    'farms.filter_all':        { en: 'All',                                     ar: 'الكل' },
    'farms.filter_active':     { en: 'Active',                                  ar: 'مفعّلة' },
    'farms.filter_inactive':   { en: 'Inactive',                                ar: 'معطّلة' },
    'farms.results':           { en: 'results',                                 ar: 'نتيجة' },
    'farms.no_results':        { en: 'No farms match your filters',             ar: 'لا توجد مزارع تطابق الفلتر' },
    'farms.no_farms':          { en: 'No farms yet',                            ar: 'لا توجد مزارع بعد' },
    'farms.try_clearing':      { en: 'Try clearing your filters',               ar: 'حاول إزالة الفلتر' },
    'farms.create_first':      { en: 'Create your first farm to get started',   ar: 'أنشئ أول مزرعة للبدء' },
    'farms.capacity':          { en: 'Capacity',                                ar: 'الطاقة' },
    'farms.acres':             { en: 'Acres',                                   ar: 'فدان' },
    'farms.users':             { en: 'Users',                                   ar: 'المستخدمون' },
    'farms.deactivate':        { en: 'Deactivate',                              ar: 'تعطيل' },
    'farms.activate':          { en: 'Activate',                                ar: 'تفعيل' },
    'farms.delete_title':      { en: 'Delete Farm',                             ar: 'حذف المزرعة' },
    'farms.delete_confirm':    { en: 'Are you sure you want to delete',         ar: 'هل أنت متأكد من حذف' },
    'farms.delete_warning':    { en: 'This action cannot be undone.',           ar: 'لا يمكن التراجع عن هذا الإجراء.' },
    'farms.delete_guard':      { en: 'Farms with animals or lots cannot be deleted.', ar: 'لا يمكن حذف المزارع التي تحتوي على ماشيةات أو أفواج.' },
    'common.active':           { en: 'Active',    ar: 'مفعّل' },
    'common.inactive':         { en: 'Inactive',  ar: 'معطّل' },
    'common.view':             { en: 'View',      ar: 'عرض' },
    'common.edit':             { en: 'Edit',      ar: 'تعديل' },
    'common.delete':           { en: 'Delete',    ar: 'حذف' },
    'common.cancel':           { en: 'Cancel',    ar: 'إلغاء' },
    'common.deleting':         { en: 'Deleting…', ar: 'جارٍ الحذف…' },
}
const t = (key, fallback = '') => {
    const e = T[key]; if (!e) return fallback
    return lang.value === 'ar' ? e.ar : e.en
}

// ── Permissions ───────────────────────────────────────────────────
const { can } = usePermissions()
const canManage    = computed(() => can('edit farms'))

// ── Filters ───────────────────────────────────────────────────────
const search        = ref('')
const filterCompany = ref('')
const filterStatus  = ref('all')

const filtered = computed(() => {
    let list = props.farms
    if (search.value.trim()) {
        const q = search.value.toLowerCase()
        list = list.filter(f =>
            (f.name_en || '').toLowerCase().includes(q) ||
            (f.name_ar || '').toLowerCase().includes(q) ||
            (f.city    || '').toLowerCase().includes(q) ||
            (f.region  || '').toLowerCase().includes(q)
        )
    }
    if (filterCompany.value) list = list.filter(f => f.company_id === Number(filterCompany.value))
    if (filterStatus.value !== 'all') {
        const active = filterStatus.value === 'active'
        list = list.filter(f => f.is_active === active)
    }
    return list
})

// ── Display helpers ───────────────────────────────────────────────
const farmName    = (f) => lang.value === 'ar' ? (f.name_ar || f.name_en || '—') : (f.name_en || f.name_ar || '—')
const companyName = (f) => {
    if (!f.company) return '—'
    return lang.value === 'ar' ? (f.company.name_ar || f.company.name_en) : (f.company.name_en || f.company.name_ar)
}
const companyLabel  = (c) => lang.value === 'ar' ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar)
const locationLabel = (f) => [f.city, f.region, f.country].filter(Boolean).join(', ') || '—'
const initials      = (f) => {
    const n = lang.value === 'ar' ? (f.name_ar || f.name_en) : (f.name_en || f.name_ar)
    return (n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

// ── Actions ───────────────────────────────────────────────────────
// FIX C: was 'farms.toggleActive' → web.php registers 'farms.toggle-active'
const toggleActive = (farm) => router.patch(route('farms.toggle-active', farm.id), {}, { preserveScroll: true })

const confirmDelete = ref(null)
const deleting      = ref(false)
const openDelete    = (farm) => { confirmDelete.value = farm }
const closeDelete   = ()     => { confirmDelete.value = null }
const doDelete = () => {
    if (!confirmDelete.value) return
    deleting.value = true
    router.delete(route('farms.destroy', confirmDelete.value.id), {
        preserveScroll: true,
        onFinish: () => { deleting.value = false; confirmDelete.value = null },
    })
}
</script>

<template>
    <!-- FIX B: No :title prop — AuthenticatedLayout has a multi-root template -->
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- PAGE HERO -->
            <div class="cv-page-hero">
                <div class="hero-inner">
                    <div>
                        <p class="cv-page-hero-eyebrow">{{ t('farms.module') }}</p>
                        <h1 class="cv-page-hero-title">{{ t('farms.title') }}</h1>
                        <p class="cv-page-hero-subtitle">{{ t('farms.subtitle') }}</p>
                    </div>
                    <Link v-if="canManage" :href="route('farms.create')" class="btn-amber">
                        <svg style="width:16px;height:16px" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg>
                        {{ t('farms.create') }}
                    </Link>
                </div>

                <!-- Chips -->
                <div class="hero-chips">
                    <div class="chip"><span class="chip-v green">{{ farms.length }}</span><span class="chip-l">{{ t('farms.total') }}</span></div>
                    <div class="chip"><span class="chip-v green">{{ farms.filter(f => f.is_active).length }}</span><span class="chip-l">{{ t('farms.active') }}</span></div>
                    <div class="chip"><span class="chip-v muted">{{ farms.filter(f => !f.is_active).length }}</span><span class="chip-l">{{ t('farms.inactive') }}</span></div>
                    <div class="chip"><span class="chip-v blue">{{ farms.reduce((s, f) => s + (f.capacity_head || 0), 0).toLocaleString() }}</span><span class="chip-l">{{ t('farms.total_capacity') }}</span></div>
                </div>
            </div>

            <!-- FILTERS -->
            <div class="filters-bar">
                <div class="search-wrap">
                    <svg class="search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                    </svg>
                    <input v-model="search" type="text" class="cv-input search-input"
                           :placeholder="t('farms.search_placeholder')"/>
                </div>

                <select v-if="isSuperAdmin" v-model="filterCompany" class="cv-input company-sel">
                    <option value="">{{ t('farms.all_companies') }}</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">{{ companyLabel(c) }}</option>
                </select>

                <div class="status-pills">
                    <button v-for="opt in ['all','active','inactive']" :key="opt"
                            @click="filterStatus = opt"
                            :class="['pill', filterStatus === opt ? 'pill-on' : 'pill-off']">
                        {{ t(`farms.filter_${opt}`) }}
                    </button>
                </div>

                <span class="results-ct">{{ filtered.length }} {{ t('farms.results') }}</span>
            </div>

            <!-- CARDS -->
            <div class="cards-area">

                <!-- Empty -->
                <div v-if="filtered.length === 0" class="empty-wrap">
                    <div class="empty-ico">
                        <svg style="width:32px;height:32px" fill="none" stroke="currentColor"
                             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                             viewBox="0 0 24 24">
                            <path d="M3 12l9-9 9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9"/>
                        </svg>
                    </div>
                    <p class="empty-title">{{ search || filterCompany || filterStatus !== 'all' ? t('farms.no_results') : t('farms.no_farms') }}</p>
                    <p class="empty-sub">{{ search || filterCompany || filterStatus !== 'all' ? t('farms.try_clearing') : t('farms.create_first') }}</p>
                    <Link v-if="canManage && !search && !filterCompany && filterStatus === 'all'"
                          :href="route('farms.create')" class="btn-amber mt-4">
                        {{ t('farms.create') }}
                    </Link>
                </div>

                <!-- Grid -->
                <div v-else class="cards-grid">
                    <div v-for="farm in filtered" :key="farm.id"
                         class="farm-card" :class="{ 'card-dim': !farm.is_active }">

                        <!-- Header -->
                        <div class="card-hd">
                            <div class="card-id">
                                <div class="farm-av">{{ initials(farm) }}</div>
                                <div class="card-nm">
                                    <p class="fn">{{ farmName(farm) }}</p>
                                    <p v-if="isSuperAdmin" class="fc">{{ companyName(farm) }}</p>
                                </div>
                            </div>
                            <button v-if="canManage" @click="toggleActive(farm)"
                                    :title="farm.is_active ? t('farms.deactivate') : t('farms.activate')"
                                    class="badge-btn">
                                <span :class="farm.is_active ? 'bdg-on' : 'bdg-off'">
                                    {{ farm.is_active ? t('common.active') : t('common.inactive') }}
                                </span>
                            </button>
                            <span v-else :class="farm.is_active ? 'bdg-on' : 'bdg-off'">
                                {{ farm.is_active ? t('common.active') : t('common.inactive') }}
                            </span>
                        </div>

                        <div class="cdiv"/>

                        <!-- Stats -->
                        <div class="card-stats">
                            <div class="sc"><span class="sv blue">{{ farm.capacity_head != null ? farm.capacity_head.toLocaleString() : '—' }}</span><span class="sl">{{ t('farms.capacity') }}</span></div>
                            <div class="sc sc-mid"><span class="sv green">{{ farm.area_acres != null ? farm.area_acres : '—' }}</span><span class="sl">{{ t('farms.acres') }}</span></div>
                            <div class="sc sc-mid"><span class="sv amber">{{ farm.users_count }}</span><span class="sl">{{ t('farms.users') }}</span></div>
                        </div>

                        <div class="cdiv"/>

                        <!-- Location -->
                        <div class="card-loc">
                            <svg style="width:13px;height:13px;flex-shrink:0;opacity:.5" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span class="loc-txt">{{ locationLabel(farm) }}</span>
                        </div>

                        <!-- Footer actions -->
                        <div class="card-ft">
                            <Link :href="route('farms.show', farm.id)" class="ca">
                                <svg style="width:13px;height:13px" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                {{ t('common.view') }}
                            </Link>
                            <Link v-if="canManage" :href="route('farms.edit', farm.id)" class="ca ca-edit">
                                <svg style="width:13px;height:13px" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                {{ t('common.edit') }}
                            </Link>
                            <button v-if="canManage" @click="openDelete(farm)" class="ca ca-del">
                                <svg style="width:13px;height:13px" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                {{ t('common.delete') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DELETE MODAL -->
            <Teleport to="body">
                <div v-if="confirmDelete" class="modal-back" @click.self="closeDelete">
                    <div class="modal-box" :dir="isRtl ? 'rtl' : 'ltr'">
                        <div class="modal-del-ico">
                            <svg style="width:22px;height:22px" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            </svg>
                        </div>
                        <h3 class="modal-title">{{ t('farms.delete_title') }}</h3>
                        <p class="modal-body">
                            {{ t('farms.delete_confirm') }}
                            <strong style="color:var(--cv-text-primary)">{{ farmName(confirmDelete) }}</strong>?
                            {{ t('farms.delete_warning') }}
                        </p>
                        <p class="modal-guard">{{ t('farms.delete_guard') }}</p>
                        <div class="modal-acts">
                            <button @click="closeDelete" class="btn-ghost">{{ t('common.cancel') }}</button>
                            <button @click="doDelete" :disabled="deleting" class="btn-danger">
                                {{ deleting ? t('common.deleting') : t('common.delete') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Hero ────────────────────────────────────── */
.hero-inner { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:.75rem; }
.hero-chips { display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1rem; position:relative; z-index:10; }
.chip { display:flex; align-items:center; gap:.5rem; background:rgba(0,0,0,.25); border:1px solid rgba(255,255,255,.07); border-radius:var(--cv-radius); padding:.375rem .75rem; }
.chip-v { font-size:1rem; font-weight:700; font-variant-numeric:tabular-nums; }
.chip-l { font-size:.6875rem; color:rgba(255,255,255,.45); text-transform:uppercase; letter-spacing:.08em; }
.green { color:var(--cv-green-bright); }
.blue  { color:var(--cv-blue-text); }
.muted { color:var(--cv-text-muted); }
.amber { color:var(--cv-amber); }

/* ── Amber CTA button ────────────────────────── */
.btn-amber {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem 1.1rem; border-radius:var(--cv-radius); border:none; cursor:pointer;
    background:var(--cv-amber); color:#1a1000; font-size:.8125rem; font-weight:600;
    text-decoration:none; box-shadow:var(--cv-shadow-amber); transition:background .15s;
}
.btn-amber:hover { background:var(--cv-amber-hover); }
.mt-4 { margin-top:1rem; }

/* ── Filters ─────────────────────────────────── */
.filters-bar { display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; padding:1rem 1.5rem; border-bottom:1px solid var(--cv-border); }
.search-wrap { position:relative; flex:1; min-width:200px; max-width:320px; }
.search-ico { position:absolute; inset-inline-start:.75rem; top:50%; transform:translateY(-50%); width:14px; height:14px; color:var(--cv-text-muted); pointer-events:none; }
.search-input { padding-inline-start:2.25rem; }
.company-sel { min-width:180px; max-width:240px; }
.status-pills { display:flex; align-items:center; gap:2px; border:1px solid var(--cv-border); border-radius:var(--cv-radius); padding:3px; }
.pill { padding:.25rem .75rem; font-size:.75rem; font-weight:600; border-radius:var(--cv-radius-sm); border:none; cursor:pointer; background:transparent; transition:var(--cv-transition); }
.pill-on  { background:var(--cv-green-bright); color:var(--cv-bg-sidebar); }
.pill-off { color:var(--cv-text-muted); }
.pill-off:hover { color:var(--cv-text-primary); }
.results-ct { margin-inline-start:auto; font-size:.75rem; color:var(--cv-text-muted); }

/* ── Cards ───────────────────────────────────── */
.cards-area { padding:1.5rem; }
.cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1.25rem; }

.farm-card { background:var(--cv-bg-card); border:1px solid var(--cv-border); border-radius:var(--cv-radius-md); box-shadow:var(--cv-shadow-sm); display:flex; flex-direction:column; overflow:hidden; transition:border-color .2s,box-shadow .2s; }
.farm-card:hover { border-color:var(--cv-green-border); box-shadow:var(--cv-shadow); }
.card-dim { opacity:.6; }

/* Card header */
.card-hd { display:flex; align-items:flex-start; justify-content:space-between; padding:1rem 1rem .75rem; }
.card-id { display:flex; align-items:center; gap:.75rem; min-width:0; }
.farm-av { width:40px; height:40px; flex-shrink:0; display:flex; align-items:center; justify-content:center; border-radius:var(--cv-radius); background:var(--cv-green-dim); border:1px solid var(--cv-green-border); color:var(--cv-green-bright); font-size:.75rem; font-weight:700; letter-spacing:.04em; }
.card-nm { min-width:0; }
.fn { font-size:.875rem; font-weight:600; color:var(--cv-text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.3; }
.fc { font-size:.75rem; color:var(--cv-text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }

/* Badges */
.badge-btn { background:none; border:none; cursor:pointer; padding:0; flex-shrink:0; margin-top:2px; }
.bdg-on,.bdg-off { display:inline-flex; align-items:center; padding:.1875rem .5rem; border-radius:9999px; font-size:.625rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }
.bdg-on  { background:var(--cv-success-bg); color:var(--cv-success-text); border:1px solid var(--cv-success-border); }
.bdg-off { background:rgba(255,255,255,.04); color:var(--cv-text-muted); border:1px solid var(--cv-border); }

/* Divider */
.cdiv { height:1px; background:var(--cv-border); margin:0 1rem; }

/* Stats */
.card-stats { display:grid; grid-template-columns:1fr 1fr 1fr; padding:.5rem 0; }
.sc { display:flex; flex-direction:column; align-items:center; gap:2px; padding:.375rem .5rem; }
.sc-mid { border-inline-start:1px solid var(--cv-border); }
.sv { font-size:.875rem; font-weight:700; font-variant-numeric:tabular-nums; }
.sl { font-size:.625rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.06em; }

/* Location */
.card-loc { display:flex; align-items:center; gap:.5rem; padding:.625rem 1rem; font-size:.75rem; color:var(--cv-text-muted); }
.loc-txt { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Footer */
.card-ft { display:flex; border-top:1px solid var(--cv-border); margin-top:auto; }
.ca { flex:1; display:flex; align-items:center; justify-content:center; gap:.375rem; padding:.625rem .5rem; font-size:.75rem; color:var(--cv-text-muted); background:none; border:none; cursor:pointer; text-decoration:none; transition:var(--cv-transition); border-inline-start:1px solid var(--cv-border); }
.ca:first-child { border-inline-start:none; }
.ca:hover { color:var(--cv-text-primary); background:var(--cv-bg-hover); }
.ca-edit:hover { color:var(--cv-green-bright); background:var(--cv-green-dim); }
.ca-del:hover  { color:var(--cv-danger-text);  background:var(--cv-danger-bg); }

/* ── Empty ───────────────────────────────────── */
.empty-wrap { display:flex; flex-direction:column; align-items:center; padding:4rem 1rem; text-align:center; }
.empty-ico { width:64px; height:64px; display:flex; align-items:center; justify-content:center; border-radius:var(--cv-radius-lg); background:var(--cv-green-dim); border:1px solid var(--cv-green-border); color:var(--cv-green-bright); margin-bottom:1rem; }
.empty-title { font-size:.9375rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:.25rem; }
.empty-sub   { font-size:.8125rem; color:var(--cv-text-muted); }

/* ── Modal ───────────────────────────────────── */
.modal-back { position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(0,0,0,.6); backdrop-filter:blur(4px); }
.modal-box  { background:var(--cv-bg-modal); border:1px solid var(--cv-border-md); border-radius:var(--cv-radius-lg); box-shadow:var(--cv-shadow-md); padding:1.75rem; width:100%; max-width:400px; }
.modal-del-ico { width:52px; height:52px; border-radius:50%; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); color:var(--cv-danger-text); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; }
.modal-title { text-align:center; font-size:1rem; font-weight:700; color:var(--cv-text-primary); margin-bottom:.5rem; }
.modal-body  { text-align:center; font-size:.875rem; color:var(--cv-text-muted); margin-bottom:.75rem; }
.modal-guard { text-align:center; font-size:.75rem; color:var(--cv-warning-text); background:var(--cv-warning-bg); border:1px solid var(--cv-warning-border); border-radius:var(--cv-radius-sm); padding:.5rem .75rem; margin-bottom:1.25rem; }
.modal-acts  { display:flex; gap:.75rem; }
.btn-ghost   { flex:1; padding:.5625rem 1rem; background:transparent; border:1px solid var(--cv-border); color:var(--cv-text-secondary); font-size:.8125rem; font-weight:500; border-radius:var(--cv-radius); cursor:pointer; transition:var(--cv-transition); }
.btn-ghost:hover { background:var(--cv-bg-hover); color:var(--cv-text-primary); }
.btn-danger  { flex:1; padding:.5625rem 1rem; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); color:var(--cv-danger-text); font-size:.8125rem; font-weight:600; border-radius:var(--cv-radius); cursor:pointer; transition:var(--cv-transition); }
.btn-danger:hover    { background:rgba(224,112,112,.22); }
.btn-danger:disabled { opacity:.5; cursor:not-allowed; }
</style>