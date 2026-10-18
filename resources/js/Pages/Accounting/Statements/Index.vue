<template>
  
    <!-- ══════════════════════════════════════════════════
         PAGE HERO
    ═══════════════════════════════════════════════════ -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">
        {{ lang === 'ar' ? 'تقارير مالية' : 'Financial Reports' }}
      </div>
      <div class="cv-page-hero-title">
        {{ lang === 'ar' ? 'كشوف الحسابات' : 'Account Statements' }}
      </div>
      <div class="cv-page-hero-sub">
        {{ lang === 'ar'
          ? 'كشف الأستاذ · كشف عميل · كشف مورد · كشف مخزون'
          : 'General Ledger · Customer Statement · Supplier Statement · Inventory Movement' }}
      </div>
    </div>

    <div class="cv-page">

      <!-- ── Tab Bar ────────────────────────────────────────────── -->
      <div class="stmt-tabs" :dir="isRtl ? 'rtl' : 'ltr'">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          class="stmt-tab"
          :class="{ active: activeTab === tab.key }"
          @click="switchTab(tab.key)"
        >
          <span class="stmt-tab-icon" v-html="tab.icon"></span>
          <span>{{ lang === 'ar' ? tab.labelAr : tab.labelEn }}</span>
        </button>
      </div>

      <!-- ══════════════════════════════════════════════
           TAB 1 — ACCOUNT LEDGER
      ═══════════════════════════════════════════════ -->
      <div v-if="activeTab === 'account-ledger'">
        <!-- Filter Card -->
        <div class="cv-card" style="margin-bottom:1.25rem">
          <div class="cv-card-body">
            <div class="stmt-filter-grid">
              <!-- SA company selector -->
              <div v-if="companies.length" class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الشركة' : 'Company' }}</label>
                <select class="cv-select" v-model="glFilters.company_id" @change="glFilters.account_id = ''">
                  <option v-for="c in companies" :key="c.id" :value="c.id">
                    {{ n(c) }}
                  </option>
                </select>
              </div>
              <!-- Account picker -->
              <div class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الحساب' : 'Account' }}</label>
                <select class="cv-select" v-model="glFilters.account_id">
                  <option value="">{{ lang === 'ar' ? '— اختر حساباً —' : '— Select account —' }}</option>
                  <optgroup
                    v-for="grp in accountGroups"
                    :key="grp.type"
                    :label="lang === 'ar' ? grp.labelAr : grp.labelEn"
                  >
                    <option v-for="a in grp.items" :key="a.id" :value="a.id">
                      {{ a.code }} — {{ n(a) }}
                    </option>
                  </optgroup>
                </select>
              </div>
              <!-- Date range -->
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'From' }}</label>
                <input type="date" class="cv-input" v-model="glFilters.date_from" :max="glFilters.date_to || today" />
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'To' }}</label>
                <input type="date" class="cv-input" v-model="glFilters.date_to" :min="glFilters.date_from" :max="today" />
              </div>
              <!-- Run button -->
              <div class="stmt-field stmt-field-btn">
                <button class="cv-btn cv-btn-amber" @click="runGl" :disabled="!glFilters.account_id || !glFilters.date_from || !glFilters.date_to">
                  <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                  {{ lang === 'ar' ? 'عرض الكشف' : 'Run Statement' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Results -->
        <template v-if="statement && statement.type === 'account-ledger'">
          <!-- Statement Header -->
          <div class="stmt-header-card cv-card">
            <div class="stmt-header-body">
              <div class="stmt-header-info">
                <div class="stmt-account-code">{{ statement.account.code }}</div>
                <div class="stmt-account-name">{{ n(statement.account) }}</div>
                <div class="stmt-account-period">
                  {{ formatDate(statement.date_from) }} — {{ formatDate(statement.date_to) }}
                </div>
              </div>
              <div class="stmt-summary-boxes">
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'الرصيد الافتتاحي' : 'Opening Balance' }}</div>
                  <div class="stmt-summary-value" :class="statement.opening < 0 ? 'danger' : 'blue'">
                    {{ fmt(statement.opening) }}
                  </div>
                </div>
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'إجمالي المدين' : 'Total Debit' }}</div>
                  <div class="stmt-summary-value blue">{{ fmt(statement.total_debit) }}</div>
                </div>
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'إجمالي الدائن' : 'Total Credit' }}</div>
                  <div class="stmt-summary-value blue">{{ fmt(statement.total_credit) }}</div>
                </div>
                <div class="stmt-summary-box closing">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'الرصيد الختامي' : 'Closing Balance' }}</div>
                  <div class="stmt-summary-value" :class="statement.closing < 0 ? 'danger' : 'green'">
                    {{ fmt(statement.closing) }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Lines Table -->
          <div class="cv-card">
            <div class="stmt-table-wrap">
              <table class="cv-table" :dir="isRtl ? 'rtl' : 'ltr'">
                <thead>
                  <tr>
                    <th>{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                    <th>{{ lang === 'ar' ? 'المرجع' : 'Reference' }}</th>
                    <th>{{ lang === 'ar' ? 'البيان' : 'Description' }}</th>
                    <th class="text-end">{{ lang === 'ar' ? 'مدين' : 'Debit' }}</th>
                    <th class="text-end">{{ lang === 'ar' ? 'دائن' : 'Credit' }}</th>
                    <th class="text-end">{{ lang === 'ar' ? 'الرصيد' : 'Balance' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <!-- Opening row -->
                  <tr class="stmt-opening-row">
                    <td colspan="5" class="stmt-opening-label">
                      {{ lang === 'ar' ? 'رصيد أول المدة' : 'Opening Balance' }}
                    </td>
                    <td class="text-end" :class="statement.opening < 0 ? 'cv-text-danger' : 'cv-text-blue'">
                      <strong>{{ fmt(statement.opening) }}</strong>
                    </td>
                  </tr>
                  <!-- Period lines -->
                  <tr v-for="(line, idx) in statement.lines" :key="idx">
                    <td class="stmt-date">{{ formatDate(line.date) }}</td>
                    <td class="stmt-ref">
                      <span class="cv-badge cv-badge-info" style="font-size:0.7rem">{{ line.reference || '—' }}</span>
                    </td>
                    <td class="stmt-desc">
                      <div>{{ line.memo || line.entry_description }}</div>
                      <div v-if="line.memo && line.entry_description !== line.memo" class="stmt-sub-desc">
                        {{ line.entry_description }}
                      </div>
                      <div v-if="line.foreign_currency && line.foreign_currency !== 'EGP'" class="stmt-fx">
                        {{ line.foreign_currency }} {{ fmtFx(line.foreign_amount) }}
                        @{{ line.exchange_rate }}
                      </div>
                    </td>
                    <td class="text-end stmt-debit">{{ line.debit ? fmt(line.debit) : '—' }}</td>
                    <td class="text-end stmt-credit">{{ line.credit ? fmt(line.credit) : '—' }}</td>
                    <td class="text-end" :class="line.running_balance < 0 ? 'cv-text-danger' : 'cv-text-blue'">
                      {{ fmt(line.running_balance) }}
                    </td>
                  </tr>
                  <!-- Empty state -->
                  <tr v-if="!statement.lines.length">
                    <td colspan="6" class="stmt-empty">
                      {{ lang === 'ar' ? 'لا توجد حركات في هذه الفترة' : 'No transactions in this period' }}
                    </td>
                  </tr>
                  <!-- Closing row -->
                  <tr class="stmt-closing-row">
                    <td colspan="3"><strong>{{ lang === 'ar' ? 'رصيد آخر المدة' : 'Closing Balance' }}</strong></td>
                    <td class="text-end"><strong>{{ fmt(statement.total_debit) }}</strong></td>
                    <td class="text-end"><strong>{{ fmt(statement.total_credit) }}</strong></td>
                    <td class="text-end" :class="statement.closing < 0 ? 'cv-text-danger' : 'cv-text-green'">
                      <strong>{{ fmt(statement.closing) }}</strong>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <!-- Prompt when no data yet -->
        <div v-else class="cv-empty" style="margin-top:3rem">
          <div class="cv-empty-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          </div>
          <div class="cv-empty-title">{{ lang === 'ar' ? 'اختر حساباً وفترة زمنية' : 'Select an account and date range' }}</div>
          <div class="cv-empty-sub">{{ lang === 'ar' ? 'سيظهر كشف الأستاذ هنا' : 'The general ledger statement will appear here' }}</div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════
           TAB 2 — CUSTOMER STATEMENT
      ═══════════════════════════════════════════════ -->
      <div v-if="activeTab === 'customer-statement'">
        <div class="cv-card" style="margin-bottom:1.25rem">
          <div class="cv-card-body">
            <div class="stmt-filter-grid">
              <div v-if="companies.length" class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الشركة' : 'Company' }}</label>
                <select class="cv-select" v-model="custFilters.company_id">
                  <option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c) }}</option>
                </select>
              </div>
              <div class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'العميل' : 'Customer' }}</label>
                <select class="cv-select" v-model="custFilters.customer_id">
                  <option value="">{{ lang === 'ar' ? '— اختر عميلاً —' : '— Select customer —' }}</option>
                  <option v-for="c in customers" :key="c.id" :value="c.id">
                    {{ n(c) }}{{ c.code ? ' (' + c.code + ')' : '' }}
                  </option>
                </select>
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'From' }}</label>
                <input type="date" class="cv-input" v-model="custFilters.date_from" :max="custFilters.date_to || today" />
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'To' }}</label>
                <input type="date" class="cv-input" v-model="custFilters.date_to" :min="custFilters.date_from" :max="today" />
              </div>
              <div class="stmt-field stmt-field-btn">
                <button class="cv-btn cv-btn-amber" @click="runCustomer" :disabled="!custFilters.customer_id || !custFilters.date_from || !custFilters.date_to">
                  <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                  {{ lang === 'ar' ? 'عرض الكشف' : 'Run Statement' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <template v-if="statement && statement.type === 'customer-statement'">
          <PartnerStatementView :statement="statement" :lang="lang" :isRtl="isRtl" :fmt="fmt" :formatDate="formatDate" />
        </template>
        <div v-else class="cv-empty" style="margin-top:3rem">
          <div class="cv-empty-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <div class="cv-empty-title">{{ lang === 'ar' ? 'اختر عميلاً وفترة زمنية' : 'Select a customer and date range' }}</div>
          <div class="cv-empty-sub">{{ lang === 'ar' ? 'سيظهر كشف الحساب هنا' : 'The customer statement will appear here' }}</div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════
           TAB 3 — SUPPLIER STATEMENT
      ═══════════════════════════════════════════════ -->
      <div v-if="activeTab === 'supplier-statement'">
        <div class="cv-card" style="margin-bottom:1.25rem">
          <div class="cv-card-body">
            <div class="stmt-filter-grid">
              <div v-if="companies.length" class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الشركة' : 'Company' }}</label>
                <select class="cv-select" v-model="suppFilters.company_id">
                  <option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c) }}</option>
                </select>
              </div>
              <div class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'المورد' : 'Supplier' }}</label>
                <select class="cv-select" v-model="suppFilters.supplier_id">
                  <option value="">{{ lang === 'ar' ? '— اختر مورداً —' : '— Select supplier —' }}</option>
                  <option v-for="s in suppliers" :key="s.id" :value="s.id">
                    {{ n(s) }}{{ s.code ? ' (' + s.code + ')' : '' }}
                  </option>
                </select>
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'From' }}</label>
                <input type="date" class="cv-input" v-model="suppFilters.date_from" :max="suppFilters.date_to || today" />
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'To' }}</label>
                <input type="date" class="cv-input" v-model="suppFilters.date_to" :min="suppFilters.date_from" :max="today" />
              </div>
              <div class="stmt-field stmt-field-btn">
                <button class="cv-btn cv-btn-amber" @click="runSupplier" :disabled="!suppFilters.supplier_id || !suppFilters.date_from || !suppFilters.date_to">
                  <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                  {{ lang === 'ar' ? 'عرض الكشف' : 'Run Statement' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <template v-if="statement && statement.type === 'supplier-statement'">
          <PartnerStatementView :statement="statement" :lang="lang" :isRtl="isRtl" :fmt="fmt" :formatDate="formatDate" />
        </template>
        <div v-else class="cv-empty" style="margin-top:3rem">
          <div class="cv-empty-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M12 12v4M8 12v4"/></svg>
          </div>
          <div class="cv-empty-title">{{ lang === 'ar' ? 'اختر مورداً وفترة زمنية' : 'Select a supplier and date range' }}</div>
          <div class="cv-empty-sub">{{ lang === 'ar' ? 'سيظهر كشف الحساب هنا' : 'The supplier statement will appear here' }}</div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════
           TAB 4 — INVENTORY STATEMENT
      ═══════════════════════════════════════════════ -->
      <div v-if="activeTab === 'inventory-statement'">
        <div class="cv-card" style="margin-bottom:1.25rem">
          <div class="cv-card-body">
            <div class="stmt-filter-grid">
              <div v-if="companies.length" class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الشركة' : 'Company' }}</label>
                <select class="cv-select" v-model="invFilters.company_id">
                  <option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c) }}</option>
                </select>
              </div>
              <div class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الصنف' : 'Item' }}</label>
                <select class="cv-select" v-model="invFilters.item_id" @change="invFilters.location_id = ''">
                  <option value="">{{ lang === 'ar' ? '— اختر صنفاً —' : '— Select item —' }}</option>
                  <optgroup
                    v-for="grp in inventoryGroups"
                    :key="grp.category"
                    :label="lang === 'ar' ? grp.labelAr : grp.labelEn"
                  >
                    <option v-for="item in grp.items" :key="item.id" :value="item.id">
                      {{ n(item) }}{{ item.sku ? ' (' + item.sku + ')' : '' }}
                    </option>
                  </optgroup>
                </select>
              </div>
              <div class="stmt-field stmt-field-wide">
                <label class="cv-label">{{ lang === 'ar' ? 'الموقع' : 'Location' }}</label>
                <select class="cv-select" v-model="invFilters.location_id">
                  <option value="">{{ lang === 'ar' ? 'جميع المواقع' : 'All locations' }}</option>
                  <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                    {{ n(loc) }}
                    <template v-if="loc.farm_name_en || loc.farm_name_ar">
                      — {{ lang === 'ar' ? (loc.farm_name_ar || loc.farm_name_en) : (loc.farm_name_en || loc.farm_name_ar) }}
                    </template>
                  </option>
                </select>
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'من تاريخ' : 'From' }}</label>
                <input type="date" class="cv-input" v-model="invFilters.date_from" :max="invFilters.date_to || today" />
              </div>
              <div class="stmt-field">
                <label class="cv-label">{{ lang === 'ar' ? 'إلى تاريخ' : 'To' }}</label>
                <input type="date" class="cv-input" v-model="invFilters.date_to" :min="invFilters.date_from" :max="today" />
              </div>
              <div class="stmt-field stmt-field-btn">
                <button class="cv-btn cv-btn-amber" @click="runInventory" :disabled="!invFilters.item_id || !invFilters.date_from || !invFilters.date_to">
                  <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                  {{ lang === 'ar' ? 'عرض الكشف' : 'Run Statement' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <template v-if="statement && statement.type === 'inventory-statement'">
          <!-- Header -->
          <div class="stmt-header-card cv-card">
            <div class="stmt-header-body">
              <div class="stmt-header-info">
                <div class="stmt-account-code">{{ statement.item.sku || statement.item.category }}</div>
                <div class="stmt-account-name">{{ n(statement.item) }}</div>
                <div class="stmt-account-period">
                  {{ lang === 'ar' ? 'الوحدة:' : 'Unit:' }} {{ statement.item.unit }}
                  &nbsp;·&nbsp;
                  <template v-if="statement.location">{{ n(statement.location) }}</template>
                  <template v-else>{{ lang === 'ar' ? 'جميع المواقع' : 'All locations' }}</template>
                  &nbsp;·&nbsp;
                  {{ formatDate(statement.date_from) }} — {{ formatDate(statement.date_to) }}
                </div>
              </div>
              <div class="stmt-summary-boxes">
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'كمية أول المدة' : 'Opening Qty' }}</div>
                  <div class="stmt-summary-value blue">{{ fmtQty(statement.opening_qty) }} {{ statement.item.unit }}</div>
                </div>
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'إجمالي الوارد' : 'Total In' }}</div>
                  <div class="stmt-summary-value green">{{ fmtQty(statement.total_qty_in) }}</div>
                </div>
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'إجمالي الصادر' : 'Total Out' }}</div>
                  <div class="stmt-summary-value amber">{{ fmtQty(statement.total_qty_out) }}</div>
                </div>
                <div class="stmt-summary-box closing">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'كمية آخر المدة' : 'Closing Qty' }}</div>
                  <div class="stmt-summary-value" :class="statement.closing_qty < 0 ? 'danger' : 'green'">
                    {{ fmtQty(statement.closing_qty) }} {{ statement.item.unit }}
                  </div>
                </div>
                <div class="stmt-summary-box">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'قيمة أول المدة' : 'Opening Value' }}</div>
                  <div class="stmt-summary-value blue">{{ fmt(statement.opening_value) }}</div>
                </div>
                <div class="stmt-summary-box closing">
                  <div class="stmt-summary-label">{{ lang === 'ar' ? 'قيمة آخر المدة' : 'Closing Value' }}</div>
                  <div class="stmt-summary-value" :class="statement.closing_value < 0 ? 'danger' : 'green'">
                    {{ fmt(statement.closing_value) }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Lines table -->
          <div class="cv-card">
            <div class="stmt-table-wrap">
              <table class="cv-table" :dir="isRtl ? 'rtl' : 'ltr'">
                <thead>
                  <tr>
                    <th>{{ lang === 'ar' ? 'التاريخ' : 'Date' }}</th>
                    <th>{{ lang === 'ar' ? 'النوع' : 'Type' }}</th>
                    <th>{{ lang === 'ar' ? 'المرجع / البيان' : 'Ref / Notes' }}</th>
                    <th v-if="!statement.location">{{ lang === 'ar' ? 'الموقع' : 'Location' }}</th>
                    <th class="text-end" style="color:var(--cv-success-text)">{{ lang === 'ar' ? 'وارد' : 'In' }}</th>
                    <th class="text-end" style="color:var(--cv-warning-text)">{{ lang === 'ar' ? 'صادر' : 'Out' }}</th>
                    <th class="text-end">{{ lang === 'ar' ? 'سعر الوحدة' : 'Unit Cost' }}</th>
                    <th class="text-end">{{ lang === 'ar' ? 'الرصيد (كمية)' : 'Balance (Qty)' }}</th>
                    <th class="text-end">{{ lang === 'ar' ? 'الرصيد (قيمة)' : 'Balance (Value)' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <!-- Opening row -->
                  <tr class="stmt-opening-row">
                    <td :colspan="statement.location ? 6 : 7" class="stmt-opening-label">
                      {{ lang === 'ar' ? 'رصيد أول المدة' : 'Opening Balance' }}
                    </td>
                    <td class="text-end blue"><strong>{{ fmtQty(statement.opening_qty) }}</strong></td>
                    <td class="text-end blue"><strong>{{ fmt(statement.opening_value) }}</strong></td>
                  </tr>
                  <!-- Lines -->
                  <tr v-for="(line, idx) in statement.lines" :key="idx">
                    <td class="stmt-date">{{ formatDate(line.date) }}</td>
                    <td>
                      <span class="cv-badge" :class="txTypeBadge(line.type)">{{ txTypeLabel(line.type) }}</span>
                    </td>
                    <td class="stmt-desc">
                      <div>{{ line.ref_no || '—' }}</div>
                      <div v-if="line.notes" class="stmt-sub-desc">{{ line.notes }}</div>
                      <div v-if="line.supplier_name_en || line.supplier_name_ar" class="stmt-sub-desc">
                        {{ lang === 'ar' ? (line.supplier_name_ar || line.supplier_name_en) : (line.supplier_name_en || line.supplier_name_ar) }}
                      </div>
                    </td>
                    <td v-if="!statement.location" class="stmt-date">
                      {{ lang === 'ar' ? (line.location_name_ar || line.location_name_en || '—') : (line.location_name_en || line.location_name_ar || '—') }}
                    </td>
                    <td class="text-end" style="color:var(--cv-success-text)">
                      {{ line.qty_in ? fmtQty(line.qty_in) : '—' }}
                    </td>
                    <td class="text-end" style="color:var(--cv-warning-text)">
                      {{ line.qty_out ? fmtQty(line.qty_out) : '—' }}
                    </td>
                    <td class="text-end cv-text-muted">{{ fmt(line.unit_cost, 4) }}</td>
                    <td class="text-end" :class="line.running_qty < 0 ? 'cv-text-danger' : 'cv-text-blue'">
                      {{ fmtQty(line.running_qty) }}
                    </td>
                    <td class="text-end" :class="line.running_value < 0 ? 'cv-text-danger' : 'cv-text-blue'">
                      {{ fmt(line.running_value) }}
                    </td>
                  </tr>
                  <!-- Empty -->
                  <tr v-if="!statement.lines.length">
                    <td :colspan="statement.location ? 8 : 9" class="stmt-empty">
                      {{ lang === 'ar' ? 'لا توجد حركات في هذه الفترة' : 'No movements in this period' }}
                    </td>
                  </tr>
                  <!-- Closing row -->
                  <tr class="stmt-closing-row">
                    <td :colspan="statement.location ? 3 : 4"><strong>{{ lang === 'ar' ? 'رصيد آخر المدة' : 'Closing Balance' }}</strong></td>
                    <td class="text-end" style="color:var(--cv-success-text)"><strong>{{ fmtQty(statement.total_qty_in) }}</strong></td>
                    <td class="text-end" style="color:var(--cv-warning-text)"><strong>{{ fmtQty(statement.total_qty_out) }}</strong></td>
                    <td></td>
                    <td class="text-end" :class="statement.closing_qty < 0 ? 'cv-text-danger' : 'cv-text-green'">
                      <strong>{{ fmtQty(statement.closing_qty) }}</strong>
                    </td>
                    <td class="text-end" :class="statement.closing_value < 0 ? 'cv-text-danger' : 'cv-text-green'">
                      <strong>{{ fmt(statement.closing_value) }}</strong>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <div v-else class="cv-empty" style="margin-top:3rem">
          <div class="cv-empty-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          </div>
          <div class="cv-empty-title">{{ lang === 'ar' ? 'اختر صنفاً وفترة زمنية' : 'Select an item and date range' }}</div>
          <div class="cv-empty-sub">{{ lang === 'ar' ? 'سيظهر كشف الحركة هنا' : 'The inventory movement statement will appear here' }}</div>
        </div>
      </div>

    </div><!-- /cv-page -->
  
</template>

<script setup>
import { ref, computed, defineComponent, h } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

defineOptions({ layout: AuthenticatedLayout })

// ── Locale ────────────────────────────────────────────────────────
const { locale: lang, isRtl, n, today } = useLocale()

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
  statement:      { type: Object,  default: null },
  filters:        { type: Object,  default: () => ({}) },
  activeTab:      { type: String,  default: 'account-ledger' },
  companies:      { type: Array,   default: () => [] },
  accounts:       { type: Array,   default: () => [] },
  customers:      { type: Array,   default: () => [] },
  suppliers:      { type: Array,   default: () => [] },
  inventoryItems: { type: Array,   default: () => [] },
  locations:      { type: Array,   default: () => [] },
})

// ── Tab definitions ───────────────────────────────────────────────
const tabs = [
  {
    key: 'account-ledger',
    labelEn: 'Account Ledger',
    labelAr: 'كشف الأستاذ',
    icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
  },
  {
    key: 'customer-statement',
    labelEn: 'Customer Statement',
    labelAr: 'كشف عميل',
    icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  },
  {
    key: 'supplier-statement',
    labelEn: 'Supplier Statement',
    labelAr: 'كشف مورد',
    icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/></svg>',
  },
  {
    key: 'inventory-statement',
    labelEn: 'Inventory Statement',
    labelAr: 'كشف مخزون',
    icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
  },
]

// ── Active tab ────────────────────────────────────────────────────
const activeTab = ref(props.activeTab)

function switchTab(key) {
  activeTab.value = key
  router.get(route('statements.index'), { tab: key }, { preserveState: true, preserveScroll: true, replace: true })
}

// ── Filter state ──────────────────────────────────────────────────
const page = usePage()
const defaultCompany = computed(() => props.companies[0]?.id ?? page.props.auth?.user?.company_id ?? '')

const glFilters = ref({
  company_id: props.filters.company_id || defaultCompany.value,
  account_id: props.filters.account_id || '',
  date_from:  props.filters.date_from  || '',
  date_to:    props.filters.date_to    || '',
})

const custFilters = ref({
  company_id:  props.filters.company_id  || defaultCompany.value,
  customer_id: props.filters.customer_id || '',
  date_from:   props.filters.date_from   || '',
  date_to:     props.filters.date_to     || '',
})

const suppFilters = ref({
  company_id:  props.filters.company_id  || defaultCompany.value,
  supplier_id: props.filters.supplier_id || '',
  date_from:   props.filters.date_from   || '',
  date_to:     props.filters.date_to     || '',
})

const invFilters = ref({
  company_id:  props.filters.company_id  || defaultCompany.value,
  item_id:     props.filters.item_id     || '',
  location_id: props.filters.location_id || '',
  date_from:   props.filters.date_from   || '',
  date_to:     props.filters.date_to     || '',
})

// ── Run actions ───────────────────────────────────────────────────
function runGl() {
  router.get(route('statements.account-ledger'), glFilters.value, { preserveScroll: true })
}
function runCustomer() {
  router.get(route('statements.customer-statement'), custFilters.value, { preserveScroll: true })
}
function runSupplier() {
  router.get(route('statements.supplier-statement'), suppFilters.value, { preserveScroll: true })
}
function runInventory() {
  const params = { ...invFilters.value }
  if (!params.location_id) delete params.location_id
  router.get(route('statements.inventory-statement'), params, { preserveScroll: true })
}

// ── Account grouped options ───────────────────────────────────────
const typeOrder = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense']
const typeLabels = {
  asset:     { en: 'Assets',      ar: 'الأصول' },
  liability: { en: 'Liabilities', ar: 'الالتزامات' },
  equity:    { en: 'Equity',      ar: 'حقوق الملكية' },
  revenue:   { en: 'Revenue',     ar: 'الإيرادات' },
  cogs:      { en: 'COGS',        ar: 'تكلفة البضاعة' },
  expense:   { en: 'Expenses',    ar: 'المصروفات' },
}

const accountGroups = computed(() => {
  const map = {}
  for (const a of props.accounts) {
    if (!map[a.type]) map[a.type] = []
    map[a.type].push(a)
  }
  return typeOrder
    .filter(t => map[t]?.length)
    .map(t => ({
      type:    t,
      labelEn: typeLabels[t].en,
      labelAr: typeLabels[t].ar,
      items:   map[t],
    }))
})

// ── Inventory grouped options ─────────────────────────────────────
const categoryLabels = {
  feed:      { en: 'Feed',        ar: 'أعلاف' },
  medicine:  { en: 'Medicine',    ar: 'أدوية' },
  vaccine:   { en: 'Vaccines',    ar: 'لقاحات' },
  equipment: { en: 'Equipment',   ar: 'معدات' },
  other:     { en: 'Other',       ar: 'أخرى' },
}

const inventoryGroups = computed(() => {
  const map = {}
  for (const item of props.inventoryItems) {
    const cat = item.category || 'other'
    if (!map[cat]) map[cat] = []
    map[cat].push(item)
  }
  return Object.keys(map).map(cat => ({
    category: cat,
    labelEn: categoryLabels[cat]?.en || cat,
    labelAr: categoryLabels[cat]?.ar || cat,
    items:   map[cat],
  }))
})

// ── Formatters ────────────────────────────────────────────────────
function fmt(val, decimals = 2) {
  if (val === null || val === undefined) return '—'
  const num = Number(val)
  return new Intl.NumberFormat(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num)
}

function fmtQty(val) {
  if (val === null || val === undefined) return '—'
  const num = Number(val)
  // Show up to 3 decimals but trim trailing zeros
  return new Intl.NumberFormat(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
  }).format(num)
}

