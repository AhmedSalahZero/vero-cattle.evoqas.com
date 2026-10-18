<template>
	<AuthenticatedLayout>
		<div :dir="isRtl ? 'rtl' : 'ltr'">
			<!-- ── Hero ──────────────────────────────────────────────────── -->
			<div class="cv-page-hero">
				<div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
				<div class="cv-page-hero-title">{{ isEdit ? t.editTitle : t.createTitle }}</div>
				<div class="cv-page-hero-sub">
					<span v-if="selectedSupplier">{{ n(selectedSupplier, 'name') }}</span>
					<span v-else>{{ t.sub }}</span>
					<span v-if="parseFloat(form.total) > 0"
						style="color:var(--cv-amber-text);margin-inline-start:0.5rem"> —
						{{ fmtAmt(form.total, form.currency) }}
						<span v-if="form.currency !== 'EGP' && parseFloat(form.exchange_rate) > 0"> (≈
							{{ fmt(parseFloat(form.total) * parseFloat(form.exchange_rate)) }} {{ t.egp }}) </span>
					</span>
				</div>
				<div class="cv-page-hero-actions">
					<Link :href="route('ap-invoices.index')" class="cv-btn cv-btn-ghost cv-btn-sm">{{ t.back }}</Link>
				</div>
			</div>
			<div class="cv-page">
				<form @submit.prevent="submit">
					<div class="flex flex-col gap-5" style="max-width:860px">
						<!-- ══ Section 1 — Invoice Header ═══════════════════════ -->
						<div class="cv-card">
							<div class="flex items-center gap-3 mb-4">
								<div class="section-badge">1</div>
								<div style="font-weight:700;font-size:0.95rem;color:var(--cv-text-primary)">{{ t.sec1 }}
								</div>
							</div>
							<div class="form-grid">
								<!-- Super Admin company selector -->
								<div v-if="showCompanyPicker" class="cv-field col-span-2">
									<label class="cv-label">{{ t.company }} <span
											style="color:var(--cv-danger-text)">*</span></label>
									<select v-model="form.company_id" class="cv-select"
										:class="{ 'cv-select--error': errors.company_id }" @change="onCompanyChange">
										<option value="">{{ t.selectCompany }}</option>
										<option v-for="c in companies" :key="c.id" :value="c.id">{{ n(c, 'name') }}
										</option>
									</select>
									<div v-if="errors.company_id" class="cv-field-error">{{ errors.company_id }}</div>
								</div>
								<!-- Supplier -->
								<div class="cv-field">
									<label class="cv-label">{{ t.supplier }} <span
											style="color:var(--cv-danger-text)">*</span></label>
									<select v-model="form.supplier_id" class="cv-select"
										:class="{ 'cv-select--error': errors.supplier_id }"
										:disabled="showCompanyPicker && !form.company_id">
										<option value="">{{ t.selectSupplier }}</option>
										<option v-for="s in dynamicSuppliers" :key="s.id" :value="s.id">
											{{ n(s, 'name') }}
										</option>
									</select>
									<div v-if="errors.supplier_id" class="cv-field-error">{{ errors.supplier_id }}</div>
									<div v-if="showCompanyPicker && !form.company_id"
										style="font-size:0.7rem;color:var(--cv-text-muted);margin-top:0.25rem">
										{{ t.selectCompanyFirst }}
									</div>
								</div>
								<!-- ── PO Warning Banner ──────────────────────────────────── -->
								<!-- Shows when selected supplier has open POs with unmatched livestock accruals -->
								<div v-if="openPosForSelectedSupplier.length > 0"
									style="grid-column:1/-1;background:var(--cv-amber-light);border:1px solid var(--cv-amber);border-radius:var(--cv-radius);padding:0.85rem 1rem;display:flex;gap:0.75rem;align-items:flex-start">
									<!-- Icon -->
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)"
										stroke-width="1.7" flex-shrink="0" style="flex-shrink:0;margin-top:0.1rem">
										<path
											d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
										<line x1="12" y1="9" x2="12" y2="13" />
										<line x1="12" y1="17" x2="12.01" y2="17" />
									</svg>
									<div>
										<div
											style="font-weight:700;font-size:0.78rem;color:var(--cv-amber);margin-bottom:0.35rem;text-transform:uppercase;letter-spacing:0.05em">
											{{ lang === 'ar' ? 'يوجد أوامر شراء مفتوحة' : 'Open Purchase Orders Detected' }}
										</div>
										<div
											style="font-size:0.78rem;color:var(--cv-text-secondary);margin-bottom:0.5rem">
											{{ lang === 'ar'
												? 'هذا المورد لديه إيصالات مواشي غير مُطابَقة. بعد حفظ الفاتورة، يمكنك مطابقتها من صفحة عرض الفاتورة.'
												: 'This supplier has unmatched livestock receipts from open POs. After saving, match them from the invoice\'s Show page.' }}
										</div>
										<div class="flex flex-col gap-1">
											<div v-for="po in openPosForSelectedSupplier" :key="po.id"
												style="font-size:0.75rem;color:var(--cv-text-primary);display:flex;gap:0.5rem;align-items:center">
												<span
													style="font-weight:600;color:var(--cv-amber-text)">{{ po.po_reference }}</span>
												<span style="color:var(--cv-text-muted)">·</span>
												<span>{{ po.received_head }} {{ lang === 'ar' ? 'رأس' : 'head' }}</span>
												<span style="color:var(--cv-text-muted)">·</span>
												<span style="color:var(--cv-blue-text);font-weight:600">
													{{ lang === 'ar'
														? po.unmatched_total.toLocaleString('ar-EG', { minimumFractionDigits: 2 })
														: po.unmatched_total.toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
													{{ lang === 'ar' ? 'ج.م' : 'EGP' }}
												</span>
											</div>
										</div>
									</div>
								</div>
								<!-- Date -->
								<div class="cv-field">
									<label class="cv-label">{{ t.date }} <span
											style="color:var(--cv-danger-text)">*</span></label>
									<input type="date" v-model="form.date" class="cv-input" :max="today"
										:class="{ 'cv-input--error': errors.date }" />
									<div v-if="errors.date" class="cv-field-error">{{ errors.date }}</div>
								</div>
								<!-- Due Date -->
								<div class="cv-field">
									<label class="cv-label">{{ t.dueDate }}</label>
									<input type="date" v-model="form.due_date" class="cv-input"
										:min="form.date || ''" />
								</div>
								<!-- Reference -->
								<!-- <div class="cv-field">
									<label class="cv-label">{{ t.reference }}</label>
									<input type="text" v-model="form.reference" class="cv-input"
										:placeholder="t.referencePh" maxlength="100" />
								</div> -->
							</div>
						</div>
						<!-- ══ Section 1.5 — Invoice Purpose ══════════════════════ -->
						<div class="cv-card">
							<div class="flex items-center gap-3 mb-4">
								<div class="section-badge"
									style="background:linear-gradient(135deg,var(--cv-blue-text),#1a6fa8)">★</div>
								<div style="font-weight:700;font-size:0.95rem;color:var(--cv-text-primary)">
									{{ t.secType }}
								</div>
							</div>
							<!-- Three purpose buttons -->
							<div class="flex flex-wrap gap-3" style="margin-bottom:1rem">
								<!-- Cattle Procurement -->
								<button type="button" @click="form.invoice_type = 'cattle'; onInvoiceTypeChange()"
									:disabled="isEdit"
									style="flex:1;min-width:180px;padding:0.875rem 1rem;border-radius:var(--cv-radius);border:2px solid;cursor:pointer;text-align:start;transition:all 0.15s"
									:style="form.invoice_type === 'cattle'
										? 'border-color:var(--cv-green-bright);background:var(--cv-green-light)'
										: 'border-color:var(--cv-border);background:var(--cv-bg-card-alt)'">
									<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
											stroke="currentColor" stroke-width="1.7"
											:style="form.invoice_type === 'cattle' ? 'color:var(--cv-green-bright)' : 'color:var(--cv-text-muted)'">
											<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
											<polyline points="9 22 9 12 15 12 15 22" />
										</svg>
										<span style="font-weight:700;font-size:0.85rem"
											:style="form.invoice_type === 'cattle' ? 'color:var(--cv-green-bright)' : 'color:var(--cv-text-primary)'">
											{{ t.typeCattle }}
										</span>
									</div>
									<div style="font-size:0.72rem;color:var(--cv-text-muted);line-height:1.4">
										{{ t.typeCattleDesc }}
									</div>
								</button>
								<!-- Inventory Procurement -->
								<button type="button" @click="form.invoice_type = 'inventory'; onInvoiceTypeChange()"
									:disabled="isEdit"
									style="flex:1;min-width:180px;padding:0.875rem 1rem;border-radius:var(--cv-radius);border:2px solid;cursor:pointer;text-align:start;transition:all 0.15s"
									:style="form.invoice_type === 'inventory'
										? 'border-color:var(--cv-amber);background:var(--cv-amber-light)'
										: 'border-color:var(--cv-border);background:var(--cv-bg-card-alt)'">
									<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
											stroke="currentColor" stroke-width="1.7"
											:style="form.invoice_type === 'inventory' ? 'color:var(--cv-amber-text)' : 'color:var(--cv-text-muted)'">
											<path
												d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
										</svg>
										<span style="font-weight:700;font-size:0.85rem"
											:style="form.invoice_type === 'inventory' ? 'color:var(--cv-amber-text)' : 'color:var(--cv-text-primary)'">
											{{ t.typeInventory }}
										</span>
									</div>
									<div style="font-size:0.72rem;color:var(--cv-text-muted);line-height:1.4">
										{{ t.typeInventoryDesc }}
									</div>
								</button>
								<!-- Other -->
								<button type="button" @click="form.invoice_type = 'other'; onInvoiceTypeChange()"
									:disabled="isEdit"
									style="flex:1;min-width:180px;padding:0.875rem 1rem;border-radius:var(--cv-radius);border:2px solid;cursor:pointer;text-align:start;transition:all 0.15s"
									:style="form.invoice_type === 'other'
										? 'border-color:var(--cv-blue-text);background:var(--cv-blue-light)'
										: 'border-color:var(--cv-border);background:var(--cv-bg-card-alt)'">
									<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
											stroke="currentColor" stroke-width="1.7"
											:style="form.invoice_type === 'other' ? 'color:var(--cv-blue-text)' : 'color:var(--cv-text-muted)'">
											<circle cx="12" cy="12" r="10" />
											<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
											<line x1="12" y1="17" x2="12.01" y2="17" />
										</svg>
										<span style="font-weight:700;font-size:0.85rem"
											:style="form.invoice_type === 'other' ? 'color:var(--cv-blue-text)' : 'color:var(--cv-text-primary)'">
											{{ t.typeOther }}
										</span>
									</div>
									<div style="font-size:0.72rem;color:var(--cv-text-muted);line-height:1.4">
										{{ t.typeOtherDesc }}
									</div>
								</button>
							</div>
							<!-- Edit mode: type is locked -->
							<div v-if="isEdit"
								style="font-size:0.72rem;color:var(--cv-text-muted);padding:0.5rem 0.75rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);border:1px solid var(--cv-border)">
								{{ lang === 'ar' ? 'نوع الفاتورة لا يمكن تغييره بعد الإنشاء.' : 'Invoice purpose cannot be changed after creation.' }}
							</div>
							<!-- Expense account selector — only for 'other' type -->
							<div v-if="isOtherType" class="cv-field" style="margin-top:1rem">
								<label class="cv-label">
									{{ t.expenseAccount }} <span style="color:var(--cv-danger-text)">*</span>
								</label>
								<select v-model="form.expense_account_id" class="cv-select"
									:class="{ 'cv-select--error': errors.expense_account_id }"
									:disabled="showCompanyPicker && !form.company_id">
									<option value="">{{ t.selectExpenseAccount }}</option>
									<option v-for="acc in dynamicExpenseAccounts" :key="acc.id" :value="acc.id">
										{{ acc.code }} — {{ n(acc, 'name') }}
									</option>
								</select>
								<div v-if="errors.expense_account_id" class="cv-field-error">
									{{ errors.expense_account_id }}
								</div>
							</div>
							<!-- No PO warning — shown for cattle/inventory when supplier has no unmatched receipts -->
							<div v-if="isProcurementType && form.supplier_id && openPosForSelectedSupplier.length === 0 && dynamicSuppliers.length > 0"
								style="margin-top:1rem;background:var(--cv-danger-light,#2d1a1a);border:1px solid var(--cv-danger-border,var(--cv-danger-text));border-radius:var(--cv-radius);padding:0.75rem 1rem;display:flex;gap:0.6rem;align-items:flex-start">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
									stroke="var(--cv-danger-text)" stroke-width="1.7"
									style="flex-shrink:0;margin-top:0.1rem">
									<circle cx="12" cy="12" r="10" />
									<line x1="12" y1="8" x2="12" y2="12" />
									<line x1="12" y1="16" x2="12.01" y2="16" />
								</svg>
								<div style="font-size:0.78rem;color:var(--cv-danger-text);line-height:1.5">
									{{ t.noPOWarning }}
								</div>
							</div>
						</div>
						<!-- ══ Section 2 — Amount & Currency ════════════════════ -->
						<div class="cv-card">
							<div class="flex items-center gap-3 mb-4">
								<div class="section-badge">2</div>
								<div style="font-weight:700;font-size:0.95rem;color:var(--cv-text-primary)">{{ t.sec2 }}
								</div>
							</div>
							<div class="form-grid">
								<!-- Currency -->
								<div class="cv-field">
									<label class="cv-label">{{ t.currency }} <span
											style="color:var(--cv-danger-text)">*</span></label>
									<select v-model="form.currency" class="cv-select" @change="onCurrencyChange">
										<option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
									</select>
								</div>
								<!-- Exchange Rate — shown only for foreign currencies -->
								<!-- 📌 InvoiceForeignCurrencyBalance: rate required when currency ≠ EGP -->
								<div class="cv-field" v-show="form.currency !== 'EGP'">
									<label class="cv-label">
										{{ t.exchangeRate }} <span style="color:var(--cv-danger-text)">*</span>
										<span
											style="font-size:0.68rem;color:var(--cv-text-muted);font-weight:400;margin-inline-start:0.35rem">
											1 {{ form.currency || '?' }} = ? {{ t.egp }} · <span
												style="color:var(--cv-amber-text)">{{ lang === 'ar' ? '(مُعبّأ تلقائياً)' : '(auto-filled)' }}</span>
										</span>
									</label>
									<input type="number" v-model="form.exchange_rate" class="cv-input"
										:class="{ 'cv-input--error': errors.exchange_rate }"
										:placeholder="t.exchangeRatePh" step="0.000001" min="0.000001" />
									<div v-if="errors.exchange_rate" class="cv-field-error">{{ errors.exchange_rate }}
									</div>
									<!-- Live EGP preview -->
									<div v-if="parseFloat(form.total) > 0 && parseFloat(form.exchange_rate) > 0"
										style="font-size:0.72rem;margin-top:0.3rem;color:var(--cv-blue-text)">
										{{ t.egpEquiv }}:
										{{ fmt(parseFloat(form.total) * parseFloat(form.exchange_rate)) }} {{ t.egp }}
									</div>
								</div>
								<!-- Total -->
								<div class="cv-field">
									<label class="cv-label">
										{{ t.total }} <span style="color:var(--cv-danger-text)">*</span>
										<span
											style="font-size:0.68rem;color:var(--cv-text-muted);font-weight:400;margin-inline-start:0.25rem">{{ form.currency || 'EGP' }}</span>
									</label>
									<div class="cv-input-group">
										<span class="cv-input-prefix">{{ form.currency || 'EGP' }}</span>
										<input type="number" v-model="form.total" class="cv-input"
											:class="{ 'cv-input--error': errors.total }" :placeholder="t.totalPh"
											step="0.01" min="0.01" />
									</div>
									<div v-if="errors.total" class="cv-field-error">{{ errors.total }}</div>
								</div>
								<!-- EGP summary box for FX invoices -->
								<div v-if="form.currency !== 'EGP' && parseFloat(form.exchange_rate) > 0 && parseFloat(form.total) > 0"
									style="grid-column:1/-1;background:var(--cv-blue-light);border:1px solid var(--cv-blue-border);border-radius:var(--cv-radius);padding:0.75rem 1rem">
									<div
										style="font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--cv-blue-text);margin-bottom:0.35rem">
										{{ t.fxSummary }}
									</div>
									<div class="flex gap-4 flex-wrap">
										<div>
											<div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.fxAmount }}
											</div>
											<div style="font-size:0.95rem;font-weight:700;color:var(--cv-text-primary)">
												{{ fmtForeign(form.total) }} {{ form.currency }}
											</div>
										</div>
										<div>
											<div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.fxRate }}
											</div>
											<div style="font-size:0.95rem;font-weight:700;color:var(--cv-blue-text)">×
												{{ fmtRate(form.exchange_rate) }}</div>
										</div>
										<div>
											<div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.fxEgp }}
											</div>
											<div style="font-size:0.95rem;font-weight:700;color:var(--cv-amber-text)">
												{{ fmt(parseFloat(form.total) * parseFloat(form.exchange_rate)) }}
												{{ t.egp }}
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<!-- ══ Section 3 — Description & Notes ════════════════ -->
						<div class="cv-card">
							<div class="flex items-center gap-3 mb-4">
								<div class="section-badge">3</div>
								<div style="font-weight:700;font-size:0.95rem;color:var(--cv-text-primary)">{{ t.sec3 }}
								</div>
							</div>
							<div class="flex flex-col gap-4">
								<div class="cv-field" style="margin:0">
									<label class="cv-label">{{ t.description }}</label>
									<input type="text" v-model="form.description" class="cv-input"
										:placeholder="t.descriptionPh" maxlength="255" />
								</div>
								<div class="cv-field" style="margin:0">
									<label class="cv-label">{{ t.notes }}</label>
									<textarea v-model="form.notes" class="cv-textarea" rows="3" :placeholder="t.notesPh"
										maxlength="1000" />
								</div>
							</div>
						</div>
						<!-- ── Submit ──────────────────────────────────────── -->
						<div class="flex gap-3 justify-end" style="padding-bottom:2rem">
							<Link :href="route('ap-invoices.index')" class="cv-btn cv-btn-ghost">{{ t.cancel }}</Link>
							<button type="submit" class="cv-btn cv-btn-amber" :disabled="form.processing || !canSave">
								<svg v-if="form.processing" width="14" height="14" viewBox="0 0 24 24" fill="none"
									stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite">
									<path d="M21 12a9 9 0 1 1-6.219-8.56" />
								</svg>
								{{ form.processing ? t.saving : (isEdit ? t.saveEdit : t.saveCreate) }}
							</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</AuthenticatedLayout>
