<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

// ── Props ─────────────────────────────────────────────────────────
const props = defineProps({
	mode: { type: String, default: 'create' },   // 'create' | 'edit'
	po_type: { type: String, default: 'cattle' },   // 'cattle' | 'inventory'
	procurement: { type: Object, default: null },
	farms: { type: Array, default: () => [] },
	lots: { type: Array, default: () => [] },
	suppliers: { type: Array, default: () => [] },
	companies: { type: Array, default: () => [] },   // Super Admin only
	// ── Inventory PO only ────────────────────────────────────────
	inventoryItems: { type: Array, default: () => [] },   // master items for line dropdowns
	locations: { type: Array, default: () => [] },   // active locations for line dropdowns
})

const { locale: lang, isRtl, n, today } = useLocale()

const isEdit = computed(() => props.mode === 'edit')
// Determine PO type: on edit, read from record; on create, read from prop (passed from ?type=)
const poType = computed(() => props.procurement?.po_type ?? props.po_type ?? 'cattle')
const isCattle = computed(() => poType.value === 'cattle')
const isInventory = computed(() => poType.value === 'inventory')

// Only draft POs can be edited
const isLocked = computed(() => isEdit.value && props.procurement?.po_status !== 'draft')

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
	po_type: poType.value,
	farm_id: props.procurement?.farm_id ?? '',
	supplier_id: props.procurement?.supplier_id ?? '',
	lot_id: props.procurement?.lot_id ?? '',
	date: props.procurement?.date ?? new Date().toISOString().split('T')[0],
	invoice_ref: props.procurement?.invoice_ref ?? '',

	// ── Cattle PO fields (Migration 056) ─────────────────────────
	min_head: props.procurement?.min_head ?? '',
	max_head: props.procurement?.max_head ?? '',
	estimated_price_per_kg: props.procurement?.estimated_price_per_kg ?? '',
	estimated_avg_weight_kg: props.procurement?.estimated_avg_weight_kg ?? '',

	// ── Financial (shared) ────────────────────────────────────────
	freight_cost: props.procurement?.freight_cost ?? 0,
	other_costs: props.procurement?.other_costs ?? 0,
	currency: props.procurement?.currency ?? 'EGP',
	payment_terms_days: props.procurement?.payment_terms_days ?? 30,
	due_date: props.procurement?.due_date ?? '',
	notes: props.procurement?.notes ?? '',

	// ── Cattle breed/sex estimate lines (Section 3, cattle only) ─
	lines: (props.procurement?.lines ?? []).map(l => ({
		breed: l.breed ?? '',
		sex: l.sex ?? '',
		estimated_weight_kg: l.estimated_weight_kg ?? '',
		price_per_kg: l.price_per_kg ?? '',
		freight_allocated: l.freight_allocated ?? '',
		notes: l.notes ?? '',
	})),

	// ── Inventory PO ordered item lines (Section 2, inventory only) ─
	item_lines: (props.procurement?.item_lines ?? []).map(l => ({
		inventory_item_id: l.inventory_item_id ?? '',
		location_id: l.location_id ?? '',
		qty_ordered: l.qty_ordered ?? '',
		unit_cost_estimated: l.unit_cost_estimated ?? '',
		notes: l.notes ?? '',
	})),
})

// ── Cattle: Estimated total range display ─────────────────────────
const estimatedMin = computed(() => {
	const min = Number(form.min_head) || 0
	const wt = Number(form.estimated_avg_weight_kg) || 0
	const price = Number(form.estimated_price_per_kg) || 0
	return min * wt * price
})
const estimatedMax = computed(() => {
	const max = Number(form.max_head) || 0
	const wt = Number(form.estimated_avg_weight_kg) || 0
	const price = Number(form.estimated_price_per_kg) || 0
	return max * wt * price
})

// ── Inventory: Line totals ────────────────────────────────────────
const itemLineTotal = computed(() =>
	form.item_lines.reduce((sum, l) => {
		const qty = Number(l.qty_ordered) || 0
		const cost = Number(l.unit_cost_estimated) || 0
		return sum + qty * cost
	}, 0)
)

// ── Auto-calculate due date ───────────────────────────────────────
watch(() => [form.date, form.payment_terms_days], ([date, days]) => {
	if (date && days) {
		const d = new Date(date)
		d.setDate(d.getDate() + Number(days))
		form.due_date = d.toISOString().split('T')[0]
	}
})

// ── Cattle: min_head guard ────────────────────────────────────────
watch(() => form.min_head, (val) => {
	if (form.max_head && Number(form.max_head) < Number(val)) {
		form.max_head = val
	}
})

// ── Filtered lots by selected farm ───────────────────────────────
const filteredLots = computed(() =>
	form.farm_id ? props.lots.filter(l => l.farm_id === Number(form.farm_id)) : []
)
watch(() => form.farm_id, () => { form.lot_id = '' })

// ── Cattle lines helpers ──────────────────────────────────────────
function addLine() {
	form.lines.push({ breed: '', sex: '', estimated_weight_kg: '', price_per_kg: '', freight_allocated: '', notes: '' })
}
function removeLine(idx) { form.lines.splice(idx, 1) }

