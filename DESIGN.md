# Fundraising Admin — Design System & Rules

> **Purpose**: This document is the single source of truth for building any new page in the Fundraising admin panel. Follow every rule here and the result will be visually consistent, fully responsive, and production-ready.

---

## 1. Technology Stack

| Layer | Technology | Version / CDN |
|-------|-----------|---------------|
| CSS Framework | Bootstrap | 5.3.3 (`cdn.jsdelivr.net/npm/bootstrap@5.3.3`) |
| Icons | Font Awesome 6 | 6.5.1 (`cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1`) |
| Font | Inter | Google Fonts (`wght@300;400;500;600;700;800`) |
| Charts (when needed) | ECharts | 5.5.0 (`cdn.jsdelivr.net/npm/echarts@5.5.0`) |
| JS Framework | Vanilla JS | No jQuery, no React |

### Required CSS imports (in order)

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../../assets/theme.css">
<link rel="stylesheet" href="../assets/admin.css">
<!-- optional page-specific CSS -->
```

### Required JS imports (at end of body)

```html
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
```

---

## 2. Color Palette

### Brand Colors

| Token | Hex | Usage |
|-------|-----|-------|
| `--primary` | `#0a6286` | Primary buttons, links, sidebar, headings, active states |
| `--primary-light` | `#0ea5e9` | Hover accents, gradients, focus rings |
| `--primary-dark` | `#075985` | Sidebar gradient, dark button states, hero backgrounds |
| `--accent` | `#e2ca18` | Gold highlights, active nav indicators, progress bars |
| `--accent-light` | `#fbbf24` | Hover state for accent elements |
| `--accent-dark` | `#d97706` | Accent text on light backgrounds |

### Semantic Colors

| Token | Hex | Usage |
|-------|-----|-------|
| `--success` | `#10b981` | Approved, completed, paid, positive metrics |
| `--warning` | `#f59e0b` | Pending, outstanding, attention needed |
| `--danger` | `#ef4444` | Rejected, overdue, errors, voided |
| `--info` | `#3b82f6` | Informational badges, registrar role, secondary data |

### Neutral Scale

| Token | Hex | Usage |
|-------|-----|-------|
| `--white` | `#ffffff` | Card backgrounds, text on dark |
| `--gray-50` | `#f9fafb` | Page background, table header bg, metric tile bg |
| `--gray-100` | `#f3f4f6` | Dividers inside cards, muted badge bg, skeleton base |
| `--gray-200` | `#e5e7eb` | Card borders, input borders, table cell borders |
| `--gray-300` | `#d1d5db` | Disabled borders, empty state icons |
| `--gray-400` | `#9ca3af` | Placeholder text, meta labels, timestamps |
| `--gray-500` | `#6b7280` | Secondary text, filter labels, sub-descriptions |
| `--gray-600` | `#4b5563` | Body text in tables, form values |
| `--gray-700` | `#374151` | Strong secondary text |
| `--gray-800` | `#1f2937` | Card titles, metric values |
| `--gray-900` | `#111827` | Page titles, primary heading text |

### DO

- Use CSS custom properties (`var(--primary)`) for all colors.
- Use semantic colors for status indicators: `--success` for positive, `--warning` for caution, `--danger` for negative.
- Use `rgba()` with brand colors at 10-15% opacity for tinted icon backgrounds (e.g., `rgba(10, 98, 134, 0.1)` for primary tint).
- Use the gradient `linear-gradient(135deg, var(--primary), var(--primary-light))` for avatars and hero sections.

### DO NOT

- Never use raw hex values inline — always reference CSS variables.
- Never use Bootstrap's default blue (`#0d6efd`) — use `--primary` (`#0a6286`) instead.
- Never use black (`#000`) for text — use `--gray-900` at most.
- Never use pure white (`#fff`) for page backgrounds — use `--gray-50`.

---

## 3. Typography

| Element | Size | Weight | Color |
|---------|------|--------|-------|
| Page title (`h1`) | `1.5rem` | 700 | `--gray-900` |
| Section title | `0.75rem` uppercase | 700 | `--gray-400` with letter-spacing `0.5px` |
| Card title (`h6`) | `0.9375rem` | 600 | `--gray-800` |
| Body text | `0.875rem` | 400-500 | `--gray-600` |
| KPI value | `1.25rem–1.5rem` | 700-800 | `--gray-900` or semantic color |
| KPI label | `0.6875rem` uppercase | 600-700 | `--gray-500` with letter-spacing `0.3-0.4px` |
| Sub-description | `0.75rem–0.8rem` | 400-500 | `--gray-400` or `--gray-500` |
| Table header | `0.7rem–0.75rem` uppercase | 600-700 | `--gray-500` with letter-spacing `0.3-0.06em` |
| Table cell | `0.875rem` | 400-500 | `--gray-600` |
| Badge / pill | `0.6875rem` | 600 | Contextual color |

