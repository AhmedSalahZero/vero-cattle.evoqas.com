<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO — Sales/CreateEdit.vue
//  Handles both /sales/create and /sales/{id}/edit via mode prop.
//
//  📌 METHODOLOGY REFERENCE: ROIperLot (#20) — extended
//  📌 METHODOLOGY REFERENCE: BatchPriceDistribution (sub-formula of #20)
//
//  TWO ENTRY MODES (Section 2 toggle):
//  ┌─────────────────────────────────────────────────────────────┐
//  │ MODE A — BATCH (multi-select)                               │
//  │  User picks N animals from a checkbox list.                 │
//  │  Enters ONE total amount OR one price/kg.                   │
//  │  System distributes to per-head prices via weight.          │
//  │                                                             │
//  │  Per-KG path:   P_head_i = P_kg × w_i                      │
//  │  Total path:    P_kg = T / Σw_i                             │
//  │                 P_head_i = P_kg × w_i                       │
//  │                                                             │
//  │  Animals with no weight record → blocked (grey, tooltip)    │
//  │                                                             │
//  │ MODE B — INDIVIDUAL (one line per animal)                   │
//  │  Same as original behaviour — manual price per line.        │
//  └─────────────────────────────────────────────────────────────┘
//
//  TWO SALE TYPES (above animal list):
//  • live     → status=active,      weight from weight_records
//  • carcass  → status=slaughtered, weight from slaughter_records.deadweight_kg
//
//  Line total: per_kg → price_per_kg × qty_kg  |  per_head → price_per_head
//  Subtotal   = Σ line totals
//  Total      = Subtotal − Discount
// ══════════════════════════════════════════════════════════════════
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'

const { locale: lang, isRtl, n, today } = useLocale()

const props = defineProps({
	sale: { type: Object, default: null },
	farms: { type: Array, default: () => [] },
	customers: { type: Array, default: () => [] },
	mode: { type: String, default: 'create' },
})

// ─────────────────────────────────────────────────────────────────
//  ENTRY MODE & SALE TYPE
// ─────────────────────────────────────────────────────────────────
// entryMode: 'batch' | 'individual'
// In edit mode: if all lines have the same price_per_kg (batch distribution),
// restore as batch; if any line has a distinct animal with different pricing, use individual.
const entryMode = ref(
	props.sale?.lines?.length
		? 'individual'   // always restore edit as individual — safest, all data is present
		: 'batch'
)

// saleType: 'live' | 'carcass'
// Detect from the first line's slaughter_id presence
const saleType = ref(
	props.sale?.lines?.length && props.sale.lines[0].slaughter_id
		? 'carcass'
		: 'live'
)

// ─────────────────────────────────────────────────────────────────
//  ANIMAL LIST (loaded on farm change)
// ─────────────────────────────────────────────────────────────────
const farmAnimals = ref([])   // full list from API
const loadingAnimals = ref(false)
const animalLoadError = ref('')

// QA-020: only the newest answer may fill the animal list (a slow answer for the previous farm
// or sale type must not overwrite it)
let animalsReq = 0
async function loadAnimals() {
	const myReq = ++animalsReq
	const farmId = form.farm_id
	if (!farmId) { farmAnimals.value = []; return }

	loadingAnimals.value = true
	animalLoadError.value = ''

	try {
		// ── FIX 1: AnimalSoftLock — pass exclude_sale_id in edit mode ──
		// The controller will include animals that are 'reserved' by THIS sale
		// so they still appear in the selector during editing.
		// Animals reserved by OTHER sales remain hidden.
		let url = route('sales.animals') + `?farm_id=${farmId}&sale_type=${saleType.value}`
		if (props.mode === 'edit' && props.sale?.id) {
			url += `&exclude_sale_id=${props.sale.id}`
		}

		const res = await fetch(url, {
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin',
		})
		if (!res.ok) throw new Error(res.statusText)
		const fetched = await res.json()
		if (myReq !== animalsReq) return

		// ── EDIT MODE: merge saved-line animals that may no longer appear
		// in the API list (e.g. animal status changed after the sale was saved).
		// This ensures every line's animal_id has a matching entry in farmAnimals
		// so the <select> can display the correct pre-selected option.
		if (props.sale?.lines?.length) {
			const fetchedIds = new Set(fetched.map(a => a.id))
			for (const line of props.sale.lines) {
				if (line.animal_id && !fetchedIds.has(line.animal_id)) {
					fetched.push({
						id: line.animal_id,
						tag: line.animal_tag ?? `#${line.animal_id}`,
						breed: line.animal_breed ?? null,
						sex: line.animal_sex ?? null,
						lot_id: line.animal_lot_id ?? null,
						weight_kg: line.weight_kg ?? line.qty_kg ?? null,
						has_weight: line.weight_kg != null || line.qty_kg != null,
						slaughter_id: line.slaughter_id ?? null,
						sale_type: line.sale_type ?? saleType.value,
						status: 'reserved',  // known to be reserved by this sale
					})
				}
			}
		}

		farmAnimals.value = fetched
	} catch (e) {
		if (myReq !== animalsReq) return
		farmAnimals.value = []
		animalLoadError.value = lang.value === 'ar' ? 'تعذر تحميل الماشيةات' : 'Failed to load animals'
	} finally {
		if (myReq === animalsReq) loadingAnimals.value = false
	}
}

// ─────────────────────────────────────────────────────────────────
//  BATCH MODE STATE
// ─────────────────────────────────────────────────────────────────
// Which animal IDs are checked in batch mode
const selectedIds = ref([])

// Batch pricing sub-mode: 'total' | 'per_kg'
// 'total'  → user types one total selling amount, system derives P/kg
// 'per_kg' → user types P/kg directly, system multiplies by each w_i
const batchPricingMode = ref('total')

// The two inputs for batch mode
const batchTotalAmount = ref('')    // used when batchPricingMode === 'total'
const batchPricePerKg = ref('')    // used when batchPricingMode === 'per_kg'

