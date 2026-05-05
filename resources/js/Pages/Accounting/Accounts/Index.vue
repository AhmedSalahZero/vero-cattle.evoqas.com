<template>
    <AuthenticatedLayout>

        <div class="cv-page-hero">
            <div class="cv-page-hero-eyebrow">{{ t({ en: 'Accounting', ar: 'المحاسبة' }) }}</div>
            <div class="cv-page-hero-title">{{ t({ en: 'Chart of Accounts', ar: 'دليل الحسابات' }) }}</div>
            <div class="cv-page-hero-sub">
                {{ t({ en: 'Structured financial account tree — Balance Sheet & Income Statement', ar: 'شجرة الحسابات المالية — الميزانية العمومية وقائمة الدخل' }) }}
            </div>
            <div class="cv-page-hero-actions">
                <button class="cv-btn cv-btn-amber" @click="openAddPanel(null)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    {{ t({ en: 'New Account', ar: 'حساب جديد' }) }}
                </button>
            </div>
        </div>

        <div class="cv-page">

            <!-- ── Super admin company switcher ────────────────────── -->
            <div v-if="isSuperAdmin" class="coa-company-bar">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                <span class="coa-company-bar-label">{{ t({ en: 'Viewing company:', ar: 'الشركة المعروضة:' }) }}</span>
                <select class="cv-select coa-company-select" :value="activeCompanyId" @change="switchCompany(+$event.target.value)">
                    <option v-if="!activeCompanyId" :value="0" disabled>{{ t({ en: '— Select company —', ar: '— اختر شركة —' }) }}</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">
                        {{ isRtl ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar) }}
                    </option>
                </select>
                <span class="coa-company-bar-count">{{ accounts.length }} {{ t({ en: 'accounts', ar: 'حساب' }) }}</span>
            </div>

            <!-- ── Tabs ─────────────────────────────────────────── -->
            <div class="coa-tabs">
                <button class="coa-tab" :class="{ active: activeTab === 'statement' }" @click="activeTab = 'statement'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="10" y="2" width="4" height="4" rx="1"/>
                        <rect x="2" y="17" width="4" height="4" rx="1"/>
                        <rect x="10" y="17" width="4" height="4" rx="1"/>
                        <rect x="18" y="17" width="4" height="4" rx="1"/>
                        <path d="M12 6v4M4 17v-2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2M12 10v3"/>
                    </svg>
                    {{ t({ en: 'Financial Statement View', ar: 'عرض القوائم المالية' }) }}
                </button>
                <button class="coa-tab" :class="{ active: activeTab === 'flat' }" @click="activeTab = 'flat'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                        <line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/>
                        <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    {{ t({ en: 'All Accounts', ar: 'جميع الحسابات' }) }}
                </button>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB 1 — FINANCIAL STATEMENT VIEW
            ══════════════════════════════════════════════════ -->
            <div v-if="activeTab === 'statement'" class="coa-statement">

                <!-- BALANCE SHEET -->
                <div class="coa-fs-block">
                    <div class="coa-fs-header coa-fs-header-blue">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3"/><path d="M3 8h18"/>
                            <path d="M8 8v13"/><path d="M16 8v13"/><path d="M3 21h18"/>
                        </svg>
                        {{ t({ en: 'Balance Sheet', ar: 'الميزانية العمومية' }) }}
                        <span class="coa-fs-total">
                            {{ t({ en: 'Total Assets', ar: 'إجمالي الأصول' }) }}:
                            <span class="coa-amount-blue">{{ formatAmount(totalAssets) }}</span>
                        </span>
                    </div>

                    <div class="coa-fs-body">
                        <CoaSection :title="t({ en: 'ASSETS', ar: 'الأصول' })" color="blue" :total="totalAssets" :total-label="t({ en: 'Total Assets', ar: 'إجمالي الأصول' })">
                            <CoaGroup :title="t({ en: 'Fixed Assets', ar: 'الأصول الثابتة' })" header-code="1100" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Long-term Assets', ar: 'الأصول طويلة الأجل' })" header-code="1200" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Current Assets & Inventory', ar: 'الأصول المتداولة والمخزون' })" header-code="1300" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete">
                                <template #special>
                                    <div v-for="a in wipAccounts" :key="a.id" class="coa-wip-row">
                                        <span class="coa-wip-badge">WIP</span>
                                        <span class="coa-code">{{ a.code }}</span>
                                        <span class="coa-name">{{ n(a, 'name') }}</span>
                                        <span class="coa-amount-blue">{{ formatAmount(a.balance ?? 0) }}</span>
                                        <div class="coa-row-actions">
                                            <button class="cv-action-btn cv-action-btn-amber" @click="openEditPanel(a)">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </CoaGroup>
                            <CoaGroup :title="t({ en: 'Cash — Safe Accounts', ar: 'النقدية — حسابات الخزينة' })" header-code="1400" :accounts="accounts" :is-rtl="isRtl" :repeater="true" :repeater-label="t({ en: '+ Add Safe / Currency', ar: '+ إضافة خزينة / عملة' })" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Banks', ar: 'البنوك' })" header-code="1500" :accounts="accounts" :is-rtl="isRtl" :repeater="true" :repeater-label="t({ en: '+ Add Bank Account', ar: '+ إضافة حساب بنكي' })" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Accounts Receivable', ar: 'حسابات القبض' })" header-code="1600" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Notes Receivable', ar: 'أوراق القبض' })" header-code="1610" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Other Debtors', ar: 'مدينون آخرون' })" header-code="1700" :accounts="accounts" :is-rtl="isRtl" :repeater="true" :repeater-label="t({ en: '+ Add Debtor Account', ar: '+ إضافة حساب مدين' })" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                        </CoaSection>

                        <CoaSection :title="t({ en: 'LIABILITIES', ar: 'الالتزامات' })" color="red" :total="totalLiabilities" :total-label="t({ en: 'Total Liabilities', ar: 'إجمالي الالتزامات' })">
                            <CoaGroup :title="t({ en: 'Long-term Liabilities', ar: 'الالتزامات طويلة الأجل' })" header-code="2100" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                            <CoaGroup :title="t({ en: 'Current Liabilities', ar: 'الالتزامات المتداولة' })" header-code="2200" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                        </CoaSection>

                        <CoaSection :title="t({ en: 'EQUITY', ar: 'حقوق الملكية' })" color="green" :total="totalEquity" :total-label="t({ en: 'Total Equity', ar: 'إجمالي حقوق الملكية' })">
                            <CoaGroup :title="t({ en: 'Equity Accounts', ar: 'حسابات حقوق الملكية' })" header-code="3000" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                        </CoaSection>

                        <div class="coa-bs-check" :class="bsBalanced ? 'coa-bs-check-ok' : 'coa-bs-check-err'">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <g v-if="bsBalanced">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                </g>
                                <g v-else>
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </g>
                            </svg>
                            <span v-if="bsBalanced">{{ t({ en: 'Balance Sheet is balanced — Assets = Liabilities + Equity', ar: 'الميزانية متوازنة — الأصول = الالتزامات + حقوق الملكية' }) }}</span>
                            <span v-else>{{ t({ en: 'Imbalance detected', ar: 'يوجد اختلال في التوازن' }) }}: <strong>{{ formatAmount(Math.abs(totalAssets - totalLiabilities - totalEquity)) }}</strong></span>
                        </div>
                    </div>
                </div>

                <!-- INCOME STATEMENT -->
                <div class="coa-fs-block" style="margin-top:1.5rem">
                    <div class="coa-fs-header coa-fs-header-green">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 1 18"/><polyline points="16 7 22 7 22 13"/>
                        </svg>
                        {{ t({ en: 'Income Statement', ar: 'قائمة الدخل' }) }}
                        <span class="coa-fs-total">
                            {{ t({ en: 'Net Income', ar: 'صافي الربح' }) }}:
                            <span :class="netIncome >= 0 ? 'coa-amount-green' : 'coa-amount-red'">{{ formatAmount(netIncome) }}</span>
                        </span>
                    </div>

                    <div class="coa-fs-body">
                        <CoaSection :title="t({ en: 'REVENUE', ar: 'الإيرادات' })" color="green" :total="totalRevenue" :total-label="t({ en: 'Total Revenue', ar: 'إجمالي الإيرادات' })">
                            <CoaGroup :title="t({ en: 'Revenue Accounts', ar: 'حسابات الإيرادات' })" header-code="4000" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                        </CoaSection>

                        <CoaSection :title="t({ en: 'COST OF GOODS SOLD', ar: 'تكلفة البضاعة المباعة' })" color="amber" :total="totalCogs" :total-label="t({ en: 'Total COGS', ar: 'إجمالي التكلفة' })">
                            <div class="coa-cogs-note">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                {{ t({ en: 'COGS is recognised only at point of sale — costs flow through Livestock WIP while animals are alive', ar: 'تكلفة المبيعات تُثبَّت فقط عند البيع — التكاليف تتراكم في مخزون الماشية قيد التشغيل طوال فترة حياة الماشية' }) }}
                            </div>
                            <CoaGroup :title="t({ en: 'COGS Accounts', ar: 'حسابات التكلفة' })" header-code="5000" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                        </CoaSection>

                        <CoaSection :title="t({ en: 'OPERATING EXPENSES', ar: 'المصروفات التشغيلية' })" color="red" :total="totalExpenses" :total-label="t({ en: 'Total Expenses', ar: 'إجمالي المصروفات' })">
                            <CoaGroup :title="t({ en: 'Expense Accounts', ar: 'حسابات المصروفات' })" header-code="6000" :accounts="accounts" :is-rtl="isRtl" @add="openAddPanel" @edit="openEditPanel" @toggle="toggleActive" @delete="confirmDelete"/>
                        </CoaSection>

                        <div class="coa-net-income" :class="netIncome >= 0 ? 'coa-net-income-profit' : 'coa-net-income-loss'">
                            <span>{{ netIncome >= 0 ? t({ en: 'Net Profit', ar: 'صافي الربح' }) : t({ en: 'Net Loss', ar: 'صافي الخسارة' }) }}</span>
                            <span class="coa-net-income-amount">{{ formatAmount(Math.abs(netIncome)) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 TAB 2 — FLAT ALL ACCOUNTS
            ══════════════════════════════════════════════════ -->
            <div v-else class="coa-flat">
                <div class="coa-flat-toolbar">
                    <div class="cv-search-wrap" style="flex:1;max-width:320px">
                        <svg class="cv-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input v-model="flatSearch" type="text" class="cv-search-input" :placeholder="t({ en: 'Search by code or name…', ar: 'بحث بالرمز أو الاسم…' })" :dir="isRtl ? 'rtl' : 'ltr'"/>
                    </div>
                    <div class="coa-filter-group">
                        <button v-for="opt in typeFilterOptions" :key="opt.value" type="button" class="coa-filter-btn" :class="{ active: flatTypeFilter === opt.value }" @click="flatTypeFilter = opt.value">{{ opt.label }}</button>
                    </div>
                    <div class="coa-filter-group">
                        <button type="button" class="coa-filter-btn" :class="{ active: flatActiveFilter === '' }"  @click="flatActiveFilter = ''">{{ t({ en: 'All', ar: 'الكل' }) }}</button>
                        <button type="button" class="coa-filter-btn" :class="{ active: flatActiveFilter === '1' }" @click="flatActiveFilter = '1'">{{ t({ en: 'Active', ar: 'نشط' }) }}</button>
                        <button type="button" class="coa-filter-btn" :class="{ active: flatActiveFilter === '0' }" @click="flatActiveFilter = '0'">{{ t({ en: 'Inactive', ar: 'معطل' }) }}</button>
                    </div>
                </div>

                <div class="coa-flat-list">
                    <div class="coa-flat-header">
                        <span>{{ t({ en: 'Code', ar: 'الرمز' }) }}</span>
                        <span>{{ t({ en: 'Account Name', ar: 'اسم الحساب' }) }}</span>
                        <span>{{ t({ en: 'Type', ar: 'النوع' }) }}</span>
                        <span>{{ t({ en: 'Balance', ar: 'الرصيد' }) }}</span>
                        <span>{{ t({ en: 'Parent', ar: 'الحساب الرئيسي' }) }}</span>
                        <span>{{ t({ en: 'Status', ar: 'الحالة' }) }}</span>
                        <span>{{ t({ en: 'Actions', ar: 'إجراءات' }) }}</span>
                    </div>

                    <div v-if="filteredAccounts.length === 0" class="coa-empty">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="opacity:.3">
                            <rect x="10" y="2" width="4" height="4" rx="1"/><rect x="2" y="17" width="4" height="4" rx="1"/>
                            <rect x="10" y="17" width="4" height="4" rx="1"/><rect x="18" y="17" width="4" height="4" rx="1"/>
                            <path d="M12 6v4M4 17v-2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2M12 10v3"/>
                        </svg>
                        <p>{{ t({ en: 'No accounts found', ar: 'لا توجد حسابات' }) }}</p>
                    </div>

                    <div v-for="acc in filteredAccounts" :key="acc.id" class="coa-flat-row" :class="{ 'coa-flat-row-inactive': !acc.is_active }">
                        <span class="coa-code">{{ acc.code }}</span>
                        <span class="coa-name">
                            {{ n(acc, 'name') }}
                            <span v-if="acc.is_system" class="coa-system-badge">sys</span>
                            <span v-if="acc.currency" class="coa-currency-badge">{{ acc.currency }}</span>
                        </span>
                        <span><span class="cv-badge" :class="typeBadgeClass(acc.type)">{{ typeLabel(acc.type) }}</span></span>
                        <span>
                            <span class="cv-badge" :class="acc.normal_balance === 'debit' ? 'cv-badge-blue' : 'cv-badge-amber'">
                                {{ acc.normal_balance === 'debit' ? t({ en: 'Dr', ar: 'مدين' }) : t({ en: 'Cr', ar: 'دائن' }) }}
                            </span>
                        </span>
                        <span class="coa-text-muted">{{ acc.parent ? acc.parent.code + ' ' + n(acc.parent, 'name') : '—' }}</span>
                        <span>
                            <span class="cv-badge" :class="acc.is_active ? 'cv-badge-green' : 'cv-badge-muted'">
                                {{ acc.is_active ? t({ en: 'Active', ar: 'نشط' }) : t({ en: 'Inactive', ar: 'معطل' }) }}
                            </span>
                        </span>
                        <span class="coa-row-actions">
                            <button class="cv-action-btn" @click="openAddPanel(acc)" :title="t({ en: 'Add Sub-account', ar: 'إضافة حساب فرعي' })">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </button>
                            <button class="cv-action-btn cv-action-btn-amber" @click="openEditPanel(acc)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button class="cv-action-btn" :class="acc.is_active ? 'cv-action-btn-amber' : ''" @click="toggleActive(acc)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <g v-if="acc.is_active">
                                        <path d="M18.36 6.64A9 9 0 0 1 20.77 15"/>
                                        <path d="M6.16 6.16a9 9 0 1 0 12.68 12.68"/>
                                        <line x1="2" y1="2" x2="22" y2="22"/>
                                    </g>
                                    <g v-else>
                                        <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
                                    </g>
                                </svg>
                            </button>
                            <button v-if="!acc.is_system && acc.children_count === 0" class="cv-action-btn cv-action-btn-danger" @click="confirmDelete(acc)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            </button>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SLIDE PANEL
        ══════════════════════════════════════════════════════ -->
        <Transition name="panel">
            <div v-if="panelOpen" class="coa-panel-backdrop" @click.self="closePanel">
                <div class="coa-panel" :dir="isRtl ? 'rtl' : 'ltr'">

                    <div class="coa-panel-header">
                        <div>
                            <div class="coa-panel-eyebrow">{{ panelMode === 'edit' ? t({ en: 'Edit Account', ar: 'تعديل الحساب' }) : t({ en: 'New Account', ar: 'حساب جديد' }) }}</div>
                            <div class="coa-panel-title">{{ panelMode === 'edit' ? (n(form, 'name') || t({ en: 'Account', ar: 'حساب' })) : t({ en: 'Add Account', ar: 'إضافة حساب' }) }}</div>
                        </div>
                        <button class="cv-action-btn" @click="closePanel">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <div class="coa-panel-body">

                        <!-- ── Context banner: shown when coming from a group repeater ── -->
                        <div v-if="panelContext" class="coa-context-banner">
                            <div class="coa-context-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            </div>
                            <div class="coa-context-body">
                                <div class="coa-context-eyebrow">{{ t({ en: 'Adding account under', ar: 'إضافة حساب تحت' }) }}</div>
                                <div class="coa-context-value">{{ isRtl ? panelContext.labelAr : panelContext.label }}</div>
                                <div class="coa-context-pills">
                                    <span class="coa-pill coa-pill-type">{{ typeLabel(panelContext.type) }}</span>
                                    <span class="coa-pill coa-pill-balance">{{ panelContext.normal_balance === 'debit' ? t({ en: 'Debit (Dr)', ar: 'مدين' }) : t({ en: 'Credit (Cr)', ar: 'دائن' }) }}</span>
                                    <span class="coa-pill coa-pill-code">{{ t({ en: 'prefix:', ar: 'بادئة:' }) }} {{ panelContext.codePrefix }}</span>
                                </div>
                                <div class="coa-context-lock-note">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    {{ t({ en: 'Type, balance and parent are pre-filled and locked to match this group.', ar: 'النوع والرصيد والحساب الرئيسي مقفلة لتطابق هذه المجموعة.' }) }}
                                </div>
                            </div>
                        </div>

                        <!-- Section ★ — Company (super admin only) -->
                        <div v-if="isSuperAdmin" class="coa-panel-section">
                            <div class="coa-panel-section-num">★</div>
                            <div class="coa-panel-section-title">{{ t({ en: 'Company', ar: 'الشركة' }) }}</div>
                            <div class="cv-field">
                                <label class="cv-label">{{ t({ en: 'Select Company', ar: 'اختر الشركة' }) }} <span class="coa-required">*</span></label>
                                <select v-model="form.company_id" class="cv-select" :class="{ 'cv-input--error': errors.company_id }">
                                    <option :value="null" disabled>{{ t({ en: '— Choose company —', ar: '— اختر شركة —' }) }}</option>
                                    <option v-for="c in companies" :key="c.id" :value="c.id">
                                        {{ isRtl ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar) }}
                                    </option>
                                </select>
                                <div v-if="errors.company_id" class="cv-field-error">{{ errors.company_id }}</div>
                            </div>
                        </div>

                        <!-- Section 1 — Code & Parent -->
                        <div class="coa-panel-section">
                            <div class="coa-panel-section-num">1</div>
                            <div class="coa-panel-section-title">{{ t({ en: 'Account Code', ar: 'رمز الحساب' }) }}</div>

                            <div class="cv-field">
                                <label class="cv-label">{{ t({ en: 'Account Code', ar: 'رمز الحساب' }) }} <span class="coa-required">*</span></label>

                               <!-- Context mode: parent pre-filled — split prefix + suffix fields -->
                                <div v-if="panelContext" class="coa-code-suggested-wrap">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <!-- Locked prefix — parent code, cannot be changed -->
                                        <div class="cv-input coa-code-mono" style="opacity:0.55; cursor:not-allowed; width:90px; text-align:center;">
                                            {{ panelContext.codePrefix }}
                                        </div>
                                        <span style="color:var(--cv-text-muted);">—</span>
                                        <!-- User types only the suffix -->
                                        <input
                                            v-model="form.code_suffix"
                                            type="text"
                                            class="cv-input coa-code-mono"
                                            dir="ltr"
                                            style="width:90px;"
                                            placeholder="001"
                                            maxlength="6"
                                            :class="{ 'cv-input--error': errors.code }"
                                            @input="form.code_suffix = form.code_suffix.replace(/\D/g, ''); form.code = panelContext.codePrefix + form.code_suffix"
                                        />
                                        <!-- Live combined preview -->
                                        <span style="font-family:monospace; font-size:0.8rem; color:var(--cv-text-muted);">
                                            → {{ panelContext.codePrefix + (form.code_suffix || '') }}
                                        </span>
                                    </div>
                                    <span class="coa-code-auto-badge">{{ t({ en: 'auto-suggested', ar: 'مقترح' }) }}</span>
                                </div>

                                <!-- Free create mode: input + full cheat-sheet -->
                                <template v-else>
                                    <input v-model="form.code" type="text" class="cv-input coa-code-mono" dir="ltr"
                                        :class="{ 'cv-input--error': errors.code }"
                                        :placeholder="t({ en: 'e.g. 1401', ar: 'مثال: 1401' })"
                                        @input="onCodeInput"/>

                                    <!-- Code cheat-sheet reference -->
                                    <div class="coa-code-guide">
                                        <div class="coa-code-guide-title">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                            {{ t({ en: 'Code reference — type the first digits to auto-fill type & balance:', ar: 'مرجع الكود — اكتب الأرقام الأولى لملء النوع تلقائياً:' }) }}
                                        </div>
                                        <div class="coa-guide-grid">
                                            <div class="coa-guide-row" :class="{ 'coa-guide-active': ['11','12','13','14','15','16','17','18'].includes(detectedPrefix) }">
                                                <span class="coa-guide-prefix">1xxx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Assets (all sub-groups below)', ar: 'الأصول (جميع المجموعات أدناه)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '11' }">
                                                <span class="coa-guide-prefix">11xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Fixed Assets (equipment, land…)', ar: 'الأصول الثابتة (معدات، أراضي…)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '12' }">
                                                <span class="coa-guide-prefix">12xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Long-term Assets (deposits…)', ar: 'أصول طويلة الأجل (ودائع…)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '13' }">
                                                <span class="coa-guide-prefix">13xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Current Assets & Inventory (feed, medicine…)', ar: 'أصول متداولة ومخزون (أعلاف، أدوية…)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '14' }">
                                                <span class="coa-guide-prefix">14xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Cash — Safe / Till accounts', ar: 'نقدية — حسابات الخزينة' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '15' }">
                                                <span class="coa-guide-prefix">15xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Banks (current, time deposit, cover accounts)', ar: 'البنوك (جاري، ودائع، غطاء)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '16' }">
                                                <span class="coa-guide-prefix">16xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Accounts Receivable (AR) — customer balances', ar: 'حسابات القبض — أرصدة العملاء' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '17' }">
                                                <span class="coa-guide-prefix">17xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Notes Receivable (NR) — promissory notes', ar: 'أوراق القبض — سندات إذنية' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '18' }">
                                                <span class="coa-guide-prefix">18xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Other Debtors', ar: 'مدينون آخرون' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-asset">{{ t({ en: 'Asset · Dr', ar: 'أصل · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row" :class="{ 'coa-guide-active': ['21','22'].includes(detectedPrefix) }">
                                                <span class="coa-guide-prefix">2xxx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Liabilities', ar: 'الالتزامات' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-liab">{{ t({ en: 'Liability · Cr', ar: 'التزام · دائن' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '21' }">
                                                <span class="coa-guide-prefix">21xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Long-term Loans', ar: 'قروض طويلة الأجل' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-liab">{{ t({ en: 'Liability · Cr', ar: 'التزام · دائن' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row coa-guide-sub" :class="{ 'coa-guide-active': detectedPrefix === '22' }">
                                                <span class="coa-guide-prefix">22xx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Current Liabilities (AP, NP, AP Accruals, banking facilities…)', ar: 'التزامات متداولة (دائنون، أوراق دفع، مستحقات…)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-liab">{{ t({ en: 'Liability · Cr', ar: 'التزام · دائن' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row" :class="{ 'coa-guide-active': detectedPrefix === '3' }">
                                                <span class="coa-guide-prefix">3xxx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Equity (capital, reserves, retained earnings)', ar: 'حقوق الملكية (رأس مال، احتياطيات، أرباح محتجزة)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-equity">{{ t({ en: 'Equity · Cr', ar: 'ملكية · دائن' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row" :class="{ 'coa-guide-active': detectedPrefix === '4' }">
                                                <span class="coa-guide-prefix">4xxx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Revenue (animal sales, meat sales, other income)', ar: 'الإيرادات (مبيعات الماشية، اللحوم، إيرادات أخرى)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-rev">{{ t({ en: 'Revenue · Cr', ar: 'إيراد · دائن' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row" :class="{ 'coa-guide-active': detectedPrefix === '5' }">
                                                <span class="coa-guide-prefix">5xxx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'COGS — cost of animals sold', ar: 'تكلفة المبيعات — تكلفة الماشية المباعة' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-cogs">{{ t({ en: 'COGS · Dr', ar: 'تكلفة · مدين' }) }}</span>
                                            </div>
                                            <div class="coa-guide-row" :class="{ 'coa-guide-active': detectedPrefix === '6' }">
                                                <span class="coa-guide-prefix">6xxx</span>
                                                <span class="coa-guide-desc">{{ t({ en: 'Operating Expenses (labour, depreciation, utilities, mortality loss…)', ar: 'مصروفات تشغيلية (عمالة، اهتلاك، مرافق، خسارة نفوق…)' }) }}</span>
                                                <span class="coa-guide-badge coa-guide-exp">{{ t({ en: 'Expense · Dr', ar: 'مصروف · مدين' }) }}</span>
                                            </div>
                                        </div>
                                        <!-- Live detection confirmation -->
                                        <div v-if="detectedPrefix" class="coa-detected-confirm">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            {{ t({ en: 'Detected:', ar: 'تم اكتشاف:' }) }} <strong>{{ detectedLabel }}</strong> — {{ t({ en: 'type and balance auto-filled in Section 3.', ar: 'تم ملء النوع والرصيد تلقائياً في القسم 3.' }) }}
                                        </div>
                                    </div>
                                </template>
                                <div v-if="errors.code" class="cv-field-error">{{ errors.code }}</div>
                            </div>

                            <!-- Parent picker — hidden when context locks it -->
                            <div class="cv-field" v-if="!panelContext">
                                <label class="cv-label">{{ t({ en: 'Parent Account', ar: 'الحساب الرئيسي' }) }}</label>
                                <div class="coa-parent-wrap">
                                    <div class="coa-parent-search">
                                        <input v-model="parentSearch" type="text" class="cv-input" :placeholder="t({ en: 'Search parent…', ar: 'بحث عن حساب رئيسي…' })"/>
                                    </div>
                                    <div class="coa-parent-list">
                                        <div class="coa-parent-opt" :class="{ active: form.parent_id === null }" @click="form.parent_id = null">
                                            — {{ t({ en: 'Root (no parent)', ar: 'جذر (بلا رئيسي)' }) }} —
                                        </div>
                                        <div v-for="p in filteredParents" :key="p.id" class="coa-parent-opt" :class="{ active: form.parent_id === p.id }" @click="form.parent_id = p.id">
                                            {{ p.code }} — {{ n(p, 'name') }}
                                        </div>
                                    </div>
                                </div>
                                <div v-if="errors.parent_id" class="cv-field-error">{{ errors.parent_id }}</div>
                            </div>
                            <!-- Parent locked display -->
                            <div v-if="panelContext && panelContext.parentId" class="coa-locked-row">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                {{ t({ en: 'Parent:', ar: 'الحساب الرئيسي:' }) }}
                                <strong>{{ panelContext.codePrefix }} — {{ isRtl ? panelContext.labelAr : panelContext.label }}</strong>
                            </div>
                        </div>

                        <!-- Section 2 — Name -->
                        <div class="coa-panel-section">
                            <div class="coa-panel-section-num">2</div>
                            <div class="coa-panel-section-title">{{ t({ en: 'Account Name', ar: 'اسم الحساب' }) }}</div>
                            <div class="cv-field" v-if="lang === 'en'">
                                <label class="cv-label">{{ t({ en: 'Account Name (English)', ar: 'اسم الحساب (إنجليزي)' }) }} <span class="coa-required">*</span></label>
                                <input v-model="form.name_en" type="text" class="cv-input" :class="{ 'cv-input--error': errors.name_en }"
                                    :placeholder="panelContext?.namePlaceholder || t({ en: 'e.g. Main Safe — EGP', ar: 'مثال: الخزينة الرئيسية' })"
                                    dir="ltr"/>
                                <div v-if="errors.name_en" class="cv-field-error">{{ errors.name_en }}</div>
                            </div>
                            <div class="cv-field" v-if="lang === 'ar'">
                                <label class="cv-label">{{ t({ en: 'Account Name (Arabic)', ar: 'اسم الحساب (عربي)' }) }} <span class="coa-required">*</span></label>
                                <input v-model="form.name_ar" type="text" class="cv-input" :class="{ 'cv-input--error': errors.name_ar }"
                                    :placeholder="panelContext?.namePlaceholderAr || t({ en: 'e.g. Main Safe', ar: 'مثال: الخزينة الرئيسية' })"
                                    dir="rtl"/>
                                <div v-if="errors.name_ar" class="cv-field-error">{{ errors.name_ar }}</div>
                            </div>
                            <div v-show="false">
                                <input v-model="form.name_en" type="text"/>
                                <input v-model="form.name_ar" type="text"/>
                            </div>
                        </div>

                        <!-- Section 3 — Classification -->
                        <div class="coa-panel-section">
                            <div class="coa-panel-section-num">3</div>
                            <div class="coa-panel-section-title">{{ t({ en: 'Classification', ar: 'التصنيف' }) }}</div>

                            <!-- Locked when context is set -->
                            <div v-if="panelContext" class="coa-locked-classification">
                                <div class="coa-locked-row">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    {{ t({ en: 'Account Type:', ar: 'نوع الحساب:' }) }}
                                    <span class="cv-badge" :class="typeBadgeClass(form.type)">{{ typeLabel(form.type) }}</span>
                                </div>
                                <div class="coa-locked-row" style="margin-top:0.5rem">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    {{ t({ en: 'Normal Balance:', ar: 'الرصيد الطبيعي:' }) }}
                                    <span class="cv-badge" :class="form.normal_balance === 'debit' ? 'cv-badge-blue' : 'cv-badge-amber'">
                                        {{ form.normal_balance === 'debit' ? t({ en: 'Debit (Dr)', ar: 'مدين' }) : t({ en: 'Credit (Cr)', ar: 'دائن' }) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Editable when free create -->
                            <template v-else>
                                <div class="cv-field">
                                    <label class="cv-label">{{ t({ en: 'Account Type', ar: 'نوع الحساب' }) }} <span class="coa-required">*</span></label>
                                    <div class="coa-type-grid">
                                        <button type="button" class="coa-type-btn" :class="{ active: form.type === 'asset' }"     @click="form.type = 'asset';     onTypeChange()">{{ t({ en: 'Asset',     ar: 'أصل' }) }}</button>
                                        <button type="button" class="coa-type-btn" :class="{ active: form.type === 'liability' }" @click="form.type = 'liability'; onTypeChange()">{{ t({ en: 'Liability', ar: 'التزام' }) }}</button>
                                        <button type="button" class="coa-type-btn" :class="{ active: form.type === 'equity' }"    @click="form.type = 'equity';    onTypeChange()">{{ t({ en: 'Equity',    ar: 'ملكية' }) }}</button>
                                        <button type="button" class="coa-type-btn" :class="{ active: form.type === 'revenue' }"   @click="form.type = 'revenue';   onTypeChange()">{{ t({ en: 'Revenue',   ar: 'إيراد' }) }}</button>
                                        <button type="button" class="coa-type-btn" :class="{ active: form.type === 'cogs' }"      @click="form.type = 'cogs';      onTypeChange()">{{ t({ en: 'COGS',      ar: 'تكلفة' }) }}</button>
                                        <button type="button" class="coa-type-btn" :class="{ active: form.type === 'expense' }"   @click="form.type = 'expense';   onTypeChange()">{{ t({ en: 'Expense',   ar: 'مصروف' }) }}</button>
                                    </div>
                                    <div v-if="errors.type" class="cv-field-error">{{ errors.type }}</div>
                                </div>
                                <div class="cv-field">
                                    <label class="cv-label">{{ t({ en: 'Normal Balance', ar: 'الرصيد الطبيعي' }) }} <span class="coa-required">*</span></label>
                                    <div class="coa-radio-group">
                                        <label class="coa-radio" :class="{ active: form.normal_balance === 'debit' }">
                                            <input type="radio" v-model="form.normal_balance" value="debit"/>
                                            {{ t({ en: 'Debit (Dr)', ar: 'مدين' }) }}
                                        </label>
                                        <label class="coa-radio" :class="{ active: form.normal_balance === 'credit' }">
                                            <input type="radio" v-model="form.normal_balance" value="credit"/>
                                            {{ t({ en: 'Credit (Cr)', ar: 'دائن' }) }}
                                        </label>
                                    </div>
                                    <div v-if="errors.normal_balance" class="cv-field-error">{{ errors.normal_balance }}</div>
                                </div>
                                <div v-if="form.type" class="coa-type-hint">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 8 12 12 14 14"/></svg>
                                    {{ normalBalanceHint }}
                                </div>
                            </template>
                        </div>

                        <!-- Section 3.5 — Currency (only for Cash / Bank accounts) -->
                        <div v-if="showCurrencyField" class="coa-panel-section">
                            <div class="coa-panel-section-num">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            </div>
                            <div class="coa-panel-section-title">{{ t({ en: 'Currency', ar: 'العملة' }) }}</div>
                            <div class="cv-field">
                                <label class="cv-label">
                                    {{ t({ en: 'Account Currency', ar: 'عملة الحساب' }) }}
                                    <span class="coa-required">*</span>
                                </label>
                                <select v-model="form.currency" class="cv-select">
                                    <option value="EGP">🇪🇬 EGP — Egyptian Pound</option>
                                    <option value="USD">🇺🇸 USD — US Dollar</option>
                                    <option value="EUR">🇪🇺 EUR — Euro</option>
                                    <option value="GBP">🇬🇧 GBP — British Pound</option>
                                    <option value="SAR">🇸🇦 SAR — Saudi Riyal</option>
                                    <option value="AED">🇦🇪 AED — UAE Dirham</option>
                                    <option value="KWD">🇰🇼 KWD — Kuwaiti Dinar</option>
                                    <option value="QAR">🇶🇦 QAR — Qatari Riyal</option>
                                    <option value="CHF">🇨🇭 CHF — Swiss Franc</option>
                                    <option value="JPY">🇯🇵 JPY — Japanese Yen</option>
                                </select>
                                <div class="coa-type-hint" style="margin-top:0.5rem">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    {{ t({ en: 'Each safe or bank account should have one currency. Create separate accounts for each currency.', ar: 'كل خزينة أو حساب بنكي يجب أن يكون بعملة واحدة. أنشئ حسابات منفصلة لكل عملة.' }) }}
                                </div>
                            </div>
                        </div>

                        <!-- Section 4 — Settings -->
                        <div class="coa-panel-section">
                            <div class="coa-panel-section-num">4</div>
                            <div class="coa-panel-section-title">{{ t({ en: 'Settings', ar: 'الإعدادات' }) }}</div>
                            <div class="coa-toggle-row">
                                <div>
                                    <div class="coa-toggle-label">{{ t({ en: 'Allow Direct Posting', ar: 'السماح بالترحيل المباشر' }) }}</div>
                                    <div class="coa-toggle-sub">{{ t({ en: 'Turn off for group / header accounts that should not receive journal entries directly', ar: 'أوقفه لحسابات المجموعات التي لا تستقبل قيوداً مباشرة' }) }}</div>
                                </div>
                                <button type="button" class="coa-toggle-btn" :class="{ active: form.allow_direct_posting }" @click="form.allow_direct_posting = !form.allow_direct_posting">
                                    <span class="coa-toggle-knob"></span>
                                </button>
                            </div>
                            <div class="cv-field" style="margin-top:1rem">
                                <label class="cv-label">{{ t({ en: 'Notes', ar: 'ملاحظات' }) }}</label>
                                <textarea v-model="form.notes" class="cv-textarea" rows="2" :dir="isRtl ? 'rtl' : 'ltr'"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="coa-panel-footer">
                        <button class="cv-btn cv-btn-ghost" @click="closePanel" :disabled="saving">{{ t({ en: 'Cancel', ar: 'إلغاء' }) }}</button>
                        <button class="cv-btn cv-btn-amber" @click="saveAccount" :disabled="saving">
                            <svg v-if="saving" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="coa-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                            {{ saving ? t({ en: 'Saving…', ar: 'جاري الحفظ…' }) : t({ en: 'Save Account', ar: 'حفظ الحساب' }) }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- DELETE MODAL -->
        <Transition name="modal">
            <div v-if="deleteModal.open" class="cv-modal-backdrop">
                <div class="cv-modal cv-modal-sm">
                    <div class="cv-modal-body" style="text-align:center;padding:2rem 1.5rem">
                        <div class="coa-delete-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </div>
                        <div class="coa-delete-title">{{ t({ en: 'Delete Account', ar: 'حذف الحساب' }) }}</div>
                        <div class="coa-delete-sub">
                            {{ t({ en: 'Are you sure you want to delete', ar: 'هل أنت متأكد من حذف' }) }}
                            <strong>{{ deleteModal.account?.code }} — {{ n(deleteModal.account ?? {}, 'name') }}</strong>?
                            {{ t({ en: 'This cannot be undone.', ar: 'لا يمكن التراجع عن هذا الإجراء.' }) }}
                        </div>
                    </div>
                    <div class="cv-modal-footer">
                        <button class="cv-btn cv-btn-ghost" @click="deleteModal.open = false">{{ t({ en: 'Cancel', ar: 'إلغاء' }) }}</button>
                        <button class="cv-btn cv-btn-danger" @click="doDelete">{{ t({ en: 'Delete', ar: 'حذف' }) }}</button>
                    </div>
                </div>
            </div>
        </Transition>

    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import CoaSection from '@/Components/CoaSection.vue'
import CoaGroup   from '@/Components/CoaGroup.vue'

const props = defineProps({
    accounts:          { type: Array,  default: () => [] },
    filters:           { type: Object, default: () => ({}) },
    types:             { type: Array,  default: () => [] },
    isSuperAdmin:      { type: Boolean, default: false },
    companies:         { type: Array,  default: () => [] },
    selectedCompanyId: { type: Number, default: 0 },
})

const { locale: lang, isRtl, n,t } = useLocale()

// ── Tabs ───────────────────────────────────────────────────────────
const activeTab = ref('statement')

// ── Flat filters ───────────────────────────────────────────────────
const flatSearch       = ref(props.filters.search ?? '')
const flatTypeFilter   = ref(props.filters.type ?? '')
const flatActiveFilter = ref(props.filters.is_active ?? '')

const typeFilterOptions = computed(() => [
    { value: '',          label: t({ en: 'All',       ar: 'الكل' }) },
    { value: 'asset',     label: t({ en: 'Asset',     ar: 'أصل' }) },
    { value: 'liability', label: t({ en: 'Liability', ar: 'التزام' }) },
    { value: 'equity',    label: t({ en: 'Equity',    ar: 'ملكية' }) },
    { value: 'revenue',   label: t({ en: 'Revenue',   ar: 'إيراد' }) },
    { value: 'cogs',      label: t({ en: 'COGS',      ar: 'تكلفة' }) },
    { value: 'expense',   label: t({ en: 'Expense',   ar: 'مصروف' }) },
])

const filteredAccounts = computed(() =>
    props.accounts.filter(a => {
        const q = flatSearch.value.toLowerCase()
        const matchSearch = !q || a.code.toLowerCase().includes(q) || (a.name_en ?? '').toLowerCase().includes(q) || (a.name_ar ?? '').toLowerCase().includes(q)
        const matchType   = !flatTypeFilter.value || a.type === flatTypeFilter.value
        const matchActive = flatActiveFilter.value === '' || String(+a.is_active) === flatActiveFilter.value
        return matchSearch && matchType && matchActive
    })
)

// ── Statement grouping ─────────────────────────────────────────────
// CORRECT approach: use parent_id to find children, NOT string prefix matching.
// String prefix matching caused group headers (e.g. 1400) to appear as children
// of their own group because '1400'.startsWith('14') === true.
// Now CoaGroup receives ALL accounts and finds its own children by parent_id internally.
// We only need this helper for the WIP special slot and for totals.
function childrenOf(headerCode) {
    const header = props.accounts.find(a => a.code === headerCode)
    if (!header) return []
    return props.accounts.filter(a => a.parent_id === header.id)
}

const wipAccounts = computed(() =>
    props.accounts.filter(a => a.code === '1310')
)

// ── Totals — sum ALL posting accounts of each type ─────────────────
const totalAssets      = computed(() => props.accounts.filter(a => a.type === 'asset'     && a.allow_direct_posting).reduce((s, a) => s + (a.balance ?? 0), 0))
const totalLiabilities = computed(() => props.accounts.filter(a => a.type === 'liability' && a.allow_direct_posting).reduce((s, a) => s + (a.balance ?? 0), 0))
const totalEquity      = computed(() => props.accounts.filter(a => a.type === 'equity'    && a.allow_direct_posting).reduce((s, a) => s + (a.balance ?? 0), 0))
const totalRevenue     = computed(() => props.accounts.filter(a => a.type === 'revenue'   && a.allow_direct_posting).reduce((s, a) => s + (a.balance ?? 0), 0))
const totalCogs        = computed(() => props.accounts.filter(a => a.type === 'cogs'      && a.allow_direct_posting).reduce((s, a) => s + (a.balance ?? 0), 0))
const totalExpenses    = computed(() => props.accounts.filter(a => a.type === 'expense'   && a.allow_direct_posting).reduce((s, a) => s + (a.balance ?? 0), 0))
const netIncome        = computed(() => totalRevenue.value - totalCogs.value - totalExpenses.value)
const bsBalanced       = computed(() => Math.abs(totalAssets.value - totalLiabilities.value - totalEquity.value) <= 0.01)

// ── Super admin company context ─────────────────────────────────────
// Tracks which company the super admin is currently viewing/adding for.
// Regular users always use their own company — this ref is ignored for them.
const activeCompanyId = ref(props.selectedCompanyId || (props.companies[0]?.id ?? 0))

function switchCompany(id) {
    activeCompanyId.value = id
    router.get(route('accounts.index'), { company_id: id }, { preserveScroll: true, preserveState: true })
}

// ── Panel ──────────────────────────────────────────────────────────
const panelOpen    = ref(false)
const panelMode    = ref('create')
const panelContext = ref(null)   // { label, labelAr, codePrefix, type, normal_balance, parentId }
const saving       = ref(false)
const errors       = ref({})
const parentSearch = ref('')

const emptyForm = () => ({
    id: null, code: '', code_suffix: '', name_en: '', name_ar: '', type: '',
    normal_balance: 'debit', parent_id: null, allow_direct_posting: true,
    currency: null, notes: '',
    company_id: props.isSuperAdmin ? activeCompanyId.value : null,
})
const form = ref(emptyForm())

// Suggest the next available code under a parent account
// Uses the parent's actual children (by parent_id), not string prefix matching
function suggestCode(parentId, parentCode) {
    const siblings = props.accounts
        .filter(a => a.parent_id === parentId)
        .map(a => parseInt(a.code))
        .filter(n => !isNaN(n))
        .sort((a, b) => b - a)
    if (!siblings.length) {
        // No children yet — first child gets parentCode + '1' e.g. 1400 → 1401
        return String(parseInt(parentCode) + 1)
    }
    return String(siblings[0] + 1)
}

const filteredParents = computed(() => {
    const q = parentSearch.value.toLowerCase()
    return props.accounts
        .filter(a => a.id !== form.value.id)
        .filter(a => !q || a.code.toLowerCase().includes(q) || (a.name_en ?? '').toLowerCase().includes(q) || (a.name_ar ?? '').toLowerCase().includes(q))
})

const normalBalanceHint = computed(() => ({
    asset:     t({ en: 'Assets normally have a Debit balance',      ar: 'الأصول رصيدها الطبيعي مدين' }),
    liability: t({ en: 'Liabilities normally have a Credit balance', ar: 'الالتزامات رصيدها الطبيعي دائن' }),
    equity:    t({ en: 'Equity normally has a Credit balance',       ar: 'حقوق الملكية رصيدها الطبيعي دائن' }),
    revenue:   t({ en: 'Revenue normally has a Credit balance',      ar: 'الإيرادات رصيدها الطبيعي دائن' }),
    cogs:      t({ en: 'COGS normally has a Debit balance',          ar: 'تكلفة المبيعات رصيدها الطبيعي مدين' }),
    expense:   t({ en: 'Expenses normally have a Debit balance',     ar: 'المصروفات رصيدها الطبيعي مدين' }),
}[form.value.type] ?? ''))

function onTypeChange() {
    if (['asset','cogs','expense'].includes(form.value.type))       form.value.normal_balance = 'debit'
    if (['liability','equity','revenue'].includes(form.value.type)) form.value.normal_balance = 'credit'
}

// Currency field appears only for Cash (14xx) and Bank (15xx) accounts
// Detected from panelContext prefix OR from the code the user typed
const showCurrencyField = computed(() => {
    const prefix = panelContext.value?.codePrefix ?? form.value.code
    return String(prefix).startsWith('14') || String(prefix).startsWith('15')
})

// When currency field becomes visible, default to EGP if not set
watch(showCurrencyField, (val) => {
    if (val && !form.value.currency) form.value.currency = 'EGP'
})
const codePrefixMap = {
    '11': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Fixed Assets',               ar: 'الأصول الثابتة' } },
    '12': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Long-term Assets',            ar: 'أصول طويلة الأجل' } },
    '13': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Current Assets / Inventory',  ar: 'أصول متداولة / مخزون' } },
    '14': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Cash — Safe Accounts',        ar: 'نقدية — خزائن' } },
    '15': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Banks',                       ar: 'البنوك' } },
    '16': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Accounts Receivable (AR)',    ar: 'حسابات القبض' } },
    '17': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Notes Receivable (NR)',       ar: 'أوراق القبض' } },
    '18': { type: 'asset',     normal_balance: 'debit',  label: { en: 'Other Debtors',               ar: 'مدينون آخرون' } },
    '21': { type: 'liability', normal_balance: 'credit', label: { en: 'Long-term Loans',             ar: 'قروض طويلة الأجل' } },
    '22': { type: 'liability', normal_balance: 'credit', label: { en: 'Current Liabilities',         ar: 'التزامات متداولة' } },
    '3':  { type: 'equity',    normal_balance: 'credit', label: { en: 'Equity',                      ar: 'حقوق الملكية' } },
    '4':  { type: 'revenue',   normal_balance: 'credit', label: { en: 'Revenue',                     ar: 'إيرادات' } },
    '5':  { type: 'cogs',      normal_balance: 'debit',  label: { en: 'COGS',                        ar: 'تكلفة المبيعات' } },
    '6':  { type: 'expense',   normal_balance: 'debit',  label: { en: 'Operating Expenses',          ar: 'مصروفات تشغيلية' } },
}

const detectedPrefix = ref('')
const detectedLabel  = computed(() => {
    const m = codePrefixMap[detectedPrefix.value]
    if (!m) return ''
    return lang.value === 'ar' ? m.label.ar : m.label.en
})

function onCodeInput() {
    const code = form.value.code.trim()
    // Try 2-digit prefix first, then 1-digit
    const twoDigit = code.slice(0, 2)
    const oneDigit = code.slice(0, 1)
    const match = codePrefixMap[twoDigit] ?? codePrefixMap[oneDigit] ?? null
    if (match && code.length >= 2) {
        detectedPrefix.value      = codePrefixMap[twoDigit] ? twoDigit : oneDigit
        form.value.type           = match.type
        form.value.normal_balance = match.normal_balance
    } else {
        detectedPrefix.value = ''
    }
}

function openAddPanel(ctx) {
    form.value         = emptyForm()
    parentSearch.value = ''
    errors.value       = {}
    panelMode.value    = 'create'
    detectedPrefix.value = ''

    if (ctx && ctx.parentId) {
        // Coming from a repeater button or row + button — context has parentId and codePrefix
        panelContext.value              = ctx
        form.value.type                 = ctx.type
        form.value.normal_balance       = ctx.normal_balance
        form.value.parent_id            = ctx.parentId
        form.value.allow_direct_posting = true
        form.value.code                 = suggestCode(ctx.parentId, ctx.codePrefix)
        form.value.code_suffix          = form.value.code.slice(ctx.codePrefix.length)
    } else if (ctx && ctx.id) {
        // Coming from flat list + button on an account row
        panelContext.value = {
            label:          ctx.name_en ?? ctx.name_ar ?? '',
            labelAr:        ctx.name_ar ?? ctx.name_en ?? '',
            codePrefix:     String(ctx.code),
            type:           ctx.type,
            normal_balance: ctx.normal_balance,
            parentId:       ctx.id,
        }
        form.value.type                 = ctx.type
        form.value.normal_balance       = ctx.normal_balance
        form.value.parent_id            = ctx.id
        form.value.allow_direct_posting = true
        form.value.code                 = suggestCode(ctx.id, String(ctx.code))
    } else {
        // Free create from hero button — no context, user fills everything manually
        panelContext.value = null
    }

    panelOpen.value = true
}

function openEditPanel(account) {
    parentSearch.value = ''
    panelContext.value = null
    form.value = {
            id: account.id, code: account.code,
            code_suffix: (account.parent_id && account.parent?.code)
                ? account.code.slice(String(account.parent.code).length)
                : '',
            name_en: account.name_en ?? '', name_ar: account.name_ar ?? '',
        type: account.type, normal_balance: account.normal_balance,
        parent_id: account.parent_id ?? null,
        allow_direct_posting: !!account.allow_direct_posting,
        currency: account.currency ?? null,
        notes: account.notes ?? '',
    }
    errors.value    = {}
    panelMode.value = 'edit'
    panelOpen.value = true
}

function closePanel() { panelOpen.value = false; panelContext.value = null }

function saveAccount() {
    saving.value = true
    errors.value = {}
    const isEdit = panelMode.value === 'edit'
    router[isEdit ? 'put' : 'post'](
        isEdit ? route('accounts.update', form.value.id) : route('accounts.store'),
        form.value,
        {
            preserveScroll: true,
            onSuccess: () => { saving.value = false; panelOpen.value = false },
            onError:   (e) => { saving.value = false; errors.value = e },
        }
    )
}

function toggleActive(account) {
    router.patch(route('accounts.toggle-active', account.id), {}, { preserveScroll: true })
}

const deleteModal = ref({ open: false, account: null })
function confirmDelete(account) { deleteModal.value = { open: true, account } }
function doDelete() {
    router.delete(route('accounts.destroy', deleteModal.value.account.id), {
        preserveScroll: true,
        onSuccess: () => { deleteModal.value.open = false },
    })
}

function formatAmount(val) {
    return new Intl.NumberFormat(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(Number(val) || 0) + ' EGP'
}

function typeLabel(type) {
    return {
        asset:     t({ en: 'Asset',     ar: 'أصل' }),
        liability: t({ en: 'Liability', ar: 'التزام' }),
        equity:    t({ en: 'Equity',    ar: 'ملكية' }),
        revenue:   t({ en: 'Revenue',   ar: 'إيراد' }),
        cogs:      t({ en: 'COGS',      ar: 'تكلفة' }),
        expense:   t({ en: 'Expense',   ar: 'مصروف' }),
    }[type] ?? type
}

function typeBadgeClass(type) {
    return {
        asset: 'cv-badge-blue', liability: 'cv-badge-red', equity: 'cv-badge-green',
        revenue: 'cv-badge-green', cogs: 'cv-badge-amber', expense: 'cv-badge-red',
    }[type] ?? 'cv-badge-muted'
}
</script>

<style scoped>
/* ── Super admin company switcher bar ─────────────────────────────── */
.coa-company-bar { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; padding:0.625rem 1rem; background:var(--cv-bg-card); border:1px solid var(--cv-amber-border); border-radius:var(--cv-radius-md); font-size:0.8125rem; }
.coa-company-bar-label { color:var(--cv-amber-text); font-weight:600; white-space:nowrap; }
.coa-company-select { flex:1; max-width:280px; padding:0.3rem 0.6rem; font-size:0.8125rem; }
.coa-company-bar-count { margin-inline-start:auto; font-size:0.75rem; color:var(--cv-text-muted); white-space:nowrap; }

/* ── Context banner ──────────────────────────────────────────────── */
.coa-context-banner { display:flex; gap:0.75rem; align-items:flex-start; padding:0.875rem 1rem; background:var(--cv-green-dim); border:1px solid var(--cv-green-border); border-radius:var(--cv-radius); }
.coa-context-icon   { color:var(--cv-green-bright); flex-shrink:0; margin-top:0.1rem; }
.coa-context-body   { flex:1; }
.coa-context-eyebrow { font-size:0.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--cv-green-bright); opacity:0.75; }
.coa-context-value  { font-size:0.9rem; font-weight:700; color:var(--cv-green-bright); margin-top:0.15rem; }
.coa-context-pills  { display:flex; flex-wrap:wrap; gap:0.35rem; margin-top:0.5rem; }
.coa-pill           { font-size:0.6875rem; font-weight:700; padding:0.15rem 0.5rem; border-radius:20px; }
.coa-pill-type      { background:var(--cv-blue-light); color:var(--cv-blue-text); border:1px solid var(--cv-blue-border); }
.coa-pill-balance   { background:var(--cv-amber-light); color:var(--cv-amber-text); border:1px solid var(--cv-amber-border); }
.coa-pill-code      { background:var(--cv-bg-card-alt); color:var(--cv-text-muted); border:1px solid var(--cv-border); font-family:monospace; }
.coa-context-lock-note { display:flex; align-items:center; gap:0.35rem; margin-top:0.5rem; font-size:0.6875rem; color:var(--cv-green-bright); opacity:0.7; }

/* ── Locked rows ─────────────────────────────────────────────────── */
.coa-locked-classification { display:flex; flex-direction:column; gap:0.375rem; }
.coa-locked-row { display:flex; align-items:center; gap:0.5rem; font-size:0.8125rem; color:var(--cv-text-muted); padding:0.4rem 0.6rem; background:var(--cv-bg-card-alt); border-radius:var(--cv-radius); border:1px solid var(--cv-border); }
.coa-locked-row strong { color:var(--cv-text-primary); }

/* ── Code input mono ─────────────────────────────────────────────── */
.coa-code-mono { font-family:monospace; font-size:1rem; letter-spacing:0.05em; }
.coa-code-suggested-wrap { display:flex; align-items:center; gap:0.5rem; }
.coa-code-auto-badge { font-size:0.625rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; padding:0.15rem 0.45rem; background:var(--cv-amber-light); color:var(--cv-amber-text); border:1px solid var(--cv-amber-border); border-radius:10px; white-space:nowrap; }

/* ── Code guide cheat-sheet ──────────────────────────────────────── */
.coa-code-guide { margin-top:0.625rem; background:var(--cv-bg-card-alt); border:1px solid var(--cv-border); border-radius:var(--cv-radius); overflow:hidden; }
.coa-code-guide-title { display:flex; align-items:center; gap:0.35rem; padding:0.5rem 0.75rem; font-size:0.6875rem; font-weight:600; color:var(--cv-text-muted); border-bottom:1px solid var(--cv-border); }
.coa-guide-grid  { display:flex; flex-direction:column; }
.coa-guide-row   { display:grid; grid-template-columns:52px 1fr auto; gap:0.5rem; align-items:center; padding:0.3125rem 0.75rem; border-bottom:1px solid var(--cv-border); font-size:0.75rem; transition:background-color 0.1s ease; }
.coa-guide-row:last-child { border-bottom:none; }
.coa-guide-sub   { padding-inline-start:1.25rem; opacity:0.85; }
.coa-guide-active { background:var(--cv-green-dim) !important; opacity:1 !important; }
.coa-guide-prefix { font-family:monospace; font-weight:700; color:var(--cv-text-primary); }
.coa-guide-desc   { color:var(--cv-text-secondary); line-height:1.35; }
.coa-guide-badge  { font-size:0.6rem; font-weight:700; padding:0.1rem 0.4rem; border-radius:10px; white-space:nowrap; }
.coa-guide-asset  { background:var(--cv-blue-light);  color:var(--cv-blue-text);    border:1px solid var(--cv-blue-border); }
.coa-guide-liab   { background:var(--cv-danger-bg);   color:var(--cv-danger-text);  border:1px solid var(--cv-danger-border); }
.coa-guide-equity { background:var(--cv-green-dim);   color:var(--cv-green-bright); border:1px solid var(--cv-green-border); }
.coa-guide-rev    { background:var(--cv-green-dim);   color:var(--cv-green-bright); border:1px solid var(--cv-green-border); }
.coa-guide-cogs   { background:var(--cv-amber-light); color:var(--cv-amber-text);   border:1px solid var(--cv-amber-border); }
.coa-guide-exp    { background:var(--cv-danger-bg);   color:var(--cv-danger-text);  border:1px solid var(--cv-danger-border); }
.coa-detected-confirm { display:flex; align-items:center; gap:0.4rem; padding:0.5rem 0.75rem; font-size:0.75rem; color:var(--cv-green-bright); background:var(--cv-green-dim); border-top:1px solid var(--cv-green-border); }

.coa-currency-badge {
    font-size: 0.6rem; padding: 0.1rem 0.4rem;
    background: var(--cv-green-dim); border: 1px solid var(--cv-green-border);
    border-radius: 3px; color: var(--cv-green-bright); font-weight: 700;
    font-family: monospace; letter-spacing: 0.05em;
}

/* ── Tabs ─────────────────────────────────────────────────────────── */
.coa-tabs { display:flex; gap:0.375rem; margin-bottom:1.25rem; background:var(--cv-bg-card); border:1px solid var(--cv-border); border-radius:var(--cv-radius-md); padding:0.3125rem; width:fit-content; }
.coa-tab  { display:flex; align-items:center; gap:0.5rem; padding:0.5rem 1rem; border-radius:var(--cv-radius); font-size:0.8125rem; font-weight:500; color:var(--cv-text-muted); background:transparent; border:none; cursor:pointer; transition:var(--cv-transition); }
.coa-tab:hover  { color:var(--cv-text-secondary); background:var(--cv-bg-hover); }
.coa-tab.active { background:var(--cv-bg-card-alt); color:var(--cv-green-bright); border:1px solid var(--cv-green-border); box-shadow:var(--cv-shadow-sm); }

/* ── FS Block ─────────────────────────────────────────────────────── */
.coa-fs-block { background:var(--cv-bg-card); border:1px solid var(--cv-border); border-radius:var(--cv-radius-lg); overflow:hidden; }
.coa-fs-header { display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem; font-size:0.875rem; font-weight:700; letter-spacing:0.03em; border-bottom:1px solid var(--cv-border); }
.coa-fs-header-blue  { color:var(--cv-blue-text);    background:var(--cv-blue-light);  border-left:3px solid var(--cv-blue-text); }
.coa-fs-header-green { color:var(--cv-green-bright); background:var(--cv-green-dim);   border-left:3px solid var(--cv-green-bright); }
.coa-fs-total { margin-inline-start:auto; font-weight:400; font-size:0.8125rem; color:var(--cv-text-muted); }
.coa-fs-body  { padding:0.75rem; display:flex; flex-direction:column; gap:0.5rem; }

/* ── WIP row ──────────────────────────────────────────────────────── */
.coa-wip-row   { display:flex; align-items:center; gap:0.75rem; padding:0.4375rem 1rem 0.4375rem 1.5rem; background:rgba(92,196,106,0.05); border-bottom:1px solid var(--cv-green-border); }
.coa-wip-badge { font-size:0.6rem; font-weight:800; letter-spacing:0.08em; padding:0.125rem 0.4rem; background:var(--cv-green-dim); border:1px solid var(--cv-green-border); border-radius:4px; color:var(--cv-green-bright); }

/* ── BS Check ─────────────────────────────────────────────────────── */
.coa-bs-check     { display:flex; align-items:center; gap:0.5rem; padding:0.625rem 1rem; border-radius:var(--cv-radius); font-size:0.8125rem; font-weight:500; margin-top:0.25rem; }
.coa-bs-check-ok  { background:var(--cv-success-bg); color:var(--cv-success-text); border:1px solid var(--cv-success-border); }
.coa-bs-check-err { background:var(--cv-danger-bg);  color:var(--cv-danger-text);  border:1px solid var(--cv-danger-border); }

/* ── COGS note ────────────────────────────────────────────────────── */
.coa-cogs-note { display:flex; align-items:flex-start; gap:0.5rem; padding:0.625rem 1rem; background:var(--cv-amber-light); border-top:1px solid var(--cv-amber-border); font-size:0.75rem; color:var(--cv-amber-text); line-height:1.5; }

/* ── Net Income ───────────────────────────────────────────────────── */
.coa-net-income        { display:flex; justify-content:space-between; align-items:center; padding:0.875rem 1rem; border-radius:var(--cv-radius); font-size:0.9375rem; font-weight:700; margin-top:0.25rem; }
.coa-net-income-profit { background:var(--cv-green-dim); color:var(--cv-green-bright); border:1px solid var(--cv-green-border); }
.coa-net-income-loss   { background:var(--cv-danger-bg); color:var(--cv-danger-text);  border:1px solid var(--cv-danger-border); }
.coa-net-income-amount { font-size:1.0625rem; }

/* ── Amount colours ───────────────────────────────────────────────── */
.coa-amount-blue  { color:var(--cv-blue-text);    font-weight:600; }
.coa-amount-green { color:var(--cv-green-bright); font-weight:600; }
.coa-amount-red   { color:var(--cv-danger-text);  font-weight:600; }

/* ── Shared helpers ───────────────────────────────────────────────── */
.coa-code        { font-size:0.75rem; font-weight:600; color:var(--cv-text-muted); min-width:56px; font-family:monospace; }
.coa-name        { font-size:0.8125rem; color:var(--cv-text-primary); flex:1; display:flex; align-items:center; gap:0.375rem; }
.coa-row-actions { display:flex; gap:0.25rem; }
.coa-system-badge { font-size:0.6rem; padding:0.1rem 0.35rem; background:var(--cv-blue-light); border:1px solid var(--cv-blue-border); border-radius:3px; color:var(--cv-blue-text); font-weight:700; }
.coa-text-muted  { color:var(--cv-text-muted); font-size:0.75rem; }

/* ── Flat tab ─────────────────────────────────────────────────────── */
.coa-flat-toolbar { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; flex-wrap:wrap; }

.coa-filter-group { display:flex; gap:0.25rem; background:var(--cv-bg-card); border:1px solid var(--cv-border); border-radius:var(--cv-radius); padding:0.25rem; flex-wrap:wrap; }
.coa-filter-btn   { padding:0.3125rem 0.75rem; border-radius:var(--cv-radius-sm); border:none; background:transparent; color:var(--cv-text-muted); font-size:0.75rem; font-weight:500; cursor:pointer; transition:var(--cv-transition); white-space:nowrap; }
.coa-filter-btn:hover  { color:var(--cv-text-secondary); background:var(--cv-bg-hover); }
.coa-filter-btn.active { background:var(--cv-green-dim); color:var(--cv-green-bright); border:1px solid var(--cv-green-border); font-weight:700; }

.coa-flat-list   { background:var(--cv-bg-card); border:1px solid var(--cv-border); border-radius:var(--cv-radius-md); overflow:hidden; }
.coa-flat-header { display:grid; grid-template-columns:80px 1fr 100px 90px 160px 80px 110px; gap:0.75rem; padding:0.625rem 1rem; background:var(--cv-bg-card-alt); border-bottom:1px solid var(--cv-border); font-size:0.6875rem; font-weight:700; letter-spacing:0.07em; text-transform:uppercase; color:var(--cv-text-muted); }
.coa-flat-row    { display:grid; grid-template-columns:80px 1fr 100px 90px 160px 80px 110px; gap:0.75rem; padding:0.5625rem 1rem; border-bottom:1px solid var(--cv-border); font-size:0.8125rem; color:var(--cv-text-primary); align-items:center; transition:background-color 0.12s ease; }
.coa-flat-row:last-child  { border-bottom:none; }
.coa-flat-row:hover       { background:var(--cv-bg-hover); }
.coa-flat-row-inactive    { opacity:0.5; }
.coa-empty { display:flex; flex-direction:column; align-items:center; gap:0.75rem; padding:3rem; color:var(--cv-text-muted); font-size:0.875rem; }

/* ── Slide Panel ──────────────────────────────────────────────────── */
.coa-panel-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.55); backdrop-filter:blur(3px); z-index:400; display:flex; justify-content:flex-end; }
.coa-panel          { width:420px; max-width:95vw; height:100%; background:var(--cv-bg-card); border-inline-start:1px solid var(--cv-border-md); display:flex; flex-direction:column; box-shadow:var(--cv-shadow-md); }
.coa-panel-header   { display:flex; align-items:flex-start; justify-content:space-between; padding:1.25rem 1.25rem 1rem; border-bottom:1px solid var(--cv-border); flex-shrink:0; }
.coa-panel-eyebrow  { font-size:0.6875rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:var(--cv-amber-text); margin-bottom:0.25rem; }
.coa-panel-title    { font-size:1rem; font-weight:700; color:var(--cv-text-primary); }
.coa-panel-body     { flex:1; overflow-y:auto; padding:1.25rem; display:flex; flex-direction:column; gap:1rem; }
.coa-panel-footer   { display:flex; justify-content:flex-end; gap:0.75rem; padding:1rem 1.25rem; border-top:1px solid var(--cv-border); flex-shrink:0; }