### DO

- Use `font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`.
- Use `text-transform: uppercase` + `letter-spacing: 0.3px–0.5px` for labels and section titles.
- Use `font-weight: 700–800` for numbers and KPI values.
- Use `white-space: nowrap; overflow: hidden; text-overflow: ellipsis` for names that might overflow.

### DO NOT

- Never use font sizes below `0.55rem` or above `1.5rem` in the admin panel.
- Never use `font-weight: 900` — max is `800`.
- Never use all-caps for body text or long strings — only for short labels.

---

## 4. Page Shell & Layout

Every admin page follows this exact HTML structure:

```html
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <!-- Page content here -->
            </div>
        </main>
    </div>
</div>
```

### Layout Constants

| Token | Value |
|-------|-------|
| `--sidebar-width` | `280px` |
| `--sidebar-collapsed-width` | `80px` |
| `--topbar-height` | `70px` |
| Main content padding | `2rem` (desktop), `1.5rem` (tablet), `1rem` (mobile) |

### DO

- Always wrap content in `.admin-wrapper > .admin-content > main.main-content > .container-fluid`.
- Always include `sidebar.php` and `topbar.php`.
- Use `<main class="main-content">` — it has `margin-top: var(--topbar-height)` and `padding: 2rem`.

### DO NOT

- Never add a second sidebar or topbar.
- Never set `overflow: hidden` on `.admin-content` — the sidebar needs scroll.
- Never use fixed widths on `.main-content` — it must flex.

---

## 5. Page Header Pattern

Every page starts with a header section. Two patterns exist:

### Pattern A: Simple Header (most pages)

```html
<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h1 style="font-size:1.5rem; font-weight:700; color:var(--gray-900); margin:0;">
            <i class="fas fa-icon me-2" style="color:var(--primary)"></i>Page Title
        </h1>
        <p style="color:var(--gray-500); font-size:0.875rem; margin:4px 0 0;">Short description</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="back-link.php"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <button class="btn btn-primary"><i class="fas fa-action me-1"></i>Action</button>
    </div>
</div>
```

### Pattern B: Hero Header (feature pages)

```css
.page-hero {
    background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, var(--primary-light) 100%);
    border-radius: 1rem;
    padding: 1.75rem 2rem;
    color: var(--white);
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
```

### DO

- Always include a Font Awesome icon before the page title.
- Always provide a "Back" button linking to the parent page.
- Keep descriptions to one line, under 80 characters.

### DO NOT

- Never omit the page header.
- Never use more than 2 action buttons in the header.
- Never use hero headers for simple CRUD pages — reserve for dashboards and feature pages.

---

## 6. Stat / KPI Cards

### Pattern: Stat Chip Row (horizontal)

```html
<div style="display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
    <div class="stat-chip">
        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat-value">42</div>
            <div class="stat-label">Total Agents</div>
        </div>
    </div>
    <!-- more chips -->
</div>
```

### Pattern: Stat Grid (4-column)

```css
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
```

### Icon Circle Backgrounds

Always use 10% opacity of the semantic color:

```css
.stat-icon.primary { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
.stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
.stat-icon.warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
.stat-icon.danger  { background: rgba(239, 68, 68, 0.1);  color: var(--danger); }
.stat-icon.info    { background: rgba(59, 130, 246, 0.1);  color: var(--info); }
```

Icon circle dimensions: `38px–42px`, `border-radius: 10px`, centered flex.

### Stat Card Styling

```css
background: var(--white);
border: 1px solid var(--gray-200);
border-radius: 10px–12px;
padding: 12px–16px;
box-shadow: var(--shadow-sm);  /* 0 1px 2px rgba(0,0,0,0.05) */
```

On hover: `box-shadow: var(--shadow-md)`.

### DO

- Use `number_format()` for all numeric values.
- Use currency formatting (`Intl.NumberFormat`) for money values.
- Keep stat labels uppercase, `0.6875rem`, `letter-spacing: 0.3px`.
- Use `border-left: 4px solid var(--color)` for accent stat cards.

### DO NOT

