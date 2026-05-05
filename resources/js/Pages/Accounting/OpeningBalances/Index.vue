<script setup>
/**
 * Accounting/OpeningBalances/Index.vue
 *
 * Lists all opening balance journal entries grouped by fiscal year.
 * Card grid — not a flat table.
 * Bilingual: All labels switch via useLocale(). Arabic mode → ALL Arabic.
 */

import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    entries:     { type: Array,  default: () => [] },
    fiscalYears: { type: Array,  default: () => [] },
})

// ── Locale ────────────────────────────────────────────────────────
const { locale: lang, isRtl } = useLocale()

// ── Helpers ───────────────────────────────────────────────────────
const fmt = (v) => new Intl.NumberFormat(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
}).format(v || 0)

const fmtDate = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString(lang.value === 'ar' ? 'ar-EG' : 'en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    })
}

const statusBadge = (status) => {
    const map = {
        draft:    'cv-badge-amber',
        posted:   'cv-badge-green',
        reversed: 'cv-badge-neutral',
    }
    return map[status] ?? 'cv-badge-neutral'
}

const statusLabel = (status) => {
    if (lang.value === 'ar') {
        const map = { draft: 'مسودة', posted: 'مرحّل', reversed: 'معكوس' }
        return map[status] ?? status
    }
    const map = { draft: 'Draft', posted: 'Posted', reversed: 'Reversed' }
    return map[status] ?? status
}

const stripeBadge = (status) => {
    const map = { draft: 'cv-card-stripe-amber', posted: 'cv-card-stripe-green', reversed: 'cv-card-stripe-muted' }
    return map[status] ?? 'cv-card-stripe-muted'
}

// ── Can create — only if there is an open FY with no existing entry ──
const openFysWithNoEntry = computed(() => {
    const existingFyIds = new Set(props.entries.map(e => e.fiscal_year?.id))
    return props.fiscalYears.filter(fy => fy.status === 'open' && !existingFyIds.has(fy.id))
})

// ── Post action ───────────────────────────────────────────────────
const postingId = ref(null)
const postEntry = (id) => {
    postingId.value = id
    router.post(route('opening-balances.post', id), {}, {
        onFinish: () => { postingId.value = null },
    })
}

// ── Stats ─────────────────────────────────────────────────────────
const totalPosted = computed(() => props.entries.filter(e => e.status === 'posted').length)
const totalDraft  = computed(() => props.entries.filter(e => e.status === 'draft').length)
</script>

