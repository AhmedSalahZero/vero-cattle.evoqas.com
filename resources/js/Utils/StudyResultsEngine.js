/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  InvestaWatch — Financial Study Results Engine  v4.0
 *  File: resources/js/Utils/StudyResultsEngine.js
 *
 *  KEY FIXES vs v3 (based on Statement & Taxes Calculations.xlsx):
 *
 *  1. AR (Customers Receivable) End Balance:
 *     AR = Beginning Balance + Revenue + Sales VAT − Total Collections
 *     Total Collections = (Revenue + Sales VAT − Debit WHT) collected per policy
 *                         + Previous period schedule payments
 *     AR tracks the FULL gross invoice amount (revenue + VAT), not just net revenue
 *
 *  2. AP (Suppliers Payable) End Balance:
 *     AP = Beginning Balance + Purchases + Purchase VAT − Total Payments
 *     Total Payments = (Purchases + Purchase VAT − Credit WHT) paid per policy
 *     AP tracks the FULL gross payable (COGS + input VAT), net of Credit WHT withheld
 *
 *  3. VAT End Balance (Net VAT Payable):
 *     VAT Payable = Sales VAT − Purchase VAT (net position)
 *     If positive → pay to authority after 30 days (next month)
 *     If negative → carry forward as VAT credit (no payment)
 *     Beginning balance carries forward until paid
 *
 *  4. Corporate Taxes:
 *     Monthly: Debit WHT withheld from customers accumulates as credit
 *     December year-end: Corporate Tax = Annual EBT × Tax Rate − Annual WHT
 *     Payment: April of the FOLLOWING year (Egyptian tax law)
 *     BS shows as liability (positive = owe authority, negative = credit/prepayment)
 *     No monthly tax cash payments — only once per year in April
 *
 *  5. Credit WHT (Supplier-side withholding):
 *     When paying suppliers we withhold Credit WHT from them
 *     We accumulate this and pay to tax authority every QUARTER
 *     Payment months: January, April, July, October
 *     e.g. Oct+Nov+Dec withheld → paid at Jan; Jan+Feb+Mar → paid at April
 *
 *  6. P&L tax line: shows annual corporate tax accrual (booked in December)
 *     Monthly P&L shows 0 tax for Jan–Nov, full annual tax in December
 *
 * ═══════════════════════════════════════════════════════════════════════════
 */

// ─────────────────────────────────────────────────────────────────────────────
//  UTILITIES
// ─────────────────────────────────────────────────────────────────────────────

