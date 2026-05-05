<script setup>
// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Animals/CreateEdit.vue  (v2)
//
//  COMPONENT PLAN:
//  ─────────────────────────────────────────────────────────────────
//  DATA FROM CONTROLLER (props shape):
//    animal    → formatAnimal() object (edit mode only)
//    farms     → [{id, name_en, name_ar, company_id}]
//    lots      → [{id, name, farm_id}]
//    suppliers → [{id, name_en, name_ar}]
//    user.permissions  → string
//
//  ENTRY MODES (create only):
//    A → Bulk / Common Info + Average Weight
//    B → Bulk / Common Info + Per-Unit Weights (table + Excel upload)
//    C → Single Animal (original form, always used in edit)
//
//  ACTIONS:
//    Mode A/B → POST animals/bulk  (animals.storeBulk)
//    Mode C   → POST animals       (animals.store)  or PUT (edit)
//
//  BILINGUAL RULE:
//    locale === 'ar' → Arabic labels/placeholders, dir="rtl"
//    locale === 'en' → English labels/placeholders, dir="ltr"
//    Both locales submitted together on all bilingual fields
//    Animal fields (tag, breed…) are single-language operational
//
//  METHODOLOGY REFERENCE: BulkAnimalPriceAllocation
//    Mode A: avgPrice = totalPrice ÷ count
//    Mode B: price_i  = totalPrice × (W_i ÷ ΣW)   [weight-proportional]
//
//  PERMISSION GUARDS: create animals / edit animals
//  MOBILE / RTL: isRtl → dir="rtl", 1-col grid on mobile
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch, nextTick } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    animal:    { type: Object, default: null },
    farms:     { type: Array,  default: () => [] },
    lots:      { type: Array,  default: () => [] },
    suppliers: { type: Array,  default: () => [] },
    po:        { type: Object, default: null },   // PO context — injected by ProcurementController::receive()
    mode:      { type: String, default: null },   // 'purchase' when coming from Receive Animals button
})

const { locale, isRtl, n } = useLocale()
const isEdit = computed(() => !!props.animal)
// Purchase mode: arrived from [Receive Animals] button on a PO
// All purchased animals go through this path — source is locked, fields pre-filled from PO
const isPurchaseMode = computed(() => props.mode === 'purchase' && !!props.po)

// ── Entry mode: 'A' | 'B' | 'C' ──────────────────────────────
// Edit always forces C; create defaults to A
const entryMode = ref(isEdit.value ? 'C' : 'A')

// ── Labels (bilingual) ────────────────────────────────────────
const L = computed(() => locale.value === 'ar' ? {
    // Page chrome
    eyebrow:            'إدارة القطيع',
    titleCreate:        'إضافة ماشيةات',
    titleEdit:          'تعديل بيانات الماشية',
    subCreate:          'اختر طريقة الإدخال وأدخل بيانات الماشيةات',
    subEdit:            'تعديل بيانات الماشية المحدد',
    back:               'رجوع',
    save:               'حفظ التغييرات',
    create:             'إضافة الماشيةات',

    // Mode tabs
    modeA:              'إدخال جماعي — وزن متوسط',
    modeB:              'إدخال جماعي — أوزان فردية',
    modeC:              'إدخال ماشية واحد',

    // Section titles
    sCount:             'عدد الماشيةات',
    sCommon:            'البيانات المشتركة',
    sIdentity:          'الهوية',
    sOrigin:            'المصدر والموقع',
    sPhoto:             'الصورة',
    sStatus:            'الحالة والملاحظات',
    sLineage:           'النسب',
    sTagRange:          'نطاق أرقام التعريف والرقائق',
    sWeightTable:       'جدول الأوزان الفردية',
    sPricing:           'التسعير والشحن',

    // Count
    animalCount:        'عدد الماشيةات *',
    animalCountPh:      'مثال: 200',

    // Common fields
    sex:                'الجنس *',
    sexSelect:          '-- اختر الجنس --',
    sexMale:            'ذكر',
    sexFemale:          'أنثى',
    sexCastrated:       'مخصي',
    breed:              'السلالة',
    breedPh:            'مثال: فريزيان، عربي...',
    color:              'اللون (اختياري)',
    colorPh:            'مثال: بني، أبلق...',
    dob:                'تاريخ الميلاد (اختياري)',
    dobEstimated:       'تاريخ تقريبي',
    source:             'المصدر *',
    sourcePurchased:    'مشترى',
    sourceBorn:         'مولود بالمزرعة',
    farm:               'المزرعة *',
    farmSelect:         '-- اختر المزرعة --',
    lot:                'المجموعة *',
    lotSelect:          '-- اختر المجموعة --',
    lotEmpty:           'اختر مزرعة أولاً',
    purchaseDate:       'تاريخ الشراء *',
    supplier:           'المورد',
    supplierSelect:     '-- اختر المورد --',

    // Pricing
    totalPrice:         'إجمالي سعر الشراء (جنيه) *',
    totalPricePh:       '0.00',
    avgPricePerAnimal:  'متوسط السعر للماشية (جنيه)',
    avgWeightPerAnimal: 'متوسط الوزن للماشية (كجم) *',
    avgWeightPh:        '0.0',
    totalFreight:       'إجمالي تكلفة الشحن (جنيه)',
    totalFreightPh:     '0.00',
    freightPerAnimal:   'تكلفة الشحن للماشية (جنيه)',

    // Status
    status:             'الحالة',
    statusActive:       'نشط',
    statusSold:         'مباع',
    statusSlaughtered:  'مذبوح',
    statusDead:         'نافق',
    statusQuarantine:   'حجر صحي',
    statusTransferred:  'محوَّل',
    statusDate:         'تاريخ تغيير الحالة',
    notes:              'ملاحظات',
    notesPh:            'أي ملاحظات إضافية...',

    // Tag range
    tagPrefix:          'بادئة رقم التعريف *',
    tagPrefixPh:        'مثال: TAG-',
    tagFrom:            'من رقم *',
    tagFromPh:          '1',
    tagTo:              'إلى رقم (تلقائي)',
    rfidPrefix:         'بادئة RFID *',
    rfidPrefixPh:       'مثال: RFID-',
    rfidFrom:           'من رقم *',
    rfidFromPh:         '1',
    rfidTo:             'إلى رقم (تلقائي)',

    // Table
    colNo:              '#',
    colTag:             'رقم التعريف',
    colRfid:            'رقم RFID',
    colWeight:          'الوزن (كجم)',
    colPrice:           'السعر/رأس (جنيه)',
    colFreight:         'الشحن/رأس (جنيه)',
    colCopy:            'نسخ',
    weightPh:           '0.0',
    copyDown:           'نسخ للأسفل',
    totalWeight:        'إجمالي الوزن',

    // Excel
    downloadTemplate:   'تنزيل نموذج Excel',
    uploadExcel:        'رفع ملف Excel',
    excelHint:          'xlsx أو xls — أدخل الأوزان في العمود المخصص',

    // Single animal
    tag:                'رقم التعريف *',
    tagPh:              'مثال: TAG-001',
    rfid:               'رقم RFID',
    rfidPh:             'رقم الرقاقة الإلكترونية',
    markings:           'العلامات المميزة',
    markingsPh:         'أي علامات أو وسوم مميزة',
    purchasePrice:      'سعر الشراء (جنيه)',
    purchaseWt:         'وزن الشراء (كجم)',
    freight:            'تكلفة الشحن (جنيه)',
    damId:              'رقم الأم',
    damPh:              'اختياري — للمواليد فقط',
    sireId:             'رقم الأب',
    sirePh:             'اختياري — للمواليد فقط',
    photoUpload:        'رفع صورة الماشية',
    photoChange:        'تغيير الصورة',
    photoClear:         'إزالة',
    photoHint:          'JPG أو PNG — الحد الأقصى 2 ميجابايت',
    autoCalc:           'محسوب تلقائياً',
    calculating:        'جارٍ الحساب...',
} : {
    // Page chrome
    eyebrow:            'Herd Management',
    titleCreate:        'Add Animals',
    titleEdit:          'Edit Animal',
    subCreate:          'Choose an entry mode and fill in the animal data',
    subEdit:            'Update this animal\'s details',
    back:               'Back',
    save:               'Save Changes',
    create:             'Add Animals',

    // Mode tabs
    modeA:              'Bulk — Average Weight',
    modeB:              'Bulk — Per-Unit Weights',
    modeC:              'Single Animal',

    // Section titles
    sCount:             'Animal Count',
    sCommon:            'Common Information',
    sIdentity:          'Identity',
    sOrigin:            'Origin & Placement',
    sPhoto:             'Photo',
    sStatus:            'Status & Notes',
    sLineage:           'Lineage',
    sTagRange:          'Tag & RFID Number Range',
    sWeightTable:       'Per-Unit Weight Table',
    sPricing:           'Pricing & Freight',

    // Count
    animalCount:        'Animal Count *',
    animalCountPh:      'e.g. 200',

    // Common fields
    sex:                'Sex *',
    sexSelect:          '-- Select Sex --',
    sexMale:            'Male',
    sexFemale:          'Female',
    sexCastrated:       'Castrated',
    breed:              'Breed',
    breedPh:            'e.g. Friesian, Angus...',
    color:              'Color (Optional)',
    colorPh:            'e.g. Brown, Pied...',
    dob:                'Date of Birth (Optional)',
    dobEstimated:       'Date is estimated',
    source:             'Source *',
    sourcePurchased:    'Purchased',
    sourceBorn:         'Born on Farm',
    farm:               'Farm *',
    farmSelect:         '-- Select Farm --',
    lot:                'Lot *',
    lotSelect:          '-- Select Lot --',
    lotEmpty:           'Select a farm first',
    purchaseDate:       'Purchase Date *',
    supplier:           'Supplier',
    supplierSelect:     '-- Select Supplier --',

    // Pricing
    totalPrice:         'Total Purchase Price (EGP) *',
    totalPricePh:       '0.00',
    avgPricePerAnimal:  'Average Price Per Animal (EGP)',
    avgWeightPerAnimal: 'Average Weight Per Animal (kg) *',
    avgWeightPh:        '0.0',
    totalFreight:       'Total Freight Cost (EGP)',
    totalFreightPh:     '0.00',
    freightPerAnimal:   'Per Animal Freight Cost (EGP)',

    // Status
    status:             'Status',
    statusActive:       'Active',
    statusSold:         'Sold',
    statusSlaughtered:  'Slaughtered',
    statusDead:         'Dead',
    statusQuarantine:   'Quarantine',
    statusTransferred:  'Transferred',
    statusDate:         'Status Change Date',
    notes:              'Notes',
    notesPh:            'Any additional notes...',

    // Tag range
    tagPrefix:          'Tag Prefix *',
    tagPrefixPh:        'e.g. TAG-',
    tagFrom:            'From Number *',
    tagFromPh:          '1',
    tagTo:              'To Number (auto)',
    rfidPrefix:         'RFID Prefix *',
    rfidPrefixPh:       'e.g. RFID-',
    rfidFrom:           'From Number *',
    rfidFromPh:         '1',
    rfidTo:             'To Number (auto)',

    // Table
    colNo:              '#',
    colTag:             'Tag Number',
    colRfid:            'RFID Number',
    colWeight:          'Weight (kg)',
    colPrice:           'Price/Head (EGP)',
    colFreight:         'Freight/Head (EGP)',
    colCopy:            'Copy',
    weightPh:           '0.0',
    copyDown:           'Copy down',
    totalWeight:        'Total Weight',

    // Excel
    downloadTemplate:   'Download Excel Template',
    uploadExcel:        'Upload Excel',
    excelHint:          'xlsx or xls — fill in the Weight column',

    // Single animal
    tag:                'Tag Number *',
    tagPh:              'e.g. TAG-001',
    rfid:               'RFID Number',
    rfidPh:             'Electronic chip ID',
    markings:           'Distinguishing Markings',
    markingsPh:         'Any notable marks or brands',
    purchasePrice:      'Purchase Price (EGP)',
    purchaseWt:         'Entry Weight (kg)',
    freight:            'Freight Cost (EGP)',
    damId:              'Dam ID (Mother)',
    damPh:              'Optional — for born-on-farm only',
    sireId:             'Sire ID (Father)',
    sirePh:             'Optional — for born-on-farm only',
    photoUpload:        'Upload Animal Photo',
    photoChange:        'Change Photo',
    photoClear:         'Remove',
    photoHint:          'JPG or PNG — Max 2 MB',
    autoCalc:           'Auto-calculated',
    calculating:        'Calculating...',
})