.coa-panel-section       { background:var(--cv-bg-card-alt); border:1px solid var(--cv-border); border-radius:var(--cv-radius); padding:1rem; position:relative; padding-top:0.75rem; }
.coa-panel-section-num   { position:absolute; top:-0.625rem; inset-inline-start:1rem; width:1.25rem; height:1.25rem; border-radius:50%; background:linear-gradient(135deg,var(--cv-amber),var(--cv-amber-hover)); color:#0A1808; font-size:0.625rem; font-weight:800; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px rgba(232,160,48,0.3); }
.coa-panel-section-title { font-size:0.6875rem; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:var(--cv-text-muted); margin-bottom:0.75rem; margin-top:0.125rem; }

/* Parent picker */
.coa-parent-wrap   { border:1.5px solid var(--cv-border-input); border-radius:var(--cv-radius); overflow:hidden; }
.coa-parent-search { padding:0.375rem; border-bottom:1px solid var(--cv-border); }
.coa-parent-search .cv-input { border:none; background:transparent; padding:0.375rem 0.5rem; box-shadow:none; }
.coa-parent-list   { max-height:160px; overflow-y:auto; }
.coa-parent-opt    { padding:0.4375rem 0.75rem; font-size:0.8125rem; color:var(--cv-text-secondary); cursor:pointer; transition:background-color 0.1s ease; }
.coa-parent-opt:hover  { background:var(--cv-bg-hover); }
.coa-parent-opt.active { background:var(--cv-green-dim); color:var(--cv-green-bright); font-weight:600; }

/* Type button grid */
.coa-type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:0.5rem; margin-bottom:0.5rem; }
.coa-type-btn  { padding:0.5rem; border-radius:var(--cv-radius); border:1.5px solid var(--cv-border-input); background:var(--cv-bg-card); color:var(--cv-text-secondary); font-size:0.8125rem; font-weight:500; cursor:pointer; transition:var(--cv-transition); }
.coa-type-btn:hover  { border-color:var(--cv-border-md); color:var(--cv-text-primary); }
.coa-type-btn.active { border-color:var(--cv-green-bright); background:var(--cv-green-dim); color:var(--cv-green-bright); font-weight:700; }