</template>
<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

const props = defineProps({
	mode: { type: String, default: 'create' },
	invoice: { type: Object, default: null },
	suppliers: { type: Array, default: () => [] },
	companies: { type: Array, default: () => [] },
	open_pos_by_supplier: { type: Object, default: () => ({}) },
	expense_accounts: { type: Array, default: () => [] },
})

const { locale: lang, isRtl, n, today } = useLocale()
const page = usePage()
const authUser = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)
// QA-011: the company dropdown is gone — a Super Admin works inside the company they "Entered".
// (Only shown if the server ever sends a company list again.)
const showCompanyPicker = computed(() => isSuperAdmin.value && props.companies.length > 0)
const isEdit = computed(() => props.mode === 'edit')

const { can } = usePermissions()
const canCreate = computed(() => isSuperAdmin.value || can('create ap_invoices'))
const canEdit = computed(() => isSuperAdmin.value || can('edit ap_invoices'))
const canSave = computed(() => isEdit.value ? canEdit.value : canCreate.value)

// ── Form ─────────────────────────────────────────────────────────
const form = useForm({
	company_id: props.invoice?.company_id ?? '',
	supplier_id: props.invoice?.supplier_id ?? '',
	invoice_type: props.invoice?.invoice_type ?? 'cattle',
	expense_account_id: props.invoice?.expense_account_id ?? '',
	date: props.invoice?.date ?? localToday(),
	due_date: props.invoice?.due_date ?? '',
	//reference: props.invoice?.reference ?? '',
	description: props.invoice?.description ?? '',
	total: props.invoice?.total ?? '',
	currency: props.invoice?.currency ?? 'EGP',
	// 📌 InvoiceForeignCurrencyBalance: rate=1 for EGP, required for foreign
	exchange_rate: props.invoice?.exchange_rate ?? 1,
	notes: props.invoice?.notes ?? '',
})

