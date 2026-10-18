<template>
    <AuthenticatedLayout>
        <div :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Flash ──────────────────────────────────────────── -->
            <div v-if="$page.props.flash?.success" class="cv-alert-success" style="margin:1rem 1.5rem 0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                {{ $page.props.flash.success }}
            </div>

            <!-- ── Hero ───────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-page-hero-eyebrow">
                    {{ lang === 'ar' ? 'المبيعات' : 'Sales' }}
                </div>
                <div class="cv-page-hero-title" style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                    <!-- Avatar -->
                    <div class="sale-hero-avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                            <polyline points="17 6 23 6 23 12"/>
                        </svg>
                    </div>
                    {{ sale.invoice_ref || (lang === 'ar' ? 'فاتورة بيع' : 'Sale Invoice') }}
                </div>
                <div class="cv-page-hero-sub">
                    {{ n(sale.farm, 'name') }} ·
                    {{ n(sale.customer, 'name') }} ·
                    {{ formatDate(sale.date) }}
                </div>
                <div class="cv-page-hero-actions">
                    <!-- Edit — only if draft -->
                    <Link v-if="sale.status === 'draft'" :href="route('sales.edit', sale.id)" class="cv-btn cv-btn-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        {{ lang === 'ar' ? 'تعديل' : 'Edit' }}
                    </Link>
                    <!-- Confirm — draft only -->
                    <button v-if="sale.status === 'draft' && canEdit" @click="openConfirmModal" class="cv-btn cv-btn-ghost" style="color:var(--cv-green-bright);border-color:var(--cv-green-border);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        {{ lang === 'ar' ? 'تأكيد البيع' : 'Confirm Sale' }}
                    </button>
                    <!-- Delete — draft only -->
                    <button v-if="sale.status === 'draft' && canDelete" @click="openDeleteModal" class="cv-btn cv-btn-ghost" style="color:var(--cv-danger-text);border-color:var(--cv-danger-border);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        {{ lang === 'ar' ? 'حذف' : 'Delete' }}
                    </button>
                    <!-- Back -->
                    <Link :href="route('sales.index')" class="cv-btn cv-btn-ghost">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        {{ lang === 'ar' ? 'رجوع' : 'Back' }}
                    </Link>
                </div>
            </div>

            <!-- ── Page Body ───────────────────────────────────────── -->
            <div class="cv-page">
                <div style="display:grid;grid-template-columns:1fr 340px;gap:1.25rem;align-items:start;">

                    <!-- ── LEFT COLUMN ─────────────────────────────── -->
                    <div style="display:flex;flex-direction:column;gap:1.25rem;">

                        <!-- §1 — Sale Details ─────────────────────── -->
                        <div class="cv-card">
                            <div class="cv-card-header" style="display:flex;align-items:center;gap:0.75rem;">
                                <div class="section-badge">١</div>
                                <div>
                                    <div class="cv-card-title">{{ lang === 'ar' ? 'تفاصيل البيع' : 'Sale Details' }}</div>
                                    <div class="cv-card-sub">{{ lang === 'ar' ? 'المعلومات الأساسية للفاتورة' : 'Core invoice information' }}</div>
                                </div>
                                <div style="margin-inline-start:auto;">
                                    <span :class="statusClass(sale.status)" class="cv-badge">
                                        {{ statusLabel(sale.status) }}
                                    </span>
                                </div>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">{{ lang === 'ar' ? 'رقم الفاتورة' : 'Invoice Ref' }}</span>
                                    <span class="detail-value">{{ sale.invoice_ref || '—' }}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">{{ lang === 'ar' ? 'تاريخ البيع' : 'Sale Date' }}</span>
                                    <span class="detail-value" style="color:var(--cv-blue-text);">{{ formatDate(sale.date) }}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">{{ lang === 'ar' ? 'تاريخ الاستحقاق' : 'Due Date' }}</span>
                                    <span class="detail-value" :style="isDue ? 'color:var(--cv-danger-text)' : 'color:var(--cv-blue-text)'">
                                        {{ sale.due_date ? formatDate(sale.due_date) : '—' }}
                                        <span v-if="isDue" class="cv-badge cv-badge-danger" style="font-size:0.6rem;padding:1px 6px;margin-inline-start:6px;">
                                            {{ lang === 'ar' ? 'متأخر' : 'Overdue' }}
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">{{ lang === 'ar' ? 'شروط الدفع' : 'Payment Terms' }}</span>
                                    <span class="detail-value">
                                        {{ sale.payment_terms_days != null
                                            ? (lang === 'ar' ? sale.payment_terms_days + ' يوم' : sale.payment_terms_days + ' days')
                                            : '—' }}
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}</span>
                                    <span class="detail-value">{{ n(sale.farm, 'name') || '—' }}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">{{ lang === 'ar' ? 'العميل' : 'Customer' }}</span>
                                    <span class="detail-value">{{ n(sale.customer, 'name') || '—' }}</span>
                                </div>
                                <div class="detail-item" v-if="sale.notes" style="grid-column:1/-1;">
                                    <span class="detail-label">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</span>
                                    <span class="detail-value" style="color:var(--cv-text-secondary);">{{ sale.notes }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- §2 — Sale Lines ───────────────────────── -->
                        <div class="cv-card">
                            <div class="cv-card-header" style="display:flex;align-items:center;gap:0.75rem;">
                                <div class="section-badge" style="background:linear-gradient(135deg,var(--cv-blue),#3878a8);">٢</div>
                                <div>
                                    <div class="cv-card-title">{{ lang === 'ar' ? 'بنود البيع' : 'Sale Lines' }}</div>
                                    <div class="cv-card-sub">
                                        {{ sale.lines_count }}
                                        {{ lang === 'ar' ? ' رأس / بند' : ' head / line(s)' }}
                                    </div>
                                </div>
                            </div>

                            <!-- Lines table -->
                            <div v-if="sale.lines && sale.lines.length" class="lines-table-wrap">
                                <table class="lines-table">
                                    <thead>
                                        <tr>
                                            <th>{{ lang === 'ar' ? 'الماشية' : 'Animal' }}</th>
                                            <th>{{ lang === 'ar' ? 'النوع' : 'Type' }}</th>
                                            <th style="text-align:end;">{{ lang === 'ar' ? 'الوزن (كجم)' : 'Weight (kg)' }}</th>
                                            <th style="text-align:end;">{{ lang === 'ar' ? 'السعر / كجم' : 'Price/kg' }}</th>
                                            <th style="text-align:end;">{{ lang === 'ar' ? 'السعر / رأس' : 'Price/head' }}</th>
                                            <th style="text-align:end;">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</th>
                                            <th style="text-align:end;">{{ lang === 'ar' ? 'هامش الربح' : 'Gross Margin' }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="line in sale.lines" :key="line.id">
                                            <td>
                                                <span class="animal-tag">{{ line.animal?.tag || '—' }}</span>
                                                <span v-if="line.animal?.breed" class="animal-breed">{{ line.animal.breed }}</span>
                                            </td>
                                            <td>
                                                <span :class="line.sale_type === 'carcass' ? 'cv-badge cv-badge-blue' : 'cv-badge cv-badge-green'" style="font-size:0.65rem;">
                                                    {{ line.sale_type === 'carcass'
                                                        ? (lang === 'ar' ? 'ذبيحة' : 'Carcass')
                                                        : (lang === 'ar' ? 'حي' : 'Live') }}
                                                </span>
                                            </td>
                                            <td style="text-align:end;color:var(--cv-blue-text);">
                                                {{ line.weight_kg != null ? fmtNum(line.weight_kg) : '—' }}
                                            </td>
                                            <td style="text-align:end;color:var(--cv-blue-text);">
                                                {{ line.price_per_kg != null ? fmtNum(line.price_per_kg) : '—' }}
                                            </td>
                                            <td style="text-align:end;color:var(--cv-blue-text);">
                                                {{ line.price_per_head != null ? fmtNum(line.price_per_head) : '—' }}
                                            </td>
                                            <td style="text-align:end;color:var(--cv-text-primary);font-weight:600;">
                                                {{ fmtMoney(line.line_total) }}
                                            </td>
                                            <td style="text-align:end;">
                                                <span v-if="line.gross_margin != null"
                                                    :style="line.gross_margin >= 0 ? 'color:var(--cv-green-bright)' : 'color:var(--cv-danger-text)'">
                                                    {{ fmtMoney(line.gross_margin) }}
                                                </span>
                                                <span v-else style="color:var(--cv-text-muted);">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- No lines yet -->
                            <div v-else class="cv-empty" style="padding:2rem 1rem;">
                                <div class="cv-empty-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;opacity:0.22;"><rect x="9" y="2" width="13" height="20" rx="2"/><path d="M5 6H2v14a2 2 0 0 0 2 2h13"/><line x1="13" y1="8" x2="18" y2="8"/><line x1="13" y1="12" x2="18" y2="12"/></svg>
                                </div>
                                <div class="cv-empty-title">{{ lang === 'ar' ? 'لا توجد بنود بعد' : 'No lines yet' }}</div>
                                <div class="cv-empty-sub">{{ lang === 'ar' ? 'سيتم إضافة البنود عند التعديل' : 'Lines will appear after editing' }}</div>
                            </div>
                        </div>

                    </div><!-- /left column -->

                    <!-- ── RIGHT COLUMN ────────────────────────────── -->
                    <div style="display:flex;flex-direction:column;gap:1.25rem;">

                        <!-- Financial summary card ─────────────────── -->
                        <div class="cv-card">
                            <div class="cv-card-header">
                                <div class="cv-card-title">{{ lang === 'ar' ? 'ملخص مالي' : 'Financial Summary' }}</div>
                            </div>

                            <div style="display:flex;flex-direction:column;gap:0.625rem;">
                                <div class="summary-row">
                                    <span class="summary-label">{{ lang === 'ar' ? 'المجموع الفرعي' : 'Subtotal' }}</span>
                                    <span class="summary-value">{{ fmtMoney(sale.subtotal) }}</span>
                                </div>
                                <div v-if="sale.discount > 0" class="summary-row">
                                    <span class="summary-label" style="color:var(--cv-warning-text);">{{ lang === 'ar' ? 'الخصم' : 'Discount' }}</span>
                                    <span class="summary-value" style="color:var(--cv-warning-text);">− {{ fmtMoney(sale.discount) }}</span>
                                </div>
                                <div class="summary-divider"></div>
                                <div class="summary-row" style="padding-top:0.25rem;">
                                    <span class="summary-label" style="font-weight:700;font-size:0.9rem;color:var(--cv-text-primary);">
                                        {{ lang === 'ar' ? 'الإجمالي' : 'Total' }}
                                    </span>
                                    <span class="summary-total">{{ fmtMoney(sale.total_amount) }} {{ sale.currency }}</span>
                                </div>
                            </div>

                            <!-- Gross margin summary -->
                            <div v-if="totalGrossMargin !== null" style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--cv-border);">
                                <div class="summary-row">
                                    <span class="summary-label">{{ lang === 'ar' ? 'إجمالي هامش الربح' : 'Total Gross Margin' }}</span>
                                    <span :style="totalGrossMargin >= 0 ? 'color:var(--cv-green-bright);font-weight:700;' : 'color:var(--cv-danger-text);font-weight:700;'">
                                        {{ fmtMoney(totalGrossMargin) }}
                                    </span>
                                </div>
                                <div class="summary-row" style="margin-top:0.375rem;">
                                    <span class="summary-label">{{ lang === 'ar' ? 'نسبة الهامش' : 'Margin %' }}</span>
                                    <span :style="marginPct >= 0 ? 'color:var(--cv-blue-text);font-weight:600;' : 'color:var(--cv-danger-text);font-weight:600;'">
                                        {{ marginPct }}%
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Status timeline card ──────────────────── -->
                        <div class="cv-card">
                            <div class="cv-card-header">
                                <div class="cv-card-title">{{ lang === 'ar' ? 'حالة الطلب' : 'Order Status' }}</div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:0;">
                                <div v-for="step in statusSteps" :key="step.key" class="timeline-step">
                                    <div class="timeline-dot" :class="step.done ? 'done' : step.active ? 'active' : ''">
                                        <svg v-if="step.done" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:10px;height:10px;"><polyline points="20 6 9 17 4 12"/></svg>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-label" :style="step.active ? 'color:var(--cv-amber);' : step.done ? 'color:var(--cv-green-bright);' : ''">
                                            {{ step.label }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Carcass details (if any carcass lines) ─── -->
                        <div v-if="hasCarcassLines" class="cv-card">
                            <div class="cv-card-header">
                                <div class="cv-card-title">{{ lang === 'ar' ? 'بيانات الذبح' : 'Slaughter Data' }}</div>
                                <div class="cv-card-sub">{{ lang === 'ar' ? 'أوزان الذبائح المرتبطة' : 'Linked carcass weights' }}</div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:0.5rem;">
                                <div v-for="line in carcassLines" :key="line.id" class="carcass-row">
                                    <span class="animal-tag">{{ line.animal?.tag }}</span>
                                    <div style="display:flex;gap:1rem;font-size:0.78rem;color:var(--cv-blue-text);">
                                        <span>{{ lang === 'ar' ? 'حي:' : 'Live:' }} {{ fmtNum(line.slaughter_record?.liveweight_kg) }} kg</span>
                                        <span>{{ lang === 'ar' ? 'ذبيحة:' : 'DW:' }} {{ fmtNum(line.slaughter_record?.deadweight_kg) }} kg</span>
                                        <span style="color:var(--cv-amber);">{{ fmtNum(line.slaughter_record?.dressing_pct) }}%</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /right column -->
                </div>
            </div><!-- /cv-page -->

            <!-- ── Confirm Sale Modal ──────────────────────────────── -->
            <Teleport to="body">
                <div v-if="showConfirmModal" class="cv-modal-backdrop" @click.self="showConfirmModal = false">
                    <div class="cv-modal cv-modal-sm">
                        <div class="cv-modal-header">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--cv-success-bg);border:1px solid var(--cv-success-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-green-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                </div>
                                <span style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary);">
                                    {{ lang === 'ar' ? 'تأكيد البيع' : 'Confirm Sale' }}
                                </span>
                            </div>
                            <button @click="showConfirmModal = false" class="cv-modal-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="cv-modal-body" style="font-size:0.875rem;color:var(--cv-text-secondary);line-height:1.6;">
                            <p>{{ lang === 'ar'
                                ? 'بعد التأكيد لن تتمكن من تعديل هذه الفاتورة. سيتم تحديث حالة الماشيةات إلى "مباع".'
                                : 'Once confirmed, this sale cannot be edited. Animal statuses will be updated to "sold".' }}</p>
                            <div class="cv-alert-warning" style="margin-top:0.875rem;font-size:0.8125rem;">
                                {{ lang === 'ar'
                                    ? '⚠ هذا الإجراء لا يمكن التراجع عنه.'
                                    : '⚠ This action is irreversible.' }}
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button @click="showConfirmModal = false" class="cv-btn cv-btn-ghost" style="font-size:0.875rem;">
                                {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button @click="confirmSale" :disabled="processing" class="cv-btn cv-btn-amber" style="font-size:0.875rem;">
                                <svg v-if="processing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;animation:spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                {{ lang === 'ar' ? 'تأكيد البيع' : 'Confirm Sale' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

            <!-- ── Delete Modal ────────────────────────────────────── -->
            <Teleport to="body">
                <div v-if="showDeleteModal" class="cv-modal-backdrop" @click.self="showDeleteModal = false">
                    <div class="cv-modal cv-modal-sm">
                        <div class="cv-modal-header">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                </div>
                                <span style="font-weight:700;font-size:0.9375rem;color:var(--cv-text-primary);">
                                    {{ lang === 'ar' ? 'حذف الفاتورة' : 'Delete Sale' }}
                                </span>
                            </div>
                            <button @click="showDeleteModal = false" class="cv-modal-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="cv-modal-body">
                            <div class="cv-alert-danger" style="font-size:0.875rem;line-height:1.6;">
                                {{ lang === 'ar'
                                    ? 'سيتم حذف هذه الفاتورة وجميع بنودها بشكل نهائي. لا يمكن التراجع عن هذا الإجراء.'
                                    : 'This sale and all its lines will be permanently deleted. This cannot be undone.' }}
                            </div>
                        </div>
                        <div class="cv-modal-footer">
                            <button @click="showDeleteModal = false" class="cv-btn cv-btn-ghost" style="font-size:0.875rem;">
                                {{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
                            </button>
                            <button @click="deleteSale" :disabled="processing" class="cv-btn cv-btn-danger" style="font-size:0.875rem;">
                                <svg v-if="processing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;animation:spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                {{ lang === 'ar' ? 'حذف نهائي' : 'Delete Permanently' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

// ── Props ──────────────────────────────────────────────────────────
const props = defineProps({
    sale:     { type: Object, required: true },
})

// ── Locale ─────────────────────────────────────────────────────────
const { locale: lang, isRtl, n } = useLocale()

// ── Permissions ────────────────────────────────────────────────────
const { can } = usePermissions()
const canEdit   = computed(() => can('edit sales'))
const canDelete = computed(() => can('delete sales'))

// ── Modal state ────────────────────────────────────────────────────
const showConfirmModal = ref(false)
const showDeleteModal  = ref(false)
const processing       = ref(false)

const openConfirmModal = () => { showConfirmModal.value = true }
const openDeleteModal  = () => { showDeleteModal.value  = true }

// ── Actions ────────────────────────────────────────────────────────
function confirmSale() {
    processing.value = true
    router.patch(route('sales.confirm', props.sale.id), {}, {
        onFinish: () => { processing.value = false; showConfirmModal.value = false },
    })
}

function deleteSale() {
    processing.value = true
    router.delete(route('sales.destroy', props.sale.id), {
        onFinish: () => { processing.value = false; showDeleteModal.value = false },
    })
}

// ── Computed: financial totals ─────────────────────────────────────
const totalGrossMargin = computed(() => {
    if (!props.sale.lines?.length) return null
    const lines = props.sale.lines.filter(l => l.gross_margin != null)
    if (!lines.length) return null
    return lines.reduce((sum, l) => sum + Number(l.gross_margin), 0)
})

const marginPct = computed(() => {
    if (totalGrossMargin.value === null || !props.sale.total_amount) return '—'
    return ((totalGrossMargin.value / props.sale.total_amount) * 100).toFixed(1)
})

// ── Computed: carcass lines ────────────────────────────────────────
const carcassLines = computed(() =>
    (props.sale.lines || []).filter(l => l.sale_type === 'carcass' && l.slaughter_record)
)
const hasCarcassLines = computed(() => carcassLines.value.length > 0)

// ── Status helpers ─────────────────────────────────────────────────
const statusSteps = computed(() => {
    const current = props.sale.status
    const steps = [
        { key: 'draft',     label: lang.value === 'ar' ? 'مسودة'       : 'Draft' },
        { key: 'confirmed', label: lang.value === 'ar' ? 'مؤكد'        : 'Confirmed' },
        { key: 'paid',      label: lang.value === 'ar' ? 'مدفوع'       : 'Paid' },
    ]
    const order = ['draft','confirmed','paid']
    const currentIdx = order.indexOf(current)
    return steps.map((s, i) => ({
        ...s,
        done:   i < currentIdx,
        active: i === currentIdx,
    }))
})

function statusLabel(status) {
    const labels = {
        draft:     lang.value === 'ar' ? 'مسودة'     : 'Draft',
        confirmed: lang.value === 'ar' ? 'مؤكد'      : 'Confirmed',
        paid:      lang.value === 'ar' ? 'مدفوع'     : 'Paid',
        cancelled: lang.value === 'ar' ? 'ملغي'      : 'Cancelled',
    }
    return labels[status] || status
}

function statusClass(status) {
    return {
        draft:     'cv-badge-warning',
        confirmed: 'cv-badge-green',
        paid:      'cv-badge-blue',
        cancelled: 'cv-badge-danger',
    }[status] || 'cv-badge-muted'
}

// ── Due date ───────────────────────────────────────────────────────
const isDue = computed(() => {
    if (!props.sale.due_date || props.sale.status === 'paid') return false
    return new Date(props.sale.due_date) < new Date()
})

// ── Formatters ─────────────────────────────────────────────────────
function formatDate(dateStr) {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleDateString(lang.value === 'ar' ? 'ar-EG' : 'en-GB', {
        year: 'numeric', month: 'short', day: 'numeric',
    })
}

function fmtNum(val, decimals = 2) {
    if (val == null || val === '') return '—'
    return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals,
    })
}

function fmtMoney(val) {
    if (val == null) return '—'
    return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    })
}
</script>