function addMonths(yyyymm, n) {
  const [y, m] = yyyymm.split('-').map(Number)
  const d = new Date(y, m - 1 + n)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

function toYM(s) {
  if (!s) return null
  return String(s).slice(0, 7)
}

function monthDiff(startYM, endYM) {
  const [sy, sm] = startYM.split('-').map(Number)
  const [ey, em] = endYM.split('-').map(Number)
  return (ey - sy) * 12 + (em - sm)
}

function buildTimeline(startYM, totalMonths) {
  return Array.from({ length: totalMonths }, (_, i) => addMonths(startYM, i))
}

function applyPaymentPolicy(arr, idx, amount, policy) {
  if (!amount || amount === 0) return
  const tranches = policy?.tranches ?? [{ pct: 100, days: 0 }]
  for (const t of tranches) {
    const pct = Number(t.pct) || 0
    const days = Number(t.days) || 0
    if (pct === 0) continue
    const target = idx + Math.round(days / 30)
    if (target < arr.length) arr[target] += (amount * pct) / 100
  }
}

function applyCollectionPolicy(arr, idx, amount, policy) {
  if (!amount || amount === 0) return
  const tranches = policy?.tranches ?? [{ pct: 100, days: 0 }]
  for (const t of tranches) {
    const pct = Number(t.pct) || 0
    const days = Number(t.days) || 0
    if (pct === 0) continue
    const target = idx + Math.round(days / 30)
    if (target < arr.length) arr[target] += (amount * pct) / 100
  }
}

const CASH_POLICY       = { tranches: [{ pct: 100, days: 0  }] }
const THIRTY_DAY_POLICY = { tranches: [{ pct: 100, days: 30 }] }

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 1 — REVENUE
// ─────────────────────────────────────────────────────────────────────────────
/*
 *  ACCOUNTING (per Excel model):
 *
 *  Invoice issued to customer:
 *    Net Revenue (P&L)         100,000   → P&L line
 *    + Sales VAT               14,000    → Adds to AR, VAT Payable
 *    Gross invoice             114,000   → AR increases by this amount
 *
 *  When customer pays (per collection policy):
 *    Cash received = (Revenue + VAT − Debit WHT) per tranche
 *    Debit WHT = Revenue collected × WHT rate  → Corporate Tax prepayment
 *    AR decreases by (Revenue + VAT) of that tranche
 *
 *  AR End Balance = Total Dues (cum) − Total Collections (cum)
 *    Total Dues       = Beg Balance + Revenue invoiced + Sales VAT invoiced
 *    Total Collections = Revenue collected + VAT collected − Debit WHT
 *
 *  VAT Payable (net) = Sales VAT invoiced − Purchase VAT on COGS (net position)
 *    → paid to authority the following month (30-day rule)
 *    → if negative, carry forward as credit
 *
 *  Debit WHT accumulates monthly → offsets corporate tax at year-end (December booking)
 */
// productDefs = props.products from Create.vue (step 1) — carries vat_rate & withhold_tax_rate
// projections  = props.projections from SalesProjection.vue (step 2) — carries prices, volumes, collection policies
// The two arrays are parallel by index: productDefs[i] matches projections.products[i]
function calcRevenue(study, projections, productDefs) {
  const totalMonths = study.duration_years * 12
  const startYM     = toYM(study.study_start_date)
  const timeline    = buildTimeline(startYM, totalMonths)
  const products    = projections?.products ?? []
  const defs        = productDefs ?? []   // step-1 product definitions

  // P&L
  const revenueByMonth       = new Array(totalMonths).fill(0)

  // AR components
  const salesVatInvoicedByMonth = new Array(totalMonths).fill(0)  // Sales VAT added to AR
  const arGrossInvoicedByMonth  = new Array(totalMonths).fill(0)  // Revenue + VAT invoiced
  const arGrossClearedByMonth   = new Array(totalMonths).fill(0)  // Revenue + VAT cleared (collected)

  // Cash flow
  const receiptsByMonth         = new Array(totalMonths).fill(0)  // Actual cash in (gross − WHT)
  const debitWhtByMonth         = new Array(totalMonths).fill(0)  // WHT withheld by customers (monthly)

  // VAT flow (output side)
  const salesVatByMonth         = new Array(totalMonths).fill(0)  // Sales VAT invoiced each month

  const revenueByProduct = []
  const volumeByProduct  = []

  for (let pi = 0; pi < products.length; pi++) {
    const prod = products[pi]
    // vat_rate and withhold_tax_rate live on the step-1 product definition (props.products),
    // NOT on the projections product (which only has prices/volumes/collection policy).
    // Fall back to prod itself in case they were somehow copied over.
    const def    = defs[pi] ?? prod
    const vatPct = (Number(def.vat_rate)          || Number(prod.vat_rate)          || 0) / 100
    const whtPct = (Number(def.withhold_tax_rate) || Number(prod.withhold_tax_rate) || 0) / 100

    const localPct  = (prod.market_split?.local_pct  ?? 100) / 100
    const exportPct = (prod.market_split?.export_pct ?? 0)   / 100

    const prodRev   = new Array(totalMonths).fill(0)
    const prodVol   = new Array(totalMonths).fill(0)
    const prodLocal = new Array(totalMonths).fill(0)
    const prodExp   = new Array(totalMonths).fill(0)

    // Build monthly revenue
    for (let mi = 0; mi < 12 && mi < totalMonths; mi++) {
      const mo  = prod.year1_months?.[mi] ?? {}
      const rev = (Number(mo.price) || 0) * (Number(mo.volume) || 0)
      prodRev[mi]   = rev
      prodVol[mi]   = Number(mo.volume) || 0
      prodLocal[mi] = rev * localPct
      prodExp[mi]   = rev * exportPct
      revenueByMonth[mi] += rev
    }
    for (let mi = 0; mi < 12 && mi + 12 < totalMonths; mi++) {
      const mo  = prod.year2_months?.[mi] ?? {}
      const rev = (Number(mo.price) || 0) * (Number(mo.volume) || 0)
      const idx = mi + 12
      prodRev[idx]   = rev
      prodVol[idx]   = Number(mo.volume) || 0
      prodLocal[idx] = rev * localPct
      prodExp[idx]   = rev * exportPct
      revenueByMonth[idx] += rev
    }
    for (let yi = 0; yi < (prod.annual_years ?? []).length; yi++) {
      const yr   = prod.annual_years[yi]
      const aRev = (Number(yr.price) || 0) * (Number(yr.volume) || 0)
      const aVol = Number(yr.volume) || 0
      const base = (yi + 2) * 12
      for (let mi = 0; mi < 12 && base + mi < totalMonths; mi++) {
        const idx = base + mi
        prodRev[idx]   = aRev / 12
        prodVol[idx]   = aVol / 12
        prodLocal[idx] = (aRev / 12) * localPct
        prodExp[idx]   = (aRev / 12) * exportPct
        revenueByMonth[idx] += aRev / 12
      }
    }

    const colLocal     = prod.collection_local  ?? CASH_POLICY
    const colExport    = prod.collection_export ?? CASH_POLICY
    const hasBreakdown = prod.local_allocation?.dimension !== 'none'
                      && (prod.local_allocation?.rows?.length ?? 0) > 0

    for (let m = 0; m < totalMonths; m++) {
      const localRev  = prodLocal[m]
      const exportRev = prodExp[m]
      if (localRev === 0 && exportRev === 0) continue

      // VAT only on local sales (export is zero-rated in Egypt)
      const vatOnLocal = localRev * vatPct

      // Gross invoice = Revenue + VAT
      const localGross  = localRev + vatOnLocal
      const exportGross = exportRev  // no VAT on exports

      // Record VAT invoiced
      salesVatByMonth[m]          += vatOnLocal
      salesVatInvoicedByMonth[m]  += vatOnLocal

      // Gross amounts to AR
      arGrossInvoicedByMonth[m] += localGross + exportGross

      // Apply collection policy to gross invoice amounts
      // When collected: cash = gross − WHT; WHT = localRev-portion × whtPct
      const applyLocal = (grossAmt, revAmt, policy) => {
        if (!grossAmt) return
        const tranches = policy?.tranches ?? [{ pct: 100, days: 0 }]
        for (const t of tranches) {
          const pct  = (Number(t.pct) || 0) / 100
          const days = Number(t.days) || 0
          if (pct === 0) continue
          const target   = m + Math.round(days / 30)
          if (target >= totalMonths) continue
          const grossTranche = grossAmt * pct
          const revTranche   = revAmt   * pct
          const wht          = revTranche * whtPct
          receiptsByMonth[target]        += grossTranche - wht   // cash in
          debitWhtByMonth[target]        += wht                  // WHT credit
          arGrossClearedByMonth[target]  += grossTranche         // AR cleared
        }
      }

      const applyExport = (grossAmt, policy) => {
        if (!grossAmt) return
        const tranches = policy?.tranches ?? [{ pct: 100, days: 0 }]
        for (const t of tranches) {
          const pct  = (Number(t.pct) || 0) / 100
          const days = Number(t.days) || 0
          if (pct === 0) continue
          const target = m + Math.round(days / 30)
          if (target >= totalMonths) continue
          receiptsByMonth[target]       += grossAmt * pct
          arGrossClearedByMonth[target] += grossAmt * pct
        }
      }

      if (hasBreakdown) {
        for (const row of prod.local_allocation.rows) {
          const frac = (Number(row.pct) || 0) / 100
          const pol  = row.collection_policy ?? colLocal
          applyLocal(localGross * frac, localRev * frac, pol)
        }
      } else {
        applyLocal(localGross, localRev, colLocal)
      }
      applyExport(exportGross, colExport)
    }

    revenueByProduct.push(prodRev)
    volumeByProduct.push(prodVol)
  }

  // ── AR End Balance = cumulative invoiced − cumulative cleared ─────────────
  const arByMonth = new Array(totalMonths).fill(0)
  let cumARInv = 0, cumARClr = 0
  for (let m = 0; m < totalMonths; m++) {
    cumARInv += arGrossInvoicedByMonth[m]
    cumARClr += arGrossClearedByMonth[m]
    arByMonth[m] = Math.max(0, cumARInv - cumARClr)
  }

  return {
    revenueByMonth,
    revenueByProduct,
    volumeByProduct,
    receiptsByMonth,             // cash from customers (gross − Debit WHT)
    salesVatByMonth,             // Sales VAT invoiced each month (output VAT)
    debitWhtByMonth,             // Debit WHT withheld by customers (monthly)
    arByMonth,                   // BS: Customers Receivable (gross, incl VAT)
    arGrossInvoicedByMonth,      // internal
    arGrossClearedByMonth,       // internal
    timeline,
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 2 — COGS  (with AP tracking, Purchase VAT, Credit WHT)
// ─────────────────────────────────────────────────────────────────────────────
/*
 *  ACCOUNTING (per Excel model):
 *
 *  Supplier invoice received:
 *    Purchase amount (COGS)    100,000   → P&L COGS
 *    + Purchase VAT            14,000    → Input VAT (reduces net VAT payable)
 *    Gross payable             114,000   → AP increases by this amount
 *
 *  When we pay supplier (per payment policy):
 *    Cash paid = (Purchase + Purchase VAT − Credit WHT) per tranche
 *    Credit WHT = Purchase amount paid × Credit WHT rate → liability to pay quarterly
 *    AP decreases by (Purchase + Purchase VAT) of that tranche
 *
 *  AP End Balance = Total Dues − Total Payments
 *    Total Dues     = Beg Balance + Purchases + Purchase VAT
 *    Total Payments = Purchases paid + Purchase VAT paid − Credit WHT
 *
 *  Credit WHT accumulates each month, paid quarterly (Jan/Apr/Jul/Oct)
 *
 *  MANUFACTURING — Full Inventory Statement (v5.0):
 *  ─────────────────────────────────────────────────
 *  Step A: Finished Goods Quantity Statement (per product, per month)
 *    - Coverage days from SalesProjection (prod.inventory_coverage_days)
 *    - Target End Qty = next-month sold qty × (coverage_days / 30)
 *    - If BegQty >= SoldQty + TargetEndQty → No manufacturing (Mfg = 0)
 *    - Else → Mfg = SoldQty + TargetEndQty − BegQty
 *
 *  Step B: Raw Material Quantity Statement (per RM, per month)
 *    - Coverage days from CogsStep (rm.inventory_days)
 *    - Target End Qty = next-month dispersed qty × (coverage_days / 30)
 *    - Dispersed Qty = Mfg qty × qty_per_unit (BOM) or derived from pct_selling
 *    - If BegQty >= DispersedQty + TargetEndQty → No purchase
 *    - Else → Purchase = DispersedQty + TargetEndQty − BegQty
 *
 *  Step C: Raw Material Value Statement (weighted average cost)
 *    - RM Dispersed Value = (BegVal + PurchaseVal) / TotalAvailQty × DispersedQty
 *    - RM End Balance Value = TotalAvailVal − Dispersed Value
 *
 *  Step D: Finished Goods Value Statement (weighted average cost)
 *    - Total Mfg Cost = RM Dispersed + Direct Labor (allocated) + Indirect Labor (allocated)
 *                       + Mfg Overheads + Mfg Depreciation (allocated)
 *    - COGS split = (Cumulative RM/DL/OH cost + BegInventoryValue portion) / TotalAvailQty × SoldQty
 *    - End Inventory Value = TotalAvail − COGS
 */

// productDefs = props.products from Create.vue (step 1) — carries vat_rate & withhold_tax_rate per product
// projections = props.projections from SalesProjection (has inventory_coverage_days, beg_inv_qty, beg_inv_amount, beg_inv_breakdown per product)
// manpowerData = from ManpowerStep — has dept, product_allocation, salary data
// depMfgByProduct = from calcFixedAssets — manufacturing depreciation per product per month
function calcCOGS(study, cogsData, revenueByProduct, volumeByProduct, productDefs, projections, manpowerData, depMfgByProduct, rawMaterialDefs) {
  const totalMonths = study.duration_years * 12
  const startYM     = toYM(study.study_start_date)

  const cogsByMonth        = new Array(totalMonths).fill(0)  // P&L COGS
  const cogsIncurred       = new Array(totalMonths).fill(0)  // raw COGS incurred
  const purchaseVatByMonth = new Array(totalMonths).fill(0)  // Input VAT on purchases

  // AP components (gross = COGS + Purchase VAT)
  const apGrossInvoicedByMonth = new Array(totalMonths).fill(0)
  const apGrossPaidByMonth     = new Array(totalMonths).fill(0)

  // Cash out to suppliers = (COGS + Purchase VAT − Credit WHT) paid
  const cogsPaymentsByMonth = new Array(totalMonths).fill(0)  // actual cash paid to suppliers
  const creditWhtByMonth    = new Array(totalMonths).fill(0)  // Credit WHT withheld from suppliers

  const cogsByProduct     = []
  const inventoryByMonth  = new Array(totalMonths).fill(0)  // BS: inventory end balance (trading + finished goods)
  // Per-product COGS breakdown (for P&L drill-down)
  // Each entry: { name, nature, rmCogs[], dlCogs[], ohCogs[], tradingCogs[], serviceCogs[] }
  const cogsByProductDetail = []

  // ── Helper: apply a supplier payment with specific VAT and Credit WHT rates ──
  const applySupplierPayment = (cogsAmt, idx, policy, vatPct, cWhtPct) => {
    if (!cogsAmt) return
    const vatOnPurchase = cogsAmt * vatPct
    const grossPayable  = cogsAmt + vatOnPurchase
    apGrossInvoicedByMonth[idx] += grossPayable
    purchaseVatByMonth[idx]     += vatOnPurchase   // input VAT (at invoice date)

    const tranches = policy?.tranches ?? [{ pct: 100, days: 0 }]
    for (const t of tranches) {
      const pct    = (Number(t.pct) || 0) / 100
      const days   = Number(t.days) || 0
      if (pct === 0) continue
      const target = idx + Math.round(days / 30)
      if (target >= totalMonths) continue
      const cogsT     = cogsAmt      * pct
      const grossT    = grossPayable * pct
      const creditWht = cogsT * cWhtPct

      cogsPaymentsByMonth[target] += grossT - creditWht   // cash actually paid to supplier
      creditWhtByMonth[target]    += creditWht             // Credit WHT we withheld from supplier
      apGrossPaidByMonth[target]  += grossT                // AP cleared (gross amount)
    }
  }

  // ── Pre-compute manpower costs per product per month (Direct + Indirect Labor) ──
  // manpower row has: dept, product_allocation (array of {product_name, pct}), net_salary, salary_taxes_pct, social_insurance_pct, annual_increase_pct, y1_count, y2_count, annual_count
  const mfgProductNames = (productDefs ?? []).filter(p => p.nature === 'manufacturing').map(p => p.name)

  // directLaborByProductByMonth[pi_within_mfg][m] = direct labor cost allocated to this mfg product
  // indirectLaborByProductByMonth[pi_within_mfg][m] = indirect labor cost allocated to this mfg product
  const directLaborByProductByMonth   = mfgProductNames.map(() => new Array(totalMonths).fill(0))
  const indirectLaborByProductByMonth = mfgProductNames.map(() => new Array(totalMonths).fill(0))

  for (const row of (manpowerData ?? [])) {
    if (row.dept !== 'direct_labor' && row.dept !== 'indirect_labor') continue
    const alloc   = row.product_allocation ?? []  // [{product_name, pct}]
    if (alloc.length === 0) continue               // unallocated labor doesn't go into COGS

    const base   = (Number(row.net_salary) || 0) * (1 + (Number(row.salary_taxes_pct) || 0) / 100 + (Number(row.social_insurance_pct) || 0) / 100)
    const annInc = (Number(row.annual_increase_pct) || 0) / 100

    for (let m = 0; m < totalMonths; m++) {
      const year  = Math.floor(m / 12)
      const gross = base * Math.pow(1 + annInc, year)
      const count = year === 0 ? (row.y1_count?.[m % 12] ?? 0) : year === 1 ? (row.y2_count?.[m % 12] ?? 0) : (row.annual_count?.[year - 2] ?? 0)
      const cost  = gross * count
      if (!cost) continue

      for (const a of alloc) {
        const mpi = mfgProductNames.indexOf(a.product_name)
        if (mpi < 0) continue
        const frac = (Number(a.pct) || 0) / 100
        if (row.dept === 'direct_labor')   directLaborByProductByMonth[mpi][m]   += cost * frac
        if (row.dept === 'indirect_labor') indirectLaborByProductByMonth[mpi][m] += cost * frac
      }
    }
  }

  // ── Map the global product index (pi) to mfg-only index (mpi) ──
  let mfgCounter = -1

  for (let pi = 0; pi < cogsData.length; pi++) {
    const cog     = cogsData[pi]
    const prodCog      = new Array(totalMonths).fill(0)
    const prodDef      = (productDefs ?? [])[pi] ?? {}
    // Per-product COGS breakdown arrays (for P&L drill-down)
    const detailRM      = new Array(totalMonths).fill(0)  // Raw Material cost
    const detailDL      = new Array(totalMonths).fill(0)  // Direct + Indirect Labor cost
    const detailOH      = new Array(totalMonths).fill(0)  // Manufacturing Overheads (non-dep)
    const detailTrading = new Array(totalMonths).fill(0)  // Trading COGS
    const detailService = new Array(totalMonths).fill(0)  // Service COGS

    // ══════════════════════════════════════════════════════════════════════════
    //  MANUFACTURING  —  Full Inventory Statement Engine
    // ══════════════════════════════════════════════════════════════════════════
    if (cog.nature === 'manufacturing') {
      mfgCounter++
      const mpi = mfgCounter  // index within mfg-only arrays

      // ── Finished Goods opening inventory ─────────────────────────────────
      // PRIMARY SOURCE: cog object (cogsData from DB) — CogsStep now embeds
      // beg_inv_qty / beg_inv_amount / beg_inv_breakdown / inventory_coverage_days
      // directly from Step 2 (SalesProjection) via the fgInventory prop.
      // FALLBACK: projections array (Step 2 data) matched by product name.
      // This two-layer approach means COGS always sees the correct FG opening
      // balance even if projections name-matching fails for any reason.
      const _cogProductName = cog.product_name ?? prodDef.name ?? ''
      const _projFallback = (() => {
        if (!Array.isArray(projections)) return {}
        const byName = projections.find(pp => pp?.name && pp.name === _cogProductName)
        return byName ?? projections[pi] ?? {}
      })()

      // Read from cogsData first (embedded by CogsStep), fall back to projections
      const fgCoverageDays = Number(
        cog.inventory_coverage_days ?? _projFallback.inventory_coverage_days ?? 30
      )
      let fgBegQty   = Number(cog.beg_inv_qty    ?? _projFallback.beg_inv_qty    ?? 0)
      const fgBegAmount = Number(cog.beg_inv_amount ?? _projFallback.beg_inv_amount ?? 0)

      // beg_inv_breakdown: { raw_material_pct, direct_labor_pct, overheads_pct }
      // Saved from the Breakdown modal in SalesProjection.vue.
      const _rawBreakdown = cog.beg_inv_breakdown ?? _projFallback.beg_inv_breakdown ?? null
      const fgBreakdown = (_rawBreakdown && typeof _rawBreakdown === 'object')
        ? _rawBreakdown
        : { raw_material_pct: 84, direct_labor_pct: 3, overheads_pct: 13 }

      const _rmPct = Number(fgBreakdown.raw_material_pct ?? 84)
      const _dlPct = Number(fgBreakdown.direct_labor_pct ??  3)
      const _ohPct = Number(fgBreakdown.overheads_pct    ?? 13)

      // Opening FG value split by bucket — these seed the weighted-average cost pool
      let fgBegRM = fgBegAmount * _rmPct / 100
      let fgBegDL = fgBegAmount * _dlPct / 100
      let fgBegOH = fgBegAmount * _ohPct / 100

      // Pre-compute last-year average monthly sold volume (fallback for last month)
      const lastYrStart   = Math.max(0, totalMonths - 12)
      const lastYrAvgVol  = Array.from({ length: 12 }, (_, i) => volumeByProduct[pi]?.[lastYrStart + i] || 0).reduce((s, v) => s + v, 0) / 12

      // ── Shared overheads (from CogsStep, already injected into cog.overheads by save logic) ──
      const overheads = cog.overheads ?? []

      // ── Determine calculation mode ───────────────────────────────────────
      const rmMethod = cog.rm_method ?? 'bom'

      if (rmMethod === 'pct_selling') {
        // ══════════════════════════════════════════════════════════════════════
        //  % OF SELLING PRICE MODE — with FG Inventory Statement
        //
        //  The pct_selling % represents the RM cost of MANUFACTURING a unit.
        //  It only applies to units that are actually manufactured this month.
        //  Units sold from the beginning inventory pool cost nothing new to
        //  purchase — they already carry their cost from the opening breakdown.
        //
        //  Flow per month:
        //  Step A: FG Qty Statement — same as BOM (determine mfgQty needed)
        //  Step B: RM purchase cost = mfgQty × unit_selling_price × pct
        //          (only purchased when manufacturing happens)
        //  Step C: FG Value pool = beginning pool + production cost this month
        //  Step D: COGS drawn from pool by weighted avg × soldQty
        //  Step E: FG end balance carries forward to Balance Sheet
        // ══════════════════════════════════════════════════════════════════════

        const lastYrStart2  = Math.max(0, totalMonths - 12)
        const lastYrAvgVol2 = Array.from({ length: 12 }, (_, i) => volumeByProduct[pi]?.[lastYrStart2 + i] || 0).reduce((s,v) => s+v, 0) / 12

        for (let m = 0; m < totalMonths; m++) {
          const year    = Math.floor(m / 12)
          const soldQty = volumeByProduct[pi]?.[m] || 0
          const rev     = revenueByProduct[pi]?.[m] || 0

          // ── Step A: FG Quantity Statement ─────────────────────────────────
          const isLastMonth2  = (m + 1 >= totalMonths)
          const nextSoldQty2  = isLastMonth2 ? lastYrAvgVol2 : (volumeByProduct[pi]?.[m+1] || 0)
          const targetFgEnd2  = nextSoldQty2 * (fgCoverageDays / 30)
          let   mfgQty2       = 0
          if (fgBegQty < soldQty + targetFgEnd2) {
            mfgQty2 = soldQty + targetFgEnd2 - fgBegQty
          }
          const totalAvailFgQty2 = fgBegQty + mfgQty2

          // ── Step B: RM purchase cost — ONLY when manufacturing happens ────
          // pct_selling % = RM cost as % of selling price per manufactured unit.
          // Unit selling price = rev / soldQty (avoid div/0).
          // No purchase when mfgQty2 = 0 (beginning stock covers demand).
          let rmProdCostThisMonth = 0
          if (mfgQty2 > 0 && rev > 0 && soldQty > 0) {
            const unitPrice = rev / soldQty
            for (let rmi = 0; rmi < (cog.raw_materials ?? []).length; rmi++) {
              const rm        = cog.raw_materials[rmi]
              const rmVatPct  = (Number(rm.vat_rate)          || 0) / 100
              const rmCWhtPct = (Number(rm.withhold_tax_rate) || 0) / 100
              const annChange = (Number(rm.annual_change_pct) || 0) / 100
              const pct       = (Number(rm.pct_selling)       || 0) / 100
              const rmCostPerUnit = unitPrice * pct * Math.pow(1 + annChange, year)
              const rmCostTotal   = rmCostPerUnit * mfgQty2
              if (!rmCostTotal) continue

              rmProdCostThisMonth += rmCostTotal
              applySupplierPayment(rmCostTotal, m, rm.payment_policy ?? CASH_POLICY, rmVatPct, rmCWhtPct)
            }
          }

          // ── Step C & D: FG Value pool + weighted-avg COGS ─────────────────
          // Pool = beginning RM/DL/OH values + this month's production cost.
          // Production cost in pct_selling mode = RM cost only (no separate
          // DL or OH entries — the % already represents all input costs if user
          // chose this simplified mode; DL/OH from Manpower/Overheads are
          // handled separately in the P&L above the gross profit line).
          const totalAvailRM2 = fgBegRM + rmProdCostThisMonth
          const totalAvailDL2 = fgBegDL   // DL from beginning breakdown only
          const totalAvailOH2 = fgBegOH   // OH from beginning breakdown only

          const cogsRM2 = totalAvailFgQty2 > 0 ? (totalAvailRM2 / totalAvailFgQty2) * soldQty : 0
          const cogsDL2 = totalAvailFgQty2 > 0 ? (totalAvailDL2 / totalAvailFgQty2) * soldQty : 0
          const cogsOH2 = totalAvailFgQty2 > 0 ? (totalAvailOH2 / totalAvailFgQty2) * soldQty : 0
          const totalCOGS2 = cogsRM2 + cogsDL2 + cogsOH2

          if (totalCOGS2 > 0) {
            prodCog[m]     += totalCOGS2
            cogsByMonth[m] += totalCOGS2
            cogsIncurred[m]+= totalCOGS2
            detailRM[m]    += cogsRM2
            detailDL[m]    += cogsDL2
            detailOH[m]    += cogsOH2
          }

          // ── Step E: FG end balance carry-forward ──────────────────────────
          fgBegRM  = Math.max(0, totalAvailRM2 - cogsRM2)
          fgBegDL  = Math.max(0, totalAvailDL2 - cogsDL2)
          fgBegOH  = Math.max(0, totalAvailOH2 - cogsOH2)
          fgBegQty = Math.max(0, totalAvailFgQty2 - soldQty)

          // FG remaining balance → Balance Sheet inventory
          inventoryByMonth[m] += fgBegRM + fgBegDL + fgBegOH
        }

      } else {
        // ══════════════════════════════════════════════════════════════════════
        //  BOM (BILL OF MATERIALS) MODE — Full Inventory Statement Engine
        // ══════════════════════════════════════════════════════════════════════

        // Pre-compute last-year average monthly sold volume (fallback for last month)
        const lastYrStart  = Math.max(0, totalMonths - 12)
        const lastYrAvgVol = Array.from({ length: 12 }, (_, i) => volumeByProduct[pi]?.[lastYrStart + i] || 0).reduce((s, v) => s + v, 0) / 12

        for (let m = 0; m < totalMonths; m++) {
          const year    = Math.floor(m / 12)
          const soldQty = volumeByProduct[pi]?.[m] || 0
          // ── Step A: Finished Goods Quantity Statement ──────────────────────
          const isLastMonth = (m + 1 >= totalMonths)
          const nextSoldQty = isLastMonth ? lastYrAvgVol : (volumeByProduct[pi]?.[m + 1] || 0)
          const targetFgEnd = nextSoldQty * (fgCoverageDays / 30)

          let mfgQty = 0
          if (fgBegQty < soldQty + targetFgEnd) {
            mfgQty = soldQty + targetFgEnd - fgBegQty
          }
          const totalAvailFgQty = fgBegQty + mfgQty

          // ── Step B & C: Raw Material Inventory Statements (one per RM) ──────
          let rmDispersedValueThisMonth = 0

          for (let rmi = 0; rmi < (cog.raw_materials ?? []).length; rmi++) {
            const rm        = cog.raw_materials[rmi]
            const rmVatPct  = (Number(rm.vat_rate)          || 0) / 100
            const rmCWhtPct = (Number(rm.withhold_tax_rate) || 0) / 100
            const rmDef     = (rawMaterialDefs ?? [])[rmi] ?? {}
            const rmCovDays = Number(rmDef.rm_inventory_coverage_days || rm.inventory_days || 30)
            const cpu       = (Number(rm.cost_per_unit) || 0) * Math.pow(1 + (Number(rm.annual_increase_pct) || 0) / 100, year)

            // Units of RM needed to manufacture mfgQty finished units
            const rmDispersedQty = mfgQty * (Number(rm.qty_per_unit) || 0)

            // Initialise RM running inventory (first month only)
            if (rm._rmInvQty === undefined) {
              rm._rmInvQty = Number(rm.beg_inventory_qty   || 0)
              rm._rmInvVal = Number(rm.beg_inventory_value || 0) || (rm._rmInvQty * cpu)
            }

            // Target RM end stock = next month's dispersion × coverage ratio
            const nextMfgQty        = isLastMonth ? mfgQty : (() => {
              const nxt = volumeByProduct[pi]?.[m + 1] || 0
              const nxtTarget = nxt * (fgCoverageDays / 30)
              const nxtBegFg  = Math.max(0, totalAvailFgQty - soldQty)
              return nxtBegFg < nxt + nxtTarget ? nxt + nxtTarget - nxtBegFg : 0
            })()
            const nextRmDisp   = nextMfgQty * (Number(rm.qty_per_unit) || 0)
            const targetRmEnd  = nextRmDisp * (rmCovDays / 30)

            // Purchase decision
            let rmPurchasedQty  = 0
            let totalAvailRmQty = 0
            if (rm._rmInvQty >= rmDispersedQty + targetRmEnd) {
              // Condition 1: existing stock sufficient — no purchase
              totalAvailRmQty = rm._rmInvQty
            } else {
              // Condition 2: must purchase
              totalAvailRmQty = rmDispersedQty + targetRmEnd
              rmPurchasedQty  = totalAvailRmQty - rm._rmInvQty
            }

            const rmPurchasedVal  = rmPurchasedQty * cpu
            const totalAvailRmVal = rm._rmInvVal + rmPurchasedVal
            const avgRmCost       = totalAvailRmQty > 0 ? totalAvailRmVal / totalAvailRmQty : cpu

            // RM Dispersed Value → feeds into FG weighted avg cost
            const rmDispersedVal       = avgRmCost * rmDispersedQty
            rmDispersedValueThisMonth += rmDispersedVal

            // RM End Balance (carry forward)
            rm._rmInvQty = Math.max(0, totalAvailRmQty - rmDispersedQty)
            rm._rmInvVal = Math.max(0, totalAvailRmVal - rmDispersedVal)

            // Supplier AP / VAT / WHT
            if (rmPurchasedVal > 0) {
              applySupplierPayment(rmPurchasedVal, m, rm.payment_policy ?? CASH_POLICY, rmVatPct, rmCWhtPct)
            }
          }  // end per-RM loop

          // ── Overheads cost this month (allocated to this product) ──────────
          let ohCostThisMonth = 0
          for (const oh of overheads) {
            const si = oh.start_date ? Math.max(0, monthDiff(startYM, toYM(oh.start_date))) : 0
            const ei = oh.end_date   ? Math.min(totalMonths - 1, monthDiff(startYM, toYM(oh.end_date))) : totalMonths - 1
            if (m < si || m > ei) continue

            let allocFrac = 0
            if (oh.method === 'fixed_monthly') {
              const alloc = oh.product_allocation ?? []
              if (alloc.length === 0) {
                allocFrac = mfgProductNames.length > 0 ? 1 / mfgProductNames.length : 1
              } else {
                const found = alloc.find(a => a.product_name === prodDef.name)
                allocFrac = found ? (Number(found.pct) || 0) / 100 : 0
              }
            } else {
              const applyTo = oh.apply_to_products ?? []
              if (applyTo.length === 0 || applyTo.includes(prodDef.name)) {
                const cnt = applyTo.length === 0 ? mfgProductNames.length : applyTo.length
                allocFrac = cnt > 0 ? 1 / cnt : 1
              }
            }
            if (!allocFrac) continue

            let ohTotal = 0
            if (oh.method === 'fixed_monthly') {
              ohTotal = (Number(oh.amount) || 0) * Math.pow(1 + (Number(oh.annual_increase_pct) || 0) / 100, year)
            } else if (oh.method === 'cost_per_unit') {
              ohTotal = (Number(oh.amount) || 0) * Math.pow(1 + (Number(oh.annual_increase_pct) || 0) / 100, year) * mfgQty
            } else if (oh.method === 'pct_revenue') {
              ohTotal = (revenueByProduct[pi]?.[m] || 0) * ((Number(oh.pct_revenue) || 0) / 100) * Math.pow(1 + (Number(oh.annual_change_pct) || 0) / 100, year)
            }

            const ohAllocated = ohTotal * allocFrac
            ohCostThisMonth  += ohAllocated
            applyPaymentPolicy(cogsPaymentsByMonth, m, ohAllocated, oh.payment_policy ?? CASH_POLICY)
            applyPaymentPolicy(apGrossPaidByMonth,  m, ohAllocated, oh.payment_policy ?? CASH_POLICY)
            apGrossInvoicedByMonth[m] += ohAllocated
          }

          // ── Labor & Depreciation allocated to this product ─────────────────
          // IMPORTANT: Production costs (DL, OH, Dep) only enter the FG pool when
          // manufacturing actually happens this month (mfgQty > 0).
          // When mfgQty = 0, the beginning inventory pool sits unchanged — we only
          // draw COGS from it. Adding costs in a zero-production month would override
          // (inflate) the beginning inventory breakdown values incorrectly.
          const dlCostThisMonth = mfgQty > 0 ? (directLaborByProductByMonth[mpi]?.[m]  || 0) : 0
          const ilCostThisMonth = mfgQty > 0 ? (indirectLaborByProductByMonth[mpi]?.[m]|| 0) : 0
          const mfgDepThisMonth = mfgQty > 0 ? (depMfgByProduct?.[pi]?.[m]             || 0) : 0
          const ohCostProduction = mfgQty > 0 ? ohCostThisMonth : 0
          const totalMfgOHCost   = ilCostThisMonth + ohCostProduction + mfgDepThisMonth

          // ── Step D: Finished Goods Value Statement (weighted avg cost) ──────
          // Per spec: pool = beginning FG breakdown value (carry-forward) +
          //                  this month's production cost (only when mfgQty > 0)
          // "Adding RM Dispersed Cost + RM Value from Breakdown" in the spec means:
          //   the running pool which starts with the opening breakdown and accumulates
          //   each month's production cost, minus COGS drawn each month.
          const totalAvailRM = fgBegRM + (mfgQty > 0 ? rmDispersedValueThisMonth : 0)
          const totalAvailDL = fgBegDL + dlCostThisMonth
          const totalAvailOH = fgBegOH + totalMfgOHCost

          // COGS by bucket = (pool value / total avail qty) × sold qty  (weighted avg)
          const cogsRM       = totalAvailFgQty > 0 ? (totalAvailRM / totalAvailFgQty) * soldQty : 0
          const cogsDL       = totalAvailFgQty > 0 ? (totalAvailDL / totalAvailFgQty) * soldQty : 0
          const cogsOH_total = totalAvailFgQty > 0 ? (totalAvailOH / totalAvailFgQty) * soldQty : 0

          const totalCOGSThisProduct = cogsRM + cogsDL + cogsOH_total
          prodCog[m]     += totalCOGSThisProduct
          cogsByMonth[m] += totalCOGSThisProduct
          cogsIncurred[m]+= totalCOGSThisProduct
          detailRM[m]    += cogsRM
          detailDL[m]    += cogsDL
          detailOH[m]    += cogsOH_total

          // End FG inventory (carry forward to next month as beginning balance)
          // These ARE the "Finished Product Breakdown End Balance" values from the spec:
          //   FG End RM = (pool RM) - RM COGS
          //   FG End DL = (pool DL) - DL COGS
          //   FG End OH = (pool OH) - OH COGS
          fgBegRM  = Math.max(0, totalAvailRM - cogsRM)
          fgBegDL  = Math.max(0, totalAvailDL - cogsDL)
          fgBegOH  = Math.max(0, totalAvailOH - cogsOH_total)
          fgBegQty = Math.max(0, totalAvailFgQty - soldQty)

          // BS: Finished Goods inventory end balance = sum of three breakdown buckets
          inventoryByMonth[m] += fgBegRM + fgBegDL + fgBegOH

        }  // end per-month loop

        // Clean up transient RM state
        for (const rm of (cog.raw_materials ?? [])) {
          delete rm._rmInvQty
          delete rm._rmInvVal
        }
      }  // end BOM mode

    // ══════════════════════════════════════════════════════════════════════════
    //  TRADING  —  Inventory Statement Method (unchanged)
    // ══════════════════════════════════════════════════════════════════════════
    } else if (cog.nature === 'trading') {
      // ── TRADING: Inventory Statement Method ──────────────────────────────
      // Formula: COGS = (Beg.Value + Purchases.Value) / TotalAvailableQty × SoldQty
      // End Inventory Qty target = next-month's sold qty × (inventoryDays / 30)
      //
      // For trading: vat_rate and withhold_tax_rate live on the step-1 product definition
      const tradDef        = (productDefs ?? [])[pi] ?? {}
      const tradVatPct     = (Number(tradDef.vat_rate)          || 0) / 100
      const tradCWhtPct    = (Number(tradDef.withhold_tax_rate) || 0) / 100
      const inventoryDays  = Number(cog.inventory_days)          || 30
      const annualCostInc  = (Number(cog.annual_cost_increase_pct) || 0) / 100

      // Running inventory state (carry across months)
      let invQty = Number(cog.beginning_inventory_units) || 0
      let invVal = Number(cog.beginning_inventory_value) || 0

      // Pre-compute average monthly volume of the last 12 months of the study.
      // Used as a proxy for "next month's sales" when we are in the final month
      // (m+1 is out of bounds) so that ending inventory doesn't collapse to zero.
      const lastYearStart  = Math.max(0, totalMonths - 12)
      const lastYearVols   = Array.from({ length: 12 }, (_, i) =>
        volumeByProduct[pi]?.[lastYearStart + i] || 0
      )
      const lastYearAvgVol = lastYearVols.reduce((s, v) => s + v, 0) / 12

      for (let m = 0; m < totalMonths; m++) {
        const year      = Math.floor(m / 12)
        const cpu       = (Number(cog.unit_purchase_cost) || 0) * Math.pow(1 + annualCostInc, year)
        const soldQty   = volumeByProduct[pi]?.[m] || 0

        // Target end inventory = next month's sold qty x coverage ratio.
        // For the last month of the study there is no m+1, so we fall back to
        // the average monthly volume of the last year - keeping the buffer realistic.
        const isLastMonth  = (m + 1 >= totalMonths)
        const nextSoldQty  = isLastMonth
          ? lastYearAvgVol
          : (volumeByProduct[pi]?.[m + 1] || 0)
        const targetEndQty = nextSoldQty * (inventoryDays / 30)

        // Condition check: do we need to purchase?
        const neededQty     = soldQty + targetEndQty   // min total available we need
        let purchasedQty    = 0
        let totalAvailQty   = 0

        if (invQty >= neededQty) {
          // Condition 1: existing inventory covers sales + target end stock → no purchase
          purchasedQty  = 0
          totalAvailQty = invQty
        } else {
          // Condition 2: must purchase to meet sales + target end inventory
          totalAvailQty = neededQty
          purchasedQty  = neededQty - invQty
        }

        // Purchase value
        const purchasedVal = purchasedQty * cpu

        // Trigger AP / supplier payment for the purchased amount
        if (purchasedVal > 0) {
          applySupplierPayment(purchasedVal, m, cog.purchase_payment_policy ?? CASH_POLICY, tradVatPct, tradCWhtPct)
        }

        // Total available value = beginning balance + purchases (weighted avg cost method)
        const totalAvailVal = invVal + purchasedVal

        // Weighted average cost per unit
        const avgCost = totalAvailQty > 0 ? totalAvailVal / totalAvailQty : cpu

        // COGS for the month = sold qty × avg cost
        const cogsCost = avgCost * soldQty

        // End inventory balance (quantity and value)
        const endQty = totalAvailQty - soldQty
        const endVal = totalAvailVal - cogsCost

        // Carry forward (next month's beginning balance)
        invQty = Math.max(0, endQty)
        invVal = Math.max(0, endVal)

        if (!cogsCost && !purchasedVal) {
          inventoryByMonth[m] += invVal   // carry the unchanged inventory to BS
          continue
        }

        prodCog[m]       += cogsCost
        cogsByMonth[m]   += cogsCost
        cogsIncurred[m]  += cogsCost
        detailTrading[m] += cogsCost

        inventoryByMonth[m] += invVal   // record end-of-month inventory value on BS
      }
    } else if (cog.nature === 'service') {
      // For service: no purchase VAT or credit WHT typically (services invoiced directly)
      const si = cog.service_start_date ? Math.max(0, monthDiff(startYM, toYM(cog.service_start_date))) : 0
      const ei = cog.service_end_date   ? Math.min(totalMonths - 1, monthDiff(startYM, toYM(cog.service_end_date))) : totalMonths - 1
      for (let m = si; m <= ei && m < totalMonths; m++) {
        const year = Math.floor(m / 12)
        const cost = cog.service_method === 'pct_revenue'
          ? (revenueByProduct[pi]?.[m] || 0) * ((Number(cog.service_pct) || 0) / 100)
            * Math.pow(1 + (Number(cog.service_annual_change) || 0) / 100, year)
          : (Number(cog.service_amount) || 0) * Math.pow(1 + (Number(cog.service_annual_increase) || 0) / 100, year)
        if (!cost) continue
        prodCog[m]        += cost
        cogsByMonth[m]    += cost
        cogsIncurred[m]   += cost
        detailService[m]  += cost
        applyPaymentPolicy(cogsPaymentsByMonth, m, cost, cog.service_payment_policy ?? CASH_POLICY)
        applyPaymentPolicy(apGrossPaidByMonth,  m, cost, cog.service_payment_policy ?? CASH_POLICY)
        apGrossInvoicedByMonth[m] += cost
      }
    }

    cogsByProduct.push(prodCog)
    cogsByProductDetail.push({
      name:    prodDef.name  || `Product ${pi + 1}`,
      nature:  cog.nature,
      rmCogs:      detailRM,
      dlCogs:      detailDL,
      ohCogs:      detailOH,
      tradingCogs: detailTrading,
      serviceCogs: detailService,
    })
  }

  // ── AP End Balance = cumulative gross invoiced − cumulative gross paid ─────
  const apByMonth = new Array(totalMonths).fill(0)
  let cumAPInv = 0, cumAPPaid = 0
  for (let m = 0; m < totalMonths; m++) {
    cumAPInv  += apGrossInvoicedByMonth[m]
    cumAPPaid += apGrossPaidByMonth[m]
    apByMonth[m] = Math.max(0, cumAPInv - cumAPPaid)
  }

  // ── Credit WHT Payable: accumulate monthly, pay in April / July / October / January
  //
  //  Quarter rule (Egyptian tax):
  //    Jan + Feb + Mar  → paid in April   (calendar month 4)
  //    Apr + May + Jun  → paid in July    (calendar month 7)
  //    Jul + Aug + Sep  → paid in October (calendar month 10)
  //    Oct + Nov + Dec  → paid in January (calendar month 1) of the NEXT year
  //
  //  Payment months by 0-indexed calendar month: 3 (Apr), 6 (Jul), 9 (Oct), 0 (Jan)
  //  We derive the calendar month for each study month from startYM.
  const startMonth0 = parseInt(startYM.split('-')[1]) - 1  // 0-indexed calendar month of study start

  const creditWhtPaidByMonth   = new Array(totalMonths).fill(0)
  const creditWhtBalByMonth    = new Array(totalMonths).fill(0)
  let cumCreditWht = 0
  for (let m = 0; m < totalMonths; m++) {
    cumCreditWht += creditWhtByMonth[m]
    // Calendar month (0-indexed) for this study month
    const calMonth = (startMonth0 + m) % 12  // 0=Jan, 3=Apr, 6=Jul, 9=Oct
    // Pay if this is April(3), July(6), October(9), or January(0)
    const isPaymentMonth = (calMonth === 3 || calMonth === 6 || calMonth === 9 || calMonth === 0)
    if (isPaymentMonth && cumCreditWht > 0) {
      creditWhtPaidByMonth[m] = cumCreditWht
      cumCreditWht = 0
    }
    creditWhtBalByMonth[m] = cumCreditWht
  }

  return {
    cogsByMonth,
    cogsByProduct,
    cogsByProductDetail,       // per-product COGS breakdown for P&L drill-down
    cogsPaymentsByMonth,       // actual cash paid to suppliers
    purchaseVatByMonth,        // input VAT on purchases (monthly)
    apByMonth,                 // BS: Suppliers Payable (gross incl VAT)
    creditWhtByMonth,          // Credit WHT withheld from suppliers (monthly)
    creditWhtPaidByMonth,      // Credit WHT paid to authority (quarterly)
    creditWhtBalByMonth,       // BS: Credit WHT Payable
    inventoryByMonth,          // BS: Trading Inventory end balance
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 3 — MANPOWER
// ─────────────────────────────────────────────────────────────────────────────
function calcManpower(study, manpowerData) {
  const totalMonths      = study.duration_years * 12
  const manpowerByMonth  = new Array(totalMonths).fill(0)
  const headcountByMonth = new Array(totalMonths).fill(0)
  const byDept = {
    direct_labor:    new Array(totalMonths).fill(0),
    indirect_labor:  new Array(totalMonths).fill(0),
    admin:           new Array(totalMonths).fill(0),
    sales_marketing: new Array(totalMonths).fill(0),
  }

  for (const row of (manpowerData ?? [])) {
    const base   = (Number(row.net_salary) || 0) * (1 + (Number(row.salary_taxes_pct) || 0) / 100 + (Number(row.social_insurance_pct) || 0) / 100)
    const annInc = (Number(row.annual_increase_pct) || 0) / 100
    const dept   = row.dept || 'admin'
    const dk     = dept === 'direct_labor' ? 'direct_labor' : dept === 'indirect_labor' ? 'indirect_labor' : dept === 'admin_management' ? 'admin' : 'sales_marketing'

    for (let m = 0; m < totalMonths; m++) {
      const year  = Math.floor(m / 12)
      const gross = base * Math.pow(1 + annInc, year)
      const count = year === 0 ? (row.y1_count?.[m % 12] ?? 0) : year === 1 ? (row.y2_count?.[m % 12] ?? 0) : (row.annual_count?.[year - 2] ?? 0)
      const cost  = gross * count
      manpowerByMonth[m]  += cost
      headcountByMonth[m] += count
      if (byDept[dk]) byDept[dk][m] += cost
    }
  }

  return { manpowerByMonth, manpowerCashByMonth: [...manpowerByMonth], headcountByMonth, manpowerByDept: byDept }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 4 — OPEX EXPENSES
// ─────────────────────────────────────────────────────────────────────────────
function calcExpenses(study, expensesData, revenueByMonth) {
  const totalMonths = study.duration_years * 12
  const startYM     = toYM(study.study_start_date)
  const byMonth     = new Array(totalMonths).fill(0)
  const cashByMonth = new Array(totalMonths).fill(0)
  const byCat = {
    sales:         new Array(totalMonths).fill(0),
    marketing:     new Array(totalMonths).fill(0),
    general_admin: new Array(totalMonths).fill(0),
    finance:       new Array(totalMonths).fill(0),
  }

  for (const row of (expensesData ?? [])) {
    const ck = row.category === 'sales' ? 'sales' : row.category === 'marketing' ? 'marketing' : row.category === 'finance' ? 'finance' : 'general_admin'
    const si = row.start_date ? Math.max(0, monthDiff(startYM, toYM(row.start_date))) : 0
    const ei = row.end_date   ? Math.min(totalMonths - 1, monthDiff(startYM, toYM(row.end_date))) : totalMonths - 1

    if (row.expense_type === 'pct_revenue') {
      for (let m = si; m <= ei && m < totalMonths; m++) {
        const cost = (revenueByMonth[m] || 0) * ((Number(row.amount) || 0) / 100) * Math.pow(1 + (Number(row.annual_increase_pct) || 0) / 100, Math.floor(m / 12))
        byMonth[m] += cost
        if (byCat[ck]) byCat[ck][m] += cost
        applyPaymentPolicy(cashByMonth, m, cost, row.payment_policy ?? CASH_POLICY)
      }
    } else if (row.expense_type === 'fixed_recurring') {
      for (let m = si; m <= ei && m < totalMonths; m++) {
        const cost = (Number(row.amount) || 0) * Math.pow(1 + (Number(row.annual_increase_pct) || 0) / 100, Math.floor(m / 12))
        byMonth[m] += cost
        if (byCat[ck]) byCat[ck][m] += cost
        applyPaymentPolicy(cashByMonth, m, cost, row.payment_policy ?? CASH_POLICY)
      }
    } else if (row.expense_type === 'one_time') {
      const total   = Number(row.amount) || 0
      const amort   = Math.max(1, Number(row.amortization_months) || 1)
      const monthly = total / amort
      for (let m = si; m < si + amort && m < totalMonths; m++) {
        byMonth[m] += monthly
        if (byCat[ck]) byCat[ck][m] += monthly
      }
      applyPaymentPolicy(cashByMonth, si, total, row.payment_policy ?? CASH_POLICY)
    }
  }

  return { expensesByMonth: byMonth, expensesCashByMonth: cashByMonth, expensesByCategory: byCat }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 5 — FIXED ASSETS & DEPRECIATION
// ─────────────────────────────────────────────────────────────────────────────
function calcFixedAssets(study, fixedAssetsData, productNames) {
  const totalMonths = study.duration_years * 12
  const startYM     = toYM(study.study_start_date)

  const depByMonth          = new Array(totalMonths).fill(0)
  const depAdminByMonth     = new Array(totalMonths).fill(0)
  const capexCashByMonth    = new Array(totalMonths).fill(0)
  const loanDrawdownByMonth = new Array(totalMonths).fill(0)
  const loanInterestByMonth = new Array(totalMonths).fill(0)
  const loanRepayByMonth    = new Array(totalMonths).fill(0)
  const depMfgByProduct     = (productNames ?? []).map(() => new Array(totalMonths).fill(0))

  let totalCapex = 0, totalDebtDrawn = 0, totalEquityFunded = 0
  const grossFAArr = new Array(totalMonths).fill(0)
  const accumDepArr = new Array(totalMonths).fill(0)
  const loanBalArr  = new Array(totalMonths).fill(0)

  for (const asset of (fixedAssetsData ?? [])) {
    const total = Number(asset.total) || 0
    if (!total) continue

    const depDur    = Number(asset.depreciation_duration) || 0
    const adminPct  = (Number(asset.admin_dep_pct) || 0) / 100
    const mfgPct    = (Number(asset.mfg_dep_pct)  || 100) / 100
    const equityPct = (asset.equity_pct != null ? Number(asset.equity_pct) : 0) / 100
    const debtPct   = (asset.debt_pct   != null ? Number(asset.debt_pct)   : (100 - (asset.equity_pct != null ? Number(asset.equity_pct) : 0))) / 100
    const equityAmt = total * equityPct
    const debtAmt   = total * debtPct

    const pupSt = Math.max(0, asset.start_date ? monthDiff(startYM, toYM(asset.start_date)) : 0)
    const pupEn = Math.max(pupSt, Math.min(totalMonths - 1, asset.end_date ? monthDiff(startYM, toYM(asset.end_date)) : pupSt))
    const depSt = pupEn + 1
    const depMo = depDur * 12
    const depEn = Math.min(totalMonths - 1, depSt + depMo - 1)
    const pupCnt = Math.max(1, pupEn - pupSt + 1)

    totalCapex        += total
    totalDebtDrawn    += debtAmt
    totalEquityFunded += equityAmt

    // ── Per-asset loan drawdown tracker (used for capitalized interest — must NOT use global array) ──
    const assetLoanDraw = new Array(totalMonths).fill(0)

    // ── CAPEX cash flows ──────────────────────────────────────────────────────
    // capexCashByMonth = FULL asset payment (investing outflow) regardless of
    // how it is funded. The loan drawdown on the financing side brings the bank's
    // share of cash IN — both must appear at full face value so the statement nets
    // correctly (net cash impact = equity portion only, which is right).
    if (asset.payment_term === 'cash') {
      capexCashByMonth[pupSt]    += total      // full cost out  (investing)
      loanDrawdownByMonth[pupSt] += debtAmt    // bank share in  (financing)
      assetLoanDraw[pupSt]       += debtAmt
    } else if (asset.payment_term === 'customize' && asset.custom_payment) {
      for (const t of (asset.custom_payment.tranches ?? [])) {
        const rate = (Number(t.rate) || 0) / 100
        const idx  = Math.min(totalMonths - 1, pupSt + Math.round((Number(t.days) || 0) / 30))
        capexCashByMonth[idx]    += total * rate          // full tranche out
        loanDrawdownByMonth[idx] += total * rate * debtPct
        assetLoanDraw[idx]       += total * rate * debtPct
      }
    } else if (asset.payment_term === 'installment' && asset.installment_config) {
      const cfg = asset.installment_config
      const rp  = (Number(cfg.reservation_pct) || 0) / 100
      const cp  = (Number(cfg.contractual_pct)  || 0) / 100
      const rem = 1 - rp - cp
      capexCashByMonth[pupSt]    += total * rp            // full reservation out
      loanDrawdownByMonth[pupSt] += total * rp * debtPct
      assetLoanDraw[pupSt]       += total * rp * debtPct
      const mo = (total * cp) / pupCnt
      for (let m = pupSt; m <= pupEn && m < totalMonths; m++) {
        capexCashByMonth[m]    += mo                      // full monthly instalment out
        loanDrawdownByMonth[m] += mo * debtPct
        assetLoanDraw[m]       += mo * debtPct
      }
      capexCashByMonth[pupEn]    += total * rem            // full final payment out
      loanDrawdownByMonth[pupEn] += total * rem * debtPct
      assetLoanDraw[pupEn]       += total * rem * debtPct
    } else {
      // Default: spread evenly across PUP period
      const mo = total / pupCnt
      for (let m = pupSt; m <= pupEn && m < totalMonths; m++) {
        capexCashByMonth[m]    += mo                      // full monthly slice out
        loanDrawdownByMonth[m] += mo * debtPct
        assetLoanDraw[m]       += mo * debtPct
      }
    }

    const annRate   = (Number(asset.interest_pct) || 0) / 100
    const monthRate = annRate / 12

    // IAS 23: capitalise interest ONLY during the construction/PUP phase.
    // When start_date = end_date the asset is acquired immediately (pupCnt = 1),
    // so there is no construction period and nothing to capitalise.
    const hasPupPeriod = pupCnt > 1

    let capInt = 0, runBal = 0
    if (hasPupPeriod) {
      for (let m = pupSt; m <= pupEn && m < totalMonths; m++) {
        runBal += assetLoanDraw[m]
        capInt += runBal * monthRate
      }
    }
    const grossFA    = total + capInt
    const monthlyDep = depDur > 0 ? grossFA / depMo : 0

    if (monthlyDep > 0) {
      for (let m = depSt; m <= depEn && m < totalMonths; m++) {
        depByMonth[m]      += monthlyDep
        depAdminByMonth[m] += monthlyDep * adminPct
        const mDep = monthlyDep * mfgPct
        const alloc = asset.product_allocation ?? []
        for (let pi2 = 0; pi2 < (productNames?.length ?? 0); pi2++) {
          const found = alloc.find(a => a.product_name === productNames[pi2])
          const pct   = found ? (Number(found.pct) || 0) / 100 : (alloc.length === 0 ? 1 / Math.max(1, productNames.length) : 0)
          if (depMfgByProduct[pi2]) depMfgByProduct[pi2][m] += mDep * pct
        }
      }
    }

    if (debtAmt > 0 && annRate > 0) {
      const grace    = Number(asset.grace_months) || 0
      const tenor    = Number(asset.tenor_months)  || 60
      const interval = asset.installment_interval === 'quarterly' ? 3 : asset.installment_interval === 'semi_annual' ? 6 : asset.installment_interval === 'annual' ? 12 : 1
      const repSt    = pupEn + 1 + grace
      const loanAmt  = debtAmt + capInt
      for (let m = pupEn + 1; m < repSt && m < totalMonths; m++) loanInterestByMonth[m] += loanAmt * monthRate
      let rem = loanAmt
      const ppI = rem / Math.ceil(tenor / interval)
      for (let m = repSt; m < repSt + tenor && m < totalMonths; m++) {
        loanInterestByMonth[m] += rem * monthRate
        if ((m - repSt) % interval === 0) { const p = Math.min(ppI, rem); loanRepayByMonth[m] += p; rem = Math.max(0, rem - p) }
      }
    }

    if (asset.replacement_cost_pct > 0 && asset.replacement_interval) {
      const ry   = { '1y':1,'2y':2,'3y':3,'5y':5 }[asset.replacement_interval] ?? 10
      const ri   = ry * 12
      const rAmt = total * (Number(asset.replacement_cost_pct) / 100)
      let rIdx   = pupEn + ri
      while (rIdx < totalMonths) {
        capexCashByMonth[rIdx]    += rAmt * equityPct
        loanDrawdownByMonth[rIdx] += rAmt * debtPct
        if (depDur > 0) {
          const rdpm = rAmt / depMo
          for (let dm = rIdx + 1; dm < rIdx + 1 + depMo && dm < totalMonths; dm++) {
            depByMonth[dm]      += rdpm
            depAdminByMonth[dm] += rdpm * adminPct
            const mD2 = rdpm * mfgPct
            const al2 = asset.product_allocation ?? []
            for (let pi2 = 0; pi2 < (productNames?.length ?? 0); pi2++) {
              const found = al2.find(a => a.product_name === productNames[pi2])
              const pct   = found ? (Number(found.pct) || 0) / 100 : (al2.length === 0 ? 1 / Math.max(1, productNames.length) : 0)
              if (depMfgByProduct[pi2]) depMfgByProduct[pi2][dm] += mD2 * pct
            }
          }
        }
        rIdx += ri
      }
    }
  }

  // capexCashByMonth now holds the FULL asset cost (equity + debt portions combined).
  // Adding loanDrawdownByMonth on top would double-count the debt share in grossFA.
  let gR = 0, aR = 0, lR = 0
  for (let m = 0; m < totalMonths; m++) {
    gR += capexCashByMonth[m]   // full asset cost — do NOT add loanDrawdown again
    aR += depByMonth[m]
    lR  = Math.max(0, lR + loanDrawdownByMonth[m] - loanRepayByMonth[m])
    grossFAArr[m]  = gR
    accumDepArr[m] = aR
    loanBalArr[m]  = lR
  }

  return {
    depByMonth, depAdminByMonth, depMfgByProduct,
    capexCashByMonth, loanDrawdownByMonth, loanInterestByMonth, loanRepayByMonth,
    grossFAByMonth: grossFAArr, accumDepByMonth: accumDepArr,
    netFAByMonth:   grossFAArr.map((g, i) => g - accumDepArr[i]),
    loanBalByMonth: loanBalArr,
    totalCapex, totalDebtDrawn, totalEquityFunded,
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 6 — VAT PAYABLE (Net: Sales VAT − Purchase VAT)
// ─────────────────────────────────────────────────────────────────────────────
/*
 *  Net VAT Payable each month = Sales VAT invoiced − Purchase VAT on COGS
 *  If net > 0 → owe authority → pay next month (30 days)
 *  If net < 0 → VAT credit → carry forward, no payment
 *  Balance Sheet shows the running balance until paid
 */
function calcVATPayable(totalMonths, salesVatByMonth, purchaseVatByMonth) {
  // Net VAT position each month
  const netVatByMonth    = new Array(totalMonths).fill(0)
  const vatPaidByMonth   = new Array(totalMonths).fill(0)
  const vatBalByMonth    = new Array(totalMonths).fill(0)

  for (let m = 0; m < totalMonths; m++) {
    netVatByMonth[m] = (salesVatByMonth[m] || 0) - (purchaseVatByMonth[m] || 0)
  }

  // Running balance: beg + net VAT − payment
  // Payment = previous month's positive balance (30-day rule)
  let bal = 0
  for (let m = 0; m < totalMonths; m++) {
    // Pay last month's balance if positive
    if (m > 0 && bal > 0) {
      vatPaidByMonth[m] = bal
      bal = 0
    }
    bal += netVatByMonth[m]
    // If balance goes negative (credit), keep it; no payment
    vatBalByMonth[m] = bal
  }

  return { netVatByMonth, vatPaidByMonth, vatBalByMonth }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 7 — P&L  (Corporate tax booked at December of each year)
// ─────────────────────────────────────────────────────────────────────────────
/*
 *  P&L is straightforward except for tax:
 *  - Monthly: no tax line (tax is an annual obligation)
 *  - December of each year: book full-year EBT × tax rate as the tax expense
 *  - This matches the "Corporate Tax = annual EBT × rate" logic in the Excel
 */
function buildPL(study, revenue, cogs, manpower, expenses, fa) {
  const totalMonths = study.duration_years * 12
  const taxRate     = (Number(study.corporate_tax_rate) || 0) / 100
  const pl          = []

  // First pass: compute EBT monthly (no tax yet)
  const ebtByMonth = []
  for (let m = 0; m < totalMonths; m++) {
    const rev        = revenue.revenueByMonth[m] || 0
    const cogsCost   = cogs.cogsByMonth[m]        || 0
    // mfgDep is a production cost → sits above the Gross Profit line.
    // It must be deducted from revenue as part of COGS.
    // For EBITDA we add ALL depreciation (mfgDep + adminDep) back.
    // Waterfall:
    //   Gross Profit = Revenue − cogsCost − mfgDep
    //   EBITDA       = Gross Profit − Manpower − OpEx + mfgDep + adminDep
    //   EBIT         = EBITDA − mfgDep − adminDep
    const mfgDep     = fa.depMfgByProduct.reduce((s, a) => s + (a[m] || 0), 0)
    const totalCogs  = cogsCost + mfgDep
    const grossProfit  = rev - totalCogs
    const manpowerCost = manpower.manpowerByMonth[m] || 0
    const opexCost     = expenses.expensesByMonth[m] || 0
    const adminDep     = fa.depAdminByMonth[m]       || 0
    const ebitda       = grossProfit - (manpowerCost + opexCost) + mfgDep + adminDep
    const ebit         = ebitda - (mfgDep + adminDep)
    const finCost      = fa.loanInterestByMonth[m] || 0
    const ebt          = ebit - finCost
    ebtByMonth.push({ rev, cogsCost, mfgDep, totalCogs, grossProfit, manpowerCost, opexCost, adminDep, ebitda, ebit, finCost, ebt })
  }

  // Second pass: book tax in December of each year
  for (let m = 0; m < totalMonths; m++) {
    const e = ebtByMonth[m]
    let tax = 0
    // December = month index 11, 23, 35, ... i.e. (m+1) % 12 === 0
    if ((m + 1) % 12 === 0) {
      const yearStart = m - 11
      const annualEBT = ebtByMonth.slice(yearStart, m + 1).reduce((s, x) => s + x.ebt, 0)
      tax = annualEBT > 0 ? annualEBT * taxRate : 0
    }
    const netProfit = e.ebt - tax
    // Build per-product COGS detail for this month
    const cogsDetail = cogs.cogsByProductDetail.map(d => ({
      name:        d.name,
      nature:      d.nature,
      rmCogs:      d.rmCogs[m]      || 0,
      dlCogs:      d.dlCogs[m]      || 0,
      ohCogs:      d.ohCogs[m]      || 0,
      tradingCogs: d.tradingCogs[m] || 0,
      serviceCogs: d.serviceCogs[m] || 0,
      mfgDep:      (fa.depMfgByProduct[cogs.cogsByProductDetail.indexOf(d)] || [])[m] || 0,
    }))
    pl.push({
      month: m,
      revenue: e.rev, cogs: e.totalCogs, rawCogs: e.cogsCost, mfgDep: e.mfgDep,
      grossProfit: e.grossProfit, grossMarginPct: e.rev > 0 ? (e.grossProfit / e.rev) * 100 : 0,
      manpowerCost: e.manpowerCost, opexCost: e.opexCost, adminDep: e.adminDep,
      totalOpEx: e.manpowerCost + e.opexCost + e.adminDep,
      ebitda: e.ebitda, ebitdaMarginPct: e.rev > 0 ? (e.ebitda / e.rev) * 100 : 0,
      totalDep: fa.depByMonth[m] || 0,
      ebit: e.ebit, finCost: e.finCost, ebt: e.ebt,
      tax, netProfit,
      netMarginPct: e.rev > 0 ? (netProfit / e.rev) * 100 : 0,
      cogsDetail,
    })
  }
  return pl
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 8 — CORPORATE TAX BALANCE (monthly BS position)
// ─────────────────────────────────────────────────────────────────────────────
/*
 *  Monthly: Debit WHT withheld from customers accumulates as a prepayment
 *           (reduces what we owe → negative balance = overpaid/prepaid)
 *  December: Corporate Tax booked = annual EBT × rate
 *            Net balance = Running WHT credits − Corporate Tax due
 *  If net positive at Dec → we OWE the authority (liability)
 *  If net negative → we have a CREDIT (asset / deferred tax benefit)
 *  Payment: April of the following calendar year
 *           e.g. Dec 2026 balance → paid April 2027 (month index ~15 for Jan2026 start)
 *
 *  BS shows:
 *    Positive balance → "Corporate Tax Payable" (liability)
 *    Negative balance → "Tax Prepayment" (asset) — in practice rare
 */
function calcCorpTaxBalance(study, pl, revenue) {
  const totalMonths = study.duration_years * 12
  const startYM     = toYM(study.study_start_date)
  const startYear   = parseInt(startYM.split('-')[0])
  const startMonth  = parseInt(startYM.split('-')[1]) - 1  // 0-indexed

  const corpTaxPaidByMonth = new Array(totalMonths).fill(0)
  const corpTaxBalByMonth  = new Array(totalMonths).fill(0)

  // Running cumulative debit WHT and tax balances per year
  // We track a running balance across all months
  let runningBal = 0  // negative = credit/prepayment; positive = liability

  for (let m = 0; m < totalMonths; m++) {
    // WHT withheld from customers this month → reduces tax liability (prepayment)
    runningBal -= (revenue.debitWhtByMonth[m] || 0)

    // December of each year: add annual corporate tax
    if ((m + 1) % 12 === 0) {
      runningBal += pl[m].tax  // tax was booked in December P&L
    }

    // April of the following year: pay the December balance
    // April = month 3 (0-indexed) in the calendar year
    // For study start Jan 2026: Dec Y1 = month 11, paid April Y2 = month 15
    // General rule: if this month is April (calendar month = 4), pay previous Dec's balance
    const calMonth = (startMonth + m) % 12  // 0-indexed calendar month
    if (calMonth === 3) {  // April (0-indexed month 3)
      // Pay only if there's a positive liability (owe the authority)
      if (runningBal > 0) {
        corpTaxPaidByMonth[m] = runningBal
        runningBal = 0
      }
    }

    corpTaxBalByMonth[m] = runningBal
  }

  return { corpTaxPaidByMonth, corpTaxBalByMonth }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 9 — CASH FLOW  (2-pass: find min cash, inject equity)
// ─────────────────────────────────────────────────────────────────────────────
function buildCashFlow(study, revenue, cogs, manpower, expenses, fa, vatCalc, corpTax, openingCash) {
  const totalMonths = study.duration_years * 12

  function runCF(injection) {
    const cf  = []
    let cum   = openingCash
    for (let m = 0; m < totalMonths; m++) {
      const rec          = revenue.receiptsByMonth[m]        || 0
      const vatOut       = vatCalc.vatPaidByMonth[m]         || 0
      const cogsP        = cogs.cogsPaymentsByMonth[m]       || 0
      const creditWhtOut = cogs.creditWhtPaidByMonth[m]      || 0
      const manP         = manpower.manpowerCashByMonth[m]   || 0
      const expP         = expenses.expensesCashByMonth[m]   || 0
      const intP         = fa.loanInterestByMonth[m]         || 0
      const corpTaxOut   = corpTax.corpTaxPaidByMonth[m]     || 0
      const capex        = fa.capexCashByMonth[m]            || 0
      const loanIn       = fa.loanDrawdownByMonth[m]         || 0
      const loanOut      = fa.loanRepayByMonth[m]            || 0
      const inject       = m === 0 ? injection : 0

      const operatingCF = rec - vatOut - cogsP - creditWhtOut - manP - expP - corpTaxOut
      const investingCF = -capex
      const financingCF = loanIn - loanOut + inject
      const netCF       = operatingCF + investingCF + financingCF - intP
      cum += netCF

      cf.push({
        month: m,
        receipts: rec, vatPaid: vatOut, cogsPaid: cogsP,
        creditWhtPaid: creditWhtOut, manpowerPaid: manP, expensesPaid: expP,
        corpTaxPaid: corpTaxOut, interestPaid: intP, operatingCF,
        capexPaid: capex, investingCF,
        loanDrawdown: loanIn, loanRepay: loanOut, equityInjection: inject, financingCF,
        netCF, cumulativeCash: cum,
      })
    }
    return cf
  }

  const pass1           = runCF(0)
  const minCash         = Math.min(0, ...pass1.map(r => r.cumulativeCash))
  const requiredEquityTopUp = -minCash

  const cf = runCF(requiredEquityTopUp)
  return { cf, requiredEquityTopUp }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 10 — BALANCE SHEET  (monthly)
// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
//  OPENING BALANCE HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * calcPreExistingDep — accumulated depreciation on pre-existing fixed assets through month m.
 * Each asset in openingBalance.fixed_assets has: monthly_dep, dep_months_remaining.
 */
function calcPreExistingDep(fixedAssets, m) {
  return (fixedAssets ?? []).reduce((sum, fa) => {
    const monthlyDep    = Number(fa.monthly_dep          || 0)
    const monthsLeft    = Number(fa.dep_months_remaining || 0)
    const depMonthsUsed = Math.min(m + 1, monthsLeft)
    return sum + (monthlyDep * depMonthsUsed)
  }, 0)
}

/**
 * calcSettlementRemaining — remaining balance of a pre-existing liability/asset row at month m.
 * Settlement schedule entries are matched by array index (slot 0 = Month 1 of study, etc.)
 */
function calcSettlementRemaining(rows, m) {
  return (rows ?? []).reduce((sum, row) => {
    const startBal  = Number(row.amount || 0)
    const schedule  = row.schedule ?? []
    const paidSoFar = schedule.slice(0, m + 1).reduce((s, sl) => s + (Number(sl.amount) || 0), 0)
    return sum + Math.max(0, startBal - paidSoFar)
  }, 0)
}

function buildBalanceSheet(study, pl, cf, fa, revenue, cogs, vatCalc, corpTax, openingBalance, requiredEquityTopUp,
  openingEquity = {}) {
  const totalMonths = study.duration_years * 12

  // ── Opening equity components ──────────────────────────────────────────────
  const { openingPaidUpCapital = 0, openingLegalReserve = 0, openingRetainedEarnings = 0 } = openingEquity

  // Opening Net Fixed Assets — new format uses totals.net_fa; old format used sections.non_current_assets
  const ob       = openingBalance ?? {}
  const openNetFA = ob.totals?.net_fa != null
    ? Number(ob.totals.net_fa)
    : ((ob.sections?.non_current_assets ?? []).reduce((s, r) => s + (Number(r.amount) || 0), 0))

  // paidUpCapital = opening paid-up + equity injection needed to keep cash ≥ 0
  const paidUpCapital = openingPaidUpCapital + requiredEquityTopUp

  // Corporate tax rate for legal reserve calc
  const corporateTaxRate = Number(study.corporate_tax_rate || 0) / 100

  // ── Legal Reserve (Egyptian Companies Law) ────────────────────────────────
  // 5% of annual net profit is transferred to Legal Reserve each December
  // until the reserve reaches 50% of paid-up capital — then stops.
  // We track this month-by-month: only the December month of each year triggers.
  let legalReserveAccum = openingLegalReserve
  const legalReserveCap = paidUpCapital * 0.5   // 50% of paid-up capital

  const bs = []
  let retainedEarnings = openingRetainedEarnings   // starts from opening RE

  for (let m = 0; m < totalMonths; m++) {
    const currentProfit = pl[m].netProfit

    // ── Non-current Assets ──
    // openNetFA = pre-existing net FA from Opening Balance step
    // fa.grossFAByMonth[m] = cumulative gross FA added during study (Step 6)
    // fa.accumDepByMonth[m] = accumulated dep on new study FA only
    // calcPreExistingDep() = accumulated dep on pre-existing assets through month m
    const preExistingAccumDep = calcPreExistingDep(ob.fixed_assets ?? [], m)
    const grossFA  = openNetFA + fa.grossFAByMonth[m]
    const accumDep = fa.accumDepByMonth[m] + preExistingAccumDep
    const netFA    = grossFA - accumDep

    // ── Current Assets ──
    const cash              = cf[m].cumulativeCash                  // always ≥ 0
    const ar                = revenue.arByMonth[m]            || 0
    const inventory         = cogs.inventoryByMonth[m]        || 0
    const corpTaxPrepayment = Math.max(0, -(corpTax.corpTaxBalByMonth[m] || 0))
    const totalCA           = cash + ar + inventory + corpTaxPrepayment
    const totalAssets       = netFA + totalCA

    // ── Non-current Liabilities ──
    const openLTLRemaining = calcSettlementRemaining(ob.sections?.long_term_liabilities ?? [], m)
    const longTermDebt     = Math.max(0, fa.loanBalByMonth[m]) + openLTLRemaining

    // ── Current Liabilities ──
    const openCLRemaining  = calcSettlementRemaining(ob.sections?.current_liabilities ?? [], m)
    const ap               = cogs.apByMonth[m]                || 0
    const vatPayable       = Math.max(0, vatCalc.vatBalByMonth[m] || 0)
    const corpTaxPayable   = Math.max(0,  corpTax.corpTaxBalByMonth[m] || 0)
    const creditWhtPayable = cogs.creditWhtBalByMonth[m]      || 0

    const totalCL   = ap + vatPayable + corpTaxPayable + creditWhtPayable + openCLRemaining
    const totalLiab = longTermDebt + totalCL

    // ── Legal Reserve (Egyptian Law) ─────────────────────────────────────────
    // Transfer 5% of net profit to legal reserve every December (month 11, 23, 35 ...)
    // Stop when legalReserveAccum >= 50% of paid-up capital.
    const isDecember   = (m + 1) % 12 === 0
    let legalReserveTransfer = 0
    if (isDecember && currentProfit > 0 && legalReserveAccum < legalReserveCap) {
      legalReserveTransfer = Math.min(
        currentProfit * 0.05,
        legalReserveCap - legalReserveAccum,  // never exceed the cap
      )
      legalReserveAccum += legalReserveTransfer
    }

    // ── Equity ───────────────────────────────────────────────────────────────
    const equityPaidUp    = paidUpCapital
    const equityLegalRes  = legalReserveAccum
    const equityRetained  = retainedEarnings
    const equityProfit    = currentProfit
    const totalEquity     = equityPaidUp + equityLegalRes + equityRetained + equityProfit
    const totalLiabEq     = totalLiab + totalEquity

    bs.push({
      month: m,
      grossFA, accumDep, netFA,
      cash, ar, inventory, corpTaxPrepayment,
      totalCurrentAssets: totalCA, totalAssets,
      longTermDebt, openLTLRemaining,
      ap, vatPayable, corpTaxPayable, creditWhtPayable, openCLRemaining,
      totalCurrentLiabilities: totalCL, totalLiabilities: totalLiab,
      legalReserveTransfer, legalReserveAccum,
      equityPaidUp, equityLegalRes, equityRetained, equityProfit,
      totalEquity, totalLiabEquity: totalLiabEq,
    })

    // Roll forward: profit becomes retained earnings next month
    // Legal reserve is already added to legalReserveAccum above
    retainedEarnings += currentProfit - legalReserveTransfer
  }

  return bs
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 11 — AGGREGATE TO ANNUAL
// ─────────────────────────────────────────────────────────────────────────────
function aggYears(data, fields, n) {
  return Array.from({ length: n }, (_, y) => {
    const row = { year: y + 1 }
    for (const f of fields) row[f] = 0
    for (let m = y * 12; m < (y + 1) * 12 && m < data.length; m++)
      for (const f of fields) row[f] += data[m][f] || 0
    return row
  })
}

function aggregatePLByYear(pl, n) {
  return aggYears(pl, ['revenue','cogs','rawCogs','mfgDep','grossProfit','manpowerCost','opexCost','adminDep','totalOpEx','ebitda','totalDep','ebit','finCost','ebt','tax','netProfit'], n)
    .map((y, yi) => {
      // Aggregate cogsDetail per product across the 12 months of this year
      const yearMonths = pl.slice(yi * 12, (yi + 1) * 12)
      const cogsDetail = yearMonths[0]?.cogsDetail?.map((d, di) => ({
        name:   d.name,
        nature: d.nature,
        rmCogs:      yearMonths.reduce((s, m) => s + (m.cogsDetail?.[di]?.rmCogs      || 0), 0),
        dlCogs:      yearMonths.reduce((s, m) => s + (m.cogsDetail?.[di]?.dlCogs      || 0), 0),
        ohCogs:      yearMonths.reduce((s, m) => s + (m.cogsDetail?.[di]?.ohCogs      || 0), 0),
        tradingCogs: yearMonths.reduce((s, m) => s + (m.cogsDetail?.[di]?.tradingCogs || 0), 0),
        serviceCogs: yearMonths.reduce((s, m) => s + (m.cogsDetail?.[di]?.serviceCogs || 0), 0),
        mfgDep:      yearMonths.reduce((s, m) => s + (m.cogsDetail?.[di]?.mfgDep      || 0), 0),
      })) ?? []
      return {
        ...y,
        grossMarginPct:  y.revenue > 0 ? y.grossProfit / y.revenue * 100 : 0,
        ebitdaMarginPct: y.revenue > 0 ? y.ebitda      / y.revenue * 100 : 0,
        netMarginPct:    y.revenue > 0 ? y.netProfit   / y.revenue * 100 : 0,
        cogsDetail,
      }
    })
}

function aggregateCFByYear(cf, n) {
  const yrs = aggYears(cf, ['receipts','vatPaid','cogsPaid','creditWhtPaid','manpowerPaid','expensesPaid','corpTaxPaid','interestPaid','operatingCF','capexPaid','investingCF','loanDrawdown','loanRepay','equityInjection','financingCF','netCF'], n)
  yrs.forEach((y, i) => {
    const last = Math.min((i + 1) * 12 - 1, cf.length - 1)
    y.cumulativeCash = cf[last]?.cumulativeCash ?? 0
  })
  return yrs
}

function aggregateBSByYear(bs, n) {
  return Array.from({ length: n }, (_, y) => ({
    year: y + 1,
    ...bs[Math.min((y + 1) * 12 - 1, bs.length - 1)],
  }))
}

// ─────────────────────────────────────────────────────────────────────────────
//  STEP 12 — KPIs
// ─────────────────────────────────────────────────────────────────────────────
function calcKPIs(study, plByYear, cfByYear, fa, openingBalance, requiredEquityTopUp) {
  const wacc       = (Number(study.required_investment_return_pct) || 10) / 100
  const perpGrowth = (Number(study.perpetual_growth_rate_pct) || 3) / 100
  const taxRate    = (Number(study.corporate_tax_rate) || 0) / 100
  // New format: openingBalance.paid_up_capital | Old fallback: sections.equity rows
  const openEq = openingBalance?.paid_up_capital != null
    ? Number(openingBalance.paid_up_capital)
    : (openingBalance?.sections?.equity ?? []).reduce((s, r) => s + (Number(r.amount) || 0), 0)

  const totalInvestment = fa.totalEquityFunded + requiredEquityTopUp + openEq

  const fcff = plByYear.map((y, i) => y.ebit * (1 - taxRate) + y.totalDep - (cfByYear[i]?.capexPaid || 0))
  const last  = fcff[fcff.length - 1] || 0
  const tv    = wacc > perpGrowth && last > 0 ? (last * (1 + perpGrowth)) / (wacc - perpGrowth) : 0

  let npv = -totalInvestment
  fcff.forEach((f, i) => { npv += f / Math.pow(1 + wacc, i + 1) })
  npv += tv / Math.pow(1 + wacc, fcff.length)

  const stream = [-totalInvestment, ...fcff.slice(0, -1), (fcff[fcff.length - 1] || 0) + tv]
  const npvAt  = r => stream.reduce((s, c, i) => s + c / Math.pow(1 + r, i), 0)
  let r = wacc
  for (let i = 0; i < 200; i++) {
    const f  = npvAt(r)
    const df = stream.reduce((s, c, i2) => s - i2 * c / Math.pow(1 + r, i2 + 1), 0)
    if (Math.abs(df) < 1e-10) break
    const nr = r - f / df
    if (Math.abs(nr - r) < 1e-8) { r = nr; break }
    r = Math.max(-0.99, Math.min(100, nr))
  }
  const irr = r

  const totalR = fcff.reduce((s, f) => s + Math.max(0, f), 0) + Math.max(0, tv)
  const moic   = totalInvestment > 0 ? (totalR + tv) / totalInvestment : 0

  let paybackMonth = null, cum = -totalInvestment
  for (let y = 0; y < fcff.length; y++) {
    const prev = cum; cum += fcff[y]
    if (cum >= 0 && paybackMonth === null)
      paybackMonth = y * 12 + Math.round((fcff[y] > 0 ? Math.abs(prev) / fcff[y] : 0) * 12)
  }

  let beMonth = null, cumP = 0
  for (let y = 0; y < plByYear.length; y++) {
    const prev = cumP; cumP += plByYear[y].netProfit
    if (cumP >= 0 && beMonth === null)
      beMonth = y * 12 + Math.round((plByYear[y].netProfit > 0 ? Math.abs(prev) / plByYear[y].netProfit : 0) * 12)
  }

  const avg = arr => arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0

  return {
    npv, irr: irr * 100, moic,
    paybackMonths: paybackMonth, paybackYears: paybackMonth !== null ? paybackMonth / 12 : null,
    breakEvenMonth: beMonth,    breakEvenYears: beMonth !== null ? beMonth / 12 : null,
    totalInvestment, totalFixedAssetCapex: fa.totalCapex,
    workingCapitalInjection: requiredEquityTopUp,
    totalEquityFunded: fa.totalEquityFunded, totalDebt: fa.totalDebtDrawn,
    terminalValue: tv, fcff,
    peakRevenue: Math.max(...plByYear.map(y => y.revenue)),
    peakProfit:  Math.max(...plByYear.map(y => y.netProfit)),
    avgGrossMarginPct:  avg(plByYear.map(y => y.grossMarginPct)),
    avgNetMarginPct:    avg(plByYear.map(y => y.netMarginPct)),
    avgEbitdaMarginPct: avg(plByYear.map(y => y.ebitdaMarginPct)),
    wacc: wacc * 100, perpGrowthRate: perpGrowth * 100,
  }
}

// ─────────────────────────────────────────────────────────────────────────────
//  MASTER ENTRY POINT
// ─────────────────────────────────────────────────────────────────────────────
export function runStudy(data) {
  const {
    study, products = [], projections = {},
    cogsData = [], manpowerData = [], expensesData = [],
    fixedAssetsData = [], openingBalance = null, manualOverrides = {},
    rawMaterials = [],  // from Create.vue Step 1 general_assumptions.raw_materials
  } = data

  if (!study?.study_start_date || !study?.duration_years)
    return { error: 'Study missing start date or duration' }

  const productNames = products.map(p => p.name || '')

  // Opening Balance dedicated fields (new format)
  // cash_bank          → seeded as opening cash in cash flow (Month 0 starting balance)
  // paid_up_capital    → pre-existing paid-up capital (added to paidUpCapital in BS equity)
  // legal_reserve      → pre-existing legal reserve carried forward
  // retained_earnings  → pre-existing retained earnings (opening RE)
  const openingCash           = Number(openingBalance?.cash_bank           || 0)
  const openingPaidUpCapital  = Number(openingBalance?.paid_up_capital     || 0)
  const openingLegalReserve   = Number(openingBalance?.legal_reserve       || 0)
  const openingRetainedEarnings = Number(openingBalance?.retained_earnings || 0)

  // ── Run calculation modules ───────────────────────────────────────────────
  // NOTE: FA runs first — calcCOGS needs depMfgByProduct for mfg inventory engine
  const revenue  = calcRevenue(study, projections, products)
  const manpower = calcManpower(study, manpowerData)
  const expenses = calcExpenses(study, expensesData, revenue.revenueByMonth)
  const fa       = calcFixedAssets(study, fixedAssetsData, productNames)

  // Build per-product projections array (indexed same as products[])
  // projections is { products: [...] } from SalesProjection via controller.
  // Each entry carries: beg_inv_qty, beg_inv_amount, beg_inv_breakdown,
  // inventory_coverage_days — entered by user in Step 2 (manufacturing only).
  // Match by product NAME (not index) to be bulletproof against ordering differences.
  const projProductsList = Array.isArray(projections)
    ? projections
    : (Array.isArray(projections?.products) ? projections.products : [])

  const projectionsArr = products.map((p, i) => {
    const byName = projProductsList.find(pp => pp?.name && pp.name === p.name)
    return byName ?? projProductsList[i] ?? {}
  })


  const cogsCalc = calcCOGS(
    study, cogsData,
    revenue.revenueByProduct, revenue.volumeByProduct,
    products, projectionsArr, manpowerData, fa.depMfgByProduct,
    rawMaterials,
  )

  if (manualOverrides?.totalInvestment != null)
    fa.totalEquityFunded = Number(manualOverrides.totalInvestment)

  // ── VAT (net Sales VAT − Purchase VAT) ────────────────────────────────────
  const vatCalc = calcVATPayable(
    study.duration_years * 12,
    revenue.salesVatByMonth,
    cogsCalc.purchaseVatByMonth,
  )

  // ── P&L (tax booked in December) ─────────────────────────────────────────
  const pl = buildPL(study, revenue, cogsCalc, manpower, expenses, fa)

  // ── Corporate Tax balance (WHT credits + annual booking + April payment) ──
  const corpTax = calcCorpTaxBalance(study, pl, revenue)

  // ── 2-pass Cash Flow ──────────────────────────────────────────────────────
  const { cf, requiredEquityTopUp } = buildCashFlow(
    study, revenue, cogsCalc, manpower, expenses, fa, vatCalc, corpTax, openingCash,
  )

  // ── Balance Sheet ─────────────────────────────────────────────────────────
  const bs = buildBalanceSheet(
    study, pl, cf, fa, revenue, cogsCalc, vatCalc, corpTax, openingBalance, requiredEquityTopUp,
    { openingPaidUpCapital, openingLegalReserve, openingRetainedEarnings },
  )

  // ── Aggregate to annual ───────────────────────────────────────────────────
  const plByYear = aggregatePLByYear(pl, study.duration_years)
  const cfByYear = aggregateCFByYear(cf, study.duration_years)
  const bsByYear = aggregateBSByYear(bs, study.duration_years)

  const kpis = calcKPIs(study, plByYear, cfByYear, fa, openingBalance, requiredEquityTopUp)

  const revenueByProductByYear = revenue.revenueByProduct.map(arr =>
    Array.from({ length: study.duration_years }, (_, y) =>
      arr.slice(y * 12, (y + 1) * 12).reduce((s, v) => s + (v || 0), 0)
    )
  )

  return {
    pl, cf, bs, plByYear, cfByYear, bsByYear,
    revenueByProductByYear, kpis,
    timeline: revenue.timeline, productNames,
    currency: study.study_currency || 'USD',
    durationYears: study.duration_years,
    startYear: new Date(study.study_start_date).getFullYear(),
    requiredEquityTopUp,
  }
}

export default { runStudy }