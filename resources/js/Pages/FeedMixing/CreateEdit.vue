<script setup>
/**
 * FeedMixing/CreateEdit.vue — VERO Cattle Management
 * One component for create AND edit (mode prop). Drafts only.
 *
 * 📌 METHODOLOGY REFERENCE: FeedMixingWip (#53)
 *
 * COMPONENT PLAN
 *  1. Props: mode, batch|null, copy|null, farms[], locations[], items[], mixItems[]
 *  2. Actions: save draft (POST on create, PUT on edit). Start / Finish happen on Show.vue
 *  3. Sections: (1) Farm, location, date  (2) Finished mix  (3) Ingredients  (4) Notes
 *  4. Filters: location list follows the chosen farm; ingredient list hides picked items
 *  5. Permissions: route-level (create feed / edit feed) — enforced by the server
 *  6. Mobile/RTL: sections stack on narrow screens; dir from useLocale
 *
 * The cost figures on this page are ESTIMATES for the user's information.
 * The server recalculates every cost from the stock WAC when the batch is started.
 */
import { ref, computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale, localToday } from '@/Composables/useLocale'
defineOptions({ layout: AuthenticatedLayout })

const { locale: lang, isRtl, n } = useLocale()

const props = defineProps({
    mode:      { type: String, default: 'create' },
    batch:     { type: Object, default: null },
    copy:      { type: Object, default: null },
    farms:     { type: Array,  default: () => [] },
    locations: { type: Array,  default: () => [] },
    items:     { type: Array,  default: () => [] },
    mixItems:  { type: Array,  default: () => [] },
})

const isEdit = computed(() => props.mode === 'edit')

// ── Form — declared BEFORE any watch that references it ───────────
const seed = props.batch ?? props.copy ?? {}
const today = localToday()

const form = useForm({
    farm_id:        seed.farm_id        ?? (props.farms.length === 1 ? props.farms[0].id : ''),
    location_id:    seed.location_id    ?? '',
    output_item_id: seed.output_item_id ?? '',
    reference:      props.batch?.reference ?? '',
    start_date:     props.batch?.start_date ?? today,
    notes:          props.batch?.notes ?? '',
    lines: (seed.lines?.length ? seed.lines : [{ item_id: '', qty: '' }])
        .map(l => ({ item_id: l.item_id ?? '', qty: l.qty ?? '' })),
})

const dict = {
    en: {
        eyebrow: 'Feed Mixing',
        titleNew: 'New Mixing Batch', titleEdit: 'Edit Draft Batch',
        sub: 'Draft only — nothing leaves stock until you press Start on the next page',
        s1: 'Farm, location and date', s1sub: 'Where the mixing happens. Ingredients are taken from this location, and the finished mix returns to it.',
        s2: 'Finished mix', s2sub: 'The inventory item the finished mix is stored as',
        s3: 'Ingredients', s3sub: 'Items taken from stock and mixed together',
        s4: 'Notes',
        farm: 'Farm', location: 'Location', startDate: 'Start date', reference: 'Reference',
        refHint: 'Leave empty to generate automatically',
        pickFarm: 'Select farm…', pickLocation: 'Select location…', pickMix: 'Select the finished mix item…',
        mixHint: 'Not in the list? First create the mix once in Inventory with category “Feed”.',
        noMix: 'No feed items found. Create the finished mix item in Inventory first (category: Feed).',
        item: 'Ingredient', qty: 'Quantity', available: 'In stock', avgCost: 'Avg cost', estCost: 'Est. cost',
        pickItem: 'Select ingredient…', addLine: 'Add ingredient', removeLine: 'Remove',
        notEnough: 'More than available', noStock: 'No stock here',
        totalKg: 'Ingredients (kg)', totalCost: 'Estimated batch cost',
        estNote: 'Estimate only — the final cost is calculated by the system from stock cost when you press Start.',
        notes: 'Notes', notesPh: 'Optional',
        save: 'Save draft', saving: 'Saving…', cancel: 'Cancel', egp: 'EGP',
    },
    ar: {
        eyebrow: 'خلط الأعلاف',
        titleNew: 'دفعة خلط جديدة', titleEdit: 'تعديل مسودة الدفعة',
        sub: 'مسودة فقط — لا يخرج شيء من المخزون حتى تضغط «ابدأ» في الصفحة التالية',
        s1: 'المزرعة والموقع والتاريخ', s1sub: 'مكان الخلط. تُسحب المكونات من هذا الموقع ويعود إليه العلف المخلوط.',
        s2: 'العلف المخلوط', s2sub: 'صنف المخزون الذي سيُحفظ به الناتج',
        s3: 'المكونات', s3sub: 'الأصناف التي تُسحب من المخزون وتُخلط معًا',
        s4: 'ملاحظات',
        farm: 'المزرعة', location: 'الموقع', startDate: 'تاريخ البدء', reference: 'المرجع',
        refHint: 'اتركه فارغًا لإنشائه تلقائيًا',
        pickFarm: 'اختر المزرعة…', pickLocation: 'اختر الموقع…', pickMix: 'اختر صنف العلف المخلوط…',
        mixHint: 'غير موجود في القائمة؟ أنشئ صنف الخلطة أولًا في المخزون بتصنيف «علف».',
        noMix: 'لا توجد أصناف علف. أنشئ صنف العلف المخلوط في المخزون أولًا (التصنيف: علف).',
        item: 'المكوّن', qty: 'الكمية', available: 'المتوفر', avgCost: 'متوسط التكلفة', estCost: 'التكلفة التقديرية',
        pickItem: 'اختر المكوّن…', addLine: 'إضافة مكوّن', removeLine: 'حذف',
        notEnough: 'أكثر من المتوفر', noStock: 'لا يوجد مخزون هنا',
        totalKg: 'المكونات (كجم)', totalCost: 'تكلفة الدفعة التقديرية',
        estNote: 'تقدير فقط — يحسب النظام التكلفة النهائية من تكلفة المخزون عند الضغط على «ابدأ».',
        notes: 'ملاحظات', notesPh: 'اختياري',
        save: 'حفظ كمسودة', saving: 'جارٍ الحفظ…', cancel: 'إلغاء', egp: 'ج.م',
    },
}
const t = computed(() => dict[lang.value] ?? dict.en)

