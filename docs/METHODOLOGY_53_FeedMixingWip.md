# 📌 METHODOLOGY AND LOGIC REFERENCE

**Reference Name:** `FeedMixingWip` (#53)
**Owner:** `FeedMixingService` · **Controller:** `FeedMixingController` · **Screens:** `FeedMixing/Index · CreateEdit · Show`
**Approved:** Session 69 (after Session 67 Journal Book Report #52)

## Formulas

$$\text{Ingredient cost}_i = \text{Qty}_i \times \text{WAC}_i \qquad (\text{rounded to 2 dp, half away from zero})$$

$$\text{Batch cost} = \sum_i \text{Ingredient cost}_i$$

$$\text{Cost per kg} = \frac{\text{Batch cost}}{\text{Finished kg}}$$

$$\text{WAC}_{new} = \frac{(\text{Qty}_{before} \times \text{WAC}_{before}) + \text{Batch cost}}{\text{Qty}_{before} + \text{Finished kg}}$$

$$\text{Yield \%} = \frac{\text{Finished kg}}{\text{Ingredient kg}} \times 100 \quad (\text{information only})$$

## Variables

| Symbol | Meaning | Source |
|---|---|---|
| Qty_i | Quantity of ingredient i taken | User (draft line) |
| WAC_i | Average cost of ingredient i **at the batch location** | Stock row `unit_cost` — never user input |
| Batch cost | Total value moved into WIP | Server, at Start |
| Finished kg | Actual kg of mix produced | User, at Finish |
| Qty_before / WAC_before | Mix stock at the location before the batch | Locked stock row |

## Step-by-step

1. **Create (draft)** — farm, location, start date, output mix item (existing master, category `feed`), ingredient lines. No stock or ledger effect.
2. **Start** — lock batch; verify open fiscal year, account 1360 and every credit account; lock each ingredient stock row (ordered by item id); block if any is short; decrement stock; write `mix_out` rows; store unit cost / line cost / account code on each line; post **Dr 1360 / Cr 1320·1330·1340·1350**. Status → `in_progress`.
3. **Finish** — lock batch; verify finished kg > 0, date ≥ start date, open fiscal year, accounts 1320 + 1360; lock (or create) the mix stock row; compute cost per kg and new WAC; increment stock; write `mix_in` row; post **Dr 1320 / Cr 1360** for the exact batch cost. Status → `completed`.
4. **Cancel** (only `in_progress`) — return each ingredient at the cost it left at (weighted into current WAC); write `mix_in` rows (note: cancelled); post a mirror entry (entry_type `reversal`). Original entry stays `posted`. Status → `cancelled`.

## Assumptions

- One location per batch; the finished mix returns to the same location (use Transfer to move it).
- The output mix is an existing master inventory item (category `feed`), so Feed Consumption lists it automatically.
- Labor and electricity are not part of the batch (future extension).
- Feed Consumption of the mix uses the normal flow — cost reaches animals through the mix's WAC (Methodology #42/#43).
- Permissions reuse the existing `feed` module (`view/create/edit/delete feed`).

## Why chosen

- Same patterns as the rest of the system: WAC, bcmath, `lockForUpdate()`, hard blocks before any write, reversal instead of delete.
- WIP account 1360 is separate from Livestock WIP 1310 so animal cost and feed-mixing cost are never confused.
- Batch cost (not `finished_kg × rounded cost_per_kg`) is what enters stock and what leaves WIP, so 1360 always returns exactly to zero.

## Edge cases

| Case | Handling |
|---|---|
| Ingredient short at location | Start blocked; nothing changes |
| No stock row for an ingredient at the location | Start blocked with item name |
| No open fiscal year / missing account | Blocked with a field error; nothing saved |
| All ingredients cost 0 | Start blocked |
| Finished kg ≤ 0 | Finish blocked → use Cancel |
| Finished kg > ingredient kg | Allowed (water); screen warns |
| Non-kg ingredient (e.g. ml) | Counts in cost, excluded from kg total |
| Mix stock empty before | WAC = cost per kg |
| Mix stock row missing at location | Created automatically (same rule as `InventoryReceiptService`) |
| Double click / two users | Locked re-fetch; second request is blocked (`status` error) |
| Cancel after Finish | Blocked |
| Output item chosen as its own ingredient | Blocked in validation |
| Delete | Draft only; started batches must be cancelled |
| Rounding | Each line rounded to cents; journal totals are sums of the rounded lines → always balanced |

## Pseudocode

```
START(batch):
  lock batch; require status = draft; require ≥ 1 line
  fy  = open fiscal year for start_date   (else block)
  wip = account 1360                      (else block)
  for each line ordered by item_id:
      stock = lock stock row at batch location   (else block)
      if stock.qty < line.qty: block
      acct  = account for item category          (else block)
      cost  = round2(line.qty × stock.unit_cost)
  if sum(cost) <= 0: block
  for each line: stock.qty -= qty; write mix_out; save unit_cost,total_cost,account_code on line
  post JE: Dr 1360 sum / Cr each account its sum          (must balance)
  status = in_progress

FINISH(batch, kg, date):
  lock batch; require in_progress; kg > 0; date >= start_date
  fy = open FY for date; accounts 1320 + 1360 exist
  mix = lock (or create) stock row of output item at location
  cpk = batch.total_cost / kg
  wac = (mix.qty = 0) ? cpk : (mix.qty×mix.wac + batch.total_cost) / (mix.qty + kg)
  mix.qty += kg; mix.unit_cost = wac; write mix_in
  post JE: Dr 1320 batch.total_cost / Cr 1360 batch.total_cost
  status = completed

CANCEL(batch, date):
  lock batch; require in_progress; date >= start_date; open FY for date
  for each line: stock.qty += line.qty; WAC re-weighted at line.unit_cost; write mix_in
  post mirror JE of the start entry (entry_type = reversal)
  status = cancelled
```

## Tests

`tests/Feature/Services/FeedMixingServiceTest.php` — 9 groups, 37 tests (worked example 9,500 / 1,480 kg / 6.4189 / 6.3690).

Logic Reference saved. You can reuse this exact methodology by asking me to reference: FeedMixingWip