// ─────────────────────────────────────────────────────────────────
//  FORM (individual mode lines + header fields)
// ─────────────────────────────────────────────────────────────────
const form = useForm({
	farm_id: props.sale?.farm_id ? Number(props.sale.farm_id) : '',
	customer_id: props.sale?.customer_id ? Number(props.sale.customer_id) : '',
	date: props.sale?.date ?? localToday(),
	invoice_ref: props.sale?.invoice_ref ?? '',
	payment_terms_days: props.sale?.payment_terms_days ?? 30,
	due_date: props.sale?.due_date ?? '',
	discount: props.sale?.discount ?? '0',
	currency: props.sale?.currency ?? 'EGP',
	notes: props.sale?.notes ?? '',
	lines: props.sale?.lines?.length
		? props.sale.lines.map(l => ({
			animal_id: l.animal_id ? Number(l.animal_id) : '',
			slaughter_id: l.slaughter_id ? Number(l.slaughter_id) : '',
			// pricing_mode: prefer per_head when price_per_head is set;
			// per_kg only when price_per_kg is set but price_per_head is not
			pricing_mode: (l.price_per_head != null && l.price_per_head > 0) ? 'per_head'
				: (l.price_per_kg != null && l.price_per_kg > 0) ? 'per_kg'
					: 'per_head',
			price_per_kg: l.price_per_kg != null ? String(l.price_per_kg) : '',
			price_per_head: l.price_per_head != null ? String(l.price_per_head) : '',
			qty_kg: l.qty_kg != null ? String(l.qty_kg) : '',
			notes: l.notes ?? '',
		}))
		: [emptyLine()],
})

function emptyLine() {
	return {
		animal_id: '', slaughter_id: '', pricing_mode: 'per_head',
		price_per_kg: '', price_per_head: '', qty_kg: '', notes: ''
	}
}

// ── Farm change watcher ───────────────────────────────────────────
// immediate:true fires on mount → edit mode loads animals immediately.
// isFirstFarmWatch guards against wiping form.lines on that first fire
// (lines hold the saved data needed for the merge inside loadAnimals).
let isFirstFarmWatch = true

watch(() => form.farm_id, () => {
	if (isFirstFarmWatch) {
		isFirstFarmWatch = false
		// First fire — do NOT clear lines, just fetch.
		// loadAnimals() will merge saved-line animals into the result.
		if (form.farm_id) loadAnimals()
		return
	}
	// User changed the farm selector — safe to wipe and reload
	farmAnimals.value = []
	selectedIds.value = []
	form.lines.forEach(l => { l.animal_id = ''; l.slaughter_id = '' })
	if (form.farm_id) loadAnimals()
}, { immediate: true })

// ── Sale type change watcher ──────────────────────────────────────
let isFirstSaleTypeWatch = true

watch(saleType, () => {
	if (isFirstSaleTypeWatch) {
		isFirstSaleTypeWatch = false
		return   // initial value already correct; farm watch handles first load
	}
	// User toggled live / carcass — wipe and reload
	farmAnimals.value = []
	selectedIds.value = []
	form.lines.forEach(l => { l.animal_id = ''; l.slaughter_id = '' })
	batchTotalAmount.value = ''
	batchPricePerKg.value = ''
	if (form.farm_id) loadAnimals()
})

// ── Auto-compute due_date ─────────────────────────────────────────
// QA-019: add days using the LOCAL calendar (not UTC), accept 0 days, and never overwrite the
// saved due date just because an existing sale was opened for editing.
function dueDateFrom(date, terms) {
	const days = parseInt(terms)
	if (!date || Number.isNaN(days)) return ''
	const [y, m, dd] = String(date).slice(0, 10).split('-').map(Number)
	const d = new Date(y, m - 1, dd + days)
	return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
}
if (!form.due_date) form.due_date = dueDateFrom(form.date, form.payment_terms_days)
watch([() => form.date, () => form.payment_terms_days], ([date, terms]) => {
	const due = dueDateFrom(date, terms)
	if (due) form.due_date = due
})

// ─────────────────────────────────────────────────────────────────
//  BATCH COMPUTATIONS
//  📌 METHODOLOGY REFERENCE: BatchPriceDistribution (ROIperLot #20)
// ─────────────────────────────────────────────────────────────────

// Animals that are currently checked and have a weight
const selectedAnimals = computed(() =>
	farmAnimals.value.filter(a => selectedIds.value.includes(a.id) && a.has_weight)
)

// Total weight of selected animals — Σ w_i
const selectedTotalWeight = computed(() =>
	selectedAnimals.value.reduce((s, a) => s + (a.weight_kg ?? 0), 0)
)

// 📌 BatchPriceDistribution — derive P/kg from total OR use direct input
// P_kg = T / Σw_i  (total-amount path)
// P_kg = user input (per-kg path)
const batchDerivedPricePerKg = computed(() => {
	if (batchPricingMode.value === 'per_kg') {
		return parseFloat(batchPricePerKg.value) || 0
	}
	// total path
	const T = parseFloat(batchTotalAmount.value) || 0
	const W = selectedTotalWeight.value
	if (T > 0 && W > 0) return T / W
	return 0
})

// Per-head price for each animal: P_head_i = P_kg × w_i
function batchHeadPrice(animal) {
	const pkg = batchDerivedPricePerKg.value
	if (!pkg || !animal.weight_kg) return 0
	return pkg * animal.weight_kg
}

// Total for batch sale
const batchTotal = computed(() => {
	if (batchPricingMode.value === 'total') {
		return r2(parseFloat(batchTotalAmount.value) || 0)
	}
	// per_kg path: Σ round(P_kg × w_i)
	return r2(selectedAnimals.value.reduce((s, a) => s + r2(batchHeadPrice(a)), 0))
})

// ─────────────────────────────────────────────────────────────────
//  INDIVIDUAL MODE — LINE HELPERS
// ─────────────────────────────────────────────────────────────────
function addLine() {
	form.lines.push(emptyLine())
}
function removeLine(idx) {
	if (form.lines.length > 1) form.lines.splice(idx, 1)
}

