# 07 — Design System

Extracted from the reference "OmniVote Student Portal" screens. Treat these as starting tokens in `lib/core/constants/` and `lib/core/theme/app_theme.dart` — adjust only for platform conventions (e.g. touch target sizes), not brand identity.

## Color

| Token | Approx. value | Usage |
|---|---|---|
| `primaryBlue` | `#2F5EFF`–`#3B6EF6` range | primary buttons ("Vote", "Submit Application"), active nav item, links, position tag text |
| `navyDark` | `#0F172A` | left sidebar background (desktop) / becomes app bar or bottom-nav-selected background on mobile |
| `successGreen` | `#16A34A`/`#22C55E` | "Voting Open" badge, "Registration Complete" banner, progress bar fill |
| `surfaceWhite` | `#FFFFFF` | card backgrounds |
| `backgroundGray` | `#F8FAFC` | page background behind cards |
| `borderGray` | `#E2E8F0` | card borders, dividers |
| `textPrimary` | `#0F172A` | headings, names |
| `textSecondary` | `#64748B` | grade lines, helper text, descriptions |
| `tagBlueBg` / `tagBlueText` | light blue bg / blue text | position pill tags (e.g. `PRESIDENTIAL CANDIDATE`) |

## Typography

- Page titles (e.g. "Presidential Candidates"): bold, ~24–28px
- Card candidate name: bold, ~16–18px
- Body / platform bullets / descriptions: regular, ~14px
- Position tag pill: uppercase, bold, ~11px, letter-spacing wide
- Slogan/quote: italic, ~14px, `textSecondary`

## Spacing & shape

- Card corner radius: ~12px
- Card padding: ~20px
- Standard gap between stacked cards: 16px
- Buttons: filled primary (blue, white text, ~8px radius) for the main action ("Vote", "Submit Application", "Submit Ballot"); outline/secondary for the counterpart ("Cancel", "Back to Candidates")
- Progress bars: rounded track, `successGreen`/`primaryBlue` fill, label + fraction + percentage right-aligned on the same row as the bar's title

## Component rules

- **Position tag pill** always sits directly above the candidate's name, never beside it.
- **"KEY PLATFORM POINTS"** label is always small-caps/bold-gray, followed by a plain bullet list — never numbered (numbered lists are reserved for the Candidate Profile's "Campaign Platform" section).
- **Status badge** ("Voting Open") is a green dot + label, always paired with the live clock in the top bar.
- Card actions are always a two-item row: text link on the left ("View Profile"), filled button on the right ("Vote") — keep this left/right convention even when adapting to mobile widths.

## Mobile adaptation notes

- Desktop reference uses a 2-column card grid; Flutter phone layouts should use a **single column** (see `docs/04_SCREENS_SPEC.md`).
- Desktop reference uses a permanent left sidebar; Flutter uses a **bottom navigation bar** for the 5 primary destinations and an overflow menu for the rest (see `docs/03_APP_FLOW.md`).
- Keep the top bar's status badge + clock, but drop the breadcrumb prefix ("Student Portal /") on mobile — just show the current page title, since there's no sibling-page sidebar for context.
