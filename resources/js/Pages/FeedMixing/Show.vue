<script setup>
/**
 * FeedMixing/Show.vue — VERO Cattle Management
 * One mixing batch: summary, ingredients, journal links, and the
 * Start / Finish / Cancel actions.
 *
 * 📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
 *
 * COMPONENT PLAN
 *  1. Props: batch (full, with lines + journal refs), can{}, today
 *  2. Actions: Start (draft) · Edit (draft) · Finish (in progress) · Cancel (in progress)
 *              · Copy as new batch (any status)
 *  3. Table: ingredients — qty, unit cost, line cost, account credited
 *  4. Filters: none
 *  5. Permissions: can.edit gates Start/Finish/Cancel/Edit; can.create gates Copy
 *  6. Mobile/RTL: stat cards wrap; table scrolls horizontally; dir from useLocale
 *
 * Every number here is read from the server. Nothing is calculated in the browser
 * except the yield % shown for information.
 */
import { ref, computed } from 'vue'
import { router, Link, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'
defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl } = useLocale()

const props = defineProps({
    batch: { type: Object, required: true },
    can:   { type: Object, default: () => ({ create: false, edit: false, delete: false }) },
    today: { type: String, default: '' },
})

const dict = {
    en: {
        eyebrow: 'Feed Mixing', back: 'All batches',
        draft: 'Draft', in_progress: 'In Progress', completed: 'Completed', cancelled: 'Cancelled',
        hintDraft: 'Nothing has left stock yet. Press Start to move the ingredients into Feed Mixing WIP.',
        hintProgress: 'Ingredients are in Feed Mixing WIP. When mixing is done, press Finish and enter how many kg you got.',
        hintDone: 'Finished. The mix is in stock and can be used in Feed Consumption.',
        hintCancelled: 'Cancelled. The ingredients were returned to stock and the accounting was reversed.',
        start: 'Start mixing', startBusy: 'Starting…',
        finish: 'Finish mixing', cancelBatch: 'Cancel batch', edit: 'Edit', copy: 'Copy as new batch',
        kpiCost: 'Batch cost', kpiIng: 'Ingredients (kg)', kpiFin: 'Finished mix (kg)', kpiCpk: 'Cost per kg', kpiYield: 'Yield',
        egp: 'EGP',
        details: 'Details', farm: 'Farm', location: 'Location', mix: 'Finished mix',
        startDate: 'Start date', finishDate: 'Finish date', createdBy: 'Created by', notes: 'Notes',
        ingredients: 'Ingredients', item: 'Ingredient', qty: 'Quantity', unitCost: 'Cost / unit', lineCost: 'Cost', account: 'Account',
        notCosted: 'Cost is fixed when mixing starts',
        journals: 'Accounting entries', jStart: 'Start (Dr WIP 1360)', jFinish: 'Finish (Dr Feed Inventory 1320)', jCancel: 'Cancel (reversal)',
        // finish modal
        finTitle: 'Finish mixing', finQtyLabel: 'Finished mix produced (kg)',
        finHint: 'Enter the actual kg you got. It may be less than the ingredients because of waste or moisture.',
        finDate: 'Finish date', finBtn: 'Finish', finBusy: 'Finishing…',
        finPreview: 'Cost per kg will be',
        warnMore: 'This is more than the ingredient weight. That is fine if water was added.',
        // cancel modal
        canTitle: 'Cancel this batch?',
        canMsg: 'The ingredients will be returned to stock at the cost they left at, and the accounting entry will be reversed. This cannot be undone.',
        canBtn: 'Yes, cancel batch', canBusy: 'Cancelling…', close: 'Close',
    },
    ar: {
        eyebrow: 'خلط الأعلاف', back: 'كل الدفعات',
        draft: 'مسودة', in_progress: 'قيد التنفيذ', completed: 'مكتملة', cancelled: 'ملغاة',
        hintDraft: 'لم يخرج أي شيء من المخزون بعد. اضغط «ابدأ الخلط» لنقل المكونات إلى حساب تصنيع الأعلاف قيد التشغيل.',
        hintProgress: 'المكونات الآن في حساب تصنيع الأعلاف قيد التشغيل. عند انتهاء الخلط اضغط «إنهاء الخلط» وأدخل الكمية الناتجة بالكجم.',
        hintDone: 'اكتملت الدفعة. العلف المخلوط في المخزون ويمكن استخدامه في استهلاك العلف.',
        hintCancelled: 'أُلغيت الدفعة. أُعيدت المكونات للمخزون وعُكس القيد المحاسبي.',
        start: 'ابدأ الخلط', startBusy: 'جارٍ البدء…',
        finish: 'إنهاء الخلط', cancelBatch: 'إلغاء الدفعة', edit: 'تعديل', copy: 'نسخ كدفعة جديدة',
        kpiCost: 'تكلفة الدفعة', kpiIng: 'المكونات (كجم)', kpiFin: 'العلف الناتج (كجم)', kpiCpk: 'التكلفة لكل كجم', kpiYield: 'نسبة الناتج',
        egp: 'ج.م',
        details: 'التفاصيل', farm: 'المزرعة', location: 'الموقع', mix: 'العلف المخلوط',
        startDate: 'تاريخ البدء', finishDate: 'تاريخ الإنهاء', createdBy: 'أنشأها', notes: 'ملاحظات',
        ingredients: 'المكونات', item: 'المكوّن', qty: 'الكمية', unitCost: 'التكلفة / وحدة', lineCost: 'التكلفة', account: 'الحساب',
        notCosted: 'تُثبَّت التكلفة عند بدء الخلط',
        journals: 'القيود المحاسبية', jStart: 'البدء (مدين 1360)', jFinish: 'الإنهاء (مدين مخزون الأعلاف 1320)', jCancel: 'الإلغاء (قيد عكسي)',
        finTitle: 'إنهاء الخلط', finQtyLabel: 'كمية العلف المخلوط الناتجة (كجم)',
        finHint: 'أدخل الكمية الفعلية بالكجم. قد تقل عن المكونات بسبب الفاقد أو الرطوبة.',
        finDate: 'تاريخ الإنهاء', finBtn: 'إنهاء', finBusy: 'جارٍ الإنهاء…',
        finPreview: 'ستكون التكلفة لكل كجم',
        warnMore: 'الكمية أكبر من وزن المكونات. لا بأس إذا أُضيف ماء.',
        canTitle: 'إلغاء هذه الدفعة؟',
        canMsg: 'ستُعاد المكونات للمخزون بنفس التكلفة التي خرجت بها، وسيُعكس القيد المحاسبي. لا يمكن التراجع عن ذلك.',
        canBtn: 'نعم، إلغاء الدفعة', canBusy: 'جارٍ الإلغاء…', close: 'إغلاق',
    },
}
const t = computed(() => dict[lang.value] ?? dict.en)

