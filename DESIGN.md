# OmniVote — Design System

> The visual authority for this repo. Read this before changing any UI.

## Two consumers, one design language

The student app (Flutter) and the staff/President console (React) are
separate codebases, but they must read as the same product. The Flutter
side is specified in `docs/07_DESIGN_SYSTEM.md`; this file is the authority
for the **React console** and states the shared principles.

## React console — the token system

The console does **not** use Tailwind. All theming lives in CSS custom
properties in `admin-react/src/index.css`. Each page owns its own
stylesheet and may only *add* page-specific rules — it must not redefine
the shell classes (`.card`, `.sidebar`, `.top-header`, `.main-content`,
`.table-card`) or the tokens.

```
admin-react/src/index.css   ← tokens + shared shell (the whole theme)
admin-react/src/<Page>.css  ← that page's components only
```

### Tokens

| Group | Tokens |
|---|---|
| Surfaces | `--bg`, `--surface`, `--surface-2`, `--surface-3` |
| Lines | `--border`, `--border-strong`, `--border-subtle` |
| Type | `--text`, `--text-muted`, `--text-faint`, `--heading` |
| Brand | `--brand-primary`, `--brand-secondary` |
| Status | `--badge-bg`, `--badge-color`, `--badge-border` |
| Shape | `--shadow` |

**Never hard-code a colour.** Use a token, or a page-level custom property
derived from one. A literal `#2563eb` in a component is a bug: it will not
follow the theme packs or dark mode.

### Light / dark / theme packs

`data-theme="dark"` on `<html>` flips the palette. `data-theme-pack`
(`classic` / `ocean` / `sunset`) swaps the palette independently, so there
are six combinations. Theme packs are defined in `index.css` as token
overrides — the components never know which pack is active.

**A new component must be verified in dark mode at minimum**, and against
the theme packs if it introduces a new colour. The `[data-theme='dark']`
block comes *before* the light theme-pack blocks in the file, so the pack
blocks win for light rendering and the compound
`[data-theme='dark'][data-theme-pack='…']` selectors win for dark.

### Spacing and shape

- Card radius `12px`, card padding `20px`
- Standard gap between stacked cards `16px`; `20px` between page sections
- Buttons: filled `--brand-primary` for the primary action, outline for its
  counterpart
- Page titles ~1.05rem; card titles ~1rem; body `0.85rem`; helper/meta
  `0.78rem`; micro-labels `0.62–0.68rem`

## Shared component rules

These hold across the console, not just one screen:

- **Status is carried by badges, not by coloured edges.** A green/amber
  pill next to the value (`ELECTED`, `PROVISIONAL`, `WINNER`). Do not put a
  thick coloured stripe on the side or top of a card to mark a state — that
  is the most recognisable tell of a generated interface, and it is
  redundant when a badge already says the same thing.
- **Meta text is muted, not smaller.** A `0.78rem` label in `--text-muted`
  reads as secondary without becoming unreadable.
- **A title and its supporting line sit together**, never split across a
  card boundary.
- **Numbers that must be scanned use tabular figures** (`font-variant-numeric:
  tabular-nums`) so columns of values align.
- **Empty states say what is missing and when it appears** — not just "No
  data." A panel that is legitimately locked until a phase says so.
- **One accent per idea.** National and Provincial each get one accent
  colour, used for the badge and one soft wash — never for borders,
  gradients, or decoration.

## Density

The staff console is an operations tool: dense, information-first, fast to
scan. The President console is read-mostly and must stay calm. When
adapting a staff pattern to the President console, reduce emphasis and
chrome rather than copying it verbatim.

## Flutter app

Tokens and component rules are in `docs/07_DESIGN_SYSTEM.md` — primary
blue `#2F5EFF`–`#3B6EF6`, navy sidebar `#0F172A`, success green
`#16A34A`, card radius `12px`, and the single-column / bottom-navigation
mobile adaptation rules. Position tag pills sit above the name, never
beside it; numbered lists are reserved for the candidate platform section.

## Verifying a change

```bash
cd admin-react
npm run lint
npm run build
```

Then look at the result. Do not ship a UI change that has only been read —
render it (the `webapp-testing` skill drives Playwright for this) in light
and dark, and check the narrow viewport. The `impeccable` skill's detector
catches mechanical anti-patterns in the source:

```bash
.agents/skills/impeccable/scripts/impeccable detect admin-react/src/<Page>.jsx
```

It needs a running page (or a built `.html`) for the rules that require a
parsed DOM; scanning source files alone undercounts.