const errors = computed(() => form.errors)

// ── Suppliers cascade for SA ─────────────────────────────────────
const dynamicSuppliers = ref([...props.suppliers])
const dynamicExpenseAccounts = ref([...props.expense_accounts])

// QA-002/003: only the newest answer may update the form (older, slower answers are ignored)
let companyReq = 0
let currencyReq = 0
async function onCompanyChange() {
	const myReq = ++companyReq
	form.supplier_id = ''
	form.expense_account_id = ''
	dynamicSuppliers.value = []
	dynamicPosBySupplier.value = {}
	dynamicExpenseAccounts.value = []
	if (!form.company_id) return
	try {
		const res = await fetch(route('ap-invoices.data-for-company') + '?company_id=' + form.company_id, {
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
		})
		const data = await res.json()
		if (myReq !== companyReq || !res.ok) return
		dynamicSuppliers.value = data.suppliers ?? []
		dynamicPosBySupplier.value = data.open_pos_by_supplier ?? {}
		dynamicExpenseAccounts.value = data.expense_accounts ?? []
	} catch (e) {
		console.error('Failed to load suppliers', e)
	}
}

// ── FX rate auto-fill ─────────────────────────────────────────────
async function fetchFxRate(currency) {
	if (!currency || currency === 'EGP') return null
	try {
		const cid = form.company_id || ''
		const res = await fetch(route('fx-rates.latest') + `?currency=${currency}&company_id=${cid}`, {
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
		})
		const data = await res.json()
		return data.rate ?? null
	} catch {
		return null
	}
}