// ── Inventory item lines helpers ──────────────────────────────────
function addItemLine() {
	form.item_lines.push({ inventory_item_id: '', location_id: '', qty_ordered: '', unit_cost_estimated: '', notes: '' })
}
function removeItemLine(idx) { form.item_lines.splice(idx, 1) }

// When an item is selected in a line, auto-fill unit_cost from its WAC
function onItemSelect(line) {
	const item = props.inventoryItems.find(i => i.id === Number(line.inventory_item_id))
	if (item) {
		line.unit_cost_estimated = item.unit_cost ?? ''
		// If only one location has stock, auto-select it
		if (item.location_stocks?.length === 1) {
			line.location_id = item.location_stocks[0].location_id
		} else {
			line.location_id = ''
		}
	}
}

// Locations available for a given line's selected item
function locationsForLine(line) {
	return props.locations
}

// ── Submit ────────────────────────────────────────────────────────
function submit() {
	if (isEdit.value) {
		const updateRoute = isInventory.value
			? route('procurement.update-inventory-po', props.procurement.id)
			: route('procurement.update', props.procurement.id)
		form.put(updateRoute)
	} else {
		const storeRoute = isInventory.value
			? route('procurement.store-inventory-po')
			: route('procurement.store')
		form.post(storeRoute)
	}
}

// ── Live preview helpers ──────────────────────────────────────────
const previewSupplier = computed(() =>
	form.supplier_id ? props.suppliers.find(s => s.id === Number(form.supplier_id)) : null
)
const previewFarm = computed(() =>
	form.farm_id ? props.farms.find(f => f.id === Number(form.farm_id)) : null
)

function fmt(val) {
	if (!val && val !== 0) return '—'
	return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-EG', {
		minimumFractionDigits: 0, maximumFractionDigits: 0,
	})
}
function fmtDec(val) {
	if (!val && val !== 0) return '—'
	return Number(val).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-EG', {
		minimumFractionDigits: 2, maximumFractionDigits: 2,
	})
}