// When an animal is selected in individual mode, auto-fill slaughter_id + qty_kg
function onAnimalSelected(line) {
	const animal = farmAnimals.value.find(a => String(a.id) === String(line.animal_id))
	if (!animal) return
	line.slaughter_id = animal.slaughter_id ?? ''
	if (animal.weight_kg && !line.qty_kg) {
		line.qty_kg = animal.weight_kg
	}
}

// QA-017: round to cents (half up) EXACTLY like the server does, line by line
const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100

// 📌 METHODOLOGY REFERENCE: ROIperLot — individual line total
function lineTotal(line) {
	if (line.pricing_mode === 'per_kg') {
		const p = parseFloat(line.price_per_kg)
		const q = parseFloat(line.qty_kg)
		if (p > 0 && q > 0) return r2(p * q)
	} else {
		const p = parseFloat(line.price_per_head)
		if (p > 0) return r2(p)
	}
	return 0
}

// subtotal = Σ of the (already rounded) line totals — same as the server
const individualSubtotal = computed(() =>
	r2(form.lines.reduce((s, l) => s + lineTotal(l), 0))
)

// ─────────────────────────────────────────────────────────────────
//  SHARED TOTALS (both modes)
// ─────────────────────────────────────────────────────────────────
const subtotal = computed(() =>
	entryMode.value === 'batch' ? batchTotal.value : individualSubtotal.value
)
const discountAmt = computed(() => parseFloat(form.discount) || 0)
const totalAmount = computed(() => Math.max(0, subtotal.value - discountAmt.value))

// ─────────────────────────────────────────────────────────────────
//  BATCH → LINES CONVERSION (before submit)
//  Explodes the batch selection into individual sales_line rows
//  so the controller receives the same lines[] structure regardless of mode.
// ─────────────────────────────────────────────────────────────────
function buildLinesFromBatch() {
	const pkg = batchDerivedPricePerKg.value
	const rows = selectedAnimals.value.map(animal => ({
		animal_id: animal.id,
		slaughter_id: animal.slaughter_id ?? '',
		pricing_mode: 'per_head',
		price_per_kg: Number(pkg.toFixed(4)),
		price_per_head: r2(batchHeadPrice(animal)),
		qty_kg: animal.weight_kg,
		notes: '',
	}))
	// QA-017: when the user typed a TOTAL, the rounded per-head prices can be a cent or two off it.
	// Put the difference on the last animal so the lines add up to exactly the typed total.
	if (batchPricingMode.value === 'total' && rows.length) {
		const diff = r2(batchTotal.value - rows.reduce((s, r) => s + r.price_per_head, 0))
		rows[rows.length - 1].price_per_head = r2(rows[rows.length - 1].price_per_head + diff)
	}
	return rows
}

// ─────────────────────────────────────────────────────────────────
//  SUBMIT
// ─────────────────────────────────────────────────────────────────
const submitError = ref('')

function submit() {
	submitError.value = ''

	// Build final lines array
	let lines
	if (entryMode.value === 'batch') {
		if (selectedAnimals.value.length === 0) {
			submitError.value = lang.value === 'ar'
				? 'يرجى اختيار ماشية واحد على الأقل'
				: 'Please select at least one animal'
			return
		}
		if (batchDerivedPricePerKg.value <= 0) {
			submitError.value = lang.value === 'ar'
				? 'يرجى إدخال السعر أو إجمالي المبلغ'
				: 'Please enter a price or total amount'
			return
		}
		lines = buildLinesFromBatch()
	} else {
		lines = form.lines
	}

	const data = {
		...form.data(),
		lines,
		subtotal: subtotal.value.toFixed(2),
		total_amount: totalAmount.value.toFixed(2),
	}

	if (props.mode === 'create') {
		form.transform(() => data).post(route('sales.store'), { onError: () => { } })
	} else {
		form.transform(() => data).put(route('sales.update', props.sale.id), { onError: () => { } })
	}
}

// ─────────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────────
const fmt = (num) => Number(num).toLocaleString(
	lang.value === 'ar' ? 'ar-EG' : 'en-US',
	{ minimumFractionDigits: 2, maximumFractionDigits: 2 }
)
const fmtWt = (kg) => kg != null
	? (lang.value === 'ar' ? `${Number(kg).toLocaleString('ar-EG', { maximumFractionDigits: 1 })} كجم` : `${Number(kg).toFixed(1)} kg`)
	: (lang.value === 'ar' ? 'لا يوجد وزن' : 'No weight')

const selectedCustomer = computed(() => props.customers.find(c => String(c.id) === String(form.customer_id)))
const selectedFarm = computed(() => props.farms.find(f => String(f.id) === String(form.farm_id)))
const isEdit = computed(() => props.mode === 'edit')

function toggleAnimal(id, hasWeight) {
	if (!hasWeight) return
	const idx = selectedIds.value.indexOf(id)
	if (idx === -1) selectedIds.value.push(id)
	else selectedIds.value.splice(idx, 1)
}

function toggleAllAnimals() {
	const eligible = farmAnimals.value.filter(a => a.has_weight).map(a => a.id)
	if (selectedIds.value.length === eligible.length) {
		selectedIds.value = []
	} else {
		selectedIds.value = [...eligible]
	}
}

