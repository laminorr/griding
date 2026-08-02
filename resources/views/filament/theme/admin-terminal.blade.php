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
    :root {
        /* ---- Background layers (near-black terminal) ---- */
        --at-bg: #0B0F14;            /* app background, near-black */
        --at-surface: #121821;       /* cards, a touch lighter */
        --at-surface-2: #1A222E;     /* nested / hover surface */
        --at-border: rgba(148, 163, 184, 0.12);  /* thin, low-contrast divider */
        --at-border-strong: rgba(148, 163, 184, 0.20);

        /* ---- Accent (single soft-neon green) ---- */
        --at-accent: #34D399;        /* chosen green: emerald-400 / mint-neon */
        --at-accent-dim: rgba(52, 211, 153, 0.14);
        --at-accent-line: rgba(52, 211, 153, 0.55);

        /* ---- Semantic ---- */
        --at-pos: var(--at-accent);  /* positive == accent green */
        --at-neg: #F87171;           /* negative == soft red */
        --at-muted: #7C8899;         /* label / secondary gray */
        --at-text: #E6EAF0;          /* near-white, not pure white */
        --at-text-dim: #AEB7C4;

        /* ---- Typography scale (small & dense) ---- */
        --at-fs-label: 10.5px;       /* tiny uppercase labels */
        --at-fs-body: 12.5px;        /* base body / rows */
        --at-fs-metric: 17px;        /* the numbers — larger, not huge */
        --at-fs-title: 13px;         /* section titles */
        --at-ls-label: 0.08em;       /* letter-spacing for uppercase labels */

        /* ---- Spacing scale (tight, 4px base) ---- */
        --at-gap-xs: 4px;
        --at-gap-sm: 8px;
        --at-gap-md: 12px;
        --at-gap-lg: 16px;

        /* ---- Radius (small) & shadow (minimal) ---- */
        --at-radius: 5px;
        --at-radius-sm: 4px;
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
        line-height: 1.4;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: var(--at-ls-label);
        color: var(--at-muted);
        margin-block-end: var(--at-gap-xs);
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
        line-height: 1.4;
        color: var(--at-text-dim);
        margin-block-start: var(--at-gap-xs);
    }
    .metric-sub.pos { color: var(--at-pos); }
    .metric-sub.neg { color: var(--at-neg); }

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
</style>