// ══════════════════════════════════════════════════════════════
// SHARED COMMON FIELDS (Mode A & B)
// ══════════════════════════════════════════════════════════════
const bulk = ref({
    company_id:     props.po?.company_id ?? props.farms[0]?.company_id ?? '',
    count:          '',
    sex:            '',
    breed:          '',
    color:          '',
    dob:            '',
    dob_estimated:  false,
    source:         isPurchaseMode.value ? 'purchased' : 'born',  
    farm_id:        props.po?.farm_id    ?? '',
    lot_id:         props.po?.lot_id     ?? '',
    purchase_date:  props.po?.date       ?? '',
    supplier_id:    props.po?.supplier_id ?? '',
    total_price:    '',
    total_freight:  '',
    avg_weight:     props.po?.estimated_avg_weight_kg ?? '',
    status:         'active',
    status_date:    '',
    notes:          '',
    tag_prefix:     '',
    tag_from:       '',
    rfid_prefix:    '',
    rfid_from:      '',
})

const bulkErrors = ref({})

// ── Filtered lots by farm ──────────────────────────────────────
const filteredLots = computed(() => {
    if (!bulk.value.farm_id) return []
    return props.lots.filter(l => String(l.farm_id) === String(bulk.value.farm_id))
})
watch(() => bulk.value.farm_id, (farmId) => {
    bulk.value.lot_id = ''
    // keep company_id in sync when farm changes
    const farm = props.farms.find(f => String(f.id) === String(farmId))
    if (farm) bulk.value.company_id = farm.company_id
})

// ══════════════════════════════════════════════════════════════
// MODE A — AUTO CALCULATIONS
// ══════════════════════════════════════════════════════════════
// avgPricePerAnimal = totalPrice ÷ count
const avgPricePerAnimal = computed(() => {
    const p = parseFloat(bulk.value.total_price)
    const c = parseInt(bulk.value.count)
    if (!p || !c || c === 0) return null
    return (p / c).toFixed(2)
})

// freightPerAnimal = totalFreight ÷ count
const freightPerAnimal = computed(() => {
    const f = parseFloat(bulk.value.total_freight)
    const c = parseInt(bulk.value.count)
    if (!f || !c || c === 0) return null
    return (f / c).toFixed(2)
})

// Tag "To" number
const tagTo = computed(() => {
    const from = parseInt(bulk.value.tag_from)
    const c    = parseInt(bulk.value.count)
    if (!from || !c) return '—'
    return from + c - 1
})
const rfidTo = computed(() => {
    const from = parseInt(bulk.value.rfid_from)
    const c    = parseInt(bulk.value.count)
    if (!from || !c) return '—'
    return from + c - 1
})

// ══════════════════════════════════════════════════════════════
// MODE B — TABLE
// ══════════════════════════════════════════════════════════════
// rows array: [{tag, rfid, weight, pricePerHead, freightPerHead}]
const tableRows = ref([])

// Pad a number to match the digit length of tagFrom
function zeroPad(num, referenceNum) {
    const refStr = String(referenceNum)
    return String(num).padStart(refStr.length, '0')
}

// Generate rows whenever count or range inputs change
function generateRows() {
    const count   = parseInt(bulk.value.count)
    const tagFrom = parseInt(bulk.value.tag_from)
    const rfidFrom = parseInt(bulk.value.rfid_from)
    if (!count || count <= 0 || count > 5000) { tableRows.value = []; return }

    // Preserve existing weights if row count hasn't changed
    const existing = tableRows.value

    tableRows.value = Array.from({ length: count }, (_, i) => {
        const existingRow = existing[i]
        const tagNum  = isNaN(tagFrom)  ? (i + 1) : (tagFrom  + i)
        const rfidNum = isNaN(rfidFrom) ? (i + 1) : (rfidFrom + i)
        return {
            tag:  (bulk.value.tag_prefix  || '') + zeroPad(tagNum,  isNaN(tagFrom)  ? 1 : tagFrom),
            rfid: (bulk.value.rfid_prefix || '') + zeroPad(rfidNum, isNaN(rfidFrom) ? 1 : rfidFrom),
            weight: existingRow?.weight ?? '',
        }
    })
}

// Watch all range-affecting fields
watch(
    [() => bulk.value.count, () => bulk.value.tag_prefix, () => bulk.value.tag_from,
     () => bulk.value.rfid_prefix, () => bulk.value.rfid_from],
    generateRows,
    { deep: false }
)

// ── Weight-proportional price/freight (Mode B) ────────────────
// Methodology: BulkAnimalPriceAllocation — weight-proportional
// price_i = totalPrice × (W_i ÷ ΣW)
const sumWeights = computed(() => {
    return tableRows.value.reduce((acc, r) => acc + (parseFloat(r.weight) || 0), 0)
})

function rowPricePerHead(row) {
    const total = parseFloat(bulk.value.total_price) || 0
    const sw    = sumWeights.value
    const w     = parseFloat(row.weight) || 0
    if (sw === 0 || total === 0) return '0.00'
    return (total * w / sw).toFixed(2)
}

function rowFreightPerHead(row) {
    const total = parseFloat(bulk.value.total_freight) || 0
    const sw    = sumWeights.value
    const w     = parseFloat(row.weight) || 0
    if (sw === 0 || total === 0) return '0.00'
    return (total * w / sw).toFixed(2)
}

// ── Copy-down: propagate weight from row i downward ───────────
function copyDown(rowIndex) {
    const sourceWeight = tableRows.value[rowIndex]?.weight
    if (sourceWeight === '' || sourceWeight === undefined) return
    for (let i = rowIndex + 1; i < tableRows.value.length; i++) {
        tableRows.value[i].weight = sourceWeight
    }
}

// ══════════════════════════════════════════════════════════════
// EXCEL UPLOAD / DOWNLOAD (Mode B)
// ══════════════════════════════════════════════════════════════
const excelFileRef = ref(null)

// Download template: build CSV matching the table structure
function downloadTemplate() {
    const headers = [
        L.value.colNo,
        L.value.colTag,
        L.value.colRfid,
        L.value.colWeight,
        L.value.colPrice,
        L.value.colFreight,
    ]
    const rows = tableRows.value.map((r, i) => [
        i + 1,
        r.tag,
        r.rfid,
        '',    // weight — user fills this
        '',    // price  — calculated in Vue
        '',    // freight — calculated in Vue
    ])
    const csv = [headers, ...rows]
        .map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
        .join('\n')

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href     = url
    a.download = `animals_bulk_template_${Date.now()}.csv`
    a.click()
    URL.revokeObjectURL(url)
}

// Upload Excel/CSV and parse weights back into tableRows
async function handleExcelUpload(event) {
    const file = event.target.files?.[0]
    if (!file) return

    const ext = file.name.split('.').pop().toLowerCase()

    if (ext === 'csv') {
        // Native CSV parsing
        const text = await file.text()
        parseCSVIntoRows(text)
    } else if (ext === 'xlsx' || ext === 'xls') {
        // Use SheetJS if available, fallback to CSV message
        if (typeof XLSX !== 'undefined') {
            const buffer = await file.arrayBuffer()
            const wb     = XLSX.read(buffer, { type: 'array' })
            const ws     = wb.Sheets[wb.SheetNames[0]]
            const csv    = XLSX.utils.sheet_to_csv(ws)
            parseCSVIntoRows(csv)
        } else {
            // Dynamically load SheetJS
            await loadSheetJS()
            const buffer = await file.arrayBuffer()
            const wb     = window.XLSX.read(buffer, { type: 'array' })
            const ws     = wb.Sheets[wb.SheetNames[0]]
            const csv    = window.XLSX.utils.sheet_to_csv(ws)
            parseCSVIntoRows(csv)
        }
    }

    // Reset file input so same file can be re-uploaded
    if (excelFileRef.value) excelFileRef.value.value = ''
}

