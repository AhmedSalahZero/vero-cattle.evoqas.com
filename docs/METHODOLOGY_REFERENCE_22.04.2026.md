# 📌 METHODOLOGY REFERENCE — VERO Cattle Management
**Project:** VERO Cattle Management System  
**Stack:** Laravel 12 + Vue 3 + Inertia.js + Tailwind + Spatie + MySQL  
**Currency:** EGP (Egyptian Pound) by default  
**Document status:** Living document — append every new methodology before implementation  
**Last updated:** Session 7 complete (29 controllers, 3 services, 2 Vue pages built)

---

> **HOW TO USE THIS FILE**
>
> Before implementing any calculation, formula, or financial logic:
> 1. Check this file — the methodology may already exist
> 2. If it exists, reference it by name in your code comment
> 3. If it does not exist, add it here FIRST, then implement
>
> Every implementation file references methodologies with the pattern:
> ```php
> // 📌 METHODOLOGY REFERENCE: <ReferenceName>
> ```
> In Vue files:
> ```js
> // 📌 METHODOLOGY REFERENCE: <ReferenceName>
> ```

---

## TABLE OF CONTENTS

| # | Reference Name | Category | Service / Location |
|---|---|---|---|
| 1 | [JournalBalanceValidation](#1-journalbalancevalidation) | Accounting | `JournalEntryController` |
| 2 | [InvoiceBalanceCalc](#2-invoicebalancecalc) | Accounting | `ApInvoiceController` / `ArInvoiceController` |
| 3 | [BudgetVariancePercent](#3-budgetvariancepercent) | Accounting | `BudgetController` |
| 4 | [DepreciationScheduleBuilder](#4-depreciationschedulebuilder) | Accounting | `FixedAssetController` |
| 5 | [TrialBalance](#5-trialbalance) | Financial Reports | `FinancialReportService` |
| 6 | [ProfitAndLoss](#6-profitandloss) | Financial Reports | `FinancialReportService` |
| 7 | [BalanceSheet](#7-balancesheet) | Financial Reports | `FinancialReportService` |
| 8 | [CashFlow](#8-cashflow) | Financial Reports | `FinancialReportService` |
| 9 | [ADG_FCR](#9-adg_fcr) | Livestock Intelligence | `LivestockIntelligenceService` |
| 10 | [WAC](#10-wac) | Livestock Intelligence | `LivestockIntelligenceService` |
| 11 | [GrossMarginBreakEven](#11-grossmarginbreakeven) | Livestock Intelligence | `LivestockIntelligenceService` |
| 12 | [BulkAnimalPriceAllocation](#12-bulkanimalpriceoallocation) | Procurement | `Animals/CreateEdit.vue` + `AnimalController::storeBulk` |
| 13 | [AnimalGroupingDisplay](#13-animalgroupingdisplay) | UI / Display | `Animals/Index.vue` |

---

---

## 1. JournalBalanceValidation

**Service / File:** `app/Http/Controllers/JournalEntryController.php`  
**Introduced:** Session 4 — Phase 3 Accounting Controllers  
**Used in:** Every journal entry write (store, post, reverse)

### Formula (KaTeX)

$$
\left| \sum_{i} \text{debit}_i - \sum_{i} \text{credit}_i \right| \leq 0.005
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | Array of journal lines `[{debit, credit}]` | Sum all `debit` values | `$totalDebit` |
| 2 | Array of journal lines | Sum all `credit` values | `$totalCredit` |
| 3 | `$totalDebit`, `$totalCredit` | Compute absolute difference | `$diff = abs($totalDebit - $totalCredit)` |
| 4 | `$diff` | Compare against tolerance `0.005` | `PASS` if `$diff ≤ 0.005`, `FAIL` otherwise |

### Variables / Constants

| Symbol | Type | Description |
|---|---|---|
| `debit_i` | float | Debit amount on line `i` |
| `credit_i` | float | Credit amount on line `i` |
| `0.005` | constant | Float-safe tolerance to absorb rounding errors |

### Assumptions

- All amounts are in the same currency (EGP)
- Minimum 2 lines required per entry
- Zero-amount lines are allowed but do not affect the balance

### Why This Methodology

Double-entry bookkeeping requires perfect balance. A tolerance of `0.005` (half a cent) is industry standard to absorb IEEE 754 floating-point representation errors while still catching genuine imbalances.

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Single line only | Must FAIL — no valid double-entry possible |
| All debits, no credits | `diff` = total debit → FAIL |
| Rounding difference ≤ 0.005 | PASS — within tolerance |
| `null` amounts | Cast to `(float)` before summing; null → 0 |

### Pseudocode

```
FUNCTION assertBalanced(lines[]):
    totalDebit  ← SUM(line.debit  for line in lines)
    totalCredit ← SUM(line.credit for line in lines)
    diff        ← ABS(totalDebit - totalCredit)
    IF diff > 0.005:
        THROW ValidationException("Journal entry is not balanced")
    RETURN true
```

---

## 2. InvoiceBalanceCalc

**Service / File:** `app/Http/Controllers/ApInvoiceController.php`, `ArInvoiceController.php`  
**Introduced:** Session 4 — Phase 3 Accounting Controllers  
**Used in:** Every payment application; invoice listing

### Formula (KaTeX)

$$
\text{balance} = \text{total\_amount} - \text{amount\_paid}
$$

$$
\text{status} = \begin{cases}
\text{paid} & \text{if balance} \leq 0 \\
\text{partially\_paid} & \text{if } 0 < \text{balance} < \text{total} \text{ AND due\_date} \geq \text{today} \\
\text{overdue} & \text{if balance} > 0 \text{ AND due\_date} < \text{today} \\
\text{open} & \text{if amount\_paid} = 0 \text{ AND due\_date} \geq \text{today}
\end{cases}
$$

**Aging buckets:**

$$
\text{bucket} = \begin{cases}
\text{current} & \text{days overdue} \leq 0 \\
\text{1–30} & 1 \leq \text{days overdue} \leq 30 \\
\text{31–60} & 31 \leq \text{days overdue} \leq 60 \\
\text{61–90} & 61 \leq \text{days overdue} \leq 90 \\
\text{90+} & \text{days overdue} > 90
\end{cases}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `total_amount`, `amount_paid` | Subtract paid from total | `balance` |
| 2 | `balance`, `due_date`, `today` | Apply status decision tree | `status` string |
| 3 | `due_date`, `today` | `days_overdue = today − due_date` | integer (negative = not due) |
| 4 | `days_overdue`, `balance` | Map to aging bucket | bucket label |

### Variables / Constants

| Symbol | Description |
|---|---|
| `total_amount` | Invoice face value |
| `amount_paid` | Sum of all payments applied |
| `balance` | Remaining unpaid amount |
| `due_date` | Payment due date |
| `today` | `now()->toDateString()` |
| `days_overdue` | `today - due_date` in calendar days |

### Assumptions

- Overpayment (balance < 0) is prevented at payment time by `PaymentController`
- Currency is consistent per company; no multi-currency conversion here
- Overdue sync runs at index time — `syncOverdueStatus()` is called on every listing load

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Overpayment attempt | `PaymentController` aborts with 422 |
| Payment > remaining balance | Capped to balance; excess rejected |
| Invoice with no due_date | Never marked overdue — treated as open |
| Zero-value invoice | Status = `paid` immediately |

### Pseudocode

```
FUNCTION applyPayment(invoice, paymentAmount):
    IF paymentAmount > invoice.balance:
        THROW "Overpayment not allowed"
    invoice.amount_paid += paymentAmount
    invoice.balance     = invoice.total_amount - invoice.amount_paid
    invoice.status      = resolveStatus(invoice)
    SAVE invoice

FUNCTION resolveStatus(invoice):
    IF invoice.balance <= 0:
        RETURN 'paid'
    IF invoice.amount_paid > 0 AND today <= invoice.due_date:
        RETURN 'partially_paid'
    IF today > invoice.due_date AND invoice.balance > 0:
        RETURN 'overdue'
    RETURN 'open'

FUNCTION agingBucket(invoice):
    days = today - invoice.due_date
    IF days <= 0:  RETURN 'current'
    IF days <= 30: RETURN '1-30'
    IF days <= 60: RETURN '31-60'
    IF days <= 90: RETURN '61-90'
    RETURN '90+'
```

---

## 3. BudgetVariancePercent

**Service / File:** `app/Http/Controllers/BudgetController.php`  
**Introduced:** Session 4 — Phase 3 Accounting Controllers  
**Used in:** Budget grid, variance report

### Formula (KaTeX)

$$
\text{variance\_absolute} = \text{actual} - \text{budget}
$$

$$
\text{variance\_percent} = \frac{\text{actual} - \text{budget}}{\text{budget}} \times 100
$$

$$
\text{flag} = \begin{cases}
\text{favourable} & \text{revenue account AND actual} > \text{budget} \\
\text{favourable} & \text{expense/COGS account AND actual} < \text{budget} \\
\text{unfavourable} & \text{otherwise}
\end{cases}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `budget_amount`, `actual_amount` | Subtract | `variance_absolute` |
| 2 | `variance_absolute`, `budget_amount` | Divide and multiply by 100; guard divide-by-zero | `variance_percent` |
| 3 | `variance_absolute`, `account.type` | Apply directional logic | `flag` string |

### Variables / Constants

| Symbol | Description |
|---|---|
| `budget_amount` | Planned amount for the period |
| `actual_amount` | Sum of posted journal lines for that account + period |
| `variance_absolute` | actual − budget (signed) |
| `variance_percent` | signed %; null if budget = 0 |
| `account.type` | `revenue` \| `cogs` \| `expense` \| `asset` \| `liability` \| `equity` |

### Assumptions

- Actuals are read from `posted` journal lines only
- Budget rows are per account per month per fiscal year
- `copyFromActuals` with growth % multiplies each actual by `(1 + growth/100)`

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| `budget = 0` | `variance_percent = null`; flag based on absolute only |
| No actuals posted yet | `actual = 0`; variance = −budget |
| Negative budget (credit account) | Flag logic inverted by account type rule |

### Pseudocode

```
FUNCTION computeVariance(budget, actual, accountType):
    variance_abs = actual - budget
    variance_pct = (budget != 0) ? (variance_abs / budget * 100) : null

    IF accountType IN ['revenue']:
        flag = (variance_abs >= 0) ? 'favourable' : 'unfavourable'
    ELSE:  // expense, cogs, overhead
        flag = (variance_abs <= 0) ? 'favourable' : 'unfavourable'

    RETURN {variance_abs, variance_pct, flag}
```

---

## 4. DepreciationScheduleBuilder

**Service / File:** `app/Http/Controllers/FixedAssetController.php` → `buildDepreciationSchedule()`  
**Introduced:** Session 3 — Fixed Assets Module (Unplanned Addition)  
**Used in:** `generateSchedule`, `postPeriod`, asset creation

### Formulas (KaTeX)

**Straight-Line:**

$$
\text{Monthly Charge} = \frac{\text{Cost} - \text{Salvage}}{\text{Useful Life (months)}}
$$

$$
\text{Accumulated Dep}_n = n \times \text{Monthly Charge} \quad \text{(capped at depreciable base)}
$$

**Declining Balance:**

$$
\text{Monthly Charge}_n = \text{NBV}_{n-1} \times \frac{\text{Annual Rate}}{12}
$$

$$
\text{NBV}_n = \text{Cost} - \text{Accumulated Dep}_n
$$

$$
\text{Floor: Accumulated Dep} \leq \text{Cost} - \text{Salvage}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `cost`, `salvage` | `base = cost - salvage` | `depreciable_base` |
| 2 | `accumulated_depreciation` | Read existing accum from DB | `$accum` |
| 3 | Per month (1→12) | Check if line exists; skip if yes | — |
| 4 | `base - accum` | Compute `remaining` | stop if ≤ 0 |
| 5 | Method branch | SL: constant charge; DB: NBV × rate | `$charge` |
| 6 | `$charge`, `$remaining` | `min($charge, $remaining)` — cap final period | capped `$charge` |
| 7 | `$charge` | Accumulate running total | `$runningAccum` |
| 8 | Fiscal year start + month | Compute last day of period | `$depDate` |
| 9 | All above | Insert `asset_depreciation_lines` row | DB record |

### Variables / Constants

| Symbol | Description |
|---|---|
| `cost` | `purchase_cost` on fixed asset |
| `salvage` | `salvage_value` (residual) |
| `base` | `cost − salvage` = max depreciable amount |
| `accum` | `accumulated_depreciation` at schedule start |
| `nbv` | Net Book Value = `cost − accum` at period start |
| `monthly_rate` | `db_rate / 12` (for declining balance) |
| `sl_charge` | Constant monthly charge (straight-line) |
| `remaining` | `base − runningAccum` at any period |

### Assumptions

- Depreciation starts from `in_service_date`, not purchase date
- Land category (`category = 'land'`) always uses `method = 'none'`; no lines generated
- Posted lines are immutable — `is_posted = true` lines cannot be deleted
- Final period is automatically adjusted so `accum` never exceeds `base`

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Land asset | Method forced to `none`; schedule skipped |
| Already fully depreciated | Loop exits immediately when `remaining ≤ 0` |
| Line already exists for period | `exists()` check — skipped silently |
| Disposal before end of schedule | Unposted future lines are deleted on `dispose()` |
| `db_rate = null` for SL asset | Rate path skipped; `$monthlyRate = 0` |

### Pseudocode

```
FUNCTION buildSchedule(asset, fiscalYear):
    base     = asset.cost - asset.salvage
    accum    = asset.accumulated_depreciation
    slCharge = base / asset.useful_life_months    // straight-line only

    FOR month = 1 TO 12:
        IF lineExists(asset, fiscalYear, month): CONTINUE
        remaining = base - accum
        IF remaining <= 0: BREAK

        IF method == 'straight_line':
            charge = MIN(slCharge, remaining)
        ELSE:  // declining_balance
            nbv    = asset.cost - accum
            charge = MIN(nbv * (db_rate / 12), remaining)

        IF charge <= 0: BREAK

        accum += charge
        depDate = lastDayOfMonth(fiscalYear.start + month - 1)
        INSERT depreciation_line(asset, fiscalYear, month, charge, accum, depDate)
```

---

## 5. TrialBalance

**Service / File:** `app/Services/FinancialReportService.php` → `trialBalance()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** `ReportController::trialBalance()`

### Formula (KaTeX)

**Debit-normal accounts (assets, expenses, COGS):**

$$
\text{Closing} = \text{Opening} + (\Sigma\text{Debit} - \Sigma\text{Credit})_\text{period}
$$

**Credit-normal accounts (liabilities, equity, revenue):**

$$
\text{Closing} = \text{Opening} + (\Sigma\text{Credit} - \Sigma\text{Debit})_\text{period}
$$

**Ledger balance assertion (tolerance):**

$$
\left| \sum \text{Closing}_\text{debit-normal} - \sum \text{Closing}_\text{credit-normal} \right| \leq 0.01
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `companyId`, `periodStart`, `periodEnd` | Fetch all active direct-posting accounts | account list |
| 2 | Account list | Sum posted lines BEFORE `periodStart` per account | `opening` map |
| 3 | Account list | Sum posted lines WITHIN `[periodStart, periodEnd]` | `period` map |
| 4 | Per account | Apply normal-balance rule to get signed balances | `ob`, `pd`, `pc`, `cb` |
| 5 | Skip if all zeros | `ob=0 AND pd=0 AND pc=0` → skip row | filtered rows |
| 6 | All closing balances | Assert `|Σclosing| ≤ 0.01` | `balanced` boolean |

### Variables / Constants

| Symbol | Description |
|---|---|
| `opening` | Signed balance before period start |
| `pd` | Period debit sum |
| `pc` | Period credit sum |
| `cb` | Closing balance = opening + net period movement |
| `normal_balance` | `debit` or `credit` — determines sign convention |
| `0.01` | Assertion tolerance |

### Assumptions

- Only `posted` journal entries are included
- `allow_direct_posting = true` accounts only (sub-ledger headers excluded)
- Opening balance = cumulative from all time before `periodStart`

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Account with no postings | Skipped (all-zero row) |
| Imbalanced ledger | `balanced = false`; `imbalance` value returned for investigation |
| Very long date range | DB query uses index on `je.date` + `je.company_id` |

### Pseudocode

```
FUNCTION trialBalance(companyId, periodStart, periodEnd):
    accounts = fetchActiveDirectPostingAccounts(companyId)
    opening  = sumPostedLines(companyId, date < periodStart, groupByAccount)
    period   = sumPostedLines(companyId, periodStart <= date <= periodEnd, groupByAccount)

    FOR EACH account:
        ob = signedBalance(opening[account], account.normal_balance)
        net = signedNet(period[account], account.normal_balance)
        cb = ob + net
        IF ob=0 AND pd=0 AND pc=0: SKIP
        rows.append({account, ob, pd, pc, cb})

    balanced = ABS(SUM(cb for debit-normal) - SUM(cb for credit-normal)) <= 0.01
    RETURN {rows, balanced, imbalance}
```

---

## 6. ProfitAndLoss

**Service / File:** `app/Services/FinancialReportService.php` → `profitAndLoss()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** `ReportController::profitAndLoss()`

### Formula (KaTeX)

$$
\text{Gross Profit} = \text{Total Revenue} - \text{Total COGS}
$$

$$
\text{Gross Margin \%} = \frac{\text{Gross Profit}}{\text{Total Revenue}} \times 100
$$

$$
\text{Net Income} = \text{Gross Profit} - \text{Total Operating Expenses}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `dateFrom`, `dateTo`, `companyId` | Sum posted lines for `type = 'revenue'` (credit-normal) | `total_revenue` |
| 2 | Same range | Sum posted lines for `type = 'cogs'` (debit-normal) | `total_cogs` |
| 3 | Same range | Sum posted lines for `type = 'expense'` (debit-normal) | `total_expenses` |
| 4 | Steps 1–2 | `gross_profit = revenue - cogs` | `gross_profit` |
| 5 | Step 4, Step 1 | `gross_margin_pct = gp / revenue × 100` | percentage or null |
| 6 | Steps 4, 3 | `net_income = gross_profit - expenses` | `net_income` |
| 7 | Optional | Run Steps 1–6 for compare period | `compare` object |

### Variables / Constants

| Symbol | Description |
|---|---|
| `revenue` | Sum of credit-side movements on `type = 'revenue'` accounts |
| `cogs` | Sum of debit-side movements on `type = 'cogs'` accounts |
| `expenses` | Sum of debit-side movements on `type = 'expense'` accounts |
| `gross_margin_pct` | `null` if revenue = 0 (division guard) |

### Assumptions

- Revenue accounts are credit-normal; balance = `credit - debit`
- COGS and Expense accounts are debit-normal; balance = `debit - credit`
- Only `posted` entries included
- Prior-period comparison is optional (pass `compareDateFrom` / `compareDateTo`)

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Revenue = 0 | `gross_margin_pct = null` |
| No postings in range | All totals = 0; net_income = 0 |
| Compare period not provided | `compare` key omitted from response |

### Pseudocode

```
FUNCTION fetchPLData(companyId, dateFrom, dateTo):
    revenue  = sumPostedLines(type='revenue',  dateFrom, dateTo, companyId, creditNormal)
    cogs     = sumPostedLines(type='cogs',     dateFrom, dateTo, companyId, debitNormal)
    expenses = sumPostedLines(type='expense',  dateFrom, dateTo, companyId, debitNormal)

    grossProfit     = revenue - cogs
    grossMarginPct  = (revenue != 0) ? (grossProfit / revenue * 100) : null
    netIncome       = grossProfit - expenses

    RETURN {revenue, cogs, expenses, grossProfit, grossMarginPct, netIncome}
```

---

## 7. BalanceSheet

**Service / File:** `app/Services/FinancialReportService.php` → `balanceSheet()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** `ReportController::balanceSheet()`

### Formula (KaTeX)

$$
\text{Assets} = \text{Liabilities} + \text{Equity}
$$

**Asset balance (debit-normal):**

$$
\text{Balance} = \Sigma\text{Debit}_{\leq \text{asOfDate}} - \Sigma\text{Credit}_{\leq \text{asOfDate}}
$$

**Liability / Equity balance (credit-normal):**

$$
\text{Balance} = \Sigma\text{Credit}_{\leq \text{asOfDate}} - \Sigma\text{Debit}_{\leq \text{asOfDate}}
$$

**Assertion tolerance:**

$$
\left| \text{Total Assets} - (\text{Total Liabilities} + \text{Total Equity}) \right| \leq 0.01
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `asOfDate`, `companyId` | Sum ALL posted lines up to and including date | raw debit/credit per account |
| 2 | Per account | Apply normal-balance rule | signed `balance` |
| 3 | Balance | Route to `assets`, `liabilities`, or `equity` array | three arrays |
| 4 | YTD P&L | Inject synthetic `RE_YTD` equity line for current year NI | `equity` array += NI |
| 5 | Three totals | Assert `assets ≈ liabilities + equity` | `balanced` boolean |

### Variables / Constants

| Symbol | Description |
|---|---|
| `asOfDate` | Point-in-time date for the balance sheet |
| `RE_YTD` | Synthetic retained earnings code — current year net income |
| `0.01` | Assertion tolerance |
| `fiscalYearId` | Optional — used to determine YTD start date for NI injection |

### Assumptions

- Opening balances (prior to any fiscal year) are reflected in cumulative ledger history
- Current year net income is a synthetic equity line, not yet closed to retained earnings
- Assets = debit-normal; Liabilities + Equity = credit-normal

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Imbalanced sheet | `balanced = false`; `imbalance` returned |
| No fiscal year found for NI | NI injection skipped |
| Zero-balance accounts | Included in arrays (always show structure) |

### Pseudocode

```
FUNCTION balanceSheet(companyId, asOfDate, fiscalYearId):
    rows = sumPostedLines(types=['asset','liability','equity'], date <= asOfDate)

    FOR EACH row:
        balance = debitNormal ? (debit - credit) : (credit - debit)
        APPEND to assets | liabilities | equity

    fyStart = fiscalYearStart(companyId, asOfDate, fiscalYearId)
    IF fyStart:
        ni = fetchPLData(companyId, fyStart, asOfDate).netIncome
        IF ni != 0: equity.append({code:'RE_YTD', balance: ni})

    totalAssets      = SUM(assets.balance)
    totalLiabilities = SUM(liabilities.balance)
    totalEquity      = SUM(equity.balance)
    balanced         = ABS(totalAssets - totalLiabilities - totalEquity) <= 0.01

    RETURN {assets, liabilities, equity, totalAssets, totalLiabilities, totalEquity, balanced}
```

---

## 8. CashFlow

**Service / File:** `app/Services/FinancialReportService.php` → `cashFlow()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** `ReportController::cashFlow()`  
**Method:** Indirect method

### Formula (KaTeX)

$$
\text{Operating CF} = \text{Net Income} + \text{Depreciation} + \Delta\text{Working Capital}
$$

$$
\Delta\text{Working Capital} = -\Delta\text{AR} + \Delta\text{AP} - \Delta\text{Inventory}
$$

$$
\text{Investing CF} = \Delta\text{Fixed Assets (net)}
$$

$$
\text{Financing CF} = \Delta\text{Loans} + \Delta\text{Equity Contributions}
$$

$$
\text{Net Change} = \text{Operating CF} + \text{Investing CF} + \text{Financing CF}
$$

$$
\text{Closing Cash} = \text{Opening Cash} + \text{Net Change}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `dateFrom`, `dateTo` | Fetch NI from P&L | `netIncome` |
| 2 | Fixed-asset journal entries in period | Sum depreciation debits on expense accounts | `depreciation` |
| 3 | AR account (code prefix `12`) | `open − close` = ΔAR | `deltaAr` |
| 4 | AP account (code prefix `21`) | `close − open` = ΔAP | `deltaAp` |
| 5 | Inventory account (code prefix `13`) | `open − close` = ΔInventory | `deltaInv` |
| 6 | Steps 1–5 | Sum = Operating CF | `operatingCf` |
| 7 | Fixed-asset accounts (code prefix `15`) | `open − close` = Investing CF | `investingCf` |
| 8 | Loan accounts (code prefix `23`) | `close − open` = Financing CF | `financingCf` |
| 9 | Steps 6–8 | Sum | `netChange` |
| 10 | Opening cash balance | `opening + netChange` | `closingCash` |

### Variables / Constants

| Symbol | Account Code Prefix | Description |
|---|---|---|
| AR | `12` | Accounts receivable |
| AP | `21` | Accounts payable |
| Inventory | `13` | Feed + livestock inventory |
| Fixed Assets | `15` | Property, plant & equipment |
| Loans | `23` | Long-term debt / loans |
| Cash | `11` | Cash & bank accounts |

### Assumptions

- Uses chart of accounts code-prefix conventions defined in the seeder
- Depreciation identified by `source_type = 'fixed_asset'` on journal entries
- Opening cash = cumulative cash balance day before `dateFrom`
- Equity contributions (other than retained earnings) mapped to code prefix `31`

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| No fixed-asset entries | `depreciation = 0` |
| Zero working capital changes | Deltas = 0; no error |
| Opening cash = 0 | Valid — may be first period |

### Pseudocode

```
FUNCTION cashFlow(companyId, dateFrom, dateTo):
    ni           = fetchPLData(companyId, dateFrom, dateTo).netIncome
    depreciation = sumDepreciationDebits(companyId, dateFrom, dateTo)

    dayBefore = dateFrom - 1 day
    deltaAr   = balance('12', 'asset',     dayBefore) - balance('12', 'asset',     dateTo)
    deltaAp   = balance('21', 'liability', dateTo)    - balance('21', 'liability', dayBefore)
    deltaInv  = balance('13', 'asset',     dayBefore) - balance('13', 'asset',     dateTo)

    operatingCf = ni + depreciation + deltaAr + deltaAp + deltaInv

    deltaFA     = balance('15', 'asset', dayBefore) - balance('15', 'asset', dateTo)
    investingCf = deltaFA

    deltaLoans  = balance('23', 'liability', dateTo) - balance('23', 'liability', dayBefore)
    financingCf = deltaLoans

    netChange    = operatingCf + investingCf + financingCf
    openingCash  = balance('11', 'asset', dayBefore)
    closingCash  = openingCash + netChange

    RETURN {operatingCf, investingCf, financingCf, netChange, openingCash, closingCash}
```

---

## 9. ADG_FCR

**Service / File:** `app/Services/LivestockIntelligenceService.php` → `adgFcrForAnimal()`, `adgFcrForLot()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** `ReportController::adgFcr()`, Dashboard KPI cards

### Formula (KaTeX)

**Average Daily Gain:**

$$
\text{ADG} = \frac{W_\text{final} - W_\text{initial}}{\text{Days on Feed}}
$$

**Feed Conversion Ratio:**

$$
\text{FCR} = \frac{\text{Total Feed DM Consumed (kg)}}{\text{Total Weight Gain (kg)}}
$$

**Feed Dry Matter:**

$$
\text{DM}_\text{consumed} = \sum_{i} \text{qty\_kg}_i \times \frac{\text{dm\_pct}_i}{100}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `animal_id` | Fetch weight records ordered by date | `W_initial`, `W_final`, `dateFirst`, `dateLast` |
| 2 | Dates | `dof = dateLast − dateFirst` (calendar days) | `days_on_feed` |
| 3 | Weights, dof | `ADG = (W_final − W_initial) / dof` | `adg_kg_day` |
| 4 | `animal_id`, date range | `SUM(qty_kg × dm_pct/100)` from `feed_consumptions JOIN feed_items` | `feed_dm_total` |
| 5 | `feed_dm_total`, weight gain | `FCR = feed_dm / gain` | `fcr` or null |

### Variables / Constants

| Symbol | Description |
|---|---|
| `W_initial` | First weight record (kg) |
| `W_final` | Last weight record (kg) |
| `dof` | Days on feed = date difference |
| `dm_pct` | Dry matter percentage from `feed_items.dm_pct` |
| `feed_dm_total` | Total dry matter intake in kg |

### Assumptions

- At least 2 weight records required; returns `null` with reason if insufficient
- Feed consumption dates filtered to `[dateFirst, dateLast]` of the weight window
- For lots: averages across all animals; lot-level FCR uses sum of gains

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| < 2 weight records | Return `{adg: null, fcr: null, reason: 'Insufficient weight records'}` |
| `dof = 0` (same date) | Return null with reason `'Days on feed is zero'` |
| Weight gain = 0 or negative | `fcr = null` (no valid denominator) |
| No feed consumption records | `feed_dm = 0`; `fcr = null` |

### Pseudocode

```
FUNCTION adgFcr(animalId, companyId):
    weights = fetchWeightRecords(animalId, companyId, orderByDate)
    IF count(weights) < 2: RETURN nullResult('Insufficient weight records')

    W_initial = weights.first.weight_kg
    W_final   = weights.last.weight_kg
    dof       = daysDiff(weights.first.date, weights.last.date)
    IF dof <= 0: RETURN nullResult('Days on feed is zero')

    adg = (W_final - W_initial) / dof

    feedDm = SUM(qty_kg × dm_pct/100) WHERE animal=animalId AND date IN [first, last]
    gain   = W_final - W_initial
    fcr    = (gain > 0) ? (feedDm / gain) : null

    RETURN {adg, fcr, dof, W_initial, W_final, feedDm}
```

---

## 10. WAC

**Service / File:** `app/Services/LivestockIntelligenceService.php` → `recalculateWac()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** Inventory valuation, COGS calculations, Dashboard KPI

### Formula (KaTeX)

**On receipt of new stock:**

$$
\text{WAC}_\text{new} = \frac{(\text{Qty}_\text{on-hand} \times \text{WAC}_\text{old}) + (\text{Qty}_\text{in} \times \text{Unit Cost}_\text{in})}{\text{Qty}_\text{on-hand} + \text{Qty}_\text{in}}
$$

**On consumption (issue):**

$$
\text{WAC}_\text{unchanged} \quad \text{(WAC preserved; only qty decreases)}
$$

**On positive adjustment:**

$$
\text{WAC}_\text{new} = \frac{(\text{Qty}_\text{on-hand} \times \text{WAC}_\text{old}) + (\text{Qty}_\text{adj} \times \text{Unit Cost}_\text{adj})}{\text{Qty}_\text{on-hand} + \text{Qty}_\text{adj}}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `feed_item_id`, `company_id`, `farm_id` | Fetch all ledger rows ordered by `date, id` | chronological ledger |
| 2 | Each `receipt` or `transfer_in` row | Apply WAC formula | updated `wac`, `qtyOnHand` |
| 3 | Each `consumption` or `transfer_out` row | Reduce qty only; WAC unchanged | updated `qtyOnHand` |
| 4 | Each `adjustment` row | If positive: apply WAC formula; if negative: reduce qty only | updated `wac`, `qtyOnHand` |
| 5 | Per row | Record `wac_after`, `qty_after` | audit trail array |

### Variables / Constants

| Symbol | Description |
|---|---|
| `WAC_old` | Previous weighted average cost |
| `Qty_on_hand` | Current stock before transaction |
| `Qty_in` | Incoming quantity (receipt) |
| `Unit_cost_in` | Unit cost of incoming stock |
| `WAC_new` | New weighted average after receipt |
| `wac_after` | Stored on each `inventory_ledger` row |

### Assumptions

- WAC is preserved at zero-quantity (ready for next receipt to blend in)
- Negative adjustments do not change WAC (cost of existing stock unchanged)
- `inventory_ledger` is the single source of truth for WAC history
- Current WAC = `wac_after` on the latest ledger row by `(date DESC, id DESC)`

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Zero initial stock, first receipt | `WAC = unit_cost` (denominator = qty_in) |
| Stock goes to zero | `WAC` preserved for next receipt blend |
| Negative adjustment greater than stock | `qtyOnHand = MAX(0, qty - adj)` — floor at zero |
| Duplicate ledger rows | Ordered by `id` — deterministic processing order |

### Pseudocode

```
FUNCTION recalculateWac(feedItemId, companyId, farmId):
    ledger = fetchLedgerChronological(feedItemId, companyId, farmId)
    qtyOnHand = 0.0
    wac       = 0.0

    FOR EACH row IN ledger:
        IF row.type IN ['receipt', 'transfer_in']:
            qtyIn    = row.qty
            unitCost = row.unit_cost
            IF qtyOnHand + qtyIn > 0:
                wac = ((qtyOnHand * wac) + (qtyIn * unitCost)) / (qtyOnHand + qtyIn)
            qtyOnHand += qtyIn

        ELSE IF row.type IN ['consumption', 'transfer_out']:
            qtyOnHand = MAX(0, qtyOnHand - row.qty)
            // WAC unchanged

        ELSE IF row.type == 'adjustment':
            IF row.qty > 0:
                IF qtyOnHand + row.qty > 0:
                    wac = ((qtyOnHand * wac) + (row.qty * row.unit_cost)) / (qtyOnHand + row.qty)
            qtyOnHand = MAX(0, qtyOnHand + row.qty)

        row.wac_after = ROUND(wac, 4)
        row.qty_after = ROUND(qtyOnHand, 3)

    RETURN {currentWac: wac, qtyOnHand, ledgerRows}
```

---

## 11. GrossMarginBreakEven

**Service / File:** `app/Services/LivestockIntelligenceService.php` → `costingForAnimal()`, `costingForLot()`  
**Introduced:** Session 5 — Phase 4 Financial Intelligence  
**Used in:** `ReportController::cogsForLot()`, `grossMarginRanking()`, `animalCosting()`, Dashboard

### Formula (KaTeX)

**COGS per animal:**

$$
\text{COGS} = C_\text{purchase} + C_\text{feed} + C_\text{vet} + C_\text{slaughter} + C_\text{overhead}
$$

**Gross Margin:**

$$
\text{GM} = \text{Revenue} - \text{COGS}
$$

$$
\text{GM\%} = \frac{\text{GM}}{\text{Revenue}} \times 100
$$

**Break-Even Weight:**

$$
\text{BEW} = \frac{\text{COGS}}{\text{Price per kg (deadweight)}}
$$

**Gross Margin per head / per kg:**

$$
\text{GM/head} = \frac{\text{Total GM}}{\text{Head Count}}
$$

$$
\text{GM/kg} = \frac{\text{Total GM}}{\text{Total Deadweight (kg)}}
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `animal_id` | `procurement_line.total_cost` OR `purchase_price + freight_cost` | `purchase_cost` |
| 2 | `animal_id`, date range | `SUM(qty_kg × wac_at_consumption)` from `feed_consumptions` | `feed_cost` |
| 3 | `animal_id` | `SUM(cost)` from `health_records` + `vaccination_records` | `vet_cost` |
| 4 | `animal_id` | `slaughter_records.slaughter_cost` | `slaughter_cost` |
| 5 | Steps 1–4 | `COGS = purchase + feed + vet + slaughter` | `total_cogs` |
| 6 | `sales_line` | `sale_price` OR `qty_kg × price_per_kg` | `revenue` |
| 7 | Steps 5–6 | `GM = revenue − COGS` | `gross_margin` |
| 8 | `slaughter_record.deadweight_kg` | `BEW = COGS / price_per_kg` | `break_even_weight_kg` |

### Variables / Constants

| Symbol | Description |
|---|---|
| `C_purchase` | Procurement cost including allocated freight |
| `C_feed` | Feed cost at WAC valuation |
| `C_vet` | Health treatment + vaccination costs |
| `C_slaughter` | Abattoir / slaughter processing fee |
| `C_overhead` | Optional: allocated overhead (from journal lines) |
| `BEW` | Minimum deadweight needed to break even |
| `price_per_kg` | Sale price per kg deadweight |

### Assumptions

- Feed cost uses WAC at time of consumption (from `inventory_ledger.wac_after`)
- Revenue uses `sales_lines.total_amount` if available; otherwise `qty_kg × price_per_kg`
- Overhead allocation is optional — excluded if no overhead journal lines exist
- `break_even_weight` is only meaningful for slaughtered animals with a known price per kg
- For lots: aggregates across all animals; averages are `total / head_count`

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Animal not sold | `revenue = 0`; `GM = −COGS` (loss) |
| No slaughter record | `slaughter_cost = 0`; `BEW = null` |
| No feed consumptions | `feed_cost = 0` |
| Price per kg = 0 | `BEW = null` (avoid divide by zero) |
| `revenue = 0` | `GM% = null` |
| Lot with 0 head | Excluded from ranking report |

### Pseudocode

```
FUNCTION costingForAnimal(animalId, companyId):
    animal = fetchAnimalWithRelations(animalId)

    purchaseCost   = animal.procurementLine?.total_cost
                     ?? (animal.purchase_price + animal.freight_cost)
    feedCost       = SUM(fc.qty_kg × wacAtDate(fc.feed_item_id, fc.date))
                     FOR fc IN animal.feedConsumptions
    vetCost        = SUM(hr.cost) FOR hr IN animal.healthRecords
                   + SUM(vr.cost) FOR vr IN animal.vaccinationRecords
    slaughterCost  = animal.slaughterRecord?.slaughter_cost ?? 0
    totalCogs      = purchaseCost + feedCost + vetCost + slaughterCost

    revenue = animal.salesLine?.total_amount
              ?? (animal.slaughterRecord?.deadweight_kg × pricePerKg) ?? 0

    gm     = revenue - totalCogs
    gmPct  = (revenue != 0) ? (gm / revenue * 100) : null

    pricePerKg = animal.salesLine?.price_per_kg ?? null
    bew = (pricePerKg != null AND pricePerKg > 0) ? (totalCogs / pricePerKg) : null

    RETURN {purchaseCost, feedCost, vetCost, slaughterCost, totalCogs,
            revenue, gm, gmPct, bew}
```

---

## 12. BulkAnimalPriceAllocation

**Service / File:** `resources/js/Pages/Animals/CreateEdit.vue` (Vue computed) + `app/Http/Controllers/AnimalController::storeBulk()`  
**Introduced:** Session 6 — Animals/CreateEdit.vue (Bulk Entry)  
**Used in:** Mode A (average weight), Mode B (per-unit weights)

### Formula (KaTeX)

**Mode A — Average Weight (equal split):**

$$
P_i = \frac{P_\text{total}}{n}
$$

$$
F_i = \frac{F_\text{total}}{n}
$$

**Mode B — Per-Unit Weights (weight-proportional split):**

$$
P_i = P_\text{total} \times \frac{W_i}{\sum_{j=1}^{n} W_j}
$$

$$
F_i = F_\text{total} \times \frac{W_i}{\sum_{j=1}^{n} W_j}
$$

**Tag / RFID generation:**

$$
\text{Tag}_i = \text{prefix} + \text{zeroPad}(\text{tagFrom} + i - 1, \text{len}(\text{tagFrom}))
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `total_price`, `count` | Mode A: divide equally | `purchase_price` per animal |
| 2 | `total_freight`, `count` | Mode A: divide equally | `freight_cost` per animal |
| 3 | `total_price`, `W_i`, `ΣW` | Mode B: multiply by weight ratio | `purchase_price` per animal |
| 4 | `total_freight`, `W_i`, `ΣW` | Mode B: multiply by weight ratio | `freight_cost` per animal |
| 5 | `tag_prefix`, `tag_from`, `i` | Concatenate prefix + zero-padded suffix | `tag` string per animal |
| 6 | All per-animal fields | Build animals array | payload for `storeBulk` |
| 7 | `company_id` (top-level) + `animals[]` | POST to `animals.storeBulk` | DB insert via `DB::table()->insert()` |

### Variables / Constants

| Symbol | Description |
|---|---|
| `P_total` | Total purchase price entered by user (EGP) |
| `F_total` | Total freight cost entered by user (EGP) |
| `n` | Animal count |
| `W_i` | Weight of animal `i` (Mode B only) |
| `ΣW` | Sum of all animal weights (Mode B denominator) |
| `P_i` | Price allocated to animal `i` |
| `F_i` | Freight allocated to animal `i` |
| `tagFrom` | Starting tag suffix number |
| `prefix` | Tag prefix string (e.g. `"TAG-"`) |

### Assumptions

- Mode A: all animals share the same average weight; price split is equal regardless of weight
- Mode B: price/freight proportional to weight — heavier animals cost more
- Tag zero-padding matches the digit length of `tagFrom` (e.g. tagFrom=001 → TAG-001, TAG-002...)
- Excel upload (Mode B): only the `weight` column is imported; price/freight are always recalculated in Vue, never imported from the file
- `company_id` is always a top-level field in the payload — never nested inside the animals array
- `DB::table()->insert()` is used (not `Animal::insert()`) to avoid SoftDeletes `__callStatic` conflict inside transactions
- `now()` must be formatted as `now()->format('Y-m-d H:i:s')` — Carbon objects cannot be passed to raw query builder
- Boolean fields (`dob_estimated`) must be cast to `(int)` for `TINYINT(1)` columns in raw inserts

### Why This Methodology

Weight-proportional allocation (Mode B) reflects the economic reality that heavier animals represent a larger share of the purchase cost in live-weight livestock transactions. Equal split (Mode A) is appropriate when individual weights are not recorded at purchase time.

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| `ΣW = 0` in Mode B | Returns `"0.00"` for all price/freight — division guard |
| `count = 0` | Blocked by top-level validator (`min:1`) before reaching logic |
| `total_price = 0` | Valid — animals may be gifted or born on farm |
| `tag_from` not a number | `parseInt()` falls back to `1`; prefix still applied |
| Duplicate tags | Per-row validator catches via `Rule::unique` scoped to `company_id` |
| `company_id` missing | Blocked by `$request->validate(['company_id' => 'required|exists:companies,id'])` before any logic |
| Carbon object in raw insert | Fixed: `now()->format('Y-m-d H:i:s')` always used |
| Farm auth bypass (mixed farm_ids) | All unique `farm_id` values authorized — not just row 0 |

### Pseudocode

```
// Vue (CreateEdit.vue)
FUNCTION buildPayload(mode, bulk, tableRows):
    IF mode == 'A':
        FOR i = 0 TO count-1:
            tag  = bulk.tag_prefix  + zeroPad(bulk.tag_from  + i)
            rfid = bulk.rfid_prefix + zeroPad(bulk.rfid_from + i)
            animals[i] = {
                tag, rfid,
                purchase_price:     total_price / count,
                freight_cost:       total_freight / count,
                purchase_weight_kg: bulk.avg_weight,
                ...commonFields
            }
    ELSE IF mode == 'B':
        sumW = SUM(row.weight for row in tableRows)
        FOR EACH row IN tableRows:
            animals[] = {
                tag:  row.tag,
                rfid: row.rfid,
                purchase_price:     (sumW > 0) ? total_price  * row.weight / sumW : 0,
                freight_cost:       (sumW > 0) ? total_freight * row.weight / sumW : 0,
                purchase_weight_kg: row.weight,
                ...commonFields
            }

    POST route('animals.storeBulk') WITH {company_id, animals}

// Laravel (AnimalController::storeBulk)
FUNCTION storeBulk(request):
    VALIDATE {company_id: required|exists, animals: required|array|min:1|max:5000}
    companyId = is_super_admin ? request.company_id : authUser.company_id

    FOR EACH row IN animals: validateRow(row)
    IF errors: RETURN back with errors

    FOR EACH uniqueFarmId IN animals.pluck('farm_id').unique():
        authorizeFarm(authUser, farmId)

    now = now().format('Y-m-d H:i:s')   // plain string — not Carbon
    rows = animals.map(row => { ...row, company_id, created_at: now, updated_at: now,
                                dob_estimated: (int) row.dob_estimated })

    DB::transaction:
        FOR EACH chunk(rows, 200):
            DB::table('animals').insert(chunk)
```

---

## 13. AnimalGroupingDisplay

**Service / File:** `resources/js/Pages/Animals/Index.vue` → `groupedAnimals` computed  
**Introduced:** Session 7 — Animals/Index.vue (Grouped Collapsible Table)  
**Used in:** Animals index page rendering

### Formula (KaTeX)

**Group sort order (newest first):**

$$
\text{Groups sorted by } \text{purchase\_date}_\text{DESC}, \quad \text{null} \rightarrow \text{last}
$$

**Group summary — total weight:**

$$
\text{TotalWt}_g = \sum_{i \in g} \text{purchase\_weight\_kg}_i
$$

**Sex breakdown per group:**

$$
\text{Males}_g = |\{i \in g \mid \text{sex}_i = \text{male}\}|, \quad
\text{Females}_g = |\{i \in g \mid \text{sex}_i = \text{female}\}|, \quad
\text{Cast}_g = |\{i \in g \mid \text{sex}_i = \text{castrated}\}|
$$

### Step-by-Step Breakdown

| Step | Input | Processing | Output |
|---|---|---|---|
| 1 | `animals.data[]` | Build `map[purchase_date] = animals[]`; null date → key `'__no_date__'` | date-keyed map |
| 2 | Map entries | Sort by ISO date string descending; `'__no_date__'` always last | sorted `[date, animals[]]` pairs |
| 3 | Each group | Count total, males, females, castrated, active | summary counts |
| 4 | Each group | Sum `purchase_weight_kg` | `totalWt` |
| 5 | Each group | Collect unique farm names (max 2 shown + "+N more") | `farms[]` |
| 6 | Groups | First group (index 0) = open; all others = collapsed | `collapsed` ref map |
| 7 | User toggle | Flip `collapsed[date]` boolean | reactive re-render |

### Variables / Constants

| Symbol | Description |
|---|---|
| `groupedAnimals` | Vue computed — array of `{date, animals[], summary}` |
| `collapsed` | `ref({})` — map of `date → boolean`; `true` = collapsed |
| `'__no_date__'` | Sentinel key for animals with no `purchase_date` |
| `summary.totalWt` | Sum of `purchase_weight_kg` across all animals in the group |
| `summary.farms` | Unique farm display names in the group |

### Assumptions

- Grouping is client-side only — controller paginates 50 animals per page; grouping applies to the current page's `animals.data`
- ISO date strings (`YYYY-MM-DD`) sort correctly with `localeCompare()` — no Date parsing needed for sort order
- Collapse state persists across filter changes (state is preserved by date key, not position)
- On mobile (≤768px), group summary chips are hidden; only date + head count are shown

### Why This Methodology

Cattle are typically purchased in batches on the same date. Grouping by `purchase_date` mirrors the physical reality of the operation and allows managers to quickly assess the performance of a specific procurement batch without filtering.

### Edge Cases & Error Handling

| Case | Handling |
|---|---|
| Animal with no `purchase_date` | Grouped under sentinel key `'__no_date__'`, displayed last as "No Purchase Date" |
| All animals on same date | Single group, starts expanded |
| Filter changes (new page data) | `watch(groupedAnimals, initCollapsed)` re-runs; existing toggle states preserved by key |
| `purchase_weight_kg = null` | `parseFloat(null) = NaN`; coerced to `0` via `|| 0` in sum |
| Farm name missing | `filter(Boolean)` removes nulls from farms array |
| Zero animals in group | Cannot occur — empty groups are never created in the map |

### Pseudocode

```
// Vue computed: groupedAnimals
FUNCTION groupedAnimals(animalsData):
    map = {}
    FOR EACH animal IN animalsData:
        key = animal.purchase_date ?? '__no_date__'
        map[key] = map[key] ?? []
        map[key].push(animal)

    sorted = Object.entries(map).sort(([a], [b]):
        IF a == '__no_date__': RETURN 1
        IF b == '__no_date__': RETURN -1
        RETURN b.localeCompare(a)   // descending ISO date
    )

    RETURN sorted.map(([date, animals]) => ({
        date,
        animals,
        summary: buildSummary(animals)
    }))

FUNCTION buildSummary(animals):
    RETURN {
        total:   animals.length,
        males:   COUNT(a.sex == 'male'),
        females: COUNT(a.sex == 'female'),
        cast:    COUNT(a.sex == 'castrated'),
        active:  COUNT(a.status == 'active'),
        farms:   UNIQUE(a.farm.name).filter(Boolean),
        totalWt: SUM(parseFloat(a.purchase_weight_kg) || 0).toFixed(0)
    }

// Collapse state init
FUNCTION initCollapsed(groups):
    FOR EACH group AT index i:
        IF group.date NOT IN collapsed:
            collapsed[group.date] = (i != 0)   // first group open, rest collapsed
        // else: preserve existing user toggle state
```

---

---

## APPENDIX A — Methodology Checklist

Before writing any calculation, confirm all items below:

- [ ] Formula written in KaTeX notation
- [ ] All variables and constants defined in a table
- [ ] Step-by-step breakdown table completed (Input → Processing → Output)
- [ ] Assumptions listed
- [ ] Why this methodology was chosen stated
- [ ] All edge cases covered with explicit handling
- [ ] Pseudocode written in language-agnostic form
- [ ] Reference name assigned (PascalCase, unique)
- [ ] Entry added to TABLE OF CONTENTS at top of this file
- [ ] Implementation file has comment: `// 📌 METHODOLOGY REFERENCE: <ReferenceName>`

---

## APPENDIX B — Pending Calculations (Not Yet Implemented)

The following calculations are known to be needed and MUST have methodology blocks written before any code is produced:

| Calculation | Target Service | Session |
|---|---|---|
| NutritionalRationTotals (CP%, TDN%, DM% per ration) | `RationService` | Phase 5 / Vue |
| CashFlowForecast (projected inflows/outflows) | `ForecastService` | Phase 5 |
| OdooJournalSync (account mapping + sync delta) | `OdooService` | Phase 6 |
| DressingPercentage (deadweight / liveweight × 100) | `SlaughterService` | Phase 5 |
| ROI per lot (GM / total invested capital × 100) | `LivestockIntelligenceService` | Phase 5 |

---

## APPENDIX C — Methodology Location Map

| Location | Purpose |
|---|---|
| `docs/METHODOLOGY_REFERENCE.md` | **Primary** — this file. Full formulas, pseudocode, edge cases |
| Inline code comments (`// 📌 METHODOLOGY REFERENCE: Name`) | Cross-reference back to this document |
| Session chat history | Original derivation and discussion context |

---

## APPENDIX D — Session Build Log

| Session | What Was Built | Methodologies Added |
|---|---|---|
| 1 | Project inception, scope document | — |
| 2 | Schema, 36 models, seeders, Phase 1 & 2 controllers (18 total) | — |
| 3 | Fixed Assets module (FixedAssetController) | #4 DepreciationScheduleBuilder |
| 4 | Phase 3 Accounting Controllers (8 controllers), web.php routes fixed | #1 JournalBalanceValidation, #2 InvoiceBalanceCalc, #3 BudgetVariancePercent |
| 5 | Phase 4 Financial Intelligence (3 services, 2 controllers: ReportController, DashboardController) | #5 TrialBalance, #6 ProfitAndLoss, #7 BalanceSheet, #8 CashFlow, #9 ADG_FCR, #10 WAC, #11 GrossMarginBreakEven |
| 6 | Animals/CreateEdit.vue (3 modes: bulk avg, bulk per-unit, single), AnimalController::storeBulk, bug fixes | #12 BulkAnimalPriceAllocation |
| 7 | Animals/Index.vue (grouped collapsible table), AnimalController bug fixes (4 bugs), METHODOLOGY_REFERENCE.md update | #13 AnimalGroupingDisplay |

---

*End of METHODOLOGY_REFERENCE.md — VERO Cattle Management*  
*Always update this file before adding any new calculation to the codebase.*