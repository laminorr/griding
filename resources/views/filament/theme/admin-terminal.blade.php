{{--
    Compact "admin / terminal" design system — Phase P4 (part 1).

    Single source of truth for the dense, dark, trading-terminal look shared by
    every panel page. This part establishes tokens + reusable classes only; the
    page-by-page rollout happens in part 2.

    Registered globally via a render hook in AdminPanelProvider
    (panels::styles.after), so it applies to the dashboard, widgets, custom
    pages and resources alike. RTL-first: uses logical properties so it stays
    correct in the panel's Persian right-to-left layout.
--}}
<style>
    /* =====================================================================
       Vazirmatn — the panel typeface (nicer / far more readable than the
       system stack for Persian). Declared here in the theme partial so the
       whole panel has one authoritative font source; the weights we actually
       use are 400 / 500 / 700. Loaded from a CDN @font-face (jsDelivr, from the
       rastikerdar/vazirmatn release) so nothing needs a host install or build
       step. The panel itself is set to Vazirmatn via ->font('Vazirmatn') and a
       global font-family rule in AdminPanelProvider, so Filament's own
       components (sidebar, tables, forms, buttons) render in it too — these
       @font-face rules just guarantee the glyphs are available. */
    @font-face {
        font-family: 'Vazirmatn';
        font-style: normal;
        font-weight: 400;
        font-display: swap;
        src: url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Regular.woff2') format('woff2');
    }
    @font-face {
        font-family: 'Vazirmatn';
        font-style: normal;
        font-weight: 500;
        font-display: swap;
        src: url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Medium.woff2') format('woff2');
    }
    @font-face {
        font-family: 'Vazirmatn';
        font-style: normal;
        font-weight: 700;
        font-display: swap;
        src: url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Bold.woff2') format('woff2');
    }

    /* Belt-and-suspenders: assert Vazirmatn on our own design-system classes
       (metric tiles, cards, section titles) with the agreed fallback stack. The
       .at-mono utility is re-asserted afterwards so numeric/JSON blocks keep a
       monospaced face. */
    .metric-card, .metric-label, .metric-value, .metric-sub,
    .panel-section, .panel-section__title, .data-table,
    .at-badge, .at-btn, .at-kv__k, .at-kv__v, .at-page-head__title {
        font-family: 'Vazirmatn', system-ui, sans-serif;
    }

    :root {
        /* ---- Background layers (cohesive dark NAVY terminal) ----
           P4-compact: reconciled to the approved «مانیتورینگ زنده» redesign
           mockup. The mockup's --bg / --panel / --text / --muted / --green /
           --red are adopted here as the panel-wide source of truth, so the
           whole panel (dashboard, widgets, resources, custom pages) shifts to
           the deeper navy + brighter mint together — not just one page. The
           surface ladder still steps one rung lighter each level. */
        --at-bg: #06101f;            /* mockup --bg — deepest navy page shell */
        --at-surface: #0d1a2d;       /* mockup --panel — cards / sections */
        --at-surface-2: #16273d;     /* nested / hover — a navy step up from panel */
        --at-border: rgba(143, 160, 184, 0.16);  /* thin navy-gray hairline */
        --at-border-strong: rgba(143, 160, 184, 0.26);

        /* ---- Accent (single soft-neon green — mockup --green) ---- */
        --at-accent: #23d18b;        /* mockup --green: mint-neon */
        --at-accent-dim: rgba(35, 209, 139, 0.14);
        --at-accent-line: rgba(35, 209, 139, 0.55);

        /* ---- Semantic ---- */
        --at-pos: var(--at-accent);  /* positive == accent green */
        --at-neg: #ff5d68;           /* mockup --red — soft coral red */
        --at-muted: #8fa0b8;         /* mockup --muted — label / secondary */
        --at-text: #eef4ff;          /* mockup --text — near-white, cool */
        --at-text-dim: #b9c6da;

        /* ---- Typography scale (dense layout, READABLE text) ----
           P4-final: the boxes/gaps stay tight (spacing tokens below are
           unchanged), but the type was bumped back up from the earlier
           "miniature" pass to a comfortable-but-compact scale — labels legible,
           metric numbers prominent. Dense layout, readable fonts. */
        --at-fs-label: 11.5px;       /* small uppercase labels — readable */
        --at-fs-body: 13.5px;        /* base body / rows — comfortable */
        --at-fs-metric: 20px;        /* the numbers — prominent, not huge */
        --at-fs-title: 14px;         /* section titles */
        --at-ls-label: 0.07em;       /* letter-spacing for uppercase labels */

        /* ---- Spacing scale (very tight — ~30-40% denser than before) ----
           Every card / section / row derives its padding + gaps from these, so
           shrinking them here tightens the whole panel's vertical rhythm at
           once. Denser is the point. */
        --at-gap-xs: 3px;   /* was 4  */
        --at-gap-sm: 5px;   /* was 8  */
        --at-gap-md: 8px;   /* was 12 */
        --at-gap-lg: 11px;  /* was 16 */

        /* ---- Radius & shadow ----
           P4-compact: the mockup uses a softer --radius:14px on its panels /
           cards. Adopted here for panel-section + metric tiles; a smaller
           radius-sm keeps buttons / selects / inputs from over-rounding. */
        --at-radius: 14px;
        --at-radius-sm: 10px;
        --at-shadow: none;
    }

    /* =====================================================================
       Reusable classes (consumed by pages in part 2)
       All colors are self-contained so a tile renders correctly regardless
       of the surrounding Filament light/dark state — this system is dark.
       ===================================================================== */

    /* Compact card: small padding, thin border, surface bg */
    .metric-card {
        background: var(--at-surface);
        border: 1px solid var(--at-border);
        border-radius: var(--at-radius);
        padding: var(--at-gap-md);
        box-shadow: var(--at-shadow);
        color: var(--at-text);
        font-size: var(--at-fs-body);
    }

    /* Tiny uppercase muted label */
    .metric-label {
        display: block;
        font-size: var(--at-fs-label);
        line-height: 1.25;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: var(--at-ls-label);
        color: var(--at-muted);
        margin-block-end: 2px;
    }

    /* The number */
    .metric-value {
        display: block;
        font-size: var(--at-fs-metric);
        line-height: 1.15;
        font-weight: 700;
        color: var(--at-text);
        font-variant-numeric: tabular-nums;
    }
    .metric-value.pos { color: var(--at-pos); }
    .metric-value.neg { color: var(--at-neg); }

    /* Tiny sub-line under the value */
    .metric-sub {
        display: block;
        font-size: var(--at-fs-label);
        line-height: 1.3;
        color: var(--at-text-dim);
        margin-block-start: 2px;
    }
    .metric-sub.pos { color: var(--at-pos); }
    .metric-sub.neg { color: var(--at-neg); }

    /* ---- Single-line metric-card variant (label ⟷ value on ONE row) ----
       For dense stat lines that must NOT stack: the label sits on the start
       edge, the value (and any sub, as a muted inline suffix) is pushed to the
       end edge — a terminal stat line like "میانگین زمان چرخه … 0s".
       Used for the non-headline KV grids; the big headline tiles keep stacking. */
    .metric-card.is-row {
        display: flex;
        align-items: baseline;
        gap: var(--at-gap-xs);
        padding: 5px var(--at-gap-sm);
    }
    .metric-card.is-row .metric-label {
        margin-block-end: 0;
        margin-inline-end: auto;   /* pushes label to start, rest to the end */
        white-space: nowrap;
    }
    .metric-card.is-row .metric-value {
        display: inline;
        font-size: var(--at-fs-body);  /* stat-line size, not the big tile size */
        line-height: 1.2;
        text-align: end;
    }
    .metric-card.is-row .metric-sub {
        display: inline;
        margin-block-start: 0;
        color: var(--at-muted);        /* sub reads as a muted suffix, not a new line */
    }

    /* Section wrapper with a small header row + thin divider */
    .panel-section {
        background: var(--at-surface);
        border: 1px solid var(--at-border);
        border-radius: var(--at-radius);
        overflow: hidden;
        color: var(--at-text);
    }
    .panel-section__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--at-gap-sm);
        padding: var(--at-gap-sm) var(--at-gap-md);
        border-block-end: 1px solid var(--at-border);
    }
    .panel-section__title {
        font-size: var(--at-fs-title);
        font-weight: 600;
        color: var(--at-text);
        margin: 0;
    }
    .panel-section__body {
        padding: var(--at-gap-md);
    }

    /* Dense table / rows: thin dividers, tight padding */
    .data-table {
        inline-size: 100%;
        border-collapse: collapse;
        font-size: var(--at-fs-body);
        color: var(--at-text);
    }
    .data-table th {
        font-size: var(--at-fs-label);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: var(--at-ls-label);
        color: var(--at-muted);
        text-align: start;            /* RTL-correct: right in an RTL panel */
        padding: var(--at-gap-xs) var(--at-gap-md);
        border-block-end: 1px solid var(--at-border);
    }
    .data-table td,
    .data-row {
        padding: var(--at-gap-xs) var(--at-gap-md);
        border-block-end: 1px solid var(--at-border);
        text-align: start;
    }
    .data-table tr:last-child td,
    .data-row:last-child {
        border-block-end: 0;
    }
    .data-table tbody tr:hover {
        background: var(--at-surface-2);
    }

    /* Numeric cells stay LTR + tabular even inside the RTL layout */
    .at-num {
        direction: ltr;
        text-align: end;
        font-variant-numeric: tabular-nums;
    }
    .at-num.pos { color: var(--at-pos); }
    .at-num.neg { color: var(--at-neg); }

    /* =====================================================================
       Phase P4 (part 2) — page-level helpers + global panel overrides.
       Additive only. Everything below shares the tokens above so the whole
       panel (dashboard, widgets, custom pages, resources) reads as one dense
       terminal. RTL-first via logical properties.
       ===================================================================== */

    :root {
        /* one muted (not bright) amber for genuine "warning / stale" states */
        --at-warn: #D9A441;
        --at-warn-dim: rgba(217, 164, 65, 0.14);
    }

    /* ---- Layout scaffolding ---- */
    .at-stack   { display: flex; flex-direction: column; gap: var(--at-gap-md); }
    .at-stack-sm{ display: flex; flex-direction: column; gap: var(--at-gap-sm); }
    .at-row     { display: flex; align-items: center; gap: var(--at-gap-sm); }
    .at-grid    { display: grid; gap: var(--at-gap-md); }
    .at-grid-sm { display: grid; gap: var(--at-gap-sm); }
    /* dense auto-fit metric grid: many small tiles, close together */
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: var(--at-gap-sm);
    }
    .at-cols-2 { display: grid; grid-template-columns: 1fr; gap: var(--at-gap-md); }
    .at-cols-3 { display: grid; grid-template-columns: 1fr; gap: var(--at-gap-md); }
    @media (min-width: 768px) {
        .at-cols-2 { grid-template-columns: repeat(2, 1fr); }
        .at-cols-3 { grid-template-columns: repeat(3, 1fr); }
    }

    /* ---- Compact page header for custom pages (small, start-aligned) ---- */
    .at-page-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: var(--at-gap-md);
        flex-wrap: wrap;
        padding-block-end: var(--at-gap-sm);
        border-block-end: 1px solid var(--at-border);
        margin-block-end: var(--at-gap-md);
    }
    .at-page-head__title {
        font-size: 16px;
        font-weight: 700;
        color: var(--at-text);
        margin: 0;
    }
    .at-page-head__sub {
        font-size: var(--at-fs-label);
        color: var(--at-muted);
        margin: 0;
    }
    .at-page-head__aside { display: flex; align-items: center; gap: var(--at-gap-sm); }

    /* ---- Small caption used inside section heads ---- */
    .panel-section__sub {
        font-size: var(--at-fs-label);
        color: var(--at-muted);
        margin: 0;
    }

    /* ---- Key/value stacked tile body ---- */
    .at-kv {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: var(--at-gap-sm);
        padding: var(--at-gap-xs) 0;
    }
    .at-kv + .at-kv { border-block-start: 1px solid var(--at-border); }
    .at-kv__k {
        font-size: var(--at-fs-label);
        color: var(--at-muted);
        text-transform: uppercase;
        letter-spacing: var(--at-ls-label);
    }
    .at-kv__v {
        font-size: var(--at-fs-body);
        color: var(--at-text);
        font-variant-numeric: tabular-nums;
    }
    .at-kv__v.pos { color: var(--at-pos); }
    .at-kv__v.neg { color: var(--at-neg); }

    /* ---- Buttons: accent + neutral only ---- */
    .at-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: var(--at-gap-xs);
        font-size: var(--at-fs-body);
        font-weight: 600;
        line-height: 1;
        padding: 7px 12px;
        border-radius: var(--at-radius-sm);
        border: 1px solid var(--at-border-strong);
        background: var(--at-surface-2);
        color: var(--at-text);
        cursor: pointer;
        text-decoration: none;
        transition: background .15s ease, border-color .15s ease;
    }
    .at-btn:hover { background: #1e3350; }
    .at-btn svg { width: 15px; height: 15px; flex: none; }
    .at-btn--accent {
        background: var(--at-accent-dim);
        border-color: var(--at-accent-line);
        color: var(--at-accent);
    }
    .at-btn--accent:hover { background: rgba(35, 209, 139, 0.22); }
    .at-btn--danger {
        color: var(--at-neg);
        border-color: rgba(255, 93, 104, 0.40);
        background: rgba(255, 93, 104, 0.10);
    }
    .at-btn--danger:hover { background: rgba(255, 93, 104, 0.18); }

    /* ---- Pills / badges ---- */
    .at-badge {
        display: inline-flex;
        align-items: center;
        gap: var(--at-gap-xs);
        font-size: var(--at-fs-label);
        font-weight: 600;
        line-height: 1.4;
        padding: 2px 8px;
        border-radius: 999px;
        background: var(--at-surface-2);
        color: var(--at-text-dim);
        border: 1px solid var(--at-border);
        white-space: nowrap;
    }
    .at-badge.pos  { color: var(--at-accent); background: var(--at-accent-dim); border-color: var(--at-accent-line); }
    .at-badge.neg  { color: var(--at-neg);    background: rgba(255, 93, 104, 0.12); border-color: rgba(255, 93, 104, 0.40); }
    .at-badge.warn { color: var(--at-warn);   background: var(--at-warn-dim); border-color: rgba(217, 164, 65, 0.35); }
    .at-badge.muted{ color: var(--at-muted); }

    /* ---- Status dot ---- */
    .at-dot { width: 7px; height: 7px; border-radius: 999px; background: var(--at-muted); flex: none; display: inline-block; }
    .at-dot.pos  { background: var(--at-accent); box-shadow: 0 0 6px rgba(35, 209, 139, 0.5); }
    .at-dot.neg  { background: var(--at-neg); }
    .at-dot.warn { background: var(--at-warn); }

    /* ---- Thin progress bar ---- */
    .at-bar {
        position: relative;
        block-size: 6px;
        border-radius: 999px;
        background: var(--at-surface-2);
        overflow: hidden;
    }
    .at-bar__fill {
        position: absolute;
        inset-block: 0;
        inset-inline-start: 0;
        background: var(--at-accent);
        border-radius: 999px;
    }
    .at-bar__fill.neg   { background: var(--at-neg); }
    .at-bar__fill.warn  { background: var(--at-warn); }
    .at-bar__fill.muted { background: var(--at-muted); }

    /* ---- Tiny leading glyph on a metric-card label (mockup: small icon per
       card). Muted, inline, never competes with the value. ---- */
    .metric-ico {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        inline-size: 15px;
        font-size: 12px;
        line-height: 1;
        opacity: 0.72;
        margin-inline-end: 4px;
        filter: grayscale(0.15);
    }

    /* ---- Cycle donut (conic ring + centred N/den) used by «وضعیت چرخه».
       --pct is set inline (0-100). The hole re-shows the panel surface so the
       ring reads as a progress arc. ---- */
    .at-donut {
        --pct: 0;
        inline-size: 92px;
        block-size: 92px;
        border-radius: 50%;
        flex: none;
        background: conic-gradient(var(--at-accent) calc(var(--pct) * 1%), var(--at-surface-2) 0);
        display: grid;
        place-items: center;
    }
    .at-donut__hole {
        inline-size: 66px;
        block-size: 66px;
        border-radius: 50%;
        background: var(--at-surface);
        display: grid;
        place-items: center;
        text-align: center;
        line-height: 1.1;
    }
    .at-donut__num {
        display: block;
        font-size: 20px;
        font-weight: 700;
        color: var(--at-text);
        font-variant-numeric: tabular-nums;
    }
    .at-donut__den {
        display: block;
        font-size: var(--at-fs-label);
        color: var(--at-muted);
    }

    /* ---- Compact native select (bot picker etc.) ---- */
    .at-select {
        font-size: var(--at-fs-body);
        color: var(--at-text);
        background: var(--at-surface-2);
        border: 1px solid var(--at-border-strong);
        border-radius: var(--at-radius-sm);
        padding: 6px 10px;
        line-height: 1.2;
        max-inline-size: 100%;
    }
    .at-select:focus {
        outline: none;
        border-color: var(--at-accent-line);
    }
    .at-select option { background: var(--at-surface); color: var(--at-text); }

    /* ---- Empty state ---- */
    .at-empty {
        text-align: center;
        padding: var(--at-gap-lg);
        color: var(--at-muted);
        font-size: var(--at-fs-body);
    }
    .at-empty__icon { font-size: 22px; opacity: 0.5; margin-block-end: var(--at-gap-xs); }

    /* ---- Text-colour utilities ---- */
    .at-t-pos   { color: var(--at-accent); }
    .at-t-neg   { color: var(--at-neg); }
    .at-t-warn  { color: var(--at-warn); }
    .at-t-muted { color: var(--at-muted); }
    .at-t-dim   { color: var(--at-text-dim); }

    /* ---- Mono / numeric utility ---- */
    .at-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-variant-numeric: tabular-nums; }
    .at-scroll { overflow-y: auto; }
    .at-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
    .at-scroll::-webkit-scrollbar-thumb { background: var(--at-accent-dim); border-radius: 3px; }

    /* =====================================================================
       Global Filament panel overrides — shrink the default chrome so the
       stock dashboard / resource pages match the dense system without
       touching their PHP. All scoped to Filament's own classes.
       ===================================================================== */

    /* Page + widget headings: compact but readable (not hero-sized) */
    .fi-header-heading { font-size: 16px !important; font-weight: 700 !important; }
    .fi-header-subheading { font-size: var(--at-fs-label) !important; color: var(--at-muted) !important; }
    .fi-section-header-heading { font-size: var(--at-fs-title) !important; }

    /* Stats overview (Dashboard's BotStatusWidget) — compact tiles.
       PHP is untouched; only the rendered card chrome is retightened. */
    .fi-wi-stats-overview-stats-ctn { gap: var(--at-gap-sm) !important; }
    .fi-wi-stats-overview-stat {
        padding: var(--at-gap-md) !important;
        border-radius: var(--at-radius) !important;
        background: var(--at-surface) !important;
        box-shadow: none !important;
        --tw-ring-color: var(--at-border) !important;
    }
    .fi-wi-stats-overview-stat .grid { gap: 3px !important; }
    .fi-wi-stats-overview-stat-label {
        font-size: var(--at-fs-label) !important;
        font-weight: 600 !important;
        text-transform: uppercase !important;
        letter-spacing: var(--at-ls-label) !important;
        color: var(--at-muted) !important;
    }
    .fi-wi-stats-overview-stat-value {
        font-size: var(--at-fs-metric) !important;
        font-weight: 700 !important;
        letter-spacing: -0.01em !important;
        color: var(--at-text) !important;
        font-variant-numeric: tabular-nums;
    }
    .fi-wi-stats-overview-stat-description {
        font-size: var(--at-fs-label) !important;
        color: var(--at-text-dim) !important;
    }
    .fi-wi-stats-overview-stat-icon,
    .fi-wi-stats-overview-stat-description-icon { block-size: 0.9rem !important; inline-size: 0.9rem !important; }
    /* the little sparkline: keep it, just make it unobtrusive */
    .fi-wi-stats-overview-stat-chart { block-size: 2rem !important; opacity: 0.7; }

    /* Chart widget (30-day performance): trim the shell padding */
    .fi-wi-chart .fi-section-content { padding: var(--at-gap-md) !important; }

    /* Resource tables (Grid Bots list) — tighten to the same dense scale.
       Row data and inline badges keep their meaning; only padding + header
       type size shrink so the list matches the cards and metric tiles. */
    .fi-ta-header-cell { padding-block: var(--at-gap-xs) !important; }
    .fi-ta-header-cell-label {
        font-size: var(--at-fs-label) !important;
        text-transform: uppercase !important;
        letter-spacing: var(--at-ls-label) !important;
        color: var(--at-muted) !important;
    }
    .fi-ta-cell { padding-block: var(--at-gap-xs) !important; }
    .fi-ta-text-item-label { font-size: var(--at-fs-body) !important; }
    /* Row height: trim the vertical padding on the row wrapper too */
    .fi-ta-row .fi-ta-cell { padding-block: var(--at-gap-xs) !important; }
    /* Small, unified action-button sizing across pages */
    .fi-ta-actions .fi-icon-btn { --size: 1.75rem; }

    /* Header action buttons (Grid Bots list "ربات جدید", "شروع همه" …) —
       shrink from Filament's default lg chrome to the dense scale so the list
       header matches the cards + metric tiles. Colour semantics (success /
       danger) are kept; only size + radius are retightened. */
    .fi-header-actions .fi-btn,
    .fi-ta-header-toolbar .fi-btn {
        --tw-ring-offset-width: 0px !important;
        font-size: var(--at-fs-body) !important;
        font-weight: 600 !important;
        padding-block: 5px !important;
        padding-inline: 10px !important;
        border-radius: var(--at-radius-sm) !important;
        gap: var(--at-gap-xs) !important;
    }
    .fi-header-actions .fi-btn .fi-btn-icon,
    .fi-ta-header-toolbar .fi-btn .fi-btn-icon { block-size: 1rem !important; inline-size: 1rem !important; }

    /* Resource table cells: keep numeric columns LTR + tabular even in the RTL
       table, matching the custom pages. */
    .fi-ta-cell .at-num { direction: ltr; }

    /* =====================================================================
       Sidebar full-height. The nav sidebar was ending at its content height,
       leaving a differently-shaded strip below it down to the bottom of the
       viewport. Stretch the sidebar (and its inner nav) to the full viewport
       height so its navy background fills all the way down regardless of how
       few nav items there are — consistent in the collapsed + expanded states.
       ===================================================================== */
    .fi-sidebar,
    .fi-sidebar-nav {
        min-block-size: 100vh;
        min-block-size: 100dvh;
    }

    /* =====================================================================
       Top-of-page bot selector (P4-compact). One prominent-but-compact
       control: a segmented pill row of the active bots with the selected one
       highlighted in the terminal green. Wraps gracefully when there are many
       bots. RTL-first (logical properties); navy/green terminal palette.
       ===================================================================== */
    .at-botpicker {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: var(--at-gap-sm) var(--at-gap-md);
        padding: var(--at-gap-sm) var(--at-gap-md);
        background: var(--at-surface);
        border: 1px solid var(--at-border);
        border-radius: var(--at-radius);
    }
    .at-botpicker__label {
        display: inline-flex;
        align-items: center;
        gap: var(--at-gap-xs);
        font-size: var(--at-fs-label);
        font-weight: 600;
        letter-spacing: var(--at-ls-label);
        text-transform: uppercase;
        color: var(--at-muted);
        white-space: nowrap;
    }
    .at-botpicker__options {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: var(--at-gap-xs);
        flex: 1;
        min-inline-size: 0;
    }
    .at-botpill {
        display: inline-flex;
        align-items: center;
        gap: var(--at-gap-xs);
        padding: 6px 12px;
        font-family: 'Vazirmatn', system-ui, sans-serif;
        font-size: var(--at-fs-body);
        font-weight: 500;
        color: var(--at-text-dim);
        background: var(--at-surface-2);
        border: 1px solid var(--at-border-strong);
        border-radius: 999px;
        cursor: pointer;
        line-height: 1.2;
        transition: background .15s ease, border-color .15s ease, color .15s ease;
    }
    .at-botpill:hover { background: #1e3350; color: var(--at-text); }
    .at-botpill.is-active {
        color: var(--at-accent);
        background: var(--at-accent-dim);
        border-color: var(--at-accent-line);
        font-weight: 700;
    }
    .at-botpill__name { white-space: nowrap; }
    .at-botpill__sym {
        font-size: var(--at-fs-label);
        color: var(--at-muted);
        direction: ltr;
    }
    .at-botpill.is-active .at-botpill__sym { color: var(--at-accent); opacity: 0.85; }
</style>