function loadSheetJS() {
    return new Promise((resolve, reject) => {
        if (window.XLSX) { resolve(); return }
        const s = document.createElement('script')
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'
        s.onload  = resolve
        s.onerror = reject
        document.head.appendChild(s)
    })
}

function parseCSVIntoRows(csvText) {
    const lines = csvText.trim().split('\n').filter(Boolean)
    if (lines.length < 2) return  // only header or empty

    // Find the weight column index from the header row
    const header = lines[0].split(',').map(c => c.replace(/^"|"$/g, '').trim())
    const weightColIndex = header.findIndex(h =>
        h === L.value.colWeight ||
        h.toLowerCase().includes('weight') ||
        h.includes('الوزن')
    )
    if (weightColIndex === -1) return

    const dataLines = lines.slice(1)
    dataLines.forEach((line, i) => {
        if (!tableRows.value[i]) return
        const cols   = line.split(',').map(c => c.replace(/^"|"$/g, '').trim())
        const weight = cols[weightColIndex]
        if (weight !== '' && !isNaN(parseFloat(weight))) {
            tableRows.value[i].weight = parseFloat(weight)
        }
    })
}

// ══════════════════════════════════════════════════════════════
// MODE C — SINGLE ANIMAL FORM (original, unchanged)
// ══════════════════════════════════════════════════════════════
const form = useForm({
    company_id:         props.animal?.company_id         ?? '',
    farm_id:            props.animal?.farm_id            ?? '',
    lot_id:             props.animal?.lot_id             ?? '',
    tag:                props.animal?.tag                ?? '',
    rfid:               props.animal?.rfid               ?? '',
    breed:              props.animal?.breed              ?? '',
    dob:                props.animal?.dob                ?? '',
    dob_estimated:      props.animal?.dob_estimated      ?? false,
    sex:                props.animal?.sex                ?? '',
    color:              props.animal?.color              ?? '',
    markings:           props.animal?.markings           ?? '',
    source:             props.animal?.source             ?? 'purchased',
    dam_id:             props.animal?.dam_id             ?? '',
    sire_id:            props.animal?.sire_id            ?? '',
    purchase_date:      props.animal?.purchase_date      ?? '',
    supplier_id:        props.animal?.supplier_id        ?? '',
    purchase_price:     props.animal?.purchase_price     ?? '',
    purchase_weight_kg: props.animal?.purchase_weight_kg ?? '',
    freight_cost:       props.animal?.freight_cost       ?? '',
    status:             props.animal?.status             ?? 'active',
    status_date:        props.animal?.status_date        ?? '',
    notes:              props.animal?.notes              ?? '',
    photo:              null,
})

// Filtered lots for single form
const filteredLotsSingle = computed(() => {
    if (!form.farm_id) return []
    return props.lots.filter(l => String(l.farm_id) === String(form.farm_id))
})
watch(() => form.farm_id, () => { form.lot_id = '' })

// Photo preview
const photoPreview = ref(props.animal?.photo ?? null)
function onPhotoChange(e) {
    const file = e.target.files?.[0]
    if (!file) return
    form.photo = file
    const reader = new FileReader()
    reader.onload = ev => { photoPreview.value = ev.target.result }
    reader.readAsDataURL(file)
}
function clearPhoto() {
    form.photo = null
    photoPreview.value = props.animal?.photo ?? null
}

// ══════════════════════════════════════════════════════════════
// SUBMIT
// ══════════════════════════════════════════════════════════════
const isSubmitting = ref(false)

function submit() {
    if (entryMode.value === 'C') {
        // Single animal — original behaviour
        if (isEdit.value) {
            form._method = 'PUT'
            form.post(route('animals.update', props.animal.id), {
                forceFormData: true,
            })
        } else {
            form.post(route('animals.store'), { forceFormData: true })
        }
        return
    }
    // Mode A or B — build payload array
    isSubmitting.value = true
    bulkErrors.value   = {}

    const count = parseInt(bulk.value.count)
    if (!count || count <= 0) {
        bulkErrors.value.count = locale.value === 'ar'
            ? 'أدخل عدداً صحيحاً من الماشيةات'
            : 'Enter a valid animal count'
        isSubmitting.value = false
        return
    }

    let animals = []

    if (entryMode.value === 'A') {
        // Mode A: same avg weight for all
        const tagFrom  = parseInt(bulk.value.tag_from)  || 1
        const rfidFrom = parseInt(bulk.value.rfid_from) || 1
        animals = Array.from({ length: count }, (_, i) => ({
            tag:                (bulk.value.tag_prefix  || '') + zeroPad(tagFrom  + i, tagFrom),
            rfid:               (bulk.value.rfid_prefix || '') + zeroPad(rfidFrom + i, rfidFrom),
            weight_kg:          parseFloat(bulk.value.avg_weight) || null,
            purchase_price:     avgPricePerAnimal.value  ? parseFloat(avgPricePerAnimal.value)  : null,
            freight_cost:       freightPerAnimal.value   ? parseFloat(freightPerAnimal.value)   : null,
            // common fields
            sex:          bulk.value.sex,
            breed:        bulk.value.breed,
            color:        bulk.value.color,
            dob:          bulk.value.dob || null,
            dob_estimated:bulk.value.dob_estimated,
            source:       bulk.value.source,
            farm_id:      bulk.value.farm_id,
            lot_id:       bulk.value.lot_id   || null,
            supplier_id:  bulk.value.supplier_id || null,
            purchase_date:bulk.value.purchase_date,
            status:       bulk.value.status,
            status_date:  bulk.value.status_date || null,
            notes:        bulk.value.notes || null,
        }))
    } else {
        // Mode B: per-unit weights — weight-proportional price/freight
        animals = tableRows.value.map(row => ({
            tag:                row.tag,
            rfid:               row.rfid,
            weight_kg:          parseFloat(row.weight) || null,
            purchase_price:     parseFloat(rowPricePerHead(row)),
            freight_cost:       parseFloat(rowFreightPerHead(row)),
            sex:          bulk.value.sex,
            breed:        bulk.value.breed,
            color:        bulk.value.color,
            dob:          bulk.value.dob || null,
            dob_estimated:bulk.value.dob_estimated,
            source:       bulk.value.source,
            farm_id:      bulk.value.farm_id,
            lot_id:       bulk.value.lot_id   || null,
            supplier_id:  bulk.value.supplier_id || null,
            purchase_date:bulk.value.purchase_date,
            status:       bulk.value.status,
            status_date:  bulk.value.status_date || null,
            notes:        bulk.value.notes || null,
        }))
    }

    const submitRoute = isPurchaseMode.value
    ? route('procurement.confirm-receipt', props.po.id)
    : route('animals.storeBulk')

    const submitPayload = isPurchaseMode.value
        ? {
            animals:            animals,
            batch_date:         bulk.value.purchase_date,
            receipt_mode:       entryMode.value === 'A' ? 'bulk_avg' : 'bulk_range',
            freight_allocated:  bulk.value.total_freight || 0,
            batch_notes:        bulk.value.notes || null,
        }
        : { company_id: bulk.value.company_id, animals }

        router.post(submitRoute, submitPayload, {
            onSuccess: () => { isSubmitting.value = false },
            onError:   (errors) => {
                bulkErrors.value   = errors
                isSubmitting.value = false
            },
        })
}

// Avatar palette for hero preview (Mode C)
const PALETTES = [
    ['#1a6b3a','#4DBF7A'], ['#7c4a00','#f5a623'],
    ['#003d6b','#4da6ff'], ['#5c1a1a','#e05252'],
]
const previewPalette = PALETTES[(props.animal?.id ?? 0) % PALETTES.length]
</script>