const sectionBadge = () => ({
	display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
	width: '26px', height: '26px', borderRadius: '6px',
	background: 'linear-gradient(135deg, var(--cv-amber), rgba(232,160,48,0.7))',
	color: '#1a1a0e', fontWeight: '800', fontSize: '0.7rem', flexShrink: 0,
})
</script>
<template>
	<AuthenticatedLayout>
		<!-- ══ PAGE HERO ══════════════════════════════════════════ -->
		<div class="cv-page-hero" :dir="isRtl ? 'rtl' : 'ltr'">
			<div class="cv-page-hero-eyebrow">{{ lang === 'ar' ? 'المشتريات' : 'Procurement' }}</div>
			<div class="cv-page-hero-title">
				<template v-if="isEdit">
					{{ lang === 'ar' ? 'تعديل أمر الشراء' : 'Edit Purchase Order' }}
					<span v-if="procurement?.po_reference"
						style="color:var(--cv-amber);font-size:1rem;margin-inline-start:8px">
						{{ procurement.po_reference }}
					</span>
				</template>
				<template v-else>
					{{ isCattle
						? (lang === 'ar' ? 'أمر شراء ماشية جديد' : 'New Cattle Purchase Order')
						: (lang === 'ar' ? 'أمر شراء مخزون جديد' : 'New Inventory Purchase Order') }}
				</template>
			</div>
			<div class="cv-page-hero-sub">
				<template v-if="previewSupplier">
					<span style="color:var(--cv-amber)">{{ n(previewSupplier, 'name') }}</span>
					<span v-if="previewFarm"> · {{ n(previewFarm, 'name') }}</span>
					<!-- Cattle: head range -->
					<span v-if="isCattle && form.min_head && form.max_head"> &nbsp;· <span
							style="color:var(--cv-blue-text)">{{ form.min_head }}–{{ form.max_head }}
							{{ lang === 'ar' ? 'رأس' : 'head' }}</span>
					</span>
					<!-- Inventory: item count -->
					<span v-if="isInventory && form.item_lines.length > 0"> &nbsp;· <span
							style="color:var(--cv-blue-text)">{{ form.item_lines.length }}
							{{ lang === 'ar' ? 'صنف' : 'item(s)' }}</span>
					</span>
				</template>
				<template v-else>
					{{ isCattle
						? (lang === 'ar'
							? 'أنشئ أمر الشراء أولاً — تُضاف الحيوانات لاحقاً عند الاستلام'
							: 'Create the PO first — animals are added later when received')
						: (lang === 'ar'
							? 'أنشئ أمر الشراء — تُستلم البضاعة عبر زر الاستلام في القائمة'
							: 'Create the PO — items are received via the Receive button in the list') }}
				</template>
			</div>
			<div class="cv-page-hero-actions">
				<Link :href="route('procurement.index')" class="cv-btn cv-btn-ghost">
					{{ lang === 'ar' ? 'رجوع' : 'Back' }}
				</Link>
			</div>
		</div>
		<div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">
			<!-- ══ LOCKED WARNING ══════════════════════════════════ -->
			<div v-if="isLocked" class="cv-alert cv-alert-warning mb-6">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
					stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0">
					<circle cx="12" cy="12" r="10" />
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
				<span>
					{{ lang === 'ar'
						? 'لا يمكن تعديل هذا الأمر — الحالة: ' + procurement?.po_status
						: 'This PO cannot be edited — status: ' + procurement?.po_status }}
				</span>
			</div>
			<!-- ══ PO TYPE BADGE (edit mode — read-only reminder) ══ -->
			<div v-if="isEdit" class="mb-5">
				<span
					:style="isInventory
						? 'font-size:.78rem;font-weight:600;color:var(--cv-amber);background:var(--cv-amber-light);border:1px solid var(--cv-amber-border);padding:4px 12px;border-radius:6px;display:inline-flex;align-items:center;gap:6px'
						: 'font-size:.78rem;font-weight:600;color:var(--cv-green-bright);background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);padding:4px 12px;border-radius:6px;display:inline-flex;align-items:center;gap:6px'">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
						stroke-linejoin="round" style="width:13px;height:13px">
						<path v-if="isInventory"
							d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z" />
						<path v-if="isInventory" d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16" />
						<path v-if="isCattle" d="M3 9l2-5h14l2 5" />
						<rect v-if="isCattle" x="5" y="9" width="14" height="10" rx="2" />
					</svg>
					{{ isInventory
						? (lang === 'ar' ? 'أمر شراء مخزون' : 'Inventory Purchase Order')
						: (lang === 'ar' ? 'أمر شراء ماشية' : 'Cattle Purchase Order') }}
				</span>
			</div>
			<!-- ══ NEW FLOW NOTICE (create mode) ══════════════════ -->
			<div v-if="!isEdit" class="cv-alert cv-alert-info mb-6" style="font-size:.8rem">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
					stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0">
					<circle cx="12" cy="12" r="10" />
					<line x1="12" y1="16" x2="12" y2="12" />
					<line x1="12" y1="8" x2="12.01" y2="8" />
				</svg>
				<span v-if="isCattle">
					{{ lang === 'ar'
						? 'بعد إنشاء أمر الشراء وتأكيده، ستظهر زر "استلام حيوانات" بجانبه في القائمة لإضافة الحيوانات عند وصولها.'
						: 'After creating and confirming this PO, a "Receive Animals" button will appear next to it in the list to add animals when they arrive.' }}
				</span>
				<span v-else>
					{{ lang === 'ar'
						? 'بعد إنشاء أمر الشراء وتأكيده، ستظهر زر "استلام بضاعة" بجانبه في القائمة لاستلام الأصناف عند وصولها.'
						: 'After creating and confirming this PO, a "Receive Items" button will appear next to it in the list to receive the ordered items when they arrive.' }}
				</span>
			</div>
			<form @submit.prevent="submit" novalidate>
				<!-- po_type is always submitted so the server knows which branch to use -->
				<input type="hidden" v-model="form.po_type" />
				<div class="flex flex-col gap-5">
					<!-- ══ SECTION 1 — تفاصيل أمر الشراء ══════════ -->
					<div class="cv-card">
						<div class="flex items-center gap-3 mb-5">
							<span :style="sectionBadge()">1</span>
							<div>
								<div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
									{{ lang === 'ar' ? 'تفاصيل أمر الشراء' : 'Purchase Order Details' }}
								</div>
								<div style="font-size:.7rem;color:var(--cv-text-muted)">
									{{ lang === 'ar' ? 'التاريخ والمورد والمزرعة' : 'Date, supplier and farm' }}
								</div>
							</div>
						</div>
						<div class="grid gap-4" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
							<!-- Date -->
							<div class="cv-field">
								<label class="cv-label">
									{{ lang === 'ar' ? 'تاريخ الأمر' : 'PO Date' }}
									<span style="color:var(--cv-danger-text)">*</span>
								</label>
								<input type="date" v-model="form.date" class="cv-input" :max="today"
									:disabled="isLocked" required />
								<div v-if="form.errors.date" class="cv-field-error">{{ form.errors.date }}</div>
							</div>
							<!-- Invoice / Internal Ref -->
							<!-- <div class="cv-field">
                                <label class="cv-label">{{ lang === 'ar' ? 'المرجع الداخلي' : 'Internal Reference' }}</label>
                                <input type="text" v-model="form.invoice_ref" class="cv-input"
                                       :placeholder="lang === 'ar' ? 'اختياري' : 'Optional'"
                                       :disabled="isLocked" />
                                <div v-if="form.errors.invoice_ref" class="cv-field-error">{{ form.errors.invoice_ref }}</div>
                            </div> -->
							<!-- Supplier -->
							<div class="cv-field" style="grid-column:span 2">
								<label class="cv-label">
									{{ lang === 'ar' ? 'المورد' : 'Supplier' }}
									<span style="color:var(--cv-danger-text)">*</span>
								</label>
								<select v-model="form.supplier_id" class="cv-select" :disabled="isLocked" required>
									<option value="">{{ lang === 'ar' ? '— اختر المورد —' : '— Select Supplier —' }}
									</option>
									<option v-for="s in suppliers" :key="s.id" :value="s.id">{{ n(s, 'name') }}</option>
								</select>
								<div v-if="form.errors.supplier_id" class="cv-field-error">{{ form.errors.supplier_id }}
								</div>
							</div>
							<!-- Farm -->
							<div class="cv-field">
								<label class="cv-label">
									{{ lang === 'ar' ? 'المزرعة' : 'Farm' }}
									<span style="color:var(--cv-danger-text)">*</span>
								</label>
								<select v-model="form.farm_id" class="cv-select" :disabled="isLocked" required>
									<option value="">{{ lang === 'ar' ? '— اختر المزرعة —' : '— Select Farm —' }}
									</option>
									<option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
								</select>
								<div v-if="form.errors.farm_id" class="cv-field-error">{{ form.errors.farm_id }}</div>
							</div>
							<!-- Lot -->
							<div class="cv-field">
								<label class="cv-label">{{ lang === 'ar' ? 'اللوت' : 'Lot' }}</label>
								<select v-model="form.lot_id" class="cv-select" :disabled="isLocked || !form.farm_id">
									<option value="">{{ lang === 'ar' ? '— اختياري —' : '— Optional —' }}</option>
									<option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
								</select>
								<div v-if="form.errors.lot_id" class="cv-field-error">{{ form.errors.lot_id }}</div>
							</div>
						</div>
					</div>
					<!-- ══════════════════════════════════════════════════════════════
                         SECTION 2 — CONDITIONAL ON PO TYPE
                         Cattle  → Quantity range + estimated price
                         Inventory → Ordered item lines table
                         ══════════════════════════════════════════════════════════════ -->
					<!-- ── CATTLE: Section 2 — نطاق الكميات المتوقعة ── -->
					<div v-if="isCattle" class="cv-card">
						<div class="flex items-center gap-3 mb-5">
							<span :style="sectionBadge()">2</span>
							<div>
								<div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
									{{ lang === 'ar' ? 'نطاق الكميات والسعر المقدر' : 'Quantity Range & Estimated Price' }}
								</div>
								<div style="font-size:.7rem;color:var(--cv-text-muted)">
									{{ lang === 'ar'
										? 'حدد النطاق المتوقع — الاستلام الفعلي يتم لاحقاً'
										: 'Set the expected range — actual receipt happens later' }}
								</div>
							</div>
						</div>
						<div class="grid gap-4" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
							<!-- Min Head -->
							<div class="cv-field">
								<label class="cv-label">
									{{ lang === 'ar' ? 'الحد الأدنى (رأس)' : 'Min Head Count' }}
									<span style="color:var(--cv-danger-text)">*</span>
								</label>
								<input type="number" v-model="form.min_head" class="cv-input" min="1" step="1"
									:placeholder="lang === 'ar' ? 'مثال: ٤٨' : 'e.g. 48'" :disabled="isLocked" />
								<div style="font-size:.68rem;color:var(--cv-text-muted);margin-top:3px">
									{{ lang === 'ar' ? 'أقل عدد مقبول' : 'Minimum acceptable delivery' }}
								</div>
								<div v-if="form.errors.min_head" class="cv-field-error">{{ form.errors.min_head }}</div>
							</div>
							<!-- Max Head -->
							<div class="cv-field">
								<label class="cv-label">
									{{ lang === 'ar' ? 'الحد الأقصى (رأس)' : 'Max Head Count' }}
									<span style="color:var(--cv-danger-text)">*</span>
								</label>
								<input type="number" v-model="form.max_head" class="cv-input" :min="form.min_head || 1"
									step="1" :placeholder="lang === 'ar' ? 'مثال: ٥٥' : 'e.g. 55'"
									:disabled="isLocked" />
								<div style="font-size:.68rem;color:var(--cv-text-muted);margin-top:3px">
									{{ lang === 'ar' ? 'تجاوزه يُعطي تحذير "زيادة استلام"' : 'Exceeding this triggers over-received warning' }}
								</div>
								<div v-if="form.errors.max_head" class="cv-field-error">{{ form.errors.max_head }}</div>
							</div>
							<!-- Estimated Avg Weight -->
							<div class="cv-field">
								<label
									class="cv-label">{{ lang === 'ar' ? 'متوسط الوزن المقدر (كجم)' : 'Est. Avg Weight (kg)' }}</label>
								<input type="number" v-model="form.estimated_avg_weight_kg" class="cv-input" min="0"
									step="0.1" :placeholder="lang === 'ar' ? 'مثال: ٣٢٠' : 'e.g. 320'"
									:disabled="isLocked" />
								<div v-if="form.errors.estimated_avg_weight_kg" class="cv-field-error">
									{{ form.errors.estimated_avg_weight_kg }}
								</div>
							</div>
							<!-- Estimated Price per kg -->
							<div class="cv-field">
								<label class="cv-label">
									{{ lang === 'ar' ? 'السعر المقدر / كجم' : 'Est. Price per kg' }}
									<span style="color:var(--cv-danger-text)">*</span>
								</label>
								<div class="cv-input-group">
									<span class="cv-input-prefix"
										style="font-size:.7rem;font-weight:600">{{ form.currency || 'EGP' }}</span>
									<input type="number" v-model="form.estimated_price_per_kg" class="cv-input"
										min="0.01" step="0.01" :placeholder="lang === 'ar' ? '٤٥٫٠٠' : '45.00'"
										:disabled="isLocked" />
								</div>
								<div style="font-size:.68rem;color:var(--cv-text-muted);margin-top:3px">
									{{ lang === 'ar' ? 'يُستخدم لتقييم المخزون عند الاستلام' : 'Used for inventory valuation on receipt' }}
								</div>
								<div v-if="form.errors.estimated_price_per_kg" class="cv-field-error">
									{{ form.errors.estimated_price_per_kg }}
								</div>
							</div>
						</div>
						<!-- Estimated Value Range Display -->
						<div v-if="estimatedMin > 0 || estimatedMax > 0"
							style="margin-top:1.25rem;padding:1rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);border:1px solid var(--cv-amber-border)">
							<div
								style="font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);margin-bottom:.5rem">
								{{ lang === 'ar' ? 'القيمة التقديرية للأمر' : 'Estimated PO Value Range' }}
							</div>
							<div class="flex items-center gap-3 flex-wrap">
								<span
									style="font-size:1.1rem;font-weight:800;color:var(--cv-blue-text)">{{ fmtDec(estimatedMin) }}</span>
								<span style="color:var(--cv-text-muted)">—</span>
								<span
									style="font-size:1.1rem;font-weight:800;color:var(--cv-blue-text)">{{ fmtDec(estimatedMax) }}</span>
								<span
									style="font-size:.72rem;color:var(--cv-text-muted)">{{ form.currency || 'EGP' }}</span>
								<span style="font-size:.68rem;color:var(--cv-text-muted);margin-inline-start:.25rem">
									({{ form.min_head || '?' }}–{{ form.max_head || '?' }}
									{{ lang === 'ar' ? 'رأس' : 'head' }}) </span>
							</div>
						</div>
					</div>
					<!-- ── INVENTORY: Section 2 — بنود الأصناف المطلوبة ── -->
					<div v-if="isInventory" class="cv-card">
						<div class="flex items-center justify-between gap-3 mb-5">
							<div class="flex items-center gap-3">
								<span :style="sectionBadge()">2</span>
								<div>
									<div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
										{{ lang === 'ar' ? 'الأصناف المطلوبة' : 'Ordered Items' }}
									</div>
									<div style="font-size:.7rem;color:var(--cv-text-muted)">
										{{ lang === 'ar'
											? 'حدد الأصناف والكميات المطلوبة — الاستلام الفعلي يتم لاحقاً'
											: 'Specify items and quantities — actual receipt happens later via the Receive button' }}
									</div>
								</div>
							</div>
							<button v-if="!isLocked" type="button" class="cv-btn cv-btn-ghost"
								style="font-size:.75rem;padding:.4rem .85rem" @click="addItemLine">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
									stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px">
									<line x1="12" y1="5" x2="12" y2="19" />
									<line x1="5" y1="12" x2="19" y2="12" />
								</svg>
								{{ lang === 'ar' ? 'إضافة صنف' : 'Add Item' }}
							</button>
						</div>
						<!-- Item Lines Table -->
						<div v-if="form.item_lines.length > 0" style="overflow-x:auto">
							<table style="width:100%;border-collapse:collapse;font-size:.78rem">
								<thead>
									<tr style="border-bottom:1px solid var(--cv-border)">
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);min-width:180px">
											{{ lang === 'ar' ? 'الصنف' : 'Item' }}
											<span style="color:var(--cv-danger-text)">*</span>
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);min-width:150px">
											{{ lang === 'ar' ? 'الموقع' : 'Location' }}
											<span style="color:var(--cv-danger-text)">*</span>
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);min-width:100px">
											{{ lang === 'ar' ? 'الكمية' : 'Qty' }}
											<span style="color:var(--cv-danger-text)">*</span>
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text);min-width:120px">
											{{ lang === 'ar' ? 'سعر الوحدة' : 'Unit Cost' }}
										</th>
										<th
											style="padding:.5rem .75rem;text-align:end;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-blue-text);min-width:110px">
											{{ lang === 'ar' ? 'الإجمالي' : 'Line Total' }}
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted);min-width:120px">
											{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}
										</th>
										<th style="padding:.5rem .75rem;width:36px"></th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(line, idx) in form.item_lines" :key="idx"
										style="border-bottom:1px solid var(--cv-border)">
										<!-- Item selector -->
										<td style="padding:.4rem .5rem">
											<select v-model="line.inventory_item_id" class="cv-select"
												style="min-width:160px;font-size:.78rem;padding:.35rem .6rem"
												:disabled="isLocked" @change="onItemSelect(line)">
												<option value="">{{ lang === 'ar' ? '— اختر —' : '— Select —' }}
												</option>
												<option v-for="item in inventoryItems" :key="item.id" :value="item.id">
													{{ n(item, 'name') }}
													<template v-if="item.sku"> ({{ item.sku }})</template>
												</option>
											</select>
											<div v-if="form.errors[`item_lines.${idx}.inventory_item_id`]"
												class="cv-field-error" style="font-size:.65rem">
												{{ form.errors[`item_lines.${idx}.inventory_item_id`] }}
											</div>
										</td>
										<!-- Location selector — cascades from item -->
										<td style="padding:.4rem .5rem">
											<select v-model="line.location_id" class="cv-select"
												style="min-width:130px;font-size:.78rem;padding:.35rem .6rem"
												:disabled="isLocked || !line.inventory_item_id">
												<option value="">
													{{ lang === 'ar' ? '— اختر الموقع —' : '— Select Location —' }}
												</option>
												<option v-for="loc in locationsForLine(line)" :key="loc.id"
													:value="loc.id">
													{{ n(loc, 'name') }}
												</option>
												<div v-if="form.errors[`item_lines.${idx}.location_id`]"
													class="cv-field-error" style="font-size:.65rem">
													{{ form.errors[`item_lines.${idx}.location_id`] }}
												</div>
											</select>
										</td>
										<!-- Quantity -->
										<td style="padding:.4rem .5rem">
											<input type="number" v-model="line.qty_ordered" class="cv-input"
												style="min-width:80px;font-size:.78rem;padding:.35rem .6rem" min="0.001"
												step="0.001" :placeholder="lang === 'ar' ? '٠' : '0'"
												:disabled="isLocked" />
											<div v-if="form.errors[`item_lines.${idx}.qty_ordered`]"
												class="cv-field-error" style="font-size:.65rem">
												{{ form.errors[`item_lines.${idx}.qty_ordered`] }}
											</div>
										</td>
										<!-- Unit Cost (auto-filled from WAC, editable) -->
										<td style="padding:.4rem .5rem">
											<div class="cv-input-group">
												<span class="cv-input-prefix" style="font-size:.65rem;font-weight:600">
													{{ form.currency || 'EGP' }}
												</span>
												<input type="number" v-model="line.unit_cost_estimated" class="cv-input"
													style="min-width:80px;font-size:.78rem;padding:.35rem .6rem" min="0"
													step="0.01" :placeholder="lang === 'ar' ? '٠٫٠٠' : '0.00'"
													:disabled="isLocked" />
											</div>
										</td>
										<!-- Line Total (computed display) -->
										<td style="padding:.4rem .75rem;text-align:end;white-space:nowrap">
											<span style="font-weight:700;color:var(--cv-blue-text)">
												{{ (Number(line.qty_ordered) && Number(line.unit_cost_estimated))
													? fmtDec(Number(line.qty_ordered) * Number(line.unit_cost_estimated))
													: '—' }}
											</span>
										</td>
										<!-- Notes -->
										<td style="padding:.4rem .5rem">
											<input type="text" v-model="line.notes" class="cv-input"
												style="min-width:100px;font-size:.78rem;padding:.35rem .6rem"
												:disabled="isLocked" />
										</td>
										<!-- Remove -->
										<td style="padding:.4rem .5rem;text-align:center">
											<button v-if="!isLocked" type="button"
												style="background:none;border:none;cursor:pointer;color:var(--cv-danger-text);padding:4px;border-radius:4px"
												@click="removeItemLine(idx)">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
													stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
													style="width:14px;height:14px">
													<polyline points="3 6 5 6 21 6" />
													<path d="M19 6l-1 14H6L5 6" />
													<path d="M10 11v6M14 11v6" />
													<path d="M9 6V4h6v2" />
												</svg>
											</button>
										</td>
									</tr>
								</tbody>
							</table>
							<!-- Grand Total row -->
							<div v-if="itemLineTotal > 0"
								style="margin-top:1rem;padding:.75rem 1rem;background:var(--cv-bg-card-alt);border-radius:var(--cv-radius);border:1px solid var(--cv-amber-border);display:flex;align-items:center;justify-content:space-between">
								<span
									style="font-size:.72rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
									{{ lang === 'ar' ? 'إجمالي الأصناف' : 'Items Subtotal' }}
								</span>
								<span style="font-size:1.05rem;font-weight:800;color:var(--cv-blue-text)">
									{{ fmtDec(itemLineTotal) }}
									<span
										style="font-size:.72rem;color:var(--cv-text-muted);margin-inline-start:4px">{{ form.currency || 'EGP' }}</span>
								</span>
							</div>
						</div>
						<!-- Empty state -->
						<div v-else
							style="padding:1.5rem;text-align:center;border:1.5px dashed var(--cv-border);border-radius:var(--cv-radius)">
							<div style="font-size:.78rem;color:var(--cv-text-muted)">
								{{ lang === 'ar'
									? 'لم تُضف أي أصناف بعد — اضغط "إضافة صنف" أعلاه'
									: 'No items added yet — press "Add Item" above' }}
							</div>
						</div>
					</div>
					<!-- ══ SECTION 3 — بنود تفصيل الأصناف (CATTLE ONLY) ══ -->
					<div v-if="isCattle" class="cv-card">
						<div class="flex items-center justify-between gap-3 mb-5">
							<div class="flex items-center gap-3">
								<span :style="sectionBadge()">3</span>
								<div>
									<div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
										{{ lang === 'ar' ? 'تفصيل الأصناف (اختياري)' : 'Breed/Category Breakdown (Optional)' }}
									</div>
									<div style="font-size:.7rem;color:var(--cv-text-muted)">
										{{ lang === 'ar'
											? 'فصّل السلالات والأوزان المقدرة — للمرجع فقط، لا تُنشئ حيوانات'
											: 'Break down breeds and estimated weights — reference only, does not create animals' }}
									</div>
								</div>
							</div>
							<button v-if="!isLocked" type="button" class="cv-btn cv-btn-ghost"
								style="font-size:.75rem;padding:.4rem .85rem" @click="addLine">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
									stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px">
									<line x1="12" y1="5" x2="12" y2="19" />
									<line x1="5" y1="12" x2="19" y2="12" />
								</svg>
								{{ lang === 'ar' ? 'إضافة صنف' : 'Add Category' }}
							</button>
						</div>
						<div v-if="form.lines.length > 0" style="overflow-x:auto">
							<table style="width:100%;border-collapse:collapse;font-size:.78rem">
								<thead>
									<tr style="border-bottom:1px solid var(--cv-border)">
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
											{{ lang === 'ar' ? 'السلالة' : 'Breed' }}
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
											{{ lang === 'ar' ? 'الجنس' : 'Sex' }}
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
											{{ lang === 'ar' ? 'الوزن المقدر' : 'Est. Weight (kg)' }}
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
											{{ lang === 'ar' ? 'سعر/كجم' : 'Price/kg' }}
										</th>
										<th
											style="padding:.5rem .75rem;text-align:start;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--cv-text-muted)">
											{{ lang === 'ar' ? 'ملاحظات' : 'Notes' }}
										</th>
										<th style="padding:.5rem .75rem;width:36px"></th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(line, idx) in form.lines" :key="idx"
										style="border-bottom:1px solid var(--cv-border)">
										<td style="padding:.4rem .5rem">
											<input type="text" v-model="line.breed" class="cv-input"
												style="min-width:110px;font-size:.78rem;padding:.35rem .6rem"
												:placeholder="lang === 'ar' ? 'فريزيان…' : 'Friesian…'"
												:disabled="isLocked" />
										</td>
										<td style="padding:.4rem .5rem">
											<select v-model="line.sex" class="cv-select"
												style="min-width:100px;font-size:.78rem;padding:.35rem .6rem"
												:disabled="isLocked">
												<option value="">—</option>
												<option value="male">{{ lang === 'ar' ? 'ذكر' : 'Male' }}</option>
												<option value="female">{{ lang === 'ar' ? 'أنثى' : 'Female' }}</option>
												<option value="castrated">{{ lang === 'ar' ? 'مخصي' : 'Castrated' }}
												</option>
												<option value="mixed">{{ lang === 'ar' ? 'مختلط' : 'Mixed' }}</option>
											</select>
										</td>
										<td style="padding:.4rem .5rem">
											<input type="number" v-model="line.estimated_weight_kg" class="cv-input"
												style="min-width:90px;font-size:.78rem;padding:.35rem .6rem" min="0"
												step="0.1" :disabled="isLocked"
												:placeholder="lang === 'ar' ? '٣٢٠' : '320'" />
										</td>
										<td style="padding:.4rem .5rem">
											<input type="number" v-model="line.price_per_kg" class="cv-input"
												style="min-width:90px;font-size:.78rem;padding:.35rem .6rem" min="0"
												step="0.01" :disabled="isLocked"
												:placeholder="lang === 'ar' ? '٤٥٫٠٠' : '45.00'" />
										</td>
										<td style="padding:.4rem .5rem">
											<input type="text" v-model="line.notes" class="cv-input"
												style="min-width:120px;font-size:.78rem;padding:.35rem .6rem"
												:disabled="isLocked" />
										</td>
										<td style="padding:.4rem .5rem;text-align:center">
											<button v-if="!isLocked" type="button"
												style="background:none;border:none;cursor:pointer;color:var(--cv-danger-text);padding:4px;border-radius:4px"
												@click="removeLine(idx)">
												<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
													stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
													style="width:14px;height:14px">
													<polyline points="3 6 5 6 21 6" />
													<path d="M19 6l-1 14H6L5 6" />
													<path d="M10 11v6M14 11v6" />
													<path d="M9 6V4h6v2" />
												</svg>
											</button>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<div v-else
							style="padding:1.5rem;text-align:center;border:1.5px dashed var(--cv-border);border-radius:var(--cv-radius)">
							<div style="font-size:.78rem;color:var(--cv-text-muted)">
								{{ lang === 'ar'
									? 'لا توجد بنود تفصيل — هذا القسم اختياري للمرجع فقط'
									: 'No breakdown lines — this section is optional, for reference only' }}
							</div>
						</div>
					</div>
					<!-- ══ SECTION 4 (cattle) / SECTION 3 (inventory) — التكاليف والدفع ══ -->
					<div class="cv-card">
						<div class="flex items-center gap-3 mb-5">
							<span :style="sectionBadge()">{{ isCattle ? 4 : 3 }}</span>
							<div>
								<div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
									{{ lang === 'ar' ? 'التكاليف وشروط الدفع' : 'Costs & Payment Terms' }}
								</div>
								<div style="font-size:.7rem;color:var(--cv-text-muted)">
									{{ lang === 'ar' ? 'الشحن والتكاليف الأخرى والعملة وأيام الدفع' : 'Freight, other costs, currency and payment days' }}
								</div>
							</div>
						</div>
						<div class="grid gap-4" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
							<!-- Freight -->
							<div class="cv-field">
								<label
									class="cv-label">{{ lang === 'ar' ? 'تكلفة الشحن / النقل' : 'Freight Cost' }}</label>
								<div class="cv-input-group">
									<span class="cv-input-prefix"
										style="font-size:.7rem;font-weight:600">{{ form.currency || 'EGP' }}</span>
									<input type="number" v-model="form.freight_cost" class="cv-input" min="0"
										step="0.01" :disabled="isLocked"
										:placeholder="lang === 'ar' ? '٠٫٠٠' : '0.00'" />
								</div>
								<div v-if="form.errors.freight_cost" class="cv-field-error">
									{{ form.errors.freight_cost }}</div>
							</div>
							<!-- Other costs -->
							<div class="cv-field">
								<label class="cv-label">{{ lang === 'ar' ? 'تكاليف أخرى' : 'Other Costs' }}</label>
								<div class="cv-input-group">
									<span class="cv-input-prefix"
										style="font-size:.7rem;font-weight:600">{{ form.currency || 'EGP' }}</span>
									<input type="number" v-model="form.other_costs" class="cv-input" min="0" step="0.01"
										:disabled="isLocked" :placeholder="lang === 'ar' ? '٠٫٠٠' : '0.00'" />
								</div>
								<div v-if="form.errors.other_costs" class="cv-field-error">{{ form.errors.other_costs }}
								</div>
							</div>
							<!-- Currency -->
							<div class="cv-field">
								<label class="cv-label">{{ lang === 'ar' ? 'العملة' : 'Currency' }}</label>
								<select v-model="form.currency" class="cv-select" :disabled="isLocked">
									<option value="EGP">EGP — {{ lang === 'ar' ? 'جنيه مصري' : 'Egyptian Pound' }}
									</option>
									<option value="USD">USD — {{ lang === 'ar' ? 'دولار أمريكي' : 'US Dollar' }}
									</option>
									<option value="EUR">EUR — {{ lang === 'ar' ? 'يورو' : 'Euro' }}</option>
									<option value="SAR">SAR — {{ lang === 'ar' ? 'ريال سعودي' : 'Saudi Riyal' }}
									</option>
								</select>
								<div v-if="form.errors.currency" class="cv-field-error">{{ form.errors.currency }}</div>
							</div>
							<!-- Payment terms -->
							<div class="cv-field">
								<label
									class="cv-label">{{ lang === 'ar' ? 'أيام الدفع' : 'Payment Terms (Days)' }}</label>
								<input type="number" v-model="form.payment_terms_days" class="cv-input" min="0" step="1"
									:disabled="isLocked" :placeholder="lang === 'ar' ? '٣٠' : '30'" />
								<div v-if="form.errors.payment_terms_days" class="cv-field-error">
									{{ form.errors.payment_terms_days }}
								</div>
							</div>
							<!-- Due date -->
							<div class="cv-field">
								<label class="cv-label">{{ lang === 'ar' ? 'تاريخ الاستحقاق' : 'Due Date' }}</label>
								<input type="date" v-model="form.due_date" class="cv-input" :disabled="isLocked" />
								<div style="font-size:.68rem;color:var(--cv-text-muted);margin-top:3px">
									{{ lang === 'ar' ? 'يُحسب تلقائياً' : 'Auto-calculated from date + days' }}
								</div>
								<div v-if="form.errors.due_date" class="cv-field-error">{{ form.errors.due_date }}</div>
							</div>
						</div>
					</div>
					<!-- ══ SECTION 5 (cattle) / SECTION 4 (inventory) — الملاحظات ══ -->
					<div class="cv-card">
						<div class="flex items-center gap-3 mb-5">
							<span :style="sectionBadge()">{{ isCattle ? 5 : 4 }}</span>
							<div>
								<div style="font-size:.85rem;font-weight:700;color:var(--cv-text-primary)">
									{{ lang === 'ar' ? 'الملاحظات' : 'Notes' }}
								</div>
							</div>
						</div>
						<div class="cv-field">
							<textarea v-model="form.notes" class="cv-textarea" rows="3"
								:placeholder="lang === 'ar' ? 'أي ملاحظات إضافية…' : 'Any additional notes…'"
								:disabled="isLocked" />
							<div v-if="form.errors.notes" class="cv-field-error">{{ form.errors.notes }}</div>
						</div>
					</div>
					<!-- ══ FORM ACTIONS ════════════════════════════════ -->
					<div class="flex items-center gap-3 justify-end"
						style="padding:1rem 0;border-top:1px solid var(--cv-border)">
						<Link :href="route('procurement.index')" class="cv-btn cv-btn-ghost">
							{{ lang === 'ar' ? 'إلغاء' : 'Cancel' }}
						</Link>
						<button v-if="!isLocked" type="submit" class="cv-btn cv-btn-amber" :disabled="form.processing">
							<svg v-if="form.processing" viewBox="0 0 24 24" fill="none" stroke="currentColor"
								stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
								style="width:14px;height:14px;animation:spin 1s linear infinite">
								<path d="M21 12a9 9 0 11-6.219-8.56" />
							</svg>
							<svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
								stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
								<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
								<polyline points="17 21 17 13 7 13 7 21" />
								<polyline points="7 3 7 8 15 8" />
							</svg>
							{{ form.processing
								? (lang === 'ar' ? 'جارٍ الحفظ…' : 'Saving…')
								: isEdit
									? (lang === 'ar' ? 'حفظ التعديلات' : 'Save Changes')
									: (lang === 'ar' ? 'إنشاء أمر الشراء' : 'Create Purchase Order') }}
						</button>
					</div>
				</div>
			</form>
		</div>
	</AuthenticatedLayout>
</template>
<style scoped>
@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
