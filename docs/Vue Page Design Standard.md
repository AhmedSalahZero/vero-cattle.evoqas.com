═══════════════════════════════════════════════════════════
VERO CATTLE MANAGEMENT — Vue Page Design Standard
Must be followed for EVERY Vue page/component built in this project.
═══════════════════════════════════════════════════════════

── THEME ────────────────────────────────────────────────────
Dark-first. Default is dark mode. app.css --cv- tokens are
the ONLY source of color. Never hardcode hex values inline.
Never use plain Tailwind color classes (bg-green-500 etc).
Use Tailwind ONLY for spacing, flex, grid, sizing.

4-color accent system — use each color for its role only:
  Green  var(--cv-green-bright)   → brand, healthy, active, success
  Amber  var(--cv-amber)          → CTAs, eyebrows, warnings, primary buttons
  Blue   var(--cv-blue-text)      → all numeric data, metrics, info badges
  Red    var(--cv-danger-text)    → alerts, critical, danger

── PAGE STRUCTURE (every module page) ───────────────────────
Every page must open with a .cv-page-hero block:

  <div class="cv-page-hero">
    <div class="cv-page-hero-eyebrow">Module Name</div>
    <div class="cv-page-hero-title">Page Title</div>
    <div class="cv-page-hero-sub">Subtitle / context line</div>
    <div class="cv-page-hero-actions">
      <!-- Primary CTA uses cv-btn cv-btn-amber -->
      <!-- Secondary uses cv-btn cv-btn-ghost -->
    </div>
  </div>

Then .cv-page for content (padding, flex column).

── CARDS ────────────────────────────────────────────────────
Index pages → card grid, NOT flat tables.
  grid: repeat(auto-fill, minmax(300px, 1fr))

Every card:
  - class="cv-card"
  - Left border stripe for instant status read:
      cv-card-stripe-green  → healthy / active / on track
      cv-card-stripe-amber  → warning / expiring / watch
      cv-card-stripe-red    → sick / critical / overdue
      cv-card-stripe-blue   → info / new entry
      cv-card-stripe-muted  → inactive / closed / archived
  - Hover: border lifts, subtle shadow
  - No flat white backgrounds — use var(--cv-bg-card)

── KPI / STAT CARDS ─────────────────────────────────────────
Use cv-stat-card + color modifier for top accent line:
  cv-stat-card-green  → count / active metrics (green value)
  cv-stat-card-amber  → performance metrics (amber value)
  cv-stat-card-blue   → weight / financial figures (blue value)
  cv-stat-card-red    → alerts / sick / overdue (red value)

Label: cv-stat-card-label (uppercase, muted, small)
Value: cv-stat-card-value (large, bold, colored by modifier)
Trend: cv-stat-card-trend-up / trend-down / trend-flat

── BUTTONS ──────────────────────────────────────────────────
Primary CTA    → cv-btn cv-btn-amber   (amber glow, dark text)
Secondary      → cv-btn cv-btn-secondary
Destructive    → cv-btn cv-btn-danger
Ghost/cancel   → cv-btn cv-btn-ghost
Data/export    → cv-btn cv-btn-blue
Icon-only      → cv-action-btn (+ cv-action-btn-danger / amber / blue)

── BADGES ───────────────────────────────────────────────────
cv-badge + semantic modifier:
  cv-badge-green    → healthy / active / success
  cv-badge-amber    → warning / low / pending
  cv-badge-danger   → critical / sick / overdue
  cv-badge-info     → new / info / sold
  cv-badge-neutral  → inactive / archived / dead

Animal lifecycle: cv-badge-active / sold / slaughtered / dead / quarantine
Lot status:       cv-badge-lot-active / lot-completed / lot-archived

── TABLES ───────────────────────────────────────────────────
Use cv-table inside cv-card.
Header: uppercase, muted, small, cv-bg-card-alt background.
Rows: hover highlights with cv-bg-hover.
Numeric columns: color var(--cv-blue-text) — never plain white.
Status columns: always a cv-badge — never plain text.

── FORMS ────────────────────────────────────────────────────
Inputs:     cv-input / cv-select / cv-textarea
Labels:     cv-label (+ cv-label-ar for Arabic pair)
Fields:     wrapped in cv-field
Errors:     cv-field-error
Icon input: cv-input-icon-wrap + cv-input-icon
Prefix:     cv-input-group + cv-input-prefix

Form pages → numbered section cards (1, 2, 3…):
  Each section in a cv-card with a gradient number badge
  and a section title. Never one giant flat form.

── MODALS ───────────────────────────────────────────────────
Backdrop: cv-modal-backdrop (blur + dark overlay)
Shell:    cv-modal + size (cv-modal-sm/md/lg/xl)
Parts:    cv-modal-header / cv-modal-body / cv-modal-footer
Delete confirm modal:
  - Red danger icon centered
  - Warning text in cv-alert-danger
  - cv-btn-ghost cancel + cv-btn-danger confirm

── SIDEBAR BADGES (alert counts) ───────────────────────────
Add badge prop to navGroups items when count > 0:
  badge: alertCount        → amber by default
  badgeType: 'danger'      → red (sick animals, overdue)

── ICONS ────────────────────────────────────────────────────
All icons: inline SVG, viewBox="0 0 24 24",
  fill="none", stroke="currentColor",
  stroke-width="1.7", stroke-linecap="round",
  stroke-linejoin="round"
Icons must be MEANINGFUL — match the domain:
  Health    → heart / syringe / pulse line
  Finance   → bank columns / scale / document with arrow
  Animals   → cattle face / weight scale / wheat stalk
  Reports   → trending lines / bar charts / justice scale
Never use generic placeholder icons (circles, squares alone).

── BILINGUAL RULES ──────────────────────────────────────────
All user-facing strings via t() helper or __() in PHP.
_en / _ar column pairs for all bilingual DB fields.
RTL: dir="rtl" on root element when lang === 'ar'.
Number formatting: use locale-aware formatting for Arabic.
Text alignment: always text-start (logical), never text-left.

── WHAT NOT TO DO ───────────────────────────────────────────
✗ No hardcoded hex colors anywhere in .vue files
✗ No flat table-only index pages — always card grids
✗ No cold white or near-white backgrounds — use cv-bg-card
✗ No plain text for status — always a cv-badge
✗ No plain green buttons as primary CTA — use cv-btn-amber
✗ No generic icons — every icon must reflect its module
✗ No excessive Tailwind utility chains for colors/borders
✗ No inline styles except for dynamic values (widths, etc.)

── REFERENCE FILES ──────────────────────────────────────────
app.css                  → all --cv- tokens (source of truth)
AuthenticatedLayout.vue  → shell, sidebar, header patterns
Companies/Index.vue      → card grid index pattern
Companies/CreateEdit.vue → numbered section form pattern
═══════════════════════════════════════════════════════════