- Never display raw database numbers without formatting.
- Never put more than 4 stat chips in a single row on mobile — use `flex-wrap: wrap`.
- Never use Bootstrap's `.card` class for stat chips — use custom classes.

---

## 7. Filter / Search Bar

```css
.filter-bar {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}
```

### Filter Labels

```css
font-size: 0.75rem;
font-weight: 600;
color: var(--gray-500);
text-transform: uppercase;
letter-spacing: 0.3px;
margin-bottom: 4px;
```

### Filter Inputs

```css
border: 1px solid var(--gray-200);
border-radius: 8px;
font-size: 0.875rem;
padding: 8px 12px;
```

Focus state:
```css
border-color: var(--primary);
box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
```

### Filter Actions

- Primary button: `btn btn-primary` (search/apply).
- Reset: `btn btn-outline-secondary` or plain link with `<i class="fas fa-times">`.
- Use Bootstrap grid: `row g-2 align-items-end`.

### DO

- Use `form-control-sm` or `form-select-sm` for compact filters.
- Support Enter key submission on text inputs.
- Show active filter count as a badge.

### DO NOT

- Never stack more than 6 filter fields in one row.
- Never use checkboxes for single-select filters — use `<select>`.
- Never hide the reset/clear option.

---

## 8. Data Tables

### Table Card Container

```css
background: var(--white);
border: 1px solid var(--gray-200);
border-radius: 12px;
box-shadow: var(--shadow-sm);
overflow: hidden;
```

### Table Header Bar

```css
padding: 14px 20px;
background: var(--gray-50);
border-bottom: 1px solid var(--gray-200);
display: flex;
align-items: center;
justify-content: space-between;
```

### Table Head (`<thead>`)

```css
background: var(--gray-50) or var(--white);
font-size: 0.7rem–0.75rem;
font-weight: 600–700;
color: var(--gray-500);
text-transform: uppercase;
letter-spacing: 0.3px–0.06em;
border-bottom: 1px–2px solid var(--gray-200);
padding: 10px 16px;
white-space: nowrap;
```

### Table Body (`<tbody>`)

```css
td padding: 10px 16px;
vertical-align: middle;
font-size: 0.875rem;
border-bottom: 1px solid var(--gray-50) or var(--gray-100);
```

Row hover: `background: var(--gray-50)` or `rgba(14,165,233,0.03)`.

### DO

- Always wrap tables in `.table-responsive`.
- Always use `table-hover` for interactive tables.
- Use `align-middle` on the table.
- Use `text-end` for numeric/money columns.
- Use `fw-semibold` for amount cells.
- Link donor names to their profile pages.
- Show phone numbers in `<small class="text-muted">` below names.

### DO NOT

- Never use Bootstrap's default table styling without overrides.
- Never let tables scroll horizontally on desktop — design columns to fit.
- Never use `table-striped` — use hover instead.
- Never put more than 8 columns in a table.

---

## 9. Badges & Status Pills

### Soft Badge (rectangular)

```css
padding: 2px–3px 8px–10px;
border-radius: 4px–6px;
font-size: 0.6875rem–0.75rem;
font-weight: 500–600;
text-transform: uppercase;
letter-spacing: 0.3px;
```

Color pattern (10% bg + full text):
```css
.badge-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
.badge-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
.badge-danger  { background: rgba(239, 68, 68, 0.1);  color: var(--danger); }
.badge-info    { background: rgba(59, 130, 246, 0.1);  color: var(--info); }
.badge-primary { background: rgba(10, 98, 134, 0.1);   color: var(--primary); }
.badge-muted   { background: var(--gray-100);           color: var(--gray-500); }
```

### Status Pill (rounded)

```css
border-radius: 999px;
padding: 0.3rem 0.75rem;
font-size: 0.72rem;
font-weight: 600;
```

Include a status dot: `width: 6px; height: 6px; border-radius: 50%; background: currentColor;`

### DO

- Use soft badges (10% opacity background) — never solid Bootstrap badges.
- Use the `<span class="badge">` pattern, not `<div>`.
- Animate the dot on "new" or "pending" statuses with a pulse animation.

### DO NOT

- Never use Bootstrap's default `.badge` colors directly.
- Never mix badge styles on the same page.
- Never use badges for long text — max 2 words.

---

## 10. Buttons

### Primary Action

```css
background: var(--primary);
color: var(--white);
border: none;
border-radius: 8px;
font-weight: 500–600;
font-size: 0.8125rem–0.875rem;
padding: 8px 16px;
```