<template>
    <AuthenticatedLayout>
        <div class="cv-page" :dir="isRtl ? 'rtl' : 'ltr'">

            <!-- ── Hero ────────────────────────────────────────── -->
            <div class="cv-page-hero">
                <div class="cv-hero-inner">
                    <div class="cv-hero-preview-avatar">
                        <img v-if="photoPreview && isEdit" :src="photoPreview" class="cv-preview-photo" alt="preview" />
                        <div
                            v-else
                            class="cv-preview-initials"
                            :style="{ background: `linear-gradient(135deg, ${previewPalette[0]}, ${previewPalette[1]})` }"
                        >
                            <span v-if="isEdit && form.tag">{{ form.tag.slice(0,2).toUpperCase() }}</span>
                            <span v-else-if="entryMode !== 'C' && bulk.count">{{ bulk.count }}</span>
                            <span v-else>🐂</span>
                        </div>
                    </div>
                    <div class="cv-hero-content">
                        <div class="cv-page-hero-eyebrow">{{ L.eyebrow }}</div>
                        <div class="cv-page-hero-title">
                            {{ isEdit ? L.titleEdit : L.titleCreate }}
                            <span v-if="isEdit && animal?.tag" class="cv-hero-tag-chip">{{ animal.tag }}</span>
                            <span v-else-if="entryMode !== 'C' && bulk.count" class="cv-hero-count-chip">
                                {{ bulk.count }} {{ locale === 'ar' ? 'رأس' : 'head' }}
                            </span>
                        </div>
                        <div class="cv-page-hero-sub">{{ isEdit ? L.subEdit : L.subCreate }}</div>
                    </div>
                </div>
            </div>
            <!-- ── PO Summary Bar (purchase mode only) ─────────────── -->
            <div v-if="isPurchaseMode" style="
                margin: 0 0 0 0;
                padding: .85rem 1.5rem;
                background: linear-gradient(135deg, rgba(232,160,48,0.08), rgba(96,168,224,0.05));
                border-bottom: 1px solid var(--cv-amber-border);
                display: flex; flex-wrap: wrap; align-items: center; gap: 1.5rem;
            " :dir="isRtl ? 'rtl' : 'ltr'">

                <!-- PO badge -->
                <div style="display:flex;align-items:center;gap:.5rem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--cv-amber)" stroke-width="1.7"
                        stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;flex-shrink:0">
                        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                    </svg>
                    <span style="font-size:.72rem;font-weight:700;color:var(--cv-amber);letter-spacing:.06em;text-transform:uppercase">
                        {{ locale === 'ar' ? 'أمر شراء' : 'Purchase Order' }}
                    </span>
                    <span style="font-size:.8rem;font-weight:800;color:var(--cv-amber)">
                        {{ po?.po_reference || ('#' + po?.id) }}
                    </span>
                </div>

                <!-- Supplier -->
                <div style="font-size:.78rem;color:var(--cv-text-secondary)">
                    <span style="color:var(--cv-text-muted);font-size:.68rem;margin-inline-end:.25rem">
                        {{ locale === 'ar' ? 'المورد:' : 'Supplier:' }}
                    </span>
                    <span style="font-weight:600">{{ n(po?.supplier) }}</span>
                </div>

                <!-- Farm / Lot -->
                <div style="font-size:.78rem;color:var(--cv-text-secondary)">
                    <span style="color:var(--cv-text-muted);font-size:.68rem;margin-inline-end:.25rem">
                        {{ locale === 'ar' ? 'المزرعة:' : 'Farm:' }}
                    </span>
                    <span style="font-weight:600">{{ n(po?.farm) }}</span>
                    <span v-if="po?.lot" style="color:var(--cv-text-muted)"> · {{ po.lot.name }}</span>
                </div>

                <!-- Receipt progress -->
                <div style="font-size:.78rem;color:var(--cv-text-secondary)">
                    <span style="color:var(--cv-text-muted);font-size:.68rem;margin-inline-end:.25rem">
                        {{ locale === 'ar' ? 'المستلم:' : 'Received:' }}
                    </span>
                    <span style="font-weight:700;color:var(--cv-blue-text)">{{ po?.received_head ?? 0 }}</span>
                    <span style="color:var(--cv-text-muted)"> / {{ po?.min_head }}–{{ po?.max_head }}</span>
                </div>

                <!-- Estimated price -->
                <div style="font-size:.78rem;color:var(--cv-text-secondary)">
                    <span style="color:var(--cv-text-muted);font-size:.68rem;margin-inline-end:.25rem">
                        {{ locale === 'ar' ? 'السعر/كجم:' : 'Price/kg:' }}
                    </span>
                    <span style="font-weight:700;color:var(--cv-blue-text)">
                        {{ po?.estimated_price_per_kg }} {{ po?.currency ?? 'EGP' }}
                    </span>
                </div>

                <!-- Over-received warning -->
                <div v-if="po?.is_over_received"
                    style="font-size:.72rem;color:var(--cv-danger-text);font-weight:600;display:flex;align-items:center;gap:.3rem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px">
                        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    {{ locale === 'ar' ? 'تحذير: تجاوز الحد الأقصى' : 'Warning: Exceeds PO max' }}
                </div>

            </div>

            <!-- ── Mode Selector (create only) ───────────────── -->
            <div v-if="!isEdit" class="cv-mode-selector">
                <button
                    type="button"
                    class="cv-mode-tab"
                    :class="{ 'cv-mode-tab--active': entryMode === 'A' }"
                    @click="entryMode = 'A'"
                >
                    <svg class="cv-mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    {{ L.modeA }}
                </button>
                <button
                    type="button"
                    class="cv-mode-tab"
                    :class="{ 'cv-mode-tab--active': entryMode === 'B' }"
                    @click="entryMode = 'B'"
                >
                    <svg class="cv-mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="3" rx="1"/><rect x="3" y="11" width="18" height="3" rx="1"/><rect x="3" y="17" width="18" height="3" rx="1"/></svg>
                    {{ L.modeB }}
                </button>
                <button
                    type="button"
                    class="cv-mode-tab"
                    :class="{ 'cv-mode-tab--active': entryMode === 'C' }"
                    @click="entryMode = 'C'"
                >
                    <svg class="cv-mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    {{ L.modeC }}
                </button>
            </div>

            <!-- ── FORM ─────────────────────────────────────── -->
            <form @submit.prevent="submit" class="cv-form-page" enctype="multipart/form-data">
                <div class="cv-form-body">

                    <!-- ══════════════════════════════════════════
                         MODE A — Bulk / Average Weight
                    ═══════════════════════════════════════════ -->
                    <template v-if="entryMode === 'A'">

                        <!-- Animal Count -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num">1</div>
                                <h2 class="cv-section-title">{{ L.sCount }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.animalCount }}</label>
                                    <input
                                        v-model="bulk.count"
                                        type="number" min="1" max="5000"
                                        class="cv-input"
                                        :class="{ 'cv-input-error': bulkErrors.count }"
                                        :placeholder="L.animalCountPh"
                                    />
                                    <div v-if="bulkErrors.count" class="cv-field-error">{{ bulkErrors.count }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Common Info -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num">2</div>
                                <h2 class="cv-section-title">{{ L.sCommon }}</h2>
                            </div>
                            <div class="cv-section-grid">

                                <!-- Sex -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.sex }}</label>
                                    <select v-model="bulk.sex" class="cv-select" :class="{ 'cv-input-error': bulkErrors.sex }">
                                        <option value="">{{ L.sexSelect }}</option>
                                        <option value="male">{{ L.sexMale }}</option>
                                        <option value="female">{{ L.sexFemale }}</option>
                                        <option value="castrated">{{ L.sexCastrated }}</option>
                                    </select>
                                    <div v-if="bulkErrors.sex" class="cv-field-error">{{ bulkErrors.sex }}</div>
                                </div>

                                <!-- Breed -->
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.breed }}</label>
                                    <input v-model="bulk.breed" class="cv-input" :placeholder="L.breedPh" />
                                </div>

                                <!-- Color -->
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.color }}</label>
                                    <input v-model="bulk.color" class="cv-input" :placeholder="L.colorPh" />
                                </div>

                                <!-- DOB -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.dob }}</label>
                                    <input type="date" v-model="bulk.dob" class="cv-input" />
                                </div>

                                <!-- DOB Estimated -->
                                <div class="cv-field cv-field-sm" style="justify-content:flex-end;padding-bottom:.25rem">
                                    <label class="cv-checkbox-label">
                                        <span
                                            class="cv-custom-checkbox"
                                            :class="{ checked: bulk.dob_estimated }"
                                            @click="bulk.dob_estimated = !bulk.dob_estimated"
                                        >
                                            <svg v-if="bulk.dob_estimated" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        </span>
                                        {{ L.dobEstimated }}
                                    </label>
                                </div>

                                <!-- Source -->
                                <div v-if="!isPurchaseMode" class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.source }}</label>
                                    <select v-model="bulk.source" class="cv-select">
                                        <option value="purchased">{{ L.sourcePurchased }}</option>
                                        <option value="born">{{ L.sourceBorn }}</option>
                                    </select>
                                </div>

                               <!-- Farm -->
                                    <div class="cv-field cv-field-md">
                                        <label class="cv-label">{{ L.farm }}</label>
                                        <!-- Purchase mode: show name read-only — farms array is empty (locked from PO) -->
                                        <div v-if="isPurchaseMode" class="cv-auto-field">
                                            <span class="cv-auto-value" style="color:var(--cv-text-primary)">
                                                {{ n(po.farm) }}
                                            </span>
                                            <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                        </div>
                                        <select v-else v-model="bulk.farm_id" class="cv-select"
                                                :class="{ 'cv-input-error': bulkErrors.farm_id }">
                                            <option value="">{{ L.farmSelect }}</option>
                                            <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                                        </select>
                                        <div v-if="bulkErrors.farm_id" class="cv-field-error">{{ bulkErrors.farm_id }}</div>
                                    </div>

                                <!-- Lot -->
                                    <div class="cv-field cv-field-md">
                                        <label class="cv-label">{{ L.lot }}</label>
                                        <!-- Purchase mode: show name read-only -->
                                        <div v-if="isPurchaseMode" class="cv-auto-field">
                                            <span class="cv-auto-value" style="color:var(--cv-text-primary)">
                                                {{ po.lot ? po.lot.name : (locale === 'ar' ? 'غير محدد' : 'Not set') }}
                                            </span>
                                            <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                        </div>
                                        <select v-else v-model="bulk.lot_id" class="cv-select"
                                                :disabled="!bulk.farm_id">
                                            <option value="">{{ bulk.farm_id ? L.lotSelect : L.lotEmpty }}</option>
                                            <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                        </select>
                                    </div>

                                <!-- Purchase Date -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.purchaseDate }}</label>
                                    <input type="date" v-model="bulk.purchase_date" class="cv-input" :class="{ 'cv-input-error': bulkErrors.purchase_date }" />
                                    <div v-if="bulkErrors.purchase_date" class="cv-field-error">{{ bulkErrors.purchase_date }}</div>
                                </div>

                           <!-- Supplier -->
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.supplier }}</label>
                                    <!-- Purchase mode: show name read-only -->
                                    <div v-if="isPurchaseMode" class="cv-auto-field">
                                        <span class="cv-auto-value" style="color:var(--cv-text-primary)">
                                            {{ n(po.supplier) }}
                                        </span>
                                        <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                    </div>
                                    <select v-else v-model="bulk.supplier_id" class="cv-select" :disabled="bulk.source === 'born'">
                                        <option value="">{{ L.supplierSelect }}</option>
                                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ n(s) }}</option>
                                    </select>
                                </div>

                            </div>
                        </div>

                        <!-- Pricing & Weight -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#7c4a00,#f5a623)">3</div>
                                <h2 class="cv-section-title">{{ L.sPricing }}</h2>
                            </div>
                            <div class="cv-section-grid">

                          <!-- Total Purchase Price -->
                            <div class="cv-field cv-field-md">
                                <label class="cv-label">{{ L.totalPrice }}</label>
                                <!-- Purchase mode: price is derived from PO — not user-editable -->
                                <div v-if="isPurchaseMode" class="cv-auto-field">
                                    <span class="cv-auto-prefix">EGP</span>
                                    <span class="cv-auto-value">
                                        {{
                                            (parseFloat(po.estimated_price_per_kg) && parseFloat(bulk.avg_weight) && parseInt(bulk.count))
                                                ? (parseFloat(po.estimated_price_per_kg) * parseFloat(bulk.avg_weight) * parseInt(bulk.count)).toFixed(2)
                                                : L.autoCalc
                                        }}
                                    </span>
                                    <span class="cv-auto-badge">{{ locale === 'ar' ? 'من أمر الشراء' : 'FROM PO' }}</span>
                                </div>
                                <div v-else class="cv-input-group">
                                    <span class="cv-input-prefix">EGP</span>
                                    <input
                                        v-model="bulk.total_price"
                                        type="number" min="0" step="0.01"
                                        class="cv-input cv-input-prefixed"
                                        :placeholder="L.totalPricePh"
                                    />
                                </div>
                            </div>

                                <!-- Avg Price Per Animal (auto) -->
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.avgPricePerAnimal }}</label>
                                    <div class="cv-auto-field">
                                        <span class="cv-auto-prefix">EGP</span>
                                        <span class="cv-auto-value">
                                            {{ avgPricePerAnimal ?? L.autoCalc }}
                                        </span>
                                        <span class="cv-auto-badge">{{ L.autoCalc }}</span>
                                    </div>
                                </div>

                                <!-- Avg Weight Per Animal -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.avgWeightPerAnimal }}</label>
                                    <div class="cv-input-group">
                                        <input
                                            v-model="bulk.avg_weight"
                                            type="number" min="0" step="0.1"
                                            class="cv-input cv-input-suffixed"
                                            :placeholder="L.avgWeightPh"
                                        />
                                        <span class="cv-input-suffix">kg</span>
                                    </div>
                                </div>

                                <!-- Total Freight -->
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.totalFreight }}</label>
                                    <div class="cv-input-group">
                                        <span class="cv-input-prefix">EGP</span>
                                        <input
                                            v-model="bulk.total_freight"
                                            type="number" min="0" step="0.01"
                                            class="cv-input cv-input-prefixed"
                                            :placeholder="L.totalFreightPh"
                                        />
                                    </div>
                                </div>

                                <!-- Per Animal Freight (auto) -->
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.freightPerAnimal }}</label>
                                    <div class="cv-auto-field">
                                        <span class="cv-auto-prefix">EGP</span>
                                        <span class="cv-auto-value">
                                            {{ freightPerAnimal ?? L.autoCalc }}
                                        </span>
                                        <span class="cv-auto-badge">{{ L.autoCalc }}</span>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Status -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#1a3a5c,#4da6ff)">4</div>
                                <h2 class="cv-section-title">{{ L.sStatus }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.status }}</label>
                                    <select v-model="bulk.status" class="cv-select">
                                        <option value="active">{{ L.statusActive }}</option>
                                        <option value="quarantine">{{ L.statusQuarantine }}</option>
                                        <option value="sold">{{ L.statusSold }}</option>
                                        <option value="slaughtered">{{ L.statusSlaughtered }}</option>
                                        <option value="dead">{{ L.statusDead }}</option>
                                        <option value="transferred">{{ L.statusTransferred }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.statusDate }}</label>
                                    <input type="date" v-model="bulk.status_date" class="cv-input" />
                                </div>
                                <div class="cv-field cv-field-full">
                                    <label class="cv-label">{{ L.notes }}</label>
                                    <textarea v-model="bulk.notes" class="cv-textarea" rows="2" :placeholder="L.notesPh"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Tag & RFID Range -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#2d1a5c,#8b5cf6)">5</div>
                                <h2 class="cv-section-title">{{ L.sTagRange }}</h2>
                            </div>
                            <div class="cv-section-grid">

                                <!-- Tag Prefix -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.tagPrefix }}</label>
                                    <input v-model="bulk.tag_prefix" class="cv-input" :placeholder="L.tagPrefixPh" />
                                </div>
                                <!-- Tag From -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.tagFrom }}</label>
                                    <input v-model="bulk.tag_from" type="number" min="1" class="cv-input" :placeholder="L.tagFromPh" />
                                </div>
                                <!-- Tag To (auto) -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.tagTo }}</label>
                                    <div class="cv-auto-field">
                                        <span class="cv-auto-value">
                                            {{ tagTo !== '—' ? (bulk.tag_prefix || '') + tagTo : '—' }}
                                        </span>
                                        <span class="cv-auto-badge">{{ L.autoCalc }}</span>
                                    </div>
                                </div>

                                <!-- RFID Prefix -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.rfidPrefix }}</label>
                                    <input v-model="bulk.rfid_prefix" class="cv-input" :placeholder="L.rfidPrefixPh" />
                                </div>
                                <!-- RFID From -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.rfidFrom }}</label>
                                    <input v-model="bulk.rfid_from" type="number" min="1" class="cv-input" :placeholder="L.rfidFromPh" />
                                </div>
                                <!-- RFID To (auto) -->
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.rfidTo }}</label>
                                    <div class="cv-auto-field">
                                        <span class="cv-auto-value">
                                            {{ rfidTo !== '—' ? (bulk.rfid_prefix || '') + rfidTo : '—' }}
                                        </span>
                                        <span class="cv-auto-badge">{{ L.autoCalc }}</span>
                                    </div>
                                </div>

                            </div>
                        </div>

                    </template>

                    <!-- ══════════════════════════════════════════
                         MODE B — Bulk / Per-Unit Weights
                    ═══════════════════════════════════════════ -->
                    <template v-if="entryMode === 'B'">

                        <!-- Animal Count -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num">1</div>
                                <h2 class="cv-section-title">{{ L.sCount }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.animalCount }}</label>
                                    <input
                                        v-model="bulk.count"
                                        type="number" min="1" max="5000"
                                        class="cv-input"
                                        :class="{ 'cv-input-error': bulkErrors.count }"
                                        :placeholder="L.animalCountPh"
                                    />
                                    <div v-if="bulkErrors.count" class="cv-field-error">{{ bulkErrors.count }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Common Info (same as Mode A) -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num">2</div>
                                <h2 class="cv-section-title">{{ L.sCommon }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.sex }}</label>
                                    <select v-model="bulk.sex" class="cv-select">
                                        <option value="">{{ L.sexSelect }}</option>
                                        <option value="male">{{ L.sexMale }}</option>
                                        <option value="female">{{ L.sexFemale }}</option>
                                        <option value="castrated">{{ L.sexCastrated }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.breed }}</label>
                                    <input v-model="bulk.breed" class="cv-input" :placeholder="L.breedPh" />
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.color }}</label>
                                    <input v-model="bulk.color" class="cv-input" :placeholder="L.colorPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.dob }}</label>
                                    <input type="date" v-model="bulk.dob" class="cv-input" />
                                </div>
                                <div class="cv-field cv-field-sm" style="justify-content:flex-end;padding-bottom:.25rem">
                                    <label class="cv-checkbox-label">
                                        <span class="cv-custom-checkbox" :class="{ checked: bulk.dob_estimated }" @click="bulk.dob_estimated = !bulk.dob_estimated">
                                            <svg v-if="bulk.dob_estimated" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        </span>
                                        {{ L.dobEstimated }}
                                    </label>
                                </div>
                                <div v-if="!isPurchaseMode" class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.source }}</label>
                                    <select v-model="bulk.source" class="cv-select">
                                        <option value="purchased">{{ L.sourcePurchased }}</option>
                                        <option value="born">{{ L.sourceBorn }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.farm }}</label>
                                    <div v-if="isPurchaseMode" class="cv-auto-field">
                                        <span class="cv-auto-value" style="color:var(--cv-text-primary)">{{ n(po.farm) }}</span>
                                        <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                    </div>
                                    <select v-else v-model="bulk.farm_id" class="cv-select" :class="{ 'cv-input-error': bulkErrors.farm_id }">
                                        <option value="">{{ L.farmSelect }}</option>
                                        <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                                    </select>
                                    <div v-if="bulkErrors.farm_id" class="cv-field-error">{{ bulkErrors.farm_id }}</div>
                                </div>
                                 <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.lot }}</label>
                                    <div v-if="isPurchaseMode" class="cv-auto-field">
                                        <span class="cv-auto-value" style="color:var(--cv-text-primary)">{{ po.lot ? po.lot.name : (locale === 'ar' ? 'غير محدد' : 'Not set') }}</span>
                                        <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                    </div>
                                    <select v-else v-model="bulk.lot_id" class="cv-select" :disabled="!bulk.farm_id">
                                        <option value="">{{ bulk.farm_id ? L.lotSelect : L.lotEmpty }}</option>
                                        <option v-for="l in filteredLots" :key="l.id" :value="l.id">{{ l.name }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.purchaseDate }}</label>
                                    <input type="date" v-model="bulk.purchase_date" class="cv-input" />
                                </div>
                               <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.supplier }}</label>
                                    <div v-if="isPurchaseMode" class="cv-auto-field">
                                        <span class="cv-auto-value" style="color:var(--cv-text-primary)">{{ n(po.supplier) }}</span>
                                        <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                    </div>
                                    <select v-else v-model="bulk.supplier_id" class="cv-select" :disabled="bulk.source === 'born'">
                                        <option value="">{{ L.supplierSelect }}</option>
                                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ n(s) }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing totals -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#7c4a00,#f5a623)">3</div>
                                <h2 class="cv-section-title">{{ L.sPricing }}</h2>
                            </div>
                            <div class="cv-section-grid">
                               <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.totalPrice }}</label>
                                    <div v-if="isPurchaseMode" class="cv-auto-field">
                                        <span class="cv-auto-prefix">EGP</span>
                                        <span class="cv-auto-value">{{ locale === 'ar' ? 'محسوب من أمر الشراء' : 'Derived from PO price × weights' }}</span>
                                        <span class="cv-auto-badge">{{ locale === 'ar' ? 'مقفل' : 'LOCKED' }}</span>
                                    </div>
                                    <div v-else class="cv-input-group">
                                        <span class="cv-input-prefix">EGP</span>
                                        <input v-model="bulk.total_price" type="number" min="0" step="0.01" class="cv-input cv-input-prefixed" :placeholder="L.totalPricePh" />
                                    </div>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.totalFreight }}</label>
                                    <div class="cv-input-group">
                                        <span class="cv-input-prefix">EGP</span>
                                        <input v-model="bulk.total_freight" type="number" min="0" step="0.01" class="cv-input cv-input-prefixed" :placeholder="L.totalFreightPh" />
                                    </div>
                                </div>
                                <!-- Total weight summary bar -->
                                <div v-if="sumWeights > 0" class="cv-field cv-field-full">
                                    <div class="cv-weight-summary">
                                        <span>{{ L.totalWeight }}: <strong>{{ sumWeights.toFixed(1) }} kg</strong></span>
                                        <span v-if="tableRows.length">
                                            {{ locale === 'ar' ? 'متوسط' : 'Avg' }}:
                                            <strong>{{ (sumWeights / tableRows.length).toFixed(1) }} kg</strong>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tag & RFID Range -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#2d1a5c,#8b5cf6)">4</div>
                                <h2 class="cv-section-title">{{ L.sTagRange }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.tagPrefix }}</label>
                                    <input v-model="bulk.tag_prefix" class="cv-input" :placeholder="L.tagPrefixPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.tagFrom }}</label>
                                    <input v-model="bulk.tag_from" type="number" min="1" class="cv-input" :placeholder="L.tagFromPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.tagTo }}</label>
                                    <div class="cv-auto-field">
                                        <span class="cv-auto-value">{{ tagTo !== '—' ? (bulk.tag_prefix || '') + tagTo : '—' }}</span>
                                        <span class="cv-auto-badge">{{ L.autoCalc }}</span>
                                    </div>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.rfidPrefix }}</label>
                                    <input v-model="bulk.rfid_prefix" class="cv-input" :placeholder="L.rfidPrefixPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.rfidFrom }}</label>
                                    <input v-model="bulk.rfid_from" type="number" min="1" class="cv-input" :placeholder="L.rfidFromPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.rfidTo }}</label>
                                    <div class="cv-auto-field">
                                        <span class="cv-auto-value">{{ rfidTo !== '—' ? (bulk.rfid_prefix || '') + rfidTo : '—' }}</span>
                                        <span class="cv-auto-badge">{{ L.autoCalc }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#1a3a5c,#4da6ff)">5</div>
                                <h2 class="cv-section-title">{{ L.sStatus }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.status }}</label>
                                    <select v-model="bulk.status" class="cv-select">
                                        <option value="active">{{ L.statusActive }}</option>
                                        <option value="quarantine">{{ L.statusQuarantine }}</option>
                                        <option value="sold">{{ L.statusSold }}</option>
                                        <option value="slaughtered">{{ L.statusSlaughtered }}</option>
                                        <option value="dead">{{ L.statusDead }}</option>
                                        <option value="transferred">{{ L.statusTransferred }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.statusDate }}</label>
                                    <input type="date" v-model="bulk.status_date" class="cv-input" />
                                </div>
                                <div class="cv-field cv-field-full">
                                    <label class="cv-label">{{ L.notes }}</label>
                                    <textarea v-model="bulk.notes" class="cv-textarea" rows="2" :placeholder="L.notesPh"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Per-Unit Weight Table -->
                        <div v-if="tableRows.length > 0" class="cv-card cv-card--table">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#1a5c3a,#4dbf7a)">6</div>
                                <h2 class="cv-section-title">{{ L.sWeightTable }}</h2>
                                <!-- Excel controls -->
                                <div class="cv-excel-controls" :class="{ 'cv-excel-controls--rtl': isRtl }">
                                    <button type="button" class="cv-btn cv-btn-secondary cv-btn-sm" @click="downloadTemplate">
                                        <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        {{ L.downloadTemplate }}
                                    </button>
                                    <label class="cv-btn cv-btn-secondary cv-btn-sm cv-upload-btn">
                                        <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        {{ L.uploadExcel }}
                                        <input
                                            ref="excelFileRef"
                                            type="file"
                                            accept=".csv,.xlsx,.xls"
                                            class="cv-file-input-hidden"
                                            @change="handleExcelUpload"
                                        />
                                    </label>
                                    <span class="cv-photo-hint">{{ L.excelHint }}</span>
                                </div>
                            </div>

                            <div class="cv-table-wrap">
                                <table class="cv-bulk-table">
                                    <thead>
                                        <tr>
                                            <th class="cv-th cv-th-num">{{ L.colNo }}</th>
                                            <th class="cv-th">{{ L.colTag }}</th>
                                            <th class="cv-th">{{ L.colRfid }}</th>
                                            <th class="cv-th">{{ L.colWeight }}</th>
                                            <th class="cv-th cv-th-calc">{{ L.colPrice }}</th>
                                            <th class="cv-th cv-th-calc">{{ L.colFreight }}</th>
                                            <th class="cv-th cv-th-action">{{ L.colCopy }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(row, i) in tableRows"
                                            :key="i"
                                            class="cv-tr"
                                            :class="{ 'cv-tr-even': i % 2 === 0 }"
                                        >
                                            <!-- Row # -->
                                            <td class="cv-td cv-td-num">{{ i + 1 }}</td>

                                            <!-- Tag (editable) -->
                                            <td class="cv-td">
                                                <input
                                                    v-model="row.tag"
                                                    class="cv-table-input"
                                                    :dir="'ltr'"
                                                />
                                            </td>

                                            <!-- RFID (editable) -->
                                            <td class="cv-td">
                                                <input
                                                    v-model="row.rfid"
                                                    class="cv-table-input"
                                                    :dir="'ltr'"
                                                />
                                            </td>

                                            <!-- Weight (user fills) -->
                                            <td class="cv-td cv-td-weight">
                                                <input
                                                    v-model="row.weight"
                                                    type="number" min="0" step="0.1"
                                                    class="cv-table-input cv-table-input--weight"
                                                    :placeholder="L.weightPh"
                                                />
                                            </td>

                                            <!-- Price/Head (auto) -->
                                            <td class="cv-td cv-td-calc">
                                                <span class="cv-calc-value">{{ rowPricePerHead(row) }}</span>
                                            </td>

                                            <!-- Freight/Head (auto) -->
                                            <td class="cv-td cv-td-calc">
                                                <span class="cv-calc-value">{{ rowFreightPerHead(row) }}</span>
                                            </td>

                                            <!-- Copy down ⋮ -->
                                            <td class="cv-td cv-td-action">
                                                <button
                                                    type="button"
                                                    class="cv-copy-btn"
                                                    :title="L.copyDown"
                                                    @click="copyDown(i)"
                                                    :disabled="i === tableRows.length - 1"
                                                >⋮</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Empty state when count not yet entered -->
                        <div v-else-if="entryMode === 'B'" class="cv-card cv-empty-table-hint">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="cv-empty-icon"><rect x="3" y="5" width="18" height="3" rx="1"/><rect x="3" y="11" width="18" height="3" rx="1"/><rect x="3" y="17" width="18" height="3" rx="1"/></svg>
                            <p>{{ locale === 'ar' ? 'أدخل عدد الماشيةات أعلاه لإنشاء جدول الأوزان' : 'Enter animal count above to generate the weight table' }}</p>
                        </div>

                    </template>

                    <!-- ══════════════════════════════════════════
                         MODE C — Single Animal (original form)
                    ═══════════════════════════════════════════ -->
                    <template v-if="entryMode === 'C'">

                        <!-- Section 1 — Identity -->
                        <div class="cv-form-section cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num">1</div>
                                <h2 class="cv-section-title">{{ L.sIdentity }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.tag }}</label>
                                    <input v-model="form.tag" class="cv-input" :class="{ 'cv-input-error': form.errors.tag }" :placeholder="L.tagPh" />
                                    <div v-if="form.errors.tag" class="cv-field-error">{{ form.errors.tag }}</div>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.rfid }}</label>
                                    <input v-model="form.rfid" class="cv-input" :placeholder="L.rfidPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.sex }}</label>
                                    <select v-model="form.sex" class="cv-select" :class="{ 'cv-input-error': form.errors.sex }">
                                        <option value="">{{ L.sexSelect }}</option>
                                        <option value="male">{{ L.sexMale }}</option>
                                        <option value="female">{{ L.sexFemale }}</option>
                                        <option value="castrated">{{ L.sexCastrated }}</option>
                                    </select>
                                    <div v-if="form.errors.sex" class="cv-field-error">{{ form.errors.sex }}</div>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.breed }}</label>
                                    <input v-model="form.breed" class="cv-input" :placeholder="L.breedPh" />
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.color }}</label>
                                    <input v-model="form.color" class="cv-input" :placeholder="L.colorPh" />
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.markings }}</label>
                                    <input v-model="form.markings" class="cv-input" :placeholder="L.markingsPh" />
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.dob }}</label>
                                    <input type="date" v-model="form.dob" class="cv-input" />
                                </div>
                                <div class="cv-field cv-field-sm" style="justify-content:flex-end;padding-bottom:.25rem">
                                    <label class="cv-checkbox-label">
                                        <span class="cv-custom-checkbox" :class="{ checked: form.dob_estimated }" @click="form.dob_estimated = !form.dob_estimated">
                                            <svg v-if="form.dob_estimated" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                        </span>
                                        {{ L.dobEstimated }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2 — Origin -->
                        <div class="cv-form-section cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#7c4a00,#f5a623)">2</div>
                                <h2 class="cv-section-title">{{ L.sOrigin }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.source }}</label>
                                    <select v-model="form.source" class="cv-select">
                                        <option value="purchased">{{ L.sourcePurchased }}</option>
                                        <option value="born">{{ L.sourceBorn }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.farm }}</label>
                                    <select v-model="form.farm_id" class="cv-select" :class="{ 'cv-input-error': form.errors.farm_id }">
                                        <option value="">{{ L.farmSelect }}</option>
                                        <option v-for="f in farms" :key="f.id" :value="f.id">{{ n(f) }}</option>
                                    </select>
                                    <div v-if="form.errors.farm_id" class="cv-field-error">{{ form.errors.farm_id }}</div>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.lot }}</label>
                                    <select v-model="form.lot_id" class="cv-select" :disabled="!form.farm_id">
                                        <option value="">{{ form.farm_id ? L.lotSelect : L.lotEmpty }}</option>
                                        <option v-for="l in filteredLotsSingle" :key="l.id" :value="l.id">{{ l.name }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.purchaseDate }}</label>
                                    <input type="date" v-model="form.purchase_date" class="cv-input" />
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.supplier }}</label>
                                    <select v-model="form.supplier_id" class="cv-select">
                                        <option value="">{{ L.supplierSelect }}</option>
                                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ n(s) }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.purchasePrice }}</label>
                                    <div class="cv-input-group">
                                        <span class="cv-input-prefix">EGP</span>
                                        <input v-model="form.purchase_price" type="number" min="0" step="0.01" class="cv-input cv-input-prefixed" placeholder="0.00" />
                                    </div>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.purchaseWt }}</label>
                                    <div class="cv-input-group">
                                        <input v-model="form.purchase_weight_kg" type="number" min="0" step="0.1" class="cv-input cv-input-suffixed" placeholder="0.0" />
                                        <span class="cv-input-suffix">kg</span>
                                    </div>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.freight }}</label>
                                    <div class="cv-input-group">
                                        <span class="cv-input-prefix">EGP</span>
                                        <input v-model="form.freight_cost" type="number" min="0" step="0.01" class="cv-input cv-input-prefixed" placeholder="0.00" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 3 — Photo -->
                        <div class="cv-form-section cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#1a3a5c,#4da6ff)">3</div>
                                <h2 class="cv-section-title">{{ L.sPhoto }}</h2>
                            </div>
                            <div class="cv-photo-section">
                                <div class="cv-photo-preview-wrap">
                                    <img v-if="photoPreview" :src="photoPreview" class="cv-photo-preview" alt="preview" />
                                    <div v-else class="cv-photo-placeholder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    </div>
                                </div>
                                <div class="cv-photo-controls">
                                    <label class="cv-btn cv-btn-secondary cv-upload-btn">
                                        <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        {{ photoPreview ? L.photoChange : L.photoUpload }}
                                        <input type="file" accept="image/*" class="cv-file-input-hidden" @change="onPhotoChange" />
                                    </label>
                                    <button v-if="photoPreview" type="button" class="cv-btn cv-btn-ghost cv-btn-sm" @click="clearPhoto">{{ L.photoClear }}</button>
                                    <span class="cv-photo-hint">{{ L.photoHint }}</span>
                                </div>
                            </div>
                            <div v-if="form.errors.photo" class="cv-field-error" style="margin-top:0.5rem">{{ form.errors.photo }}</div>
                        </div>

                        <!-- Section 4 — Status -->
                        <div class="cv-form-section cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#1a3a5c,#4da6ff)">4</div>
                                <h2 class="cv-section-title">{{ L.sStatus }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.status }}</label>
                                    <select v-model="form.status" class="cv-select">
                                        <option value="active">{{ L.statusActive }}</option>
                                        <option value="quarantine">{{ L.statusQuarantine }}</option>
                                        <option value="sold">{{ L.statusSold }}</option>
                                        <option value="slaughtered">{{ L.statusSlaughtered }}</option>
                                        <option value="dead">{{ L.statusDead }}</option>
                                        <option value="transferred">{{ L.statusTransferred }}</option>
                                    </select>
                                </div>
                                <div class="cv-field cv-field-sm">
                                    <label class="cv-label">{{ L.statusDate }}</label>
                                    <input type="date" v-model="form.status_date" class="cv-input" />
                                </div>
                                <div class="cv-field cv-field-full">
                                    <label class="cv-label">{{ L.notes }}</label>
                                    <textarea v-model="form.notes" class="cv-textarea" rows="3" :placeholder="L.notesPh"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Section 5 — Lineage -->
                        <!-- <div class="cv-form-section cv-card">
                            <div class="cv-section-heading">
                                <div class="cv-section-num" style="background:linear-gradient(135deg,#2d1a5c,#8b5cf6)">5</div>
                                <h2 class="cv-section-title">{{ L.sLineage }}</h2>
                            </div>
                            <div class="cv-section-grid">
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.damId }}</label>
                                    <input v-model="form.dam_id" class="cv-input" :placeholder="L.damPh" />
                                    <div v-if="form.errors.dam_id" class="cv-field-error">{{ form.errors.dam_id }}</div>
                                </div>
                                <div class="cv-field cv-field-md">
                                    <label class="cv-label">{{ L.sireId }}</label>
                                    <input v-model="form.sire_id" class="cv-input" :placeholder="L.sirePh" />
                                    <div v-if="form.errors.sire_id" class="cv-field-error">{{ form.errors.sire_id }}</div>
                                </div>
                            </div>
                        </div> -->

                    </template>

                </div><!-- /cv-form-body -->

                <!-- ── Footer ──────────────────────────────── -->
                <div class="cv-form-footer">
                    <Link :href="route('animals.index')" class="cv-btn cv-btn-ghost">
                        <svg class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <polyline :points="isRtl ? '9 18 15 12 9 6' : '15 18 9 12 15 6'"/>
                        </svg>
                        {{ L.back }}
                    </Link>

                    <button
                        type="submit"
                        class="cv-btn cv-btn-amber cv-submit-btn"
                        :disabled="isSubmitting || (entryMode === 'C' && form.processing)"
                    >
                        <svg v-if="!form.processing && !isSubmitting" class="cv-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <svg v-else class="cv-btn-icon cv-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                        {{ isEdit ? L.save : L.create }}
                    </button>
                </div>

            </form>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Page ────────────────────────────────────────────────── */
