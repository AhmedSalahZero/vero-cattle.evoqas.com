<template>
  <AuthenticatedLayout>
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ ui.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ ui.title }}</div>
      <div class="cv-page-hero-sub">{{ ui.subtitle }}</div>

      <!-- Super Admin company selector -->
      <div v-if="isSuperAdmin && companies.length" class="mt-4 flex items-center gap-3">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"><path d="M3 21h18M3 7l9-4 9 4M4 7v14M20 7v14M8 11h2m4 0h2M8 15h2m4 0h2"/></svg>
        <select v-model="selectedCompanyId" @change="onCompanyChange" class="cv-select" style="min-width:220px">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c, 'name') }}</option>
        </select>
      </div>
    </div>

    <div class="cv-page">

      <!-- Tab Bar -->
      <div class="analytics-tabs-bar">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          @click="setTab(tab.key)"
          :class="['analytics-tab', activeTab === tab.key ? 'analytics-tab--active' : '']"
        >
          <span class="analytics-tab-icon" v-html="tab.icon"></span>
          <span>{{ isRtl ? tab.labelAr : tab.labelEn }}</span>
        </button>
      </div>

      <!-- ─────────────────────────────────────────────────────── -->
      <!-- TAB 1 — FINANCIAL RATIOS                               -->
      <!-- ─────────────────────────────────────────────────────── -->
      <div v-show="activeTab === 'financial-ratios'">
        <!-- Filter bar -->
        <div class="cv-card mb-5 flex flex-wrap gap-4 items-end">
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'من تاريخ' : 'Period From' }}</label>
            <input type="date" v-model="ratioFilters.date_from" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'إلى تاريخ' : 'Period To' }}</label>
            <input type="date" v-model="ratioFilters.date_to" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'تاريخ الميزانية' : 'Balance Sheet Date' }}</label>
            <input type="date" v-model="ratioFilters.as_of_date" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'السنة المالية' : 'Fiscal Year' }}</label>
            <select v-model="ratioFilters.fiscal_year_id" class="cv-select">
              <option value="">{{ isRtl ? '— اختر سنة —' : '— Select Year —' }}</option>
              <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">{{ fy.name || fy.year }}</option>
            </select>
          </div>
          <button @click="runReport('financial-ratios')" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تشغيل التقرير' : 'Run Report' }}
          </button>
        </div>

        <!-- Results -->
        <template v-if="activeTab === 'financial-ratios' && report">
          <!-- BS Summary strip -->
          <div class="analytics-kpi-strip">
            <div class="analytics-kpi" v-for="kpi in ratioBsSummary" :key="kpi.key">
              <div class="analytics-kpi-label">{{ kpi.label }}</div>
              <div class="analytics-kpi-value" :style="{ color: kpi.color }">{{ fmt(kpi.value) }}</div>
            </div>
          </div>

          <!-- Ratio groups -->
          <div v-for="group in ratioGroups" :key="group.key" class="mb-6">
            <div class="analytics-group-header">
              <span class="analytics-group-dot" :style="{ background: group.color }"></span>
              {{ isRtl ? group.labelAr : group.labelEn }}
            </div>
            <div class="analytics-ratios-grid">
              <div
                v-for="ratio in ratiosInGroup(group.key)"
                :key="ratio.key"
                class="analytics-ratio-card"
                :class="ratioStatusClass(ratio)"
              >
                <!-- Colored status bar -->
                <div class="analytics-ratio-bar" :class="ratioStatusClass(ratio)"></div>

                <div class="analytics-ratio-body">
                  <!-- Label + formula -->
                  <div class="analytics-ratio-label">{{ isRtl ? ratio.label_ar : ratio.label_en }}</div>
                  <div class="analytics-ratio-formula">{{ isRtl ? ratio.formula_ar : ratio.formula_en }}</div>

                  <!-- Value -->
                  <div class="analytics-ratio-value" :class="ratioValueClass(ratio)">
                    <template v-if="ratio.value !== null && ratio.value !== undefined">
                      {{ formatRatio(ratio) }}
                    </template>
                    <span v-else class="text-xs" style="color:var(--cv-text-muted)">{{ isRtl ? 'لا بيانات' : 'N/A' }}</span>
                  </div>

                  <!-- Status badge -->
                  <div v-if="ratio.value !== null" class="mt-1">
                    <span class="cv-badge" :class="ratioBadgeClass(ratio)">{{ ratioStatusLabel(ratio) }}</span>
                  </div>

                  <!-- Comment & Action section -->
                  <div class="analytics-comment-section mt-3">
                    <div v-if="!editingComment[ratio.key]" class="analytics-comment-display">
                      <div v-if="ratio.comment || ratio.action" class="space-y-1">
                        <div v-if="ratio.comment" class="analytics-comment-text">
                          <span class="analytics-comment-chip comment">💬</span> {{ ratio.comment }}
                        </div>
                        <div v-if="ratio.action" class="analytics-comment-text">
                          <span class="analytics-comment-chip action">⚡</span> {{ ratio.action }}
                        </div>
                      </div>
                      <div v-else class="analytics-comment-placeholder">
                        {{ isRtl ? 'لا يوجد تعليق' : 'No comment yet' }}
                      </div>
                      <button
                        v-if="ratioFilters.fiscal_year_id"
                        @click="startEditComment(ratio)"
                        class="analytics-comment-btn"
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="w-3.5 h-3.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        {{ isRtl ? 'تعديل' : 'Edit' }}
                      </button>
                    </div>

                    <!-- Edit mode -->
                    <div v-else class="analytics-comment-edit">
                      <textarea
                        v-model="commentDraft[ratio.key + '_comment']"
                        :placeholder="isRtl ? 'أدخل التعليق...' : 'Enter comment...'"
                        class="cv-textarea text-xs"
                        rows="2"
                      ></textarea>
                      <textarea
                        v-model="commentDraft[ratio.key + '_action']"
                        :placeholder="isRtl ? 'الإجراء المطلوب...' : 'Action required...'"
                        class="cv-textarea text-xs mt-1"
                        rows="2"
                      ></textarea>
                      <div class="flex gap-2 mt-2">
                        <button @click="saveComment(ratio)" class="cv-btn cv-btn-amber" style="font-size:11px;padding:4px 12px">
                          {{ isRtl ? 'حفظ' : 'Save' }}
                        </button>
                        <button @click="cancelEditComment(ratio.key)" class="cv-btn cv-btn-ghost" style="font-size:11px;padding:4px 12px">
                          {{ isRtl ? 'إلغاء' : 'Cancel' }}
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>

        <div v-else-if="activeTab === 'financial-ratios'" class="analytics-empty">
          <div class="analytics-empty-icon">📊</div>
          <div>{{ isRtl ? 'اختر الفترة وشغّل التقرير' : 'Select a period and run the report' }}</div>
        </div>
      </div>

      <!-- ─────────────────────────────────────────────────────── -->
      <!-- TAB 2 — TOP CUSTOMERS                                  -->
      <!-- ─────────────────────────────────────────────────────── -->
      <div v-show="activeTab === 'top-customers'">
        <div class="cv-card mb-5 flex flex-wrap gap-4 items-end">
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'من تاريخ' : 'From' }}</label>
            <input type="date" v-model="custFilters.date_from" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'إلى تاريخ' : 'To' }}</label>
            <input type="date" v-model="custFilters.date_to" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'عدد النتائج' : 'Top N' }}</label>
            <select v-model="custFilters.limit" class="cv-select">
              <option value="5">Top 5</option>
              <option value="10">Top 10</option>
              <option value="20">Top 20</option>
            </select>
          </div>
          <button @click="runReport('top-customers')" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تشغيل' : 'Run' }}
          </button>
        </div>

        <template v-if="activeTab === 'top-customers' && report">
          <div class="analytics-rank-header mb-3">
            <span style="color:var(--cv-text-muted)">{{ isRtl ? 'إجمالي الإيرادات:' : 'Total Revenue:' }}</span>
            <span style="color:var(--cv-blue-text);font-weight:700;margin-inline-start:8px">{{ fmt(report.grand_total) }} EGP</span>
          </div>
          <div class="analytics-rank-list">
            <div v-for="row in report.ranked" :key="row.customer_id" class="analytics-rank-row">
              <div class="analytics-rank-medal" :class="medalClass(row.rank)">{{ row.rank }}</div>
              <div class="analytics-rank-name">
                <div style="font-weight:600;color:var(--cv-text-primary)">{{ n(row, 'name') }}</div>
                <div style="font-size:12px;color:var(--cv-text-muted)">
                  {{ row.invoice_count }} {{ isRtl ? 'فاتورة' : 'invoices' }} · {{ isRtl ? 'متوسط:' : 'avg:' }} {{ fmt(row.avg_invoice) }}
                </div>
              </div>
              <div class="analytics-rank-bar-wrap">
                <div class="analytics-rank-bar" :style="{ width: row.revenue_pct + '%' }"></div>
              </div>
              <div class="analytics-rank-value">
                <div style="color:var(--cv-blue-text);font-weight:700">{{ fmt(row.total_revenue) }}</div>
                <div style="font-size:11px;color:var(--cv-amber)">{{ row.revenue_pct }}%</div>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="activeTab === 'top-customers'" class="analytics-empty">
          <div class="analytics-empty-icon">🏆</div>
          <div>{{ isRtl ? 'اختر الفترة وشغّل التقرير' : 'Select a period and run the report' }}</div>
        </div>
      </div>

      <!-- ─────────────────────────────────────────────────────── -->
      <!-- TAB 3 — TOP CUSTOMERS BY BREED                         -->
      <!-- ─────────────────────────────────────────────────────── -->
      <div v-show="activeTab === 'top-customers-breed'">
        <div class="cv-card mb-5 flex flex-wrap gap-4 items-end">
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'من تاريخ' : 'From' }}</label>
            <input type="date" v-model="breedCustFilters.date_from" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'إلى تاريخ' : 'To' }}</label>
            <input type="date" v-model="breedCustFilters.date_to" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'نوع الماشية' : 'Breed' }}</label>
            <select v-model="breedCustFilters.breed" class="cv-select">
              <option value="">{{ isRtl ? '— الكل —' : '— All Breeds —' }}</option>
              <option v-for="b in availableBreeds" :key="b" :value="b">{{ b }}</option>
            </select>
          </div>
          <button @click="runReport('top-customers-breed')" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تشغيل' : 'Run' }}
          </button>
        </div>

        <template v-if="activeTab === 'top-customers-breed' && report">
          <div v-if="report.by_breed.length === 0" class="analytics-empty">
            <div class="analytics-empty-icon">🐄</div>
            <div>{{ isRtl ? 'لا توجد بيانات' : 'No data for selected period' }}</div>
          </div>
          <div v-else v-for="group in report.by_breed" :key="group.breed" class="analytics-breed-group mb-6">
            <div class="analytics-breed-header">
              <span class="analytics-breed-badge">{{ group.breed }}</span>
              <span style="color:var(--cv-text-muted);font-size:13px">{{ isRtl ? 'إجمالي:' : 'Total:' }}</span>
              <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(group.breed_total) }} EGP</span>
            </div>
            <div class="analytics-rank-list">
              <div v-for="row in group.customers" :key="row.customer_id" class="analytics-rank-row">
                <div class="analytics-rank-medal" :class="medalClass(row.rank)">{{ row.rank }}</div>
                <div class="analytics-rank-name">
                  <div style="font-weight:600;color:var(--cv-text-primary)">{{ n(row, 'name') }}</div>
                  <div style="font-size:12px;color:var(--cv-text-muted)">
                    {{ row.head_count }} {{ isRtl ? 'رأس' : 'head' }} · {{ row.sale_count }} {{ isRtl ? 'فاتورة' : 'sales' }}
                  </div>
                </div>
                <div class="analytics-rank-bar-wrap">
                  <div class="analytics-rank-bar" :style="{ width: row.revenue_pct + '%' }"></div>
                </div>
                <div class="analytics-rank-value">
                  <div style="color:var(--cv-blue-text);font-weight:700">{{ fmt(row.total_revenue) }}</div>
                  <div style="font-size:11px;color:var(--cv-amber)">{{ row.revenue_pct }}%</div>
                </div>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="activeTab === 'top-customers-breed'" class="analytics-empty">
          <div class="analytics-empty-icon">🐄</div>
          <div>{{ isRtl ? 'اختر الفترة وشغّل التقرير' : 'Select a period and run the report' }}</div>
        </div>
      </div>

      <!-- ─────────────────────────────────────────────────────── -->
      <!-- TAB 4 — TOP SUPPLIERS                                  -->
      <!-- ─────────────────────────────────────────────────────── -->
      <div v-show="activeTab === 'top-suppliers'">
        <div class="cv-card mb-5 flex flex-wrap gap-4 items-end">
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'من تاريخ' : 'From' }}</label>
            <input type="date" v-model="suppFilters.date_from" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'إلى تاريخ' : 'To' }}</label>
            <input type="date" v-model="suppFilters.date_to" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'عدد النتائج' : 'Top N' }}</label>
            <select v-model="suppFilters.limit" class="cv-select">
              <option value="5">Top 5</option>
              <option value="10">Top 10</option>
              <option value="20">Top 20</option>
            </select>
          </div>
          <button @click="runReport('top-suppliers')" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تشغيل' : 'Run' }}
          </button>
        </div>

        <template v-if="activeTab === 'top-suppliers' && report">
          <div class="analytics-rank-header mb-3">
            <span style="color:var(--cv-text-muted)">{{ isRtl ? 'إجمالي المشتريات:' : 'Total Purchases:' }}</span>
            <span style="color:var(--cv-blue-text);font-weight:700;margin-inline-start:8px">{{ fmt(report.grand_total) }} EGP</span>
          </div>
          <div class="analytics-rank-list">
            <div v-for="row in report.ranked" :key="row.supplier_id" class="analytics-rank-row">
              <div class="analytics-rank-medal" :class="medalClass(row.rank)">{{ row.rank }}</div>
              <div class="analytics-rank-name">
                <div style="font-weight:600;color:var(--cv-text-primary)">{{ n(row, 'name') }}</div>
                <div style="font-size:12px;color:var(--cv-text-muted)">
                  {{ row.invoice_count }} {{ isRtl ? 'فاتورة' : 'invoices' }}
                  <template v-if="row.outstanding_balance > 0">
                    · <span style="color:var(--cv-danger-text)">{{ isRtl ? 'متأخر:' : 'outstanding:' }} {{ fmt(row.outstanding_balance) }}</span>
                  </template>
                </div>
              </div>
              <div class="analytics-rank-bar-wrap">
                <div class="analytics-rank-bar supplier-bar" :style="{ width: row.purchases_pct + '%' }"></div>
              </div>
              <div class="analytics-rank-value">
                <div style="color:var(--cv-blue-text);font-weight:700">{{ fmt(row.total_purchases) }}</div>
                <div style="font-size:11px;color:var(--cv-amber)">{{ row.purchases_pct }}%</div>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="activeTab === 'top-suppliers'" class="analytics-empty">
          <div class="analytics-empty-icon">🏭</div>
          <div>{{ isRtl ? 'اختر الفترة وشغّل التقرير' : 'Select a period and run the report' }}</div>
        </div>
      </div>

      <!-- ─────────────────────────────────────────────────────── -->
      <!-- TAB 5 — TOP SUPPLIERS BY ITEM                          -->
      <!-- ─────────────────────────────────────────────────────── -->
      <div v-show="activeTab === 'top-suppliers-item'">
        <div class="cv-card mb-5 flex flex-wrap gap-4 items-end">
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'من تاريخ' : 'From' }}</label>
            <input type="date" v-model="suppItemFilters.date_from" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'إلى تاريخ' : 'To' }}</label>
            <input type="date" v-model="suppItemFilters.date_to" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'الصنف' : 'Item' }}</label>
            <select v-model="suppItemFilters.item_id" class="cv-select" style="min-width:180px">
              <option value="">{{ isRtl ? '— الكل —' : '— All Items —' }}</option>
              <option v-for="item in availableItems" :key="item.id" :value="item.id">{{ n(item, 'name') }}</option>
            </select>
          </div>
          <button @click="runReport('top-suppliers-item')" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تشغيل' : 'Run' }}
          </button>
        </div>

        <template v-if="activeTab === 'top-suppliers-item' && report">
          <div v-if="report.by_item.length === 0" class="analytics-empty">
            <div class="analytics-empty-icon">📦</div>
            <div>{{ isRtl ? 'لا توجد بيانات' : 'No receipt data for selected period' }}</div>
          </div>
          <div v-else v-for="group in report.by_item" :key="group.item_id" class="analytics-breed-group mb-6">
            <div class="analytics-breed-header">
              <span class="analytics-breed-badge" style="background:var(--cv-blue-light);border-color:var(--cv-blue-border);color:var(--cv-blue-text)">
                {{ n(group, 'item_name') }} · {{ group.unit }}
              </span>
              <span class="cv-badge" style="font-size:10px">{{ group.category }}</span>
              <span style="color:var(--cv-text-muted);font-size:13px">{{ isRtl ? 'إجمالي:' : 'Total:' }}</span>
              <span style="color:var(--cv-blue-text);font-weight:700">{{ fmt(group.item_total) }} EGP</span>
            </div>
            <div class="analytics-rank-list">
              <div v-for="row in group.suppliers" :key="row.supplier_id" class="analytics-rank-row">
                <div class="analytics-rank-medal" :class="medalClass(row.rank)">{{ row.rank }}</div>
                <div class="analytics-rank-name">
                  <div style="font-weight:600;color:var(--cv-text-primary)">{{ n(row, 'name') }}</div>
                  <div style="font-size:12px;color:var(--cv-text-muted)">
                    {{ row.receipt_count }} {{ isRtl ? 'استلام' : 'receipts' }} · {{ row.total_qty }} {{ group.unit }} · {{ isRtl ? 'متوسط:' : 'avg:' }} {{ row.avg_unit_cost }}/{{ group.unit }}
                  </div>
                </div>
                <div class="analytics-rank-bar-wrap">
                  <div class="analytics-rank-bar supplier-bar" :style="{ width: row.value_pct + '%' }"></div>
                </div>
                <div class="analytics-rank-value">
                  <div style="color:var(--cv-blue-text);font-weight:700">{{ fmt(row.total_value) }}</div>
                  <div style="font-size:11px;color:var(--cv-amber)">{{ row.value_pct }}%</div>
                </div>
              </div>
            </div>
          </div>
        </template>
        <div v-else-if="activeTab === 'top-suppliers-item'" class="analytics-empty">
          <div class="analytics-empty-icon">📦</div>
          <div>{{ isRtl ? 'اختر الفترة وشغّل التقرير' : 'Select a period and run the report' }}</div>
        </div>
      </div>

      <!-- ─────────────────────────────────────────────────────── -->
      <!-- TAB 6 — FEEDING DURATION                               -->
      <!-- ─────────────────────────────────────────────────────── -->
      <div v-show="activeTab === 'feeding-duration'">
        <div class="cv-card mb-5 flex flex-wrap gap-4 items-end">
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'من تاريخ البيع' : 'Sale Date From' }}</label>
            <input type="date" v-model="feedingFilters.date_from" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'إلى تاريخ البيع' : 'Sale Date To' }}</label>
            <input type="date" v-model="feedingFilters.date_to" class="cv-input" />
          </div>
          <div class="flex flex-col gap-1">
            <label class="analytics-label">{{ isRtl ? 'نوع الماشية' : 'Breed Filter' }}</label>
            <select v-model="feedingFilters.breed" class="cv-select">
              <option value="">{{ isRtl ? '— الكل —' : '— All Breeds —' }}</option>
              <option v-for="b in availableBreeds" :key="b" :value="b">{{ b }}</option>
            </select>
          </div>
          <button @click="runReport('feeding-duration')" class="cv-btn cv-btn-amber">
            {{ isRtl ? 'تشغيل' : 'Run' }}
          </button>
        </div>

        <template v-if="activeTab === 'feeding-duration' && report">

          <!-- Excluded warning -->
          <div v-if="report.excluded_count > 0" class="cv-card mb-4" style="border-color:var(--cv-amber-border);background:var(--cv-amber-light)">
            <div style="color:var(--cv-amber);font-size:13px">
              ⚠️ {{ report.excluded_count }} {{ isRtl ? 'حيوان مستبعد (لا يوجد تاريخ شراء)' : 'animals excluded (no purchase date recorded)' }}
            </div>
          </div>

          <!-- Grand summary strip — 5 bucket totals -->
          <div class="mb-6">
            <div class="analytics-section-title">{{ isRtl ? 'الملخص الإجمالي' : 'Grand Summary — All Breeds' }}</div>
            <div class="feeding-bucket-strip">
              <div
                v-for="(bucket, idx) in report.grand_summary"
                :key="bucket.bucket"
                class="feeding-bucket-card"
                :class="'feeding-bucket--' + idx"
              >
                <div class="feeding-bucket-label">{{ isRtl ? bucket.label_ar : bucket.label_en }}</div>
                <div class="feeding-bucket-count">{{ bucket.count }}</div>
                <div class="feeding-bucket-pct">{{ bucket.pct }}%</div>
                <div class="feeding-bucket-bar-wrap">
                  <div class="feeding-bucket-bar" :style="{ height: bucket.pct + '%' }"></div>
                </div>
                <div class="feeding-bucket-rev" style="color:var(--cv-blue-text);font-size:11px">{{ fmt(bucket.revenue) }}</div>
              </div>
            </div>
          </div>

          <!-- Per-breed breakdown -->
          <div v-if="report.by_breed.length === 0" class="analytics-empty">
            <div>{{ isRtl ? 'لا توجد مبيعات تطابق الفلتر' : 'No sales match the selected filters' }}</div>
          </div>
          <div v-else v-for="breed in report.by_breed" :key="breed.breed" class="cv-card mb-4">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
              <div class="flex items-center gap-2">
                <span class="analytics-breed-badge">{{ breed.breed }}</span>
                <span style="color:var(--cv-text-muted);font-size:13px">
                  {{ breed.total_count }} {{ isRtl ? 'رأس' : 'head' }}
                  · {{ isRtl ? 'متوسط مدة التغذية:' : 'avg duration:' }}
                  <span style="color:var(--cv-blue-text)">{{ breed.avg_duration_months }} {{ isRtl ? 'شهر' : 'mo' }}</span>
                </span>
              </div>
              <span style="color:var(--cv-blue-text);font-weight:700;font-size:14px">{{ fmt(breed.total_revenue) }} EGP</span>
            </div>

            <!-- Bucket rows -->
            <div class="feeding-breed-table">
              <div class="feeding-breed-header">
                <div>{{ isRtl ? 'مدة التغذية' : 'Duration' }}</div>
                <div>{{ isRtl ? 'عدد الرؤوس' : 'Head Count' }}</div>
                <div>{{ isRtl ? 'النسبة %' : '%' }}</div>
                <div>{{ isRtl ? 'الإيراد' : 'Revenue' }}</div>
                <div>{{ isRtl ? 'التوزيع' : 'Distribution' }}</div>
              </div>
              <div
                v-for="bucket in breed.buckets"
                :key="bucket.bucket"
                class="feeding-breed-row"
                :class="{ 'feeding-breed-row--dominant': bucket.pct === Math.max(...breed.buckets.map(b => b.pct)) }"
              >
                <div>
                  <span class="cv-badge" style="font-size:11px">{{ isRtl ? bucket.label_ar : bucket.label_en }}</span>
                </div>
                <div style="color:var(--cv-text-primary);font-weight:600">{{ bucket.count }}</div>
                <div style="color:var(--cv-amber);font-weight:700">{{ bucket.pct }}%</div>
                <div style="color:var(--cv-blue-text)">{{ fmt(bucket.revenue) }}</div>
                <div class="feeding-dist-bar-wrap">
                  <div class="feeding-dist-bar" :style="{ width: bucket.pct + '%' }"></div>
                  <span style="font-size:11px;color:var(--cv-text-muted);margin-inline-start:6px">{{ bucket.pct }}%</span>
                </div>
              </div>
            </div>
          </div>
        </template>

        <div v-else-if="activeTab === 'feeding-duration'" class="analytics-empty">
          <div class="analytics-empty-icon">⏱️</div>
          <div>{{ isRtl ? 'اختر فترة البيع وشغّل التقرير' : 'Select a sale period and run the report' }}</div>
        </div>
      </div>

    </div><!-- end cv-page -->
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

