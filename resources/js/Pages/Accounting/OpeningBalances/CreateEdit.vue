<script setup>
/**
 * Accounting/OpeningBalances/CreateEdit.vue
 *
 * Opening Balance Wizard — mirrors the company's chart of accounts.
 * Every balance-sheet account the user created appears here,
 * grouped by type (Assets → Liabilities → Equity).
 *
 * Special behaviour per account flag:
 *   is_ar / is_nr  → "Customers" expand button shows per-customer sub-table
 *   is_ap / is_np  → "Suppliers" expand button shows per-supplier sub-table
 *   is_inventory   → account row is hidden; inventory section handles it instead
 *
 * Inventory section: all master inventory items listed with
 *   qty input + unit_cost input → value auto-computed (Debit)
 *
 * 📌 METHODOLOGY REFERENCE: OpeningBalanceEquityPlug
 */

import { computed, ref, watch, reactive } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    mode:                    { type: String,  default: 'create' },
    entry:                   { type: Object,  default: null },
    fiscalYears:             { type: Array,   default: () => [] },
    // accountsByType: { asset: [], liability: [], equity: [] }
    // Each account has: id, code, name_en, name_ar, type, normal_balance,
    //   is_ar, is_nr, is_ap, is_np, is_inventory
    accountsByType:          { type: Object,  default: () => ({ asset: [], liability: [], equity: [] }) },
    customers:               { type: Array,   default: () => [] },
    suppliers:               { type: Array,   default: () => [] },
    // inventoryItems: [{ id, name_en, name_ar, category, unit, suggested_cost, account_code }]
    inventoryItems:          { type: Array,   default: () => [] },
    reAccounts:              { type: Array,   default: () => [] },
    preselectedFiscalYearId: { type: Number,  default: null },
})

// ── Locale ────────────────────────────────────────────────────────
const { locale: lang, isRtl, n } = useLocale()
const t = (en, ar) => lang.value === 'ar' ? ar : en

// ── Helpers ───────────────────────────────────────────────────────
const isEdit = computed(() => props.mode === 'edit')
const hasFiscalYears = computed(() => props.fiscalYears.length > 0)

const accountLabel = (acc) => {
    if (!acc) return ''
    const name = lang.value === 'ar' ? (acc.name_ar || acc.name_en || '') : (acc.name_en || acc.name_ar || '')
    return `${acc.code} — ${name}`
}
const partnerLabel = (p) => {
    if (!p) return ''
    return lang.value === 'ar' ? (p.name_ar || p.name_en || '') : (p.name_en || p.name_ar || '')
}
const fmt = (v) => new Intl.NumberFormat(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
}).format(Math.abs(v || 0))

// ── Build initial account_lines from accountsByType ───────────────
// Each account gets a reactive slot. On edit, we merge saved values.
function buildAccountLines(accountsByType, savedLines) {
    const savedMap = {}
    if (savedLines) {
        for (const s of savedLines) savedMap[s.account_id] = s
    }

    const result = []
    for (const type of ['asset', 'liability', 'equity']) {
        for (const acc of (accountsByType[type] ?? [])) {
            // Inventory accounts are handled in the inventory section
            if (acc.is_inventory) continue

            const saved  = savedMap[acc.id]
            const defSide = acc.normal_balance === 'debit' ? 'dr' : 'cr'
            result.push({
                account_id:    acc.id,
                amount:        saved?.amount        ?? '',
                side:          saved?.side          ?? defSide,
                memo:          saved?.memo          ?? '',
                customer_rows: saved?.customer_rows ?? [],
                supplier_rows: saved?.supplier_rows ?? [],
                // UI state (not submitted)
                _expanded:     !!(saved?.customer_rows?.length || saved?.supplier_rows?.length),
                _acc:          acc,   // reference for flags/labels
            })
        }
    }
    return result
}

// Build initial inventory_lines from inventoryItems
function buildInventoryLines(inventoryItems, savedLines) {
    const savedMap = {}
    if (savedLines) {
        for (const s of savedLines) {
            if (s.item_id) savedMap[s.item_id] = s
        }
    }

    return inventoryItems.map(item => {
        const saved = savedMap[item.id]
        return {
            item_id:      item.id,
            account_code: item.account_code,
            account_id:   null,   // resolved from accountsByType on mount
            qty:          saved?.qty       ?? '',
            unit_cost:    saved?.unit_cost ?? item.suggested_cost ?? '',
            _item:        item,
        }
    })
}

// Resolve account_id for inventory lines by matching account_code
function resolveInventoryAccountIds(inventoryLines, accountsByType) {
    const codeMap = {}
    for (const acc of (accountsByType.asset ?? [])) {
        codeMap[acc.code] = acc.id
    }
    for (const line of inventoryLines) {
        line.account_id = codeMap[line.account_code] ?? null
    }
}

// ── Reactive state ────────────────────────────────────────────────
const accountLines   = reactive(buildAccountLines(props.accountsByType, props.entry?.account_lines))
const inventoryLines = reactive(buildInventoryLines(props.inventoryItems, props.entry?.inventory_lines))
resolveInventoryAccountIds(inventoryLines, props.accountsByType)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
    fiscal_year_id:               props.entry?.fiscal_year_id   ?? props.preselectedFiscalYearId ?? null,
    date:                         props.entry?.date              ?? '',
    description:                  props.entry?.description       ?? '',
    notes:                        props.entry?.notes             ?? '',
    retained_earnings_account_id: props.entry?.retained_earnings_account_id ?? '',
    auto_post:                    false,
    // These are populated from accountLines / inventoryLines at submit time
    account_lines:   [],
    inventory_lines: [],
})