function pick(en, ar) { return lang.value === 'ar' ? (ar || en) : (en || ar) }
function fmt(v, dec = 2) {
    if (v === null || v === undefined || v === '') return '—'
    return Number(v).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: dec, maximumFractionDigits: dec,
    })
}

const b = computed(() => props.batch)
const hint = computed(() => ({
    draft: t.value.hintDraft, in_progress: t.value.hintProgress,
    completed: t.value.hintDone, cancelled: t.value.hintCancelled,
}[b.value.status]))
const badgeClass = computed(() => ({
    draft: 'cv-badge-neutral', in_progress: 'cv-badge-amber',
    completed: 'cv-badge-green', cancelled: 'cv-badge-danger',
}[b.value.status] ?? 'cv-badge-neutral'))

const yieldPct = computed(() => {
    const ing = Number(b.value.total_ingredient_kg)
    const fin = Number(b.value.finished_qty_kg)
    return ing > 0 && fin > 0 ? (fin / ing) * 100 : null
})

// Validation / business errors returned by the server (e.g. "Not enough hay")
const page = usePage()
const serverErrors = computed(() => Object.values(page.props.errors ?? {}).filter(Boolean))

// ── Actions ───────────────────────────────────────────────────────
const busy = ref(false)
function startBatch() {
    busy.value = true
    router.patch(route('feed.mixing.start', b.value.id), {}, {
        preserveScroll: true, onFinish: () => { busy.value = false },
    })
}