// ── Props ──────────────────────────────────────────────────────────
const props = defineProps({
  report:            { type: Object, default: null },
  activeTab:         { type: String, default: 'financial-ratios' },
  filters:           { type: Object, default: () => ({}) },
  companies:         { type: Array,  default: () => [] },
  fiscalYears:       { type: Array,  default: () => [] },
  selectedCompanyId: { type: Number, default: null },
})

// ── Locale ─────────────────────────────────────────────────────────
const { locale: lang, isRtl, n } = useLocale()

const isSuperAdmin = computed(() => usePage().props.auth?.user?.is_super_admin ?? false)
const selectedCompanyId = ref(props.selectedCompanyId)

// ── Tab config ─────────────────────────────────────────────────────
const tabs = [
  {
    key: 'financial-ratios',
    labelEn: 'Financial Ratios',
    labelAr: 'النسب المالية',
    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
  },
  {
    key: 'top-customers',
    labelEn: 'Top Customers',
    labelAr: 'أفضل العملاء',
    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
  },
  {
    key: 'top-customers-breed',
    labelEn: 'Customers by Breed',
    labelAr: 'العملاء حسب النوع',
    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>',
  },
  {
    key: 'top-suppliers',
    labelEn: 'Top Suppliers',
    labelAr: 'أفضل الموردين',
    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
  },
  {
    key: 'top-suppliers-item',
    labelEn: 'Suppliers by Item',
    labelAr: 'الموردون حسب الصنف',
    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>',
  },
  {
    key: 'feeding-duration',
    labelEn: 'Feeding Duration',
    labelAr: 'مدة التغذية',
    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
  },
]