.cv-page { min-height: 100vh; background: var(--cv-bg-base); display: flex; flex-direction: column; }

/* ── Hero ────────────────────────────────────────────────── */
.cv-page-hero {
    background: linear-gradient(135deg, #0a1f11 0%, #0d2b18 60%, #0e1f13 100%);
    border-bottom: 1px solid rgba(77,191,122,0.12);
    padding: 2rem 2rem 1.5rem;
    position: relative; overflow: hidden;
}
.cv-page-hero::after {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(77,191,122,0.06) 1px, transparent 1px);
    background-size: 24px 24px;
    pointer-events: none;
}
.cv-hero-inner { position: relative; z-index: 1; display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
.cv-hero-preview-avatar { flex-shrink: 0; }
.cv-preview-photo { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; border: 2px solid rgba(77,191,122,0.3); }
.cv-preview-initials {
    width: 64px; height: 64px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 800; color: #fff;
    border: 2px solid rgba(77,191,122,0.2);
}
.cv-hero-content { flex: 1; min-width: 0; }
.cv-page-hero-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: #f5a623; margin-bottom: 0.3rem; }
.cv-page-hero-title { font-size: 1.6rem; font-weight: 800; color: var(--cv-text-primary); display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.cv-hero-tag-chip, .cv-hero-count-chip {
    font-size: 0.85rem; font-weight: 600;
    background: rgba(77,191,122,0.12); color: #4DBF7A;
    border: 1px solid rgba(77,191,122,0.2); border-radius: 20px;
    padding: 0.2em 0.75em;
}
.cv-hero-count-chip { background: rgba(245,166,35,0.12); color: #f5a623; border-color: rgba(245,166,35,0.2); }
.cv-page-hero-sub { font-size: 0.875rem; color: var(--cv-text-muted); margin-top: 0.25rem; }

/* ── Mode selector ───────────────────────────────────────── */
.cv-mode-selector {
    display: flex;
    background: var(--cv-bg-card);
    border-bottom: 1px solid var(--cv-border);
    padding: 0 2rem;
    gap: 0;
    overflow-x: auto;
}
.cv-mode-tab {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 1rem 1.25rem;
    background: transparent; border: none;
    border-bottom: 3px solid transparent;
    color: var(--cv-text-muted);
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; white-space: nowrap;
    transition: color 0.15s, border-color 0.15s;
}
.cv-mode-tab:hover { color: var(--cv-text-primary); }
.cv-mode-tab--active { color: #4DBF7A; border-bottom-color: #4DBF7A; }
.cv-mode-icon { width: 16px; height: 16px; flex-shrink: 0; }

/* ── Form layout ─────────────────────────────────────────── */
.cv-form-page { display: flex; flex-direction: column; flex: 1; }
.cv-form-body { padding: 2rem 2rem 1rem; display: flex; flex-direction: column; gap: 1.5rem; }
.cv-form-footer {
    background: var(--cv-bg-card);
    border-top: 0px solid #c6eb26;
    padding: 1rem 2rem;
    display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;
    bottom: 0; z-index: 20;
}

/* ── Cards ───────────────────────────────────────────────── */
.cv-card {
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    border-radius: 10px;
    padding: 1.5rem;
}
.cv-card--table { padding: 1.5rem 0; }
.cv-card--table .cv-section-heading { padding: 0 1.5rem 1rem; }

/* ── Section heading ─────────────────────────────────────── */
.cv-section-heading { display: flex; align-items: center; gap: 0.85rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.cv-section-num {
    width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
    background: linear-gradient(135deg, #1a6b3a, #4DBF7A);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 800; color: #fff;
}
.cv-section-title { font-size: 1rem; font-weight: 700; color: var(--cv-text-primary); margin: 0; flex: 1; }

/* ── Excel controls ──────────────────────────────────────── */
.cv-excel-controls {
    display: flex; align-items: center; gap: 0.6rem;
    flex-wrap: wrap;
    margin-inline-start: auto;
}

/* ── Section grid ────────────────────────────────────────── */
.cv-section-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem 1.25rem;
    align-items: start;
}
.cv-field-sm  { grid-column: span 1; }
.cv-field-md  { grid-column: span 2; }
.cv-field-lg  { grid-column: span 3; }
.cv-field-full { grid-column: 1 / -1; }

/* ── Field ───────────────────────────────────────────────── */
.cv-field { display: flex; flex-direction: column; gap: 0.4rem; }
.cv-label { font-size: 0.78rem; font-weight: 600; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.cv-field-error { font-size: 0.78rem; color: var(--cv-danger-text, #e05252); margin-top: 2px; }

/* ── Auto-calc display field ─────────────────────────────── */
.cv-auto-field {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: rgba(77,191,122,0.06);
    border: 1px dashed rgba(77,191,122,0.3);
    border-radius: 7px;
    min-height: 38px;
}
.cv-auto-prefix { font-size: 0.78rem; font-weight: 600; color: var(--cv-text-muted); }
.cv-auto-value { font-size: 0.875rem; font-weight: 700; color: #4DBF7A; flex: 1; }
.cv-auto-badge {
    font-size: 0.65rem; font-weight: 700; letter-spacing: 0.05em;
    text-transform: uppercase;
    background: rgba(77,191,122,0.15); color: #4DBF7A;
    border-radius: 4px; padding: 0.15em 0.45em;
    white-space: nowrap;
}

/* ── Weight summary bar ──────────────────────────────────── */
.cv-weight-summary {
    display: flex; gap: 2rem; flex-wrap: wrap;
    padding: 0.65rem 1rem;
    background: rgba(245,166,35,0.08);
    border: 1px solid rgba(245,166,35,0.2);
    border-radius: 7px;
    font-size: 0.875rem; color: var(--cv-text-muted);
}
.cv-weight-summary strong { color: #f5a623; }

/* ── Inputs ──────────────────────────────────────────────── */
.cv-input, .cv-textarea {
    width: 100%; padding: 0.5rem 0.75rem;
    background: var(--cv-bg-base);
    border: 1px solid var(--cv-border);
    border-radius: 7px;
    color: var(--cv-text-primary);
    font-size: 0.875rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    font-family: inherit;
}
.cv-select {
    width: 100%; padding: 0.5rem 0.75rem;
    background: #023b15;
    border: 1px solid var(--cv-border);
    border-radius: 7px;
    color: var(--cv-text-primary);
    font-size: 0.875rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    font-family: inherit;
}
.cv-input:focus, .cv-select:focus, .cv-textarea:focus {
    border-color: #4DBF7A;
    box-shadow: 0 0 0 3px rgba(77,191,122,0.12);
}
.cv-input-error { border-color: #e05252 !important; }
.cv-select:disabled { opacity: 0.5; cursor: not-allowed; }
.cv-textarea { resize: vertical; min-height: 80px; }

/* Input group */
.cv-input-group { display: flex; align-items: stretch; }
.cv-input-prefix, .cv-input-suffix {
    padding: 0.5rem 0.65rem;
    background: var(--cv-bg-card);
    border: 1px solid var(--cv-border);
    font-size: 0.78rem; font-weight: 600;
    color: var(--cv-text-muted);
    display: flex; align-items: center; white-space: nowrap;
}
.cv-input-prefix { border-right: none; border-radius: 7px 0 0 7px; }
.cv-input-suffix { border-left: none; border-radius: 0 7px 7px 0; }
.cv-input-prefixed { border-radius: 0 7px 7px 0; }
.cv-input-suffixed { border-radius: 7px 0 0 7px; }
[dir="rtl"] .cv-input-prefix { border-right: 1px solid var(--cv-border); border-left: none; border-radius: 0 7px 7px 0; }
[dir="rtl"] .cv-input-suffix { border-left: 1px solid var(--cv-border); border-right: none; border-radius: 7px 0 0 7px; }
[dir="rtl"] .cv-input-prefixed { border-radius: 7px 0 0 7px; }
[dir="rtl"] .cv-input-suffixed { border-radius: 0 7px 7px 0; }

/* ── Checkbox ────────────────────────────────────────────── */
.cv-checkbox-label { display: flex; align-items: center; gap: 0.6rem; cursor: pointer; font-size: 0.875rem; color: var(--cv-text-primary); }
.cv-custom-checkbox {
    width: 20px; height: 20px; border-radius: 5px;
    border: 2px solid var(--cv-border);
    background: var(--cv-bg-base);
    display: flex; align-items: center; justify-content: center;
    transition: all 0.15s; flex-shrink: 0;
}
.cv-custom-checkbox.checked { background: #4DBF7A; border-color: #4DBF7A; }
.cv-custom-checkbox svg { width: 12px; height: 12px; color: #fff; }

/* ── Photo ───────────────────────────────────────────────── */
.cv-photo-section { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
.cv-photo-preview-wrap { flex-shrink: 0; }
.cv-photo-preview { width: 100px; height: 100px; border-radius: 10px; object-fit: cover; border: 2px solid var(--cv-border); }
.cv-photo-placeholder {
    width: 100px; height: 100px; border-radius: 10px;
    background: var(--cv-bg-base); border: 2px dashed var(--cv-border);
    display: flex; align-items: center; justify-content: center; color: var(--cv-text-muted);
}
.cv-photo-placeholder svg { width: 32px; height: 32px; }
.cv-photo-controls { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.cv-photo-hint { font-size: 0.75rem; color: var(--cv-text-muted); }

/* ── Bulk Table ──────────────────────────────────────────── */
.cv-table-wrap { overflow-x: auto; }
.cv-bulk-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 700px; }
.cv-th {
    padding: 0.6rem 0.75rem;
    text-align: start;
    font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;
    color: var(--cv-text-muted);
    background: var(--cv-bg-base);
    border-bottom: 2px solid var(--cv-border);
    white-space: nowrap;
}
.cv-th-num   { width: 48px; text-align: center; }
.cv-th-calc  { color: #4DBF7A; }
.cv-th-action { width: 52px; text-align: center; }

.cv-tr { border-bottom: 1px solid var(--cv-border); transition: background 0.1s; }
.cv-tr:hover { background: var(--cv-bg-hover, rgba(77,191,122,0.04)); }
.cv-tr-even { background: rgba(0,0,0,0.04); }

.cv-td { padding: 0.4rem 0.5rem; vertical-align: middle; }
.cv-td-num { text-align: center; font-weight: 600; color: var(--cv-text-muted); font-size: 0.75rem; }
.cv-td-weight { width: 110px; }
.cv-td-calc { color: #4DBF7A; font-weight: 700; font-size: 0.82rem; padding-inline-end: 1rem; }
.cv-td-action { text-align: center; }

.cv-table-input {
    width: 100%; padding: 0.35rem 0.5rem;
    background: var(--cv-bg-base);
    border: 1px solid transparent;
    border-radius: 5px;
    color: var(--cv-text-primary);
    font-size: 0.82rem;
    font-family: inherit;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.cv-table-input:focus {
    border-color: #4DBF7A;
    box-shadow: 0 0 0 2px rgba(77,191,122,0.15);
    background: var(--cv-bg-card);
}
.cv-table-input--weight { font-weight: 700; }

.cv-calc-value { display: block; text-align: end; padding-inline-end: 0.25rem; }

/* Copy-down button */
.cv-copy-btn {
    width: 28px; height: 28px;
    background: transparent;
    border: 1px solid var(--cv-border);
    border-radius: 5px;
    color: var(--cv-text-muted);
    font-size: 1rem; line-height: 1;
    cursor: pointer; transition: all 0.15s;
    display: inline-flex; align-items: center; justify-content: center;
}
.cv-copy-btn:hover:not(:disabled) { background: rgba(77,191,122,0.1); color: #4DBF7A; border-color: #4DBF7A; }
.cv-copy-btn:disabled { opacity: 0.25; cursor: not-allowed; }

/* Empty table hint */
.cv-empty-table-hint {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 0.75rem; padding: 3rem;
    color: var(--cv-text-muted); text-align: center;
}
.cv-empty-icon { width: 40px; height: 40px; opacity: 0.4; }
.cv-empty-table-hint p { font-size: 0.875rem; margin: 0; max-width: 320px; }

/* ── Buttons ─────────────────────────────────────────────── */
.cv-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.55rem 1.1rem; border-radius: 8px;
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; border: none; transition: all 0.15s;
    text-decoration: none; white-space: nowrap;
}
.cv-btn-icon { width: 16px; height: 16px; flex-shrink: 0; }
.cv-btn-amber { background: #f5a623; color: #1a0e00; box-shadow: 0 0 10px rgba(245,166,35,0.25); }
.cv-btn-amber:hover:not(:disabled) { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(245,166,35,0.35); }
.cv-btn-amber:disabled { opacity: 0.6; cursor: not-allowed; }
.cv-btn-secondary { background: var(--cv-bg-card); color: var(--cv-text-primary); border: 1px solid var(--cv-border); }
.cv-btn-secondary:hover { background: var(--cv-bg-hover); }
.cv-btn-ghost { background: transparent; color: var(--cv-text-muted); border: 1px solid var(--cv-border); }
.cv-btn-ghost:hover { background: var(--cv-bg-hover); color: var(--cv-text-primary); }
.cv-btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
.cv-submit-btn { min-width: 160px; justify-content: center; }
.cv-upload-btn { cursor: pointer; position: relative; }
.cv-file-input-hidden { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }

/* Spinner */
.cv-spinner { animation: cv-spin 0.8s linear infinite; }
@keyframes cv-spin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
    .cv-form-body { padding: 1rem; }
    .cv-form-footer { padding: 1rem; }
    .cv-section-grid { grid-template-columns: 1fr; }
    .cv-field-sm, .cv-field-md, .cv-field-lg, .cv-field-full { grid-column: span 1; }
    .cv-page-hero { padding: 1.25rem 1rem; }
    .cv-page-hero-title { font-size: 1.3rem; }
    .cv-mode-selector { padding: 0 1rem; }
    .cv-mode-tab { padding: 0.75rem 0.9rem; font-size: 0.8rem; }
    .cv-excel-controls { margin-inline-start: 0; }
    .cv-card--table .cv-section-heading { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .cv-photo-section { flex-direction: column; align-items: flex-start; }
}
</style>