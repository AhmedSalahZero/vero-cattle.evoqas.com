<template>
    <AuthenticatedLayout>
        <template #header>
            <span class="cv-header-breadcrumb-current">
                {{ lang === 'ar' ? 'الميزانية التقديرية' : 'Budget' }}
            </span>
        </template>

        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ══ HERO ══════════════════════════════════════════════════ -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">
                    {{ lang === 'ar' ? 'المحاسبة' : 'Accounting' }}
                </div>
                <div class="cv-page-hero-title">
                    {{ lang === 'ar' ? 'الميزانية التقديرية' : 'Budget Planner' }}
                </div>
                <div class="cv-page-hero-sub">
                    <template v-if="fiscalYear">
                        {{ lang === 'ar' ? 'السنة المالية' : 'Fiscal Year' }}
                        {{ fiscalYear.year }} ·
                        <span :class="fyStatusClass">{{ fyStatusLabel }}</span>
                    </template>
                    <template v-else>
                        {{ lang === 'ar' ? 'اختر سنة مالية للبدء' : 'Select a fiscal year to begin' }}
                    </template>
                </div>
                <div class="cv-page-hero-actions">
                    <!-- FY Switcher -->
                    <select
                        class="cv-select"
                        style="min-width:160px"
                        :value="filters.fiscal_year_id"
                        @change="switchFiscalYear($event.target.value)"
                    >
                        <option value="">
                            {{ lang === 'ar' ? '— اختر سنة —' : '— Select Year —' }}
                        </option>
                        <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">
                            {{ fy.year }} ({{ fy.status }})
                        </option>
                    </select>

                    <!-- Copy from prior year -->
                    <button
                        v-if="canEdit"
                        class="cv-btn cv-btn-ghost"
                        @click="openCopyModal"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        {{ lang === 'ar' ? 'نسخ من السنة السابقة' : 'Copy from Prior Year' }}
                    </button>
                </div>
            </div>

            <div class="cv-page">

                <!-- ══ READ-ONLY BANNER (closed / locked FY) ══════════════ -->
                <div
                    v-if="fiscalYear && fiscalYear.status !== 'open'"
                    class="cv-alert"
                    style="background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:10px;padding:12px 18px;display:flex;align-items:center;gap:10px;margin-bottom:20px;"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <path d="M12 8v4M12 16h.01"/>
                    </svg>
                    <span style="color:var(--cv-amber-text);font-size:.875rem">
                        {{ fiscalYear.status === 'locked'
                            ? (lang === 'ar' ? 'السنة المالية مقفلة — القراءة فقط' : 'Fiscal year is locked — read-only view')
                            : (lang === 'ar' ? 'السنة المالية مغلقة — لا يمكن التعديل' : 'Fiscal year is closed — editing disabled')
                        }}
                    </span>
                </div>

             <!-- AFTER — P&L-correct 5-card strip per Methodology #44 BudgetPnlSummaryTotals -->
                <div class="budget-kpi-strip" v-if="fiscalYear">
                    <div class="budget-kpi-card">
                        <div class="budget-kpi-label">{{ lang === 'ar' ? 'ميزانية الإيرادات' : 'Revenue Budget' }}</div>
                        <div class="budget-kpi-value blue">{{ fmt(revenueBudget) }}</div>
                        <div class="budget-kpi-sub">{{ lang === 'ar' ? 'فعلي' : 'Actual' }}: {{ fmt(revenueActual) }}</div>
                    </div>
                    <div class="budget-kpi-card">
                        <div class="budget-kpi-label">{{ lang === 'ar' ? 'ميزانية التكاليف' : 'Cost Budget' }}</div>
                        <div class="budget-kpi-value blue">{{ fmt(costBudget) }}</div>
                        <div class="budget-kpi-sub">{{ lang === 'ar' ? 'فعلي' : 'Actual' }}: {{ fmt(costActual) }}</div>
                    </div>
                    <div class="budget-kpi-card">
                        <div class="budget-kpi-label">{{ lang === 'ar' ? 'صافي الدخل المخطط' : 'Budgeted Net Income' }}</div>
                        <div class="budget-kpi-value blue">{{ fmt(netBudget) }}</div>
                    </div>
                    <div class="budget-kpi-card">
                        <div class="budget-kpi-label">{{ lang === 'ar' ? 'صافي الدخل الفعلي' : 'Actual Net Income' }}</div>
                        <div class="budget-kpi-value" :class="netFlag === 'favourable' ? 'green' : netFlag === 'unfavourable' ? 'red' : 'blue'">
                            {{ fmt(netActual) }}
                        </div>
                    </div>
                    <div class="budget-kpi-card">
                        <div class="budget-kpi-label">{{ lang === 'ar' ? 'انحراف صافي الدخل' : 'Net Variance' }}</div>
                        <div class="budget-kpi-value" :class="netFlag === 'favourable' ? 'green' : netFlag === 'unfavourable' ? 'red' : 'blue'">
                            {{ fmt(netVariance) }}
                        </div>
                        <div class="budget-kpi-sub">
                            {{ netFlag === 'favourable' ? (lang === 'ar' ? '▲ مواتٍ' : '▲ Favourable') : netFlag === 'unfavourable' ? (lang === 'ar' ? '▼ غير مواتٍ' : '▼ Unfavourable') : '—' }}
                        </div>
                    </div>
                </div>

                <!-- ══ TOOLBAR ════════════════════════════════════════════ -->
                <div class="budget-toolbar" v-if="grid.length">
                    <div class="budget-tab-group">
                        <button
                            v-for="g in typeGroups"
                            :key="g.key"
                            class="budget-tab"
                            :class="{ active: activeGroup === g.key }"
                            @click="activeGroup = g.key"
                        >{{ lang === 'ar' ? g.ar : g.en }}</button>
                    </div>
                    <div style="position:relative;flex:1;max-width:280px">
                        <svg style="position:absolute;top:50%;transform:translateY(-50%);inset-inline-start:10px;pointer-events:none" viewBox="0 0 24 24" fill="none" stroke="var(--cv-text-muted)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="15" height="15">
                            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                        </svg>
                        <input
                            v-model="search"
                            class="cv-input"
                            :placeholder="lang === 'ar' ? 'بحث بالاسم أو الكود…' : 'Search by name or code…'"
                            style="padding-inline-start:34px"
                        />
                    </div>
                </div>

                <!-- ══ EMPTY STATE ════════════════════════════════════════ -->
                <div v-if="!fiscalYear" class="budget-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-text-muted)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" width="52" height="52">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <path d="M8 21h8M12 17v4"/>
                        <path d="M7 8h10M7 11h6"/>
                    </svg>
                    <div class="budget-empty-title">{{ lang === 'ar' ? 'اختر سنة مالية' : 'Select a Fiscal Year' }}</div>
                    <div class="budget-empty-sub">{{ lang === 'ar' ? 'اختر سنة مالية من القائمة أعلاه لعرض الميزانية' : 'Choose a fiscal year above to view and edit the budget' }}</div>
                </div>

                <div v-else-if="filteredGrid.length === 0" class="budget-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-text-muted)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" width="52" height="52">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <div class="budget-empty-title">{{ lang === 'ar' ? 'لا توجد نتائج' : 'No accounts found' }}</div>
                    <div class="budget-empty-sub">{{ lang === 'ar' ? 'جرب تغيير البحث أو الفلتر' : 'Try adjusting the search or filter' }}</div>
                </div>

                <!-- ══ SPREADSHEET GRID ═══════════════════════════════════ -->
                <template v-else>

                    <!-- Group: Revenue -->
                    <template v-if="showGroup('revenue') && revenueRows.length">
                        <div class="budget-group-header">
                            <span class="budget-group-badge revenue">
                                {{ lang === 'ar' ? 'إيرادات' : 'Revenue' }}
                            </span>
                        </div>
                        <div class="budget-grid-wrap">
                            <table class="budget-table">
                                <thead>
                                    <tr>
                                        <th class="budget-th-account sticky-col">{{ lang === 'ar' ? 'الحساب' : 'Account' }}</th>
                                        <th v-for="(m, idx) in monthHeaders" :key="idx" class="budget-th-month">{{ m }}</th>
                                        <th class="budget-th-total">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                        <th v-if="canEdit || canDelete" class="budget-th-action"></th>
                                    </tr>
                                    <tr class="budget-sub-header">
                                        <td class="sticky-col"></td>
                                        <td v-for="(_, idx) in 12" :key="idx" class="budget-sub-cell">
                                            <span class="sub-b">{{ lang === 'ar' ? 'مخطط' : 'Bdgt' }}</span>
                                            <span class="sub-a">{{ lang === 'ar' ? 'فعلي' : 'Actl' }}</span>
                                            <span class="sub-v">{{ lang === 'ar' ? 'فرق' : 'Var' }}</span>
                                        </td>
                                        <td class="budget-sub-cell">
                                            <span class="sub-b">{{ lang === 'ar' ? 'مخطط' : 'Bdgt' }}</span>
                                            <span class="sub-a">{{ lang === 'ar' ? 'فعلي' : 'Actl' }}</span>
                                            <span class="sub-v">{{ lang === 'ar' ? 'فرق' : 'Var' }}</span>
                                        </td>
                                        <td v-if="canEdit || canDelete"></td>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in revenueRows" :key="row.account_id"
                                        class="budget-data-row"
                                        :class="{ 'budget-row-editing': editingId === row.account_id }">
                                        <td class="budget-td-account sticky-col">
                                            <div class="budget-account-info">
                                                <span class="budget-account-code">{{ row.account_code }}</span>
                                                <span class="budget-account-name">{{ accountName(row) }}</span>
                                            </div>
                                        </td>
                                        <td v-for="m in 12" :key="m" class="budget-data-cell"
                                            @click="editingId !== row.account_id && startEdit(row)"
                                            :title="editingId !== row.account_id && canEdit ? (lang === 'ar' ? 'انقر للتعديل' : 'Click to edit') : ''">
                                            <template v-if="editingId === row.account_id">
                                                <input type="number" v-model="editDraft[m]" class="budget-cell-input"
                                                    min="0" step="1"
                                                    @keyup.enter="saveRow(row)" @keyup.escape="cancelEdit" />
                                            </template>
                                            <template v-else>
                                                <div class="cell-b">{{ fmt(row.months?.[m]?.budget ?? 0) }}</div>
                                                <div class="cell-a">{{ fmt(row.months?.[m]?.actual ?? 0) }}</div>
                                                <div class="cell-v" :class="rowVarianceClass(row.months?.[m]?.variance ?? 0, row.account_type)">
                                                    {{ fmt(row.months?.[m]?.variance ?? 0) }}
                                                </div>
                                            </template>
                                        </td>
                                        <td class="budget-total-cell budget-data-cell">
                                            <div class="cell-b">{{ fmt(row.total_budget) }}</div>
                                            <div class="cell-a">{{ fmt(row.total_actual) }}</div>
                                            <div class="cell-v" :class="rowVarianceClass(row.total_variance, row.account_type)">
                                                {{ fmt(row.total_variance) }}
                                            </div>
                                        </td>
                                        <td v-if="canEdit || canDelete" class="budget-td-actions">
                                            <div class="budget-row-actions">
                                                <template v-if="editingId === row.account_id">
                                                    <button class="cv-btn cv-btn-ghost" style="padding:4px 10px;font-size:.78rem" @click.stop="cancelEdit">
                                                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                                                    </button>
                                                    <button class="cv-btn cv-btn-amber" style="padding:4px 10px;font-size:.78rem" @click.stop="saveRow(row)">
                                                        {{ lang === 'ar' ? 'حفظ' : 'Save' }}
                                                    </button>
                                                </template>
                                                <template v-else>
                                                    <button v-if="canEdit" class="budget-icon-btn edit"
                                                        :title="lang === 'ar' ? 'تعديل' : 'Edit row'" @click.stop="startEdit(row)">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                        </svg>
                                                    </button>
                                                    <button v-if="canDelete" class="budget-icon-btn danger"
                                                        :title="lang === 'ar' ? 'مسح الميزانية' : 'Clear budget'" @click.stop="openClearConfirm(row)">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                                                            <polyline points="3 6 5 6 21 6"/>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                            <path d="M10 11v6M14 11v6"/>
                                                        </svg>
                                                    </button>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="budget-totals-row">
                                        <td class="sticky-col budget-total-label">
                                            {{ lang === 'ar' ? 'إجمالي الإيرادات' : 'Revenue Total' }}
                                        </td>
                                        <td v-for="m in 12" :key="m" class="budget-total-cell">
                                            <div class="cell-b">{{ fmt(groupMonthSum(revenueRows, m, 'budget')) }}</div>
                                            <div class="cell-a">{{ fmt(groupMonthSum(revenueRows, m, 'actual')) }}</div>
                                            <div class="cell-v" :class="groupVarianceClass(revenueRows, m, 'revenue')">
                                                {{ fmt(groupMonthSum(revenueRows, m, 'variance')) }}
                                            </div>
                                        </td>
                                        <td class="budget-total-cell">
                                            <div class="cell-b">{{ fmt(groupSum(revenueRows, 'total_budget')) }}</div>
                                            <div class="cell-a">{{ fmt(groupSum(revenueRows, 'total_actual')) }}</div>
                                            <div class="cell-v" :class="totalVarianceClass(groupSum(revenueRows,'total_variance'), 'revenue')">
                                                {{ fmt(groupSum(revenueRows, 'total_variance')) }}
                                            </div>
                                        </td>
                                        <td v-if="canEdit || canDelete"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>

                    <!-- Group: COGS -->
                    <template v-if="showGroup('cogs') && cogsRows.length">
                        <div class="budget-group-header">
                            <span class="budget-group-badge cogs">
                                {{ lang === 'ar' ? 'تكلفة البضاعة المباعة' : 'Cost of Goods Sold' }}
                            </span>
                        </div>
                        <div class="budget-grid-wrap">
                            <table class="budget-table">
                                <thead>
                                    <tr>
                                        <th class="budget-th-account sticky-col">{{ lang === 'ar' ? 'الحساب' : 'Account' }}</th>
                                        <th v-for="(m, idx) in monthHeaders" :key="idx" class="budget-th-month">{{ m }}</th>
                                        <th class="budget-th-total">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                        <th v-if="canEdit || canDelete" class="budget-th-action"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in cogsRows" :key="row.account_id"
                                        class="budget-data-row"
                                        :class="{ 'budget-row-editing': editingId === row.account_id }">
                                        <td class="budget-td-account sticky-col">
                                            <div class="budget-account-info">
                                                <span class="budget-account-code">{{ row.account_code }}</span>
                                                <span class="budget-account-name">{{ accountName(row) }}</span>
                                            </div>
                                        </td>
                                        <td v-for="m in 12" :key="m" class="budget-data-cell"
                                            @click="editingId !== row.account_id && startEdit(row)"
                                            :title="editingId !== row.account_id && canEdit ? (lang === 'ar' ? 'انقر للتعديل' : 'Click to edit') : ''">
                                            <template v-if="editingId === row.account_id">
                                                <input type="number" v-model="editDraft[m]" class="budget-cell-input"
                                                    min="0" step="1"
                                                    @keyup.enter="saveRow(row)" @keyup.escape="cancelEdit" />
                                            </template>
                                            <template v-else>
                                                <div class="cell-b">{{ fmt(row.months?.[m]?.budget ?? 0) }}</div>
                                                <div class="cell-a">{{ fmt(row.months?.[m]?.actual ?? 0) }}</div>
                                                <div class="cell-v" :class="rowVarianceClass(row.months?.[m]?.variance ?? 0, row.account_type)">
                                                    {{ fmt(row.months?.[m]?.variance ?? 0) }}
                                                </div>
                                            </template>
                                        </td>
                                        <td class="budget-total-cell budget-data-cell">
                                            <div class="cell-b">{{ fmt(row.total_budget) }}</div>
                                            <div class="cell-a">{{ fmt(row.total_actual) }}</div>
                                            <div class="cell-v" :class="rowVarianceClass(row.total_variance, row.account_type)">
                                                {{ fmt(row.total_variance) }}
                                            </div>
                                        </td>
                                        <td v-if="canEdit || canDelete" class="budget-td-actions">
                                            <div class="budget-row-actions">
                                                <template v-if="editingId === row.account_id">
                                                    <button class="cv-btn cv-btn-ghost" style="padding:4px 10px;font-size:.78rem" @click.stop="cancelEdit">
                                                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                                                    </button>
                                                    <button class="cv-btn cv-btn-amber" style="padding:4px 10px;font-size:.78rem" @click.stop="saveRow(row)">
                                                        {{ lang === 'ar' ? 'حفظ' : 'Save' }}
                                                    </button>
                                                </template>
                                                <template v-else>
                                                    <button v-if="canEdit" class="budget-icon-btn edit"
                                                        :title="lang === 'ar' ? 'تعديل' : 'Edit row'" @click.stop="startEdit(row)">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                        </svg>
                                                    </button>
                                                    <button v-if="canDelete" class="budget-icon-btn danger"
                                                        :title="lang === 'ar' ? 'مسح الميزانية' : 'Clear budget'" @click.stop="openClearConfirm(row)">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                                                            <polyline points="3 6 5 6 21 6"/>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                            <path d="M10 11v6M14 11v6"/>
                                                        </svg>
                                                    </button>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="budget-totals-row">
                                        <td class="sticky-col budget-total-label">{{ lang === 'ar' ? 'إجمالي التكلفة' : 'COGS Total' }}</td>
                                        <td v-for="m in 12" :key="m" class="budget-total-cell">
                                            <div class="cell-b">{{ fmt(groupMonthSum(cogsRows, m, 'budget')) }}</div>
                                            <div class="cell-a">{{ fmt(groupMonthSum(cogsRows, m, 'actual')) }}</div>
                                            <div class="cell-v" :class="groupVarianceClass(cogsRows, m, 'cogs')">{{ fmt(groupMonthSum(cogsRows, m, 'variance')) }}</div>
                                        </td>
                                        <td class="budget-total-cell">
                                            <div class="cell-b">{{ fmt(groupSum(cogsRows, 'total_budget')) }}</div>
                                            <div class="cell-a">{{ fmt(groupSum(cogsRows, 'total_actual')) }}</div>
                                            <div class="cell-v" :class="totalVarianceClass(groupSum(cogsRows,'total_variance'), 'cogs')">{{ fmt(groupSum(cogsRows, 'total_variance')) }}</div>
                                        </td>
                                        <td v-if="canEdit || canDelete"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>

                    <!-- Group: Expense -->
                    <template v-if="showGroup('expense') && expenseRows.length">
                        <div class="budget-group-header">
                            <span class="budget-group-badge expense">
                                {{ lang === 'ar' ? 'المصروفات' : 'Expenses' }}
                            </span>
                        </div>
                        <div class="budget-grid-wrap">
                            <table class="budget-table">
                                <thead>
                                    <tr>
                                        <th class="budget-th-account sticky-col">{{ lang === 'ar' ? 'الحساب' : 'Account' }}</th>
                                        <th v-for="(m, idx) in monthHeaders" :key="idx" class="budget-th-month">{{ m }}</th>
                                        <th class="budget-th-total">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                        <th v-if="canEdit || canDelete" class="budget-th-action"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in expenseRows" :key="row.account_id"
                                        class="budget-data-row"
                                        :class="{ 'budget-row-editing': editingId === row.account_id }">
                                        <td class="budget-td-account sticky-col">
                                            <div class="budget-account-info">
                                                <span class="budget-account-code">{{ row.account_code }}</span>
                                                <span class="budget-account-name">{{ accountName(row) }}</span>
                                            </div>
                                        </td>
                                        <td v-for="m in 12" :key="m" class="budget-data-cell"
                                            @click="editingId !== row.account_id && startEdit(row)"
                                            :title="editingId !== row.account_id && canEdit ? (lang === 'ar' ? 'انقر للتعديل' : 'Click to edit') : ''">
                                            <template v-if="editingId === row.account_id">
                                                <input type="number" v-model="editDraft[m]" class="budget-cell-input"
                                                    min="0" step="1"
                                                    @keyup.enter="saveRow(row)" @keyup.escape="cancelEdit" />
                                            </template>
                                            <template v-else>
                                                <div class="cell-b">{{ fmt(row.months?.[m]?.budget ?? 0) }}</div>
                                                <div class="cell-a">{{ fmt(row.months?.[m]?.actual ?? 0) }}</div>
                                                <div class="cell-v" :class="rowVarianceClass(row.months?.[m]?.variance ?? 0, row.account_type)">
                                                    {{ fmt(row.months?.[m]?.variance ?? 0) }}
                                                </div>
                                            </template>
                                        </td>
                                        <td class="budget-total-cell budget-data-cell">
                                            <div class="cell-b">{{ fmt(row.total_budget) }}</div>
                                            <div class="cell-a">{{ fmt(row.total_actual) }}</div>
                                            <div class="cell-v" :class="rowVarianceClass(row.total_variance, row.account_type)">
                                                {{ fmt(row.total_variance) }}
                                            </div>
                                        </td>
                                        <td v-if="canEdit || canDelete" class="budget-td-actions">
                                            <div class="budget-row-actions">
                                                <template v-if="editingId === row.account_id">
                                                    <button class="cv-btn cv-btn-ghost" style="padding:4px 10px;font-size:.78rem" @click.stop="cancelEdit">
                                                        {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                                                    </button>
                                                    <button class="cv-btn cv-btn-amber" style="padding:4px 10px;font-size:.78rem" @click.stop="saveRow(row)">
                                                        {{ lang === 'ar' ? 'حفظ' : 'Save' }}
                                                    </button>
                                                </template>
                                                <template v-else>
                                                    <button v-if="canEdit" class="budget-icon-btn edit"
                                                        :title="lang === 'ar' ? 'تعديل' : 'Edit row'" @click.stop="startEdit(row)">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                        </svg>
                                                    </button>
                                                    <button v-if="canDelete" class="budget-icon-btn danger"
                                                        :title="lang === 'ar' ? 'مسح الميزانية' : 'Clear budget'" @click.stop="openClearConfirm(row)">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                                                            <polyline points="3 6 5 6 21 6"/>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                            <path d="M10 11v6M14 11v6"/>
                                                        </svg>
                                                    </button>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="budget-totals-row">
                                        <td class="sticky-col budget-total-label">{{ lang === 'ar' ? 'إجمالي المصروفات' : 'Expense Total' }}</td>
                                        <td v-for="m in 12" :key="m" class="budget-total-cell">
                                            <div class="cell-b">{{ fmt(groupMonthSum(expenseRows, m, 'budget')) }}</div>
                                            <div class="cell-a">{{ fmt(groupMonthSum(expenseRows, m, 'actual')) }}</div>
                                            <div class="cell-v" :class="groupVarianceClass(expenseRows, m, 'expense')">{{ fmt(groupMonthSum(expenseRows, m, 'variance')) }}</div>
                                        </td>
                                        <td class="budget-total-cell">
                                            <div class="cell-b">{{ fmt(groupSum(expenseRows, 'total_budget')) }}</div>
                                            <div class="cell-a">{{ fmt(groupSum(expenseRows, 'total_actual')) }}</div>
                                            <div class="cell-v" :class="totalVarianceClass(groupSum(expenseRows,'total_variance'), 'expense')">{{ fmt(groupSum(expenseRows, 'total_variance')) }}</div>
                                        </td>
                                        <td v-if="canEdit || canDelete"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>
                </template>

                <!-- ══ COPY FROM PRIOR YEAR MODAL ════════════════════════ -->
                <div v-if="copyModal" class="cv-modal-backdrop" @click.self="copyModal = false">
                    <div class="cv-modal cv-modal-md">
                        <div class="cv-modal-header">
                            <div class="cv-modal-title">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="color:var(--cv-amber)">
                                    <rect x="9" y="9" width="13" height="13" rx="2"/>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                </svg>
                                {{ lang === 'ar' ? 'نسخ من السنة السابقة' : 'Copy from Prior Year' }}
                            </div>
                            <button class="cv-modal-close" @click="copyModal = false">✕</button>
                        </div>
                        <div class="cv-modal-body">
                            <p style="color:var(--cv-text-secondary);font-size:.875rem;margin-bottom:16px">
                                {{ lang === 'ar'
                                    ? 'انسخ الأرصدة الفعلية من السنة المصدر كميزانية تقديرية للسنة الهدف مع إمكانية تطبيق نسبة نمو.'
                                    : 'Copy actuals from a source year as the budget for the target year, with an optional growth adjustment.'
                                }}
                            </p>
                            <div class="cv-field" style="margin-bottom:14px">
                                <label class="cv-label">{{ lang === 'ar' ? 'السنة المصدر' : 'Source Year (Actuals)' }}</label>
                                <select v-model="copyForm.source_fiscal_year_id" class="cv-select">
                                    <option value="">{{ lang === 'ar' ? '— اختر —' : '— Select —' }}</option>
                                    <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">{{ fy.year }}</option>
                                </select>
                                <div v-if="copyErrors.source_fiscal_year_id" class="cv-field-error">{{ copyErrors.source_fiscal_year_id }}</div>
                            </div>
                            <div class="cv-field" style="margin-bottom:14px">
                                <label class="cv-label">{{ lang === 'ar' ? 'السنة الهدف (يجب أن تكون مفتوحة)' : 'Target Year (must be open)' }}</label>
                                <select v-model="copyForm.target_fiscal_year_id" class="cv-select">
                                    <option value="">{{ lang === 'ar' ? '— اختر —' : '— Select —' }}</option>
                                    <option v-for="fy in fiscalYears.filter(f => f.status === 'open')" :key="fy.id" :value="fy.id">{{ fy.year }}</option>
                                </select>
                                <div v-if="copyErrors.target_fiscal_year_id" class="cv-field-error">{{ copyErrors.target_fiscal_year_id }}</div>
                            </div>
                            <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'نسبة النمو % (اختياري)' : 'Growth % (optional)' }}</label>
                                <input v-model="copyForm.growth_pct" type="number" step="0.1" class="cv-input" placeholder="0" />
                                <div style="font-size:.78rem;color:var(--cv-text-muted);margin-top:4px">
                                    {{ lang === 'ar' ? 'أدخل 10 للزيادة بنسبة 10٪، و-5 للانخفاض بنسبة 5٪' : 'Enter 10 for +10% growth, -5 for -5% reduction' }}
                                </div>
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="copyModal = false">
                                {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button
                                class="cv-btn cv-btn-amber"
                                :disabled="copyProcessing || !copyForm.source_fiscal_year_id || !copyForm.target_fiscal_year_id"
                                @click="submitCopy"
                            >
                                {{ copyProcessing
                                    ? (lang === 'ar' ? 'جارٍ النسخ…' : 'Copying…')
                                    : (lang === 'ar' ? 'نسخ الميزانية' : 'Copy Budget')
                                }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══ CLEAR ACCOUNT CONFIRM MODAL ═══════════════════════ -->
                <div v-if="clearTarget" class="cv-modal-backdrop" @click.self="clearTarget = null">
                    <div class="cv-modal cv-modal-sm">
                        <div class="cv-modal-header">
                            <div class="cv-modal-title" style="color:var(--cv-danger-text)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                                    <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
                                </svg>
                                {{ lang === 'ar' ? 'مسح الميزانية' : 'Clear Budget' }}
                            </div>
                            <button class="cv-modal-close" @click="clearTarget = null">✕</button>
                        </div>
                        <div class="cv-modal-body">
                            <div class="cv-alert-danger" style="border-radius:8px;padding:12px;background:var(--cv-danger-light,rgba(224,112,112,.10));border:1px solid var(--cv-danger-border,rgba(224,112,112,.22))">
                                <p style="color:var(--cv-danger-text);font-size:.875rem;margin:0">
                                    {{ lang === 'ar'
                                        ? `سيتم مسح جميع المبالغ المدرجة لحساب "${clearTarget?.account_en}" لهذه السنة المالية. لا يمكن التراجع عن هذا الإجراء.`
                                        : `All 12 monthly budget amounts for "${clearTarget?.account_en}" will be permanently deleted. This cannot be undone.`
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button class="cv-btn cv-btn-ghost" @click="clearTarget = null">{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}</button>
                            <button class="cv-btn cv-btn-danger" :disabled="clearProcessing" @click="submitClear">
                                {{ clearProcessing ? (lang === 'ar' ? 'جارٍ المسح…' : 'Clearing…') : (lang === 'ar' ? 'مسح' : 'Clear') }}
                            </button>
                        </div>
                    </div>
                </div>

            </div><!-- /cv-page -->
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
/**
 * Accounting/Budgets/Index.vue
 * Spreadsheet-style 12-month budget grid.
 * 📌 METHODOLOGY REFERENCE: BudgetVariancePercent (#3)
 *   variance_absolute = actual − budget
 *   variance_percent  = (actual − budget) / budget × 100
 *   flag: revenue → higher actual = favourable
 *         expense/cogs → lower actual = favourable
 */
import { ref, computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
    grid:               { type: Array,  default: () => [] },
    fiscalYear:         { type: Object, default: null },
    fiscalYears:        { type: Array,  default: () => [] },
    totalBudget:        { type: Number, default: 0 },   // kept — backward compat
    totalActual:        { type: Number, default: 0 },   // kept — backward compat
    revenueBudget:      { type: Number, default: 0 },
    revenueActual:      { type: Number, default: 0 },
    costBudget:         { type: Number, default: 0 },
    costActual:         { type: Number, default: 0 },
    netBudget:          { type: Number, default: 0 },
    netActual:          { type: Number, default: 0 },
    netVariance:        { type: Number, default: 0 },
    netFlag:            { type: String, default: 'neutral' },
    filters:            { type: Object, default: () => ({}) },
    companies:          { type: Array,  default: () => [] },
    selectedCompanyId:  { type: Number, default: null },
})

// ── Locale + permissions ──────────────────────────────────────────
const { locale: lang, isRtl } = useLocale()
const page         = usePage()
const authUser     = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => authUser.value?.is_super_admin ?? false)
const { can }      = usePermissions()

const canCreate = computed(() => isSuperAdmin.value || can('create budgets'))
const canDelete = computed(() => isSuperAdmin.value || can('delete budgets'))
const canEdit   = computed(() =>
    props.fiscalYear?.status === 'open' && canCreate.value
)

// ── Fiscal year helpers ───────────────────────────────────────────
const fyStatusLabel = computed(() => {
    const s = props.fiscalYear?.status
    const labels = { open: { en: 'Open', ar: 'مفتوحة' }, closed: { en: 'Closed', ar: 'مغلقة' }, locked: { en: 'Locked', ar: 'مقفلة' } }
    return lang.value === 'ar' ? labels[s]?.ar ?? s : labels[s]?.en ?? s
})
const fyStatusClass = computed(() => {
    const s = props.fiscalYear?.status
    return s === 'open' ? 'cv-badge cv-badge-success' : s === 'locked' ? 'cv-badge cv-badge-danger' : 'cv-badge cv-badge-muted'
})

// ── Switch FY via Inertia GET ─────────────────────────────────────
const switchFiscalYear = (id) => {
    router.get(route('budgets.index'), { fiscal_year_id: id }, { preserveState: true, preserveScroll: true })
}

// ── Grid filtering ────────────────────────────────────────────────
const search      = ref('')
const activeGroup = ref('all')

const typeGroups = [
    { key: 'all',     en: 'All',     ar: 'الكل' },
    { key: 'revenue', en: 'Revenue', ar: 'إيرادات' },
    { key: 'cogs',    en: 'COGS',    ar: 'تكلفة المبيعات' },
    { key: 'expense', en: 'Expenses',ar: 'مصروفات' },
]

const filteredGrid = computed(() => {
    let rows = props.grid
    if (activeGroup.value !== 'all') rows = rows.filter(r => r.account_type === activeGroup.value)
    if (search.value.trim()) {
        const q = search.value.trim().toLowerCase()
        rows = rows.filter(r =>
            r.account_code?.toLowerCase().includes(q) ||
            r.account_en?.toLowerCase().includes(q) ||
            r.account_ar?.includes(q)
        )
    }
    return rows
})

const revenueRows = computed(() => filteredGrid.value.filter(r => r.account_type === 'revenue'))
const cogsRows    = computed(() => filteredGrid.value.filter(r => r.account_type === 'cogs'))
const expenseRows = computed(() => filteredGrid.value.filter(r => r.account_type === 'expense'))

const showGroup = (type) => activeGroup.value === 'all' || activeGroup.value === type

// ── Month headers ─────────────────────────────────────────────────
const EN_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
const AR_MONTHS = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر']
const monthHeaders = computed(() => lang.value === 'ar' ? AR_MONTHS : EN_MONTHS)

// ── Number formatting ─────────────────────────────────────────────
const fmt = (val) => {
    if (val === null || val === undefined) return '—'
    const n = Number(val)
    if (isNaN(n)) return '—'
    const locale = lang.value === 'ar' ? 'ar-EG' : 'en-US'
    return new Intl.NumberFormat(locale, { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(n)
}

// ── Grand total KPI variance ──────────────────────────────────────
// 📌 METHODOLOGY REFERENCE: BudgetVariancePercent
const grandVariancePct = computed(() => {
    if (!props.totalBudget) return null
    return ((props.totalActual - props.totalBudget) / props.totalBudget) * 100
})
const grandVarianceFlag = computed(() => props.netFlag ?? 'neutral')

// ── Group aggregate helpers ───────────────────────────────────────
const groupMonthSum = (rows, month, key) =>
    rows.reduce((sum, r) => sum + (r.months?.[month]?.[key] ?? 0), 0)

const groupSum = (rows, key) =>
    rows.reduce((sum, r) => sum + (r[key] ?? 0), 0)

// 📌 METHODOLOGY REFERENCE: BudgetVariancePercent — flag for group month cell
const groupVarianceClass = (rows, month, accountType) => {
    const v = groupMonthSum(rows, month, 'variance')
    if (v === 0) return 'cell-v-neutral'
    const fav = accountType === 'revenue' ? v > 0 : v < 0
    return fav ? 'cell-v-fav' : 'cell-v-unfav'
}
const totalVarianceClass = (v, accountType) => {
    if (v === 0) return 'cell-v-neutral'
    const fav = accountType === 'revenue' ? v > 0 : v < 0
    return fav ? 'cell-v-fav' : 'cell-v-unfav'
}

// ── Row editing state ─────────────────────────────────────────────
// Replaces the Options API BudgetRow sub-component which used a string
// template — string templates do not work in Vite builds without the
// Vue runtime compiler included.
const editingId = ref(null)   // account_id of the row being edited
const editDraft = ref({})     // { 1: amount, ..., 12: amount }

// 📌 METHODOLOGY REFERENCE: BudgetVariancePercent
const rowVarianceClass = (variance, accountType) => {
    if (!variance || variance === 0) return 'cell-v-neutral'
    const fav = accountType === 'revenue' ? variance > 0 : variance < 0
    return fav ? 'cell-v-fav' : 'cell-v-unfav'
}

const accountName = (row) => lang.value === 'ar'
    ? (row.account_ar || row.account_en)
    : (row.account_en || row.account_ar)

const startEdit = (row) => {
    if (!canEdit.value) return
    const draft = {}
    for (let m = 1; m <= 12; m++) draft[m] = row.months?.[m]?.budget ?? 0
    editDraft.value = draft
    editingId.value = row.account_id
}

const cancelEdit = () => { editingId.value = null; editDraft.value = {} }

const saveRow = (row) => {
    const months = Object.entries(editDraft.value).map(([month, amount]) => ({
        month: parseInt(month), amount: parseFloat(amount) || 0,
    }))
    handleRowSave({ accountId: row.account_id, months })
    editingId.value = null
}

// ── Row save (bulkUpsert) ─────────────────────────────────────────
const handleRowSave = ({ accountId, months }) => {
    router.post(route('budgets.bulk-upsert'), {
        fiscal_year_id: props.fiscalYear.id,
        account_id:     accountId,
        months,
        company_id:     props.selectedCompanyId,
    }, { preserveScroll: true })
}

// ── Clear account ─────────────────────────────────────────────────
const clearTarget     = ref(null)
const clearProcessing = ref(false)
const openClearConfirm = (row) => { clearTarget.value = row }
const submitClear = () => {
    clearProcessing.value = true
    router.delete(route('budgets.destroy-by-account'), {
        data: {
            fiscal_year_id: props.fiscalYear.id,
            account_id:     clearTarget.value.account_id,
            company_id:     props.selectedCompanyId,
        },
        preserveScroll: true,
        onFinish: () => { clearProcessing.value = false; clearTarget.value = null },
    })
}

// ── Copy from prior year ──────────────────────────────────────────
const copyModal      = ref(false)
const copyProcessing = ref(false)
const copyErrors     = ref({})
const copyForm       = ref({ source_fiscal_year_id: '', target_fiscal_year_id: props.fiscalYear?.id ?? '', growth_pct: '' })

const openCopyModal = () => {
    copyForm.value = { source_fiscal_year_id: '', target_fiscal_year_id: props.fiscalYear?.id ?? '', growth_pct: '' }
    copyErrors.value = {}
    copyModal.value = true
}
const submitCopy = () => {
    copyProcessing.value = true
    router.post(route('budgets.copy-from-actuals'), {
        ...copyForm.value,
        company_id: props.selectedCompanyId,
    }, {
        preserveScroll: true,
        onSuccess: () => { copyModal.value = false },
        onError:   (e) => { copyErrors.value = e },
        onFinish:  () => { copyProcessing.value = false },
    })
}
</script>



<style>
/* ══════════════════════════════════════════════════════════════════
   Budget Grid — scoped to classes that don't exist in app.css
   Only new elements — no overrides of global cv- classes.
═══════════════════════════════════════════════════════════════════ */

/* ── KPI Strip ───────────────────────────────────────────────────── */
.budget-kpi-strip {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.budget-kpi-card {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border-md);
    border-radius: 10px;
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.budget-kpi-label { font-size: .75rem; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: .04em; }
.budget-kpi-value { font-size: 1.15rem; font-weight: 700; }
.budget-kpi-value.blue  { color: var(--cv-blue-text); }
.budget-kpi-value.green { color: var(--cv-green-bright); }
.budget-kpi-value.red   { color: var(--cv-danger-text, #E07070); }
.budget-kpi-sub {
    font-size: .72rem;
    color: var(--cv-text-muted);
    margin-top: 3px;
}

/* ── Toolbar ─────────────────────────────────────────────────────── */
.budget-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}
.budget-tab-group { display: flex; border-radius: 8px; overflow: hidden; border: 1px solid var(--cv-border-md); }
.budget-tab {
    padding: 7px 14px;
    font-size: .8rem;
    background: transparent;
    border: none;
    color: var(--cv-text-muted);
    cursor: pointer;
    transition: background .15s, color .15s;
}
.budget-tab:not(:last-child) { border-inline-end: 1px solid var(--cv-border-md); }
.budget-tab.active { background: var(--cv-amber-light); color: var(--cv-amber); }
.budget-tab:hover:not(.active) { background: var(--cv-bg-hover); color: var(--cv-text-primary); }

/* ── Group header ────────────────────────────────────────────────── */
.budget-group-header { margin: 24px 0 8px; display: flex; align-items: center; gap: 10px; }
.budget-group-badge {
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 3px 10px;
    border-radius: 20px;
}
.budget-group-badge.revenue { background: var(--cv-green-dim); color: var(--cv-green-bright); border: 1px solid var(--cv-green-border); }
.budget-group-badge.cogs    { background: var(--cv-amber-light); color: var(--cv-amber); border: 1px solid var(--cv-amber-border); }
.budget-group-badge.expense { background: var(--cv-blue-light); color: var(--cv-blue-text); border: 1px solid var(--cv-blue-border); }

/* ── Grid wrapper (horizontal scroll) ───────────────────────────── */
.budget-grid-wrap {
    overflow-x: auto;
    margin-bottom: 10px;
    border-radius: 10px;
    border: 1px solid var(--cv-border-md);
}

/* ── Table ───────────────────────────────────────────────────────── */
.budget-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    min-width: 1400px;
}
.budget-th-account {
    width: 220px;
    min-width: 180px;
    text-align: start;
    padding: 10px 14px;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--cv-text-muted);
    background: var(--cv-bg-card-alt);
    border-bottom: 1px solid var(--cv-border-md);
}
.budget-th-month {
    width: 88px;
    text-align: center;
    padding: 10px 4px;
    font-size: .72rem;
    font-weight: 600;
    color: var(--cv-text-secondary);
    background: var(--cv-bg-card-alt);
    border-bottom: 1px solid var(--cv-border-md);
}
.budget-th-total {
    width: 100px;
    text-align: center;
    padding: 10px 4px;
    font-size: .72rem;
    font-weight: 700;
    color: var(--cv-amber);
    background: var(--cv-bg-card-alt);
    border-bottom: 1px solid var(--cv-border-md);
}
.budget-th-action {
    width: 100px;
    background: var(--cv-bg-card-alt);
    border-bottom: 1px solid var(--cv-border-md);
}

/* Sub-header row (Bdgt / Actl / Var labels) */
.budget-sub-header td { background: var(--cv-bg-card); border-bottom: 1px solid var(--cv-border-md); }
.budget-sub-cell {
    display: table-cell;
    text-align: center;
    padding: 3px 2px;
}
.sub-b { font-size: .65rem; color: var(--cv-text-muted); margin-inline-end: 4px; }
.sub-a { font-size: .65rem; color: var(--cv-blue-text); margin-inline-end: 4px; }
.sub-v { font-size: .65rem; color: var(--cv-green-bright); }

/* ── Sticky first column ─────────────────────────────────────────── */
.sticky-col {
    position: sticky;
    inset-inline-start: 0;
    z-index: 1;
    background: var(--cv-bg-card);
}
.budget-th-account.sticky-col { background: var(--cv-bg-card-alt); z-index: 2; }

/* ── Data rows ───────────────────────────────────────────────────── */
.budget-data-row {
    border-bottom: 1px solid var(--cv-border-md);
    transition: background .12s;
}
.budget-data-row:hover { background: var(--cv-bg-hover); }
.budget-data-row:hover .sticky-col { background: var(--cv-bg-hover); }
.budget-row-editing { background: var(--cv-amber-light) !important; }
.budget-row-editing .sticky-col { background: var(--cv-amber-light) !important; }

.budget-td-account {
    padding: 8px 14px;
    vertical-align: middle;
}
.budget-account-info { display: flex; flex-direction: column; gap: 2px; }
.budget-account-code { font-size: .7rem; color: var(--cv-text-muted); font-family: monospace; }
.budget-account-name { font-size: .825rem; color: var(--cv-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 190px; }

.budget-data-cell {
    padding: 6px 4px;
    text-align: center;
    vertical-align: middle;
    cursor: default;
}
.budget-data-row:not(.budget-row-editing) .budget-data-cell:not(.budget-total-cell) {
    cursor: pointer;
}

/* Three micro-lines inside each cell */
.cell-b { font-size: .72rem; color: var(--cv-text-secondary); line-height: 1.4; }
.cell-a { font-size: .72rem; color: var(--cv-blue-text); font-weight: 600; line-height: 1.4; }
.cell-v { font-size: .72rem; font-weight: 700; line-height: 1.4; }
.cell-v-fav     { color: var(--cv-green-bright); }
.cell-v-unfav   { color: var(--cv-danger-text, #E07070); }
.cell-v-neutral { color: var(--cv-text-muted); }

/* Inline cell input */
.budget-cell-input {
    width: 76px;
    padding: 3px 5px;
    background: var(--cv-bg-white);
    border: 1px solid var(--cv-amber-border);
    border-radius: 4px;
    color: var(--cv-text-primary);
    font-size: .8rem;
    text-align: center;
    outline: none;
}
.budget-cell-input:focus { border-color: var(--cv-amber); }

/* ── Totals footer row ───────────────────────────────────────────── */
.budget-totals-row {
    background: var(--cv-bg-card-alt);
    border-top: 2px solid var(--cv-border-md);
}
.budget-total-label {
    padding: 8px 14px;
    font-size: .78rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-align: start;
}
.budget-total-cell {
    padding: 6px 4px;
    text-align: center;
    vertical-align: middle;
    background: transparent;
}

/* ── Row action buttons ──────────────────────────────────────────── */
.budget-td-actions { padding: 4px 8px; vertical-align: middle; }
.budget-row-actions { display: flex; align-items: center; justify-content: center; gap: 6px; }
.budget-icon-btn {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid var(--cv-border-md);
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background .12s, border-color .12s, color .12s;
    color: var(--cv-text-muted);
}
.budget-icon-btn.edit:hover  { background: var(--cv-blue-light); border-color: var(--cv-blue-border); color: var(--cv-blue-text); }
.budget-icon-btn.danger:hover{ background: rgba(224,112,112,.12); border-color: rgba(224,112,112,.28); color: var(--cv-danger-text, #E07070); }

/* ── Empty state ─────────────────────────────────────────────────── */
.budget-empty {
    text-align: center;
    padding: 64px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    color: var(--cv-text-muted);
}
.budget-empty-title { font-size: 1.1rem; font-weight: 600; color: var(--cv-text-secondary); }
.budget-empty-sub   { font-size: .85rem; max-width: 360px; }
</style>