// ── Reactive tab ───────────────────────────────────────────────────
const activeTab = ref(props.activeTab)
function setTab(key) {
  activeTab.value = key
}

// ── Filter refs (per tab) ──────────────────────────────────────────
const today    = new Date().toISOString().slice(0, 10)
const yearStart = today.slice(0, 4) + '-01-01'

const ratioFilters = ref({
  date_from:      props.filters.date_from      || yearStart,
  date_to:        props.filters.date_to        || today,
  as_of_date:     props.filters.as_of_date     || today,
  fiscal_year_id: props.filters.fiscal_year_id || '',
})
const custFilters = ref({
  date_from: props.filters.date_from || yearStart,
  date_to:   props.filters.date_to   || today,
  limit:     props.filters.limit     || '10',
})
const breedCustFilters = ref({
  date_from: props.filters.date_from || yearStart,
  date_to:   props.filters.date_to   || today,
  breed:     props.filters.breed     || '',
  limit:     props.filters.limit     || '5',
})
const suppFilters = ref({
  date_from: props.filters.date_from || yearStart,
  date_to:   props.filters.date_to   || today,
  limit:     props.filters.limit     || '10',
})
const suppItemFilters = ref({
  date_from: props.filters.date_from || yearStart,
  date_to:   props.filters.date_to   || today,
  item_id:   props.filters.item_id   || '',
})
const feedingFilters = ref({
  date_from: props.filters.date_from || yearStart,
  date_to:   props.filters.date_to   || today,
  breed:     props.filters.breed     || '',
})

