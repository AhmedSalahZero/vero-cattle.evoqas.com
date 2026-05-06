<script setup>
/**
 * Inventory/Locations/Index.vue
 * ─────────────────────────────────────────────────────────────────
 * Manage physical storage locations — warehouses, store rooms, etc.
 * Grouped by: Central (farm_id = null) then by Farm.
 * Access: Company Admin + Accountant only (canManage prop).
 * ─────────────────────────────────────────────────────────────────
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    locations: Array,
    farms:     Array,
    filters:   Object,
    canManage: Boolean,
})

// ── Grouped locations ─────────────────────────────────────────────
const grouped = computed(() => {
    const central = props.locations.filter(l => l.is_central)
    const byFarm  = {}

    props.locations.filter(l => !l.is_central).forEach(l => {
        const key = l.farm_id
        if (!byFarm[key]) {
            byFarm[key] = { farm: l.farm, items: [] }
        }
        byFarm[key].items.push(l)
    })

    return { central, byFarm: Object.values(byFarm) }
})

// ── Type config (plain object, not computed — used directly in template) ──
function typeLabel(type) {
    const map = {
        warehouse:    { icon: '🏭', label: lang.value === 'ar' ? 'مستودع'      : 'Warehouse',    color: 'var(--cv-blue-text)' },
        store_room:   { icon: '🏠', label: lang.value === 'ar' ? 'غرفة تخزين'  : 'Store Room',   color: 'var(--cv-green-bright)' },
        cold_storage: { icon: '❄️', label: lang.value === 'ar' ? 'تخزين بارد'  : 'Cold Storage', color: 'var(--cv-blue-text)' },
        cabinet:      { icon: '🗄️', label: lang.value === 'ar' ? 'خزانة'       : 'Cabinet',      color: 'var(--cv-amber)' },
        shed:         { icon: '🏗️', label: lang.value === 'ar' ? 'سقيفة'       : 'Shed',         color: 'var(--cv-amber)' },
        other:        { icon: '📦', label: lang.value === 'ar' ? 'أخرى'        : 'Other',        color: 'var(--cv-text-muted)' },
    }
    return map[type] ?? map.other
}

// ── Delete modal ──────────────────────────────────────────────────
const deleteModal  = ref(false)
const deleteTarget = ref(null)

function confirmDelete(loc) {
    deleteTarget.value = loc
    deleteModal.value  = true
}
function doDelete() {
    router.delete(route('inventory.locations.destroy', deleteTarget.value.id), {
        onSuccess: () => { deleteModal.value = false },
    })
}

function toggleActive(loc) {
    router.patch(route('inventory.locations.toggle-active', loc.id))
}

// ── i18n ──────────────────────────────────────────────────────────
const t = computed(() => lang.value === 'ar' ? {
    eyebrow:         'المخزون',
    title:           'مواقع التخزين',
    sub:             'إدارة المستودعات وغرف التخزين في المزارع والمركز',
    addLocation:     'إضافة موقع',
    centralTitle:    'المستودعات المركزية',
    centralSub:      'مواقع تخزين على مستوى الشركة (غير مرتبطة بمزرعة)',
    items:           'صنف مخزون',
    edit:            'تعديل',
    delete:          'حذف',
    activate:        'تفعيل',
    deactivate:      'إيقاف',
    deleteTitle:     'حذف موقع التخزين',
    deleteMsg:       'هل أنت متأكد؟ لا يمكن الحذف إذا كان الموقع يحتوي على مخزون.',
    cancel:          'إلغاء',
    confirm:         'حذف',
    noLocations:     'لا توجد مواقع تخزين',
    noLocationsSub:  'أضف مستودعاً أو غرفة تخزين للبدء',
    active:          'نشط',
    inactive:        'غير نشط',
    backToInventory: 'العودة للمخزون',
} : {
    eyebrow:         'Inventory',
    title:           'Storage Locations',
    sub:             'Manage warehouses and store rooms across farms and central',
    addLocation:     'Add Location',
    centralTitle:    'Central Locations',
    centralSub:      'Company-level storage (not tied to a specific farm)',
    items:           'stock items',
    edit:            'Edit',
    delete:          'Delete',
    activate:        'Activate',
    deactivate:      'Deactivate',
    deleteTitle:     'Delete Storage Location',
    deleteMsg:       'Are you sure? Cannot delete if location has stock.',
    cancel:          'Cancel',
    confirm:         'Delete',
    noLocations:     'No storage locations',
    noLocationsSub:  'Add a warehouse or store room to get started',
    active:          'Active',
    inactive:        'Inactive',
    backToInventory: 'Back to Inventory',
})
</script>

<template>
    
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- Hero -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
                <div class="cv-page-hero-title">{{ t.title }}</div>
                <div class="cv-page-hero-sub">{{ t.sub }}</div>
                <div class="cv-page-hero-actions">
                    <Link :href="route('inventory.index')" class="cv-btn cv-btn-ghost">
                        ← {{ t.backToInventory }}
                    </Link>
                    <Link v-if="canManage" :href="route('inventory.locations.create')" class="cv-btn cv-btn-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        {{ t.addLocation }}
                    </Link>
                </div>
            </div>

            <div class="cv-page">

                <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">
                    {{ $page.props.flash.success }}
                </div>

                <!-- Empty state -->
                <div v-if="locations.length === 0" class="cv-empty-state">
                    <div class="cv-empty-icon">📦</div>
                    <div class="cv-empty-title">{{ t.noLocations }}</div>
                    <div class="cv-empty-sub">{{ t.noLocationsSub }}</div>
                    <Link v-if="canManage" :href="route('inventory.locations.create')" class="cv-btn cv-btn-amber mt-4">
                        {{ t.addLocation }}
                    </Link>
                </div>

                <template v-else>

                    <!-- Central Locations -->
                    <div v-if="grouped.central.length" class="mb-8">
                        <div class="group-header">
                            <span class="group-icon">🏢</span>
                            <div>
                                <div class="group-title">{{ t.centralTitle }}</div>
                                <div class="group-sub">{{ t.centralSub }}</div>
                            </div>
                        </div>
                        <div class="loc-grid">
                            <div
                                v-for="loc in grouped.central"
                                :key="loc.id"
                                class="loc-card"
                                :class="{ 'loc-card-inactive': !loc.is_active }"
                            >
                                <div class="loc-card-stripe" :style="{ background: typeLabel(loc.type).color }"/>
                                <div class="loc-card-body">
                                    <div class="flex items-start justify-between mb-2">
                                        <span class="loc-type-badge">
                                            {{ typeLabel(loc.type).icon }} {{ typeLabel(loc.type).label }}
                                        </span>
                                        <span class="cv-badge" :class="loc.is_active ? 'badge-green' : 'badge-muted'">
                                            {{ loc.is_active ? t.active : t.inactive }}
                                        </span>
                                    </div>
                                    <div class="loc-name">
                                        {{ lang === 'ar' ? (loc.name_ar || loc.name_en) : (loc.name_en || loc.name_ar) }}
                                    </div>
                                    <div class="loc-meta">
                                        {{ loc.stock_items_count }} {{ t.items }}
                                    </div>
                                    <div v-if="canManage" class="loc-actions">
                                        <button class="cv-btn cv-btn-ghost cv-btn-xs" @click="router.visit(route('inventory.locations.edit', loc.id))">
                                            {{ t.edit }}
                                        </button>
                                        <button class="cv-btn cv-btn-ghost cv-btn-xs" @click="toggleActive(loc)">
                                            {{ loc.is_active ? t.deactivate : t.activate }}
                                        </button>
                                        <button class="cv-btn cv-btn-danger-ghost cv-btn-xs" @click="confirmDelete(loc)">
                                            {{ t.delete }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Per-Farm Groups -->
                    <div v-for="group in grouped.byFarm" :key="group.farm?.id" class="mb-8">
                        <div class="group-header">
                            <span class="group-icon">🏠</span>
                            <div>
                                <div class="group-title">{{ n(group.farm, 'name') }}</div>
                                <div class="group-sub">{{ group.items.length }} {{ lang === 'ar' ? 'موقع' : 'location(s)' }}</div>
                            </div>
                        </div>
                        <div class="loc-grid">
                            <div
                                v-for="loc in group.items"
                                :key="loc.id"
                                class="loc-card"
                                :class="{ 'loc-card-inactive': !loc.is_active }"
                            >
                                <div class="loc-card-stripe" :style="{ background: typeLabel(loc.type).color }"/>
                                <div class="loc-card-body">
                                    <div class="flex items-start justify-between mb-2">
                                        <span class="loc-type-badge">
                                            {{ typeLabel(loc.type).icon }} {{ typeLabel(loc.type).label }}
                                        </span>
                                        <span class="cv-badge" :class="loc.is_active ? 'badge-green' : 'badge-muted'">
                                            {{ loc.is_active ? t.active : t.inactive }}
                                        </span>
                                    </div>
                                    <div class="loc-name">
                                        {{ lang === 'ar' ? (loc.name_ar || loc.name_en) : (loc.name_en || loc.name_ar) }}
                                    </div>
                                    <div class="loc-meta">
                                        {{ loc.stock_items_count }} {{ t.items }}
                                    </div>
                                    <div v-if="canManage" class="loc-actions">
                                        <button class="cv-btn cv-btn-ghost cv-btn-xs" @click="router.visit(route('inventory.locations.edit', loc.id))">
                                            {{ t.edit }}
                                        </button>
                                        <button class="cv-btn cv-btn-ghost cv-btn-xs" @click="toggleActive(loc)">
                                            {{ loc.is_active ? t.deactivate : t.activate }}
                                        </button>
                                        <button class="cv-btn cv-btn-danger-ghost cv-btn-xs" @click="confirmDelete(loc)">
                                            {{ t.delete }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </template>
            </div>

            <!-- Delete Modal -->
            <Transition name="fade">
                <div v-if="deleteModal" class="cv-modal-backdrop" @click.self="deleteModal = false">
                    <div class="cv-modal">
                        <div class="cv-modal-header">
                            <div class="cv-modal-danger-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="28" height="28">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <h3 class="cv-modal-title">{{ t.deleteTitle }}</h3>
                        </div>
                        <div class="cv-modal-body">
                            <p>{{ t.deleteMsg }}</p>
                            <p class="mt-2 font-semibold" style="color:var(--cv-text-primary)">
                                {{ lang === 'ar' ? (deleteTarget?.name_ar || deleteTarget?.name_en) : (deleteTarget?.name_en || deleteTarget?.name_ar) }}
                            </p>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="deleteModal = false">{{ t.cancel }}</button>
                            <button class="cv-btn cv-btn-danger" @click="doDelete">{{ t.confirm }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    
</template>

<style scoped>
.group-header { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; padding-bottom:0.5rem; border-bottom:1px solid var(--cv-border-muted); }
.group-icon   { font-size:1.5rem; }
.group-title  { font-size:1rem; font-weight:600; color:var(--cv-text-primary); }
.group-sub    { font-size:0.8rem; color:var(--cv-text-muted); }

.loc-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:0.75rem; }

.loc-card { display:flex; border-radius:10px; overflow:hidden; background:var(--cv-bg-card); border:1px solid var(--cv-border-muted); transition:transform 0.15s, box-shadow 0.15s; }
.loc-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,0.2); }
.loc-card-inactive { opacity:0.55; }
.loc-card-stripe { width:4px; flex-shrink:0; }
.loc-card-body { flex:1; padding:0.9rem; }
.loc-name { font-size:0.95rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:0.25rem; }
.loc-meta { font-size:0.75rem; color:var(--cv-text-muted); margin-bottom:0.75rem; }
.loc-type-badge { font-size:0.75rem; color:var(--cv-text-secondary); }
.loc-actions { display:flex; gap:0.4rem; flex-wrap:wrap; }

.cv-btn-xs { padding:0.2rem 0.55rem; font-size:0.72rem; }
.badge-green { background:rgba(16,185,129,0.15); color:var(--cv-green-bright); padding:0.15rem 0.5rem; border-radius:999px; font-size:0.72rem; }
.badge-muted { background:rgba(148,163,184,0.15); color:var(--cv-text-muted); padding:0.15rem 0.5rem; border-radius:999px; font-size:0.72rem; }
.cv-btn-danger-ghost { background:transparent; color:var(--cv-danger-text); border:1px solid transparent; }
.cv-btn-danger-ghost:hover { background:rgba(239,68,68,0.1); border-color:var(--cv-danger-text); }
.cv-empty-state { text-align:center; padding:4rem 2rem; }
.cv-empty-icon  { font-size:3rem; margin-bottom:1rem; opacity:0.3; }
.cv-empty-title { font-size:1.1rem; font-weight:600; color:var(--cv-text-primary); margin-bottom:0.4rem; }
.cv-empty-sub   { color:var(--cv-text-muted); font-size:0.9rem; }
.fade-enter-active, .fade-leave-active { transition:opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity:0; }
</style>