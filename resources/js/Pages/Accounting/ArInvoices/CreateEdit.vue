<template>
	<AuthenticatedLayout>
		<div :dir="isRtl ? 'rtl' : 'ltr'">
			<div class="cv-page-hero">
				<div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
				<div class="cv-page-hero-title">{{ isEdit ? t.editTitle : t.createTitle }}</div>
				<div class="cv-page-hero-sub">
					<span v-if="selectedCustomer">{{ n(selectedCustomer, 'name') }}</span>
					<span v-else>{{ t.sub }}</span>
					<span v-if="parseFloat(form.total) > 0"
						style="color:var(--cv-green-bright);margin-inline-start:0.5rem"> — {{ fmt(form.total) }}
						{{ form.currency }}
						<span v-if="form.currency !== 'EGP' && parseFloat(form.exchange_rate) > 0"> (≈
							{{ fmt(parseFloat(form.total) * parseFloat(form.exchange_rate)) }} {{ t.egp }}) </span>
					</span>
				</div>
				<div class="cv-page-hero-actions">
					<Link :href="route('ar-invoices.index')" class="cv-btn cv-btn-ghost cv-btn-sm">{{ t.back }}</Link>
				</div>
			</div>
			<div class="cv-page">
				<form @submit.prevent="submit">
					<div class="flex flex-col gap-5" style="max-width:860px">
						<!-- ══ Section 1 — Invoice Header ═══════════════════ -->
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
								<!-- Customer -->
								<div class="cv-field">
									<label class="cv-label">{{ t.customer }} <span
											style="color:var(--cv-danger-text)">*</span></label>
									<select v-model="form.customer_id" class="cv-select"
										:class="{ 'cv-select--error': errors.customer_id }"
										:disabled="showCompanyPicker && !form.company_id">
										<option value="">{{ t.selectCustomer }}</option>
										<option v-for="c in dynamicCustomers" :key="c.id" :value="c.id">
											{{ n(c, 'name') }}
										</option>
									</select>
									<div v-if="errors.customer_id" class="cv-field-error">{{ errors.customer_id }}</div>
									<div v-if="showCompanyPicker && !form.company_id"
										style="font-size:0.7rem;color:var(--cv-text-muted);margin-top:0.25rem">
										{{ t.selectCompanyFirst }}
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
						<!-- ══ Section 2 — Amount & Currency ══════════════ -->
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
								<!-- 📌 InvoiceForeignCurrencyBalance -->
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
								<!-- FX summary box -->
								<div v-if="form.currency !== 'EGP' && parseFloat(form.exchange_rate) > 0 && parseFloat(form.total) > 0"
									style="grid-column:1/-1;background:var(--cv-green-dim);border:1px solid var(--cv-green-border);border-radius:var(--cv-radius);padding:0.75rem 1rem">
									<div
										style="font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--cv-green-bright);margin-bottom:0.35rem">
										{{ t.fxSummary }}
									</div>
									<div class="flex gap-4 flex-wrap">
										<div>
											<div style="font-size:0.63rem;color:var(--cv-text-muted)">{{ t.fxAmount }}
											</div>
											<div style="font-size:0.95rem;font-weight:700;color:var(--cv-text-primary)">
												{{ fmt(form.total) }} {{ form.currency }}
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
											<div style="font-size:0.95rem;font-weight:700;color:var(--cv-green-bright)">
												{{ fmt(parseFloat(form.total) * parseFloat(form.exchange_rate)) }}
												{{ t.egp }}
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<!-- ══ Section 3 — Description & Notes ══════════ -->
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
						<!-- Submit -->
						<div class="flex gap-3 justify-end" style="padding-bottom:2rem">
							<Link :href="route('ar-invoices.index')" class="cv-btn cv-btn-ghost">{{ t.cancel }}</Link>
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
	customers: { type: Array, default: () => [] },
	companies: { type: Array, default: () => [] },
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
const canCreate = computed(() => isSuperAdmin.value || can('create ar_invoices'))
const canEdit = computed(() => isSuperAdmin.value || can('edit ar_invoices'))
const canSave = computed(() => isEdit.value ? canEdit.value : canCreate.value)

const form = useForm({
	company_id: props.invoice?.company_id ?? '',
	customer_id: props.invoice?.customer_id ?? '',
	date: props.invoice?.date ?? localToday(),
	due_date: props.invoice?.due_date ?? '',
	//	reference: props.invoice?.reference ?? '',
	description: props.invoice?.description ?? '',
	total: props.invoice?.total ?? '',
	currency: props.invoice?.currency ?? 'EGP',
	exchange_rate: props.invoice?.exchange_rate ?? 1,
	notes: props.invoice?.notes ?? '',
})
const errors = computed(() => form.errors)

const dynamicCustomers = ref([...props.customers])

// QA-002/003: only the newest answer may update the form
let companyReq = 0
let currencyReq = 0
async function onCompanyChange() {
	const myReq = ++companyReq
	form.customer_id = ''
	dynamicCustomers.value = []
	if (!form.company_id) return
	try {
		const res = await fetch(route('ar-invoices.data-for-company') + '?company_id=' + form.company_id, {
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
		})
		const data = await res.json()
		if (myReq !== companyReq || !res.ok) return
		dynamicCustomers.value = data.customers ?? []
	} catch (e) {
		console.error('Failed to load customers', e)
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

const selectedCustomer = computed(() =>
	dynamicCustomers.value.find(c => c.id == form.customer_id) ?? null
)

function fmt(v) {
	const num = parseFloat(v) || 0
	return lang.value === 'ar'
		? num.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		: num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtRate(v) {
	const num = parseFloat(v) || 1
	return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: 4, maximumFractionDigits: 6 })
}

function submit() {
	if (form.currency !== 'EGP' && !(parseFloat(form.exchange_rate) > 0)) {
		form.setError('exchange_rate', t.value.rateRequired)
		return
	}
	if (isEdit.value) {
		form.put(route('ar-invoices.update', props.invoice.id))
	} else {
		form.post(route('ar-invoices.store'))
	}
}

const i18n = {
	en: {
		eyebrow: 'Accounting',
		createTitle: 'New AR Invoice', editTitle: 'Edit AR Invoice',
		sub: 'Enter customer invoice details',
		back: 'Back',
		sec1: 'Invoice Header', sec2: 'Amount & Currency', sec3: 'Description & Notes',
		company: 'Company', selectCompany: 'Select company…', selectCompanyFirst: 'Select a company first',
		customer: 'Customer', selectCustomer: 'Select customer…',
		date: 'Invoice Date', dueDate: 'Due Date',
		//	reference: 'Reference / Invoice No.', referencePh: 'e.g. SI-2025-001',
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
	},
	ar: {
		eyebrow: 'المحاسبة',
		createTitle: 'فاتورة عميل جديدة', editTitle: 'تعديل فاتورة العميل',
		sub: 'أدخل تفاصيل فاتورة العميل',
		back: 'رجوع',
		sec1: 'بيانات الفاتورة', sec2: 'المبلغ والعملة', sec3: 'الوصف والملاحظات',
		company: 'الشركة', selectCompany: 'اختر الشركة…', selectCompanyFirst: 'اختر الشركة أولاً',
		customer: 'العميل', selectCustomer: 'اختر العميل…',
		date: 'تاريخ الفاتورة', dueDate: 'تاريخ الاستحقاق',
		//	reference: 'المرجع / رقم الفاتورة', referencePh: 'مثال: SI-2025-001',
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
	grid-column: 1/-1;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