// ── Available breeds / items from report props ─────────────────────
const availableBreeds = computed(() => props.report?.available_breeds || [])
const availableItems  = computed(() => props.report?.available_items  || [])

// ── Run report ─────────────────────────────────────────────────────
const routeMap = {
  'financial-ratios':    'analytics.financial-ratios',
  'top-customers':       'analytics.top-customers',
  'top-customers-breed': 'analytics.top-customers-breed',
  'top-suppliers':       'analytics.top-suppliers',
  'top-suppliers-item':  'analytics.top-suppliers-item',
  'feeding-duration':    'analytics.feeding-duration',
}
const filterMap = {
  'financial-ratios':    ratioFilters,
  'top-customers':       custFilters,
  'top-customers-breed': breedCustFilters,
  'top-suppliers':       suppFilters,
  'top-suppliers-item':  suppItemFilters,
  'feeding-duration':    feedingFilters,
}
function runReport(tab) {
  const params = { ...filterMap[tab].value }
  if (isSuperAdmin.value) params.company_id = selectedCompanyId.value
  router.get(route(routeMap[tab]), params, { preserveScroll: false })
}
function onCompanyChange() {
  // Reload current tab with new company
  runReport(activeTab.value)
}

// ── Financial Ratios helpers ───────────────────────────────────────
const ratioGroups = [
  { key: 'liquidity',     labelEn: 'Liquidity',     labelAr: 'السيولة',             color: 'var(--cv-blue)' },
  { key: 'profitability', labelEn: 'Profitability',  labelAr: 'الربحية',             color: 'var(--cv-green-bright)' },
  { key: 'leverage',      labelEn: 'Leverage',       labelAr: 'الرافعة المالية',     color: 'var(--cv-amber)' },
  { key: 'efficiency',    labelEn: 'Efficiency',     labelAr: 'الكفاءة التشغيلية',  color: 'var(--cv-blue-text)' },
]
function ratiosInGroup(group) {
  return (props.report?.ratios || []).filter(r => r.group === group)
}
function ratioStatusClass(ratio) {
  if (ratio.value === null || ratio.value === undefined) return 'ratio-neutral'
  const { benchmark, value, higher_is } = ratio
  if (!benchmark) return 'ratio-neutral'
  if (higher_is === 'better') {
    if (benchmark.good_above !== undefined && value >= benchmark.good_above) return 'ratio-good'
    if (benchmark.warning_below !== undefined && value < benchmark.warning_below) return 'ratio-bad'
    return 'ratio-warning'
  } else {
    if (benchmark.good_below !== undefined && value <= benchmark.good_below) return 'ratio-good'
    if (benchmark.warning_above !== undefined && value > benchmark.warning_above) return 'ratio-bad'
    return 'ratio-warning'
  }
}
function ratioValueClass(ratio) {
  const cls = ratioStatusClass(ratio)
  if (cls === 'ratio-good') return 'ratio-val-good'
  if (cls === 'ratio-bad')  return 'ratio-val-bad'
  if (cls === 'ratio-warning') return 'ratio-val-warning'
  return ''
}
function ratioBadgeClass(ratio) {
  const cls = ratioStatusClass(ratio)
  if (cls === 'ratio-good')    return 'cv-badge-success'
  if (cls === 'ratio-bad')     return 'cv-badge-danger'
  if (cls === 'ratio-warning') return 'cv-badge-warning'
  return ''
}
function ratioStatusLabel(ratio) {
  const cls = ratioStatusClass(ratio)
  if (isRtl.value) {
    if (cls === 'ratio-good')    return 'جيد'
    if (cls === 'ratio-bad')     return 'ضعيف'
    if (cls === 'ratio-warning') return 'مقبول'
  } else {
    if (cls === 'ratio-good')    return 'Good'
    if (cls === 'ratio-bad')     return 'Weak'
    if (cls === 'ratio-warning') return 'Fair'
  }
  return ''
}
function formatRatio(ratio) {
  if (ratio.value === null || ratio.value === undefined) return 'N/A'
  if (ratio.unit === '%') return ratio.value + '%'
  if (ratio.unit === 'x') return ratio.value + 'x'
  return ratio.value
}