const finishOpen = ref(false)
const finishQty = ref('')
const finishDate = ref(props.today)
const previewCpk = computed(() => {
    const q = Number(finishQty.value)
    return q > 0 ? Number(b.value.total_cost) / q : null
})
const moreThanIngredients = computed(() =>
    Number(finishQty.value) > Number(b.value.total_ingredient_kg) && Number(b.value.total_ingredient_kg) > 0
)
function doFinish() {
    busy.value = true
    router.patch(route('feed.mixing.finish', b.value.id),
        { finished_qty_kg: finishQty.value, finish_date: finishDate.value },
        {
            preserveScroll: true,
            onSuccess: () => { finishOpen.value = false },
            onFinish: () => { busy.value = false },
        })
}

const cancelOpen = ref(false)
function doCancel() {
    busy.value = true
    router.patch(route('feed.mixing.cancel', b.value.id), { cancel_date: props.today }, {
        preserveScroll: true,
        onSuccess: () => { cancelOpen.value = false },
        onFinish: () => { busy.value = false },
    })
}
</script>

<template>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- Hero -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title" style="font-family:monospace">{{ b.reference }}</div>
      <div class="cv-page-hero-sub">
        <span class="cv-badge" :class="badgeClass">{{ t[b.status] }}</span>
        &nbsp;{{ pick(b.output_name_en, b.output_name_ar) }}
      </div>
      <div class="cv-page-hero-actions">
        <button v-if="can.edit && b.status === 'draft'" class="cv-btn cv-btn-amber" :disabled="busy" @click="startBatch">
          {{ busy ? t.startBusy : t.start }}
        </button>
        <Link v-if="can.edit && b.status === 'draft'" :href="route('feed.mixing.edit', b.id)" class="cv-btn cv-btn-ghost">{{ t.edit }}</Link>
        <button v-if="can.edit && b.status === 'in_progress'" class="cv-btn cv-btn-amber" @click="finishOpen = true">{{ t.finish }}</button>
        <button v-if="can.edit && b.status === 'in_progress'" class="cv-btn cv-btn-ghost" @click="cancelOpen = true">{{ t.cancelBatch }}</button>
        <Link v-if="can.create" :href="route('feed.mixing.create', { copy: b.id })" class="cv-btn cv-btn-ghost">{{ t.copy }}</Link>
        <Link :href="route('feed.mixing.index')" class="cv-btn cv-btn-ghost">{{ t.back }}</Link>
      </div>
    </div>

    <div class="cv-page" style="max-width:1000px">

      <div v-if="$page.props.flash?.success" class="cv-alert cv-alert-success mb-4">{{ $page.props.flash.success }}</div>
      <div v-if="serverErrors.length" class="cv-alert cv-alert-danger mb-4">
        <div v-for="(e, i) in serverErrors" :key="i">{{ e }}</div>
      </div>
      <div class="cv-alert cv-alert-info mb-4">{{ hint }}</div>

      <!-- KPI strip -->
      <div class="grid gap-3 mb-5" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="cv-stat-card cv-stat-card-blue">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiCost }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-blue-text)">
            {{ b.status === 'draft' ? '—' : fmt(b.total_cost) }}
            <span v-if="b.status !== 'draft'" style="font-size:0.7rem;color:var(--cv-text-muted);font-weight:400">{{ t.egp }}</span>
          </div>
        </div>
        <div class="cv-stat-card">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiIng }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-blue-text)">{{ b.status === 'draft' ? '—' : fmt(b.total_ingredient_kg, 1) }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-green">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiFin }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-green-bright)">{{ fmt(b.finished_qty_kg, 1) }}</div>
        </div>
        <div class="cv-stat-card cv-stat-card-amber">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiCpk }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-amber)">{{ fmt(b.cost_per_kg, 4) }}</div>
        </div>
        <div class="cv-stat-card">
          <div style="font-size:0.68rem;color:var(--cv-text-muted);margin-bottom:0.2rem">{{ t.kpiYield }}</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--cv-blue-text)">{{ yieldPct === null ? '—' : fmt(yieldPct, 1) + '%' }}</div>
        </div>
      </div>

      <!-- Details -->
      <div class="cv-card mb-4" style="padding:1.25rem">
        <div class="cv-section-title">{{ t.details }}</div>
        <div class="grid gap-4" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
          <div><div class="cv-label">{{ t.farm }}</div><div style="color:var(--cv-text-primary);font-weight:600">{{ pick(b.farm_name_en, b.farm_name_ar) }}</div></div>
          <div><div class="cv-label">{{ t.location }}</div><div style="color:var(--cv-text-primary);font-weight:600">{{ pick(b.location_name_en, b.location_name_ar) }}</div></div>
          <div><div class="cv-label">{{ t.mix }}</div><div style="color:var(--cv-text-primary);font-weight:600">{{ pick(b.output_name_en, b.output_name_ar) }}</div></div>
          <div><div class="cv-label">{{ t.startDate }}</div><div style="color:var(--cv-text-primary)">{{ b.start_date }}</div></div>
          <div v-if="b.finish_date"><div class="cv-label">{{ t.finishDate }}</div><div style="color:var(--cv-text-primary)">{{ b.finish_date }}</div></div>
          <div v-if="b.created_by_name"><div class="cv-label">{{ t.createdBy }}</div><div style="color:var(--cv-text-primary)">{{ b.created_by_name }}</div></div>
        </div>
        <div v-if="b.notes" style="margin-top:1rem">
          <div class="cv-label">{{ t.notes }}</div>
          <div style="color:var(--cv-text-secondary);white-space:pre-line">{{ b.notes }}</div>
        </div>
      </div>

      <!-- Ingredients -->
      <div class="cv-card mb-4" style="padding:0;overflow:hidden">
        <div style="padding:1rem 1.25rem 0.5rem"><div class="cv-section-title" style="margin:0">{{ t.ingredients }}</div></div>
        <div style="overflow-x:auto">
          <table class="cv-table">
            <thead>
              <tr>
                <th class="text-start">{{ t.item }}</th>
                <th class="text-end">{{ t.qty }}</th>
                <th class="text-end">{{ t.unitCost }}</th>
                <th class="text-end">{{ t.lineCost }}</th>
                <th class="text-start">{{ t.account }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in b.lines" :key="l.id">
                <td class="text-start" style="font-weight:600;color:var(--cv-text-primary)">{{ pick(l.name_en, l.name_ar) }}</td>
                <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">{{ fmt(l.qty, 3) }} <span style="font-size:0.7rem;color:var(--cv-text-muted);font-weight:400">{{ l.unit }}</span></td>
                <td class="text-end" style="color:var(--cv-blue-text)">{{ l.unit_cost === null ? '—' : fmt(l.unit_cost, 4) }}</td>
                <td class="text-end" style="color:var(--cv-blue-text);font-weight:600">{{ l.total_cost === null ? '—' : fmt(l.total_cost) }}</td>
                <td class="text-start" style="font-family:monospace;color:var(--cv-text-muted)">{{ l.account_code ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="b.status === 'draft'" style="padding:0.6rem 1.25rem;font-size:0.72rem;color:var(--cv-text-muted)">{{ t.notCosted }}</div>
      </div>

      <!-- Journal entries -->
      <div v-if="b.start_je || b.finish_je || b.cancel_je" class="cv-card" style="padding:1.25rem">
        <div class="cv-section-title">{{ t.journals }}</div>
        <div class="flex flex-col gap-2">
          <div v-if="b.start_je" class="flex items-center justify-between gap-3">
            <span style="color:var(--cv-text-secondary)">{{ t.jStart }}</span>
            <Link :href="route('journal-entries.show', b.start_je.id)" style="font-family:monospace;color:var(--cv-amber);font-weight:700">{{ b.start_je.reference }}</Link>
          </div>
          <div v-if="b.finish_je" class="flex items-center justify-between gap-3">
            <span style="color:var(--cv-text-secondary)">{{ t.jFinish }}</span>
            <Link :href="route('journal-entries.show', b.finish_je.id)" style="font-family:monospace;color:var(--cv-amber);font-weight:700">{{ b.finish_je.reference }}</Link>
          </div>
          <div v-if="b.cancel_je" class="flex items-center justify-between gap-3">
            <span style="color:var(--cv-text-secondary)">{{ t.jCancel }}</span>
            <Link :href="route('journal-entries.show', b.cancel_je.id)" style="font-family:monospace;color:var(--cv-amber);font-weight:700">{{ b.cancel_je.reference }}</Link>
          </div>
        </div>
      </div>

    </div>

    <!-- Finish modal -->
    <Teleport to="body">
      <div v-if="finishOpen" class="cv-modal-backdrop" @click.self="finishOpen = false">
        <div class="cv-modal" style="max-width:460px" :dir="isRtl ? 'rtl' : 'ltr'">
          <div class="cv-modal-header">
            <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">{{ t.finTitle }}</div>
          </div>
          <div class="cv-modal-body">
            <label class="cv-label">{{ t.finQtyLabel }}</label>
            <input v-model="finishQty" type="number" step="0.001" min="0" class="cv-input" autofocus />
            <div style="font-size:0.72rem;color:var(--cv-text-muted);margin:0.3rem 0 0.8rem">{{ t.finHint }}</div>

            <label class="cv-label">{{ t.finDate }}</label>
            <input v-model="finishDate" type="date" class="cv-input" />

            <div v-if="previewCpk !== null" style="margin-top:0.9rem;font-size:0.85rem;color:var(--cv-text-secondary)">
              {{ t.finPreview }}:
              <strong style="color:var(--cv-amber)">{{ fmt(previewCpk, 4) }} {{ t.egp }}</strong>
            </div>
            <div v-if="moreThanIngredients" class="cv-alert cv-alert-warning" style="margin-top:0.7rem;font-size:0.78rem">{{ t.warnMore }}</div>
            <div v-if="serverErrors.length" class="cv-alert cv-alert-danger" style="margin-top:0.7rem;font-size:0.8rem">
              <div v-for="(e, i) in serverErrors" :key="i">{{ e }}</div>
            </div>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="finishOpen = false">{{ t.close }}</button>
            <button class="cv-btn cv-btn-amber cv-btn-sm" :disabled="busy || !(Number(finishQty) > 0)" @click="doFinish">
              {{ busy ? t.finBusy : t.finBtn }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Cancel modal -->
    <Teleport to="body">
      <div v-if="cancelOpen" class="cv-modal-backdrop" @click.self="cancelOpen = false">
        <div class="cv-modal" style="max-width:440px" :dir="isRtl ? 'rtl' : 'ltr'">
          <div class="cv-modal-header">
            <div class="flex items-center gap-2">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--cv-danger-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cv-danger-text)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              </div>
              <div style="font-weight:700;font-size:1rem;color:var(--cv-text-primary)">{{ t.canTitle }}</div>
            </div>
          </div>
          <div class="cv-modal-body">
            <div class="cv-alert-danger" style="font-size:0.82rem">{{ t.canMsg }}</div>
          </div>
          <div class="cv-modal-footer">
            <button class="cv-btn cv-btn-ghost cv-btn-sm" @click="cancelOpen = false">{{ t.close }}</button>
            <button class="cv-btn cv-btn-danger cv-btn-sm" :disabled="busy" @click="doCancel">
              {{ busy ? t.canBusy : t.canBtn }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>