Hover: `background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 2px 8px rgba(10,98,134,0.25);`

### Outline / Secondary

```css
border: 1px solid var(--primary) or var(--gray-200);
color: var(--primary) or var(--gray-700);
background: transparent;
border-radius: 8px;
```

Hover: fill with primary color + white text.

### DO

- Always include a Font Awesome icon inside buttons: `<i class="fas fa-icon me-1"></i>`.
- Use `btn-sm` for table actions and filter buttons.
- Use `d-flex gap-2` for button groups.

### DO NOT

- Never use more than 3 buttons in a single row.
- Never use `btn-lg` in the admin panel.
- Never use Bootstrap's `btn-primary` without `theme.css` override (it maps to `--primary`).

---

## 11. Cards

### Standard Card

```css
background: var(--white);
border: 1px solid var(--gray-200);
border-radius: 10px–14px;
padding: 14px–24px;
box-shadow: 0 1px 4px rgba(0,0,0,0.05);
```

Hover (interactive cards): `box-shadow: var(--shadow-md); border-color: var(--gray-300);`

### Clickable Card

```css
cursor: pointer;
transition: transform 0.15s ease, box-shadow 0.15s ease;
```

Hover: `transform: translateY(-2px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);`

### Performance / Hero Card

```css
background: linear-gradient(135deg, var(--primary) 0%, #0b78a6 60%, rgba(226,202,24,0.85) 100%);
border-radius: 14px;
padding: 16px;
color: #fff;
```

Inner items: `background: rgba(255,255,255,0.15); backdrop-filter: blur(8px); border-radius: 10px;`

### DO

- Use `border-0 shadow-sm` when using Bootstrap card classes.
- Use `h-100` on cards inside grid rows for equal height.
- Use `flex-direction: column` with `flex: 1` on card content for proper spacing.

### DO NOT

- Never nest cards more than 1 level deep.
- Never use card headers with heavy backgrounds — use `bg-transparent border-0`.
- Never use `card-footer` with background color — keep it minimal.

---

## 12. Avatars & Initials

```css
width: 46px–64px;
height: 46px–64px;
border-radius: 50% (circular) or 12px (rounded square);
background: linear-gradient(135deg, var(--primary), var(--primary-light));
color: var(--white);
display: flex;
align-items: center;
justify-content: center;
font-weight: 700–800;
font-size: 1.125rem–1.5rem;
flex-shrink: 0;
```

For profile pages: add `box-shadow: 0 4px 12px rgba(10, 98, 134, 0.3)`.

---

## 13. Empty States

```css
text-align: center;
padding: 40px–60px 20px;
color: var(--gray-500);
```

Icon: `font-size: 1.5rem–3rem; color: var(--gray-300); margin-bottom: 8px–12px; display: block;`

Always include:
1. A relevant Font Awesome icon.
2. A short message explaining why it's empty.
3. An action button to clear filters or navigate.

---

## 14. Pagination

```css
.pagination .page-link {
    border: 1px–1.5px solid var(--gray-200);
    border-radius: 8px;
    font-size: 0.78rem–0.8rem;
    font-weight: 600;
    padding: 0.4rem 0.75rem;
    color: var(--gray-600);
}

.page-item.active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: var(--white);
    box-shadow: 0 2px 8px rgba(10, 98, 134, 0.3);
}
```

Hover: `background: var(--primary); color: var(--white); transform: translateY(-1px);`

### DO

- Show "Showing X–Y of Z records" text alongside pagination.
- Use First/Prev/Next/Last with chevron icons.
- Show max 5 page numbers with ellipsis.

### DO NOT

- Never show pagination when there's only 1 page.
- Never use Bootstrap's default pagination colors.

---

## 15. Alerts & Error States

### Enhanced Alert

```css
border: none;
border-radius: 0.75rem;
padding: 1rem 1.25rem;
font-size: 0.875rem;
font-weight: 500;
display: flex;
align-items: center;
gap: 0.75rem;
border-left: 4px solid [semantic-color];
background: rgba([semantic-color], 0.08);
```

### DO

- Always include an icon (`fa-check-circle` for success, `fa-exclamation-triangle` for errors).
- Auto-dismiss success alerts after 4 seconds.
- Use `alert-dismissible` with Bootstrap close button.

### DO NOT

- Never use solid-color alert backgrounds.
- Never show raw error messages to users — log them and show a friendly message.

---

## 16. Responsive Breakpoints