// BS summary strip for financial ratios
const ratioBsSummary = computed(() => {
  if (!props.report?.bs_summary) return []
  const s = props.report.bs_summary
  return [
    { key: 'assets',      label: isRtl.value ? 'إجمالي الأصول' : 'Total Assets',      value: s.total_assets,      color: 'var(--cv-blue-text)' },
    { key: 'liabilities', label: isRtl.value ? 'إجمالي الالتزامات' : 'Total Liabilities', value: s.total_liabilities, color: 'var(--cv-danger-text)' },
    { key: 'equity',      label: isRtl.value ? 'حقوق الملكية' : 'Total Equity',       value: s.total_equity,      color: 'var(--cv-green-bright)' },
    { key: 'revenue',     label: isRtl.value ? 'الإيرادات' : 'Revenue',               value: s.revenue,           color: 'var(--cv-blue-text)' },
    { key: 'net_income',  label: isRtl.value ? 'صافي الربح' : 'Net Income',           value: s.net_income,        color: s.net_income >= 0 ? 'var(--cv-green-bright)' : 'var(--cv-danger-text)' },
  ]
})

// ── Comment editing ────────────────────────────────────────────────
const editingComment = ref({})
const commentDraft   = ref({})

function startEditComment(ratio) {
  editingComment.value[ratio.key] = true
  commentDraft.value[ratio.key + '_comment'] = ratio.comment || ''
  commentDraft.value[ratio.key + '_action']  = ratio.action  || ''
}
function cancelEditComment(key) {
  editingComment.value[key] = false
}
function saveComment(ratio) {
  router.post(route('analytics.ratio-comment'), {
    fiscal_year_id: ratioFilters.value.fiscal_year_id,
    ratio_key:      ratio.key,
    comment:        commentDraft.value[ratio.key + '_comment'] || null,
    action:         commentDraft.value[ratio.key + '_action']  || null,
    company_id:     isSuperAdmin.value ? selectedCompanyId.value : undefined,
  }, {
    preserveScroll: true,
    onSuccess: () => { editingComment.value[ratio.key] = false },
  })
}