// Auto-fill date from selected FY
watch(() => form.fiscal_year_id, (fyId) => {
    if (!fyId) return
    const fy = props.fiscalYears.find(f => f.id === parseInt(fyId))
    if (fy && !form.date) form.date = fy.start_date
})

// ── Partner sub-table helpers ─────────────────────────────────────
function toggleExpand(line) {
    line._expanded = !line._expanded
}

function addCustomerRow(line) {
    line.customer_rows.push({ customer_id: '', amount: '' })
    line._expanded = true
}
function removeCustomerRow(line, i) { line.customer_rows.splice(i, 1) }

function addSupplierRow(line) {
    line.supplier_rows.push({ supplier_id: '', amount: '' })
    line._expanded = true
}
function removeSupplierRow(line, i) { line.supplier_rows.splice(i, 1) }

// ── Per-account computed totals ───────────────────────────────────
function accountTotal(line) {
    if (line.customer_rows.length > 0) {
        return line.customer_rows.reduce((s, r) => s + parseFloat(r.amount || 0), 0)
    }
    if (line.supplier_rows.length > 0) {
        return line.supplier_rows.reduce((s, r) => s + parseFloat(r.amount || 0), 0)
    }
    return parseFloat(line.amount || 0)
}

// ── Equity plug (live preview) ────────────────────────────────────
// 📌 OpeningBalanceEquityPlug — client-side preview
const totalDebits = computed(() => {
    let sum = 0
    for (const line of accountLines) {
        if (line.side === 'dr') sum += accountTotal(line)
    }
    for (const inv of inventoryLines) {
        const qty  = parseFloat(inv.qty || 0)
        const cost = parseFloat(inv.unit_cost || 0)
        sum += qty * cost
    }
    return sum
})

const totalCredits = computed(() => {
    let sum = 0
    for (const line of accountLines) {
        if (line.side === 'cr') sum += accountTotal(line)
    }
    return sum
})

const equityPlug = computed(() => parseFloat((totalDebits.value - totalCredits.value).toFixed(2)))
const plugSide   = computed(() => {
    if (Math.abs(equityPlug.value) < 0.005) return null
    return equityPlug.value > 0 ? 'cr' : 'dr'   // positive plug → Cr to RE to balance
})

// ── Section totals ────────────────────────────────────────────────
const sectionTotal = (type) =>
    accountLines
        .filter(l => l._acc.type === type)
        .reduce((s, l) => s + (l.side === 'dr' ? accountTotal(l) : -accountTotal(l)), 0)

const inventoryTotal = computed(() =>
    inventoryLines.reduce((s, l) => s + parseFloat(l.qty || 0) * parseFloat(l.unit_cost || 0), 0)
)

// ── Fiscal year ref ───────────────────────────────────────────────
const selectedFy = computed(() =>
    props.fiscalYears.find(f => f.id === Number(form.fiscal_year_id))
)

// ── Submit ────────────────────────────────────────────────────────
const submit = (postNow = false) => {
    form.auto_post = postNow

    // Serialize accountLines (skip zero-amount lines with no sub-rows)
    form.account_lines = accountLines
        .filter(line => {
            if (line.customer_rows.length > 0) return line.customer_rows.some(r => parseFloat(r.amount || 0) > 0)
            if (line.supplier_rows.length > 0) return line.supplier_rows.some(r => parseFloat(r.amount || 0) > 0)
            return parseFloat(line.amount || 0) > 0
        })
        .map(line => ({
            account_id:    line.account_id,
            amount:        accountTotal(line),
            side:          line.side,
            memo:          line.memo || null,
            customer_rows: line.customer_rows
                .filter(r => parseFloat(r.amount || 0) > 0)
                .map(r => ({ customer_id: r.customer_id, amount: r.amount })),
            supplier_rows: line.supplier_rows
                .filter(r => parseFloat(r.amount || 0) > 0)
                .map(r => ({ supplier_id: r.supplier_id, amount: r.amount })),
        }))

    // Serialize inventoryLines (skip zero-value lines)
    form.inventory_lines = inventoryLines
        .filter(inv => parseFloat(inv.qty || 0) > 0 && inv.account_id)
        .map(inv => ({
            account_id: inv.account_id,
            item_id:    inv.item_id,
            qty:        inv.qty,
            unit_cost:  inv.unit_cost,
        }))

    if (isEdit.value) {
        form.put(route('opening-balances.update', props.entry.id))
    } else {
        form.post(route('opening-balances.store'))
    }
}

// ── Post confirm modal ────────────────────────────────────────────
const showPostModal = ref(false)
const confirmPost = () => { showPostModal.value = false; submit(true) }

// ── Collapse / expand all ─────────────────────────────────────────
const collapseAll = () => { for (const l of accountLines) l._expanded = false }
const expandAll   = () => { for (const l of accountLines) if (l._acc.is_ar || l._acc.is_nr || l._acc.is_ap || l._acc.is_np) l._expanded = true }