// ── Locations follow the farm (farm-level + central warehouses) ───
const visibleLocations = computed(() =>
    props.locations.filter(l => l.farm_id === null || String(l.farm_id) === String(form.farm_id))
)
watch(() => form.farm_id, () => {
    if (!visibleLocations.value.some(l => String(l.id) === String(form.location_id))) {
        form.location_id = ''
    }
})

// ── Ingredient helpers ────────────────────────────────────────────
function pick(en, ar) { return lang.value === 'ar' ? (ar || en) : (en || ar) }
function itemById(id) { return props.items.find(i => String(i.id) === String(id)) }

function optionsFor(index) {
    const takenElsewhere = form.lines
        .filter((_, i) => i !== index)
        .map(l => String(l.item_id))
    return props.items.filter(i =>
        String(i.id) !== String(form.output_item_id) && !takenElsewhere.includes(String(i.id))
    )
}
function stockFor(itemId) {
    const it = itemById(itemId)
    if (!it || !form.location_id) return null
    return it.stocks.find(s => String(s.location_id) === String(form.location_id)) ?? null
}
function lineCost(line) {
    const s = stockFor(line.item_id)
    if (!s || !line.qty) return 0
    return Number(line.qty) * Number(s.unit_cost)
}
function overStock(line) {
    const s = stockFor(line.item_id)
    return !!(s && line.qty && Number(line.qty) > Number(s.qty))
}
const totalKg = computed(() => form.lines.reduce((sum, l) => {
    const it = itemById(l.item_id)
    return it && it.unit === 'kg' ? sum + (Number(l.qty) || 0) : sum
}, 0))
const totalCost = computed(() => form.lines.reduce((sum, l) => sum + lineCost(l), 0))

function addLine() { form.lines.push({ item_id: '', qty: '' }) }
function removeLine(i) {
    if (form.lines.length > 1) form.lines.splice(i, 1)
    else form.lines[0] = { item_id: '', qty: '' }
}

function fmt(v, dec = 2) {
    return Number(v ?? 0).toLocaleString(lang.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: dec, maximumFractionDigits: dec,
    })
}

function errorFor(path) { return form.errors[path] }
const generalErrors = computed(() =>
    ['lines', 'accounts', 'status'].map(k => form.errors[k]).filter(Boolean)
)

// ── Submit ────────────────────────────────────────────────────────
function submit() {
    if (isEdit.value) form.put(route('feed.mixing.update', props.batch.id))
    else form.post(route('feed.mixing.store'))
}
</script>