<style scoped>
/* ── Hero avatar ─────────────────────────────────────────── */
.sale-hero-avatar {
    width: 46px; height: 46px; border-radius: 12px;
    background: linear-gradient(135deg, var(--cv-green-deep), var(--cv-green));
    border: 1px solid var(--cv-green-border);
    display: flex; align-items: center; justify-content: center;
    color: var(--cv-green-bright); flex-shrink: 0;
}

/* ── Section badge ───────────────────────────────────────── */
.section-badge {
    width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--cv-green-deep), var(--cv-green));
    border: 1px solid var(--cv-green-border);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8125rem; font-weight: 700; color: var(--cv-green-bright);
}

/* ── Detail grid ─────────────────────────────────────────── */
.detail-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1.25rem;
    margin-top: 1rem;
}
.detail-item { display: flex; flex-direction: column; gap: 0.25rem; }
.detail-label {
    font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--cv-text-muted);
}
.detail-value { font-size: 0.875rem; color: var(--cv-text-primary); font-weight: 500; }

/* ── Lines table ─────────────────────────────────────────── */
.lines-table-wrap { overflow-x: auto; margin-top: 0.75rem; }
.lines-table {
    width: 100%; border-collapse: collapse; font-size: 0.8125rem;
}
.lines-table th {
    padding: 0.5rem 0.75rem; text-align: start;
    color: var(--cv-text-muted); font-size: 0.6875rem;
    font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
    border-bottom: 1px solid var(--cv-border);
}
.lines-table td {
    padding: 0.625rem 0.75rem;
    color: var(--cv-text-secondary);
    border-bottom: 1px solid var(--cv-border);
}
.lines-table tbody tr:last-child td { border-bottom: none; }
.lines-table tbody tr:hover { background: var(--cv-bg-hover); }

