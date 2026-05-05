<template>
    <div class="coa-group">
        <div class="coa-group-header" @click="collapsed = !collapsed">
            <button class="coa-collapse-btn" type="button">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline v-if="!collapsed" points="6 9 12 15 18 9"/>
                    <polyline v-else points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <span class="coa-group-title">
                <!-- Show the system header account code next to the group name -->
                <span v-if="headerAccount" class="coa-group-code">{{ headerAccount.code }}</span>
                {{ title }}
                <span v-if="headerAccount?.is_system" class="coa-system-badge">sys</span>
            </span>
            <span class="coa-group-count">{{ children.length }}</span>
            <span class="coa-group-balance">{{ fmt(groupBalance) }} EGP</span>
        </div>

        <div v-show="!collapsed" class="coa-group-body">
            <slot name="special" />

            <!-- Children: accounts whose parent_id === headerAccount.id -->
            <div
                v-for="acc in children"
                :key="acc.id"
                class="coa-account-row"
                :class="{ 'coa-row-inactive': !acc.is_active }"
            >
                <div class="coa-row-indent"></div>
                <span class="coa-code">{{ acc.code }}</span>
                <span class="coa-name">
                    {{ isRtl ? (acc.name_ar ?? acc.name_en) : (acc.name_en ?? acc.name_ar) }}
                    <span v-if="acc.is_system" class="coa-system-badge">sys</span>
                    <span v-if="!acc.allow_direct_posting" class="coa-header-badge">group</span>
                </span>
                <span class="coa-row-balance">{{ fmt(acc.balance ?? 0) }} EGP</span>
                <div class="coa-row-actions">
                    <button class="cv-action-btn" type="button"
                        @click.stop="$emit('add', buildChildContext(acc))"
                        :title="isRtl ? 'إضافة حساب فرعي' : 'Add sub-account'">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <button class="cv-action-btn cv-action-btn-amber" type="button" @click.stop="$emit('edit', acc)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button
                        v-if="!acc.is_system && acc.children_count === 0"
                        class="cv-action-btn cv-action-btn-danger"
                        type="button"
                        @click.stop="$emit('delete', acc)"
                    >
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                </div>
            </div>

            <!-- Repeater "add" button — passes the header account as context -->
            <div v-if="repeater && headerAccount" class="coa-repeater-add" @click="$emit('add', repeaterContext)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ repeaterLabel }}
            </div>

            <div v-if="children.length === 0 && !repeater" class="coa-group-empty">
                {{ isRtl ? 'لا توجد حسابات بعد' : 'No accounts yet' }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
    title:         String,
    headerCode:    String,   // The exact code of the system group header e.g. '1400'
    accounts:      { type: Array, default: () => [] },  // ALL accounts flat list
    isRtl:         Boolean,
    repeater:      Boolean,
    repeaterLabel: String,
})
defineEmits(['add', 'edit', 'toggle', 'delete'])

const collapsed = ref(false)

// The system header account — matched by exact code, not prefix
const headerAccount = computed(() =>
    props.accounts.find(a => a.code === props.headerCode) ?? null
)

// Direct children: accounts whose parent_id === headerAccount.id
// This is the ONLY correct way to determine children — not string prefix matching
const children = computed(() => {
    if (!headerAccount.value) return []
    return props.accounts
        .filter(a => a.parent_id === headerAccount.value.id)
        .sort((a, b) => String(a.code).localeCompare(String(b.code)))
})

// Sum of all direct children balances
const groupBalance = computed(() =>
    children.value.reduce((s, a) => s + (a.balance ?? 0), 0)
)

// Context passed to panel when the repeater button is clicked
// Uses the actual header account data — code, type, normal_balance, id
const repeaterContext = computed(() => {
    if (!headerAccount.value) return null
    return {
        label:          headerAccount.value.name_en ?? headerAccount.value.name_ar ?? '',
        labelAr:        headerAccount.value.name_ar ?? headerAccount.value.name_en ?? '',
        codePrefix:     headerAccount.value.code,
        type:           headerAccount.value.type,
        normal_balance: headerAccount.value.normal_balance,
        parentId:       headerAccount.value.id,
    }
})