// ── Medal classes ──────────────────────────────────────────────────
function medalClass(rank) {
  if (rank === 1) return 'medal-gold'
  if (rank === 2) return 'medal-silver'
  if (rank === 3) return 'medal-bronze'
  return 'medal-plain'
}

// ── Number formatter ───────────────────────────────────────────────
function fmt(val) {
  if (val === null || val === undefined) return '—'
  return new Intl.NumberFormat(isRtl.value ? 'ar-EG' : 'en-EG', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(val)
}

// ── Bilingual UI strings ───────────────────────────────────────────
const ui = computed(() => isRtl.value ? {
  eyebrow:  'تحليلات متقدمة',
  title:    'التقارير التحليلية',
  subtitle: 'نسب مالية · تصنيف العملاء والموردين · تحليل مدة التغذية',
} : {
  eyebrow:  'Advanced Analytics',
  title:    'Analytical Reports',
  subtitle: 'Financial Ratios · Customer & Supplier Rankings · Feeding Duration Analysis',
})
</script>

<style>
/* Analytics page — global cv- classes only, no scoped overrides */

.analytics-tabs-bar {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--cv-border-md);
  padding-bottom: 0;
}
.analytics-tab {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: var(--cv-radius-sm) var(--cv-radius-sm) 0 0;
  font-size: 13px;
  color: var(--cv-text-muted);
  background: transparent;
  border: none;
  cursor: pointer;
  transition: color 0.15s, background 0.15s;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.analytics-tab:hover { color: var(--cv-text-secondary); background: var(--cv-bg-hover); }
.analytics-tab--active { color: var(--cv-amber); border-bottom-color: var(--cv-amber); background: var(--cv-amber-light); }
.analytics-tab-icon { width: 16px; height: 16px; }

.analytics-label {
  font-size: 11px;
  color: var(--cv-text-muted);
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.analytics-empty {
  text-align: center;
  padding: 64px 24px;
  color: var(--cv-text-muted);
  font-size: 14px;
}
.analytics-empty-icon { font-size: 40px; margin-bottom: 12px; }

.analytics-kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 24px;
}
.analytics-kpi {
  background: var(--cv-bg-card);
  border: 1px solid var(--cv-border-md);
  border-radius: var(--cv-radius);
  padding: 16px;
}
.analytics-kpi-label { font-size: 11px; color: var(--cv-text-muted); margin-bottom: 4px; }
.analytics-kpi-value { font-size: 18px; font-weight: 700; }

.analytics-group-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--cv-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.07em;
  margin-bottom: 12px;
}
.analytics-group-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}

