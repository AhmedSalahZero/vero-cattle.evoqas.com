<script setup>
import { ref, computed, watch } from 'vue'
import { usePage, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermissions } from '@/Composables/usePermissions'

defineOptions({ layout: AuthenticatedLayout })

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
	lines: Object,   // paginated
	summary: Object,
	accounts: Array,
	filters: Object,
	companies: Array,    // SA only
	selectedCompanyId: Number,
	lastUploadErrors: {
		type: Object,
		default: () => ({ errors: [] }),
	},
})

// ── Locale & Permissions ──────────────────────────────────────────
const { locale: lang, isRtl, n } = useLocale()

const page = usePage()
const authUser = computed(() => page.props.auth?.user)
const isSuperAdmin = computed(() => !!authUser.value?.is_super_admin)

const { can } = usePermissions()
const canCreate = computed(() => can('create bank_statements'))
const canEdit = computed(() => can('edit bank_statements'))
const canDelete = computed(() => can('delete bank_statements'))

// ── i18n ──────────────────────────────────────────────────────────
const ui = computed(() => lang.value === 'ar' ? {
	// Hero
	eyebrow: 'المحاسبة',
	title: 'كشوف الحساب البنكي',
	subtitle: 'تسجيل ومطابقة حركات الحساب البنكي مع القيود المحاسبية',
	addBtn: 'إضافة حركة',
	downloadTemplate: 'تنزيل قالب Excel',
	uploadTemplate: 'رفع Excel',
	lastUploadErrorsBtn: 'آخر أخطاء الرفع',
	uploadAccount: 'الحساب المستهدف للرفع',
	selectUploadAccount: 'اختر الحساب قبل الرفع',
	uploadErrorsTitle: 'أخطاء آخر رفع',
	errorRow: 'رقم السطر',
	errorColumn: 'العمود',
	errorMessage: 'الخطأ',
	noUploadErrors: 'لا توجد أخطاء رفع.',
	// KPIs
	totalLines: 'إجمالي الحركات',
	reconciled: 'مُسوَّاة',
	unmatched: 'غير مُطابَقة',
	totalIn: 'إجمالي الوارد',
	totalOut: 'إجمالي الصادر',
	reconciledPct: 'نسبة التسوية',
	// Filters
	filterTitle: 'تصفية الحركات',
	allAccounts: 'جميع الحسابات',
	allStatus: 'جميع الحالات',
	statusUnmatched: 'غير مُطابَقة',
	statusMatched: 'مُطابَقة',
	statusReconciled: 'مُسوَّاة',
	dateFrom: 'من تاريخ',
	dateTo: 'إلى تاريخ',
	search: 'بحث في الوصف...',
	company: 'الشركة',
	allCompanies: 'جميع الشركات',
	// Table headers
	date: 'التاريخ',
	description: 'الوصف',
	reference: 'المرجع',
	account: 'الحساب',
	amount: 'المبلغ',
	status: 'الحالة',
	actions: 'إجراءات',
	// Badges
	badgeUnmatched: 'غير مُطابَقة',
	badgeMatched: 'مُطابَقة',
	badgeReconciled: 'مُسوَّاة',
	// Actions
	editBtn: 'تعديل',
	matchBtn: 'مطابقة',
	unmatchBtn: 'إلغاء المطابقة',
	reconcileBtn: 'تسوية',
	deleteBtn: 'حذف',
	// Add/Edit modal
	addTitle: 'إضافة حركة بنكية',
	editTitle: 'تعديل الحركة',
	fieldAccount: 'الحساب البنكي',
	fieldDate: 'التاريخ',
	fieldDesc: 'الوصف',
	fieldAmount: 'المبلغ (موجب = وارد، سالب = صادر)',
	fieldRef: 'المرجع / رقم المستند',
	fieldRefPh: 'مثال: TRF-001، CHK-123',
	saveBtn: 'حفظ',
	cancelBtn: 'إلغاء',
	selectAccount: 'اختر الحساب',
	// Match modal
	matchTitle: 'مطابقة مع قيد محاسبي',
	matchSub: 'اختر رقم القيد المحاسبي المقابل لهذه الحركة',
	fieldJeId: 'رقم القيد المحاسبي',
	fieldJeIdPh: 'أدخل رقم القيد...',
	confirmMatch: 'تأكيد المطابقة',
	// Reconcile modal
	reconcileTitle: 'تأكيد التسوية',
	reconcileSub: 'هل أنت متأكد من تسوية هذه الحركة؟ لا يمكن التراجع عن هذه العملية.',
	reconcileWarn: 'بعد التسوية لا يمكن تعديل أو حذف هذه الحركة.',
	confirmReconcile: 'تأكيد التسوية',
	// Delete modal
	deleteTitle: 'حذف الحركة',
	deleteSub: 'هل أنت متأكد من حذف هذه الحركة؟ لا يمكن التراجع.',
	confirmDelete: 'حذف',
	// Unmatch confirm
	unmatchTitle: 'إلغاء المطابقة',
	unmatchSub: 'هل أنت متأكد من إلغاء مطابقة هذه الحركة مع القيد المحاسبي؟',
	confirmUnmatch: 'إلغاء المطابقة',
	// Empty
	emptyTitle: 'لا توجد حركات بنكية',
	emptySub: 'أضف حركات الحساب البنكي يدوياً أو استورد كشف الحساب',
	// Pagination
	prev: 'السابق',
	next: 'التالي',
	showing: 'عرض',
	of: 'من',
	entries: 'حركة',
	// Flow hint
	flowHint: 'تدفق العمل: إضافة حركة ← مطابقة مع قيد ← تسوية',
	// Amount
	credit: 'وارد',
	debit: 'صادر',
} : {
	eyebrow: 'Accounting',
	title: 'Bank Reconcilation',
	subtitle: 'Record and reconcile bank transactions against posted journal entries',
	addBtn: 'Add Transaction',
	downloadTemplate: 'Download Excel Template',
	uploadTemplate: 'Upload Excel',
	lastUploadErrorsBtn: 'Last Upload Errors',
	uploadAccount: 'Upload Target Account',
	selectUploadAccount: 'Select account before upload',
	uploadErrorsTitle: 'Last Upload Errors',
	errorRow: 'Row',
	errorColumn: 'Column',
	errorMessage: 'Error',
	noUploadErrors: 'No upload errors.',
	totalLines: 'Total Lines',
	reconciled: 'Reconciled',
	unmatched: 'Unmatched',
	totalIn: 'Total In',
	totalOut: 'Total Out',
	reconciledPct: 'Reconciled %',
	filterTitle: 'Filter Transactions',
	allAccounts: 'All Accounts',
	allStatus: 'All Statuses',
	statusUnmatched: 'Unmatched',
	statusMatched: 'Matched',
	statusReconciled: 'Reconciled',
	dateFrom: 'Date From',
	dateTo: 'Date To',
	search: 'Search description...',
	company: 'Company',
	allCompanies: 'All Companies',
	date: 'Date',
	description: 'Description',
	reference: 'Reference',
	account: 'Account',
	amount: 'Amount',
	status: 'Status',
	actions: 'Actions',
	badgeUnmatched: 'Unmatched',
	badgeMatched: 'Matched',
	badgeReconciled: 'Reconciled',
	editBtn: 'Edit',
	matchBtn: 'Match',
	unmatchBtn: 'Unmatch',
	reconcileBtn: 'Reconcile',
	deleteBtn: 'Delete',
	addTitle: 'Add Bank Transaction',
	editTitle: 'Edit Transaction',
	fieldAccount: 'Bank Account',
	fieldDate: 'Date',
	fieldDesc: 'Description',
	fieldAmount: 'Amount (positive = credit/in, negative = debit/out)',
	fieldRef: 'Reference / Document No.',
	fieldRefPh: 'e.g. TRF-001, CHK-123',
	saveBtn: 'Save',
	cancelBtn: 'Cancel',
	selectAccount: 'Select account',
	matchTitle: 'Match to Journal Entry',
	matchSub: 'Enter the journal entry ID that corresponds to this bank transaction',
	fieldJeId: 'Journal Entry ID',
	fieldJeIdPh: 'Enter journal entry ID...',
	confirmMatch: 'Confirm Match',
	reconcileTitle: 'Confirm Reconciliation',
	reconcileSub: 'Are you sure you want to reconcile this transaction? This cannot be undone.',
	reconcileWarn: 'Once reconciled, this line cannot be edited or deleted.',
	confirmReconcile: 'Confirm Reconciliation',
	deleteTitle: 'Delete Transaction',
	deleteSub: 'Are you sure you want to delete this transaction? This cannot be undone.',
	confirmDelete: 'Delete',
	unmatchTitle: 'Remove Match',
	unmatchSub: 'Are you sure you want to remove the journal entry match from this transaction?',
	confirmUnmatch: 'Remove Match',
	emptyTitle: 'No bank transactions',
	emptySub: 'Add bank statement lines manually or import from your bank',
	prev: 'Previous',
	next: 'Next',
	showing: 'Showing',
	of: 'of',
	entries: 'entries',
	flowHint: 'Workflow: Add Line → Match to Journal Entry → Reconcile',
	credit: 'Credit',
	debit: 'Debit',
})