// ── Currency logic ────────────────────────────────────────────────
const currencies = ['EGP', 'USD', 'EUR', 'GBP', 'SAR', 'AED', 'KWD', 'QAR', 'CHF', 'JPY']

async function onCurrencyChange() {
	const myReq = ++currencyReq
	const cur = form.currency
	if (cur === 'EGP') {
		form.exchange_rate = 1
	} else {
		form.exchange_rate = ''
		const rate = await fetchFxRate(cur)
		if (myReq !== currencyReq || form.currency !== cur) return
		if (rate) form.exchange_rate = rate
	}
}

// ── Selected supplier (for hero preview) ─────────────────────────
const selectedSupplier = computed(() =>
	dynamicSuppliers.value.find(s => s.id == form.supplier_id) ?? null
)

// ── Invoice type helpers ──────────────────────────────────────────
const isProcurementType = computed(() =>
	form.invoice_type === 'cattle' || form.invoice_type === 'inventory'
)
const isOtherType = computed(() => form.invoice_type === 'other')

// Reset expense_account_id when switching away from 'other'
function onInvoiceTypeChange() {
	if (form.invoice_type !== 'other') {
		form.expense_account_id = ''
	}
}
// ── PO warning banner ─────────────────────────────────────────────
// Reactive: shows when selected supplier has open POs with unmatched livestock accruals
const dynamicPosBySupplier = ref({ ...props.open_pos_by_supplier })