function fmtFx(val) {
  return fmt(val, 4)
}

function formatDate(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return dt.toLocaleDateString(lang.value === 'ar' ? 'ar-EG' : 'en-GB', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

// ── Transaction type labels and badge classes ─────────────────────
const txLabels = {
  receipt:           { en: 'Receipt',     ar: 'استلام' },
  consumption:       { en: 'Consumption', ar: 'صرف' },
  adjustment:        { en: 'Adjustment',  ar: 'تعديل' },
  transfer_in:       { en: 'Transfer In', ar: 'تحويل وارد' },
  transfer_out:      { en: 'Transfer Out', ar: 'تحويل صادر' },
  livestock_receipt: { en: 'Livestock Receipt', ar: 'استلام ماشية' },
  mix_out:           { en: 'Mix Out (to WIP)', ar: 'صرف للخلط' },
  mix_in:            { en: 'Mix In', ar: 'وارد من الخلط' },
}

function txTypeLabel(type) {
  const labels = txLabels[type]
  if (!labels) return type
  return lang.value === 'ar' ? labels.ar : labels.en
}

function txTypeBadge(type) {
  const map = {
    receipt:      'cv-badge-success',
    consumption:  'cv-badge-warning',
    adjustment:   'cv-badge-info',
    transfer_in:  'cv-badge-success',
    transfer_out: 'cv-badge-warning',
    mix_out:      'cv-badge-warning',
    mix_in:       'cv-badge-success',
  }
  return map[type] || 'cv-badge-neutral'
}

// ── PartnerStatementView — render-function component ─────────────
// Defined here (not a separate file) to avoid Vite runtime-compiler
// requirement. Uses h() directly — no string template. (Rule §4.4)
const PartnerStatementView = defineComponent({
  name: 'PartnerStatementView',
  props: {
    statement:  Object,
    lang:       Object, // locale ref
    isRtl:      Object,
    fmt:        Function,
    formatDate: Function,
  },
  setup(props) {
    return () => {
      const s   = props.statement
      const l   = props.lang?.value ?? 'en'
      const fmt = props.fmt
      const fd  = props.formatDate
      const rtl = props.isRtl?.value ?? false

      if (!s) return null

      const isCustomer = s.type === 'customer-statement'

      const headerCard = h('div', { class: 'stmt-header-card cv-card' }, [
        h('div', { class: 'stmt-header-body' }, [
          h('div', { class: 'stmt-header-info' }, [
            h('div', { class: 'stmt-account-code' }, s.partner.code || (isCustomer ? (l === 'ar' ? 'عميل' : 'Customer') : (l === 'ar' ? 'مورد' : 'Supplier'))),
            h('div', { class: 'stmt-account-name' }, l === 'ar' ? (s.partner.name_ar || s.partner.name_en) : (s.partner.name_en || s.partner.name_ar)),
            h('div', { class: 'stmt-account-period' }, `${fd(s.date_from)} — ${fd(s.date_to)}`),
            s.partner.phone ? h('div', { class: 'stmt-sub-desc', style: 'margin-top:0.25rem' }, `📞 ${s.partner.phone}`) : null,
            s.partner.payment_terms_days ? h('div', { class: 'stmt-sub-desc' },
              `${l === 'ar' ? 'شروط الدفع:' : 'Payment terms:'} ${s.partner.payment_terms_days} ${l === 'ar' ? 'يوم' : 'days'}`
            ) : null,
          ]),
          h('div', { class: 'stmt-summary-boxes' }, [
            h('div', { class: 'stmt-summary-box' }, [
              h('div', { class: 'stmt-summary-label' }, l === 'ar' ? 'الرصيد الافتتاحي' : 'Opening Balance'),
              h('div', { class: `stmt-summary-value ${s.opening < 0 ? 'danger' : 'blue'}` }, fmt(s.opening)),
            ]),
            h('div', { class: 'stmt-summary-box' }, [
              h('div', { class: 'stmt-summary-label' }, l === 'ar' ? (isCustomer ? 'إجمالي الفواتير' : 'إجمالي الفواتير') : 'Total Invoiced'),
              h('div', { class: 'stmt-summary-value blue' }, fmt(s.total_invoiced)),
            ]),
            h('div', { class: 'stmt-summary-box' }, [
              h('div', { class: 'stmt-summary-label' }, l === 'ar' ? (isCustomer ? 'إجمالي التحصيل' : 'إجمالي المدفوع') : (isCustomer ? 'Total Collected' : 'Total Paid')),
              h('div', { class: 'stmt-summary-value green' }, fmt(s.total_paid)),
            ]),
            h('div', { class: 'stmt-summary-box closing' }, [
              h('div', { class: 'stmt-summary-label' }, l === 'ar' ? 'الرصيد الختامي' : 'Closing Balance'),
              h('div', { class: `stmt-summary-value ${s.closing < 0 ? 'danger' : (s.closing > 0 ? 'amber' : 'green')}` }, fmt(s.closing)),
            ]),
          ]),
        ]),
      ])

      const invoiceLabel = l === 'ar' ? (isCustomer ? 'فاتورة بيع' : 'فاتورة شراء') : (isCustomer ? 'Invoice' : 'Invoice')
      const paymentLabel = l === 'ar' ? (isCustomer ? 'تحصيل' : 'دفع') : (isCustomer ? 'Collection' : 'Payment')

      const tableRows = s.rows.map((row, idx) => {
        const isInvoice = row.row_type === 'invoice'
        const badgeClass = isInvoice ? 'cv-badge-info' : 'cv-badge-success'
        const typeText   = isInvoice ? invoiceLabel  : paymentLabel

        return h('tr', { key: idx }, [
          h('td', { class: 'stmt-date' }, fd(row.date)),
          h('td', {}, h('span', { class: `cv-badge ${badgeClass}`, style: 'font-size:0.7rem' }, typeText)),
          h('td', { class: 'stmt-ref' }, h('span', { class: 'cv-badge cv-badge-info', style: 'font-size:0.7rem' }, row.reference || '—')),
          h('td', { class: 'stmt-desc' }, [
            row.memo ? h('div', {}, row.memo) : null,
            isInvoice && row.due_date ? h('div', { class: 'stmt-sub-desc' },
              `${l === 'ar' ? 'تاريخ الاستحقاق:' : 'Due:'} ${fd(row.due_date)}`
            ) : null,
            !isInvoice && row.method ? h('div', { class: 'stmt-sub-desc' },
              `${l === 'ar' ? 'طريقة الدفع:' : 'Method:'} ${row.method}`
            ) : null,
          ]),
          h('td', { class: 'text-end', style: 'color:var(--cv-blue-text)' },
            isInvoice ? fmt(row.invoice_amount) : '—'
          ),
          h('td', { class: 'text-end', style: 'color:var(--cv-success-text)' },
            !isInvoice ? fmt(row.payment_amount) : '—'
          ),
          h('td', {
            class: `text-end ${row.running_balance < 0 ? 'cv-text-danger' : row.running_balance > 0 ? 'cv-text-amber' : 'cv-text-green'}`,
          }, fmt(row.running_balance)),
        ])
      })

      const openingRow = h('tr', { class: 'stmt-opening-row' }, [
        h('td', { colspan: 4, class: 'stmt-opening-label' }, l === 'ar' ? 'رصيد أول المدة' : 'Opening Balance'),
        h('td', { class: 'text-end' }, ''),
        h('td', { class: 'text-end' }, ''),
        h('td', { class: `text-end ${s.opening < 0 ? 'cv-text-danger' : 'cv-text-blue'}` },
          h('strong', {}, fmt(s.opening))
        ),
      ])

      const emptyRow = s.rows.length === 0
        ? h('tr', {}, [h('td', { colspan: 7, class: 'stmt-empty' }, l === 'ar' ? 'لا توجد حركات في هذه الفترة' : 'No transactions in this period')])
        : null

      const closingRow = h('tr', { class: 'stmt-closing-row' }, [
        h('td', { colspan: 3 }, h('strong', {}, l === 'ar' ? 'رصيد آخر المدة' : 'Closing Balance')),
        h('td', {}),
        h('td', { class: 'text-end' }, h('strong', {}, fmt(s.total_invoiced))),
        h('td', { class: 'text-end' }, h('strong', {}, fmt(s.total_paid))),
        h('td', { class: `text-end ${s.closing < 0 ? 'cv-text-danger' : 'cv-text-green'}` },
          h('strong', {}, fmt(s.closing))
        ),
      ])

      const tableCard = h('div', { class: 'cv-card' }, [
        h('div', { class: 'stmt-table-wrap' }, [
          h('table', { class: 'cv-table', dir: rtl ? 'rtl' : 'ltr' }, [
            h('thead', {}, [
              h('tr', {}, [
                h('th', {}, l === 'ar' ? 'التاريخ' : 'Date'),
                h('th', {}, l === 'ar' ? 'النوع' : 'Type'),
                h('th', {}, l === 'ar' ? 'المرجع' : 'Reference'),
                h('th', {}, l === 'ar' ? 'البيان' : 'Description'),
                h('th', { class: 'text-end', style: 'color:var(--cv-blue-text)' }, l === 'ar' ? (isCustomer ? 'مدين (فاتورة)' : 'دائن (فاتورة)') : 'Invoiced'),
                h('th', { class: 'text-end', style: 'color:var(--cv-success-text)' }, l === 'ar' ? (isCustomer ? 'دائن (تحصيل)' : 'مدين (دفع)') : (isCustomer ? 'Collected' : 'Paid')),
                h('th', { class: 'text-end' }, l === 'ar' ? 'الرصيد' : 'Balance'),
              ]),
            ]),
            h('tbody', {}, [openingRow, ...tableRows, emptyRow, closingRow].filter(Boolean)),
          ]),
        ]),
      ])

      return h('div', {}, [headerCard, tableCard])
    }
  },
})


</script>

<style>
/* ── Statement-specific styles — NEW classes only, never override cv- globals ── */

.stmt-tabs {
  display: flex;
  gap: 0.25rem;
  background: var(--cv-bg-card);
  border: 1px solid var(--cv-border-md);
  border-radius: var(--cv-radius-md);
  padding: 0.3rem;
  margin-bottom: 1.5rem;
  overflow-x: auto;
  flex-wrap: wrap;
}

.stmt-tab {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.5rem 1rem;
  border-radius: var(--cv-radius);
  border: 1px solid transparent;
  background: transparent;
  color: var(--cv-text-secondary);
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  transition: var(--cv-transition);
  white-space: nowrap;
  font-family: inherit;
}
.stmt-tab:hover {
  background: var(--cv-bg-hover);
  color: var(--cv-text-primary);
}
.stmt-tab.active {
  background: var(--cv-amber-light);
  border-color: var(--cv-amber-border);
  color: var(--cv-amber);
}
.stmt-tab-icon { display: flex; align-items: center; }

/* ── Filter grid ── */
.stmt-filter-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.875rem;
  align-items: flex-end;
}
.stmt-field        { display: flex; flex-direction: column; gap: 0.3rem; min-width: 160px; flex: 1; }
.stmt-field-wide   { min-width: 220px; flex: 2; }
.stmt-field-btn    { min-width: 140px; flex: none; }

/* ── Statement header card ── */
.stmt-header-card  { margin-bottom: 1rem; }
.stmt-header-body  {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  align-items: flex-start;
  padding: 1.25rem;
}
.stmt-header-info  { flex: 1; min-width: 200px; }
.stmt-account-code {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--cv-amber);
  margin-bottom: 0.2rem;
}
.stmt-account-name {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--cv-text-primary);
  margin-bottom: 0.25rem;
}
.stmt-account-period {
  font-size: 0.8125rem;
  color: var(--cv-text-muted);
}