<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ HERO ══════════════════════════════════════════════ -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">
                    {{ lang === 'ar' ? 'المحاسبة' : 'Accounting' }}
                </div>
                <div class="cv-page-hero-title">
                    {{ lang === 'ar' ? 'الأرصدة الافتتاحية' : 'Opening Balances' }}
                </div>
                <div class="cv-page-hero-sub">
                    {{ lang === 'ar'
                        ? 'قيد واحد لكل سنة مالية — يُدخل مرة واحدة عند بدء تشغيل النظام'
                        : 'One entry per fiscal year — entered once when starting the system' }}
                </div>
                <div class="cv-page-hero-actions">
                    <!-- Always visible — controller handles duplicate/posted guards -->
                    <a
                        :href="route('opening-balances.create', openFysWithNoEntry.length === 1 ? { fiscal_year_id: openFysWithNoEntry[0].id } : {})"
                        class="cv-btn cv-btn-amber"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        {{ lang === 'ar' ? 'إدخال أرصدة افتتاحية' : 'Enter Opening Balances' }}
                    </a>
                </div>
            </div>

            <div class="cv-page" style="display:flex;flex-direction:column;gap:1.5rem;padding-bottom:3rem">

                <!-- ══ KPI STRIP ══════════════════════════════════════ -->
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem">
                    <div class="cv-stat-card cv-stat-card-blue">
                        <div class="cv-stat-card-label">{{ lang === 'ar' ? 'إجمالي القيود' : 'Total Entries' }}</div>
                        <div class="cv-stat-card-value">{{ entries.length }}</div>
                    </div>
                    <div class="cv-stat-card cv-stat-card-green">
                        <div class="cv-stat-card-label">{{ lang === 'ar' ? 'مرحّل' : 'Posted' }}</div>
                        <div class="cv-stat-card-value">{{ totalPosted }}</div>
                    </div>
                    <div class="cv-stat-card cv-stat-card-amber">
                        <div class="cv-stat-card-label">{{ lang === 'ar' ? 'مسودة' : 'Draft' }}</div>
                        <div class="cv-stat-card-value">{{ totalDraft }}</div>
                    </div>
                    <div class="cv-stat-card cv-stat-card-amber">
                        <div class="cv-stat-card-label">{{ lang === 'ar' ? 'سنوات مالية مفتوحة بدون رصيد' : 'Open FYs Without Entry' }}</div>
                        <div class="cv-stat-card-value" :style="`color:${openFysWithNoEntry.length > 0 ? 'var(--cv-warning-text)' : 'var(--cv-success-text)'}`">
                            {{ openFysWithNoEntry.length }}
                        </div>
                    </div>
                </div>

                <!-- ══ EMPTY STATE ════════════════════════════════════ -->
                <div v-if="entries.length === 0" class="cv-card" style="padding:3rem;display:flex;flex-direction:column;align-items:center;gap:1rem;text-align:center">
                    <div style="width:3.5rem;height:3.5rem;border-radius:50%;background:var(--cv-bg-card-alt);border:1px solid var(--cv-border);display:flex;align-items:center;justify-content:center">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cv-amber)">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10 9 9 9 8 9"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size:0.9rem;font-weight:700;color:var(--cv-text-primary);margin-bottom:0.375rem">
                            {{ lang === 'ar' ? 'لا توجد أرصدة افتتاحية بعد' : 'No Opening Balances Yet' }}
                        </div>
                        <div style="font-size:0.8rem;color:var(--cv-text-muted);max-width:360px">
                            {{ lang === 'ar'
                                ? 'أدخل الأرصدة الافتتاحية مرة واحدة عند الانتقال إلى نظام VERO من نظام محاسبة آخر.'
                                : 'Enter opening balances once when migrating to VERO from another accounting system.' }}
                        </div>
                    </div>
                    <a
                        :href="route('opening-balances.create')"
                        class="cv-btn cv-btn-amber"
                        style="margin-top:0.5rem"
                    >
                        {{ lang === 'ar' ? 'إدخال أرصدة افتتاحية' : 'Enter Opening Balances' }}
                    </a>
                    <p style="font-size:0.72rem;color:var(--cv-text-muted);margin:0">
                        {{ lang === 'ar'
                            ? 'تأكد من وجود سنة مالية مفتوحة أولاً'
                            : 'Make sure you have an open fiscal year first' }}
                    </p>
                </div>

                <!-- ══ CARD GRID ═════════════════════════════════════ -->
                <div
                    v-if="entries.length > 0"
                    style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.25rem"
                >
                    <div
                        v-for="entry in entries"
                        :key="entry.id"
                        class="cv-card"
                        :class="stripeBadge(entry.status)"
                        style="display:flex;flex-direction:column;gap:0"
                    >
                        <!-- Card header -->
                        <div style="padding:1.125rem 1.25rem 0.875rem;border-bottom:1px solid var(--cv-border);display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem">
                            <div style="display:flex;align-items:center;gap:0.75rem;flex:1;min-width:0">
                                <!-- Avatar -->
                                <div class="ob-avatar">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <line x1="16" y1="13" x2="8" y2="13"/>
                                        <line x1="16" y1="17" x2="8" y2="17"/>
                                    </svg>
                                </div>
                                <div style="min-width:0">
                                    <div style="font-size:0.9rem;font-weight:700;color:var(--cv-text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                        {{ entry.reference ?? (lang === 'ar' ? 'رصيد افتتاحي' : 'Opening Balance') }}
                                    </div>
                                    <div style="font-size:0.72rem;color:var(--cv-text-muted);margin-top:0.1rem">
                                        {{ lang === 'ar' ? 'السنة المالية' : 'FY' }}
                                        <span style="color:var(--cv-amber);font-weight:600;margin-inline-start:4px">
                                            {{ entry.fiscal_year?.year ?? '—' }}
                                        </span>
                                        <span
                                            class="cv-badge"
                                            :class="entry.fiscal_year?.status === 'open' ? 'cv-badge-green' : 'cv-badge-neutral'"
                                            style="margin-inline-start:6px"
                                        >
                                            {{ entry.fiscal_year?.status === 'open'
                                                ? (lang === 'ar' ? 'مفتوحة' : 'Open')
                                                : (entry.fiscal_year?.status ?? '—') }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <span class="cv-badge" :class="statusBadge(entry.status)">
                                {{ statusLabel(entry.status) }}
                            </span>
                        </div>

                        <!-- Card body — key figures -->
                        <div style="padding:0.875rem 1.25rem;display:flex;flex-direction:column;gap:0.5rem;flex:1">

                            <!-- Date -->
                            <div class="ob-row">
                                <span class="ob-label">{{ lang === 'ar' ? 'تاريخ الرصيد' : 'As-of Date' }}</span>
                                <span class="ob-value">{{ fmtDate(entry.date) }}</span>
                            </div>

                            <!-- Total debit / credit -->
                            <div class="ob-row">
                                <span class="ob-label">{{ lang === 'ar' ? 'إجمالي المدين' : 'Total Debit' }}</span>
                                <span class="ob-value" style="color:var(--cv-blue-text)">{{ fmt(entry.total_debit) }} {{ lang === 'ar' ? 'ج.م' : 'EGP' }}</span>
                            </div>
                            <div class="ob-row">
                                <span class="ob-label">{{ lang === 'ar' ? 'إجمالي الدائن' : 'Total Credit' }}</span>
                                <span class="ob-value" style="color:var(--cv-blue-text)">{{ fmt(entry.total_credit) }} {{ lang === 'ar' ? 'ج.م' : 'EGP' }}</span>
                            </div>

                            <!-- Balance check -->
                            <div class="ob-row">
                                <span class="ob-label">{{ lang === 'ar' ? 'التوازن' : 'Balance' }}</span>
                                <span
                                    class="cv-badge"
                                    :class="Math.abs(entry.total_debit - entry.total_credit) <= 0.005 ? 'cv-badge-green' : 'cv-badge-danger'"
                                >
                                    {{ Math.abs(entry.total_debit - entry.total_credit) <= 0.005
                                        ? (lang === 'ar' ? '✓ متوازن' : '✓ Balanced')
                                        : (lang === 'ar' ? '✗ غير متوازن' : '✗ Imbalanced') }}
                                </span>
                            </div>

                            <!-- Lines count -->
                            <div class="ob-row">
                                <span class="ob-label">{{ lang === 'ar' ? 'عدد السطور' : 'Lines' }}</span>
                                <span class="ob-value">{{ entry.lines_count ?? 0 }}</span>
                            </div>

                            <!-- Posted at -->
                            <div v-if="entry.posted_at" class="ob-row">
                                <span class="ob-label">{{ lang === 'ar' ? 'تاريخ الترحيل' : 'Posted At' }}</span>
                                <span class="ob-value">{{ fmtDate(entry.posted_at) }}</span>
                            </div>
                        </div>

                        <!-- Card footer — actions -->
                        <div style="padding:0.75rem 1.25rem;border-top:1px solid var(--cv-border);display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">

                            <!-- Edit — draft only -->
                            <a
                                v-if="entry.status === 'draft'"
                                :href="route('opening-balances.edit', entry.id)"
                                class="cv-btn cv-btn-secondary cv-btn-sm"
                            >
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                {{ lang === 'ar' ? 'تعديل' : 'Edit' }}
                            </a>

                            <!-- Post — draft only -->
                            <button
                                v-if="entry.status === 'draft'"
                                type="button"
                                class="cv-btn cv-btn-amber cv-btn-sm"
                                :disabled="postingId === entry.id"
                                @click="postEntry(entry.id)"
                            >
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                {{ postingId === entry.id
                                    ? (lang === 'ar' ? 'جارٍ الترحيل...' : 'Posting...')
                                    : (lang === 'ar' ? 'ترحيل' : 'Post') }}
                            </button>

                            <!-- Posted label -->
                            <div v-if="entry.status === 'posted'" style="display:flex;align-items:center;gap:0.375rem;font-size:0.72rem;color:var(--cv-success-text)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                {{ lang === 'ar' ? 'تم الترحيل — لا يمكن التعديل' : 'Posted — read-only' }}
                            </div>

                            <!-- FY link -->
                            <a
                                :href="route('fiscal-years.index')"
                                class="cv-btn cv-btn-ghost cv-btn-sm"
                                style="margin-inline-start:auto"
                            >
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                {{ lang === 'ar' ? 'السنة المالية' : 'Fiscal Year' }}
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ══ OPEN FYS WITHOUT ENTRY — PROMPT CARDS ═════════ -->
                <template v-if="openFysWithNoEntry.length > 0">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.5rem">
                        <div style="flex:1;height:1px;background:var(--cv-border)"></div>
                        <span style="font-size:0.7rem;color:var(--cv-text-muted);white-space:nowrap">
                            {{ lang === 'ar' ? 'سنوات مالية بدون أرصدة افتتاحية' : 'Fiscal years without opening balances' }}
                        </span>
                        <div style="flex:1;height:1px;background:var(--cv-border)"></div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem">
                        <div
                            v-for="fy in openFysWithNoEntry"
                            :key="fy.id"
                            class="cv-card cv-card-stripe-amber"
                            style="padding:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap"
                        >
                            <div>
                                <div style="font-size:0.875rem;font-weight:700;color:var(--cv-text-primary)">
                                    {{ lang === 'ar' ? 'السنة المالية' : 'Fiscal Year' }}
                                    <span style="color:var(--cv-amber);margin-inline-start:4px">{{ fy.year }}</span>
                                </div>
                                <div style="font-size:0.72rem;color:var(--cv-text-muted);margin-top:0.2rem">
                                    {{ fy.start_date }} → {{ fy.end_date }}
                                </div>
                                <div style="font-size:0.7rem;color:var(--cv-warning-text);margin-top:0.25rem">
                                    {{ lang === 'ar' ? '⚠ لم يُدخل الرصيد الافتتاحي بعد' : '⚠ Opening balance not entered yet' }}
                                </div>
                            </div>
                            <a
                                :href="route('opening-balances.create', { fiscal_year_id: fy.id })"
                                class="cv-btn cv-btn-amber cv-btn-sm"
                                style="white-space:nowrap"
                            >
                                {{ lang === 'ar' ? 'إدخال الآن' : 'Enter Now' }}
                            </a>
                        </div>
                    </div>
                </template>

            </div><!-- /cv-page -->
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Stat card overrides ─────────────────────────────────────────── */
.cv-stat-card {
    padding: 0.875rem 1rem;
    border-radius: var(--cv-radius);
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    position: relative;
    overflow: hidden;
}
.cv-stat-card::before {
    content: '';
    position: absolute;
    top: 0; inset-inline-start: 0; inset-inline-end: 0;
    height: 3px;
    border-radius: var(--cv-radius) var(--cv-radius) 0 0;
}
.cv-stat-card-blue::before   { background: var(--cv-blue-text); }
.cv-stat-card-green::before  { background: var(--cv-green-bright); }
.cv-stat-card-amber::before  { background: var(--cv-amber); }

.cv-stat-card-label {
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--cv-text-muted);
    margin-bottom: 0.375rem;
}
.cv-stat-card-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-blue-text);
}
.cv-stat-card-blue .cv-stat-card-value   { color: var(--cv-blue-text); }
.cv-stat-card-green .cv-stat-card-value  { color: var(--cv-green-bright); }
.cv-stat-card-amber .cv-stat-card-value  { color: var(--cv-amber); }

/* ── Avatar ──────────────────────────────────────────────────────── */
.ob-avatar {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: var(--cv-radius-sm);
    background: linear-gradient(135deg, rgba(232,160,48,0.18), rgba(232,160,48,0.06));
    border: 1px solid rgba(232,160,48,0.22);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--cv-amber);
    flex-shrink: 0;
}

/* ── Row label / value pairs ─────────────────────────────────────── */
.ob-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}
.ob-label {
    font-size: 0.72rem;
    color: var(--cv-text-muted);
    white-space: nowrap;
}
.ob-value {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--cv-text-secondary);
    text-align: end;
}
</style>