// ── Filters ───────────────────────────────────────────────────────
const filters = ref({
	account_id: props.filters?.account_id ?? '',
	status: props.filters?.status ?? '',
	date_from: props.filters?.date_from ?? '',
	date_to: props.filters?.date_to ?? '',
	search: props.filters?.search ?? '',
	company_id: props.selectedCompanyId ?? '',
})

function applyFilters() {
	const params = {}
	if (filters.value.account_id) params.account_id = filters.value.account_id
	if (filters.value.date_from) params.date_from = filters.value.date_from
	if (filters.value.date_to) params.date_to = filters.value.date_to
	if (filters.value.search) params.search = filters.value.search
	if (filters.value.company_id) params.company_id = filters.value.company_id
	// Map status to is_matched / is_reconciled
	if (filters.value.status === 'unmatched') params.is_matched = 0
	if (filters.value.status === 'matched') { params.is_matched = 1; params.is_reconciled = 0 }
	if (filters.value.status === 'reconciled') params.is_reconciled = 1
	router.get(route('bank-statements.index'), params, { preserveScroll: true, replace: true })
}

let filterTimer = null
watch(filters, () => {
	clearTimeout(filterTimer)
	filterTimer = setTimeout(applyFilters, 450)
}, { deep: true })

// ── Add / Edit Modal ──────────────────────────────────────────────
const showLineModal = ref(false)
const editingLine = ref(null)

const lineForm = useForm({
	account_id: '',
	date: '',
	description: '',
	amount: '',
	reference: '',
})

function openAdd() {
	editingLine.value = null
	lineForm.reset()
	lineForm.date = new Date().toISOString().slice(0, 10)
	showLineModal.value = true
}

function openEdit(line) {
	if (line.is_reconciled) return
	editingLine.value = line
	lineForm.account_id = line.account?.id ?? ''
	lineForm.date = line.date
	lineForm.description = line.description
	lineForm.amount = line.amount
	lineForm.reference = line.reference ?? ''
	showLineModal.value = true
}

function submitLine() {
	if (editingLine.value) {
		lineForm.put(route('bank-statements.update', editingLine.value.id), {
			preserveScroll: true,
			onSuccess: () => { showLineModal.value = false; lineForm.reset() },
		})
	} else {
		lineForm.post(route('bank-statements.store'), {
			preserveScroll: true,
			onSuccess: () => { showLineModal.value = false; lineForm.reset() },
		})
	}
}

// ── Match Modal ───────────────────────────────────────────────────
const showMatchModal = ref(false)
const matchingLine = ref(null)

const matchForm = useForm({ journal_entry_id: '' })