.animal-tag {
    display: inline-flex; align-items: center;
    background: var(--cv-green-dim); border: 1px solid var(--cv-green-border);
    border-radius: 4px; padding: 1px 7px;
    font-size: 0.75rem; font-weight: 600; color: var(--cv-green-bright);
    font-family: monospace; letter-spacing: 0.04em;
}
.animal-breed {
    display: block; font-size: 0.7rem; color: var(--cv-text-muted); margin-top: 2px;
}

/* ── Financial summary ───────────────────────────────────── */
.summary-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.5rem;
}
.summary-label { font-size: 0.8125rem; color: var(--cv-text-secondary); }
.summary-value { font-size: 0.875rem; color: var(--cv-blue-text); font-weight: 600; font-variant-numeric: tabular-nums; }
.summary-total {
    font-size: 1.0625rem; font-weight: 800;
    color: var(--cv-green-bright);
    font-variant-numeric: tabular-nums;
}
.summary-divider { border-top: 1px solid var(--cv-border); margin: 0.375rem 0; }

/* ── Status timeline ─────────────────────────────────────── */
.timeline-step {
    display: flex; align-items: flex-start; gap: 0.75rem;
    padding: 0.5rem 0; position: relative;
}
.timeline-step:not(:last-child)::before {
    content: '';
    position: absolute;
    inset-inline-start: 9px;
    top: 28px;
    width: 1px; height: calc(100% - 4px);
    background: var(--cv-border);
}
.timeline-dot {
    width: 20px; height: 20px; border-radius: 50%; flex-shrink: 0;
    border: 2px solid var(--cv-border-md);
    background: var(--cv-bg-card-alt);
    display: flex; align-items: center; justify-content: center;
    margin-top: 2px;
}
.timeline-dot.done {
    background: var(--cv-green-dim); border-color: var(--cv-green-border);
    color: var(--cv-green-bright);
}
.timeline-dot.active {
    background: var(--cv-amber-light); border-color: var(--cv-amber-border);
    box-shadow: 0 0 0 3px var(--cv-amber-light);
}
.timeline-label { font-size: 0.8125rem; color: var(--cv-text-secondary); padding-top: 2px; }

/* ── Carcass row ─────────────────────────────────────────── */
.carcass-row {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 0.5rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--cv-border);
}
.carcass-row:last-child { border-bottom: none; }

/* ── Spin animation ──────────────────────────────────────── */
@keyframes spin { to { transform: rotate(360deg); } }
</style>