.coa-required  { color:var(--cv-danger-text); }
.coa-type-hint { display:flex; align-items:center; gap:0.375rem; font-size:0.75rem; color:var(--cv-text-muted); margin-top:0.5rem; }

/* Normal balance radio */
.coa-radio-group { display:flex; gap:0.75rem; }
.coa-radio       { display:flex; align-items:center; gap:0.375rem; padding:0.5rem 1rem; border-radius:var(--cv-radius); border:1.5px solid var(--cv-border-input); background:var(--cv-bg-card); cursor:pointer; font-size:0.8125rem; color:var(--cv-text-secondary); transition:var(--cv-transition); flex:1; justify-content:center; }
.coa-radio input  { display:none; }
.coa-radio.active { border-color:var(--cv-green-bright); background:var(--cv-green-dim); color:var(--cv-green-bright); font-weight:600; }

/* Toggle */
.coa-toggle-row   { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
.coa-toggle-label { font-size:0.8125rem; font-weight:600; color:var(--cv-text-primary); }
.coa-toggle-sub   { font-size:0.6875rem; color:var(--cv-text-muted); margin-top:0.2rem; }
.coa-toggle-btn   { width:2.5rem; height:1.375rem; border-radius:0.6875rem; background:var(--cv-border-md); border:none; cursor:pointer; position:relative; flex-shrink:0; transition:background-color 0.2s ease; }
.coa-toggle-btn.active { background:var(--cv-green-bright); }
.coa-toggle-knob  { position:absolute; top:2px; left:2px; width:1rem; height:1rem; border-radius:50%; background:white; transition:transform 0.2s ease; display:block; }
.coa-toggle-btn.active .coa-toggle-knob { transform:translateX(1.125rem); }

/* Delete modal */
.coa-delete-icon  { width:3.5rem; height:3.5rem; border-radius:50%; background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; color:var(--cv-danger-text); }
.coa-delete-title { font-size:1rem; font-weight:700; color:var(--cv-text-primary); margin-bottom:0.5rem; }
.coa-delete-sub   { font-size:0.8125rem; color:var(--cv-text-muted); line-height:1.6; }

/* Spinner */
@keyframes coa-spin { to { transform:rotate(360deg); } }
.coa-spin { animation:coa-spin 0.8s linear infinite; }

/* Transitions */
.panel-enter-active { transition:transform 0.25s ease, opacity 0.2s ease; }
.panel-leave-active { transition:transform 0.2s ease, opacity 0.15s ease; }
.panel-enter-from, .panel-leave-to { transform:translateX(100%); opacity:0; }
[dir="rtl"] .panel-enter-from, [dir="rtl"] .panel-leave-to { transform:translateX(-100%); }
.modal-enter-active { transition:opacity 0.2s ease; }
.modal-leave-active { transition:opacity 0.15s ease; }
.modal-enter-from, .modal-leave-to { opacity:0; }
</style>