function openMatch(line) {
	matchingLine.value = line
	matchForm.journal_entry_id = ''
	showMatchModal.value = true
}

function submitMatch() {
	matchForm.patch(route('bank-statements.match', matchingLine.value.id), {
		preserveScroll: true,
		onSuccess: () => { showMatchModal.value = false; matchForm.reset() },
	})
}

// ── Unmatch ───────────────────────────────────────────────────────
const showUnmatchModal = ref(false)
const unmatchingLine = ref(null)
const unmatchForm = useForm({})

function openUnmatch(line) {
	unmatchingLine.value = line
	showUnmatchModal.value = true
}

function submitUnmatch() {
	unmatchForm.patch(route('bank-statements.unmatch', unmatchingLine.value.id), {
		preserveScroll: true,
		onSuccess: () => { showUnmatchModal.value = false },
	})
}

// ── Reconcile Modal ───────────────────────────────────────────────
const showReconcileModal = ref(false)
const reconcilingLine = ref(null)
const reconcileForm = useForm({})

function openReconcile(line) {
	reconcilingLine.value = line
	showReconcileModal.value = true
}

function submitReconcile() {
	reconcileForm.patch(route('bank-statements.reconcile', reconcilingLine.value.id), {
		preserveScroll: true,
		onSuccess: () => { showReconcileModal.value = false },
	})
}

// ── Delete Modal ──────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deletingLine = ref(null)
const deleteForm = useForm({})

function openDelete(line) {
	deletingLine.value = line
	showDeleteModal.value = true
}

function submitDelete() {
	deleteForm.delete(route('bank-statements.destroy', deletingLine.value.id), {
		preserveScroll: true,
		onSuccess: () => { showDeleteModal.value = false },
	})
}

// ── Helpers ───────────────────────────────────────────────────────
function fmtAmount(val) {
	const num = parseFloat(val)
	return new Intl.NumberFormat(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
		minimumFractionDigits: 2, maximumFractionDigits: 2,
	}).format(Math.abs(num))
}

function fmtDate(d) {
	if (!d) return '—'
	return new Date(d).toLocaleDateString(lang.value === 'ar' ? 'ar-EG' : 'en-GB', {
		day: '2-digit', month: 'short', year: 'numeric',
	})
}

function statusBadgeClass(line) {
	if (line.is_reconciled) return 'cv-badge cv-badge-success'
	if (line.is_matched) return 'cv-badge cv-badge-info'
	return 'cv-badge cv-badge-warning'
}

function statusLabel(line) {
	if (line.is_reconciled) return ui.value.badgeReconciled
	if (line.is_matched) return ui.value.badgeMatched
	return ui.value.badgeUnmatched
}

const summaryData = computed(() => props.summary ?? {
	total_lines: 0, reconciled_lines: 0, unmatched_lines: 0,
	reconciled_pct: 0, total_in: 0, total_out: 0,
})

// ── Excel import/export ───────────────────────────────────────────
const uploadInputRef = ref(null)
const showUploadErrorsModal = ref(false)
const importForm = useForm({
	account_id: props.accounts?.[0]?.id ?? '',
	file: null,
})

const hasLastUploadErrors = computed(
	() => (props.lastUploadErrors?.errors?.length ?? 0) > 0
)

function downloadTemplate() {
	window.location.href = route('bank-statements.download-template')
}

function pickUploadFile() {
	if (!importForm.account_id) {
		importForm.setError('account_id', ui.value.selectUploadAccount)
		return
	}
	importForm.clearErrors('account_id')
	uploadInputRef.value?.click()
}