// Context when clicking + on a child account row (for adding a sub-account under that child)
function buildChildContext(acc) {
    return {
        label:          acc.name_en ?? acc.name_ar ?? '',
        labelAr:        acc.name_ar ?? acc.name_en ?? '',
        codePrefix:     acc.code,
        type:           acc.type,
        normal_balance: acc.normal_balance,
        parentId:       acc.id,
    }
}

const fmt = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(v || 0)
</script>

<style scoped>
.coa-group { border-bottom: 1px solid var(--cv-border); }
.coa-group:last-child { border-bottom: none; }
.coa-group-header {
    display: flex; align-items: center; gap: 0.625rem;
    padding: 0.5625rem 1rem; cursor: pointer;
    background: var(--cv-bg-card-alt); transition: var(--cv-transition);
}
.coa-group-header:hover { background: var(--cv-bg-hover); }
.coa-collapse-btn { background: transparent; border: none; color: var(--cv-text-muted); cursor: pointer; display: flex; align-items: center; }
.coa-group-code   { font-family: monospace; font-size: 0.6875rem; color: var(--cv-text-muted); margin-inline-end: 0.4rem; font-weight: 700; }
.coa-group-title  { font-size: 0.8125rem; font-weight: 600; color: var(--cv-text-secondary); flex: 1; display: flex; align-items: center; gap: 0.35rem; }
.coa-group-count  { font-size: 0.6875rem; padding: 0.125rem 0.5rem; background: var(--cv-bg-hover); border-radius: 10px; color: var(--cv-text-muted); }
.coa-group-balance { font-size: 0.8125rem; color: var(--cv-blue-text); font-weight: 600; min-width: 120px; text-align: end; }
.coa-group-body   { background: var(--cv-bg-card); }

.coa-account-row {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.4375rem 1rem 0.4375rem 1.5rem;
    border-bottom: 1px solid var(--cv-border);
    transition: background-color 0.12s ease;
}
.coa-account-row:last-child { border-bottom: none; }
.coa-account-row:hover      { background: var(--cv-bg-hover); }
.coa-row-inactive            { opacity: 0.45; }
.coa-row-indent              { width: 12px; border-left: 2px solid var(--cv-border-md); height: 20px; flex-shrink: 0; }
.coa-code    { font-size: 0.75rem; font-weight: 700; color: var(--cv-text-muted); min-width: 56px; font-family: monospace; }
.coa-name    { font-size: 0.8125rem; color: var(--cv-text-primary); flex: 1; display: flex; align-items: center; gap: 0.375rem; }
.coa-row-balance { font-size: 0.8125rem; color: var(--cv-blue-text); min-width: 130px; text-align: end; font-weight: 600; }
.coa-row-actions { display: flex; gap: 0.25rem; }

.coa-system-badge {
    font-size: 0.6rem; padding: 0.1rem 0.35rem;
    background: var(--cv-blue-light); border: 1px solid var(--cv-blue-border);
    border-radius: 3px; color: var(--cv-blue-text); font-weight: 700;
}
.coa-header-badge {
    font-size: 0.6rem; padding: 0.1rem 0.35rem;
    background: var(--cv-amber-light); border: 1px solid var(--cv-amber-border);
    border-radius: 3px; color: var(--cv-amber-text); font-weight: 700;
}
.coa-repeater-add {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 1rem 0.5rem 1.5rem;
    font-size: 0.75rem; font-weight: 600; color: var(--cv-amber-text);
    cursor: pointer; border-top: 1px dashed var(--cv-amber-border);
    background: var(--cv-amber-light); transition: var(--cv-transition);
}
.coa-repeater-add:hover { background: rgba(232,160,48,0.15); }
.coa-group-empty {
    padding: 0.75rem 1rem 0.75rem 1.5rem;
    font-size: 0.75rem; color: var(--cv-text-muted); font-style: italic;
}
</style>