.analytics-ratios-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}
.analytics-ratio-card {
  background: var(--cv-bg-card);
  border: 1px solid var(--cv-border-md);
  border-radius: var(--cv-radius-md);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color 0.15s;
}
.analytics-ratio-card.ratio-good    { border-color: var(--cv-success-border); }
.analytics-ratio-card.ratio-bad     { border-color: var(--cv-danger-border); }
.analytics-ratio-card.ratio-warning { border-color: var(--cv-warning-border); }
.analytics-ratio-bar {
  height: 3px;
  flex-shrink: 0;
}
.analytics-ratio-bar.ratio-good    { background: var(--cv-success); }
.analytics-ratio-bar.ratio-bad     { background: var(--cv-danger); }
.analytics-ratio-bar.ratio-warning { background: var(--cv-warning); }
.analytics-ratio-bar.ratio-neutral { background: var(--cv-border-md); }
.analytics-ratio-body { padding: 14px 16px 14px; flex: 1; display: flex; flex-direction: column; }
.analytics-ratio-label  { font-size: 13px; font-weight: 600; color: var(--cv-text-primary); }
.analytics-ratio-formula { font-size: 10px; color: var(--cv-text-muted); margin-top: 2px; }
.analytics-ratio-value  { font-size: 28px; font-weight: 800; margin-top: 8px; letter-spacing: -0.02em; }
.ratio-val-good    { color: var(--cv-success-text); }
.ratio-val-bad     { color: var(--cv-danger-text); }
.ratio-val-warning { color: var(--cv-amber-text); }