function onFilePicked(event) {
	const file = event?.target?.files?.[0]
	if (!file) return
	importForm.file = file
	importForm.post(route('bank-statements.upload-template'), {
		forceFormData: true,
		preserveScroll: true,
		onSuccess: () => {
			importForm.reset('file')
		},
		onFinish: () => {
			if (uploadInputRef.value) uploadInputRef.value.value = null
		},
	})
}
</script>
<template>
	<div :dir="isRtl ? 'rtl' : 'ltr'">
		<!-- ── Hero ──────────────────────────────────────────── -->
		<div class="cv-page-hero">
			<div class="cv-page-hero-eyebrow">{{ ui.eyebrow }}</div>
			<div class="cv-page-hero-title">{{ ui.title }}</div>
			<div class="cv-page-hero-sub">{{ ui.subtitle }}</div>
			<div class="cv-page-hero-actions">
				<button v-if="canCreate" class="cv-btn cv-btn-ghost" @click="downloadTemplate">
					{{ ui.downloadTemplate }}
				</button>
				<button v-if="canCreate" class="cv-btn cv-btn-ghost" @click="pickUploadFile">
					{{ ui.uploadTemplate }}
				</button>
				<button
					v-if="hasLastUploadErrors"
					class="cv-btn"
					style="background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border);color:var(--cv-danger-text);"
					@click="showUploadErrorsModal = true"
				>
					{{ ui.lastUploadErrorsBtn }}
				</button>
				<button v-if="canCreate" class="cv-btn cv-btn-amber" @click="openAdd">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
						style="flex-shrink:0">
						<line x1="12" y1="5" x2="12" y2="19" />
						<line x1="5" y1="12" x2="19" y2="12" />
					</svg>
					{{ ui.addBtn }}
				</button>
				<input
					ref="uploadInputRef"
					type="file"
					accept=".xlsx,.xls,.csv"
					style="display:none"
					@change="onFilePicked"
				/>
			</div>
		</div>
		<div class="cv-page">
			<!-- ── Workflow hint ──────────────────────────────── -->
			<div style="
                    display:flex; align-items:center; gap:0.5rem;
                    background:var(--cv-blue-light); border:1px solid var(--cv-blue-border);
                    border-radius:var(--cv-radius); padding:0.625rem 1rem;
                    margin-bottom:1.25rem; font-size:0.8rem; color:var(--cv-blue-text);
                ">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
					<circle cx="12" cy="12" r="10" />
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
				{{ ui.flowHint }}
			</div>
			<div
				v-if="canCreate"
				class="cv-card"
				style="padding:.875rem 1rem;margin-bottom:1.25rem;display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"
			>
				<div style="min-width:260px;flex:1;">
					<label class="cv-label">{{ ui.uploadAccount }}</label>
					<select class="cv-select" v-model="importForm.account_id">
						<option value="">{{ ui.selectUploadAccount }}</option>
						<option v-for="a in accounts" :key="a.id" :value="a.id">
							{{ a.code }} — {{ n(a) }}
						</option>
					</select>
					<p v-if="importForm.errors.account_id" style="color:var(--cv-danger-text);font-size:.75rem;margin-top:.2rem;">
						{{ importForm.errors.account_id }}
					</p>
					<p v-if="importForm.errors.file" style="color:var(--cv-danger-text);font-size:.75rem;margin-top:.2rem;">
						{{ importForm.errors.file }}
					</p>
				</div>
			</div>
			<!-- ── KPI Strip ──────────────────────────────────── -->
			<div
				style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:0.875rem; margin-bottom:1.5rem;">
				<div class="cv-card" style="padding:1rem; text-align:center;">
					<div
						style="font-size:0.6875rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.375rem;">
						{{ ui.totalLines }}
					</div>
					<div style="font-size:1.5rem; font-weight:700; color:var(--cv-blue-text);">
						{{ summaryData.total_lines }}
					</div>
				</div>
				<div class="cv-card" style="padding:1rem; text-align:center;">
					<div
						style="font-size:0.6875rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.375rem;">
						{{ ui.reconciled }}
					</div>
					<div style="font-size:1.5rem; font-weight:700; color:var(--cv-green-bright);">
						{{ summaryData.reconciled_lines }}
					</div>
				</div>
				<div class="cv-card" style="padding:1rem; text-align:center;">
					<div
						style="font-size:0.6875rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.375rem;">
						{{ ui.unmatched }}
					</div>
					<div style="font-size:1.5rem; font-weight:700; color:var(--cv-amber);">
						{{ summaryData.unmatched_lines }}
					</div>
				</div>
				<div class="cv-card" style="padding:1rem; text-align:center;">
					<div
						style="font-size:0.6875rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.375rem;">
						{{ ui.totalIn }}
					</div>
					<div style="font-size:1.25rem; font-weight:700; color:var(--cv-green-bright);">
						{{ fmtAmount(summaryData.total_in) }}
					</div>
				</div>
				<div class="cv-card" style="padding:1rem; text-align:center;">
					<div
						style="font-size:0.6875rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.375rem;">
						{{ ui.totalOut }}
					</div>
					<div style="font-size:1.25rem; font-weight:700; color:var(--cv-danger-text);">
						{{ fmtAmount(summaryData.total_out) }}
					</div>
				</div>
				<div class="cv-card" style="padding:1rem; text-align:center;">
					<div
						style="font-size:0.6875rem; color:var(--cv-text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.375rem;">
						{{ ui.reconciledPct }}
					</div>
					<div style="font-size:1.5rem; font-weight:700; color:var(--cv-blue-text);">
						{{ summaryData.reconciled_pct }}%
					</div>
					<!-- Progress bar -->
					<div
						style="margin-top:.5rem; height:4px; background:var(--cv-border); border-radius:2px; overflow:hidden;">
						<div
							:style="`width:${summaryData.reconciled_pct}%; height:100%; background:var(--cv-green-bright); border-radius:2px; transition:width .4s ease;`">
						</div>
					</div>
				</div>
			</div>
			<!-- ── Filters ────────────────────────────────────── -->
			<div class="cv-card" style="padding:1rem 1.25rem; margin-bottom:1.25rem;">
				<div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.875rem;">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)"
						stroke-width="1.7">
						<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
					</svg>
					<span style="font-size:0.75rem; font-weight:600; color:var(--cv-text-secondary);">
						{{ ui.filterTitle }}
					</span>
				</div>
				<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:0.75rem;">
					<!-- Company (SA only) -->
					<div v-if="isSuperAdmin">
						<label class="cv-label">{{ ui.company }}</label>
						<select class="cv-select" v-model="filters.company_id">
							<option value="">{{ ui.allCompanies }}</option>
							<option v-for="c in companies" :key="c.id" :value="c.id">
								{{ n(c) }}
							</option>
						</select>
					</div>
					<!-- Account -->
					<div>
						<label class="cv-label">{{ ui.fieldAccount }}</label>
						<select class="cv-select" v-model="filters.account_id">
							<option value="">{{ ui.allAccounts }}</option>
							<option v-for="a in accounts" :key="a.id" :value="a.id">
								{{ a.code }} — {{ n(a) }}
							</option>
						</select>
					</div>
					<!-- Status -->
					<div>
						<label class="cv-label">{{ ui.status }}</label>
						<select class="cv-select" v-model="filters.status">
							<option value="">{{ ui.allStatus }}</option>
							<option value="unmatched">{{ ui.statusUnmatched }}</option>
							<option value="matched">{{ ui.statusMatched }}</option>
							<option value="reconciled">{{ ui.statusReconciled }}</option>
						</select>
					</div>
					<!-- Date from -->
					<div>
						<label class="cv-label">{{ ui.dateFrom }}</label>
						<input type="date" class="cv-input" v-model="filters.date_from" />
					</div>
					<!-- Date to -->
					<div>
						<label class="cv-label">{{ ui.dateTo }}</label>
						<input type="date" class="cv-input" v-model="filters.date_to" />
					</div>
					<!-- Search -->
					<div style="grid-column: span 2;">
						<label class="cv-label">{{ ui.description }}</label>
						<div class="cv-search-wrap">
							<svg class="cv-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="1.7">
								<circle cx="11" cy="11" r="8" />
								<line x1="21" y1="21" x2="16.65" y2="16.65" />
							</svg>
							<input type="text" class="cv-search-input" v-model="filters.search"
								:placeholder="ui.search" />
						</div>
					</div>
				</div>
			</div>
			<!-- ── Lines Table ────────────────────────────────── -->
			<div class="cv-card" style="overflow:hidden;">
				<!-- Empty state -->
				<div v-if="!lines?.data?.length" class="cv-empty">
					<div class="cv-empty-icon">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="1.2">
							<path d="M3 22V12" />
							<path d="M21 22V12" />
							<path d="M7 22V12" />
							<path d="M11 22V12" />
							<path d="M15 22V12" />
							<path d="M19 22V12" />
							<path d="M1 12l11-9 11 9" />
							<path d="M1 22h22" />
						</svg>
					</div>
					<div class="cv-empty-title">{{ ui.emptyTitle }}</div>
					<div class="cv-empty-sub">{{ ui.emptySub }}</div>
					<button v-if="canCreate" class="cv-btn cv-btn-amber" style="margin-top:1rem;" @click="openAdd">
						{{ ui.addBtn }}
					</button>
				</div>
				<!-- Table -->
				<div v-else style="overflow-x:auto;">
					<table style="width:100%; border-collapse:collapse; font-size:0.8125rem;">
						<thead>
							<tr style="border-bottom:1px solid var(--cv-border-md);">
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:start;">
									{{ ui.date }}
								</th>
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:start;">
									{{ ui.account }}
								</th>
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:start;">
									{{ ui.description }}
								</th>
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:start;">
									{{ ui.reference }}
								</th>
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:end;">
									{{ ui.amount }}
								</th>
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:start;">
									{{ ui.status }}
								</th>
								<th
									style="padding:.75rem 1rem; font-weight:600; font-size:.6875rem; text-transform:uppercase; letter-spacing:.08em; color:var(--cv-text-muted); text-align:start;">
									{{ ui.actions }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="line in lines.data" :key="line.id"
								style="border-bottom:1px solid var(--cv-border); transition:background .15s ease;"
								:style="line.is_reconciled ? 'opacity:.8;' : ''"
								onmouseover="this.style.background='var(--cv-bg-hover)'"
								onmouseout="this.style.background=''">
								<!-- Date -->
								<td style="padding:.75rem 1rem; color:var(--cv-text-secondary); white-space:nowrap;">
									{{ fmtDate(line.date) }}
								</td>
								<!-- Account -->
								<td style="padding:.75rem 1rem;">
									<div v-if="line.account" style="display:flex; flex-direction:column; gap:.125rem;">
										<span
											style="font-size:.6875rem; color:var(--cv-amber-text); font-weight:600; font-variant-numeric:tabular-nums;">
											{{ line.account.code }}
										</span>
										<span style="color:var(--cv-text-secondary); font-size:.8rem;">
											{{ n(line.account) }}
										</span>
									</div>
									<span v-else style="color:var(--cv-text-muted);">—</span>
								</td>
								<!-- Description -->
								<td style="padding:.75rem 1rem; color:var(--cv-text-primary); max-width:220px;">
									<span
										style="display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
										{{ line.description }}
									</span>
									<!-- Matched entry ID pill -->
									<span v-if="line.is_matched && line.matched_entry_id" style="display:inline-flex; align-items:center; gap:.25rem; margin-top:.25rem;
                                                     font-size:.625rem; background:var(--cv-blue-light);
                                                     border:1px solid var(--cv-blue-border); border-radius:4px;
                                                     padding:.125rem .375rem; color:var(--cv-blue-text);">
										<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor"
											stroke-width="2">
											<polyline points="20 6 9 17 4 12" />
										</svg> JE #{{ line.matched_entry_id }} </span>
								</td>
								<!-- Reference -->
								<td
									style="padding:.75rem 1rem; color:var(--cv-text-muted); font-size:.8rem; white-space:nowrap;">
									{{ line.reference || '—' }}
								</td>
								<!-- Amount -->
								<td style="padding:.75rem 1rem; text-align:end; white-space:nowrap;">
									<div
										style="display:flex; flex-direction:column; align-items:flex-end; gap:.125rem;">
										<span
											:style="`font-size:.9375rem; font-weight:700; font-variant-numeric:tabular-nums; color:${parseFloat(line.amount) >= 0 ? 'var(--cv-green-bright)' : 'var(--cv-danger-text)'};`">
											{{ parseFloat(line.amount) >= 0 ? '+' : '-' }} {{ fmtAmount(line.amount) }}
										</span>
										<span style="font-size:.625rem; color:var(--cv-text-muted);">
											{{ parseFloat(line.amount) >= 0 ? ui.credit : ui.debit }}
										</span>
									</div>
								</td>
								<!-- Status badge -->
								<td style="padding:.75rem 1rem;">
									<span :class="statusBadgeClass(line)">{{ statusLabel(line) }}</span>
								</td>
								<!-- Actions -->
								<td style="padding:.75rem 1rem;">
									<div style="display:flex; align-items:center; gap:.375rem;">
										<!-- Edit (unreconciled only) -->
										<button v-if="canEdit && !line.is_reconciled" class="cv-btn cv-btn-ghost"
											style="padding:.3rem .5rem; font-size:.75rem;" @click="openEdit(line)"
											:title="ui.editBtn">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
												stroke="currentColor" stroke-width="1.7">
												<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
												<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
											</svg>
										</button>
										<!-- Match (unmatched + unreconciled) -->
										<button v-if="canEdit && !line.is_matched && !line.is_reconciled"
											class="cv-btn cv-btn-ghost"
											style="padding:.3rem .5rem; font-size:.75rem; color:var(--cv-blue-text);"
											@click="openMatch(line)" :title="ui.matchBtn">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
												stroke="currentColor" stroke-width="1.7">
												<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
												<path
													d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
											</svg>
										</button>
										<!-- Unmatch (matched but not reconciled) -->
										<button v-if="canEdit && line.is_matched && !line.is_reconciled"
											class="cv-btn cv-btn-ghost"
											style="padding:.3rem .5rem; font-size:.75rem; color:var(--cv-amber-text);"
											@click="openUnmatch(line)" :title="ui.unmatchBtn">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
												stroke="currentColor" stroke-width="1.7">
												<path
													d="M18.84 12.25l1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71" />
												<path
													d="M5.17 11.75l-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71" />
												<line x1="8" y1="2" x2="8" y2="5" />
												<line x1="2" y1="8" x2="5" y2="8" />
												<line x1="16" y1="19" x2="16" y2="22" />
												<line x1="19" y1="16" x2="22" y2="16" />
											</svg>
										</button>
										<!-- Reconcile (matched but not yet reconciled) -->
										<button v-if="canEdit && line.is_matched && !line.is_reconciled"
											class="cv-btn cv-btn-ghost"
											style="padding:.3rem .5rem; font-size:.75rem; color:var(--cv-green-bright);"
											@click="openReconcile(line)" :title="ui.reconcileBtn">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
												stroke="currentColor" stroke-width="1.7">
												<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
												<polyline points="22 4 12 14.01 9 11.01" />
											</svg>
										</button>
										<!-- Delete (unreconciled only) -->
										<button v-if="canDelete && !line.is_reconciled" class="cv-btn cv-btn-ghost"
											style="padding:.3rem .5rem; font-size:.75rem; color:var(--cv-danger-text);"
											@click="openDelete(line)" :title="ui.deleteBtn">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
												stroke="currentColor" stroke-width="1.7">
												<polyline points="3 6 5 6 21 6" />
												<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
												<path d="M10 11v6" />
												<path d="M14 11v6" />
												<path d="M9 6V4h6v2" />
											</svg>
										</button>
										<!-- Reconciled lock icon -->
										<span v-if="line.is_reconciled" style="color:var(--cv-text-muted);"
											:title="ui.badgeReconciled">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
												stroke="currentColor" stroke-width="1.7">
												<rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
												<path d="M7 11V7a5 5 0 0 1 10 0v4" />
											</svg>
										</span>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<!-- Pagination -->
				<div v-if="lines?.data?.length && lines.last_page > 1"
					style="padding:.875rem 1.25rem; border-top:1px solid var(--cv-border);
                                display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
					<span style="font-size:.75rem; color:var(--cv-text-muted);">
						{{ ui.showing }} {{ lines.from }} – {{ lines.to }} {{ ui.of }} {{ lines.total }}
						{{ ui.entries }}
					</span>
					<div class="cv-pagination">
						<button class="cv-page-btn" :disabled="!lines.prev_page_url"
							@click="router.get(lines.prev_page_url, {}, { preserveScroll: true })">
							{{ ui.prev }}
						</button>
						<button class="cv-page-btn" :disabled="!lines.next_page_url"
							@click="router.get(lines.next_page_url, {}, { preserveScroll: true })">
							{{ ui.next }}
						</button>
					</div>
				</div>
			</div>
		</div><!-- /cv-page -->
	</div><!-- /dir -->
	<!-- ════════════════════════════════════════════════════════
             MODAL: Add / Edit Line
        ════════════════════════════════════════════════════════ -->
	<Teleport to="body">
		<Transition name="cv-modal-fade">
			<div v-if="showLineModal" class="cv-modal-backdrop" @click.self="showLineModal = false">
				<div class="cv-modal" style="max-width:520px; width:100%;" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<h3 class="cv-modal-title">
							{{ editingLine ? ui.editTitle : ui.addTitle }}
						</h3>
						<button class="cv-modal-close" @click="showLineModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body" style="display:flex; flex-direction:column; gap:1rem;">
						<!-- Account (only on add) -->
						<div v-if="!editingLine">
							<label class="cv-label">{{ ui.fieldAccount }} *</label>
							<select class="cv-select" v-model="lineForm.account_id"
								:class="{ 'border-red-500': lineForm.errors.account_id }">
								<option value="">{{ ui.selectAccount }}</option>
								<option v-for="a in accounts" :key="a.id" :value="a.id">
									{{ a.code }} — {{ n(a) }}
								</option>
							</select>
							<p v-if="lineForm.errors.account_id"
								style="color:var(--cv-danger-text); font-size:.75rem; margin-top:.25rem;">
								{{ lineForm.errors.account_id }}
							</p>
						</div>
						<!-- Date -->
						<div>
							<label class="cv-label">{{ ui.fieldDate }} *</label>
							<input type="date" class="cv-input" v-model="lineForm.date"
								:class="{ 'border-red-500': lineForm.errors.date }" />
							<p v-if="lineForm.errors.date"
								style="color:var(--cv-danger-text); font-size:.75rem; margin-top:.25rem;">
								{{ lineForm.errors.date }}
							</p>
						</div>
						<!-- Description -->
						<div>
							<label class="cv-label">{{ ui.fieldDesc }} *</label>
							<input type="text" class="cv-input" v-model="lineForm.description"
								:class="{ 'border-red-500': lineForm.errors.description }" />
							<p v-if="lineForm.errors.description"
								style="color:var(--cv-danger-text); font-size:.75rem; margin-top:.25rem;">
								{{ lineForm.errors.description }}
							</p>
						</div>
						<!-- Amount -->
						<div>
							<label class="cv-label">{{ ui.fieldAmount }} *</label>
							<input type="number" step="0.01" class="cv-input" v-model="lineForm.amount"
								style="font-variant-numeric:tabular-nums;"
								:class="{ 'border-red-500': lineForm.errors.amount }" />
							<!-- Live preview -->
							<div v-if="lineForm.amount !== ''" style="margin-top:.375rem; font-size:.75rem;"
								:style="`color:${parseFloat(lineForm.amount) >= 0 ? 'var(--cv-green-bright)' : 'var(--cv-danger-text)'}`">
								{{ parseFloat(lineForm.amount) >= 0 ? '▲ ' + ui.credit : '▼ ' + ui.debit }}
								{{ fmtAmount(lineForm.amount) }} EGP
							</div>
							<p v-if="lineForm.errors.amount"
								style="color:var(--cv-danger-text); font-size:.75rem; margin-top:.25rem;">
								{{ lineForm.errors.amount }}
							</p>
						</div>
						<!-- Reference -->
						<div>
							<label class="cv-label">{{ ui.fieldRef }}</label>
							<input type="text" class="cv-input" v-model="lineForm.reference"
								:placeholder="ui.fieldRefPh" />
						</div>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="showLineModal = false">
							{{ ui.cancelBtn }}
						</button>
						<button class="cv-btn cv-btn-amber" :disabled="lineForm.processing" @click="submitLine">
							{{ ui.saveBtn }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
	<!-- ════════════════════════════════════════════════════════
             MODAL: Match to Journal Entry
        ════════════════════════════════════════════════════════ -->
	<Teleport to="body">
		<Transition name="cv-modal-fade">
			<div v-if="showMatchModal" class="cv-modal-backdrop" @click.self="showMatchModal = false">
				<div class="cv-modal" style="max-width:460px; width:100%;" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<h3 class="cv-modal-title">{{ ui.matchTitle }}</h3>
						<button class="cv-modal-close" @click="showMatchModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body">
						<!-- Line summary card -->
						<div v-if="matchingLine" style="
                                background:var(--cv-bg-card-alt); border:1px solid var(--cv-border-md);
                                border-radius:var(--cv-radius); padding:.875rem 1rem; margin-bottom:1.25rem;
                                display:grid; grid-template-columns:1fr 1fr; gap:.5rem; font-size:.8rem;
                            ">
							<div>
								<div style="color:var(--cv-text-muted); font-size:.6875rem;">{{ ui.date }}</div>
								<div style="color:var(--cv-text-primary);">{{ fmtDate(matchingLine.date) }}</div>
							</div>
							<div>
								<div style="color:var(--cv-text-muted); font-size:.6875rem;">{{ ui.amount }}</div>
								<div
									:style="`color:${parseFloat(matchingLine.amount) >= 0 ? 'var(--cv-green-bright)' : 'var(--cv-danger-text)'}`">
									{{ parseFloat(matchingLine.amount) >= 0 ? '+' : '-' }}{{ fmtAmount(matchingLine.amount) }}
								</div>
							</div>
							<div style="grid-column:span 2;">
								<div style="color:var(--cv-text-muted); font-size:.6875rem;">{{ ui.description }}</div>
								<div style="color:var(--cv-text-secondary);">{{ matchingLine.description }}</div>
							</div>
						</div>
						<p style="font-size:.8125rem; color:var(--cv-text-muted); margin-bottom:1rem;">
							{{ ui.matchSub }}
						</p>
						<div>
							<label class="cv-label">{{ ui.fieldJeId }} *</label>
							<input type="number" class="cv-input" v-model="matchForm.journal_entry_id"
								:placeholder="ui.fieldJeIdPh" min="1" style="font-variant-numeric:tabular-nums;" />
							<p v-if="matchForm.errors.journal_entry_id"
								style="color:var(--cv-danger-text); font-size:.75rem; margin-top:.25rem;">
								{{ matchForm.errors.journal_entry_id }}
							</p>
						</div>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="showMatchModal = false">
							{{ ui.cancelBtn }}
						</button>
						<button class="cv-btn cv-btn-amber"
							:disabled="matchForm.processing || !matchForm.journal_entry_id" @click="submitMatch">
							{{ ui.confirmMatch }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
	<!-- ════════════════════════════════════════════════════════
             MODAL: Unmatch Confirm
        ════════════════════════════════════════════════════════ -->
	<Teleport to="body">
		<Transition name="cv-modal-fade">
			<div v-if="showUnmatchModal" class="cv-modal-backdrop" @click.self="showUnmatchModal = false">
				<div class="cv-modal" style="max-width:420px; width:100%;" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<h3 class="cv-modal-title" style="color:var(--cv-amber-text);">
							{{ ui.unmatchTitle }}
						</h3>
						<button class="cv-modal-close" @click="showUnmatchModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body">
						<div style="display:flex; gap:1rem; align-items:flex-start;">
							<div style="flex-shrink:0; width:40px; height:40px; border-radius:50%;
                                            background:var(--cv-warning-bg); border:1px solid var(--cv-warning-border);
                                            display:flex; align-items:center; justify-content:center;">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)"
									stroke-width="1.7">
									<path
										d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
									<line x1="12" y1="9" x2="12" y2="13" />
									<line x1="12" y1="17" x2="12.01" y2="17" />
								</svg>
							</div>
							<div>
								<p style="color:var(--cv-text-primary); font-size:.875rem; margin-bottom:.375rem;">
									{{ ui.unmatchSub }}
								</p>
								<p v-if="unmatchingLine" style="font-size:.8rem; color:var(--cv-text-muted);"> JE #{{
									unmatchingLine.matched_entry_id }} </p>
							</div>
						</div>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="showUnmatchModal = false">
							{{ ui.cancelBtn }}
						</button>
						<button class="cv-btn" :disabled="unmatchForm.processing"
							style="background:var(--cv-warning-bg); border:1px solid var(--cv-warning-border); color:var(--cv-amber-text);"
							@click="submitUnmatch">
							{{ ui.confirmUnmatch }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
	<!-- ════════════════════════════════════════════════════════
             MODAL: Reconcile Confirm
        ════════════════════════════════════════════════════════ -->
	<Teleport to="body">
		<Transition name="cv-modal-fade">
			<div v-if="showReconcileModal" class="cv-modal-backdrop" @click.self="showReconcileModal = false">
				<div class="cv-modal" style="max-width:440px; width:100%;" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<h3 class="cv-modal-title" style="color:var(--cv-green-bright);">
							{{ ui.reconcileTitle }}
						</h3>
						<button class="cv-modal-close" @click="showReconcileModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body">
						<!-- Summary card -->
						<div v-if="reconcilingLine" style="
                                background:var(--cv-success-bg); border:1px solid var(--cv-success-border);
                                border-radius:var(--cv-radius); padding:.875rem 1rem; margin-bottom:1rem;
                                display:grid; grid-template-columns:1fr 1fr; gap:.5rem; font-size:.8rem;
                            ">
							<div>
								<div style="color:var(--cv-text-muted); font-size:.6875rem;">{{ ui.date }}</div>
								<div style="color:var(--cv-text-primary);">{{ fmtDate(reconcilingLine.date) }}</div>
							</div>
							<div>
								<div style="color:var(--cv-text-muted); font-size:.6875rem;">{{ ui.amount }}</div>
								<div style="color:var(--cv-green-bright); font-weight:700;">
									{{ parseFloat(reconcilingLine.amount) >= 0 ? '+' : '-' }}{{ fmtAmount(reconcilingLine.amount) }}
								</div>
							</div>
							<div>
								<div style="color:var(--cv-text-muted); font-size:.6875rem;">JE</div>
								<div style="color:var(--cv-blue-text);">#{{ reconcilingLine.matched_entry_id }}</div>
							</div>
						</div>
						<p style="font-size:.8125rem; color:var(--cv-text-secondary); margin-bottom:.625rem;">
							{{ ui.reconcileSub }}
						</p>
						<p style="font-size:.75rem; color:var(--cv-warning-text);"> ⚠ {{ ui.reconcileWarn }}
						</p>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="showReconcileModal = false">
							{{ ui.cancelBtn }}
						</button>
						<button class="cv-btn" :disabled="reconcileForm.processing"
							style="background:var(--cv-green); color:#fff; border:none; padding:.5rem 1.125rem; border-radius:var(--cv-radius); cursor:pointer; font-weight:600;"
							@click="submitReconcile">
							{{ ui.confirmReconcile }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
	<!-- ════════════════════════════════════════════════════════
             MODAL: Delete Confirm
        ════════════════════════════════════════════════════════ -->
	<Teleport to="body">
		<Transition name="cv-modal-fade">
			<div v-if="showDeleteModal" class="cv-modal-backdrop" @click.self="showDeleteModal = false">
				<div class="cv-modal" style="max-width:420px; width:100%;" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<h3 class="cv-modal-title" style="color:var(--cv-danger-text);">
							{{ ui.deleteTitle }}
						</h3>
						<button class="cv-modal-close" @click="showDeleteModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body">
						<div style="display:flex; gap:1rem; align-items:flex-start;">
							<div style="flex-shrink:0; width:40px; height:40px; border-radius:50%;
                                            background:var(--cv-danger-bg); border:1px solid var(--cv-danger-border);
                                            display:flex; align-items:center; justify-content:center;">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
									stroke="var(--cv-danger-text)" stroke-width="1.7">
									<polyline points="3 6 5 6 21 6" />
									<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
									<path d="M10 11v6" />
									<path d="M14 11v6" />
									<path d="M9 6V4h6v2" />
								</svg>
							</div>
							<div>
								<p style="color:var(--cv-text-primary); font-size:.875rem; margin-bottom:.375rem;">
									{{ ui.deleteSub }}
								</p>
								<p v-if="deletingLine" style="font-size:.8rem; color:var(--cv-text-muted);">
									{{ fmtDate(deletingLine.date) }} — {{ deletingLine.description }}
								</p>
							</div>
						</div>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="showDeleteModal = false">
							{{ ui.cancelBtn }}
						</button>
						<button class="cv-btn cv-btn-danger" :disabled="deleteForm.processing" @click="submitDelete">
							{{ ui.confirmDelete }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
	<!-- ════════════════════════════════════════════════════════
             MODAL: Last Upload Errors
        ════════════════════════════════════════════════════════ -->
	<Teleport to="body">
		<Transition name="cv-modal-fade">
			<div v-if="showUploadErrorsModal" class="cv-modal-backdrop" @click.self="showUploadErrorsModal = false">
				<div class="cv-modal" style="max-width:860px; width:100%;" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<h3 class="cv-modal-title" style="color:var(--cv-danger-text);">{{ ui.uploadErrorsTitle }}</h3>
						<button class="cv-modal-close" @click="showUploadErrorsModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body">
						<div v-if="!hasLastUploadErrors" style="color:var(--cv-text-muted);font-size:.85rem;">
							{{ ui.noUploadErrors }}
						</div>
						<div v-else style="overflow:auto;max-height:50vh;border:1px solid var(--cv-border);border-radius:8px;">
							<table style="width:100%;border-collapse:collapse;font-size:.8rem;">
								<thead>
									<tr style="background:var(--cv-bg-card-alt);border-bottom:1px solid var(--cv-border);">
										<th style="padding:.65rem .75rem;text-align:start;">{{ ui.errorRow }}</th>
										<th style="padding:.65rem .75rem;text-align:start;">{{ ui.errorColumn }}</th>
										<th style="padding:.65rem .75rem;text-align:start;">{{ ui.errorMessage }}</th>
									</tr>
								</thead>
								<tbody>
									<tr
										v-for="(uploadError, idx) in props.lastUploadErrors.errors"
										:key="`${uploadError.row}-${uploadError.column}-${idx}`"
										style="border-bottom:1px solid var(--cv-border);"
									>
										<td style="padding:.6rem .75rem;color:var(--cv-danger-text);font-weight:700;">
											{{ uploadError.row }}
										</td>
										<td style="padding:.6rem .75rem;color:var(--cv-text-secondary);">
											{{ uploadError.column }}
										</td>
										<td style="padding:.6rem .75rem;color:var(--cv-text-primary);">
											{{ uploadError.error }}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="showUploadErrorsModal = false">
							{{ ui.cancelBtn }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
</template>