const allEligibleSelected = computed(() => {
	const eligible = farmAnimals.value.filter(a => a.has_weight)
	return eligible.length > 0 && eligible.every(a => selectedIds.value.includes(a.id))
})
</script>
<template>
	<AuthenticatedLayout>
		<div :dir="isRtl ? 'rtl' : 'ltr'">
			<!-- ── Hero ───────────────────────────────────────────── -->
			<div class="cv-page-hero">
				<div class="cv-page-hero-eyebrow">{{ lang === 'ar' ? 'الإيرادات' : 'Revenue' }}</div>
				<div class="cv-page-hero-title">
					{{ isEdit
						? (lang === 'ar' ? 'تعديل سجل البيع' : 'Edit Sale Record')
						: (lang === 'ar' ? 'تسجيل عملية بيع جديدة' : 'New Sale Record') }}
				</div>
				<div class="cv-page-hero-sub" style="color:var(--cv-amber-text)">
					{{ selectedCustomer ? n(selectedCustomer, 'name') : (lang === 'ar' ? 'اختر العميل أدناه' : 'Select a customer below') }}
					<span v-if="selectedFarm"> — {{ n(selectedFarm, 'name') }}</span>
				</div>
				<div class="cv-page-hero-actions">
					<span v-if="totalAmount > 0" class="text-2xl font-bold" style="color:var(--cv-amber-text)">
						{{ fmt(totalAmount) }} {{ lang === 'ar' ? 'ج.م' : 'EGP' }}
					</span>
				</div>
			</div>
			<div class="cv-page">
				<form @submit.prevent="submit" class="flex flex-col gap-5 max-w-4xl">
					<!-- ── Section 1: Invoice Header ──────────────── -->
					<div class="cv-card p-5">
						<div class="cv-section-title mb-4 flex items-center gap-2">
							<span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
								style="background:var(--cv-amber-light);color:var(--cv-amber);border:1px solid var(--cv-amber-border)">
								{{ lang === 'ar' ? '١' : '1' }}
							</span>
							{{ lang === 'ar' ? 'بيانات الفاتورة' : 'Invoice Details' }}
						</div>
						<div class="grid grid-cols-2 gap-4">
							<div>
								<label class="cv-label">{{ lang === 'ar' ? 'المزرعة' : 'Farm' }} <span
										style="color:var(--cv-danger)">*</span></label>
								<select v-model="form.farm_id" class="cv-select"
									:class="{ 'cv-input-error': form.errors.farm_id }">
									<option value="">{{ lang === 'ar' ? 'اختر المزرعة' : 'Select farm' }}</option>
									<option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
								</select>
								<div v-if="form.errors.farm_id" class="cv-error">{{ form.errors.farm_id }}</div>
							</div>
							<div>
								<label class="cv-label">{{ lang === 'ar' ? 'العميل' : 'Customer' }} <span
										style="color:var(--cv-danger)">*</span></label>
								<select v-model="form.customer_id" class="cv-select"
									:class="{ 'cv-input-error': form.errors.customer_id }">
									<option value="">{{ lang === 'ar' ? 'اختر العميل' : 'Select customer' }}</option>
									<option v-for="c in customers" :key="c.id" :value="c.id">{{ n(c, 'name') }}</option>
								</select>
								<div v-if="form.errors.customer_id" class="cv-error">{{ form.errors.customer_id }}</div>
							</div>
							<div>
								<label class="cv-label">{{ lang === 'ar' ? 'تاريخ البيع' : 'Sale Date' }} <span
										style="color:var(--cv-danger)">*</span></label>
								<input v-model="form.date" type="date" class="cv-input" :max="today"
									:class="{ 'cv-input-error': form.errors.date }" />
								<div v-if="form.errors.date" class="cv-error">{{ form.errors.date }}</div>
							</div>
							<!-- <div>
                                <label class="cv-label">{{ lang === 'ar' ? 'رقم الفاتورة' : 'Invoice Reference' }}</label>
                                <input v-model="form.invoice_ref" type="text" class="cv-input"
                                       :placeholder="lang === 'ar' ? 'رقم أو مرجع الفاتورة' : 'Invoice number or ref'"/>
                            </div> -->
							<div>
								<label
									class="cv-label">{{ lang === 'ar' ? 'شروط الدفع (أيام)' : 'Payment Terms (days)' }}</label>
								<input v-model="form.payment_terms_days" type="number" min="0" class="cv-input" />
							</div>
							<div>
								<label class="cv-label">{{ lang === 'ar' ? 'تاريخ الاستحقاق' : 'Due Date' }}</label>
								<input v-model="form.due_date" type="date" class="cv-input" />
								<div class="text-xs mt-1" style="color:var(--cv-text-muted)">
									{{ lang === 'ar' ? 'يُحسب تلقائياً من شروط الدفع' : 'Auto-calculated from payment terms' }}
								</div>
							</div>
						</div>
					</div>
					<!-- ── Section 2: Sale Lines ───────────────────── -->
					<div class="cv-card p-5">
						<div class="cv-section-title mb-4 flex items-center gap-2">
							<span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
								style="background:var(--cv-blue-light);color:var(--cv-blue-text);border:1px solid var(--cv-blue-border)">
								{{ lang === 'ar' ? '٢' : '2' }}
							</span>
							{{ lang === 'ar' ? 'بنود البيع' : 'Sale Lines' }}
						</div>
						<!-- ── Sale Type Toggle (live / carcass) ─────── -->
						<div class="mb-4">
							<label class="cv-label mb-2">{{ lang === 'ar' ? 'نوع البيع' : 'Sale Type' }}</label>
							<div class="flex gap-2">
								<button type="button" class="cv-btn flex-1 flex items-center justify-center gap-2"
									:class="saleType === 'live' ? 'cv-btn-amber' : 'cv-btn-ghost'"
									@click="saleType = 'live'">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
										width="15" height="15">
										<ellipse cx="12" cy="14" rx="6" ry="4" />
										<path d="M9 10c0-1.5.5-3 3-4 2.5 1 3 2.5 3 4" />
										<path d="M7 10c-2 .5-3 1.5-3 3" />
										<path d="M17 10c2 .5 3 1.5 3 3" />
									</svg>
									{{ lang === 'ar' ? 'ماشية حي' : 'Live Animal' }}
								</button>
								<button type="button" class="cv-btn flex-1 flex items-center justify-center gap-2"
									:class="saleType === 'carcass' ? 'cv-btn-amber' : 'cv-btn-ghost'"
									@click="saleType = 'carcass'">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
										width="15" height="15">
										<rect x="4" y="8" width="16" height="10" rx="1" />
										<path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
										<line x1="12" y1="12" x2="12" y2="15" />
										<line x1="9" y1="13" x2="15" y2="13" />
									</svg>
									{{ lang === 'ar' ? 'ذبيحة / لحم' : 'Carcass / Meat' }}
								</button>
							</div>
							<p class="text-xs mt-2" style="color:var(--cv-text-muted)">
								<template v-if="saleType === 'live'">
									{{ lang === 'ar'
										? 'يعرض الماشيةات النشطة — يُستخدم آخر وزن حي لكل ماشية'
										: 'Shows active animals — uses the latest live weight record for each animal' }}
								</template>
								<template v-else>
									{{ lang === 'ar'
										? 'يعرض الماشيةات المذبوحة ذات سجل ذبح — يُستخدم وزن الذبيحة (deadweight)'
										: 'Shows slaughtered animals with a slaughter record — uses deadweight from the slaughter record' }}
								</template>
							</p>
						</div>
						<!-- ── Entry Mode Toggle (batch / individual) ── -->
						<div class="mb-5">
							<label class="cv-label mb-2">{{ lang === 'ar' ? 'طريقة الإدخال' : 'Entry Method' }}</label>
							<div class="flex gap-2">
								<button type="button" class="cv-btn flex-1 flex items-center justify-center gap-2"
									:class="entryMode === 'batch' ? 'cv-btn-amber' : 'cv-btn-ghost'"
									@click="entryMode = 'batch'">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
										width="15" height="15">
										<rect x="2" y="5" width="4" height="4" rx="0.5" />
										<rect x="2" y="11" width="4" height="4" rx="0.5" />
										<rect x="2" y="17" width="4" height="4" rx="0.5" />
										<line x1="9" y1="7" x2="22" y2="7" />
										<line x1="9" y1="13" x2="22" y2="13" />
										<line x1="9" y1="19" x2="22" y2="19" />
									</svg>
									{{ lang === 'ar' ? 'اختيار متعدد (دفعة)' : 'Multi-Select (Batch)' }}
								</button>
								<button type="button" class="cv-btn flex-1 flex items-center justify-center gap-2"
									:class="entryMode === 'individual' ? 'cv-btn-amber' : 'cv-btn-ghost'"
									@click="entryMode = 'individual'">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
										width="15" height="15">
										<line x1="8" y1="6" x2="21" y2="6" />
										<line x1="8" y1="12" x2="21" y2="12" />
										<line x1="8" y1="18" x2="21" y2="18" />
										<line x1="3" y1="6" x2="3.01" y2="6" />
										<line x1="3" y1="12" x2="3.01" y2="12" />
										<line x1="3" y1="18" x2="3.01" y2="18" />
									</svg>
									{{ lang === 'ar' ? 'إدخال فردي' : 'Individual Lines' }}
								</button>
							</div>
						</div>
						<!-- ════════════════════════════════════════════
                             BATCH MODE
                        ════════════════════════════════════════════ -->
						<div v-if="entryMode === 'batch'">
							<!-- No farm selected -->
							<div v-if="!form.farm_id" class="text-center py-8" style="color:var(--cv-text-muted)">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="36"
									height="36" class="mx-auto mb-2" style="opacity:0.4">
									<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
									<polyline points="9 22 9 12 15 12 15 22" />
								</svg>
								{{ lang === 'ar' ? 'اختر المزرعة أولاً لعرض الماشيةات' : 'Select a farm first to load animals' }}
							</div>
							<!-- Loading -->
							<div v-else-if="loadingAnimals" class="text-center py-8" style="color:var(--cv-text-muted)">
								<div class="inline-block w-6 h-6 rounded-full border-2 border-t-transparent animate-spin mb-2"
									style="border-color:var(--cv-amber);border-top-color:transparent"></div>
								<div>{{ lang === 'ar' ? 'جاري تحميل الماشيةات…' : 'Loading animals…' }}</div>
							</div>
							<!-- Error -->
							<div v-else-if="animalLoadError" class="cv-error py-3">{{ animalLoadError }}</div>
							<!-- Empty list -->
							<div v-else-if="farmAnimals.length === 0" class="text-center py-8 rounded-lg"
								style="background:var(--cv-bg-card-alt);border:1px dashed var(--cv-border-md)">
								<p style="color:var(--cv-text-muted)">
									{{ saleType === 'live'
										? (lang === 'ar' ? 'لا توجد ماشيةات نشطة في هذه المزرعة' : 'No active animals in this farm')
										: (lang === 'ar' ? 'لا توجد ذبائح مسجلة في هذه المزرعة' : 'No slaughter records found for this farm') }}
								</p>
							</div>
							<!-- Animal Checklist -->
							<div v-else>
								<!-- Select All + summary bar -->
								<div class="flex items-center justify-between mb-3 pb-3"
									style="border-bottom:1px solid var(--cv-border-md)">
									<label class="flex items-center gap-2 cursor-pointer select-none">
										<input type="checkbox" class="w-4 h-4 rounded"
											style="accent-color:var(--cv-amber)" :checked="allEligibleSelected"
											@change="toggleAllAnimals" />
										<span class="text-sm font-medium" style="color:var(--cv-text-secondary)">
											{{ lang === 'ar' ? 'تحديد الكل' : 'Select All' }}
										</span>
									</label>
									<div class="text-sm" style="color:var(--cv-blue-text)">
										{{ selectedAnimals.length }}
										{{ lang === 'ar' ? 'ماشية مختار ·' : 'selected ·' }}
										{{ fmtWt(selectedTotalWeight) }}
										{{ lang === 'ar' ? 'إجمالي' : 'total' }}
									</div>
								</div>
								<!-- Animal rows -->
								<div class="flex flex-col gap-2 mb-5" style="max-height:340px;overflow-y:auto">
									<div v-for="animal in farmAnimals" :key="animal.id" :class="[
										'flex items-center gap-3 px-3 py-2 rounded-lg transition-colors',
										animal.has_weight ? 'cursor-pointer' : 'cursor-not-allowed',
										selectedIds.includes(animal.id)
											? 'cv-bg-card-alt'
											: 'cv-bg-hover',
									]" :style="selectedIds.includes(animal.id)
											? 'border:1px solid var(--cv-amber-border);background:var(--cv-amber-light)'
											: 'border:1px solid var(--cv-border-md)'"
										@click="toggleAnimal(animal.id, animal.has_weight)">
										<!-- Checkbox -->
										<input type="checkbox" class="w-4 h-4 rounded flex-shrink-0"
											style="accent-color:var(--cv-amber)"
											:checked="selectedIds.includes(animal.id)" :disabled="!animal.has_weight"
											@click.stop @change="toggleAnimal(animal.id, animal.has_weight)" />
										<!-- Tag + breed -->
										<div class="flex-1 min-w-0">
											<div class="font-semibold text-sm" style="color:var(--cv-text-primary)">
												{{ animal.tag }}
												<span v-if="animal.grade" class="text-xs ms-1"
													style="color:var(--cv-amber-text)">({{ animal.grade }})</span>
												<!-- FIX 1: Reserved badge — shown for animals held by THIS draft sale -->
												<span v-if="animal.status === 'reserved'"
													class="text-xs ms-1 px-1.5 py-0.5 rounded"
													style="background:var(--cv-amber-bg);color:var(--cv-amber-text)">
													{{ lang === 'ar' ? 'محجوز لهذه الصفقة' : 'reserved for this sale' }}
												</span>
											</div>
											<div class="text-xs" style="color:var(--cv-text-muted)">
												{{ animal.breed ?? '—' }} · {{ animal.sex }}
												<span v-if="animal.dressing_pct" class="ms-2">
													{{ lang === 'ar' ? 'نسبة التصافي:' : 'Dress:' }}
													{{ animal.dressing_pct }}% </span>
											</div>
										</div>
										<!-- Weight -->
										<div class="text-end flex-shrink-0">
											<template v-if="animal.has_weight">
												<div class="text-sm font-mono font-semibold"
													style="color:var(--cv-blue-text)">
													{{ fmtWt(animal.weight_kg) }}
												</div>
												<div class="text-xs" style="color:var(--cv-text-muted)">
													{{ saleType === 'carcass'
														? (lang === 'ar' ? 'وزن الذبيحة' : 'deadweight')
														: (lang === 'ar' ? 'آخر وزن' : 'latest') }}
												</div>
											</template>
											<template v-else>
												<span class="text-xs px-2 py-0.5 rounded"
													style="background:var(--cv-danger-bg);color:var(--cv-danger-text)">
													{{ lang === 'ar' ? 'لا يوجد وزن' : 'No weight' }}
												</span>
											</template>
										</div>
										<!-- Per-head computed price (shows after price is set) -->
										<div v-if="selectedIds.includes(animal.id) && batchDerivedPricePerKg > 0"
											class="text-end flex-shrink-0 ms-2" style="min-width:90px">
											<div class="text-sm font-mono font-semibold"
												style="color:var(--cv-amber-text)">
												{{ fmt(batchHeadPrice(animal)) }}
											</div>
											<div class="text-xs" style="color:var(--cv-text-muted)">
												{{ lang === 'ar' ? 'ج.م / رأس' : 'EGP/head' }}
											</div>
										</div>
									</div>
								</div>
								<!-- Batch Pricing Panel -->
								<div v-if="selectedAnimals.length > 0" class="rounded-xl p-4 mt-2"
									style="background:var(--cv-bg-card-alt);border:1px solid var(--cv-amber-border)">
									<div class="text-sm font-semibold mb-3" style="color:var(--cv-amber-text)"> 📌
										{{ lang === 'ar' ? 'توزيع السعر التلقائي (BatchPriceDistribution)' : 'Automatic Price Distribution (BatchPriceDistribution)' }}
									</div>
									<!-- Pricing sub-mode toggle -->
									<div class="flex gap-2 mb-4">
										<button type="button" class="cv-btn flex-1 text-sm"
											:class="batchPricingMode === 'total' ? 'cv-btn-amber' : 'cv-btn-ghost'"
											@click="batchPricingMode = 'total'">
											{{ lang === 'ar' ? 'إجمالي المبلغ → سعر/كجم' : 'Enter Total → derive $/kg' }}
										</button>
										<button type="button" class="cv-btn flex-1 text-sm"
											:class="batchPricingMode === 'per_kg' ? 'cv-btn-amber' : 'cv-btn-ghost'"
											@click="batchPricingMode = 'per_kg'">
											{{ lang === 'ar' ? 'سعر/كجم → إجمالي' : 'Enter $/kg → derive Total' }}
										</button>
									</div>
									<!-- Total-amount path -->
									<div v-if="batchPricingMode === 'total'">
										<label class="cv-label">
											{{ lang === 'ar' ? 'إجمالي مبلغ البيع (ج.م)' : 'Total Selling Amount (EGP)' }}
											<span style="color:var(--cv-danger)">*</span>
										</label>
										<input v-model="batchTotalAmount" type="number" step="0.01" min="0"
											class="cv-input" placeholder="0.00" />
										<p class="text-xs mt-1" style="color:var(--cv-text-muted)">
											{{ lang === 'ar'
												? `سعر/كجم = الإجمالي ÷ ${fmtWt(selectedTotalWeight)} = ${batchDerivedPricePerKg > 0 ? fmt(batchDerivedPricePerKg) + ' ج.م/كجم' : '—'}`
												: `P/kg = Total ÷ ${fmtWt(selectedTotalWeight)} = ${batchDerivedPricePerKg > 0 ? fmt(batchDerivedPricePerKg) + ' EGP/kg' : '—'}` }}
										</p>
									</div>
									<!-- Per-kg path -->
									<div v-else>
										<label class="cv-label">
											{{ lang === 'ar' ? 'سعر الكيلو جرام (ج.م/كجم)' : 'Price per Kilogram (EGP/kg)' }}
											<span style="color:var(--cv-danger)">*</span>
										</label>
										<input v-model="batchPricePerKg" type="number" step="0.0001" min="0"
											class="cv-input" placeholder="0.00" />
										<p class="text-xs mt-1" style="color:var(--cv-text-muted)">
											{{ lang === 'ar'
												? `الإجمالي = ${fmt(batchDerivedPricePerKg)} × ${fmtWt(selectedTotalWeight)} = ${fmt(batchTotal)}`
												: `Total = ${fmt(batchDerivedPricePerKg)} × ${fmtWt(selectedTotalWeight)} = ${fmt(batchTotal)} EGP` }}
										</p>
									</div>
									<!-- Summary row -->
									<div v-if="batchDerivedPricePerKg > 0" class="flex flex-wrap gap-4 mt-4 pt-3"
										style="border-top:1px solid var(--cv-amber-border)">
										<div>
											<div class="text-xs" style="color:var(--cv-text-muted)">
												{{ lang === 'ar' ? 'عدد الماشيةات' : 'Head count' }}
											</div>
											<div class="font-mono font-bold" style="color:var(--cv-blue-text)">
												{{ selectedAnimals.length }}
											</div>
										</div>
										<div>
											<div class="text-xs" style="color:var(--cv-text-muted)">
												{{ lang === 'ar' ? 'الوزن الإجمالي' : 'Total weight' }}
											</div>
											<div class="font-mono font-bold" style="color:var(--cv-blue-text)">
												{{ fmtWt(selectedTotalWeight) }}
											</div>
										</div>
										<div>
											<div class="text-xs" style="color:var(--cv-text-muted)">
												{{ lang === 'ar' ? 'سعر/كجم' : 'Price/kg' }}
											</div>
											<div class="font-mono font-bold" style="color:var(--cv-amber-text)">
												{{ fmt(batchDerivedPricePerKg) }}
											</div>
										</div>
										<div class="ms-auto">
											<div class="text-xs" style="color:var(--cv-text-muted)">
												{{ lang === 'ar' ? 'إجمالي البيع' : 'Batch total' }}
											</div>
											<div class="font-mono font-bold text-lg"
												style="color:var(--cv-green-bright)">{{ fmt(batchTotal) }}
												{{ lang === 'ar' ? 'ج.م' : 'EGP' }}
											</div>
										</div>
									</div>
								</div>
								<!-- Prompt to select animals first -->
								<div v-else-if="farmAnimals.filter(a => a.has_weight).length > 0"
									class="text-center py-4 rounded-lg"
									style="border:1px dashed var(--cv-amber-border);color:var(--cv-text-muted)">
									{{ lang === 'ar' ? 'اختر ماشيةاً واحداً على الأقل لإدخال السعر' : 'Select at least one animal to enter pricing' }}
								</div>
							</div>
						</div>
						<!-- ════════════════════════════════════════════
                             INDIVIDUAL MODE
                        ════════════════════════════════════════════ -->
						<div v-else>
							<div v-if="!form.farm_id" class="text-center py-6" style="color:var(--cv-text-muted)">
								{{ lang === 'ar' ? 'اختر المزرعة أولاً' : 'Select a farm first' }}
							</div>
							<div v-else class="flex flex-col gap-4">
								<div v-for="(line, idx) in form.lines" :key="idx" class="rounded-xl p-4"
									style="background:var(--cv-bg-card-alt);border:1px solid var(--cv-border-md)">
									<!-- Line header -->
									<div class="flex items-center justify-between mb-3">
										<span class="text-sm font-semibold" style="color:var(--cv-text-secondary)">
											{{ lang === 'ar' ? `البند ${idx + 1}` : `Line ${idx + 1}` }}
											<span v-if="lineTotal(line) > 0" class="ms-2 font-mono"
												style="color:var(--cv-blue-text)">
												{{ fmt(lineTotal(line)) }} {{ lang === 'ar' ? 'ج.م' : 'EGP' }}
											</span>
										</span>
										<button v-if="form.lines.length > 1" type="button"
											class="cv-btn cv-btn-ghost py-1 px-2 text-xs"
											style="color:var(--cv-danger-text)" @click="removeLine(idx)">
											{{ lang === 'ar' ? 'حذف' : 'Remove' }}
										</button>
									</div>
									<div class="grid grid-cols-2 gap-3">
										<!-- Animal selector -->
										<div class="col-span-2">
											<label class="cv-label">{{ lang === 'ar' ? 'الماشية' : 'Animal' }}</label>
											<div v-if="loadingAnimals" class="cv-input text-sm"
												style="color:var(--cv-text-muted)">
												{{ lang === 'ar' ? 'جاري التحميل…' : 'Loading…' }}
											</div>
											<select v-else v-model="line.animal_id" class="cv-select"
												:key="'animal-select-' + idx + '-' + farmAnimals.length"
												@change="onAnimalSelected(line)">
												<option value="">{{ lang === 'ar' ? 'اختر الماشية' : 'Select animal' }}
												</option>
												<option v-for="a in farmAnimals" :key="a.id" :value="a.id"
													:disabled="!a.has_weight">
													{{ a.tag }} — {{ a.breed ?? '—' }}
													{{ a.has_weight ? `(${fmtWt(a.weight_kg)})` : `(${lang === 'ar' ? 'لا يوجد وزن' : 'no weight'})` }}
													{{ a.status === 'reserved' ? (lang === 'ar' ? ' ★ محجوز' : ' ★ reserved') : '' }}
												</option>
											</select>
										</div>
										<!-- Pricing mode toggle -->
										<div class="col-span-2">
											<label
												class="cv-label">{{ lang === 'ar' ? 'طريقة التسعير' : 'Pricing Method' }}</label>
											<div class="flex gap-2">
												<button type="button" class="cv-btn flex-1"
													:class="line.pricing_mode === 'per_head' ? 'cv-btn-amber' : 'cv-btn-ghost'"
													@click="line.pricing_mode = 'per_head'">
													{{ lang === 'ar' ? 'بالرأس' : 'Per Head' }}
												</button>
												<button type="button" class="cv-btn flex-1"
													:class="line.pricing_mode === 'per_kg' ? 'cv-btn-amber' : 'cv-btn-ghost'"
													@click="line.pricing_mode = 'per_kg'">
													{{ lang === 'ar' ? 'بالكيلو' : 'Per Kg' }}
												</button>
											</div>
										</div>
										<!-- Per head -->
										<div v-if="line.pricing_mode === 'per_head'" class="col-span-2">
											<label
												class="cv-label">{{ lang === 'ar' ? 'السعر للرأس (ج.م)' : 'Price per Head (EGP)' }}</label>
											<input v-model="line.price_per_head" type="number" step="0.01" min="0"
												class="cv-input" placeholder="0.00" />
										</div>
										<!-- Per kg -->
										<template v-if="line.pricing_mode === 'per_kg'">
											<div>
												<label
													class="cv-label">{{ lang === 'ar' ? 'سعر/كجم (ج.م)' : 'Price/kg (EGP)' }}</label>
												<input v-model="line.price_per_kg" type="number" step="0.0001" min="0"
													class="cv-input" placeholder="0.00" />
											</div>
											<div>
												<label
													class="cv-label">{{ lang === 'ar' ? 'الوزن (كجم)' : 'Weight (kg)' }}</label>
												<input v-model="line.qty_kg" type="number" step="0.01" min="0"
													class="cv-input" placeholder="0.0" />
											</div>
										</template>
										<!-- Line notes -->
										<div class="col-span-2">
											<label class="cv-label">{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}</label>
											<input v-model="line.notes" type="text" class="cv-input"
												:placeholder="lang === 'ar' ? 'وصف أو ملاحظة…' : 'Description or note…'" />
										</div>
									</div>
								</div>
								<button type="button" class="cv-btn cv-btn-ghost w-full" @click="addLine">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
										width="14" height="14">
										<path stroke-linecap="round" stroke-linejoin="round"
											d="M12 4.5v15m7.5-7.5h-15" />
									</svg>
									{{ lang === 'ar' ? 'إضافة بند آخر' : 'Add Another Line' }}
								</button>
							</div>
							<div v-if="form.errors.lines" class="cv-error mt-2">{{ form.errors.lines }}</div>
						</div>
					</div>
					<!-- ── Section 3: Totals ───────────────────────── -->
					<div class="cv-card p-5">
						<div class="cv-section-title mb-4 flex items-center gap-2">
							<span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
								style="background:var(--cv-green-dim);color:var(--cv-green-bright);border:1px solid var(--cv-green-border)">
								{{ lang === 'ar' ? '٣' : '3' }}
							</span>
							{{ lang === 'ar' ? 'الإجماليات' : 'Totals' }}
						</div>
						<!-- 📌 METHODOLOGY REFERENCE: ROIperLot / BatchPriceDistribution -->
						<div class="max-w-xs ms-auto">
							<div class="flex justify-between items-center py-2 border-b"
								style="border-color:var(--cv-border)">
								<span class="text-sm"
									style="color:var(--cv-text-secondary)">{{ lang === 'ar' ? 'المجموع الفرعي' : 'Subtotal' }}</span>
								<span class="font-mono font-semibold" style="color:var(--cv-blue-text)">
									{{ fmt(subtotal) }} {{ lang === 'ar' ? 'ج.م' : 'EGP' }}
								</span>
							</div>
							<div class="flex justify-between items-center py-2 border-b"
								style="border-color:var(--cv-border)">
								<span class="text-sm"
									style="color:var(--cv-text-secondary)">{{ lang === 'ar' ? 'الخصم' : 'Discount' }}</span>
								<input v-model="form.discount" type="number" step="0.01" min="0"
									class="cv-input text-end" style="width:130px" placeholder="0.00" />
							</div>
							<div class="flex justify-between items-center py-3 rounded-lg mt-2 px-3"
								style="background:var(--cv-amber-light);border:1px solid var(--cv-amber-border)">
								<span class="font-semibold"
									style="color:var(--cv-amber-text)">{{ lang === 'ar' ? 'الإجمالي' : 'Total' }}</span>
								<span class="font-mono font-bold text-lg" style="color:var(--cv-amber-text)">
									{{ fmt(totalAmount) }} {{ lang === 'ar' ? 'ج.م' : 'EGP' }}
								</span>
							</div>
						</div>
					</div>
					<!-- ── Section 4: Notes ───────────────────────── -->
					<div class="cv-card p-5">
						<div class="cv-section-title mb-4 flex items-center gap-2">
							<span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
								style="background:var(--cv-bg-card-alt);color:var(--cv-text-muted);border:1px solid var(--cv-border-md)">
								{{ lang === 'ar' ? '٤' : '4' }}
							</span>
							{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}
						</div>
						<textarea v-model="form.notes" rows="3" class="cv-textarea"
							:placeholder="lang === 'ar' ? 'ملاحظات إضافية على عملية البيع…' : 'Additional notes about this sale…'" />
					</div>
					<!-- ── Submit Row ──────────────────────────────── -->
					<div class="flex justify-between items-center">
						<button type="button" class="cv-btn cv-btn-ghost" @click="router.get(route('sales.index'))">
							{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
						</button>
						<div class="flex gap-3 items-center">
							<div v-if="submitError" class="cv-error">{{ submitError }}</div>
							<div v-if="form.errors.general" class="cv-error">{{ form.errors.general }}</div>
							<button type="submit" class="cv-btn cv-btn-amber" :disabled="form.processing">
								{{ form.processing
									? (lang === 'ar' ? 'جاري الحفظ…' : 'Saving…')
									: isEdit
										? (lang === 'ar' ? 'حفظ التعديلات' : 'Save Changes')
										: (lang === 'ar' ? 'إنشاء سجل البيع' : 'Create Sale Record') }}
							</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</AuthenticatedLayout>
</template>
