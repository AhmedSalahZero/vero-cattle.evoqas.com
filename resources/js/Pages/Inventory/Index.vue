<script setup>
/**
 * Inventory/Index.vue
 * Master items card grid. Each card expands to show location breakdown.
 * Filters: category, farm, location, low_stock, search.
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    items:     Array,
    farms:     Array,
    locations: Array,
    companies: { type: Array, default: () => [] },
    filters:   Object,
})

// ── Permissions ───────────────────────────────────────────────────
const { isSuperAdmin, can: canPermission } = usePermissions()
const can = computed(() => ({
    create: canPermission('create inventory'),
    edit:   canPermission('edit inventory'),
    delete: canPermission('delete inventory'),
}))

const filters = ref({
    company_id:  props.filters?.company_id  ?? '',
    category:    props.filters?.category    ?? '',
    farm_id:     props.filters?.farm_id     ?? '',
    location_id: props.filters?.location_id ?? '',
    low_stock:   props.filters?.low_stock   ?? false,
    search:      props.filters?.search      ?? '',
})

const filteredLocations = computed(() =>
    filters.value.farm_id
        ? props.locations.filter(l => String(l.farm_id) === String(filters.value.farm_id))
        : props.locations
)

function applyFilters() {
    router.get(route('inventory.index'), filters.value, { preserveState:true, replace:true })
}
function resetFilters() {
    filters.value = { company_id:'', category:'', farm_id:'', location_id:'', low_stock:false, search:'' }
    applyFilters()
}

// ── Expanded items ────────────────────────────────────────────────
const expandedIds = ref(new Set())
function toggleExpand(id) {
    if (expandedIds.value.has(id)) expandedIds.value.delete(id)
    else expandedIds.value.add(id)
}

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal  = ref(false)
const deleteTarget = ref(null)
function confirmDelete(item) { deleteTarget.value = item; deleteModal.value = true }
function doDelete() {
    router.delete(route('inventory.destroy', deleteTarget.value.id), {
        onSuccess: () => { deleteModal.value = false },
    })
}
function toggleActive(item) {
    router.patch(route('inventory.toggle-active', item.id))
}

// ── Helpers ───────────────────────────────────────────────────────
const categoryConfig = computed(() => ({
    medicine:  { icon:'💊', label: lang.value==='ar'?'دواء':'Medicine',   stripe:'var(--cv-danger-text)' },
    feed:      { icon:'🌾', label: lang.value==='ar'?'علف':'Feed',        stripe:'var(--cv-green-bright)' },
    vaccine:   { icon:'💉', label: lang.value==='ar'?'لقاح':'Vaccine',    stripe:'var(--cv-blue-text)' },
    equipment: { icon:'🔧', label: lang.value==='ar'?'معدات':'Equipment', stripe:'var(--cv-amber)' },
    supply:    { icon:'📦', label: lang.value==='ar'?'مستلزمات':'Supply', stripe:'#8b5cf6' },
    other:     { icon:'📋', label: lang.value==='ar'?'أخرى':'Other',      stripe:'var(--cv-text-muted)' },
}))

function itemName(item) {
    return lang.value === 'ar'
        ? (item.name_ar || item.name_en || '—')
        : (item.name_en || item.name_ar || '—')
}

function locName(loc) {
    const name = lang.value === 'ar' ? (loc.location_name_ar || loc.location_name_en) : (loc.location_name_en || loc.location_name_ar)
    const farm = lang.value === 'ar' ? (loc.farm_name_ar || loc.farm_name_en) : (loc.farm_name_en || loc.farm_name_ar)
    return loc.is_central
        ? `🏢 ${name || (lang.value==='ar'?'مركزي':'Central')}`
        : `🏠 ${farm ? farm + ' — ' : ''}${name || '—'}`
}

function fmt(n) {
    if (n == null) return '—'
    return Number(n).toLocaleString(lang.value==='ar'?'ar-EG':'en-US', {minimumFractionDigits:2,maximumFractionDigits:2})
}

function fmtQty(n) {
    if (n == null) return '0'
    return Number(n).toLocaleString(lang.value==='ar'?'ar-EG':'en-US', {minimumFractionDigits:0,maximumFractionDigits:3})
}

const t = computed(() => lang.value === 'ar' ? {
    eyebrow:'المخزون', title:'إدارة المخزون', sub:'جميع الأصناف مع تفصيل المواقع',
    addItem:'إضافة صنف', manageLocations:'إدارة المواقع',
    search:'بحث...', allCats:'كل الأنواع', allFarms:'كل المزارع', allLocs:'كل المواقع',
    lowStockOnly:'منخفض المخزون فقط', apply:'تطبيق', reset:'إعادة تعيين',
    total:'الإجمالي', locations:'المواقع', unit:'وحدة', cost:'التكلفة',
    viewTx:'الحركات', edit:'تعديل', deactivate:'إيقاف', activate:'تفعيل', delete:'حذف',
    lowStock:'⚠️ منخفض', outOfStock:'🔴 نفد', active:'نشط', inactive:'غير نشط',
    noItems:'لا توجد أصناف مخزون', noItemsSub:'أضف صنفاً جديداً للبدء',
    deleteTitle:'حذف الصنف', deleteMsg:'هل أنت متأكد؟ لا يمكن الحذف إذا كان هناك مخزون متبقٍّ.',
    cancel:'إلغاء', confirm:'حذف',
    categories: { medicine:'دواء', feed:'علف', vaccine:'لقاح', equipment:'معدات', supply:'مستلزمات', other:'أخرى' },
} : {
    eyebrow:'Inventory', title:'Inventory Management', sub:'All items with per-location stock breakdown',
    addItem:'Add Item', manageLocations:'Manage Locations',
    search:'Search...', allCats:'All Categories', allFarms:'All Farms', allLocs:'All Locations',
    lowStockOnly:'Low stock only', apply:'Apply', reset:'Reset',
    total:'Total', locations:'Locations', unit:'Unit', cost:'Cost',
    viewTx:'Transactions', edit:'Edit', deactivate:'Deactivate', activate:'Activate', delete:'Delete',
    lowStock:'⚠️ Low', outOfStock:'🔴 Out', active:'Active', inactive:'Inactive',
    noItems:'No inventory items', noItemsSub:'Add a new item to get started',
    deleteTitle:'Delete Item', deleteMsg:'Are you sure? Cannot delete if any location has remaining stock.',
    cancel:'Cancel', confirm:'Delete',
    categories: { medicine:'Medicine', feed:'Feed', vaccine:'Vaccine', equipment:'Equipment', supply:'Supply', other:'Other' },
})
</script>

<template>
    
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ t.title }}</div>
                <div class="cv-page-hero-sub">{{ t.sub }}</div>
                <div class="cv-page-hero-actions">
                    <Link :href="route('inventory.locations.index')" class="cv-btn cv-btn-ghost">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="15" height="15"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        {{ t.manageLocations }}
                    </Link>
                    <Link v-if="can.create" :href="route('inventory.create')" class="cv-btn cv-btn-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="15" height="15"><path d="M12 5v14M5 12h14"/></svg>
                        {{ t.addItem }}
                    </Link>
                </div>
            </div>

            <div class="cv-page">

                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">{{ $page.props.flash.success }}</div>
                <div v-if="$page.props.flash?.error"   class="cv-alert cv-alert-danger  mb-4">{{ $page.props.flash.error }}</div>

                <!-- Filters -->
                <div class="cv-card mb-5 p-4">
                    <div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
                        <!-- Company filter — super admin only -->
                        <select v-if="isSuperAdmin" v-model="filters.company_id" class="cv-select">
                            <option value="">{{ lang==='ar' ? 'كل الشركات' : 'All Companies' }}</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">
                                {{ lang==='ar' ? (c.name_ar||c.name_en) : (c.name_en||c.name_ar) }}
                            </option>
                        </select>
                        <input v-model="filters.search" type="text" class="cv-input" :placeholder="t.search"/>
                        <select v-model="filters.category" class="cv-select">
                            <option value="">{{ t.allCats }}</option>
                            <option v-for="(label,val) in t.categories" :key="val" :value="val">{{ label }}</option>
                        </select>
                        <select v-model="filters.farm_id" class="cv-select">
                            <option value="">{{ t.allFarms }}</option>
                            <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f,'name') }}</option>
                        </select>
                        <select v-model="filters.location_id" class="cv-select">
                            <option value="">{{ t.allLocs }}</option>
                            <option v-for="l in filteredLocations" :key="l.id" :value="l.id">
                                {{ lang==='ar'?(l.name_ar||l.name_en):(l.name_en||l.name_ar) }}
                            </option>
                        </select>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <div class="cv-toggle" :class="{'cv-toggle-amber': filters.low_stock}" @click="filters.low_stock = !filters.low_stock">
                                <span class="cv-toggle-knob"/>
                            </div>
                            <span class="text-xs" style="color:var(--cv-text-secondary)">{{ t.lowStockOnly }}</span>
                        </label>
                    </div>
                    <div class="flex gap-2 mt-3">
                        <button class="cv-btn cv-btn-amber" @click="applyFilters">{{ t.apply }}</button>
                        <button class="cv-btn cv-btn-ghost" @click="resetFilters">{{ t.reset }}</button>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="items.length === 0" class="cv-empty-state">
                    <div style="font-size:3rem;opacity:.25;margin-bottom:1rem">📦</div>
                    <div class="cv-empty-title">{{ t.noItems }}</div>
                    <div class="cv-empty-sub">{{ t.noItemsSub }}</div>
                    <Link v-if="can.create" :href="route('inventory.create')" class="cv-btn cv-btn-amber mt-4">{{ t.addItem }}</Link>
                </div>

                <!-- Items grid -->
                <div v-else class="inv-grid">
                    <div v-for="item in items" :key="item.id" class="inv-card"
                         :style="`border-inline-start:4px solid ${categoryConfig[item.category]?.stripe ?? 'var(--cv-text-muted)'}`">

                        <!-- Card header -->
                        <div class="inv-card-header" @click="toggleExpand(item.id)">
                            <div class="inv-category-icon">{{ categoryConfig[item.category]?.icon }}</div>
                            <div class="flex-1 min-w-0">
                                <div class="inv-name">{{ itemName(item) }}</div>
                                <div class="inv-meta">
                                    <span class="inv-cat-badge">{{ categoryConfig[item.category]?.label }}</span>
                                    <span v-if="item.sku" class="inv-sku">{{ item.sku }}</span>
                                </div>
                            </div>
                            <!-- Total stock -->
                            <div class="text-end flex-shrink-0">
                                <div class="inv-total-qty" style="color:var(--cv-blue-text)">
                                    {{ fmtQty(item.total_qty) }}
                                    <span class="inv-unit">{{ item.unit }}</span>
                                </div>
                                <div class="flex gap-1 justify-end mt-1">
                                    <span v-if="item.has_out_of_stock" class="inv-alert-badge badge-danger">{{ t.outOfStock }}</span>
                                    <span v-else-if="item.has_low_stock" class="inv-alert-badge badge-amber">{{ t.lowStock }}</span>
                                </div>
                            </div>
                            <!-- Expand chevron -->
                            <svg class="inv-chevron" :class="{'inv-chevron-open': expandedIds.has(item.id)}"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polyline points="6,9 12,15 18,9"/>
                            </svg>
                        </div>

                        <!-- Location breakdown (expanded) -->
                        <Transition name="expand">
                            <div v-if="expandedIds.has(item.id)" class="inv-locations">
                                <div v-for="loc in item.locations" :key="loc.location_id" class="inv-loc-row">
                                    <div class="inv-loc-name">{{ locName(loc) }}</div>
                                    <div class="inv-loc-stock">
                                        <span class="inv-loc-qty" :class="{
                                            'text-danger': loc.is_out_of_stock,
                                            'text-amber':  loc.is_low_stock && !loc.is_out_of_stock,
                                            'text-blue':   !loc.is_low_stock && !loc.is_out_of_stock,
                                        }">{{ fmtQty(loc.qty_on_hand) }} {{ item.unit }}</span>
                                        <span class="inv-loc-cost">{{ fmt(loc.unit_cost) }} EGP</span>
                                    </div>
                                </div>
                            </div>
                        </Transition>

                        <!-- Actions -->
                        <div class="inv-card-actions">
                            <Link :href="route('inventory.transactions', item.id)" class="cv-btn cv-btn-ghost cv-btn-xs">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="12" height="12"><path d="M3 3h18v4H3zM3 10h18v4H3zM3 17h18v4H3z"/></svg>
                                {{ t.viewTx }}
                            </Link>
                            <Link v-if="can.edit" :href="route('inventory.edit', item.id)" class="cv-btn cv-btn-ghost cv-btn-xs">{{ t.edit }}</Link>
                            <button v-if="can.edit" class="cv-btn cv-btn-ghost cv-btn-xs" @click="toggleActive(item)">
                                {{ item.is_active ? t.deactivate : t.activate }}
                            </button>
                            <button v-if="can.delete" class="cv-btn cv-btn-danger-ghost cv-btn-xs" @click="confirmDelete(item)">{{ t.delete }}</button>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Delete Modal -->
            <Transition name="fade">
                <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal=false">
                    <div class="cv-modal">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </div>
                            <h3 class="cv-modal-title">{{ t.deleteTitle }}</h3>
                        </div>
                        <div class="cv-modal-body"><p>{{ t.deleteMsg }}</p><p class="mt-2 font-semibold" style="color:var(--cv-text-primary)">{{ itemName(deleteTarget) }}</p></div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deleteModal=false">{{ t.cancel }}</button>
                            <button class="cv-btn cv-btn-danger" @click="doDelete">{{ t.confirm }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    
</template>

<style scoped>
.inv-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:0.9rem; }
.inv-card { background:var(--cv-bg-card); border:1px solid var(--cv-border-muted); border-radius:10px; overflow:hidden; }
.inv-card-header { display:flex; align-items:center; gap:0.75rem; padding:0.9rem; cursor:pointer; user-select:none; }
.inv-card-header:hover { background:rgba(255,255,255,0.03); }
.inv-category-icon { font-size:1.5rem; flex-shrink:0; }
.inv-name { font-size:0.95rem; font-weight:600; color:var(--cv-text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.inv-meta { display:flex; align-items:center; gap:0.5rem; margin-top:0.2rem; }
.inv-cat-badge { font-size:0.7rem; padding:0.1rem 0.4rem; border-radius:4px; background:rgba(255,255,255,0.07); color:var(--cv-text-muted); }
.inv-sku { font-size:0.7rem; color:var(--cv-text-muted); }
.inv-total-qty { font-size:1.1rem; font-weight:700; }
.inv-unit { font-size:0.72rem; font-weight:400; color:var(--cv-text-muted); margin-inline-start:0.2rem; }
.inv-alert-badge { font-size:0.68rem; padding:0.15rem 0.45rem; border-radius:999px; }
.badge-danger { background:rgba(239,68,68,0.15); color:var(--cv-danger-text); }
.badge-amber  { background:rgba(245,158,11,0.15); color:var(--cv-amber); }
.inv-chevron { color:var(--cv-text-muted); flex-shrink:0; transition:transform 0.2s; }
.inv-chevron-open { transform:rotate(180deg); }
.inv-locations { border-top:1px solid var(--cv-border-muted); padding:0.5rem 0.9rem; background:rgba(0,0,0,0.15); }
.inv-loc-row { display:flex; align-items:center; justify-content:space-between; padding:0.35rem 0; border-bottom:1px solid rgba(255,255,255,0.04); }
.inv-loc-row:last-child { border-bottom:none; }
.inv-loc-name { font-size:0.78rem; color:var(--cv-text-secondary); flex:1; }
.inv-loc-stock { display:flex; align-items:center; gap:0.75rem; }
.inv-loc-qty { font-size:0.82rem; font-weight:600; }
.inv-loc-cost { font-size:0.72rem; color:var(--cv-text-muted); }
.text-danger { color:var(--cv-danger-text) !important; }
.text-amber  { color:var(--cv-amber) !important; }
.text-blue   { color:var(--cv-blue-text) !important; }
.inv-card-actions { display:flex; gap:0.4rem; padding:0.6rem 0.9rem; border-top:1px solid var(--cv-border-muted); flex-wrap:wrap; }
.cv-btn-xs { padding:0.2rem 0.55rem; font-size:0.72rem; }
.cv-btn-danger-ghost { background:transparent; color:var(--cv-danger-text); border:1px solid transparent; }
.cv-btn-danger-ghost:hover { background:rgba(239,68,68,0.1); border-color:var(--cv-danger-text); }
.cv-toggle { width:36px; height:20px; border-radius:999px; border:1px solid var(--cv-border); background:var(--cv-bg-input); position:relative; cursor:pointer; transition:background 0.2s; flex-shrink:0; }
.cv-toggle-amber { background:var(--cv-amber); border-color:var(--cv-amber); }
.cv-toggle-knob { position:absolute; top:2px; inset-inline-start:2px; width:14px; height:14px; background:#fff; border-radius:50%; transition:inset-inline-start 0.2s; }
.cv-toggle-amber .cv-toggle-knob { inset-inline-start:calc(100% - 16px); }
.cv-empty-state { text-align:center; padding:4rem 2rem; }
.cv-empty-title { font-size:1.1rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:0.4rem; }
.cv-empty-sub { color:var(--cv-text-muted); font-size:0.9rem; }
.expand-enter-active, .expand-leave-active { transition:all 0.2s ease; overflow:hidden; }
.expand-enter-from, .expand-leave-to { max-height:0; opacity:0; }
.expand-enter-to, .expand-leave-from { max-height:500px; opacity:1; }
.fade-enter-active, .fade-leave-active { transition:opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity:0; }
</style>