/* ── Summary boxes row ── */
.stmt-summary-boxes {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  flex: 2;
}
.stmt-summary-box {
  background: var(--cv-bg-card-alt);
  border: 1px solid var(--cv-border);
  border-radius: var(--cv-radius);
  padding: 0.75rem 1rem;
  min-width: 120px;
  flex: 1;
}
.stmt-summary-box.closing {
  border-color: var(--cv-green-border);
  background: var(--cv-green-dim);
}
.stmt-summary-label {
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cv-text-muted);
  margin-bottom: 0.3rem;
}
.stmt-summary-value {
  font-size: 1.0625rem;
  font-weight: 700;
}
.stmt-summary-value.blue   { color: var(--cv-blue-text); }
.stmt-summary-value.green  { color: var(--cv-green-bright); }
.stmt-summary-value.amber  { color: var(--cv-amber); }
.stmt-summary-value.danger { color: var(--cv-danger-text); }

/* ── Table wrap (horizontal scroll) ── */
.stmt-table-wrap { overflow-x: auto; }

/* ── Special rows ── */
.stmt-opening-row td,
.stmt-closing-row td {
  background: var(--cv-bg-card-alt);
  font-size: 0.8125rem;
}
.stmt-opening-row td { color: var(--cv-text-secondary); }
.stmt-closing-row td { border-top: 2px solid var(--cv-border-md); }
.stmt-opening-label  { color: var(--cv-text-muted); font-style: italic; }

/* ── Cell types ── */
.stmt-date   { font-size: 0.8rem; color: var(--cv-text-muted); white-space: nowrap; }
.stmt-ref    { white-space: nowrap; }
.stmt-desc   { font-size: 0.8125rem; max-width: 320px; }
.stmt-sub-desc { font-size: 0.75rem; color: var(--cv-text-muted); margin-top: 0.125rem; }
.stmt-fx     { font-size: 0.7rem; color: var(--cv-info-text); margin-top: 0.125rem; }
.stmt-debit  { color: var(--cv-blue-text); }
.stmt-credit { color: var(--cv-success-text); }
.stmt-empty  { text-align: center; padding: 2rem; color: var(--cv-text-muted); font-style: italic; }

/* ── Colour helpers (additive — not overriding cv- globals) ── */
.cv-text-blue   { color: var(--cv-blue-text); }
.cv-text-green  { color: var(--cv-green-bright); }
.cv-text-amber  { color: var(--cv-amber); }
.cv-text-danger { color: var(--cv-danger-text); }
.cv-text-muted  { color: var(--cv-text-muted); }
</style>