Follow Bootstrap's breakpoints with these specific rules:

| Breakpoint | Width | Behavior |
|-----------|-------|----------|
| Desktop XL | `≥1200px` | 4-column stat grids, 2–3 column card grids |
| Desktop | `≥992px` | Sidebar visible, 3–4 column grids |
| Tablet | `768px–991px` | Sidebar hidden (hamburger), 2-column grids |
| Mobile | `576px–767px` | 2-column stat grids, stacked filters |
| Small mobile | `<576px` | 1–2 column grids, compact padding |

### Responsive Rules

```css
@media (max-width: 991px) {
    /* Sidebar collapses, content goes full-width */
    .admin-content { margin-left: 0; }
    .main-content { padding: 1.5rem; }
}

@media (max-width: 768px) {
    /* Stack page headers vertically */
    /* Stats row: 2 columns or stacked */
    /* Filters: stack vertically */
}

@media (max-width: 576px) {
    .main-content { padding: 1rem; }
    /* Cards: reduce padding to 12px–16px */
    /* Filter bar: reduce padding */
}
```

### DO

- Use Bootstrap grid (`row g-3`, `col-xl-4 col-md-6 col-12`) for card layouts.
- Use CSS Grid with `repeat(auto-fit, minmax(160px, 1fr))` for stat rows.
- Use `flex-wrap: wrap` on all flex containers.
- Use `gap` instead of margins between flex/grid children.
- Test every page at 375px width.

### DO NOT

- Never use fixed pixel widths for content containers.
- Never hide critical data on mobile — reflow it instead.
- Never use horizontal scroll for primary content (tables are the exception via `.table-responsive`).

---

## 17. Shadows

| Token | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | `0 1px 2px rgba(0,0,0,0.05)` | Cards at rest, stat chips |
| `--shadow` | `0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06)` | General elevation |
| `--shadow-md` | `0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06)` | Card hover, dropdowns |
| `--shadow-lg` | `0 10px 15px -3px rgba(0,0,0,0.1)` | Modals, popovers |
| `--shadow-xl` | `0 20px 25px -5px rgba(0,0,0,0.1)` | Sidebar, command palette |

### DO

- Use `--shadow-sm` as the default card shadow.
- Elevate to `--shadow-md` on hover.

### DO NOT

- Never use `box-shadow` with opacity above `0.15` for standard elements.
- Never stack multiple shadows that total more than `--shadow-xl`.

---

## 18. Transitions & Animations

| Token | Value | Usage |
|-------|-------|-------|
| `--transition-fast` | `150ms cubic-bezier(0.4, 0, 0.2, 1)` | Color, background, border changes |
| `--transition` | `200ms cubic-bezier(0.4, 0, 0.2, 1)` | Box-shadow, transform |
| `--transition-slow` | `300ms cubic-bezier(0.4, 0, 0.2, 1)` | Layout shifts, sidebar |

### Available Animation Classes

- `.animate-fade-in` — fade + slide up on page load.
- `.animate-slide-in` — slide from left.
- `.animate-bounce-in` — bouncy entrance.
- `.animate-pulse` — continuous pulse (for live indicators).
- `.animate-float` — gentle floating effect.
- `.hover-lift` — translateY(-4px) + shadow on hover.

### DO

- Use `animate-fade-in` on page sections with staggered `animation-delay`.
- Use `transform: translateY(-1px)` on button hover.
- Use `transition` on all interactive elements.

### DO NOT

- Never use animations longer than 600ms.
- Never animate layout properties (`width`, `height`, `top`, `left`) — use `transform`.
- Never use animations on data tables or forms — only on cards and page sections.

---

## 19. Spacing System

Use consistent spacing based on multiples of 4px:

| Size | Value | Usage |
|------|-------|-------|
| xs | `4px` | Inline gaps, icon margins |
| sm | `8px` | Between badges, tight grid gaps |
| md | `12px` | Grid gaps, card internal spacing |
| lg | `16px` | Section padding, card padding |
| xl | `20px` | Section margins, major gaps |
| 2xl | `24px` | Profile header padding |
| 3xl | `32px` | Empty state padding |

### DO

- Use Bootstrap's `g-3` (12px gap) for card grids.
- Use `mb-3` (16px) between major sections.
- Use `gap` property instead of margins for flex/grid children.
- Use `me-1` or `me-2` for icon-to-text spacing.

### DO NOT

- Never use arbitrary spacing values like `13px` or `17px`.
- Never use `margin-left` on flex children — use `gap`.

