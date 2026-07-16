<template>
   <AuthenticatedLayout>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- ── Hero ─────────────────────────────────────────────────── -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">
        <Link :href="route('fixed-assets.index')" style="color: var(--cv-amber-text); text-decoration: none; display:inline-flex; align-items:center; gap:0.3rem; font-size:0.75rem">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
          {{ isRtl ? 'الأصول الثابتة' : 'Fixed Assets' }}
        </Link>
      </div>
      <div class="cv-page-hero-title" style="display:flex; align-items:center; gap: 0.75rem">
        <div class="hero-avatar" :style="{ background: categoryGradient(asset.category) }">
          <span v-html="categoryIcon(asset.category)" style="display:flex;align-items:center;justify-content:center;width:24px;height:24px"></span>
        </div>
        <span>{{ n(asset) }}</span>
        <span class="cv-badge" :class="statusBadgeClass(asset.status)" style="align-self:center">{{ statusLabel(asset.status) }}</span>
      </div>
      <div class="cv-page-hero-sub">
        <span style="color: var(--cv-amber-text)">{{ asset.asset_code }}</span>
        &nbsp;·&nbsp;{{ categoryLabel(asset.category) }}
        <span v-if="asset.farm">&nbsp;·&nbsp;{{ n(asset.farm) }}</span>
      </div>
      <div class="cv-page-hero-actions">
        <Link
          v-if="canEdit && asset.status !== 'disposed'"
          :href="route('fixed-assets.edit', asset.id)"
          class="cv-btn cv-btn-ghost"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          {{ isRtl ? 'تعديل' : 'Edit' }}
        </Link>
        <button
          v-if="canEdit && asset.status !== 'disposed' && asset.depreciation_method !== 'none'"
          class="cv-btn cv-btn-amber"
          @click="showDisposeModal = true"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
          {{ isRtl ? 'التصرف في الأصل' : 'Dispose Asset' }}
        </button>
      </div>
    </div>

    <div class="cv-page">

      <!-- ── KPI Strip ──────────────────────────────────────────── -->
      <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.875rem; margin-bottom: 1.5rem">
        <div class="cv-stat-card cv-stat-card-blue">
          <div class="cv-stat-card-label">{{ isRtl ? 'تكلفة الشراء' : 'Purchase Cost' }}</div>
          <div class="cv-stat-card-value">{{ fmtShort(asset.purchase_cost) }}</div>
          <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-amber">
          <div class="cv-stat-card-label">{{ isRtl ? 'الإهلاك المتراكم' : 'Accumulated Dep.' }}</div>
          <div class="cv-stat-card-value">{{ fmtShort(asset.accumulated_depreciation) }}</div>
          <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-blue">
          <div class="cv-stat-card-label">{{ isRtl ? 'القيمة الدفترية الصافية' : 'Net Book Value' }}</div>
          <div class="cv-stat-card-value">{{ fmtShort(asset.net_book_value) }}</div>
          <div class="cv-stat-card-sub">{{ isRtl ? 'ج.م' : 'EGP' }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-green">
          <div class="cv-stat-card-label">{{ isRtl ? 'نسبة الإهلاك' : '% Depreciated' }}</div>
          <div class="cv-stat-card-value">{{ depPct }}%</div>
          <div style="margin-top: 0.5rem">
            <div class="dep-bar-track">
              <div class="dep-bar-fill" :style="{ width: depPct + '%', background: depBarColor(depPct) }"></div>
            </div>
          </div>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem" class="info-grid">

        <!-- ── Asset Details ────────────────────────────────────── -->
        <div class="cv-card">
          <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.1em; color: var(--cv-text-muted); margin-bottom: 1rem">
            {{ isRtl ? 'تفاصيل الأصل' : 'Asset Details' }}
          </div>
          <div class="detail-grid">
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'تاريخ الشراء' : 'Purchase Date' }}</span>
              <span class="detail-value">{{ asset.purchase_date ?? '—' }}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'بدء الاستخدام' : 'In-Service Date' }}</span>
              <span class="detail-value">{{ asset.in_service_date ?? '—' }}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'طريقة الإهلاك' : 'Method' }}</span>
              <span class="detail-value">{{ methodLabel(asset.depreciation_method) }}</span>
            </div>
            <div v-if="asset.depreciation_method === 'straight_line'" class="detail-row">
              <span class="detail-label">{{ isRtl ? 'العمر الإنتاجي' : 'Useful Life' }}</span>
              <span class="detail-value">{{ asset.useful_life_months }} {{ isRtl ? 'شهر' : 'months' }}</span>
            </div>
            <div v-if="asset.depreciation_method === 'declining_balance'" class="detail-row">
              <span class="detail-label">{{ isRtl ? 'معدل الإهلاك السنوي' : 'Annual DB Rate' }}</span>
              <span class="detail-value">{{ Math.round(Number(asset.db_rate) * 100) }}%</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'قيمة الخردة' : 'Salvage Value' }}</span>
              <span class="detail-value" style="color: var(--cv-blue-text)">{{ fmt(asset.salvage_value) }} {{ isRtl ? 'ج.م' : 'EGP' }}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'وعاء الإهلاك' : 'Depreciable Base' }}</span>
              <span class="detail-value" style="color: var(--cv-blue-text)">{{ fmt(asset.depreciable_base) }} {{ isRtl ? 'ج.م' : 'EGP' }}</span>
            </div>
            <div v-if="asset.journal_entry" class="detail-row">
              <span class="detail-label">{{ isRtl ? 'قيد الشراء' : 'Purchase JE' }}</span>
              <span class="detail-value" style="color: var(--cv-info-text)">{{ asset.journal_entry.reference }}</span>
            </div>
          </div>
        </div>

        <!-- ── Accounts ──────────────────────────────────────────── -->
        <div class="cv-card">
          <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.1em; color: var(--cv-text-muted); margin-bottom: 1rem">
            {{ isRtl ? 'الحسابات المحاسبية' : 'Accounting Accounts' }}
          </div>
          <div class="detail-grid">
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'حساب الأصل' : 'Asset Account' }}</span>
              <span class="detail-value">
                <span v-if="asset.asset_account" style="color: var(--cv-info-text)">{{ asset.asset_account.code }}</span>
                {{ asset.asset_account ? '—' : '' }} {{ asset.asset_account ? n(asset.asset_account) : '—' }}
              </span>
            </div>
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'الإهلاك المتراكم' : 'Accum. Dep. Account' }}</span>
              <span class="detail-value">
                <span v-if="asset.acc_dep_account" style="color: var(--cv-info-text)">{{ asset.acc_dep_account?.code }}</span>
                {{ asset.acc_dep_account ? '—' : '' }} {{ asset.acc_dep_account ? n(asset.acc_dep_account) : '—' }}
              </span>
            </div>
            <div class="detail-row">
              <span class="detail-label">{{ isRtl ? 'مصروف الإهلاك' : 'Dep. Expense Account' }}</span>
              <span class="detail-value">
                <span v-if="asset.dep_expense_account" style="color: var(--cv-info-text)">{{ asset.dep_expense_account?.code }}</span>
                {{ asset.dep_expense_account ? '—' : '' }} {{ asset.dep_expense_account ? n(asset.dep_expense_account) : '—' }}
              </span>
            </div>
          </div>

          <!-- Generate Schedule form -->
          <div v-if="canEdit && asset.depreciation_method !== 'none' && !asset.is_fully_depreciated && asset.status !== 'disposed'" style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--cv-border)">
            <div style="font-size:0.75rem; font-weight:600; color: var(--cv-text-secondary); margin-bottom: 0.625rem">
              {{ isRtl ? 'توليد جدول الإهلاك' : 'Generate Depreciation Schedule' }}
            </div>
            <div class="flex gap-2 flex-wrap">
              <select v-model="scheduleForm.fiscal_year_id" class="cv-select" style="flex:1; min-width:150px">
                <option value="">{{ isRtl ? 'اختر السنة المالية' : 'Select fiscal year' }}</option>
                <option v-for="fy in fiscal_years" :key="fy.id" :value="fy.id">{{ fy.year }}</option>
              </select>
              <button
                class="cv-btn cv-btn-secondary cv-btn-sm"
                :class="{ 'cv-btn-loading': generatingSchedule }"
                :disabled="!scheduleForm.fiscal_year_id"
                @click="generateSchedule"
              >
                <svg v-if="!generatingSchedule" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                </svg>
                {{ isRtl ? 'توليد الجدول' : 'Generate Schedule' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Depreciation Schedule Table ───────────────────────── -->
      <div class="cv-card" v-if="asset.depreciation_method !== 'none'">
        <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.1em; color: var(--cv-text-muted); margin-bottom: 1rem; display:flex; align-items:center; justify-content:space-between">
          <span>{{ isRtl ? 'جدول الإهلاك' : 'Depreciation Schedule' }}</span>
          <span style="color: var(--cv-text-muted); font-weight:400">{{ asset.depreciation_lines?.length ?? 0 }} {{ isRtl ? 'سطر' : 'lines' }}</span>
        </div>

        <div v-if="!asset.depreciation_lines || asset.depreciation_lines.length === 0" style="text-align:center; padding: 2rem; color: var(--cv-text-muted); font-size: 0.8125rem">
          {{ isRtl ? 'لم يتم توليد جدول الإهلاك بعد. اختر السنة المالية وانقر "توليد الجدول".' : 'No depreciation schedule generated yet. Select a fiscal year above and click "Generate Schedule".' }}
        </div>

        <div v-else style="overflow-x: auto">
          <table class="cv-table" style="width: 100%; font-size: 0.8rem">
            <thead>
              <tr>
                <th>{{ isRtl ? 'السنة المالية' : 'Fiscal Year' }}</th>
                <th>{{ isRtl ? 'الشهر' : 'Month' }}</th>
                <th>{{ isRtl ? 'تاريخ الإهلاك' : 'Dep. Date' }}</th>
                <th style="text-align:end">{{ isRtl ? 'المبلغ (ج.م)' : 'Amount (EGP)' }}</th>
                <th style="text-align:end">{{ isRtl ? 'متراكم حتى الآن' : 'Accum. to Date' }}</th>
                <th>{{ isRtl ? 'الحالة' : 'Status' }}</th>
                <th>{{ isRtl ? 'قيد يومية' : 'Journal Entry' }}</th>
                <th v-if="canEdit">{{ isRtl ? 'إجراء' : 'Action' }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="line in asset.depreciation_lines"
                :key="line.id"
                :class="{ 'row-posted': line.is_posted, 'row-pending': !line.is_posted }"
              >
                <td style="color: var(--cv-text-muted)">{{ line.fiscal_year?.year ?? '—' }}</td>
                <td style="color: var(--cv-text-muted)">{{ isRtl ? monthAr(line.period_month) : monthEn(line.period_month) }}</td>
                <td style="color: var(--cv-text-secondary)">{{ line.depreciation_date }}</td>
                <td style="text-align:end; color: var(--cv-blue-text); font-weight:600">{{ fmt(line.amount) }}</td>
                <td style="text-align:end; color: var(--cv-text-secondary)">{{ fmt(line.accumulated_to_date) }}</td>
                <td>
                  <span class="cv-badge" :class="line.is_posted ? 'cv-badge-green' : 'cv-badge-amber'">
                    {{ line.is_posted ? (isRtl ? 'مُرحَّل' : 'Posted') : (isRtl ? 'معلق' : 'Pending') }}
                  </span>
                </td>
                <td>
                  <span v-if="line.journal_entry_ref" style="font-size:0.7rem; color: var(--cv-info-text)">{{ line.journal_entry_ref }}</span>
                  <span v-else style="color: var(--cv-text-muted)">—</span>
                </td>
                <td v-if="canEdit">
                  <button
                    v-if="!line.is_posted"
                    class="cv-btn cv-btn-amber cv-btn-sm"
                    :class="{ 'cv-btn-loading': postingLineId === line.id }"
                    @click="postPeriod(line)"
                  >
                    {{ isRtl ? 'ترحيل' : 'Post' }}
                  </button>
                  <span v-else style="color: var(--cv-text-muted); font-size: 0.7rem">✓</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- No depreciation note for land -->
      <div v-else class="cv-card" style="text-align:center; padding: 2rem; color: var(--cv-text-muted); font-size:0.85rem">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin: 0 auto 0.75rem; opacity:0.35">
          <circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        {{ isRtl ? 'هذا الأصل لا يُستهلك (مثل الأراضي).' : 'This asset is not depreciated (e.g. land).' }}
      </div>

    </div><!-- /cv-page -->

    <!-- ── Post Period Confirm Modal ──────────────────────────────── -->
    <Teleport to="body">
      <div v-if="postTarget" class="cv-modal-backdrop" @click.self="postTarget = null">
        <div class="cv-modal cv-modal-sm" role="dialog">
          <div class="cv-modal-header">
            <span style="font-weight:600; color: var(--cv-text-primary)">{{ isRtl ? 'تأكيد ترحيل قسط الإهلاك' : 'Confirm Depreciation Post' }}</span>
            <button class="cv-action-btn" @click="postTarget = null">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </div>
          <div class="cv-modal-body">
            <div style="display: flex; flex-direction: column; gap: 0.625rem; font-size: 0.8125rem">
              <div class="detail-row">
                <span class="detail-label">{{ isRtl ? 'السنة المالية' : 'Fiscal Year' }}</span>
                <span class="detail-value">{{ postTarget?.fiscal_year?.year }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">{{ isRtl ? 'الشهر' : 'Month' }}</span>
                <span class="detail-value">{{ isRtl ? monthAr(postTarget?.period_month) : monthEn(postTarget?.period_month) }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">{{ isRtl ? 'المبلغ' : 'Amount' }}</span>
                <span class="detail-value" style="color: var(--cv-blue-text); font-weight:700">{{ fmt(postTarget?.amount) }} {{ isRtl ? 'ج.م' : 'EGP' }}</span>
              </div>
            </div>
            <div style="margin-top:0.875rem; background: var(--cv-info-bg); border: 1px solid var(--cv-info-border); border-radius: var(--cv-radius-sm); padding: 0.625rem 0.875rem; font-size:0.75rem; color: var(--cv-info-text)">
              {{ isRtl ? 'سيتم إنشاء قيد يومية: مدين حساب مصروف الإهلاك / دائن حساب الإهلاك المتراكم.' : 'A journal entry will be created: Dr Depreciation Expense / Cr Accumulated Depreciation.' }}
            </div>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="postTarget = null">{{ isRtl ? 'إلغاء' : 'Cancel' }}</button>
            <button class="cv-btn cv-btn-amber cv-btn-sm" :class="{ 'cv-btn-loading': postingLineId }" @click="confirmPost">
              {{ isRtl ? 'تأكيد الترحيل' : 'Confirm Post' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── Dispose Modal ─────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showDisposeModal" class="cv-modal-backdrop" @click.self="showDisposeModal = false">
        <div class="cv-modal cv-modal-md" role="dialog">
          <div class="cv-modal-header">
            <div style="display:flex; align-items:center; gap: 0.625rem">
              <div style="width:2rem; height:2rem; border-radius:50%; background: var(--cv-danger-bg); border: 1px solid var(--cv-danger-border); display:flex; align-items:center; justify-content:center">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                </svg>
              </div>
              <span style="font-weight:600; color: var(--cv-text-primary)">{{ isRtl ? 'التصرف في الأصل' : 'Dispose Asset' }}</span>
            </div>
            <button class="cv-action-btn" @click="showDisposeModal = false">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </div>
          <div class="cv-modal-body">
            <div style="background: var(--cv-warning-bg); border: 1px solid var(--cv-warning-border); border-radius: var(--cv-radius); padding: 0.75rem 1rem; font-size: 0.8125rem; color: var(--cv-warning-text); margin-bottom: 1rem; display:flex; align-items:flex-start; gap:0.5rem">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
              </svg>
              <span>{{ isRtl ? 'هذا الإجراء لا يمكن التراجع عنه. سيتم إلغاء جميع أقساط الإهلاك المعلقة وترحيل قيد التصرف.' : 'This action cannot be undone. All pending depreciation lines will be cancelled and a disposal journal entry will be posted.' }}</span>
            </div>

            <!-- NBV summary -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.625rem; margin-bottom: 1rem">
              <div style="text-align:center; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); padding: 0.5rem">
                <div style="font-size:0.55rem; color: var(--cv-text-muted); text-transform:uppercase; letter-spacing:0.08em">{{ isRtl ? 'التكلفة' : 'Cost' }}</div>
                <div style="font-size:0.875rem; font-weight:700; color: var(--cv-blue-text)">{{ fmt(asset.purchase_cost) }}</div>
              </div>
              <div style="text-align:center; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); padding: 0.5rem">
                <div style="font-size:0.55rem; color: var(--cv-text-muted); text-transform:uppercase; letter-spacing:0.08em">{{ isRtl ? 'إهلاك متراكم' : 'Accum. Dep.' }}</div>
                <div style="font-size:0.875rem; font-weight:700; color: var(--cv-amber-text)">{{ fmt(asset.accumulated_depreciation) }}</div>
              </div>
              <div style="text-align:center; background: var(--cv-bg-card-alt); border-radius: var(--cv-radius-sm); padding: 0.5rem">
                <div style="font-size:0.55rem; color: var(--cv-text-muted); text-transform:uppercase; letter-spacing:0.08em">{{ isRtl ? 'ق.د.ص' : 'NBV' }}</div>
                <div style="font-size:0.875rem; font-weight:700; color: var(--cv-success-text)">{{ fmt(asset.net_book_value) }}</div>
              </div>
            </div>

            <div class="flex flex-col gap-3">
              <div class="cv-field" style="margin-bottom:0">
                <label class="cv-label">{{ isRtl ? 'تاريخ التصرف' : 'Disposal Date' }} <span style="color:var(--cv-danger-text)">*</span></label>
                <input v-model="disposeForm.disposal_date" type="date" class="cv-input" :class="{ 'cv-input-error': disposeForm.errors?.disposal_date }" />
                <div v-if="disposeForm.errors?.disposal_date" class="cv-field-error">{{ disposeForm.errors.disposal_date }}</div>
              </div>
              <div class="cv-field" style="margin-bottom:0">
                <label class="cv-label">{{ isRtl ? 'عائدات التصرف (ج.م)' : 'Disposal Proceeds (EGP)' }} <span style="color:var(--cv-danger-text)">*</span></label>
                <div class="cv-input-group">
                  <span class="cv-input-prefix">{{ isRtl ? 'ج.م' : 'EGP' }}</span>
                  <input v-model="disposeForm.disposal_proceeds" type="number" step="0.01" min="0" class="cv-input" :class="{ 'cv-input-error': disposeForm.errors?.disposal_proceeds }" :placeholder="isRtl ? '0.00 — أدخل 0 للإتلاف بدون عائد' : '0.00 — enter 0 for write-off'" />
                </div>
                <div v-if="disposeForm.errors?.disposal_proceeds" class="cv-field-error">{{ disposeForm.errors.disposal_proceeds }}</div>
                <!-- Gain/Loss preview -->
                <div v-if="disposeForm.disposal_proceeds !== '' && !isNaN(Number(disposeForm.disposal_proceeds))" style="margin-top:4px; font-size:0.75rem">
                  <span v-if="gainLossPreview > 0" style="color: var(--cv-success-text)">
                    ▲ {{ isRtl ? 'ربح من التصرف:' : 'Gain on disposal:' }} {{ fmt(gainLossPreview) }} {{ isRtl ? 'ج.م' : 'EGP' }}
                  </span>
                  <span v-else-if="gainLossPreview < 0" style="color: var(--cv-danger-text)">
                    ▼ {{ isRtl ? 'خسارة من التصرف:' : 'Loss on disposal:' }} {{ fmt(Math.abs(gainLossPreview)) }} {{ isRtl ? 'ج.م' : 'EGP' }}
                  </span>
                  <span v-else style="color: var(--cv-text-muted)">
                    {{ isRtl ? 'لا ربح ولا خسارة' : 'No gain or loss' }}
                  </span>
                </div>
              </div>
              <div class="cv-field" style="margin-bottom:0">
                <label class="cv-label">{{ isRtl ? 'ملاحظات' : 'Notes' }}</label>
                <textarea v-model="disposeForm.notes" class="cv-textarea" rows="2" :placeholder="isRtl ? 'سبب التصرف...' : 'Reason for disposal...'" style="resize:vertical"></textarea>
              </div>
            </div>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="showDisposeModal = false">{{ isRtl ? 'إلغاء' : 'Cancel' }}</button>
            <button class="cv-btn cv-btn-danger cv-btn-sm" :class="{ 'cv-btn-loading': disposeForm.processing }" @click="confirmDispose">
              {{ isRtl ? 'تأكيد التصرف' : 'Confirm Disposal' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const { locale, isRtl, n } = useLocale()

const props = defineProps({
  asset:        { type: Object, required: true },
  fiscal_years: { type: Array,  default: () => [] },
})

// ── Permissions ───────────────────────────────────────────────────
const { can } = usePermissions()
const canEdit = computed(() => can('edit fixed_assets'))

// ── Schedule form ─────────────────────────────────────────────────
const scheduleForm      = ref({ fiscal_year_id: '' })
const generatingSchedule = ref(false)

function generateSchedule() {
  if (!scheduleForm.value.fiscal_year_id) return
  generatingSchedule.value = true
  router.post(route('fixed-assets.schedule', props.asset.id), scheduleForm.value, {
    onFinish: () => { generatingSchedule.value = false },
  })
}

// ── Post period ───────────────────────────────────────────────────
const postTarget    = ref(null)
const postingLineId = ref(null)

function postPeriod(line) { postTarget.value = line }

function confirmPost() {
  if (!postTarget.value) return
  postingLineId.value = postTarget.value.id
  router.post(route('fixed-assets.post-period', props.asset.id), {
    fiscal_year_id: postTarget.value.fiscal_year_id,
    period_month:   postTarget.value.period_month,
  }, {
    onFinish: () => { postingLineId.value = null; postTarget.value = null },
  })
}

// ── Dispose ───────────────────────────────────────────────────────
const showDisposeModal = ref(false)
const disposeForm = useForm({
  disposal_date:     '',
  disposal_proceeds: '',
  notes:             '',
})

const gainLossPreview = computed(() => {
  const proceeds = Number(disposeForm.disposal_proceeds) || 0
  const nbv      = Number(props.asset.net_book_value)    || 0
  return proceeds - nbv
})

function confirmDispose() {
  disposeForm.post(route('fixed-assets.dispose', props.asset.id), {
    onSuccess: () => { showDisposeModal.value = false },
  })
}

// ── Computed helpers ──────────────────────────────────────────────
const depPct = computed(() => {
  const base = Number(props.asset.depreciable_base ?? 0)
  if (base <= 0) return 100
  return Math.min(100, Math.round((Number(props.asset.accumulated_depreciation ?? 0) / base) * 100))
})

function depBarColor(pct) {
  if (pct >= 100) return 'var(--cv-blue)'
  if (pct >= 70)  return 'var(--cv-amber)'
  return 'var(--cv-green)'
}

// ── Formatting ────────────────────────────────────────────────────
function fmt(v)      { return Number(v ?? 0).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) }
function fmtShort(v) {
  const num = Number(v ?? 0)
  if (num >= 1_000_000) return (num / 1_000_000).toFixed(1) + (isRtl.value ? ' م' : 'M')
  if (num >= 1_000)     return (num / 1_000).toFixed(1)     + (isRtl.value ? ' ك' : 'K')
  return num.toFixed(0)
}

// ── Month names ───────────────────────────────────────────────────
const monthsEn = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
const monthsAr = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر']
function monthEn(m) { return monthsEn[(Number(m) || 1) - 1] ?? '—' }
function monthAr(m) { return monthsAr[(Number(m) || 1) - 1] ?? '—' }

// ── Category helpers ──────────────────────────────────────────────
const categories = [
  { value: 'land',      en: 'Land',      ar: 'أرض' },
  { value: 'building',  en: 'Building',  ar: 'مبنى' },
  { value: 'vehicle',   en: 'Vehicle',   ar: 'مركبة' },
  { value: 'equipment', en: 'Equipment', ar: 'معدات' },
  { value: 'furniture', en: 'Furniture', ar: 'أثاث' },
  { value: 'other',     en: 'Other',     ar: 'أخرى' },
]

function categoryLabel(cat) {
  const c = categories.find(x => x.value === cat)
  return c ? (isRtl.value ? c.ar : c.en) : cat
}

function categoryGradient(cat) {
  const map = { land: 'linear-gradient(135deg, #1a3a20, #2d5a35)', building: 'linear-gradient(135deg, #1e2a40, #2a3f60)', vehicle: 'linear-gradient(135deg, #2a1e18, #4a3020)', equipment: 'linear-gradient(135deg, #2a2218, #4a3c20)', furniture: 'linear-gradient(135deg, #28182a, #452040)', other: 'linear-gradient(135deg, #1a2228, #263040)' }
  return map[cat] ?? map.other
}

function categoryIcon(cat) {
  const icons = {
    land:      `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-success-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>`,
    building:  `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-info-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>`,
    vehicle:   `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-warning-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    equipment: `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber-text)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 5.34 3.93 6.75M19.07 19.07l-1.41-1.41M5.34 18.66l-1.41 1.41M22 12h-2M4 12H2M12 22v-2M12 4V2"/></svg>`,
    furniture: `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#C070E0" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v3"/><path d="M2 11v5a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H6v-2a2 2 0 0 0-4 0z"/><path d="M4 18v2M20 18v2"/></svg>`,
    other:     `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cv-text-secondary)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>`,
  }
  return icons[cat] ?? icons.other
}

function statusBadgeClass(status) {
  return { active: 'cv-badge-green', fully_depreciated: 'cv-badge-info', impaired: 'cv-badge-amber', disposed: 'cv-badge-neutral' }[status] ?? 'cv-badge-neutral'
}

function statusLabel(status) {
  if (isRtl.value) return { active: 'نشط', fully_depreciated: 'مستهلك بالكامل', impaired: 'مُخفَّض القيمة', disposed: 'مُتصرف فيه' }[status] ?? status
  return { active: 'Active', fully_depreciated: 'Fully Dep.', impaired: 'Impaired', disposed: 'Disposed' }[status] ?? status
}

function methodLabel(m) {
  if (isRtl.value) return { straight_line: 'القسط الثابت', declining_balance: 'الرصيد المتناقص', none: 'لا يوجد إهلاك' }[m] ?? m
  return { straight_line: 'Straight-Line', declining_balance: 'Declining Balance', none: 'None' }[m] ?? m
}
</script>

<style scoped>
.hero-avatar {
  width: 3.25rem;
  height: 3.25rem;
  border-radius: var(--cv-radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid rgba(255,255,255,0.07);
}
.detail-grid { display: flex; flex-direction: column; gap: 0.625rem; }
.detail-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.5rem;
  font-size: 0.8rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--cv-border);
}
.detail-row:last-child { border-bottom: none; padding-bottom: 0; }
.detail-label { color: var(--cv-text-muted); flex-shrink: 0; }
.detail-value { color: var(--cv-text-secondary); text-align: end; }
.dep-bar-track {
  height: 5px;
  background: var(--cv-bg-card-alt);
  border-radius: 9999px;
  overflow: hidden;
  border: 1px solid var(--cv-border);
}
.dep-bar-fill {
  height: 100%;
  border-radius: 9999px;
  transition: width 0.4s ease;
}
.info-grid { grid-template-columns: 1fr 1fr; }
@media (max-width: 768px) {
  .info-grid { grid-template-columns: 1fr; }
}
/* Table row colouring */
.row-posted td { opacity: 0.6; }
.row-pending td { }
/* cv-textarea not in app.css */
.cv-textarea {
  width: 100%;
  background: var(--cv-bg-card-alt);
  border: 1.5px solid var(--cv-border-input);
  border-radius: var(--cv-radius);
  padding: 0.625rem 0.75rem;
  font-size: 0.8125rem;
  color: var(--cv-text-primary);
  font-family: inherit;
  transition: var(--cv-transition);
}
.cv-textarea:focus { outline: none; border-color: var(--cv-border-focus); box-shadow: var(--cv-shadow-focus); }
.cv-textarea::placeholder { color: var(--cv-text-placeholder); }
.cv-input-error { border-color: var(--cv-danger-border) !important; }
.cv-field-error { font-size: 0.7rem; color: var(--cv-danger-text); margin-top: 3px; }
</style>