<template>
  <div :dir="isRtl ? 'rtl' : 'ltr'">

    <!-- Hero with live preview -->
    <div class="cv-page-hero">
      <div class="cv-page-hero-eyebrow">{{ t.eyebrow }}</div>
      <div class="cv-page-hero-title">{{ isEdit ? t.titleEdit : t.titleNew }}</div>
      <div class="cv-page-hero-sub">
        {{ form.reference || (isEdit ? batch?.reference : '') || t.sub }}
        <template v-if="totalCost > 0"> · {{ t.totalCost }}: {{ fmt(totalCost) }} {{ t.egp }}</template>
      </div>
    </div>

    <div class="cv-page" style="max-width:980px">

      <div v-if="generalErrors.length" class="cv-alert cv-alert-danger mb-4">
        <div v-for="(e, i) in generalErrors" :key="i">{{ e }}</div>
      </div>

      <!-- 1 — Farm, location, date -->
      <div class="cv-card mb-4" style="padding:1.25rem">
        <div class="flex items-center gap-3 mb-3">
          <div class="fm-num">1</div>
          <div>
            <div class="cv-section-title" style="margin:0">{{ t.s1 }}</div>
            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s1sub }}</div>
          </div>
        </div>
        <div class="grid gap-3" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
          <div>
            <label class="cv-label">{{ t.farm }}</label>
            <select v-model="form.farm_id" class="cv-select" :class="{ 'cv-select--error': errorFor('farm_id') }">
              <option value="">{{ t.pickFarm }}</option>
              <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f, 'name') }}</option>
            </select>
            <div v-if="errorFor('farm_id')" class="cv-field-error">{{ errorFor('farm_id') }}</div>
          </div>
          <div>
            <label class="cv-label">{{ t.location }}</label>
            <select v-model="form.location_id" class="cv-select" :class="{ 'cv-select--error': errorFor('location_id') }">
              <option value="">{{ t.pickLocation }}</option>
              <option v-for="l in visibleLocations" :key="l.id" :value="l.id">{{ n(l, 'name') }}</option>
            </select>
            <div v-if="errorFor('location_id')" class="cv-field-error">{{ errorFor('location_id') }}</div>
          </div>
          <div>
            <label class="cv-label">{{ t.startDate }}</label>
            <input v-model="form.start_date" type="date" class="cv-input" :class="{ 'cv-input--error': errorFor('start_date') }" />
            <div v-if="errorFor('start_date')" class="cv-field-error">{{ errorFor('start_date') }}</div>
          </div>
          <div>
            <label class="cv-label">{{ t.reference }}</label>
            <input v-model="form.reference" type="text" maxlength="60" class="cv-input" style="font-family:monospace" />
            <div style="font-size:0.7rem;color:var(--cv-text-muted);margin-top:0.2rem">{{ t.refHint }}</div>
          </div>
        </div>
      </div>

      <!-- 2 — Finished mix -->
      <div class="cv-card mb-4" style="padding:1.25rem">
        <div class="flex items-center gap-3 mb-3">
          <div class="fm-num">2</div>
          <div>
            <div class="cv-section-title" style="margin:0">{{ t.s2 }}</div>
            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s2sub }}</div>
          </div>
        </div>
        <div v-if="!mixItems.length" class="cv-alert cv-alert-warning">{{ t.noMix }}</div>
        <div v-else>
          <select v-model="form.output_item_id" class="cv-select" :class="{ 'cv-select--error': errorFor('output_item_id') }">
            <option value="">{{ t.pickMix }}</option>
            <option v-for="m in mixItems" :key="m.id" :value="m.id">{{ pick(m.name_en, m.name_ar) }} ({{ m.unit }})</option>
          </select>
          <div v-if="errorFor('output_item_id')" class="cv-field-error">{{ errorFor('output_item_id') }}</div>
          <div style="font-size:0.72rem;color:var(--cv-text-muted);margin-top:0.3rem">{{ t.mixHint }}</div>
        </div>
      </div>

      <!-- 3 — Ingredients -->
      <div class="cv-card mb-4" style="padding:1.25rem">
        <div class="flex items-center gap-3 mb-3">
          <div class="fm-num">3</div>
          <div>
            <div class="cv-section-title" style="margin:0">{{ t.s3 }}</div>
            <div style="font-size:0.75rem;color:var(--cv-text-muted)">{{ t.s3sub }}</div>
          </div>
        </div>

        <div v-for="(line, i) in form.lines" :key="i" class="fm-line">
          <div>
            <label v-if="i === 0" class="cv-label">{{ t.item }}</label>
            <select v-model="line.item_id" class="cv-select" :class="{ 'cv-select--error': errorFor(`lines.${i}.item_id`) }">
              <option value="">{{ t.pickItem }}</option>
              <option v-for="it in optionsFor(i)" :key="it.id" :value="it.id">
                {{ pick(it.name_en, it.name_ar) }} ({{ it.unit }})
              </option>
            </select>
            <div v-if="errorFor(`lines.${i}.item_id`)" class="cv-field-error">{{ errorFor(`lines.${i}.item_id`) }}</div>
          </div>

          <div>
            <label v-if="i === 0" class="cv-label">{{ t.qty }}</label>
            <input v-model="line.qty" type="number" step="0.001" min="0" class="cv-input"
                   :class="{ 'cv-input--error': errorFor(`lines.${i}.qty`) || overStock(line) }" />
            <div v-if="errorFor(`lines.${i}.qty`)" class="cv-field-error">{{ errorFor(`lines.${i}.qty`) }}</div>
          </div>

          <div class="fm-info">
            <label v-if="i === 0" class="cv-label">{{ t.available }} / {{ t.avgCost }}</label>
            <template v-if="line.item_id && form.location_id">
              <template v-if="stockFor(line.item_id)">
                <span :style="{ color: overStock(line) ? 'var(--cv-danger-text)' : 'var(--cv-blue-text)', fontWeight: 600 }">
                  {{ fmt(stockFor(line.item_id).qty, 1) }}
                </span>
                <span style="color:var(--cv-text-muted)"> · {{ fmt(stockFor(line.item_id).unit_cost, 4) }}</span>
                <div v-if="overStock(line)" style="font-size:0.7rem;color:var(--cv-danger-text)">{{ t.notEnough }}</div>
              </template>
              <span v-else style="color:var(--cv-danger-text);font-size:0.75rem">{{ t.noStock }}</span>
            </template>
            <span v-else style="color:var(--cv-text-muted)">—</span>
          </div>

          <div class="fm-info" style="text-align:end">
            <label v-if="i === 0" class="cv-label">{{ t.estCost }}</label>
            <span style="color:var(--cv-blue-text);font-weight:600">{{ lineCost(line) ? fmt(lineCost(line)) : '—' }}</span>
          </div>

          <div class="fm-remove">
            <button type="button" class="cv-action-btn cv-action-btn-danger" :title="t.removeLine" @click="removeLine(i)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="13" height="13"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>

        <div class="flex items-center justify-between flex-wrap gap-3 mt-3">
          <button type="button" class="cv-btn cv-btn-ghost cv-btn-sm" @click="addLine">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="14" height="14"><path d="M12 5v14M5 12h14"/></svg>
            {{ t.addLine }}
          </button>
          <div style="text-align:end">
            <div style="font-size:0.78rem;color:var(--cv-text-muted)">
              {{ t.totalKg }}: <strong style="color:var(--cv-blue-text)">{{ fmt(totalKg, 1) }}</strong>
              &nbsp;·&nbsp; {{ t.totalCost }}: <strong style="color:var(--cv-blue-text)">{{ fmt(totalCost) }} {{ t.egp }}</strong>
            </div>
          </div>
        </div>
        <div style="font-size:0.7rem;color:var(--cv-text-muted);margin-top:0.5rem">{{ t.estNote }}</div>
      </div>

      <!-- 4 — Notes -->
      <div class="cv-card mb-5" style="padding:1.25rem">
        <div class="flex items-center gap-3 mb-3">
          <div class="fm-num">4</div>
          <div class="cv-section-title" style="margin:0">{{ t.s4 }}</div>
        </div>
        <textarea v-model="form.notes" rows="3" class="cv-textarea" :placeholder="t.notesPh"></textarea>
      </div>

      <!-- Footer -->
      <div class="flex gap-2">
        <button type="button" class="cv-btn cv-btn-amber" :disabled="form.processing" @click="submit">
          {{ form.processing ? t.saving : t.save }}
        </button>
        <Link :href="isEdit ? route('feed.mixing.show', batch.id) : route('feed.mixing.index')" class="cv-btn cv-btn-ghost">
          {{ t.cancel }}
        </Link>
      </div>

    </div>
  </div>
</template>

<style scoped>
/* Only elements that are new and not in app.css */
.fm-num {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; color: #fff;
  background: linear-gradient(135deg, var(--cv-amber), var(--cv-green-bright));
}
.fm-line {
  display: grid;
  grid-template-columns: minmax(180px, 2.2fr) minmax(110px, 1fr) minmax(130px, 1.3fr) minmax(90px, 1fr) 36px;
  gap: 0.75rem;
  align-items: end;
  margin-bottom: 0.6rem;
}
.fm-info { font-size: 0.82rem; padding-bottom: 0.45rem; }
.fm-remove { padding-bottom: 0.2rem; }
@media (max-width: 720px) {
  .fm-line { grid-template-columns: 1fr 1fr; align-items: start; padding-bottom: 0.6rem; border-bottom: 1px solid var(--cv-border); }
  .fm-line > :first-child { grid-column: 1 / -1; }
}
</style>