const openPosForSelectedSupplier = computed(() => {
	if (!form.supplier_id) return []
	return dynamicPosBySupplier.value[form.supplier_id] ?? []
})

// ── Formatting ────────────────────────────────────────────────────
function fmt(v) {
	const num = parseFloat(v) || 0
	return lang.value === 'ar'
		? num.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		: num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtAmt(v) { return fmt(v) }
function fmtForeign(v) { return fmt(v) }
function fmtRate(v) {
	const num = parseFloat(v) || 1
	return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: 4, maximumFractionDigits: 6 })
}

// ── Submit ───────────────────────────────────────────────────────
function submit() {
	// Guard: exchange_rate must be > 0 for foreign currencies
	if (form.currency !== 'EGP' && !(parseFloat(form.exchange_rate) > 0)) {
		form.setError('exchange_rate', t.value.rateRequired)
		return
	}
	if (isEdit.value) {
		form.put(route('ap-invoices.update', props.invoice.id))
	} else {
		form.post(route('ap-invoices.store'))
	}
}

// ── i18n ─────────────────────────────────────────────────────────
const i18n = {
	en: {
		eyebrow: 'Accounting',
		createTitle: 'New AP Invoice', editTitle: 'Edit AP Invoice',
		sub: 'Enter supplier invoice details',
		back: 'Back',
		sec1: 'Invoice Header',
		secType: 'Invoice Purpose',
		sec2: 'Amount & Currency',
		sec3: 'Description & Notes',
		company: 'Company', selectCompany: 'Select company…', selectCompanyFirst: 'Select a company first',
		supplier: 'Supplier', selectSupplier: 'Select supplier…',
		date: 'Invoice Date', dueDate: 'Due Date',
		//	reference: 'Reference / Invoice No.', referencePh: 'e.g. INV-2025-001',
		currency: 'Currency',
		exchangeRate: 'Exchange Rate', exchangeRatePh: 'e.g. 49.5', egp: 'EGP', egpEquiv: 'EGP Equivalent',
		total: 'Invoice Total', totalPh: '0.00',
		fxSummary: 'Foreign Currency Summary',
		fxAmount: 'Foreign Amount', fxRate: 'Exchange Rate', fxEgp: 'EGP Equivalent',
		description: 'Description', descriptionPh: 'Invoice description…',
		notes: 'Internal Notes', notesPh: 'Optional internal notes…',
		cancel: 'Cancel',
		saveCreate: 'Create Invoice', saveEdit: 'Save Changes', saving: 'Saving…',
		rateRequired: 'Exchange rate is required for foreign currency invoices.',
		typeCattle: 'Cattle Procurement',
		typeInventory: 'Inventory Procurement',
		typeOther: 'Other',
		typeCattleDesc: 'Invoice for purchased animals against a livestock PO',
		typeInventoryDesc: 'Invoice for feed, medicine, or supplies against an inventory PO',
		typeOtherDesc: 'Stationery, utilities, maintenance, services, or any other expense',
		expenseAccount: 'Expense / Asset Account',
		selectExpenseAccount: 'Select account…',
		noPOWarning: 'No unmatched purchase receipts found for this supplier. Please complete the goods receipt against a PO before creating this invoice.',
	},
	ar: {
		eyebrow: 'المحاسبة',
		createTitle: 'فاتورة مورد جديدة', editTitle: 'تعديل فاتورة المورد',
		sub: 'أدخل تفاصيل فاتورة المورد',
		back: 'رجوع',
		sec1: 'بيانات الفاتورة',
		secType: 'نوع الفاتورة',
		sec2: 'المبلغ والعملة',
		sec3: 'الوصف والملاحظات',
		company: 'الشركة', selectCompany: 'اختر الشركة…', selectCompanyFirst: 'اختر الشركة أولاً',
		supplier: 'المورد', selectSupplier: 'اختر المورد…',
		date: 'تاريخ الفاتورة', dueDate: 'تاريخ الاستحقاق',
		//	reference: 'المرجع / رقم الفاتورة', referencePh: 'مثال: INV-2025-001',
		currency: 'العملة',
		exchangeRate: 'سعر الصرف', exchangeRatePh: 'مثال: 49.5', egp: 'ج.م', egpEquiv: 'المعادل بالجنيه',
		total: 'إجمالي الفاتورة', totalPh: '0.00',
		fxSummary: 'ملخص العملة الأجنبية',
		fxAmount: 'المبلغ الأجنبي', fxRate: 'سعر الصرف', fxEgp: 'المعادل بالجنيه',
		description: 'الوصف', descriptionPh: 'وصف الفاتورة…',
		notes: 'ملاحظات داخلية', notesPh: 'ملاحظات داخلية اختيارية…',
		cancel: 'إلغاء',
		saveCreate: 'إنشاء الفاتورة', saveEdit: 'حفظ التعديلات', saving: 'جارٍ الحفظ…',
		rateRequired: 'سعر الصرف مطلوب للفواتير بالعملات الأجنبية.',
		typeCattle: 'مشتريات مواشي',
		typeInventory: 'مشتريات مخزون',
		typeOther: 'أخرى',
		typeCattleDesc: 'فاتورة مقابل أوامر شراء مواشي',
		typeInventoryDesc: 'فاتورة مقابل أوامر شراء أعلاف أو أدوية أو مستلزمات',
		typeOtherDesc: 'قرطاسية أو مرافق أو صيانة أو خدمات أو أي مصروف آخر',
		expenseAccount: 'حساب المصروف / الأصل',
		selectExpenseAccount: 'اختر الحساب…',
		noPOWarning: 'لا توجد إيصالات استلام غير مطابقة لهذا المورد. يرجى إتمام عملية الاستلام مقابل أمر شراء قبل إنشاء هذه الفاتورة.',
	},
}
const t = computed(() => i18n[lang.value] ?? i18n.en)
</script>
<style scoped>
.section-badge {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	flex-shrink: 0;
	background: linear-gradient(135deg, var(--cv-amber), var(--cv-amber-hover));
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
	font-weight: 800;
	color: #0A1808;
}

.form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 1rem;
}

.form-grid .col-span-2 {
	grid-column: 1 / -1;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
