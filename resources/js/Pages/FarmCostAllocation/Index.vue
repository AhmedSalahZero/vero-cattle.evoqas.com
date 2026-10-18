<template>
  
    <div :dir="isRtl ? 'rtl' : 'ltr'">

      <!-- ── Hero ─────────────────────────────────────────────── -->
      <div class="cv-page-hero">
        <div class="cv-page-hero-eyebrow">
          {{ isRtl ? 'المحاسبة' : 'Accounting' }}
        </div>
        <div class="cv-page-hero-title">
          {{ isRtl ? 'تخصيص تكاليف المزرعة' : 'Farm Cost Allocation' }}
        </div>
        <div class="cv-page-hero-sub">
          {{ isRtl
            ? 'تكاليف العمالة والتكاليف العامة الشهرية — تخصيص تلقائي على الماشية'
            : 'Monthly labor and overhead costs — automatically absorbed into livestock WIP' }}
        </div>
        <div class="cv-page-hero-actions">
          <Link :href="route('farm-cost-allocations.create')" class="cv-btn cv-btn-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            {{ isRtl ? 'تسجيل شهر جديد' : 'New Month Record' }}
          </Link>
          <Link :href="route('farm-cost-allocations.overhead-types.index')" class="cv-btn cv-btn-ghost">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
              <circle cx="12" cy="12" r="3"/>
              <path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/>
            </svg>
            {{ isRtl ? 'أنواع التكاليف العامة' : 'Overhead Types' }}
          </Link>
        </div>
      </div>

      <div class="cv-page">

        <!-- Flash -->
        <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               style="width:16px;height:16px;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
          {{ $page.props.flash.success }}
        </div>
        <div v-if="$page.props.flash?.error" class="cv-alert cv-alert-danger mb-4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               style="width:16px;height:16px;flex-shrink:0">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          {{ $page.props.flash.error }}
        </div>

        <!-- ── KPI Strip ───────────────────────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem">
          <div class="cv-stat-card cv-stat-card-amber">
            <div class="cv-stat-card-label">
              {{ isRtl ? 'بانتظار الترحيل' : 'Pending Post' }}
            </div>
            <div class="cv-stat-card-value">{{ kpis.pending_post }}</div>
            <div class="cv-stat-card-sub">
              {{ isRtl ? 'سجل مسودة' : 'draft records' }}
            </div>
          </div>
          <div class="cv-stat-card cv-stat-card-blue">
            <div class="cv-stat-card-label">
              {{ isRtl ? 'بانتظار التسوية' : 'Pending Settle' }}
            </div>
            <div class="cv-stat-card-value">{{ kpis.pending_settle }}</div>
            <div class="cv-stat-card-sub">
              {{ isRtl ? 'مرحّل، لم يُسوَّ' : 'posted, not settled' }}
            </div>
          </div>
          <div class="cv-stat-card cv-stat-card-green">
            <div class="cv-stat-card-label">
              {{ isRtl ? 'تكلفة العمالة — السنة الحالية' : 'Labor Cost YTD' }}
            </div>
            <div class="cv-stat-card-value">
              {{ formatMoney(kpis.total_labor_ytd) }}
            </div>
            <div class="cv-stat-card-sub">EGP</div>
          </div>
        </div>

        <!-- ── Filters ─────────────────────────────────────────── -->
        <div class="cv-card mb-4" style="padding:0.875rem 1.25rem">
          <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:140px">
              <label class="cv-label">{{ isRtl ? 'المزرعة' : 'Farm' }}</label>
              <select v-model="filterFarm" class="cv-select" @change="applyFilters">
                <option value="">{{ isRtl ? 'جميع المزارع' : 'All Farms' }}</option>
                <option v-for="f in farms" :key="f.id" :value="f.id">{{ f.name }}</option>
              </select>
            </div>
            <div style="flex:1;min-width:120px">
              <label class="cv-label">{{ isRtl ? 'السنة' : 'Year' }}</label>
              <select v-model="filterYear" class="cv-select" @change="applyFilters">
                <option value="">{{ isRtl ? 'كل السنوات' : 'All Years' }}</option>
                <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
              </select>
            </div>
            <div style="flex:1;min-width:140px">
              <label class="cv-label">{{ isRtl ? 'الحالة' : 'Status' }}</label>
              <select v-model="filterStatus" class="cv-select" @change="applyFilters">
                <option value="">{{ isRtl ? 'كل الحالات' : 'All Statuses' }}</option>
                <option value="draft">{{ isRtl ? 'مسودة' : 'Draft' }}</option>
                <option value="posted">{{ isRtl ? 'مرحّل' : 'Posted' }}</option>
                <option value="settled">{{ isRtl ? 'مُسوَّى' : 'Settled' }}</option>
                <option value="locked">{{ isRtl ? 'مقفّل' : 'Locked' }}</option>
              </select>
            </div>
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="clearFilters">
              {{ isRtl ? 'إعادة تعيين' : 'Reset' }}
            </button>
          </div>
        </div>

        <!-- ── Card Grid ───────────────────────────────────────── -->
        <div v-if="allocations.length === 0" class="cv-card">
          <div class="cv-empty">
            <div class="cv-empty-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"
                   style="width:48px;height:48px;opacity:0.2">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
            </div>
            <div class="cv-empty-title">
              {{ isRtl ? 'لا توجد سجلات تخصيص' : 'No allocation records yet' }}
            </div>
            <div class="cv-empty-sub">
              {{ isRtl
                  ? 'أنشئ سجل شهري لبدء تراكم تكاليف العمالة والتكاليف العامة في المخزون'
                  : 'Create a monthly record to start absorbing labor and overhead into livestock WIP' }}
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
          <div v-for="a in allocations" :key="a.id"
               class="cv-card"
               :class="stripeClass(a.status)"
               style="position:relative">

            <!-- Status badge -->
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.75rem">
              <div>
                <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">
                  {{ a.month_label }}
                </div>
                <div style="font-size:0.75rem;color:var(--cv-text-muted);margin-top:2px">
                  {{ isRtl ? (a.farm_name_ar || a.farm_name_en) : (a.farm_name_en || a.farm_name_ar) }}
                </div>
              </div>
              <span class="cv-badge" :class="statusBadgeClass(a.status)">
                {{ statusLabel(a.status) }}
              </span>
            </div>

            <!-- Metrics row -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.875rem">
              <div style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius-sm);padding:0.5rem 0.625rem">
                <div style="font-size:0.6rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--cv-text-muted);margin-bottom:2px">
                  {{ isRtl ? 'عمالة (تقديري)' : 'Labor (Est.)' }}
                </div>
                <div style="font-size:0.9rem;font-weight:700;color:var(--cv-blue-text)">
                  {{ formatMoney(a.labor_estimated) }}
                </div>
              </div>
              <div style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius-sm);padding:0.5rem 0.625rem">
                <div style="font-size:0.6rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--cv-text-muted);margin-bottom:2px">
                  {{ isRtl ? 'أيام الماشية' : 'Animal Days' }}
                </div>
                <div style="font-size:0.9rem;font-weight:700;color:var(--cv-blue-text)">
                  {{ a.total_animal_days ?? '—' }}
                </div>
              </div>
            </div>

            <!-- Rate per animal day -->
            <div v-if="a.rate_per_animal_day"
                 style="font-size:0.75rem;color:var(--cv-text-muted);margin-bottom:0.75rem">
              {{ isRtl ? 'المعدل / يوم / رأس:' : 'Rate / animal-day:' }}
              <span style="color:var(--cv-amber-text);font-weight:600">
                {{ formatMoney(a.rate_per_animal_day) }} EGP
              </span>
            </div>

            <!-- Variance (settled) -->
            <div v-if="a.status === 'settled' && a.labor_variance != null"
                 style="font-size:0.75rem;margin-bottom:0.75rem">
              <span :style="{ color: a.labor_variance > 0 ? 'var(--cv-danger-text)' : 'var(--cv-success-text)' }">
                {{ isRtl ? 'فروق العمالة:' : 'Labor variance:' }}
                {{ a.labor_variance > 0 ? '+' : '' }}{{ formatMoney(a.labor_variance) }} EGP
              </span>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;padding-top:0.75rem;border-top:1px solid var(--cv-border)">
              <!-- Edit (draft only) -->
              <Link v-if="a.can_edit"
                    :href="route('farm-cost-allocations.edit', a.id)"
                    class="cv-btn cv-btn-ghost cv-btn-sm">
                {{ isRtl ? 'تعديل' : 'Edit' }}
              </Link>

              <!-- Post -->
              <button v-if="a.can_post"
                      class="cv-btn cv-btn-amber cv-btn-sm"
                      @click="openPostModal(a)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:12px;height:12px">
                  <polyline points="9 11 12 14 22 4"/>
                  <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                </svg>
                {{ isRtl ? 'ترحيل القيد' : 'Post Accrual' }}
              </button>

              <!-- Settle -->
              <button v-if="a.can_settle"
                      class="cv-btn cv-btn-blue cv-btn-sm"
                      @click="openSettleModal(a)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:12px;height:12px">
                  <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
                {{ isRtl ? 'تسوية التكاليف الفعلية' : 'Settle Actuals' }}
              </button>

              <!-- View JE -->
              <a v-if="a.labor_je_id"
                 :href="route('journal-entries.show', a.labor_je_id)"
                 class="cv-btn cv-btn-ghost cv-btn-sm">
                {{ isRtl ? 'قيد' : 'JE' }}
              </a>

              <!-- Delete (draft only) -->
              <button v-if="a.can_delete"
                      class="cv-btn cv-btn-ghost cv-btn-sm"
                      style="color:var(--cv-danger-text);border-color:var(--cv-danger-border)"
                      @click="openDeleteModal(a)">
                {{ isRtl ? 'حذف' : 'Delete' }}
              </button>
            </div>
          </div>
        </div>

      </div>

      <!-- ── Post Accrual Modal ───────────────────────────────── -->
      <Transition name="modal">
        <div v-if="postTarget" class="cv-modal-backdrop" @click.self="postTarget = null">
          <div class="cv-modal cv-modal-md">
            <div class="cv-modal-header">
              <div class="cv-modal-title">
                {{ isRtl ? 'ترحيل قيد الاستحقاق الشهري' : 'Post Month-End Accrual' }}
              </div>
              <button class="cv-action-btn" @click="postTarget = null">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:16px;height:16px">
                  <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>
            <div class="cv-modal-body">
              <div class="cv-alert cv-alert-info mb-4">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:16px;height:16px;flex-shrink:0">
                  <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                  <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>
                  {{ isRtl
                    ? 'سيُرحّل قيد يُضيف تكاليف العمالة والتكاليف العامة المقدّرة إلى مخزون الماشية قيد التشغيل، مع احتساب حصة الماشية المباعة مباشرة في تكلفة المبيعات.'
                    : 'This posts a journal entry adding estimated labor and overhead to Livestock WIP, with exited animals\' share going directly to COGS.' }}
                </span>
              </div>

              <table class="cv-table" style="margin-bottom:0">
                <tbody>
                  <tr>
                    <td style="color:var(--cv-text-muted);font-size:0.8rem">
                      {{ isRtl ? 'المزرعة / الشهر' : 'Farm / Month' }}
                    </td>
                    <td style="font-weight:600">
                      {{ postTarget.farm_name }} — {{ postTarget.month_label }}
                    </td>
                  </tr>
                  <tr>
                    <td style="color:var(--cv-text-muted);font-size:0.8rem">
                      {{ isRtl ? 'تكلفة العمالة المقدّرة' : 'Estimated Labor' }}
                    </td>
                    <td style="color:var(--cv-blue-text);font-weight:700">
                      {{ formatMoney(postTarget.labor_estimated) }} EGP
                    </td>
                  </tr>
                </tbody>
              </table>

              <div class="cv-alert cv-alert-warning mt-4">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:16px;height:16px;flex-shrink:0">
                  <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                  <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span>
                  {{ isRtl
                    ? 'لا يمكن التراجع عن القيد بعد الترحيل. تأكد من دقة التقديرات.'
                    : 'The journal entry cannot be undone after posting. Ensure estimates are correct.' }}
                </span>
              </div>
            </div>
            <div class="cv-modal-footer">
              <button class="cv-btn cv-btn-ghost" @click="postTarget = null">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
              <button class="cv-btn cv-btn-amber" :disabled="acting" @click="confirmPost">
                <span v-if="acting">{{ isRtl ? 'جارٍ الترحيل...' : 'Posting…' }}</span>
                <span v-else>{{ isRtl ? 'ترحيل القيد' : 'Post Journal Entry' }}</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- ── Settle Modal ────────────────────────────────────── -->
      <Transition name="modal">
        <div v-if="settleTarget" class="cv-modal-backdrop" @click.self="settleTarget = null">
          <div class="cv-modal cv-modal-lg">
            <div class="cv-modal-header">
              <div class="cv-modal-title">
                {{ isRtl ? 'تسوية التكاليف الفعلية' : 'Settle Actual Costs' }}
              </div>
              <button class="cv-action-btn" @click="settleTarget = null">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:16px;height:16px">
                  <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>
            <div class="cv-modal-body">
              <p style="font-size:0.8125rem;color:var(--cv-text-muted);margin-bottom:1.25rem">
                {{ isRtl
                  ? `${settleTarget.farm_name} — ${settleTarget.month_label}`
                  : `${settleTarget.farm_name} — ${settleTarget.month_label}` }}
              </p>

              <!-- Labor actual -->
              <div class="cv-card cv-card-stripe-amber" style="margin-bottom:1rem">
                <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--cv-amber-text);margin-bottom:0.75rem">
                  {{ isRtl ? 'تكلفة العمالة الفعلية' : 'Actual Labor Cost' }}
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:center">
                  <div>
                    <div style="font-size:0.7rem;color:var(--cv-text-muted);margin-bottom:4px">
                      {{ isRtl ? 'المقدّر' : 'Estimated' }}
                    </div>
                    <div style="font-size:1rem;font-weight:700;color:var(--cv-blue-text)">
                      {{ formatMoney(settleTarget.labor_estimated) }} EGP
                    </div>
                  </div>
                  <div class="cv-field" style="margin-bottom:0">
                    <label class="cv-label">{{ isRtl ? 'الفعلي (EGP)' : 'Actual (EGP)' }}</label>
                    <input v-model="settleForm.labor_actual" type="number" min="0" step="0.01"
                           class="cv-input" :class="{'cv-input--error': settleErrors.labor_actual}"
                           :placeholder="isRtl ? 'أدخل التكلفة الفعلية' : 'Enter actual cost'" />
                    <div v-if="settleErrors.labor_actual" class="cv-field-error">
                      {{ settleErrors.labor_actual }}
                    </div>
                    <!-- Live variance -->
                    <div v-if="settleForm.labor_actual !== ''"
                         style="font-size:0.7rem;margin-top:4px"
                         :style="{ color: laborVariance > 0 ? 'var(--cv-danger-text)' : laborVariance < 0 ? 'var(--cv-success-text)' : 'var(--cv-text-muted)' }">
                      {{ isRtl ? 'الفروق:' : 'Variance:' }}
                      {{ laborVariance > 0 ? '+' : '' }}{{ formatMoney(laborVariance) }} EGP
                      {{ laborVariance > 0 ? (isRtl ? '(غير مواتية)' : '(unfavorable)') : laborVariance < 0 ? (isRtl ? '(مواتية)' : '(favorable)') : '' }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- Payment account -->
              <div class="cv-field">
                <label class="cv-label">{{ isRtl ? 'حساب الدفع (نقدية / بنك)' : 'Payment Account (Cash / Bank)' }}</label>
                <select v-model="settleForm.payment_account_id" class="cv-select"
                        :class="{'cv-select--error': settleErrors.payment_account_id}">
                  <option value="">{{ isRtl ? '— اختر الحساب —' : '— Select account —' }}</option>
                  <option v-for="a in paymentAccounts" :key="a.id" :value="a.id">
                    {{ a.code }} — {{ isRtl ? (a.name_ar || a.name_en) : (a.name_en || a.name_ar) }}
                  </option>
                </select>
                <div v-if="settleErrors.payment_account_id" class="cv-field-error">
                  {{ settleErrors.payment_account_id }}
                </div>
              </div>

              <!-- Warning if >20% variance -->
              <div v-if="Math.abs(laborVariance) > settleTarget.labor_estimated * 0.2 && settleForm.labor_actual !== ''"
                   class="cv-alert cv-alert-warning mt-2 mb-0">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:16px;height:16px;flex-shrink:0">
                  <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                  <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span style="font-size:0.8rem">
                  {{ isRtl
                    ? 'تنبيه: الفرق بين الفعلي والمقدّر يتجاوز 20%. تأكد من صحة المبلغ.'
                    : 'Warning: variance exceeds 20% of estimate. Please verify the amount.' }}
                </span>
              </div>
            </div>
            <div class="cv-modal-footer">
              <button class="cv-btn cv-btn-ghost" @click="settleTarget = null">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
              <button class="cv-btn cv-btn-amber" :disabled="acting" @click="confirmSettle">
                <span v-if="acting">{{ isRtl ? 'جارٍ التسوية...' : 'Settling…' }}</span>
                <span v-else>{{ isRtl ? 'تسوية وترحيل القيد' : 'Settle & Post JE' }}</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- ── Delete Modal ───────────────────────────────────── -->
      <Transition name="modal">
        <div v-if="deleteTarget" class="cv-modal-backdrop" @click.self="deleteTarget = null">
          <div class="cv-modal cv-modal-sm">
            <div class="cv-modal-header">
              <div class="cv-modal-title" style="color:var(--cv-danger-text)">
                {{ isRtl ? 'حذف السجل' : 'Delete Record' }}
              </div>
            </div>
            <div class="cv-modal-body" style="text-align:center">
              <div style="width:3rem;height:3rem;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     style="width:20px;height:20px;color:var(--cv-danger-text)">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/>
                </svg>
              </div>
              <div class="cv-alert cv-alert-danger" style="text-align:start">
                {{ isRtl
                    ? `حذف سجل "${deleteTarget.farm_name} — ${deleteTarget.month_label}"؟ لا يمكن التراجع.`
                    : `Delete "${deleteTarget.farm_name} — ${deleteTarget.month_label}"? This cannot be undone.` }}
              </div>
            </div>
            <div class="cv-modal-footer">
              <button class="cv-btn cv-btn-ghost" @click="deleteTarget = null">
                {{ isRtl ? 'إلغاء' : 'Cancel' }}
              </button>
              <button class="cv-btn cv-btn-danger" :disabled="acting" @click="confirmDelete">
                {{ acting ? (isRtl ? 'جارٍ الحذف...' : 'Deleting…') : (isRtl ? 'حذف' : 'Delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>

    </div>
 
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

defineOptions({ layout: AuthenticatedLayout })

const { isRtl } = useLocale()

const props = defineProps({
  allocations:     { type: Array,  default: () => [] },
  farms:           { type: Array,  default: () => [] },
  companies:       { type: Array,  default: () => [] },
  filters:         { type: Object, default: () => ({}) },
  kpis:            { type: Object, default: () => ({}) },
  paymentAccounts: { type: Array,  default: () => [] },
})

// ── Filters ──────────────────────────────────────────────────────
const filterFarm   = ref(props.filters.farm_id  || '')
const filterYear   = ref(props.filters.year     || '')
const filterStatus = ref(props.filters.status   || '')

const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i)

function applyFilters() {
  router.get(route('farm-cost-allocations.index'), {
    farm_id: filterFarm.value  || undefined,
    year:    filterYear.value  || undefined,
    status:  filterStatus.value || undefined,
  }, { preserveScroll: true, replace: true })
}

function clearFilters() {
  filterFarm.value = ''
  filterYear.value = ''
  filterStatus.value = ''
  applyFilters()
}

// ── Formatting ───────────────────────────────────────────────────
function formatMoney(val) {
  if (val == null || val === '') return '0'
  return Number(val).toLocaleString(isRtl.value ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

// ── Status helpers ───────────────────────────────────────────────
function statusLabel(s) {
  if (isRtl.value) {
    return { draft: 'مسودة', posted: 'مرحّل', settled: 'مُسوَّى', locked: 'مقفّل' }[s] || s
  }
  return { draft: 'Draft', posted: 'Posted', settled: 'Settled', locked: 'Locked' }[s] || s
}

function statusBadgeClass(s) {
  return {
    draft:   'cv-badge-amber',
    posted:  'cv-badge-info',
    settled: 'cv-badge-green',
    locked:  'cv-badge-neutral',
  }[s] || 'cv-badge-neutral'
}

function stripeClass(s) {
  return {
    draft:   'cv-card-stripe-amber',
    posted:  'cv-card-stripe-blue',
    settled: 'cv-card-stripe-green',
    locked:  'cv-card-stripe-muted',
  }[s] || ''
}

// ── Post modal ───────────────────────────────────────────────────
const postTarget = ref(null)
const acting     = ref(false)

function openPostModal(a) { postTarget.value = a }

function confirmPost() {
  if (!postTarget.value) return
  acting.value = true
  router.post(route('farm-cost-allocations.post', postTarget.value.id), {}, {
    preserveScroll: true,
    onSuccess: () => { acting.value = false; postTarget.value = null },
    onError:   () => { acting.value = false; postTarget.value = null },
  })
}

// ── Settle modal ─────────────────────────────────────────────────
const settleTarget = ref(null)
const settleErrors = ref({})
const settleForm   = ref({ labor_actual: '', payment_account_id: '' })

const laborVariance = computed(() => {
  if (!settleTarget.value || settleForm.value.labor_actual === '') return 0
  return parseFloat(settleForm.value.labor_actual) - parseFloat(settleTarget.value.labor_estimated)
})

function openSettleModal(a) {
  settleTarget.value = a
  settleErrors.value = {}
  settleForm.value   = { labor_actual: '', payment_account_id: '' }
}

function confirmSettle() {
  if (!settleTarget.value) return
  acting.value = true
  settleErrors.value = {}
  router.post(route('farm-cost-allocations.settle', settleTarget.value.id), {
    labor_actual:        settleForm.value.labor_actual,
    payment_account_id:  settleForm.value.payment_account_id,
    overhead_actuals:    {},
  }, {
    preserveScroll: true,
    onSuccess: () => { acting.value = false; settleTarget.value = null },
    onError:   (e) => { acting.value = false; settleErrors.value = e },
  })
}

// ── Delete modal ─────────────────────────────────────────────────
const deleteTarget = ref(null)

function openDeleteModal(a) { deleteTarget.value = a }

function confirmDelete() {
  if (!deleteTarget.value) return
  acting.value = true
  router.delete(route('farm-cost-allocations.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => { acting.value = false; deleteTarget.value = null },
    onError:   () => { acting.value = false; deleteTarget.value = null },
  })
}
</script>