---

## 20. Forms & Inputs

### Input Styling

```css
border: 1px–1.5px solid var(--gray-200);
border-radius: 8px;
padding: 8px–9px 10px–12px;
font-size: 0.82rem–0.875rem;
```

Focus: `border-color: var(--primary); box-shadow: 0 0 0 3px rgba(10,98,134,0.08–0.1);`

### Form Labels

```css
font-size: 0.75rem;
font-weight: 600;
color: var(--gray-500);
text-transform: uppercase;
letter-spacing: 0.3px;
margin-bottom: 4px;
```

### DO

- Use `form-control-sm` / `form-select-sm` in filter bars.
- Use `onchange="this.form.submit()"` for filter selects that should auto-apply.
- Group related filters in a single `.row g-2`.

### DO NOT

- Never use default Bootstrap form styling without the focus ring override.
- Never use `<textarea>` without a max-height.

---

## 21. Section Dividers

Use this pattern for titled sections:

```css
.section-title {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--gray-400);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--gray-200);
}
```

---

## 22. Progress Bars

```css
height: 5px–6px;
background: var(--gray-100) or var(--gray-200);
border-radius: 3px;
overflow: hidden;
```

Fill bar: `height: 100%; border-radius: 3px;`

Color logic:
- `≥100%` → `--success` (`#10b981`)
- `≥50%` → `--info` (`#3b82f6`)
- `>0%` → `--warning` (`#f59e0b`)
- `0%` → `--gray-200`

---

## 23. Naming Conventions

### CSS Class Prefixes

Each page uses a 2–3 letter prefix to avoid conflicts:

| Page | Prefix | Example |
|------|--------|---------|
| Agents Management | `am-` | `am-agent-card`, `am-stat-chip` |
| View Agent | `vp-` | `vp-profile-header`, `vp-stat-card` |
| Member View | `rpt-` | `rpt-member`, `rpt-card`, `rpt-donor` |
| Public Donations | descriptive | `page-hero`, `filter-card`, `data-card` |

### DO

- Use a unique prefix for every new page.
- Use BEM-like naming: `prefix-block`, `prefix-block-element`.
- Keep class names lowercase with hyphens.

### DO NOT

- Never use generic class names like `.card-title` or `.header` without a prefix.
- Never use IDs for styling — only for JavaScript hooks.
- Never use `!important` except in utility overrides from `admin.css`.

---

## 24. Accessibility

### DO

- Use `role="alert"` on alert messages.
- Use `aria-label` on icon-only buttons.
- Use `<label>` elements for all form inputs.
- Use semantic HTML: `<main>`, `<nav>`, `<table>`, `<thead>`, `<tbody>`.
- Ensure color contrast ratio ≥ 4.5:1 for text.

### DO NOT

- Never rely on color alone to convey status — always include text or icons.
- Never use `tabindex` values above 0.
- Never disable focus outlines without providing an alternative.

---

## 25. Performance

### DO

- Load CSS in `<head>`, JS at end of `<body>`.
- Use CDN for Bootstrap, Font Awesome, and ECharts.
- Use `loading="lazy"` on images.
- Use skeleton loaders (`.skeleton` class) for async content.
- Debounce search inputs (300ms).

### DO NOT

- Never load jQuery — use vanilla JS.
- Never inline large CSS blocks — extract to a page-specific CSS file if >50 lines.
- Never use `document.write()`.
- Never load unused chart libraries.

---

## 26. Quick Reference Checklist

Before shipping any new page, verify:

- [ ] Uses `admin-wrapper > admin-content > main.main-content > container-fluid` structure
- [ ] Includes sidebar.php and topbar.php
- [ ] All colors use CSS custom properties
- [ ] Page header with icon, title, description, and back button
- [ ] Stat cards use 10% opacity icon backgrounds
- [ ] Tables are wrapped in `.table-responsive`
- [ ] Badges use soft color pattern (10% bg + full text)
- [ ] Buttons have Font Awesome icons
- [ ] Filter bar has apply + clear actions
- [ ] Pagination shows record count
- [ ] Empty states have icon + message + action
- [ ] Responsive at 375px, 768px, 992px, 1200px
- [ ] All `<form>` inputs have labels
- [ ] Page-specific CSS uses a unique class prefix
- [ ] No raw hex colors — all via variables
- [ ] Hover states on all interactive elements
- [ ] `box-shadow: var(--shadow-sm)` on cards
- [ ] Font Awesome 6 icons (not v4/v5)