.analytics-comment-section { border-top: 1px solid var(--cv-border-md); padding-top: 10px; margin-top: auto; }
.analytics-comment-display { font-size: 12px; }
.analytics-comment-text    { color: var(--cv-text-secondary); margin-bottom: 3px; line-height: 1.4; }
.analytics-comment-chip    { display: inline-block; width: 16px; text-align: center; }
.analytics-comment-placeholder { color: var(--cv-text-muted); font-style: italic; font-size: 11px; }
.analytics-comment-btn {
  display: inline-flex; align-items: center; gap: 4px;
  margin-top: 6px; font-size: 11px; padding: 3px 8px;
  border: 1px solid var(--cv-border-md); border-radius: var(--cv-radius-sm);
  background: transparent; color: var(--cv-text-muted); cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.analytics-comment-btn:hover { color: var(--cv-amber); border-color: var(--cv-amber-border); }

/* Rank list */
.analytics-rank-header { display: flex; align-items: center; }
.analytics-rank-list { display: flex; flex-direction: column; gap: 8px; }
.analytics-rank-row {
  display: grid;
  grid-template-columns: 40px 1fr 120px 100px;
  align-items: center;
  gap: 12px;
  background: var(--cv-bg-card);
  border: 1px solid var(--cv-border-md);
  border-radius: var(--cv-radius);
  padding: 10px 14px;
}
.analytics-rank-medal {
  width: 36px; height: 36px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 14px; flex-shrink: 0;
}
.medal-gold   { background: rgba(255,215,0,0.15); color: #FFD700; border: 1px solid rgba(255,215,0,0.3); }
.medal-silver { background: rgba(192,192,192,0.15); color: #C0C0C0; border: 1px solid rgba(192,192,192,0.3); }
.medal-bronze { background: rgba(205,127,50,0.15); color: #CD7F32; border: 1px solid rgba(205,127,50,0.3); }
.medal-plain  { background: var(--cv-bg-hover); color: var(--cv-text-muted); border: 1px solid var(--cv-border-md); }
.analytics-rank-name { min-width: 0; }
.analytics-rank-bar-wrap { background: var(--cv-green-dim); border-radius: 4px; height: 6px; overflow: hidden; }
.analytics-rank-bar { height: 100%; background: var(--cv-green-bright); border-radius: 4px; transition: width 0.4s; min-width: 2px; }
.analytics-rank-bar.supplier-bar { background: var(--cv-amber); }
.analytics-rank-value { text-align: end; }

/* Breed group */
.analytics-breed-header {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin-bottom: 10px;
}
.analytics-breed-badge {
  padding: 3px 10px;
  background: var(--cv-green-dim); border: 1px solid var(--cv-green-border);
  border-radius: 20px; font-size: 12px; color: var(--cv-green-bright); font-weight: 600;
}

.analytics-section-title {
  font-size: 12px; font-weight: 600; color: var(--cv-text-muted);
  text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 12px;
}

/* Feeding duration buckets */
.feeding-bucket-strip {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
}
.feeding-bucket-card {
  background: var(--cv-bg-card);
  border: 1px solid var(--cv-border-md);
  border-radius: var(--cv-radius-md);
  padding: 14px 10px;
  text-align: center;
  display: flex; flex-direction: column; align-items: center; gap: 4px;
}
.feeding-bucket--0 { border-top: 3px solid var(--cv-blue); }
.feeding-bucket--1 { border-top: 3px solid var(--cv-green-bright); }
.feeding-bucket--2 { border-top: 3px solid var(--cv-amber); }
.feeding-bucket--3 { border-top: 3px solid #E8A030; }
.feeding-bucket--4 { border-top: 3px solid var(--cv-danger); }
.feeding-bucket-label { font-size: 11px; color: var(--cv-text-muted); }
.feeding-bucket-count { font-size: 28px; font-weight: 800; color: var(--cv-text-primary); line-height: 1; }
.feeding-bucket-pct   { font-size: 14px; font-weight: 600; color: var(--cv-amber); }
.feeding-bucket-bar-wrap { width: 100%; height: 48px; background: var(--cv-bg-hover); border-radius: 4px; overflow: hidden; display: flex; align-items: flex-end; }
.feeding-bucket-bar { width: 100%; background: var(--cv-green-bright); border-radius: 4px; transition: height 0.4s; min-height: 2px; }
.feeding-bucket-rev { margin-top: 2px; }

/* Feeding per-breed table */
.feeding-breed-table { width: 100%; }
.feeding-breed-header {
  display: grid;
  grid-template-columns: 140px 100px 80px 120px 1fr;
  gap: 8px;
  padding: 6px 8px;
  font-size: 11px; font-weight: 600; color: var(--cv-text-muted);
  text-transform: uppercase; letter-spacing: 0.05em;
  border-bottom: 1px solid var(--cv-border-md);
}
.feeding-breed-row {
  display: grid;
  grid-template-columns: 140px 100px 80px 120px 1fr;
  gap: 8px;
  padding: 8px;
  font-size: 13px;
  border-bottom: 1px solid var(--cv-border-md);
  transition: background 0.1s;
}
.feeding-breed-row:hover { background: var(--cv-bg-hover); }
.feeding-breed-row--dominant { background: var(--cv-amber-light); }
.feeding-dist-bar-wrap { display: flex; align-items: center; }
.feeding-dist-bar {
  height: 6px; background: var(--cv-amber);
  border-radius: 3px; transition: width 0.4s; min-width: 2px;
}

@media (max-width: 640px) {
  .analytics-rank-row { grid-template-columns: 36px 1fr 80px; }
  .analytics-rank-bar-wrap { display: none; }
  .feeding-bucket-strip { grid-template-columns: repeat(3, 1fr); }
  .analytics-ratios-grid { grid-template-columns: 1fr; }
  .feeding-breed-header, .feeding-breed-row { grid-template-columns: 120px 60px 60px 1fr; }
  .feeding-breed-row > div:nth-child(4) { display: none; }
}
</style>