// ── Account type grouping helpers ────────────────────────────────
const assetLines     = computed(() => accountLines.filter(l => l._acc.type === 'asset'))
const liabilityLines = computed(() => accountLines.filter(l => l._acc.type === 'liability'))
const equityLines    = computed(() => accountLines.filter(l => l._acc.type === 'equity' && l._acc.id !== Number(form.retained_earnings_account_id)))
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ HERO ══════════════════════════════════════════════ -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">{{ t('Accounting', 'المحاسبة') }}</div>
                <div class="cv-page-hero-title">
                    {{ isEdit ? t('Edit Opening Balances', 'تعديل الأرصدة الافتتاحية') : t('Opening Balances', 'الأرصدة الافتتاحية') }}
                </div>
                <div class="cv-page-hero-sub">
                    <template v-if="selectedFy">
                        {{ t('Fiscal Year', 'السنة المالية') }}
                        <span style="color:var(--cv-amber);font-weight:700;margin-inline-start:4px">{{ selectedFy.year }}</span>
                        <span class="cv-badge" :class="selectedFy.status === 'open' ? 'cv-badge-green' : 'cv-badge-neutral'" style="margin-inline-start:8px">
                            {{ selectedFy.status === 'open' ? t('Open','مفتوحة') : selectedFy.status }}
                        </span>
                    </template>
                    <template v-else>{{ t('Enter balances once when starting the system','أدخل الأرصدة الافتتاحية مرة واحدة عند بدء تشغيل النظام') }}</template>
                </div>
                <div class="cv-page-hero-actions">
                    <a :href="route('opening-balances.index')" class="cv-btn cv-btn-ghost">{{ t('Back','العودة') }}</a>
                </div>
            </div>

            <!-- ══ CONTENT ══════════════════════════════════════════ -->
            <div class="cv-page" style="display:flex;flex-direction:column;gap:1.5rem;padding-bottom:3rem">

                <!-- Draft warning -->
                <div v-if="isEdit" class="cv-alert cv-alert-success" style="margin:0">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    {{ t('This entry is still a draft and can be edited. Once posted, it cannot be changed.','هذا السجل لا يزال مسودة — يمكن تعديله. بمجرد الترحيل لا يمكن التعديل.') }}
                </div>

                <!-- No fiscal year -->
                <div v-if="!hasFiscalYears" class="cv-alert cv-alert-danger" style="margin:0;display:flex;align-items:flex-start;gap:0.75rem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.25rem">{{ t('No Fiscal Years Found','لا توجد سنوات مالية') }}</div>
                        <div style="font-size:0.8rem;opacity:0.9">{{ t('You must create an open fiscal year before entering opening balances.','يجب إنشاء سنة مالية مفتوحة أولاً قبل إدخال الأرصدة الافتتاحية.') }}</div>
                        <a :href="route('fiscal-years.index')" class="cv-btn cv-btn-danger cv-btn-sm" style="margin-top:0.625rem;display:inline-flex">{{ t('Create Fiscal Year','إنشاء سنة مالية') }}</a>
                    </div>
                </div>

                <!-- No accounts warning -->
                <div v-if="!accountsByType.asset?.length && !accountsByType.liability?.length && !accountsByType.equity?.length" class="cv-alert cv-alert-danger" style="margin:0;display:flex;align-items:flex-start;gap:0.75rem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.25rem">{{ t('No Chart of Accounts Found','لا يوجد دليل حسابات') }}</div>
                        <div style="font-size:0.8rem;opacity:0.9">{{ t('Please set up your chart of accounts first before entering opening balances.','يرجى إعداد دليل الحسابات أولاً قبل إدخال الأرصدة الافتتاحية.') }}</div>
                        <a :href="route('accounts.index')" class="cv-btn cv-btn-amber cv-btn-sm" style="margin-top:0.625rem;display:inline-flex">{{ t('Go to Chart of Accounts','الذهاب إلى دليل الحسابات') }}</a>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     SECTION 1 — Fiscal Year & Date
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1.5rem">
                    <div style="display:flex;align-items:center;gap:0.875rem;margin-bottom:1.25rem">
                        <div class="ob-badge">{{ t('1','١') }}</div>
                        <div>
                            <div class="ob-section-title">{{ t('Fiscal Year & Date','السنة المالية والتاريخ') }}</div>
                            <div class="ob-section-sub">{{ t('Select the fiscal year and as-of date','اختر السنة المالية وتاريخ الرصيد الافتتاحي') }}</div>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem">
                        <div class="cv-field">
                            <label class="cv-label">{{ t('Fiscal Year','السنة المالية') }} <span style="color:var(--cv-danger-text)">*</span></label>
                            <select v-model="form.fiscal_year_id" class="cv-select" :disabled="isEdit">
                                <option :value="null">{{ t('— Select Fiscal Year —','— اختر السنة المالية —') }}</option>
                                <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">
                                    {{ fy.year }} ({{ fy.start_date }} → {{ fy.end_date }}) — {{ fy.status === 'open' ? t('Open','مفتوحة') : fy.status }}
                                </option>
                            </select>
                            <div v-if="form.errors.fiscal_year_id" class="cv-field-error">{{ form.errors.fiscal_year_id }}</div>
                        </div>
                        <div class="cv-field">
                            <label class="cv-label">{{ t('As-of Date','تاريخ الرصيد الافتتاحي') }} <span style="color:var(--cv-danger-text)">*</span></label>
                            <input v-model="form.date" type="date" class="cv-input" />
                            <div v-if="form.errors.date" class="cv-field-error">{{ form.errors.date }}</div>
                        </div>
                        <div class="cv-field" style="grid-column:1/-1">
                            <label class="cv-label">{{ t('Description','الوصف') }}</label>
                            <input v-model="form.description" type="text" class="cv-input" :placeholder="t('Opening Balances — optional','أرصدة افتتاحية — اختياري')" />
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     SECTION 2 — ASSETS
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1.5rem">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem">
                        <div style="display:flex;align-items:center;gap:0.875rem">
                            <div class="ob-badge ob-badge-blue">{{ t('2','٢') }}</div>
                            <div>
                                <div class="ob-section-title">{{ t('Assets','الأصول') }}</div>
                                <div class="ob-section-sub">{{ t('Enter opening balance for each asset account — Debit side','أدخل الرصيد الافتتاحي لكل حساب أصل — جانب المدين') }}</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem">
                            <span style="font-size:0.72rem;color:var(--cv-text-muted)">{{ t('Total Assets','إجمالي الأصول') }}</span>
                            <span style="color:var(--cv-blue-text);font-weight:700;font-size:0.9rem">{{ fmt(sectionTotal('asset') + inventoryTotal) }} {{ t('EGP','ج.م') }}</span>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="assetLines.length === 0 && inventoryItems.length === 0" class="ob-empty">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        <p>{{ t('No asset accounts found. Go to Chart of Accounts to create them.','لا توجد حسابات أصول. اذهب إلى دليل الحسابات لإنشائها.') }}</p>
                    </div>

                    <!-- Account rows -->
                    <div v-for="(line, idx) in assetLines" :key="line.account_id" class="ob-account-row" :class="{ 'ob-account-row-expanded': line._expanded }">

                        <!-- Main row -->
                        <div class="ob-main-row">
                            <div class="ob-code">{{ line._acc.code }}</div>
                            <div class="ob-name">{{ accountLabel(line._acc) }}</div>

                            <!-- Normal side badge -->
                            <span class="cv-badge cv-badge-info" style="font-size:0.65rem">{{ line.side === 'dr' ? t('Dr','مدين') : t('Cr','دائن') }}</span>

                            <!-- Amount input (hidden when sub-rows exist) -->
                            <div v-if="line.customer_rows.length === 0 && line.supplier_rows.length === 0" class="ob-amount-wrap">
                                <input
                                    v-model="line.amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="cv-input ob-amount-input"
                                    placeholder="0.00"
                                    style="color:var(--cv-blue-text)"
                                />
                            </div>
                            <!-- Sub-row total display -->
                            <div v-else class="ob-amount-wrap ob-amount-derived">
                                <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(accountTotal(line)) }}</span>
                                <span style="font-size:0.65rem;color:var(--cv-text-muted);margin-inline-start:4px">{{ t('EGP','ج.م') }}</span>
                            </div>

                            <!-- Side toggle (allow manual override for unusual accounts) -->
                            <div class="ob-side-toggle">
                                <button type="button" :class="['ob-side-btn', line.side === 'dr' ? 'ob-side-btn-active-blue' : '']" @click="line.side = 'dr'">{{ t('Dr','مد') }}</button>
                                <button type="button" :class="['ob-side-btn', line.side === 'cr' ? 'ob-side-btn-active-amber' : '']" @click="line.side = 'cr'">{{ t('Cr','دا') }}</button>
                            </div>

                            <!-- Customer expand button — AR / NR accounts -->
                            <button
                                v-if="line._acc.is_ar || line._acc.is_nr"
                                type="button"
                                class="cv-btn cv-btn-blue cv-btn-sm ob-partner-btn"
                                @click="addCustomerRow(line)"
                                :title="t('Add customer balance','إضافة رصيد عميل')"
                            >
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>
                                {{ t('+ Customer','+ عميل') }}
                            </button>

                            <!-- Error -->
                            <div v-if="form.errors[`account_lines.${idx}.amount`]" class="cv-field-error" style="width:100%;margin-top:0.25rem">{{ form.errors[`account_lines.${idx}.amount`] }}</div>
                        </div>

                        <!-- Customer sub-table (AR / NR) -->
                        <div v-if="line.customer_rows.length > 0" class="ob-sub-table">
                            <div class="ob-sub-header">
                                <span>{{ t('Customer','العميل') }}</span>
                                <span>{{ t('Amount (EGP)','المبلغ (ج.م)') }}</span>
                                <span></span>
                            </div>
                            <div v-for="(cr, ci) in line.customer_rows" :key="ci" class="ob-sub-row">
                                <select v-model="cr.customer_id" class="cv-select ob-sub-select">
                                    <option value="">{{ t('— Select Customer —','— اختر عميلاً —') }}</option>
                                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ partnerLabel(c) }}</option>
                                </select>
                                <input v-model="cr.amount" type="number" step="0.01" min="0" class="cv-input ob-sub-amount" placeholder="0.00" style="color:var(--cv-blue-text)" />
                                <button type="button" class="cv-action-btn cv-action-btn-danger" @click="removeCustomerRow(line, ci)">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                </button>
                            </div>
                            <div class="ob-sub-footer">
                                <button type="button" class="cv-btn cv-btn-ghost cv-btn-sm" @click="addCustomerRow(line)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    {{ t('Add Row','إضافة سطر') }}
                                </button>
                                <span style="font-size:0.72rem;color:var(--cv-text-muted)">{{ t('Sub-total','الإجمالي الفرعي') }}: <strong style="color:var(--cv-blue-text)">{{ fmt(accountTotal(line)) }} {{ t('EGP','ج.م') }}</strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- ── Inventory sub-section ─────────────────────── -->
                    <div v-if="inventoryItems.length > 0" style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--cv-border)">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.875rem;flex-wrap:wrap;gap:0.5rem">
                            <div style="display:flex;align-items:center;gap:0.625rem">
                                <div style="width:0.25rem;height:1.25rem;background:var(--cv-green-bright);border-radius:2px"></div>
                                <div>
                                    <div style="font-size:0.82rem;font-weight:700;color:var(--cv-text-primary)">{{ t('Inventory Opening Stock','المخزون الافتتاحي') }}</div>
                                    <div style="font-size:0.7rem;color:var(--cv-text-muted)">{{ t('Enter qty and unit cost for each item — value is auto-computed','أدخل الكمية والتكلفة لكل صنف — القيمة تحسب تلقائيًا') }}</div>
                                </div>
                            </div>
                            <span style="font-size:0.72rem;color:var(--cv-text-muted)">
                                {{ t('Total','الإجمالي') }}: <strong style="color:var(--cv-blue-text)">{{ fmt(inventoryTotal) }} {{ t('EGP','ج.م') }}</strong>
                            </span>
                        </div>

                        <!-- Inventory table header -->
                        <div class="ob-inv-header">
                            <span>{{ t('Item','الصنف') }}</span>
                            <span>{{ t('Category','الفئة') }}</span>
                            <span>{{ t('Account','الحساب') }}</span>
                            <span>{{ t('Qty','الكمية') }}</span>
                            <span>{{ t('Unit Cost (EGP)','تكلفة الوحدة (ج.م)') }}</span>
                            <span>{{ t('Value (EGP)','القيمة (ج.م)') }}</span>
                        </div>

                        <div v-for="inv in inventoryLines" :key="inv.item_id" class="ob-inv-row">
                            <!-- Item name -->
                            <div class="ob-inv-name">
                                <div style="font-weight:600;font-size:0.82rem;color:var(--cv-text-primary)">{{ n(inv._item, 'name') }}</div>
                                <div style="font-size:0.66rem;color:var(--cv-text-muted)">{{ inv._item.unit }}</div>
                            </div>
                            <!-- Category badge -->
                            <div>
                                <span class="cv-badge" :class="{
                                    'cv-badge-green':   inv._item.category === 'feed',
                                    'cv-badge-info':    inv._item.category === 'medicine',
                                    'cv-badge-amber':   inv._item.category === 'vaccine',
                                    'cv-badge-neutral': !['feed','medicine','vaccine'].includes(inv._item.category),
                                }" style="font-size:0.65rem">{{ inv._item.category }}</span>
                            </div>
                            <!-- Account code -->
                            <div style="font-size:0.72rem;color:var(--cv-text-muted);font-family:monospace">
                                {{ inv._item.account_code }}
                                <span v-if="!inv.account_id" style="color:var(--cv-danger-text);margin-inline-start:4px" :title="t('Account not found in Chart of Accounts','الحساب غير موجود في دليل الحسابات')">⚠</span>
                            </div>
                            <!-- Qty -->
                            <input
                                v-model="inv.qty"
                                type="number"
                                step="0.001"
                                min="0"
                                class="cv-input ob-inv-input"
                                :placeholder="t('0.000','٠٫٠٠٠')"
                            />
                            <!-- Unit cost -->
                            <input
                                v-model="inv.unit_cost"
                                type="number"
                                step="0.01"
                                min="0"
                                class="cv-input ob-inv-input"
                                :placeholder="t('0.00','٠٫٠٠')"
                                style="color:var(--cv-blue-text)"
                            />
                            <!-- Value (computed) -->
                            <div class="ob-inv-value" :class="parseFloat(inv.qty||0) * parseFloat(inv.unit_cost||0) > 0 ? 'ob-inv-value-active' : ''">
                                {{ fmt(parseFloat(inv.qty||0) * parseFloat(inv.unit_cost||0)) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     SECTION 3 — LIABILITIES
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1.5rem">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem">
                        <div style="display:flex;align-items:center;gap:0.875rem">
                            <div class="ob-badge ob-badge-red">{{ t('3','٣') }}</div>
                            <div>
                                <div class="ob-section-title">{{ t('Liabilities','الالتزامات') }}</div>
                                <div class="ob-section-sub">{{ t('Enter opening balance for each liability account — Credit side','أدخل الرصيد الافتتاحي لكل حساب التزام — جانب الدائن') }}</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem">
                            <span style="font-size:0.72rem;color:var(--cv-text-muted)">{{ t('Total Liabilities','إجمالي الالتزامات') }}</span>
                            <span style="color:var(--cv-danger-text);font-weight:700;font-size:0.9rem">{{ fmt(Math.abs(sectionTotal('liability'))) }} {{ t('EGP','ج.م') }}</span>
                        </div>
                    </div>

                    <div v-if="liabilityLines.length === 0" class="ob-empty">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <p>{{ t('No liability accounts found.','لا توجد حسابات التزامات.') }}</p>
                    </div>

                    <div v-for="(line, idx) in liabilityLines" :key="line.account_id" class="ob-account-row" :class="{ 'ob-account-row-expanded': line._expanded }">
                        <div class="ob-main-row">
                            <div class="ob-code">{{ line._acc.code }}</div>
                            <div class="ob-name">{{ accountLabel(line._acc) }}</div>
                            <span class="cv-badge cv-badge-amber" style="font-size:0.65rem">{{ line.side === 'cr' ? t('Cr','دائن') : t('Dr','مدين') }}</span>

                            <div v-if="line.supplier_rows.length === 0" class="ob-amount-wrap">
                                <input v-model="line.amount" type="number" step="0.01" min="0" class="cv-input ob-amount-input" placeholder="0.00" style="color:var(--cv-blue-text)" />
                            </div>
                            <div v-else class="ob-amount-wrap ob-amount-derived">
                                <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(accountTotal(line)) }}</span>
                                <span style="font-size:0.65rem;color:var(--cv-text-muted);margin-inline-start:4px">{{ t('EGP','ج.م') }}</span>
                            </div>

                            <div class="ob-side-toggle">
                                <button type="button" :class="['ob-side-btn', line.side === 'dr' ? 'ob-side-btn-active-blue' : '']" @click="line.side = 'dr'">{{ t('Dr','مد') }}</button>
                                <button type="button" :class="['ob-side-btn', line.side === 'cr' ? 'ob-side-btn-active-amber' : '']" @click="line.side = 'cr'">{{ t('Cr','دا') }}</button>
                            </div>

                            <!-- Supplier expand button — AP / NP accounts -->
                            <button
                                v-if="line._acc.is_ap || line._acc.is_np"
                                type="button"
                                class="cv-btn cv-btn-amber cv-btn-sm ob-partner-btn"
                                @click="addSupplierRow(line)"
                                :title="t('Add supplier balance','إضافة رصيد مورد')"
                            >
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
                                {{ t('+ Supplier','+ مورد') }}
                            </button>
                        </div>

                        <!-- Supplier sub-table (AP / NP) -->
                        <div v-if="line.supplier_rows.length > 0" class="ob-sub-table">
                            <div class="ob-sub-header">
                                <span>{{ t('Supplier','المورد') }}</span>
                                <span>{{ t('Amount (EGP)','المبلغ (ج.م)') }}</span>
                                <span></span>
                            </div>
                            <div v-for="(sr, si) in line.supplier_rows" :key="si" class="ob-sub-row">
                                <select v-model="sr.supplier_id" class="cv-select ob-sub-select">
                                    <option value="">{{ t('— Select Supplier —','— اختر موردًا —') }}</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ partnerLabel(s) }}</option>
                                </select>
                                <input v-model="sr.amount" type="number" step="0.01" min="0" class="cv-input ob-sub-amount" placeholder="0.00" style="color:var(--cv-blue-text)" />
                                <button type="button" class="cv-action-btn cv-action-btn-danger" @click="removeSupplierRow(line, si)">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                </button>
                            </div>
                            <div class="ob-sub-footer">
                                <button type="button" class="cv-btn cv-btn-ghost cv-btn-sm" @click="addSupplierRow(line)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    {{ t('Add Row','إضافة سطر') }}
                                </button>
                                <span style="font-size:0.72rem;color:var(--cv-text-muted)">{{ t('Sub-total','الإجمالي الفرعي') }}: <strong style="color:var(--cv-blue-text)">{{ fmt(accountTotal(line)) }} {{ t('EGP','ج.م') }}</strong></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     SECTION 4 — EQUITY
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1.5rem">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem">
                        <div style="display:flex;align-items:center;gap:0.875rem">
                            <div class="ob-badge ob-badge-green">{{ t('4','٤') }}</div>
                            <div>
                                <div class="ob-section-title">{{ t('Equity','حقوق الملكية') }}</div>
                                <div class="ob-section-sub">{{ t('Retained Earnings is auto-computed as the equity plug','الأرباح المرحلة تُحسب تلقائيًا كفارق الترصيد') }}</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem">
                            <span style="font-size:0.72rem;color:var(--cv-text-muted)">{{ t('Total Equity','إجمالي حقوق الملكية') }}</span>
                            <span style="color:var(--cv-green-bright);font-weight:700;font-size:0.9rem">{{ fmt(Math.abs(sectionTotal('equity'))) }} {{ t('EGP','ج.م') }}</span>
                        </div>
                    </div>

                    <div v-if="equityLines.length === 0" class="ob-empty">
                        <p>{{ t('No equity accounts found (excluding Retained Earnings).','لا توجد حسابات حقوق ملكية (باستثناء الأرباح المرحلة).') }}</p>
                    </div>

                    <div v-for="(line, idx) in equityLines" :key="line.account_id" class="ob-account-row">
                        <div class="ob-main-row">
                            <div class="ob-code">{{ line._acc.code }}</div>
                            <div class="ob-name">{{ accountLabel(line._acc) }}</div>
                            <span class="cv-badge cv-badge-green" style="font-size:0.65rem">{{ line.side === 'cr' ? t('Cr','دائن') : t('Dr','مدين') }}</span>
                            <div class="ob-amount-wrap">
                                <input v-model="line.amount" type="number" step="0.01" min="0" class="cv-input ob-amount-input" placeholder="0.00" style="color:var(--cv-blue-text)" />
                            </div>
                            <div class="ob-side-toggle">
                                <button type="button" :class="['ob-side-btn', line.side === 'dr' ? 'ob-side-btn-active-blue' : '']" @click="line.side = 'dr'">{{ t('Dr','مد') }}</button>
                                <button type="button" :class="['ob-side-btn', line.side === 'cr' ? 'ob-side-btn-active-amber' : '']" @click="line.side = 'cr'">{{ t('Cr','دا') }}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     SECTION 5 — Retained Earnings (Equity Plug)
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1.5rem">
                    <div style="display:flex;align-items:center;gap:0.875rem;margin-bottom:1.25rem">
                        <div class="ob-badge" style="background:linear-gradient(135deg,var(--cv-green-bright),#22c55e)">{{ t('5','٥') }}</div>
                        <div>
                            <div class="ob-section-title">{{ t('Retained Earnings Account (Auto-Plug)','حساب الأرباح المرحلة (ترصيد تلقائي)') }}</div>
                            <div class="ob-section-sub">{{ t('The difference between total debits and credits is auto-posted here','الفرق بين إجمالي المدين والدائن يُرحَّل تلقائيًا لهذا الحساب') }}</div>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;align-items:end">
                        <div class="cv-field">
                            <label class="cv-label">{{ t('Retained Earnings Account','حساب الأرباح المرحلة') }}</label>
                            <select v-model="form.retained_earnings_account_id" class="cv-select">
                                <option value="">{{ t('— Select Account —','— اختر الحساب —') }}</option>
                                <option v-for="acc in reAccounts" :key="acc.id" :value="acc.id">{{ accountLabel(acc) }}</option>
                            </select>
                        </div>

                        <!-- Live plug preview -->
                        <div class="cv-card" :style="`padding:1rem;border-color:${Math.abs(equityPlug) < 0.005 ? 'var(--cv-success-border)' : 'var(--cv-info-border)'}`">
                            <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--cv-text-muted);margin-bottom:0.375rem">
                                {{ t('Equity Plug Amount','قيمة الترصيد التلقائي') }}
                            </div>
                            <div v-if="Math.abs(equityPlug) < 0.005" style="color:var(--cv-success-text);font-weight:700;font-size:1rem">
                                ✓ {{ t('Naturally Balanced','متوازن تلقائيًا') }}
                            </div>
                            <div v-else style="font-size:1.1rem;font-weight:700;color:var(--cv-blue-text)">
                                {{ fmt(equityPlug) }} {{ t('EGP','ج.م') }}
                                <span class="cv-badge cv-badge-info" style="margin-inline-start:6px">
                                    {{ plugSide === 'cr' ? t('Cr to RE','دائن للأرباح') : t('Dr to RE','مدين للأرباح') }}
                                </span>
                            </div>
                            <div style="font-size:0.65rem;color:var(--cv-text-muted);margin-top:0.375rem">
                                {{ t('Dr','مدين') }}: {{ fmt(totalDebits) }} &nbsp;|&nbsp; {{ t('Cr','دائن') }}: {{ fmt(totalCredits) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     SECTION 6 — Notes
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1.5rem">
                    <div style="display:flex;align-items:center;gap:0.875rem;margin-bottom:1.25rem">
                        <div class="ob-badge ob-badge-muted">{{ t('6','٦') }}</div>
                        <div class="ob-section-title">{{ t('Notes','ملاحظات') }}</div>
                    </div>
                    <div class="cv-field">
                        <textarea v-model="form.notes" class="cv-textarea" rows="3" :placeholder="t('Optional notes...','ملاحظات اختيارية...')" />
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     ACTIONS BAR
                ════════════════════════════════════════════════════ -->
                <div class="cv-card" style="padding:1rem 1.5rem">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem">
                        <a :href="route('opening-balances.index')" class="cv-btn cv-btn-ghost">{{ t('Cancel','إلغاء') }}</a>
                        <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
                            <button type="button" class="cv-btn cv-btn-secondary" :disabled="form.processing" @click="submit(false)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                {{ t('Save Draft','حفظ مسودة') }}
                            </button>
                            <button type="button" class="cv-btn cv-btn-amber" :disabled="form.processing" @click="showPostModal = true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                {{ t('Save & Post','حفظ وترحيل') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Form errors summary -->
                <div v-if="Object.keys(form.errors).length > 0" class="cv-alert cv-alert-danger" style="margin-top:0">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <div style="font-weight:600;margin-bottom:0.25rem">{{ t('Please fix the following errors:','يرجى مراجعة الأخطاء التالية:') }}</div>
                        <ul style="margin:0;padding-inline-start:1rem">
                            <li v-for="(err, field) in form.errors" :key="field" style="font-size:0.8rem">{{ err }}</li>
                        </ul>
                    </div>
                </div>

            </div><!-- /cv-page -->

            <!-- ══ POST CONFIRM MODAL ══════════════════════════════ -->
            <Transition name="cv-modal-fade">
                <div v-if="showPostModal" class="cv-modal-backdrop" @click.self="showPostModal = false">
                    <div class="cv-modal cv-modal-sm" role="dialog" :dir="isRtl ? 'rtl' : 'ltr'">
                        <div class="cv-modal-header">
                            <div style="display:flex;align-items:center;gap:0.625rem">
                                <div style="width:2rem;height:2rem;border-radius:50%;background:var(--cv-warning-bg);border:1px solid var(--cv-warning-border);display:flex;align-items:center;justify-content:center">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--cv-warning-text)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                </div>
                                <span style="font-weight:700;font-size:0.9rem">{{ t('Confirm Posting','تأكيد الترحيل') }}</span>
                            </div>
                        </div>
                        <div class="cv-modal-body">
                            <p style="color:var(--cv-text-secondary);font-size:0.875rem;line-height:1.6;margin:0">
                                {{ t('Once posted, this opening balance entry cannot be edited. Any correction will require a reversal entry. Do you want to proceed?','بمجرد الترحيل، لن تتمكن من تعديل قيد الأرصدة الافتتاحية. لعمل أي تصحيح ستحتاج إلى عمل قيد عكسي. هل تريد المتابعة؟') }}
                            </p>
                            <div style="margin-top:1rem;padding:0.875rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius-sm);border:1px solid var(--cv-border)">
                                <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:0.25rem">
                                    <span style="color:var(--cv-text-muted)">{{ t('Total Debit','إجمالي المدين') }}</span>
                                    <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(totalDebits + (equityPlug < 0 ? Math.abs(equityPlug) : 0)) }} {{ t('EGP','ج.م') }}</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:0.8rem">
                                    <span style="color:var(--cv-text-muted)">{{ t('Total Credit','إجمالي الدائن') }}</span>
                                    <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(totalCredits + (equityPlug > 0 ? equityPlug : 0)) }} {{ t('EGP','ج.م') }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button type="button" class="cv-btn cv-btn-ghost" @click="showPostModal = false">{{ t('Cancel','إلغاء') }}</button>
                            <button type="button" class="cv-btn cv-btn-amber" @click="confirmPost">{{ t('Yes, Post Now','نعم، ترحيل الآن') }}</button>
                        </div>
                    </div>
                </div>
            </Transition>

        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Section badges ─────────────────────────────────────────────── */
.ob-badge {
    width: 2rem; height: 2rem; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.875rem; font-weight: 800; color: #000; flex-shrink: 0;
    background: linear-gradient(135deg, var(--cv-amber), #f59e0b);
}
.ob-badge-blue   { background: linear-gradient(135deg, var(--cv-blue-text), #3b82f6); color: #fff; }
.ob-badge-red    { background: linear-gradient(135deg, var(--cv-danger-text), #ef4444); color: #fff; }
.ob-badge-green  { background: linear-gradient(135deg, var(--cv-green-bright), #22c55e); color: #000; }
.ob-badge-muted  { background: var(--cv-bg-card-alt); border: 1px solid var(--cv-border); color: var(--cv-text-muted); }

.ob-section-title { font-size: 0.9rem; font-weight: 700; color: var(--cv-text-primary); }
.ob-section-sub   { font-size: 0.72rem; color: var(--cv-text-muted); margin-top: 0.1rem; }

/* ── Account rows ────────────────────────────────────────────────── */
.ob-account-row {
    border: 1px solid var(--cv-border);
    border-radius: var(--cv-radius-sm);
    margin-bottom: 0.5rem;
    transition: var(--cv-transition);
    overflow: hidden;
}
.ob-account-row:hover { border-color: var(--cv-border-md); }
.ob-account-row-expanded { border-color: var(--cv-info-border); }

.ob-main-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 0.875rem;
    flex-wrap: wrap;
}

.ob-code {
    font-family: monospace;
    font-size: 0.75rem;
    color: var(--cv-text-muted);
    min-width: 3.5rem;
    flex-shrink: 0;
}
.ob-name {
    flex: 1;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--cv-text-primary);
    min-width: 140px;
}

/* Amount input */
.ob-amount-wrap { display: flex; align-items: center; gap: 0.375rem; min-width: 120px; }
.ob-amount-input { width: 120px; font-size: 0.82rem; padding: 0.3rem 0.5rem; }
.ob-amount-derived {
    padding: 0.3rem 0.5rem;
    background: var(--cv-bg-card-alt);
    border: 1px solid var(--cv-border);
    border-radius: var(--cv-radius-sm);
    font-size: 0.82rem;
}

/* Side toggle */
.ob-side-toggle {
    display: flex;
    border: 1px solid var(--cv-border);
    border-radius: var(--cv-radius-sm);
    overflow: hidden;
    flex-shrink: 0;
}
.ob-side-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.65rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    background: transparent;
    color: var(--cv-text-muted);
    transition: var(--cv-transition);
}
.ob-side-btn-active-blue  { background: var(--cv-info-bg);    color: var(--cv-info-text); }
.ob-side-btn-active-amber { background: var(--cv-warning-bg); color: var(--cv-warning-text); }

.ob-partner-btn { flex-shrink: 0; font-size: 0.7rem; }

/* ── Sub-table ───────────────────────────────────────────────────── */
.ob-sub-table {
    border-top: 1px solid var(--cv-info-border);
    background: var(--cv-bg-card-alt);
    padding: 0.75rem 0.875rem;
}
.ob-sub-header {
    display: grid;
    grid-template-columns: 1fr 140px 36px;
    gap: 0.5rem;
    font-size: 0.65rem;
    font-weight: 700;
    color: var(--cv-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--cv-border);
    margin-bottom: 0.5rem;
}
.ob-sub-row {
    display: grid;
    grid-template-columns: 1fr 140px 36px;
    gap: 0.5rem;
    align-items: center;
    margin-bottom: 0.375rem;
}
.ob-sub-select { font-size: 0.78rem; padding: 0.3rem 0.5rem; }
.ob-sub-amount { width: 140px; font-size: 0.78rem; padding: 0.3rem 0.5rem; }
.ob-sub-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--cv-border);
}

/* ── Inventory table ─────────────────────────────────────────────── */
.ob-inv-header {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 120px 140px 120px;
    gap: 0.5rem;
    padding: 0.375rem 0.75rem;
    font-size: 0.65rem;
    font-weight: 700;
    color: var(--cv-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-bottom: 1px solid var(--cv-border);
    margin-bottom: 0.25rem;
}
.ob-inv-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 120px 140px 120px;
    gap: 0.5rem;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border-radius: var(--cv-radius-sm);
    border: 1px solid transparent;
    transition: var(--cv-transition);
}
.ob-inv-row:hover { background: var(--cv-bg-card-alt); border-color: var(--cv-border); }
.ob-inv-name { min-width: 0; }
.ob-inv-input { font-size: 0.78rem; padding: 0.3rem 0.5rem; }
.ob-inv-value {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--cv-text-muted);
    font-family: monospace;
    text-align: end;
}
.ob-inv-value-active { color: var(--cv-blue-text); }

/* ── Empty state ─────────────────────────────────────────────────── */
.ob-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 2rem 1rem;
    color: var(--cv-text-muted);
    font-size: 0.8rem;
    border: 1px dashed var(--cv-border);
    border-radius: var(--cv-radius-sm);
}
.ob-empty p { margin: 0; }

/* ── Modal transition ────────────────────────────────────────────── */
.cv-modal-fade-enter-active { transition: opacity 0.18s ease; }
.cv-modal-fade-leave-active { transition: opacity 0.12s ease; }
.cv-modal-fade-enter-from,
.cv-modal-fade-leave-to { opacity: 0; }

/* ── Responsive: collapse inventory grid on small screens ─────────── */
@media (max-width: 860px) {
    .ob-inv-header { display: none; }
    .ob-inv-row    { grid-template-columns: 1fr 1fr; }
    .ob-main-row   { gap: 0.5rem; }
}
</style>