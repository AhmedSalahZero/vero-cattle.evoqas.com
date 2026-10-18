<script setup>
/**
 * Accounting/JournalEntries/CreateEdit.vue
 *
 * 📌 METHODOLOGY REFERENCE: JournalBalanceValidation
 *   |Σ debit − Σ credit| ≤ 0.005  (EGP amounts only)
 *
 * 📌 METHODOLOGY REFERENCE: ForeignCurrencyJournalLine
 *   EGP amount = foreign_amount × exchange_rate
 *   Triggered when selected account.currency ≠ EGP (and not null)
 *   debit/credit always stored in EGP — foreign fields are audit trail
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'

const { locale: lang, isRtl, n } = useLocale()

// ── Bilingual UI strings ──────────────────────────────────────────
const ui = {
	eyebrow: { en: 'Accounting', ar: 'المحاسبة' },
	titleCreate: { en: 'New Journal Entry', ar: 'قيد يومي جديد' },
	titleEdit: { en: 'Edit Journal Entry', ar: 'تعديل القيد اليومي' },
	subCreate: { en: 'Enter a balanced double-entry record', ar: 'أدخل قيداً محاسبياً مزدوجاً متوازناً' },
	subEdit: { en: 'Update this draft entry', ar: 'تعديل هذه المسودة' },

	// Section 1
	sec1: { en: '1. Entry Header', ar: '١. بيانات القيد' },
	labelCompany: { en: 'Company', ar: 'الشركة' },
	selectCompany: { en: 'Select company…', ar: 'اختر الشركة…' },
	labelDate: { en: 'Entry Date', ar: 'تاريخ القيد' },
	labelFy: { en: 'Fiscal Year', ar: 'السنة المالية' },
	// labelRef: { en: 'Reference', ar: 'المرجع' },
	labelDesc: { en: 'Description', ar: 'الوصف' },
	phRef: { en: 'e.g. JE-2025-001', ar: 'مثال: JE-2025-001' },
	phDesc: { en: 'Entry description…', ar: 'وصف القيد…' },
	selectFy: { en: 'Select fiscal year…', ar: 'اختر السنة المالية…' },

	// Section 2
	sec2: { en: '2. Journal Lines', ar: '٢. سطور القيد' },
	colAccount: { en: 'Account', ar: 'الحساب' },
	colDebit: { en: 'Debit (Dr) — EGP', ar: 'مدين (Dr) — ج.م' },
	colCredit: { en: 'Credit (Cr) — EGP', ar: 'دائن (Cr) — ج.م' },
	colMemo: { en: 'Memo', ar: 'ملاحظة' },
	addLine: { en: 'Add Line', ar: 'إضافة سطر' },
	searchAccount: { en: 'Search account…', ar: 'بحث عن حساب…' },
	noAccount: { en: 'No matching accounts', ar: 'لا توجد حسابات مطابقة' },
	removeLine: { en: 'Remove line', ar: 'حذف السطر' },
	minLines: { en: 'A journal entry requires at least 2 lines.', ar: 'القيد يحتاج إلى سطرين على الأقل.' },

	// Foreign currency
	foreignAmt: { en: 'Amount', ar: 'المبلغ' },
	exchangeRate: { en: 'Rate (1 {cur} = ? EGP)', ar: 'سعر الصرف (1 {cur} = ? ج.م)' },
	egpCalc: { en: 'EGP =', ar: 'ج.م =' },
	rateZeroErr: { en: 'Exchange rate cannot be zero', ar: 'سعر الصرف لا يمكن أن يكون صفراً' },
	foreignDr: { en: 'Debit side (Dr)', ar: 'في الجانب المدين' },
	foreignCr: { en: 'Credit side (Cr)', ar: 'في الجانب الدائن' },
	foreignSide: { en: 'Side', ar: 'الجانب' },

	// Section 3 — Balance
	sec3: { en: '3. Balance Check', ar: '٣. التحقق من التوازن' },
	totalDebit: { en: 'Total Debit', ar: 'إجمالي المدين' },
	totalCredit: { en: 'Total Credit', ar: 'إجمالي الدائن' },
	difference: { en: 'Difference', ar: 'الفرق' },
	balanced: { en: '✓ Balanced — entry is ready to post', ar: '✓ متوازن — القيد جاهز للترحيل' },
	imbalanced: { en: 'Entry is not balanced. Fix before posting.', ar: 'القيد غير متوازن. صحح الأرقام قبل الترحيل.' },
	allInEgp: { en: 'All amounts in EGP (functional currency)', ar: 'جميع المبالغ بالجنيه المصري (العملة الوظيفية)' },

	// Section 4 — Notes
	sec4: { en: '4. Internal Notes', ar: '٤. ملاحظات داخلية' },
	labelNotes: { en: 'Notes (optional)', ar: 'ملاحظات (اختياري)' },
	phNotes: { en: 'Internal notes for this entry…', ar: 'ملاحظات داخلية لهذا القيد…' },

	// Actions
	saveDraft: { en: 'Save Draft', ar: 'حفظ كمسودة' },
	savePost: { en: 'Save & Post', ar: 'حفظ وترحيل' },
	cancel: { en: 'Cancel', ar: 'إلغاء' },
	back: { en: 'Back to entries', ar: 'العودة للقيود' },

	// Post confirm modal
	postTitle: { en: 'Confirm Post', ar: 'تأكيد الترحيل' },
	postBody: { en: 'Once posted, this entry cannot be edited — only reversed. Proceed?', ar: 'بعد الترحيل لا يمكن تعديل القيد — يمكن عكسه فقط. هل تريد المتابعة؟' },
	postConfirm: { en: 'Post Entry', ar: 'ترحيل القيد' },

	// Auto-entry banner
	autoTitle: { en: 'Auto-Generated Entry', ar: 'قيد تلقائي' },
	autoBody: { en: 'This entry was created automatically by the system and cannot be edited manually.', ar: 'هذا القيد أنشأه النظام تلقائياً ولا يمكن تعديله يدوياً.' },

	// Validation
	unbalancedErr: { en: 'Entry must be balanced before posting', ar: 'يجب أن يكون القيد متوازناً قبل الترحيل' },
}

const t = (key, vars = {}) => {
	let str = ui[key]?.[lang.value] ?? key
	Object.entries(vars).forEach(([k, v]) => { str = str.replace(`{${k}}`, v) })
	return str
}

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
	mode: { type: String, default: 'create' },
	entry: { type: Object, default: null },
	accounts: { type: Array, default: () => [] },
	fiscalYears: { type: Array, default: () => [] },
	companies: { type: Array, default: () => [] },
})

// ── Super admin company cascade ───────────────────────────────────
const isSuperAdmin = computed(() => props.companies.length > 0)
const selectedCompanyId = ref(props.entry?.company_id ?? '')
const dynamicFiscalYears = ref([...props.fiscalYears])
const dynamicAccounts = ref([...props.accounts])

// QA-003/006/008: newest answer wins; failed lookups are ignored; lines picked under the
// previous company are cleared (their accounts belong to the other company).
let companyReq = 0
watch(selectedCompanyId, async (cid) => {
	const myReq = ++companyReq
	if (!cid) return
	form.lines.forEach(l => {
		l.account_id = ''; l.foreign_currency = null; l.foreign_amount = ''
		l.exchange_rate = 1; l.debit = 0; l.credit = 0
	})
	form.fiscal_year_id = ''
	form.company_id = cid
	dynamicAccounts.value = []
	dynamicFiscalYears.value = []
	initTypeahead()
	try {
		const res = await fetch(route('journal-entries.data-for-company') + `?company_id=${cid}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
		if (!res.ok) return
		const data = await res.json()
		if (myReq !== companyReq) return
		dynamicFiscalYears.value = data.fiscal_years ?? []
		dynamicAccounts.value = data.accounts ?? []
	} catch (e) { console.error('Failed to load company data', e) }
})

const isEdit = computed(() => props.mode === 'edit')
const isAutoEntry = computed(() => props.entry?.source_type && props.entry?.entry_type !== 'manual' && props.entry?.entry_type !== 'opening_balance')
const readOnly = computed(() => isEdit.value && isAutoEntry.value)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
	date: props.entry?.date ?? localToday(),
	company_id: props.entry?.company_id ?? '',
	fiscal_year_id: props.entry?.fiscal_year_id ?? '',
	//    reference:        props.entry?.reference        ?? '',
	description: props.entry?.description ?? '',
	notes: props.entry?.notes ?? '',
	lines: props.entry?.lines?.map(l => ({
		account_id: l.account_id ?? '',
		debit: l.debit ?? 0,
		credit: l.credit ?? 0,
		memo: l.memo ?? '',
		foreign_currency: l.foreign_currency ?? null,
		foreign_amount: l.foreign_amount ?? '',
		exchange_rate: l.exchange_rate ?? 1,
		foreign_side: parseFloat(l.debit ?? 0) > 0 ? 'debit' : 'credit',
	})) ?? [
			{ account_id: '', debit: 0, credit: 0, memo: '', foreign_currency: null, foreign_amount: '', exchange_rate: 1, foreign_side: 'debit' },
			{ account_id: '', debit: 0, credit: 0, memo: '', foreign_currency: null, foreign_amount: '', exchange_rate: 1, foreign_side: 'debit' },
		],
	post_immediately: false,
})

// ── Line management ───────────────────────────────────────────────
const addLine = () => {
	form.lines.push({ account_id: '', debit: 0, credit: 0, memo: '', foreign_currency: null, foreign_amount: '', exchange_rate: 1, foreign_side: 'debit' })
}

const removeLine = (idx) => {
	if (form.lines.length <= 2) return
	form.lines.splice(idx, 1)
}

// ── Balance calculation ─────────────────────────────────────────
// 📌 METHODOLOGY: JournalBalanceValidation — runs on EGP amounts only
const totalDebit = computed(() => form.lines.reduce((s, l) => s + (parseFloat(l.debit) || 0), 0))
const totalCredit = computed(() => form.lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0))
const difference = computed(() => Math.abs(totalDebit.value - totalCredit.value))
// QA-007: an entry with no amounts at all is not "balanced"
const isBalanced = computed(() => difference.value <= 0.005 && totalDebit.value > 0)

const formatAmt = (v, decimals = 2) => {
	const num = parseFloat(v ?? 0)
	return num.toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

// ── Foreign currency helpers ──────────────────────────────────────
// 📌 METHODOLOGY: ForeignCurrencyJournalLine
const isForeignLine = (line) => {
	if (!line.account_id) return false
	const acc = dynamicAccounts.value.find(a => a.id === line.account_id)
	return acc?.currency && acc.currency !== 'EGP'
}
// ── FX rate auto-fill ─────────────────────────────────────────────
const fetchFxRate = async (currency) => {
	if (!currency || currency === 'EGP') return null
	try {
		const cid = form.company_id || selectedCompanyId.value || ''
		const res = await fetch(route('fx-rates.latest') + `?currency=${currency}&company_id=${cid}`)
		const data = await res.json()
		return data.rate ?? null
	} catch {
		return null
	}
}

const lineAccountCurrency = (line) => {
	const acc = dynamicAccounts.value.find(a => a.id === line.account_id)
	return acc?.currency ?? 'EGP'
}

// Recalculate EGP debit/credit from foreign_amount × exchange_rate
const recalcEgp = (line) => {
	const fa = parseFloat(line.foreign_amount) || 0
	const rate = parseFloat(line.exchange_rate) || 1
	const egp = Math.round(fa * rate * 100) / 100
	if (line.foreign_side === 'debit') {
		line.debit = egp
		line.credit = 0
	} else {
		line.credit = egp
		line.debit = 0
	}
	// store foreign_currency from account
	line.foreign_currency = lineAccountCurrency(line)
}

// ── Account typeahead ─────────────────────────────────────────────
const searchQueries = ref([])
const dropdownOpen = ref([])

const initTypeahead = () => {
	form.lines.forEach((line, idx) => {
		const acc = dynamicAccounts.value.find(a => a.id === line.account_id)
		searchQueries.value[idx] = acc ? `${acc.code} — ${n(acc)}` : ''
		dropdownOpen.value[idx] = false
	})
}
onMounted(initTypeahead)

watch(() => form.lines.length, (newLen) => {
	for (let i = searchQueries.value.length; i < newLen; i++) {
		searchQueries.value[i] = ''
		dropdownOpen.value[i] = false
	}
})

const filteredAccounts = (idx) => {
	const q = (searchQueries.value[idx] ?? '').toLowerCase()
	if (!q) return dynamicAccounts.value.slice(0, 20)
	return dynamicAccounts.value.filter(a =>
		a.code.toLowerCase().includes(q) ||
		a.name_en?.toLowerCase().includes(q) ||
		a.name_ar?.includes(q)
	).slice(0, 20)
}

const openDropdown = (idx) => { dropdownOpen.value = dropdownOpen.value.map((_, i) => i === idx) }

const selectAccount = (idx, acc) => {
	const line = form.lines[idx]
	const prevCurrency = lineAccountCurrency(line)

	line.account_id = acc.id
	searchQueries.value[idx] = `${acc.code} — ${n(acc)}`
	dropdownOpen.value[idx] = false

	// Reset foreign fields if currency changed
	const newCurrency = acc.currency ?? 'EGP'
	if (newCurrency !== prevCurrency) {
		line.foreign_currency = newCurrency !== 'EGP' ? newCurrency : null
		line.foreign_amount = ''
		line.exchange_rate = 1
		line.debit = 0
		line.credit = 0
		// Auto-fill today's stored rate if one exists
		if (newCurrency !== 'EGP') {
			fetchFxRate(newCurrency).then(rate => {
				// ignore the answer if the line was changed to another account meanwhile
				if (rate && line.account_id === acc.id) line.exchange_rate = rate
			})
		}
	}
}

const clearAccount = (idx) => {
	const line = form.lines[idx]
	line.account_id = ''
	line.foreign_currency = null
	line.foreign_amount = ''
	line.exchange_rate = 1
	line.debit = 0
	line.credit = 0
	searchQueries.value[idx] = ''
	dropdownOpen.value[idx] = true
	nextTick(() => {
		const inputs = document.querySelectorAll(`.je-account-search-${idx}`)
		if (inputs[0]) inputs[0].focus()
	})
}

const handleClickOutside = (e) => {
	if (!e.target.closest('.je-typeahead-wrap')) {
		dropdownOpen.value = dropdownOpen.value.map(() => false)
	}
}
onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))

// ── Submit ────────────────────────────────────────────────────────
const postModal = ref(false)

const submitDraft = () => {
	form.post_immediately = false
	isEdit.value
		? form.put(route('journal-entries.update', props.entry.id), { preserveScroll: true })
		: form.post(route('journal-entries.store'), { preserveScroll: true })
}

const openPostModal = () => { if (isBalanced.value) postModal.value = true }

const confirmPost = () => {
	form.post_immediately = true
	postModal.value = false
	isEdit.value
		? form.put(route('journal-entries.update', props.entry.id), { preserveScroll: true })
		: form.post(route('journal-entries.store'), { preserveScroll: true })
}
</script>
<template>
	<AuthenticatedLayout>
		<template #header>
			<span class="cv-header-breadcrumb-current">{{ isEdit ? t('titleEdit') : t('titleCreate') }}</span>
		</template>
		<div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">
			<!-- ── Hero ──────────────────────────────────────────── -->
			<div class="cv-page-hero">
				<div class="cv-page-hero-eyebrow">{{ t('eyebrow') }}</div>
				<div class="cv-page-hero-title">{{ isEdit ? t('titleEdit') : t('titleCreate') }}</div>
				<div class="cv-page-hero-sub">{{ isEdit ? t('subEdit') : t('subCreate') }}</div>
				<div class="cv-page-hero-actions">
					<a :href="route('journal-entries.index')" class="cv-btn cv-btn-ghost">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
							<polyline points="15 18 9 12 15 6" />
						</svg>
						{{ t('back') }}
					</a>
				</div>
			</div>
			<!-- ── Auto-entry banner ──────────────────────────────── -->
			<div v-if="readOnly"
				style="background:var(--cv-info-bg);border:1px solid var(--cv-info-border);border-radius:var(--cv-radius-md);padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;gap:0.75rem;align-items:flex-start;">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--cv-blue-text)"
					stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
					style="flex-shrink:0;margin-top:1px;">
					<circle cx="12" cy="12" r="10" />
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
				<div>
					<div style="font-size:0.875rem;font-weight:600;color:var(--cv-blue-text);">{{ t('autoTitle') }}
					</div>
					<div style="font-size:0.8125rem;color:var(--cv-text-muted);margin-top:2px;">{{ t('autoBody') }}
					</div>
				</div>
			</div>
			<!-- ════════════════════════════════════════════════════
                 SECTION 1 — Entry Header
            ════════════════════════════════════════════════════ -->
			<div class="cv-card" style="margin-bottom:1.25rem;">
				<div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
					<div
						style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--cv-amber),var(--cv-amber-hover));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#0A1808;flex-shrink:0;">
						{{ isRtl ? '١' : '1' }}
					</div>
					<div style="font-size:0.875rem;font-weight:700;color:var(--cv-text-primary);">{{ t('sec1') }}</div>
				</div>
				<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;">
					<!-- Company — super admin only -->
					<div v-if="isSuperAdmin" class="cv-field" style="grid-column:1/-1;">
						<label class="cv-label">{{ t('labelCompany') }} <span
								style="color:var(--cv-danger-text);">*</span></label>
						<select class="cv-select" v-model="selectedCompanyId" :disabled="isEdit">
							<option value="">{{ t('selectCompany') }}</option>
							<option v-for="c in companies" :key="c.id" :value="c.id">
								{{ lang === 'ar' ? (c.name_ar || c.name_en) : (c.name_en || c.name_ar) }}
							</option>
						</select>
					</div>
					<!-- Date -->
					<div class="cv-field">
						<label class="cv-label">{{ t('labelDate') }} <span
								style="color:var(--cv-danger-text);">*</span></label>
						<input type="date" class="cv-input" :class="{ 'cv-input--error': form.errors.date }"
							v-model="form.date" :disabled="readOnly">
						<div v-if="form.errors.date" class="cv-field-error">{{ form.errors.date }}</div>
					</div>
					<!-- Fiscal Year -->
					<div class="cv-field">
						<label class="cv-label">{{ t('labelFy') }} <span
								style="color:var(--cv-danger-text);">*</span></label>
						<select class="cv-select" :class="{ 'cv-select--error': form.errors.fiscal_year_id }"
							v-model="form.fiscal_year_id" :disabled="readOnly">
							<option value="">{{ t('selectFy') }}</option>
							<option v-for="fy in dynamicFiscalYears" :key="fy.id" :value="fy.id">
								{{ fy.year }} ({{ fy.start_date }} → {{ fy.end_date }})
							</option>
						</select>
						<div v-if="form.errors.fiscal_year_id" class="cv-field-error">{{ form.errors.fiscal_year_id }}
						</div>
					</div>
					<!-- Reference -->
					<!-- <div class="cv-field">
						<label class="cv-label">{{ t('labelRef') }}</label>
						<input type="text" class="cv-input" v-model="form.reference" :placeholder="t('phRef')"
							:disabled="readOnly">
						<div v-if="form.errors.reference" class="cv-field-error">{{ form.errors.reference }}</div>
					</div> -->
					<!-- Description -->
					<div class="cv-field" style="grid-column:1/-1;">
						<label class="cv-label">{{ t('labelDesc') }}</label>
						<input type="text" class="cv-input" v-model="form.description" :placeholder="t('phDesc')"
							:disabled="readOnly">
						<div v-if="form.errors.description" class="cv-field-error">{{ form.errors.description }}</div>
					</div>
				</div>
			</div>
			<!-- ════════════════════════════════════════════════════
                 SECTION 2 — Journal Lines
            ════════════════════════════════════════════════════ -->
			<div class="cv-card" style="margin-bottom:1.25rem;">
				<div
					style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.5rem;">
					<div style="display:flex;align-items:center;gap:0.75rem;">
						<div
							style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--cv-amber),var(--cv-amber-hover));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#0A1808;flex-shrink:0;">
							{{ isRtl ? '٢' : '2' }}
						</div>
						<div style="font-size:0.875rem;font-weight:700;color:var(--cv-text-primary);">{{ t('sec2') }}
						</div>
					</div>
					<button v-if="!readOnly" class="cv-btn cv-btn-secondary cv-btn-sm" @click="addLine" type="button">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							stroke-width="2">
							<line x1="12" y1="5" x2="12" y2="19" />
							<line x1="5" y1="12" x2="19" y2="12" />
						</svg>
						{{ t('addLine') }}
					</button>
				</div>
				<!-- Column headers -->
				<div
					style="display:grid;grid-template-columns:minmax(220px,2fr) 130px 130px minmax(110px,1fr) 32px;gap:0.5rem;margin-bottom:0.5rem;padding:0 0.25rem;">
					<div
						style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">
						{{ t('colAccount') }}
					</div>
					<div
						style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-blue-text);">
						{{ t('colDebit') }}
					</div>
					<div
						style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-blue-text);">
						{{ t('colCredit') }}
					</div>
					<div
						style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">
						{{ t('colMemo') }}
					</div>
					<div></div>
				</div>
				<!-- Server messages about the lines as a whole (wrong-company account, debit+credit on one line, …) -->
				<div v-for="(msg, key) in form.errors" :key="key" v-show="key === 'lines' || /^lines\.\d+\.(debit|credit)$/.test(key)"
					class="cv-field-error" style="margin-bottom:0.5rem;">{{ msg }}</div>
				<!-- Lines -->
				<div v-for="(line, idx) in form.lines" :key="idx" style="margin-bottom:0.75rem;">
					<!-- Main line row -->
					<div
						style="display:grid;grid-template-columns:minmax(220px,2fr) 130px 130px minmax(110px,1fr) 32px;gap:0.5rem;align-items:start;">
						<!-- Account typeahead -->
						<div class="je-typeahead-wrap" style="position:relative;">
							<div style="display:flex;gap:0.25rem;align-items:center;">
								<input :class="`cv-input je-account-search-${idx}`"
									:style="form.errors[`lines.${idx}.account_id`] ? 'border-color:var(--cv-danger)' : ''"
									v-model="searchQueries[idx]" :placeholder="t('searchAccount')" :disabled="readOnly"
									@focus="openDropdown(idx)" @input="openDropdown(idx)" autocomplete="off">
								<button v-if="line.account_id && !readOnly" type="button"
									class="cv-action-btn cv-action-btn-danger" style="flex-shrink:0;"
									:title="t('removeLine')" @click="clearAccount(idx)">
									<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
										stroke-width="2">
										<line x1="18" y1="6" x2="6" y2="18" />
										<line x1="6" y1="6" x2="18" y2="18" />
									</svg>
								</button>
							</div>
							<!-- Dropdown -->
							<div v-if="dropdownOpen[idx] && !readOnly"
								style="position:absolute;top:calc(100% + 4px);inset-inline-start:0;width:340px;background:var(--cv-bg-card);border:1px solid var(--cv-border-md);border-radius:var(--cv-radius);box-shadow:var(--cv-shadow-md);z-index:100;max-height:220px;overflow-y:auto;"
								class="cv-scroll">
								<div v-if="filteredAccounts(idx).length === 0"
									style="padding:0.75rem;font-size:0.8125rem;color:var(--cv-text-muted);text-align:center;">
									{{ t('noAccount') }}
								</div>
								<div v-for="acc in filteredAccounts(idx)" :key="acc.id"
									@mousedown.prevent="selectAccount(idx, acc)"
									style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0.75rem;cursor:pointer;transition:var(--cv-transition);"
									:style="line.account_id === acc.id ? 'background:var(--cv-green-dim)' : ''"
									@mouseenter="e => e.currentTarget.style.background = 'var(--cv-bg-hover)'"
									@mouseleave="e => e.currentTarget.style.background = line.account_id === acc.id ? 'var(--cv-green-dim)' : ''">
									<span
										style="font-size:0.75rem;font-weight:700;color:var(--cv-blue-text);font-family:monospace;min-width:42px;">{{ acc.code }}</span>
									<span style="font-size:0.8125rem;color:var(--cv-text-primary);">{{ n(acc) }}</span>
									<span v-if="acc.currency && acc.currency !== 'EGP'"
										style="font-size:0.6rem;font-weight:700;color:var(--cv-amber-text);background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:4px;padding:1px 5px;margin-inline-start:auto;">{{ acc.currency }}</span>
								</div>
							</div>
							<div v-if="form.errors[`lines.${idx}.account_id`]" class="cv-field-error">
								{{ form.errors[`lines.${idx}.account_id`] }}
							</div>
						</div>
						<!-- Debit — EGP (read-only if foreign) -->
						<div>
							<input type="number" class="cv-input" v-model="line.debit" min="0" step="0.01"
								:disabled="readOnly || isForeignLine(line)"
								:style="(readOnly || isForeignLine(line)) ? 'text-align:end;font-variant-numeric:tabular-nums;opacity:0.6;background:var(--cv-bg-card-alt)' : 'text-align:end;font-variant-numeric:tabular-nums;'"
								@input="parseFloat(line.debit) > 0 ? line.credit = 0 : null">
						</div>
						<!-- Credit — EGP (read-only if foreign) -->
						<div>
							<input type="number" class="cv-input" v-model="line.credit" min="0" step="0.01"
								:disabled="readOnly || isForeignLine(line)"
								:style="(readOnly || isForeignLine(line)) ? 'text-align:end;font-variant-numeric:tabular-nums;opacity:0.6;background:var(--cv-bg-card-alt)' : 'text-align:end;font-variant-numeric:tabular-nums;'"
								@input="parseFloat(line.credit) > 0 ? line.debit = 0 : null">
						</div>
						<!-- Memo -->
						<div>
							<input type="text" class="cv-input" v-model="line.memo"
								:placeholder="isRtl ? 'ملاحظة…' : 'Memo…'" :disabled="readOnly">
						</div>
						<!-- Remove button -->
						<div style="display:flex;align-items:center;">
							<button v-if="!readOnly && form.lines.length > 2" type="button"
								class="cv-action-btn cv-action-btn-danger" :title="t('removeLine')"
								@click="removeLine(idx)">
								<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
									stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
									<polyline points="3 6 5 6 21 6" />
									<path d="M19 6l-1 14H6L5 6" />
									<path d="M10 11v6" />
									<path d="M14 11v6" />
									<path d="M9 6V4h6v2" />
								</svg>
							</button>
						</div>
					</div>
					<!-- ── Foreign currency sub-row ─────────────────
                         📌 METHODOLOGY: ForeignCurrencyJournalLine
                         Shown only when account.currency ≠ EGP
                    ──────────────────────────────────────────────── -->
					<div v-if="isForeignLine(line) && !readOnly"
						style="margin-top:0.5rem;margin-inline-start:0;background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:var(--cv-radius);padding:0.75rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
						<!-- Currency badge -->
						<div style="display:flex;align-items:center;gap:0.375rem;">
							<span
								style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-amber-text);">
								{{ lineAccountCurrency(line) }}
							</span>
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber-text)"
								stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="10" />
								<path d="M12 6v6l4 2" />
							</svg>
						</div>
						<!-- Dr / Cr side toggle -->
						<div class="cv-field" style="margin:0;min-width:120px;">
							<label class="cv-label" style="color:var(--cv-amber-text);">{{ t('foreignSide') }}</label>
							<select class="cv-select" v-model="line.foreign_side" @change="recalcEgp(line)"
								style="background:var(--cv-bg-card);">
								<option value="debit">{{ t('foreignDr') }}</option>
								<option value="credit">{{ t('foreignCr') }}</option>
							</select>
						</div>
						<!-- Foreign amount -->
						<div class="cv-field" style="margin:0;min-width:130px;">
							<label class="cv-label" style="color:var(--cv-amber-text);">
								{{ t('foreignAmt') }} ({{ lineAccountCurrency(line) }}) </label>
							<input type="number" class="cv-input" v-model="line.foreign_amount" min="0" step="0.01"
								style="background:var(--cv-bg-card);text-align:end;font-variant-numeric:tabular-nums;"
								@input="recalcEgp(line)">
						</div>
						<!-- Exchange rate -->
						<div class="cv-field" style="margin:0;min-width:160px;">
							<label class="cv-label"
								style="color:var(--cv-amber-text);display:flex;align-items:center;gap:0.375rem;">
								{{ t('exchangeRate', { cur: lineAccountCurrency(line) }) }}
								<span style="font-size:0.65rem;opacity:0.7;">
									{{ lang === 'ar' ? '(مُعبّأ تلقائياً)' : '(auto-filled)' }}
								</span>
							</label>
							<input type="number" class="cv-input" v-model="line.exchange_rate" min="0.000001"
								step="0.01"
								style="background:var(--cv-bg-card);text-align:end;font-variant-numeric:tabular-nums;"
								@input="recalcEgp(line)">
							<div v-if="parseFloat(line.exchange_rate) === 0" class="cv-field-error">
								{{ t('rateZeroErr') }}
							</div>
						</div>
						<!-- EGP result display -->
						<div style="display:flex;align-items:flex-end;padding-bottom:0.375rem;">
							<div
								style="background:var(--cv-bg-card);border:1px solid var(--cv-border-md);border-radius:var(--cv-radius);padding:0.5rem 0.75rem;font-size:0.875rem;font-variant-numeric:tabular-nums;">
								<span style="font-size:0.6875rem;color:var(--cv-text-muted);">{{ t('egpCalc') }} </span>
								<span style="font-weight:800;color:var(--cv-blue-text);">
									{{ formatAmt((parseFloat(line.foreign_amount) || 0) * (parseFloat(line.exchange_rate) || 1)) }}
								</span>
								<span
									style="font-size:0.6875rem;color:var(--cv-text-muted);margin-inline-start:4px;">EGP</span>
							</div>
						</div>
					</div>
					<!-- Foreign display in read-only / posted view -->
					<div v-if="isForeignLine(line) && readOnly && line.foreign_amount"
						style="margin-top:0.375rem;padding:0.375rem 0.625rem;background:var(--cv-amber-light);border-radius:var(--cv-radius-sm);display:inline-flex;align-items:center;gap:0.5rem;font-size:0.75rem;">
						<span style="color:var(--cv-amber-text);font-weight:700;">{{ line.foreign_currency }}</span>
						<span style="color:var(--cv-text-secondary);">{{ formatAmt(line.foreign_amount) }}</span>
						<span style="color:var(--cv-text-muted);">×</span>
						<span style="color:var(--cv-text-secondary);">{{ formatAmt(line.exchange_rate, 4) }}</span>
						<span style="color:var(--cv-text-muted);">=</span>
						<span
							style="color:var(--cv-blue-text);font-weight:700;">{{ formatAmt(parseFloat(line.foreign_amount) * parseFloat(line.exchange_rate)) }}
							EGP</span>
					</div>
				</div><!-- /line loop -->
				<!-- Min lines warning -->
				<div v-if="form.lines.length < 2"
					style="font-size:0.8125rem;color:var(--cv-danger-text);margin-top:0.5rem;">
					{{ t('minLines') }}
				</div>
			</div>
			<!-- ════════════════════════════════════════════════════
                 SECTION 3 — Live Balance Check
                 📌 METHODOLOGY: JournalBalanceValidation
            ════════════════════════════════════════════════════ -->
			<div class="cv-card" style="margin-bottom:1.25rem;"
				:style="isBalanced ? 'border-color:var(--cv-success-border)' : 'border-color:var(--cv-danger-border)'">
				<div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
					<div
						style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--cv-amber),var(--cv-amber-hover));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#0A1808;flex-shrink:0;">
						{{ isRtl ? '٣' : '3' }}
					</div>
					<div style="font-size:0.875rem;font-weight:700;color:var(--cv-text-primary);">{{ t('sec3') }}</div>
					<div style="font-size:0.6875rem;color:var(--cv-text-muted);margin-inline-start:auto;">
						{{ t('allInEgp') }}
					</div>
				</div>
				<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem;">
					<div
						style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);padding:1rem;text-align:center;">
						<div
							style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">
							{{ t('totalDebit') }}
						</div>
						<div
							style="font-size:1.25rem;font-weight:800;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">
							{{ formatAmt(totalDebit) }}
						</div>
						<div style="font-size:0.6875rem;color:var(--cv-text-muted);margin-top:2px;">EGP</div>
					</div>
					<div
						style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);padding:1rem;text-align:center;">
						<div
							style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">
							{{ t('totalCredit') }}
						</div>
						<div
							style="font-size:1.25rem;font-weight:800;color:var(--cv-blue-text);font-variant-numeric:tabular-nums;">
							{{ formatAmt(totalCredit) }}
						</div>
						<div style="font-size:0.6875rem;color:var(--cv-text-muted);margin-top:2px;">EGP</div>
					</div>
					<div style="border-radius:var(--cv-radius);padding:1rem;text-align:center;"
						:style="isBalanced ? 'background:var(--cv-success-bg);border:1px solid var(--cv-success-border)' : 'background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border)'">
						<div
							style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:4px;">
							{{ t('difference') }}
						</div>
						<div style="font-size:1.25rem;font-weight:800;font-variant-numeric:tabular-nums;"
							:style="isBalanced ? 'color:var(--cv-success-text)' : 'color:var(--cv-danger-text)'">
							{{ formatAmt(difference) }}
						</div>
						<div style="font-size:0.6875rem;color:var(--cv-text-muted);margin-top:2px;">EGP</div>
					</div>
				</div>
				<div style="border-radius:var(--cv-radius-sm);padding:0.75rem 1rem;display:flex;align-items:center;gap:0.625rem;"
					:style="isBalanced ? 'background:var(--cv-success-bg);border:1px solid var(--cv-success-border)' : 'background:var(--cv-danger-bg);border:1px solid var(--cv-danger-border)'">
					<svg v-if="isBalanced" width="15" height="15" viewBox="0 0 24 24" fill="none"
						stroke="var(--cv-success-text)" stroke-width="2.5">
						<polyline points="20 6 9 17 4 12" />
					</svg>
					<svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)"
						stroke-width="2.5">
						<circle cx="12" cy="12" r="10" />
						<line x1="12" y1="8" x2="12" y2="12" />
						<line x1="12" y1="16" x2="12.01" y2="16" />
					</svg>
					<span style="font-size:0.8125rem;font-weight:600;"
						:style="isBalanced ? 'color:var(--cv-success-text)' : 'color:var(--cv-danger-text)'">{{ isBalanced ? t('balanced') : t('imbalanced') }}</span>
				</div>
			</div>
			<!-- ════════════════════════════════════════════════════
                 SECTION 4 — Notes
            ════════════════════════════════════════════════════ -->
			<div class="cv-card" style="margin-bottom:1.5rem;">
				<div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
					<div
						style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--cv-amber),var(--cv-amber-hover));display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:#0A1808;flex-shrink:0;">
						{{ isRtl ? '٤' : '4' }}
					</div>
					<div style="font-size:0.875rem;font-weight:700;color:var(--cv-text-primary);">{{ t('sec4') }}</div>
				</div>
				<div class="cv-field">
					<label class="cv-label">{{ t('labelNotes') }}</label>
					<textarea class="cv-textarea" v-model="form.notes" :placeholder="t('phNotes')" rows="3"
						:disabled="readOnly" style="resize:vertical;"></textarea>
				</div>
			</div>
			<!-- ── Action buttons ─────────────────────────────────── -->
			<div v-if="!readOnly" style="display:flex;gap:0.75rem;flex-wrap:wrap;justify-content:flex-end;">
				<a :href="route('journal-entries.index')" class="cv-btn cv-btn-ghost">{{ t('cancel') }}</a>
				<button type="button" class="cv-btn cv-btn-secondary" @click="submitDraft" :disabled="form.processing">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
						stroke-linecap="round" stroke-linejoin="round">
						<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z" />
						<polyline points="17 21 17 13 7 13 7 21" />
						<polyline points="7 3 7 8 15 8" />
					</svg>
					{{ t('saveDraft') }}
				</button>
				<button type="button" class="cv-btn cv-btn-amber" @click="openPostModal"
					:disabled="form.processing || !isBalanced || form.lines.length < 2"
					:title="!isBalanced ? t('unbalancedErr') : ''">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
						stroke-linecap="round" stroke-linejoin="round">
						<polyline points="22 2 11 13" />
						<polygon points="22 2 15 22 11 13 2 9 22 2" />
					</svg>
					{{ t('savePost') }}
				</button>
			</div>
		</div><!-- /cv-page -->
		<!-- ══════════════════════════════════════════════════════════
             POST CONFIRMATION MODAL
        ══════════════════════════════════════════════════════════════ -->
		<Teleport to="body">
			<div v-if="postModal" class="cv-modal-backdrop" @click.self="postModal = false">
				<div class="cv-modal cv-modal-sm" :dir="isRtl ? 'rtl' : 'ltr'">
					<div class="cv-modal-header">
						<span style="font-weight:600;color:var(--cv-text-primary);">{{ t('postTitle') }}</span>
						<button class="cv-action-btn" @click="postModal = false">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2">
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>
					<div class="cv-modal-body">
						<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1rem;">
							<div
								style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius-sm);padding:0.75rem;text-align:center;">
								<div
									style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">
									{{ t('totalDebit') }}
								</div>
								<div style="font-size:1rem;font-weight:700;color:var(--cv-blue-text);">
									{{ formatAmt(totalDebit) }} EGP
								</div>
							</div>
							<div
								style="background:var(--cv-bg-card-alt);border-radius:var(--cv-radius-sm);padding:0.75rem;text-align:center;">
								<div
									style="font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--cv-text-muted);">
									{{ t('totalCredit') }}
								</div>
								<div style="font-size:1rem;font-weight:700;color:var(--cv-blue-text);">
									{{ formatAmt(totalCredit) }} EGP
								</div>
							</div>
						</div>
						<div
							style="background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);border-radius:var(--cv-radius-sm);padding:0.75rem;font-size:0.8125rem;color:var(--cv-amber-text);">
							{{ t('postBody') }}
						</div>
					</div>
					<div class="cv-modal-footer">
						<button class="cv-btn cv-btn-ghost" @click="postModal = false">{{ t('cancel') }}</button>
						<button class="cv-btn cv-btn-amber" @click="confirmPost">{{ t('postConfirm') }}</button>
					</div>
				</div>
			</div>
		</Teleport>
	</AuthenticatedLayout>
</template>
